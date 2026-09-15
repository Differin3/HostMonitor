import logging
from pathlib import Path
from typing import Optional, Dict, Any

from base_provider import StorageProvider

logger = logging.getLogger(__name__)

# boto3 ставится вместе с зависимостями плагина (requirements.txt).
try:
    import boto3  # type: ignore
    from botocore.config import Config  # type: ignore
    from botocore.exceptions import BotoCoreError, ClientError  # type: ignore
except ImportError:
    boto3 = None  # type: ignore
    Config = None  # type: ignore
    BotoCoreError = Exception  # type: ignore
    ClientError = Exception  # type: ignore
    logger.error(
        "boto3/botocore не найдены. Установите зависимости плагина: "
        "pip install -r requirements.txt"
    )


class S3Provider(StorageProvider):
    """Провайдер для S3-совместимых хранилищ."""

    def __init__(
        self,
        endpoint: str,
        region: str,
        bucket: str,
        access_key: str,
        secret_key: str,
        path_style: bool = False,
        signature_version: str = "s3v4",
    ):
        self.endpoint = endpoint
        self.region = region or "us-east-1"
        self.bucket = bucket
        self.access_key = access_key
        self.secret_key = secret_key
        self.path_style = path_style
        self.signature_version = signature_version

        if boto3 is None or Config is None:
            raise RuntimeError(
                "boto3 недоступен в окружении Decky. "
                "Проверьте подключение к интернету и логи плагина для деталей установки boto3."
            )

        session = boto3.session.Session()
        extra_config = {
            "s3": {
                "addressing_style": "path" if path_style else "auto",
                "signature_version": signature_version,
            }
        }
        config = Config(
            region_name=self.region,
            retries={"max_attempts": 3, "mode": "standard"},
            **extra_config,  # type: ignore[arg-type]
        )

        self.client = session.client(
            "s3",
            endpoint_url=self.endpoint or None,
            aws_access_key_id=self.access_key,
            aws_secret_access_key=self.secret_key,
            config=config,
        )

    def _make_key(self, remote_dir: Optional[str], file_path: str) -> str:
        directory = (remote_dir or "GameSync").strip("/")
        return f"{directory}/{Path(file_path).name}"

    def upload_file(self, file_path: str, remote_dir: str = None) -> Optional[str]:
        key = self._make_key(remote_dir, file_path)
        try:
            path_obj = Path(file_path)
            extra_args: Dict[str, Any] = {}
            try:
                mtime = int(path_obj.stat().st_mtime)
                extra_args["Metadata"] = {"local-mtime": str(mtime)}
            except Exception as e:
                logger.debug(f"Failed to read mtime for {file_path}: {e}")

            self.client.upload_file(str(path_obj), self.bucket, key, ExtraArgs=extra_args)
            logger.info(f"Uploaded file to S3: bucket={self.bucket}, key={key}")
            return key
        except (BotoCoreError, ClientError) as e:
            logger.error(f"Error uploading file to S3: {e}")
            return None

    def download_file(self, file_id: str, save_path: str) -> bool:
        try:
            target = Path(save_path)
            target.parent.mkdir(parents=True, exist_ok=True)

            self.client.download_file(self.bucket, file_id, str(target))
            logger.info(f"Downloaded file from S3: key={file_id} -> {save_path}")
            return True
        except (BotoCoreError, ClientError) as e:
            logger.error(f"Error downloading file from S3: {e}")
            return False

    def find_file(self, file_name: str, folder_name: str = "GameSync") -> Optional[str]:
        prefix = folder_name.rstrip("/") + "/"
        try:
            paginator = self.client.get_paginator("list_objects_v2")
            for page in paginator.paginate(Bucket=self.bucket, Prefix=prefix):
                for obj in page.get("Contents", []):
                    key = obj.get("Key") or ""
                    if key.endswith("/" + file_name) or key == file_name:
                        return key
            return None
        except (BotoCoreError, ClientError) as e:
            logger.error(f"Error finding file in S3: {e}")
            return None

    def get_file_info(self, file_id: str) -> Optional[Dict[str, Any]]:
        try:
            response = self.client.head_object(Bucket=self.bucket, Key=file_id)
            size = int(response.get("ContentLength", 0))
            last_modified = response.get("LastModified")
            metadata = response.get("Metadata") or {}

            local_mtime = None
            if "local-mtime" in metadata:
                try:
                    local_mtime = int(metadata["local-mtime"])
                except ValueError:
                    pass

            return {
                "id": file_id,
                "size": size,
                "modifiedTime": last_modified.isoformat() if last_modified else None,
                "localMtime": local_mtime,
            }
        except (BotoCoreError, ClientError) as e:
            logger.error(f"Error getting S3 file info: {e}")
            return None

    def test_connection(self) -> Dict[str, Any]:
        try:
            # Пробуем получить хотя бы один объект или просто метаданные бакета
            self.client.head_bucket(Bucket=self.bucket)
            return {
                "success": True,
                "message": "Подключение к S3 успешно",
            }
        except ClientError as e:
            code = e.response.get("Error", {}).get("Code", "")
            msg = str(e)
            if code in ("NoSuchBucket", "404"):
                return {
                    "success": False,
                    "error": "Указанный бакет не существует или недоступен",
                    "message": msg,
                }
            if code in ("InvalidAccessKeyId", "SignatureDoesNotMatch"):
                return {
                    "success": False,
                    "error": "Неверные ключи доступа (Access Key / Secret Key)",
                    "message": msg,
                }
            return {
                "success": False,
                "error": f"Ошибка S3: {code or msg}",
                "message": msg,
            }
        except BotoCoreError as e:
            return {
                "success": False,
                "error": f"Ошибка подключения к S3: {e}",
                "message": str(e),
            }

