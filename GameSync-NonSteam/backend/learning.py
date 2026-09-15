"""Запоминание ручных путей сохранений и подсказки для похожих игр.

Когда пользователь вручную добавляет путь, он сохраняется относительно
папки drive_c префикса. Для новой игры с похожим названием такие пути
подставляются в её префикс.
"""

import json
import logging
from difflib import SequenceMatcher
from pathlib import Path
from typing import Dict, List, Optional

logger = logging.getLogger(__name__)

CONFIG_DIR = Path.home() / ".config" / "gamesync"
LEARNED_FILE = CONFIG_DIR / "learned_paths.json"


def _normalize(text: str) -> str:
    return "".join(c for c in (text or "").lower() if c.isalnum() or c.isspace()).strip()


_EDITION_SUFFIXES = [
    "game of the year edition", "goty edition", "game of the year", "goty",
    "definitive edition", "complete edition", "ultimate edition",
    "deluxe edition", "enhanced edition", "gold edition", "premium edition",
    "special edition", "definitive", "remastered", "remaster",
]


def _strip_edition(text: str) -> str:
    norm = text
    changed = True
    while changed:
        changed = False
        for suffix in _EDITION_SUFFIXES:
            if norm != suffix and norm.endswith(" " + suffix):
                norm = norm[: -(len(suffix) + 1)].strip()
                changed = True
                break
    return norm


def _similarity(a: str, b: str) -> float:
    if not a or not b:
        return 0.0
    return SequenceMatcher(None, a, b).ratio()


def _load() -> Dict[str, Dict]:
    if not LEARNED_FILE.exists():
        return {}
    try:
        with open(LEARNED_FILE, "r", encoding="utf-8") as f:
            data = json.load(f)
        return data if isinstance(data, dict) else {}
    except Exception as e:
        logger.warning(f"Failed to load learned paths: {e}")
        return {}


def _save(data: Dict[str, Dict]) -> None:
    try:
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        with open(LEARNED_FILE, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=2, ensure_ascii=False)
        try:
            import os
            os.chmod(LEARNED_FILE, 0o600)
        except OSError:
            pass
    except Exception as e:
        logger.warning(f"Failed to save learned paths: {e}")


def _rel_under_prefix(path: Path) -> Optional[str]:
    """Путь относительно drive_c какого-либо префикса PortProton (если внутри)."""
    try:
        from portproton_scanner import get_portproton_prefixes_path
        prefixes = get_portproton_prefixes_path()
    except Exception:
        return None
    if not prefixes or not prefixes.is_dir():
        return None
    try:
        for prefix in prefixes.iterdir():
            drive_c = prefix / "drive_c"
            if not drive_c.is_dir():
                continue
            try:
                rel = path.relative_to(drive_c)
            except ValueError:
                continue
            return str(rel).replace("\\", "/")
    except (PermissionError, OSError):
        pass
    return None


def record_path(game_name: str, path: str) -> None:
    """Запомнить ручной путь (в виде относительного к drive_c)."""
    if not game_name or not path:
        return
    rel = _rel_under_prefix(Path(path))
    if not rel:
        return

    key = _normalize(game_name)
    if not key:
        return

    data = _load()
    entry = data.setdefault(key, {"name": game_name, "paths": []})
    if rel not in entry["paths"]:
        entry["paths"].append(rel)
        _save(data)
        logger.info(f"Learned path for '{game_name}': {rel}")


def suggest_paths(game_name: str, drive_c: Path) -> List[str]:
    """Подсказать существующие пути для игры на основе изученных похожих игр."""
    if not game_name:
        return []
    norm = _normalize(game_name)
    if not norm:
        return []

    data = _load()
    if not data:
        return []

    results: List[str] = []
    target = _strip_edition(norm)
    for key, entry in data.items():
        if key == norm:
            score = 1.0
        else:
            score = max(_similarity(norm, key), _similarity(target, _strip_edition(key)))
        if score < 0.85:
            continue
        for rel in entry.get("paths", []):
            candidate = drive_c / rel
            if candidate.exists():
                results.append(str(candidate))

    return list(dict.fromkeys(results))


def clear() -> None:
    try:
        if LEARNED_FILE.exists():
            LEARNED_FILE.unlink()
    except OSError:
        pass
