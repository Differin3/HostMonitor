import json
import logging
import os
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional, Any

logger = logging.getLogger(__name__)

CONFIG_DIR = Path.home() / ".config" / "gamesync"
CONFIG_FILE = CONFIG_DIR / "games.json"
SYNCED_GAMES_FILE = CONFIG_DIR / "synced_games.json"
STORAGE_CONFIG_FILE = CONFIG_DIR / "storage_config.json"


def _ensure_config_dir() -> None:
    """Создаёт директорию конфига и ограничивает доступ владельцу."""
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    try:
        os.chmod(CONFIG_DIR, 0o700)
    except OSError:
        pass


def _restrict_permissions(path: Path) -> None:
    """Ограничивает доступ к файлу (в нём могут быть секреты)."""
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass

def load_game_configs() -> Dict[str, Dict]:
    """Загрузка конфигурации игр"""
    if not CONFIG_FILE.exists():
        return {}
    
    try:
        with open(CONFIG_FILE, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        logger.error(f"Error loading game configs: {e}")
        return {}

def save_game_configs(configs: Dict[str, Dict]):
    """Сохранение конфигурации игр"""
    _ensure_config_dir()
    
    try:
        with open(CONFIG_FILE, 'w', encoding='utf-8') as f:
            json.dump(configs, f, indent=2, ensure_ascii=False)
        _restrict_permissions(CONFIG_FILE)
        logger.info(f"Saved game configs to {CONFIG_FILE}")
    except Exception as e:
        logger.error(f"Error saving game configs: {e}")
        raise

def get_game_config(game_name: str) -> Optional[Dict]:
    """Получение конфигурации игры"""
    configs = load_game_configs()
    return configs.get(game_name)

def update_game_config(game_name: str, save_paths: List[str], enabled: bool = True, exclude_paths: Optional[List[str]] = None):
    """Обновление конфигурации игры."""
    configs = load_game_configs()
    existing = configs.get(game_name) or {}
    configs[game_name] = {
        "savePaths": save_paths,
        "enabled": enabled,
        "excludePaths": exclude_paths if exclude_paths is not None else existing.get("excludePaths", []),
    }
    save_game_configs(configs)


def get_excluded_paths(game_name: str) -> List[str]:
    """Пути, исключённые из синхронизации для игры."""
    config = get_game_config(game_name) or {}
    return config.get("excludePaths") or []

def remove_game_config(game_name: str):
    """Удаление конфигурации игры"""
    configs = load_game_configs()
    if game_name in configs:
        del configs[game_name]
        save_game_configs(configs)

def validate_path(path: str) -> Dict[str, Any]:
    """Валидация пути сохранений"""
    path_obj = Path(path)
    
    if not path_obj.exists():
        return {"valid": False, "error": "Путь не существует"}
    
    if not path_obj.is_absolute():
        return {"valid": False, "error": "Путь должен быть абсолютным"}
    
    return {"valid": True, "path": str(path_obj.absolute())}

def load_synced_games() -> Dict[str, Dict]:
    """Загрузка списка синхронизированных игр"""
    if not SYNCED_GAMES_FILE.exists():
        return {}
    
    try:
        with open(SYNCED_GAMES_FILE, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        logger.error(f"Error loading synced games: {e}")
        return {}

def save_synced_games(synced_games: Dict[str, Dict]):
    """Сохранение списка синхронизированных игр"""
    _ensure_config_dir()
    
    try:
        with open(SYNCED_GAMES_FILE, 'w', encoding='utf-8') as f:
            json.dump(synced_games, f, indent=2, ensure_ascii=False)
        _restrict_permissions(SYNCED_GAMES_FILE)
        logger.info(f"Saved synced games to {SYNCED_GAMES_FILE}")
    except Exception as e:
        logger.error(f"Error saving synced games: {e}")
        raise

def get_synced_games() -> List[Dict]:
    """Получение списка синхронизированных игр"""
    synced_games = load_synced_games()
    return [{"gameName": name, **data} for name, data in synced_games.items()]

def add_synced_game(game_name: str, file_id: Optional[str] = None, file_size: Optional[int] = None):
    """Добавление игры в список синхронизированных"""
    synced_games = load_synced_games()
    synced_games[game_name] = {
        "lastSync": datetime.now().isoformat(),
        "fileId": file_id,
        "fileSize": file_size  # Сохраняем размер файла локально
    }
    save_synced_games(synced_games)

def save_storage_config(provider: str, **kwargs):
    """Сохранение конфигурации хранилища"""
    _ensure_config_dir()
    config = {
        "provider": provider,
        **kwargs,
    }
    try:
        with open(STORAGE_CONFIG_FILE, 'w', encoding='utf-8') as f:
            json.dump(config, f, indent=2, ensure_ascii=False)
        _restrict_permissions(STORAGE_CONFIG_FILE)
        logger.info(f"Saved storage config to {STORAGE_CONFIG_FILE}")
    except Exception as e:
        logger.error(f"Error saving storage config: {e}")
        raise

def load_storage_config() -> Dict[str, Any]:
    """Загрузка конфигурации хранилища"""
    if not STORAGE_CONFIG_FILE.exists():
        # Конфигурация по умолчанию: WebDAV без настроек
        return {"provider": "webdav"}
    try:
        with open(STORAGE_CONFIG_FILE, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        logger.error(f"Error loading storage config: {e}")
        return {"provider": "webdav"}
