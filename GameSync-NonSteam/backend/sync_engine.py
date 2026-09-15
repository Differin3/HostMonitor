import os
import re
import tarfile
import tempfile
import logging
from pathlib import Path
from typing import List, Optional

logger = logging.getLogger(__name__)


def _sanitize_name(name: str) -> str:
    """Приводит имя игры к безопасному для файловой системы виду."""
    safe = re.sub(r"[^\w.\- ]", "_", name, flags=re.UNICODE).strip()
    safe = re.sub(r"\s+", "_", safe)
    return safe or "game"


def create_backup(game_name: str, save_paths: List[str], output_dir: Optional[str] = None, incremental: bool = False, last_backup_time: Optional[float] = None) -> Optional[str]:
    """Создание архива сохранений игры"""
    if not save_paths:
        logger.warning(f"No save paths provided for {game_name}")
        return None
    
    # Создаем временную папку для архива
    if output_dir:
        output_path = Path(output_dir)
    else:
        output_path = Path(tempfile.gettempdir())
    
    output_path.mkdir(parents=True, exist_ok=True)
    
    # Стабильное имя архива: повторная синхронизация перезаписывает облачную
    # копию, а не создаёт новую с меткой времени (иначе хранилище растёт бесконечно).
    archive_name = f"{_sanitize_name(game_name)}.tar.gz"
    archive_path = output_path / archive_name
    
    try:
        files_added = 0
        with tarfile.open(archive_path, "w:gz", compresslevel=6) as tar:
            for save_path in save_paths:
                path = Path(save_path)
                if not path.exists():
                    logger.warning(f"Save path does not exist: {save_path}")
                    continue
                
                # Добавляем файлы в архив
                if path.is_file():
                    # Инкрементальный бэкап: проверяем время модификации
                    if incremental and last_backup_time:
                        if os.path.getmtime(path) <= last_backup_time:
                            continue
                    tar.add(path, arcname=path.name)
                    files_added += 1
                elif path.is_dir():
                    # Добавляем все файлы из директории
                    for root, dirs, files in os.walk(path):
                        for file in files:
                            file_path = Path(root) / file
                            # Инкрементальный бэкап: проверяем время модификации
                            if incremental and last_backup_time:
                                if os.path.getmtime(file_path) <= last_backup_time:
                                    continue
                            arcname = file_path.relative_to(path.parent if path.is_absolute() else Path.cwd())
                            tar.add(file_path, arcname=str(arcname))
                            files_added += 1
        
        if files_added == 0 and incremental:
            logger.info("No new files to backup (incremental)")
            os.remove(archive_path)
            return None
        
        logger.info(f"Created backup: {archive_path} ({archive_path.stat().st_size / 1024 / 1024:.2f} MB, {files_added} files)")
        return str(archive_path)
    except Exception as e:
        logger.error(f"Error creating backup: {e}")
        return None

def extract_backup(archive_path: str, extract_to: str) -> bool:
    """Извлечение архива сохранений"""
    try:
        archive = Path(archive_path)
        if not archive.exists():
            logger.error(f"Archive not found: {archive_path}")
            return False
        
        extract_path = Path(extract_to)
        extract_path.mkdir(parents=True, exist_ok=True)
        
        with tarfile.open(archive_path, "r:gz") as tar:
            tar.extractall(extract_to)
        
        logger.info(f"Extracted backup to: {extract_to}")
        return True
    except Exception as e:
        logger.error(f"Error extracting backup: {e}")
        return False
