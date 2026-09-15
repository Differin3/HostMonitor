"""Источник путей сохранений из манифеста Ludusavi.

Ludusavi (https://github.com/mtkennerly/ludusavi-manifest) — большая
поддерживаемая база путей сохранений для Windows/Linux/Proton. Манифест
скачивается один раз и кэшируется в ~/.config/gamesync, затем строится
компактный индекс для быстрого поиска.
"""

import json
import logging
import os
import time
import urllib.request
from pathlib import Path
from typing import Dict, List, Optional

logger = logging.getLogger(__name__)

MANIFEST_URL = os.environ.get(
    "GAMESYNC_LUDUSAVI_MANIFEST_URL",
    "https://raw.githubusercontent.com/mtkennerly/ludusavi-manifest/master/data/manifest.yaml",
)
CACHE_TTL = 30 * 24 * 3600  # 30 дней

# Плейсхолдеры Ludusavi -> путь внутри drive_c префикса PortProton.
_PLACEHOLDERS = {
    "<home>": "users/deck",
    "<winAppData>": "users/deck/AppData/Roaming",
    "<winLocalAppData>": "users/deck/AppData/Local",
    "<winLocalAppDataLow>": "users/deck/AppData/LocalLow",
    "<winDocuments>": "users/deck/Documents",
    "<winSavedGames>": "users/deck/Saved Games",
    "<winProgramData>": "ProgramData",
}

# Установочные/корневые плейсхолдеры — это не сохранения.
_SKIP_TOKENS = ("<base>", "<root>", "<storeGameId>")

_FILE_EXTENSIONS = {
    ".sav", ".save", ".dat", ".ini", ".cfg", ".json", ".xml", ".bin",
    ".txt", ".log", ".bak", ".profile",
}

_index_cache: Optional[Dict] = None
_token_index: Optional[Dict[str, List[int]]] = None


def _data_dir() -> Path:
    override = os.environ.get("GAMESYNC_LUDUSAVI_MANIFEST_PATH")
    if override:
        return Path(override).parent
    return Path.home() / ".config" / "gamesync"


def _manifest_path() -> Path:
    override = os.environ.get("GAMESYNC_LUDUSAVI_MANIFEST_PATH")
    if override:
        return Path(override)
    return _data_dir() / "ludusavi_manifest.yaml"


def _index_path() -> Path:
    return _data_dir() / "ludusavi_index.json"


def _normalize(text: str) -> str:
    return "".join(c for c in (text or "").lower() if c.isalnum() or c.isspace()).strip()


def _similarity(a: str, b: str) -> float:
    from difflib import SequenceMatcher
    if not a or not b:
        return 0.0
    return SequenceMatcher(None, a, b).ratio()


def _convert_placeholder(path: str) -> Optional[str]:
    """Преобразовать путь Ludusavi в путь относительно drive_c."""
    if not path:
        return None
    if any(token in path for token in _SKIP_TOKENS):
        return None

    p = path.replace("\\", "/")
    for key, value in _PLACEHOLDERS.items():
        p = p.replace(key, value)

    if "<" in p:  # остались неизвестные плейсхолдеры
        return None

    p = p.lstrip("/")

    # Если это файл/глоб — берём папку
    last = p.rsplit("/", 1)[-1]
    if "*" in last:
        p = p.rsplit("/", 1)[0] if "/" in p else ""
    elif "." in last and Path(last).suffix.lower() in _FILE_EXTENSIONS:
        p = p.rsplit("/", 1)[0] if "/" in p else ""

    p = p.strip("/")
    return p or None


def _download(url: str, dest: Path) -> bool:
    dest.parent.mkdir(parents=True, exist_ok=True)
    tmp = dest.with_suffix(dest.suffix + ".tmp")
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "GameSync-NonSteam"})
        with urllib.request.urlopen(req, timeout=120) as response:
            with open(tmp, "wb") as f:
                while True:
                    chunk = response.read(1 << 20)
                    if not chunk:
                        break
                    f.write(chunk)
        os.replace(tmp, dest)
        return True
    except Exception as e:
        logger.warning(f"Failed to download Ludusavi manifest: {e}")
        try:
            if tmp.exists():
                tmp.unlink()
        except OSError:
            pass
        return False


