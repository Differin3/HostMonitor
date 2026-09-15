"""Чтение non-Steam ярлыков Steam (shortcuts.vdf).

Даёт сопоставление игры с ярлыком Steam: appid (для compatdata/Steam Cloud),
StartDir/Exe (подсказка о префиксе PortProton) и название.
"""

import logging
import struct
from pathlib import Path
from typing import Any, Dict, List, Optional

logger = logging.getLogger(__name__)

_shortcuts_cache: Optional[List[Dict[str, Any]]] = None


# ---------------------------------------------------------------------------
# Разбор бинарного VDF (формат Steam shortcuts.vdf)
# ---------------------------------------------------------------------------

def _read_cstring(data: bytes, index: int):
    end = data.index(b"\x00", index)
    return data[index:end].decode("utf-8", "replace"), end + 1


def _parse_object(data: bytes, index: int):
    obj: Dict[str, Any] = {}
    while index < len(data):
        entry_type = data[index]
        index += 1
        if entry_type == 0x08:  # конец объекта
            return obj, index
        key, index = _read_cstring(data, index)
        if entry_type == 0x00:  # вложенный объект
            value, index = _parse_object(data, index)
        elif entry_type == 0x01:  # строка
            value, index = _read_cstring(data, index)
        elif entry_type == 0x02:  # int32
            value = struct.unpack("<i", data[index:index + 4])[0]
            index += 4
        elif entry_type == 0x07:  # uint64
            value = struct.unpack("<Q", data[index:index + 8])[0]
            index += 8
        else:
            logger.debug(f"Unknown VDF type {entry_type:#x}, stopping")
            return obj, index
        obj[key] = value
    return obj, index


def parse_vdf(data: bytes) -> Dict[str, Any]:
    obj, _ = _parse_object(data, 0)
    return obj


# ---------------------------------------------------------------------------
# Поиск файлов shortcuts.vdf
# ---------------------------------------------------------------------------

def _shortcut_files() -> List[Path]:
    home = Path.home()
    bases = [
        home / ".steam" / "steam" / "userdata",
        home / ".steam" / "root" / "userdata",
        home / ".local" / "share" / "Steam" / "userdata",
        home / ".var" / "app" / "com.valvesoftware.Steam" / ".local" / "share" / "Steam" / "userdata",
    ]
    files: List[Path] = []
    for base in bases:
        if not base.is_dir():
            continue
        try:
            for user_dir in base.iterdir():
                candidate = user_dir / "config" / "shortcuts.vdf"
                if candidate.is_file():
                    files.append(candidate)
        except (PermissionError, OSError):
            continue
    return files


def load_shortcuts(force: bool = False) -> List[Dict[str, Any]]:
    """Загрузить non-Steam ярлыки из всех профилей Steam."""
    global _shortcuts_cache
    if _shortcuts_cache is not None and not force:
        return _shortcuts_cache

    shortcuts: List[Dict[str, Any]] = []
    for path in _shortcut_files():
        try:
            root = parse_vdf(path.read_bytes())
        except Exception as e:
            logger.warning(f"Failed to parse {path}: {e}")
            continue
        container = root.get("shortcuts", {})
        if not isinstance(container, dict):
            continue
        for value in container.values():
            if not isinstance(value, dict):
                continue
            appid = value.get("appid")
            try:
                appid_unsigned = int(appid) & 0xFFFFFFFF if appid is not None else None
            except (TypeError, ValueError):
                appid_unsigned = None
            shortcuts.append({
                "appid": appid_unsigned,
                "appname": value.get("appname") or value.get("AppName") or "",
                "exe": value.get("exe") or value.get("Exe") or "",
                "startDir": value.get("StartDir") or value.get("startdir") or "",
                "launchOptions": value.get("LaunchOptions") or "",
            })

    _shortcuts_cache = shortcuts
    logger.info(f"Loaded {len(shortcuts)} Steam shortcuts")
    return shortcuts


def _normalize(text: str) -> str:
    return "".join(c for c in (text or "").lower() if c.isalnum() or c.isspace()).strip()


def _similarity(a: str, b: str) -> float:
    from difflib import SequenceMatcher
    if not a or not b:
        return 0.0
    return SequenceMatcher(None, a, b).ratio()


def find_shortcut_for_game(game_name: str) -> Optional[Dict[str, Any]]:
    """Найти ярлык Steam по названию игры (точное или нечёткое совпадение)."""
    if not game_name:
        return None
    target = _normalize(game_name)
    if not target:
        return None

    best: Optional[Dict[str, Any]] = None
    best_score = 0.0
    for shortcut in load_shortcuts():
        name = _normalize(shortcut.get("appname", ""))
        if not name:
            continue
        if name == target:
            return shortcut
        score = _similarity(target, name)
        if score > best_score:
            best_score = score
            best = shortcut
    if best is not None and best_score >= 0.85:
        return best
    return None


def prefix_hint_from_shortcut(shortcut: Dict[str, Any]) -> str:
    """Подсказка пути префикса из StartDir/Exe/LaunchOptions ярлыка."""
    for key in ("startDir", "exe", "launchOptions"):
        value = shortcut.get(key) or ""
        if "prefixes/" in value.replace("\\", "/"):
            return value
    return shortcut.get("startDir") or shortcut.get("exe") or ""


def clear_cache() -> None:
    global _shortcuts_cache
    _shortcuts_cache = None
