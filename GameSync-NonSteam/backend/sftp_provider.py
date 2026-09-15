import logging
from datetime import datetime
from pathlib import Path
from typing import Optional, Dict, Any

from base_provider import StorageProvider

logger = logging.getLogger(__name__)

DEFAULT_FOLDER = "GameSync"

try:
    import paramiko  # type: ignore
except ImportError:
    paramiko = None  # type: ignore
    logger.error(
        "paramiko не найден. Установите зависимости плагина: "
        "pip install -r requirements.txt"
    )


class SFTPProvider(StorageProvider):
    """Провайдер для SFTP (SSH File Transfer Protocol) на базе paramiko."""

    def __init__(
        self,
        host: str,
        port: int = 22,
        username: str = "",
        password: str = "",
        key_path: str = "",
        key_passphrase: str = "",
    ):
        self.host = host
        self.port = int(port or 22)
        self.username = username or ""
        self.password = password or ""
        self.key_path = key_path or ""
        self.key_passphrase = key_passphrase or ""
        self.folder_name = DEFAULT_FOLDER

        if paramiko is None:
            raise RuntimeError(
                "paramiko недоступен в окружении Decky. "
                "Установите зависимости: pip install -r requirements.txt"
            )

    def _connect(self):
        """Открывает SSH-клиент и SFTP-сессию."""
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())

        connect_kwargs: Dict[str, Any] = {
            "hostname": self.host,
            "port": self.port,
            "username": self.username,
            "timeout": 20,
        }
        if self.password:
            connect_kwargs["password"] = self.password
        if self.key_path:
            connect_kwargs["key_filename"] = self.key_path
            if self.key_passphrase:
                connect_kwargs["passphrase"] = self.key_passphrase

        client.connect(**connect_kwargs)
        return client

    @staticmethod
    def _close(client) -> None:
        if client is None:
            return
        try:
            client.close()
        except Exception:
            pass

    @staticmethod
    def _ensure_folder(sftp, folder: str) -> None:
        try:
            sftp.stat(folder)
        except IOError:
            sftp.mkdir(folder)

    def upload_file(self, file_path: str, remote_dir: str = None) -> Optional[str]:
        directory = (remote_dir or self.folder_name).strip("/")
        file_name = Path(file_path).name
        remote_path = f"{directory}/{file_name}"
        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            try:
                self._ensure_folder(sftp, directory)
                sftp.put(file_path, remote_path)
            finally:
                sftp.close()
            logger.info(f"Uploaded file to SFTP: {remote_path}")
            return remote_path
        except Exception as e:
            logger.error(f"Error uploading file to SFTP: {e}")
            return None
        finally:
            self._close(client)

    def download_file(self, file_id: str, save_path: str) -> bool:
        client = None
        try:
            target = Path(save_path)
            target.parent.mkdir(parents=True, exist_ok=True)
            client = self._connect()
            sftp = client.open_sftp()
            try:
                sftp.get(file_id, str(target))
            finally:
                sftp.close()
            logger.info(f"Downloaded file from SFTP: {file_id} -> {save_path}")
            return True
        except Exception as e:
            logger.error(f"Error downloading file from SFTP: {e}")
            return False
        finally:
            self._close(client)

    def find_file(self, file_name: str, folder_name: str = DEFAULT_FOLDER) -> Optional[str]:
        remote_path = f"{folder_name.strip('/')}/{file_name}"
        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            try:
                sftp.stat(remote_path)
                return remote_path
            finally:
                sftp.close()
        except Exception:
            return None
        finally:
            self._close(client)

    def get_file_info(self, file_id: str) -> Optional[Dict[str, Any]]:
        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            try:
                stat = sftp.stat(file_id)
            finally:
                sftp.close()
            return {
                "id": file_id,
                "name": Path(file_id).name,
                "size": int(stat.st_size),
                "modifiedTime": datetime.fromtimestamp(stat.st_mtime).isoformat(),
            }
        except Exception as e:
            logger.error(f"Error getting SFTP file info: {e}")
            return None
        finally:
            self._close(client)

    def test_connection(self) -> Dict[str, Any]:
        client = None
        try:
            client = self._connect()
            sftp = client.open_sftp()
            try:
                sftp.listdir(".")
            finally:
                sftp.close()
            return {"success": True, "message": "Подключение к SFTP успешно"}
        except Exception as e:
            logger.error(f"SFTP connection test failed: {e}")
            return {"success": False, "error": str(e), "message": "Ошибка подключения"}
        finally:
            self._close(client)