def _manifest_fresh() -> bool:
    path = _manifest_path()
    if not path.exists():
        return False
    try:
        return (time.time() - path.stat().st_mtime) < CACHE_TTL
    except OSError:
        return False


def _build_index(manifest: Dict) -> Dict:
    entries: List[Dict] = []
    exact: Dict[str, int] = {}
    if not isinstance(manifest, dict):
        return {"entries": entries, "exact": exact}

    for game_id, entry in manifest.items():
        if not isinstance(entry, dict):
            continue
        name = entry.get("name") or game_id
        files = entry.get("files") or {}
        paths: List[str] = []
        for file_path, meta in files.items():
            tags = (meta or {}).get("tags") or []
            if tags and not any(t in ("save", "config") for t in tags):
                continue
            rel = _convert_placeholder(file_path)
            if rel and rel not in paths:
                paths.append(rel)
        if not paths:
            continue
        norm = _normalize(name)
        if not norm:
            continue
        idx = len(entries)
        entries.append({"name": name, "norm": norm, "paths": paths[:10]})
        exact.setdefault(norm, idx)

    return {"entries": entries, "exact": exact}


def _save_index(index: Dict) -> None:
    try:
        path = _index_path()
        path.parent.mkdir(parents=True, exist_ok=True)
        with open(path, "w", encoding="utf-8") as f:
            json.dump(index, f, ensure_ascii=False)
    except Exception as e:
        logger.warning(f"Failed to save Ludusavi index: {e}")


def _load_index(force: bool = False) -> Dict:
    global _index_cache
    if _index_cache is not None and not force:
        return _index_cache

    manifest = _manifest_path()
    index_file = _index_path()

    # 1) Свежий компактный индекс
    try:
        if index_file.exists() and manifest.exists() and index_file.stat().st_mtime >= manifest.stat().st_mtime:
            with open(index_file, "r", encoding="utf-8") as f:
                _index_cache = json.load(f)
            _build_token_index()
            return _index_cache
    except Exception as e:
        logger.warning(f"Failed to read Ludusavi index: {e}")

    # 2) Манифест: скачать при необходимости
    if force or not _manifest_fresh():
        if not _download(MANIFEST_URL, manifest) and not manifest.exists():
            _index_cache = {"entries": [], "exact": {}}
            _build_token_index()
            return _index_cache

    # 3) Разобрать YAML и построить индекс
    try:
        import yaml  # type: ignore
        with open(manifest, "r", encoding="utf-8") as f:
            raw = yaml.safe_load(f)
        _index_cache = _build_index(raw)
        _save_index(_index_cache)
    except Exception as e:
        logger.warning(f"Failed to parse Ludusavi manifest: {e}")
        _index_cache = {"entries": [], "exact": {}}

    _build_token_index()
    return _index_cache


def _build_token_index() -> None:
    global _token_index
    tokens: Dict[str, List[int]] = {}
    if not _index_cache:
        _token_index = tokens
        return
    for idx, entry in enumerate(_index_cache.get("entries", [])):
        for token in set(entry["norm"].split()):
            if len(token) >= 3:
                tokens.setdefault(token, []).append(idx)
    _token_index = tokens


def get_known_save_paths_for_game(game_name: str) -> List[str]:
    """Найти пути сохранений игры в базе Ludusavi."""
    if not game_name:
        return []

    index = _load_index()
    entries = index.get("entries") or []
    if not entries:
        return []

    norm = _normalize(game_name)
    if not norm:
        return []

    # Точное совпадение
    exact_idx = index.get("exact", {}).get(norm)
    if exact_idx is not None:
        return list(entries[exact_idx]["paths"])

    # Нечёткое по общим словам
    candidates: Dict[int, int] = {}
    for token in set(norm.split()):
        if len(token) < 3:
            continue
        for idx in (_token_index or {}).get(token, []):
            candidates[idx] = candidates.get(idx, 0) + 1
    if not candidates:
        return []

    best_idx = None
    best_score = 0.0
    for idx in candidates:
        score = _similarity(norm, entries[idx]["norm"])
        if score > best_score:
            best_score = score
            best_idx = idx

    if best_idx is not None and best_score >= 0.85:
        return list(entries[best_idx]["paths"])
    return []


def clear_cache() -> None:
    """Сбросить кэш (используется при полной очистке данных)."""
    global _index_cache, _token_index
    _index_cache = None
    _token_index = None
