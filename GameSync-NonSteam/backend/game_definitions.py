import logging
from difflib import SequenceMatcher
from typing import Dict, List, Optional

from gamedef_loader import load_raw_gamedef_map

logger = logging.getLogger(__name__)

# Локальная мини-база (самые важные игры для тебя).
# Ключи: нормализованные имена игр (lowercase, без лишних символов).
# Значения: список путей ОТНОСИТЕЛЬНО папки drive_c.
LOCAL_GAME_SAVE_DEFINITIONS: Dict[str, List[str]] = {
    # Expedition 33 (по твоему примеру с Sandfall)
    # Учитываем варианты написания: с/без s и с/без пробела.
    "expedition 33": [
        "users/deck/AppData/Local/Sandfall/Saved/SaveGames",
    ],
    "expeditions 33": [
        "users/deck/AppData/Local/Sandfall/Saved/SaveGames",
    ],
    "expedition33": [
        "users/deck/AppData/Local/Sandfall/Saved/SaveGames",
    ],
    "expeditions33": [
        "users/deck/AppData/Local/Sandfall/Saved/SaveGames",
    ],
}


def _normalize_name(name: str) -> str:
    """Нормализация имени игры для поиска в базе."""
    name = name.lower()
    return "".join(c for c in name if c.isalnum() or c.isspace()).strip()


def _convert_windows_path_to_portproton_rel(path: str) -> Optional[str]:
    r"""
    Грубая конвертация Windows-пути из gamedef_map.json
    в относительный путь внутри drive_c префикса PortProton.

    Поддерживаем только основные варианты с переменными окружения:
    %LOCALAPPDATA%, %APPDATA%, %USERPROFILE%\Documents, Saved Games и My Games.
    """
    if not path:
        return None

    original = path

    # Нормализуем слэши
    p = path.replace("\\", "/")

    # %LOCALAPPDATA%\Foo -> users/deck/AppData/Local/Foo
    for token in ("%LOCALAPPDATA%", "%LocalAppData%", "%localappdata%"):
        if token in p:
            p = p.replace(token, "users/deck/AppData/Local")
            break

    # %APPDATA%\Foo -> users/deck/AppData/Roaming/Foo
    for token in ("%APPDATA%", "%AppData%", "%appdata%"):
        if token in p:
            p = p.replace(token, "users/deck/AppData/Roaming")
            break

    # %USERPROFILE%\Documents\My Games -> users/deck/Documents/My Games
    for token in ("%USERPROFILE%", "%HOMEPATH%", "%UserProfile%", "%HomePath%"):
        if token in p and "/Documents/My Games" in p:
            p = p.replace(token + "/Documents/My Games", "users/deck/Documents/My Games")
            break

    # %USERPROFILE%\Documents -> users/deck/Documents
    for token in ("%USERPROFILE%", "%HOMEPATH%", "%UserProfile%", "%HomePath%"):
        if token in p and "/Documents" in p:
            p = p.replace(token + "/Documents", "users/deck/Documents")
            break

    # %USERPROFILE%\Saved Games -> users/deck/Saved Games
    for token in ("%USERPROFILE%", "%HOMEPATH%", "%UserProfile%", "%HomePath%"):
        if token in p and "/Saved Games" in p:
            p = p.replace(token + "/Saved Games", "users/deck/Saved Games")
            break

    # Обрезаем возможный префикс вроде "C:/Users/..."
    if p.startswith("C:/Users/") or p.startswith("C:/users/"):
        # Заменяем "C:/Users/XXX" на "users/deck"
        parts = p.split("/")
        # ["C:", "Users", "<Name>", ...]
        if len(parts) >= 4:
            p = "users/deck/" + "/".join(parts[3:])

    # Убираем начальные слэши
    while p.startswith("/"):
        p = p[1:]

    if not p or p == original:
        # Ничего внятного не смогли сконвертировать
        return None

    return p


def _title_score(a: str, b: str) -> float:
    """Оценка схожести нормализованных названий (0..1)."""
    if not a or not b:
        return 0.0
    if a == b:
        return 1.0
    if a in b or b in a:
        return 0.9
    a_words = set(a.split())
    b_words = set(b.split())
    if a_words and b_words and (a_words <= b_words or b_words <= a_words):
        return 0.85
    return SequenceMatcher(None, a, b).ratio()


def _find_in_openclouds(game_name: str) -> List[str]:
    """Поиск путей в большой базе OpenCloudSaves (агрегирует близкие совпадения)."""
    raw = load_raw_gamedef_map()
    if not raw:
        return []

    normalized = _normalize_name(game_name)
    if not normalized:
        return []

    matches: List[tuple] = []
    for key, entry in raw.items():
        if not isinstance(entry, dict):
            continue
        names = [key, entry.get("display_name", "")]
        best = max(
            (_title_score(normalized, _normalize_name(n)) for n in names if n),
            default=0.0,
        )
        if best >= 0.85:
            matches.append((best, entry))

    if not matches:
        return []

    matches.sort(key=lambda item: item[0], reverse=True)
    top_score = matches[0][0]

    rel_paths: List[str] = []
    for score, entry in matches:
        # берём только записи, близкие к лучшему совпадению
        if score < top_score - 0.05:
            break
        for p in entry.get("win_path") or []:
            win_path = p.get("path") if isinstance(p, dict) else p
            rel = _convert_windows_path_to_portproton_rel(win_path)
            if rel and rel not in rel_paths:
                rel_paths.append(rel)

    return rel_paths


def get_known_save_paths_for_game(game_name: str) -> List[str]:
    """
    Вернуть список относительных путей (относительно drive_c) для известной игры.
    Приоритет:
    1) Локальная мини-база (ручные пути).
    2) Конвертированные данные из OpenCloudSaves gamedef_map.json.

    Собирает все подходящие записи (у игры может быть несколько папок сохранений).
    """
    if not game_name:
        return []

    normalized = _normalize_name(game_name)
    result: List[str] = []

    # 1. Локальная база
    if normalized in LOCAL_GAME_SAVE_DEFINITIONS:
        result.extend(LOCAL_GAME_SAVE_DEFINITIONS[normalized])
    else:
        for key, paths in LOCAL_GAME_SAVE_DEFINITIONS.items():
            if _title_score(normalized, key) >= 0.85:
                logger.debug(f"Matched game '{game_name}' to local definition '{key}'")
                result.extend(paths)

    # 2. База OpenCloudSaves
    oc_paths = _find_in_openclouds(game_name)
    if oc_paths:
        logger.debug(f"Matched game '{game_name}' to OpenCloudSaves, paths={oc_paths}")
        result.extend(oc_paths)

    # 3. База Ludusavi (скачивается и кэшируется автоматически)
    try:
        from ludusavi import get_known_save_paths_for_game as ludusavi_paths
        lu_paths = ludusavi_paths(game_name)
    except Exception as e:
        logger.debug(f"Ludusavi lookup failed for '{game_name}': {e}")
        lu_paths = []
    if lu_paths:
        logger.debug(f"Matched game '{game_name}' to Ludusavi, paths={lu_paths}")
        result.extend(lu_paths)

    # Убираем дубликаты, сохраняя порядок
    return list(dict.fromkeys(result))


