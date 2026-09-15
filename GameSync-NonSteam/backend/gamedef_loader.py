import json
import logging
from pathlib import Path
from typing import Any, Dict, Optional

logger = logging.getLogger(__name__)

_RAW_GAMEDEF_MAP: Optional[Dict[str, Any]] = None


def _get_gamedef_path() -> Path:
    """
    Путь до локальной копии базы игр OpenCloudSaves.
    Файл находится прямо в backend: oc_gamedef_map.json
    """
    backend_dir = Path(__file__).parent
    return backend_dir / "oc_gamedef_map.json"


def load_raw_gamedef_map() -> Dict[str, Any]:
    """
    Ленивая загрузка оригинальной базы игр OpenCloudSaves.
    Если файл не найден или не читается, возвращает пустой словарь.
    """
    global _RAW_GAMEDEF_MAP

    if _RAW_GAMEDEF_MAP is not None:
        return _RAW_GAMEDEF_MAP

    path = _get_gamedef_path()
    if not path.exists():
        logger.info(f"OpenCloudSaves gamedef_map.json not found at {path}")
        _RAW_GAMEDEF_MAP = {}
        return _RAW_GAMEDEF_MAP

    try:
        with path.open("r", encoding="utf-8") as f:
            _RAW_GAMEDEF_MAP = json.load(f)
        logger.info(f"Loaded OpenCloudSaves gamedef_map.json with {len(_RAW_GAMEDEF_MAP)} entries")
    except Exception as e:
        logger.error(f"Error loading gamedef_map.json from {path}: {e}")
        _RAW_GAMEDEF_MAP = {}

    return _RAW_GAMEDEF_MAP

