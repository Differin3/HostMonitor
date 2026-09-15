import logging
import requests
from pathlib import Path
from typing import Optional, Dict, Any
from urllib.parse import urljoin, quote
from base_provider import StorageProvider

logger = logging.getLogger(__name__)

class WebDAVProvider(StorageProvider):
    """Провайдер для WebDAV хранилища"""
    
    def __init__(self, url: str, username: str = None, password: str = None, oauth_token: str = None):
        self.base_url = url.rstrip('/') + '/'
        self.username = username
        self.password = password
        self.oauth_token = oauth_token
        self.folder_name = "GameSync"
        
        # Определяем тип авторизации
        if oauth_token:
            # OAuth токен (для Яндекс Диска)
            self.auth = None
            self.auth_header = f"OAuth {oauth_token}"
        else:
            # Basic auth
            self.auth = (username, password) if username and password else None
            self.auth_header = None
    
    def _get_full_path(self, path: str) -> str:
        """Получение полного пути к файлу"""
        if not path.startswith('/'):
            path = '/' + path
        return urljoin(self.base_url, quote(path.lstrip('/')))
    
    def _get_headers(self) -> Dict[str, str]:
        """Получение заголовков для запроса"""
        headers = {}
        if self.auth_header:
            headers['Authorization'] = self.auth_header
        return headers
    
    def _ensure_folder(self, folder_path: str) -> bool:
        """Создание папки если не существует"""
        try:
            full_path = self._get_full_path(folder_path)
            headers = self._get_headers()
            response = requests.request('MKCOL', full_path, auth=self.auth, headers=headers, timeout=10)
            if response.status_code in [201, 405]:  # 405 = уже существует
                return True
            return False
        except Exception as e:
            logger.warning(f"Error ensuring folder {folder_path}: {e}")
            return False
    
    def upload_file(self, file_path: str, remote_dir: str = None) -> Optional[str]:
        """Загрузка файла на WebDAV в папку remote_dir (по умолчанию GameSync)"""
        try:
            file_name = Path(file_path).name
            directory = (remote_dir or self.folder_name).strip("/")
            # Создаем папку назначения, если нужно
            self._ensure_folder(directory)
            remote_file_path = f"{directory}/{file_name}"
            
            full_path = self._get_full_path(remote_file_path)
            headers = self._get_headers()
            
            with open(file_path, 'rb') as f:
                response = requests.put(full_path, data=f, auth=self.auth, headers=headers, timeout=60)
            
            if response.status_code in [201, 204]:
                logger.info(f"Uploaded file: {remote_file_path}")
                return remote_file_path  # Возвращаем путь как ID
            else:
                logger.error(f"Upload failed: {response.status_code} - {response.text}")
                return None
        except Exception as e:
            logger.error(f"Error uploading file: {e}")
            return None
    
    def download_file(self, file_id: str, save_path: str) -> bool:
        """Скачивание файла с WebDAV"""
        try:
            # file_id это путь к файлу
            full_path = self._get_full_path(file_id)
            headers = self._get_headers()
            
            response = requests.get(full_path, auth=self.auth, headers=headers, timeout=60, stream=True)
            
            if response.status_code == 200:
                Path(save_path).parent.mkdir(parents=True, exist_ok=True)
                with open(save_path, 'wb') as f:
                    for chunk in response.iter_content(chunk_size=8192):
                        f.write(chunk)
                logger.info(f"Downloaded file: {save_path}")
                return True
            else:
                logger.error(f"Download failed: {response.status_code} - {response.text}")
                return False
        except Exception as e:
            logger.error(f"Error downloading file: {e}")
            return False
    
    def find_file(self, file_name: str, folder_name: str = "GameSync") -> Optional[str]:
        """Поиск файла по имени"""
        try:
            search_path = f"{folder_name}/{file_name}"
            full_path = self._get_full_path(search_path)
            headers = self._get_headers()
            
            response = requests.head(full_path, auth=self.auth, headers=headers, timeout=10)
            
            if response.status_code == 200:
                return search_path
            return None
        except Exception as e:
            logger.error(f"Error finding file: {e}")
            return None
    
    def get_file_info(self, file_id: str) -> Optional[Dict[str, Any]]:
        """Получение информации о файле"""
        try:
            full_path = self._get_full_path(file_id)
            headers = self._get_headers()
            
            response = requests.head(full_path, auth=self.auth, headers=headers, timeout=10)
            
            if response.status_code == 200:
                return {
                    'id': file_id,
                    'name': Path(file_id).name,
                    'size': int(response.headers.get('Content-Length', 0)),
                    'modifiedTime': response.headers.get('Last-Modified', ''),
                    'createdTime': response.headers.get('Date', '')
                }
            return None
        except Exception as e:
            logger.error(f"Error getting file info: {e}")
            return None
    
    def list_files(self, folder_name: str = "GameSync") -> list:
        """Список файлов в папке"""
        try:
            folder_path = self._get_full_path(folder_name)
            headers = self._get_headers()
            
            # PROPFIND для получения списка файлов
            response = requests.request('PROPFIND', folder_path, auth=self.auth, headers=headers, timeout=10)
            
            if response.status_code == 207:
                # Парсим XML ответ (упрощенный вариант)
                import xml.etree.ElementTree as ET
                root = ET.fromstring(response.content)
                files = []
                for response_elem in root.findall('.//{DAV:}response'):
                    href = response_elem.find('.//{DAV:}href')
                    if href is not None:
                        file_path = href.text
                        if file_path and not file_path.endswith('/'):
                            files.append({'name': Path(file_path).name, 'path': file_path})
                return files
            return []
        except Exception as e:
            logger.error(f"Error listing files: {e}")
            return []
    
    def test_connection(self) -> Dict[str, Any]:
        """Тест подключения к WebDAV"""
        try:
            # Пробуем выполнить PROPFIND на корневую папку
            headers = self._get_headers()
            # Параметр Depth передаётся через заголовок, а не как аргумент функции
            if "Depth" not in headers:
                headers["Depth"] = "0"
            response = requests.request('PROPFIND', self.base_url, auth=self.auth, headers=headers, timeout=10)
            
            if response.status_code in [207, 200, 301, 302]:
                return {
                    "success": True,
                    "message": "Подключение успешно"
                }
            else:
                return {
                    "success": False,
                    "error": f"HTTP {response.status_code}",
                    "message": "Ошибка подключения"
                }
        except Exception as e:
            logger.error(f"Connection test failed: {e}")
            return {
                "success": False,
                "error": str(e),
                "message": "Ошибка подключения"
            }
