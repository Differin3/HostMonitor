import ftplib
import logging
from pathlib import Path
from typing import Optional, Dict, Any

from base_provider import StorageProvider

logger = logging.getLogger(__name__)

DEFAULT_FOLDER = "GameSync"


class FTPProvider(StorageProvider):
    """Провайдер для FTP/FTPS хранилища (на базе стандартного ftplib)."""

    def __init__(
        self,
        host: str,
        port: int = 21,
        username: str = "",
        password: str = "",
        use_tls: bool = False,
        passive: bool = True,
    ):
        self.host = host
        self.port = int(port or 21)
        self.username = username or ""
        self.password = password or ""
        self.use_tls = use_tls
        self.passive = passive
        self.folder_name = DEFAULT_FOLDER

    def _connect(self) -> ftplib.FTP:
        """Открывает соединение и логинится."""
        ftp = ftplib.FTP_TLS() if self.use_tls else ftplib.FTP()
        ftp.connect(self.host, self.port, timeout=20)
        ftp.login(self.username or "anonymous", self.password or "")
        if self.use_tls:
            ftp.prot_p()
        ftp.set_pasv(self.passive)
        return ftp

    @staticmethod
    def _close(ftp: Optional[ftplib.FTP]) -> None:
        if ftp is None:
            return
        try:
            ftp.quit()
        except Exception:
            try:
                ftp.close()
            except Exception:
                pass

    def _ensure_folder(self, ftp: ftplib.FTP, folder: str) -> None:
        """Создаёт папку, если её ещё нет."""
        try:
            ftp.mkd(folder)
        except ftplib.error_perm as e:
            # 550 обычно означает, что папка уже существует
            if not str(e).startswith("550"):
                raise

    def upload_file(self, file_path: str, remote_dir: str = None) -> Optional[str]:
        directory = (remote_dir or self.folder_name).strip("/")
        file_name = Path(file_path).name
        remote_path = f"{directory}/{file_name}"
        ftp = None
        try:
            ftp = self._connect()
            self._ensure_folder(ftp, directory)
            with open(file_path, "rb") as f:
                ftp.storbinary(f"STOR {remote_path}", f)
            logger.info(f"Uploaded file to FTP: {remote_path}")
            return remote_path
        except Exception as e:
            logger.error(f"Error uploading file to FTP: {e}")
            return None
        finally:
            self._close(ftp)

    def download_file(self, file_id: str, save_path: str) -> bool:
        try:
            target = Path(save_path)
            target.parent.mkdir(parents=True, exist_ok=True)
            ftp = self._connect()
            try:
                with open(target, "wb") as f:
                    ftp.retrbinary(f"RETR {file_id}", f.write)
            finally:
                self._close(ftp)
            logger.info(f"Downloaded file from FTP: {file_id} -> {save_path}")
            return True
        except Exception as e:
            logger.error(f"Error downloading file from FTP: {e}")
            return False

    def find_file(self, file_name: str, folder_name: str = DEFAULT_FOLDER) -> Optional[str]:
        remote_path = f"{folder_name.strip('/')}/{file_name}"
        ftp = None
        try:
            ftp = self._connect()
            ftp.size(remote_path)  # бросит error_perm, если файла нет
            return remote_path
        except Exception:
            return None
        finally:
            self._close(ftp)

    def get_file_info(self, file_id: str) -> Optional[Dict[str, Any]]:
        ftp = None
        try:
            ftp = self._connect()
            size = ftp.size(file_id)
            if size is None:
                return None

            info: Dict[str, Any] = {
                "id": file_id,
                "name": Path(file_id).name,
                "size": int(size),
            }
            try:
                # MDTM возвращает "213 YYYYMMDDHHMMSS"
                response = ftp.sendcmd(f"MDTM {file_id}")
                info["modifiedTime"] = response[4:].strip()
            except Exception:
                pass
            return info
        except Exception as e:
            logger.error(f"Error getting FTP file info: {e}")
            return None
        finally:
            self._close(ftp)

    def test_connection(self) -> Dict[str, Any]:
        ftp = None
        try:
            ftp = self._connect()
            try:
                ftp.cwd("/")
            except Exception:
                pass
            return {"success": True, "message": "Подключение к FTP успешно"}
        except Exception as e:
            logger.error(f"FTP connection test failed: {e}")
            return {"success": False, "error": str(e), "message": "Ошибка подключения"}
        finally:
            self._close(ftp)
