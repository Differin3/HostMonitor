import logging
import configparser
import time
from difflib import SequenceMatcher
from pathlib import Path
from typing import List, Dict, Any, Optional, Tuple

logger = logging.getLogger(__name__)

# Использование кэша
use_cache = True

# Возможные корни PortProton (по приоритету)
PORTPROTON_ROOT_CANDIDATES = [
    "PortProton",
    ".PortProton",
    ".local/share/PortProton",
]

# Шаблоны папок внутри Windows-профиля, где игры обычно хранят сохранения.
SAVE_ROOT_TEMPLATES = [
    "Documents/My Games",
    "Documents",
    "Saved Games",
    "AppData/LocalLow",
    "AppData/Local",
    "AppData/Roaming",
]

# Пользователи Windows, которых проверяем, если не удалось определить динамически.
FALLBACK_WINDOWS_USERS = ["steamuser", "deck", "user", "Public"]

# Стандартные пути (оставлено для обратной совместимости / внешнего использования).
STANDARD_SAVE_PATHS = [
    "users/steamuser/Documents",
    "users/steamuser/Documents/My Games",
    "users/steamuser/Saved Games",
    "users/steamuser/AppData/Local",
    "users/steamuser/AppData/Roaming",
    "users/deck/Documents",
    "users/deck/Documents/My Games",
    "users/deck/Saved Games",
    "users/deck/AppData/Local",
    "users/deck/AppData/Roaming",
]

# Расширения файлов сохранений
SAVE_EXTENSIONS = {
    ".sav", ".save", ".sav0", ".sav1", ".sav2", ".slot", ".dat", ".cfg",
    ".ini", ".json", ".xml", ".profile", ".es3", ".ess", ".d2s", ".nhl",
    ".rsv", ".sgs", ".gsv", ".ugc", ".bak",
}

# Частые служебные папки, которые не являются сохранениями.
IGNORED_FOLDER_NAMES = {
    "microsoft", "windows", "temp", "tmp", "cache", "crashdumps", "packages",
    "nvidia", "intel", "amd", "d3dcompiler", "logs", "log", "crashpad",
    "google", "mozilla", "wine", "proton",
}

# Служебные .desktop файлы, которые нужно игнорировать
IGNORED_DESKTOP_FILES = {"portproton.desktop", "readme.desktop"}

# Общие имена, которые не являются названиями игр
GENERIC_GAME_NAMES = {"portproton", "wine", "proton", "readme", "game", "launcher"}

# Финальные «издания», которые не входят в имя папки сохранений.
_EDITION_SUFFIXES = [
    "game of the year edition", "goty edition", "game of the year", "goty",
    "definitive edition", "complete edition", "ultimate edition",
    "deluxe edition", "enhanced edition", "gold edition", "premium edition",
    "collectors edition", "collector's edition", "special edition",
    "definitive", "remastered", "remaster",
]


def find_portproton_root() -> Optional[Path]:
    """Найти корневую папку PortProton."""
    home = Path.home()
    for rel in PORTPROTON_ROOT_CANDIDATES:
        candidate = home / rel
        if candidate.exists() and candidate.is_dir():
            return candidate
    logger.warning(f"PortProton root not found under {home}")
    return None


def get_portproton_prefixes_path() -> Optional[Path]:
    """Получить путь к папке префиксов PortProton."""
    root = find_portproton_root()
    if not root:
        return None
    for rel in ("prefixes", "data/prefixes"):
        candidate = root / rel
        if candidate.exists() and candidate.is_dir():
            return candidate
    return root / "prefixes"


# ---------------------------------------------------------------------------
# Нормализация и сравнение имён
# ---------------------------------------------------------------------------

def _normalize(text: str) -> str:
    """lowercase + только буквы/цифры/пробелы."""
    return "".join(c for c in (text or "").lower() if c.isalnum() or c.isspace()).strip()


def _normalize_title(text: str) -> str:
    """Нормализация названия с отсечением финальных слов-изданий (GOTY, Deluxe...)."""
    norm = _normalize(text)
    if not norm:
        return ""
    changed = True
    while changed:
        changed = False
        for suffix in _EDITION_SUFFIXES:
            if norm != suffix and norm.endswith(" " + suffix):
                norm = norm[: -(len(suffix) + 1)].strip()
                changed = True
                break
    return norm or _normalize(text)


def _similarity(a: str, b: str) -> float:
    if not a or not b:
        return 0.0
    return SequenceMatcher(None, a, b).ratio()


def _word_boundary_match(short: str, long: str) -> bool:
    """Есть ли `short` в `long` на границе слова (не внутри слова)."""
    idx = long.find(short)
    while idx != -1:
        before = long[idx - 1] if idx > 0 else " "
        after = long[idx + len(short)] if idx + len(short) < len(long) else " "
        if not before.isalnum() and not after.isalnum():
            return True
        idx = long.find(short, idx + 1)
    return False


def _name_match_score(game_name: str, folder_name: str) -> int:
    """Оценка совпадения имени игры и папки (0..100)."""
    a = _normalize_title(game_name)
    b = _normalize_title(folder_name)
    if not a or not b:
        return 0
    if a == b:
        return 100

    # Сравнение без пробелов: "Test Game" == "TestGame"
    a_flat = a.replace(" ", "")
    b_flat = b.replace(" ", "")
    if a_flat and a_flat == b_flat:
        return 95

    # Вхождение на границе слова (Doom -> Doom Eternal), но не Star -> Starfield
    if len(a) >= 4 and _word_boundary_match(a, b):
        return 80
    if len(b) >= 4 and _word_boundary_match(b, a):
        return 80

    # Вхождение без пробелов, но только для достаточно длинных строк
    if len(a_flat) >= 5 and a_flat in b_flat:
        return 78
    if len(b_flat) >= 5 and b_flat in a_flat:
        return 78

    a_words = {w for w in a.split() if len(w) >= 3}
    b_words = {w for w in b.split() if len(w) >= 3}
    if a_words and b_words and (a_words <= b_words or b_words <= a_words):
        return 65

    sim = _similarity(a_flat, b_flat)
    if sim >= 0.85:
        return int(50 * sim)
    return 0


def _names_match(game_name: str, folder_name: str) -> bool:
    """Совпадают ли имена (точное, вхождение, слова или нечёткое сходство)."""
    return _name_match_score(game_name, folder_name) > 0


# ---------------------------------------------------------------------------
# Парсинг .desktop
# ---------------------------------------------------------------------------

def parse_desktop_file(desktop_path: Path) -> Optional[Dict[str, str]]:
    """Парсинг .desktop файла.

    - поддерживает локализованные имена Name[xx];
    - отключает интерполяцию configparser, иначе строки вида %U/%f в Exec
      ломают разбор и игра не находится;
    - отбрасывает служебные/общие записи.
    """
    try:
        parser = configparser.ConfigParser(interpolation=None, strict=False)
        with open(desktop_path, "r", encoding="utf-8", errors="replace") as f:
            parser.read_file(f)

        if "Desktop Entry" not in parser:
            return None

        entry = parser["Desktop Entry"]

        name = entry.get("Name")
        if not name:
            for key, value in entry.items():
                if key.lower().startswith("name[") and value:
                    name = value
                    break
        if not name:
            name = desktop_path.stem

        name = name.strip()
        if not name or name.lower() in GENERIC_GAME_NAMES:
            return None

        return {
            "name": name,
            "exec": entry.get("Exec", "").strip(),
            "try_exec": entry.get("TryExec", "").strip(),
            "comment": entry.get("Comment", "").strip(),
            "icon": entry.get("Icon", "").strip(),
            "desktop_file": str(desktop_path),
        }
    except Exception as e:
        logger.error(f"Error parsing desktop file {desktop_path}: {e}")
        return None


# ---------------------------------------------------------------------------
# Поиск префикса игры
# ---------------------------------------------------------------------------

def _iter_prefixes(prefixes_path: Path) -> List[Path]:
    if not prefixes_path or not prefixes_path.is_dir():
        return []
    result = []
    try:
        for item in prefixes_path.iterdir():
            if item.is_dir() and (item / "drive_c").exists():
                result.append(item)
    except (PermissionError, OSError) as e:
        logger.debug(f"Cannot list prefixes in {prefixes_path}: {e}")
    return result


def _prefix_hint_from_exec(exec_cmd: str) -> Optional[str]:
    """Пытается вытащить имя префикса из команды Exec."""
    if not exec_cmd:
        return None
    for token in exec_cmd.replace('"', " ").replace("'", " ").split():
        normalized = token.replace("\\", "/")
        if "/prefixes/" in normalized:
            tail = normalized.split("/prefixes/", 1)[1].strip("/")
            if tail:
                return tail.split("/")[0]
    return None


def _prefix_hint_from_path(path: str) -> Optional[str]:
    """Имя префикса из произвольного пути (StartDir/Exe ярлыка Steam)."""
    if not path:
        return None
    normalized = path.replace("\\", "/")
    if "prefixes/" not in normalized:
        return None
    tail = normalized.split("prefixes/", 1)[1].strip("/")
    return tail.split("/")[0] if tail else None


def find_prefix_for_game(
    game_name: str,
    desktop_file_path: str,
    prefixes_path: Path,
    exec_cmd: str = "",
    hint_path: str = "",
) -> Optional[Path]:
    """Найти префикс для игры по имени, .desktop, Exec и подсказке пути."""
    prefixes = _iter_prefixes(prefixes_path)
    if not prefixes:
        return None

    desktop_stem = Path(desktop_file_path).stem if desktop_file_path else ""
    targets = [
        t for t in (
            game_name,
            desktop_stem,
            _prefix_hint_from_exec(exec_cmd),
            _prefix_hint_from_path(hint_path),
        ) if t
    ]

    best: Optional[Path] = None
    best_score = 0
    for prefix in prefixes:
        if prefix.name.upper() == "DEFAULT":
            continue
        for target in targets:
            score = _name_match_score(target, prefix.name)
            if score > best_score:
                best_score = score
                best = prefix

    if best and best_score >= 65:
        return best

    # DEFAULT префикс как fallback (многие игры PortProton используют его)
    default_prefix = prefixes_path / "DEFAULT"
    if default_prefix.exists() and (default_prefix / "drive_c").exists():
        logger.debug(f"Using DEFAULT prefix for game '{game_name}'")
        return default_prefix

    logger.debug(f"Prefix not found for game '{game_name}', desktop: {desktop_file_path}")
    return None


# ---------------------------------------------------------------------------
# Поиск путей сохранений
# ---------------------------------------------------------------------------

def _list_windows_users(drive_c: Path) -> List[str]:
    users_dir = drive_c / "users"
    names: List[str] = []
    if users_dir.is_dir():
        try:
            for item in users_dir.iterdir():
                if item.is_dir():
                    names.append(item.name)
        except (PermissionError, OSError):
            pass
    for user in FALLBACK_WINDOWS_USERS:
        if user not in names and (users_dir / user).is_dir():
            names.append(user)
    return names or FALLBACK_WINDOWS_USERS[:2]


def _folder_stats(folder: Path) -> Tuple[int, int, int, float]:
    """(всего файлов, файлов-сохранений, размер, самый свежий mtime)."""
    total = 0
    save_files = 0
    size = 0
    newest = 0.0
    try:
        for path in folder.rglob("*"):
            if not path.is_file():
                continue
            total += 1
            if path.suffix.lower() in SAVE_EXTENSIONS:
                save_files += 1
            try:
                stat = path.stat()
                size += stat.st_size
                newest = max(newest, stat.st_mtime)
            except OSError:
                pass
            if total >= 3000:
                break
    except (PermissionError, OSError):
        pass
    return total, save_files, size, newest


def _score_candidate(name_score: int, total: int, save_files: int, size: int, newest: float, depth: int) -> int:
    score = name_score
    if save_files > 0:
        score += 25 + min(save_files, 25)
    elif total > 0:
        score += 8
    if size > 0:
        score += 5
    if newest and (time.time() - newest) < 30 * 86400:
        score += 10
    if depth >= 2:
        score += 3
    return score


def _find_named_dirs(root: Path, game_name: str, max_depth: int = 2, max_dirs: int = 400) -> List[Tuple[Path, int, int]]:
    """Найти папки с именем игры под root. Возвращает (path, name_score, depth)."""
    matches: List[Tuple[Path, int, int]] = []
    if not root.is_dir():
        return matches

    visited = 0
    stack: List[Tuple[Path, int]] = [(root, 1)]
    while stack:
        current, depth = stack.pop()
        try:
            entries = list(current.iterdir())
        except (PermissionError, OSError):
            continue
        for item in entries:
            if not item.is_dir():
                continue
            if item.name.lower() in IGNORED_FOLDER_NAMES:
                continue
            visited += 1
            if visited > max_dirs:
                return matches

            score = _name_match_score(game_name, item.name)
            if score > 0:
                matches.append((item, score, depth))
                continue  # не заходим внутрь найденной папки
            if depth < max_depth:
                stack.append((item, depth + 1))
    return matches


def _candidate_roots(drive_c: Path) -> List[Path]:
    roots: List[Path] = []
    for user in _list_windows_users(drive_c):
        base = drive_c / "users" / user
        for template in SAVE_ROOT_TEMPLATES:
            roots.append(base / template)
    roots.append(drive_c / "ProgramData")
    return roots


def _resolve_known_rel(drive_c: Path, rel: str, users: List[str]) -> Optional[Path]:
    """Разрешить известный относительный путь, подставив реального Windows-пользователя."""
    rel = rel.replace("\\", "/").lstrip("/")
    direct = drive_c / rel
    if direct.exists():
        return direct.parent if direct.is_file() else direct

    parts = rel.split("/")
    if len(parts) >= 3 and parts[0].lower() == "users":
        rest = "/".join(parts[2:])
        for user in users:
            candidate = drive_c / "users" / user / rest
            if candidate.exists():
                return candidate.parent if candidate.is_file() else candidate
    return None


def _known_save_paths(game_name: str) -> List[str]:
    try:
        from game_definitions import get_known_save_paths_for_game
    except ImportError:
        return []
    if not game_name:
        return []
    try:
        return get_known_save_paths_for_game(game_name)
    except Exception as e:
        logger.warning(f"Error in game_definitions for '{game_name}': {e}")
        return []


def detect_save_candidates(game_prefix_path: Path, game_name: str = "") -> List[Dict[str, Any]]:
    """Вернуть отсортированные кандидаты путей сохранений с оценкой.

    Каждый кандидат: {path, score, source, reason, fileCount, saveFileCount, size}.
    """
    drive_c = game_prefix_path / "drive_c"
    if not drive_c.exists():
        return []

    prefix_name = game_prefix_path.name
    users = _list_windows_users(drive_c)
    candidates: Dict[str, Dict[str, Any]] = {}

    def add(path: Path, name_score: int, source: str, depth: int, reason: str) -> None:
        key = str(path)
        total, save_files, size, newest = _folder_stats(path)
        score = _score_candidate(name_score, total, save_files, size, newest, depth)
        existing = candidates.get(key)
        if existing is None or score > existing["score"]:
            candidates[key] = {
                "path": key,
                "score": score,
                "source": source,
                "reason": reason,
                "fileCount": total,
                "saveFileCount": save_files,
                "size": size,
            }

    # 1. Известные пути из базы (самый надёжный источник)
    for rel in _known_save_paths(game_name):
        resolved = _resolve_known_rel(drive_c, rel, users)
        if resolved is not None:
            add(resolved, 100, "known", 0, f"известный путь: {rel}")

    # 1b. Изученные пользователем пути для похожих игр
    try:
        from learning import suggest_paths
        for learned in suggest_paths(game_name, drive_c):
            add(Path(learned), 100, "learned", 0, "изученный путь")
    except Exception as e:
        logger.debug(f"Learning lookup failed for '{game_name}': {e}")

    # 2. Эвристика по стандартным корням
    search_names = [n for n in (game_name, prefix_name) if n and n.upper() != "DEFAULT"]
    for root in _candidate_roots(drive_c):
        for search_name in search_names:
            for folder, name_score, depth in _find_named_dirs(root, search_name):
                add(folder, name_score, "heuristic", depth, f"папка '{folder.name}'")

    # Отбрасываем пустые эвристические кандидаты без файлов
    filtered = [
        c for c in candidates.values()
        if c["source"] in ("known", "learned") or c["fileCount"] > 0
    ]
    filtered.sort(key=lambda c: c["score"], reverse=True)
    return filtered[:5]


def detect_save_paths(game_prefix_path: Path, game_name: str = "") -> List[str]:
    """Автоопределение путей сохранений (список, отсортированный по уверенности)."""
    return [c["path"] for c in detect_save_candidates(game_prefix_path, game_name)]


def find_game_folders(base_path: Path, game_name: str) -> List[Path]:
    """Найти папки, связанные с игрой по названию (обратная совместимость)."""
    return [folder for folder, _, _ in _find_named_dirs(base_path, game_name, max_depth=1)]


# ---------------------------------------------------------------------------
# Сканирование игр
# ---------------------------------------------------------------------------

def _desktop_dirs(root: Optional[Path]) -> List[Path]:
    """Каталоги, где ищем .desktop файлы игр PortProton."""
    dirs: List[Path] = []
    if root:
        dirs.append(root)
    home = Path.home()
    dirs.append(home / ".local" / "share" / "applications")
    dirs.append(home / "Desktop")
    return dirs


def _collect_desktop_games(root: Optional[Path]) -> List[Dict[str, str]]:
    seen = set()
    games: List[Dict[str, str]] = []
    for directory in _desktop_dirs(root):
        if not directory.is_dir():
            continue
        for desktop_file in directory.glob("*.desktop"):
            if desktop_file.name.lower() in IGNORED_DESKTOP_FILES:
                continue
            # во внешних каталогах берём только записи, связанные с PortProton
            if root and directory != root:
                try:
                    text = desktop_file.read_text(encoding="utf-8", errors="replace").lower()
                except OSError:
                    continue
                if "portproton" not in text:
                    continue
            key = str(desktop_file.resolve())
            if key in seen:
                continue
            info = parse_desktop_file(desktop_file)
            if info:
                seen.add(key)
                games.append(info)
    return games


def discover_prefix_games(prefixes_path: Path, used_prefixes: set) -> List[Dict[str, Any]]:
    """Игры, у которых есть префикс, но нет .desktop файла."""
    games: List[Dict[str, Any]] = []
    for prefix in _iter_prefixes(prefixes_path):
        if prefix.name.upper() == "DEFAULT":
            continue
        if str(prefix.resolve()) in used_prefixes:
            continue
        games.append({
            "name": prefix.name.replace("_", " ").strip() or prefix.name,
            "desktopFile": "",
            "prefixPath": str(prefix),
            "exec": "",
        })
    return games


def scan_portproton_games(force_refresh: bool = False) -> List[Dict[str, Any]]:
    """Сканирование игр PortProton (.desktop + префиксы + Steam-ярлыки) и сохранений."""
    if use_cache and not force_refresh:
        try:
            from cache_manager import cache_manager
            cached = cache_manager.get("portproton_games")
            if cached is not None:
                logger.info("Using cached game list")
                return cached
        except ImportError:
            pass

    portproton_root = find_portproton_root()
    prefixes_path = (portproton_root / "prefixes") if portproton_root else None
    if prefixes_path is None or not prefixes_path.exists():
        logger.warning("PortProton prefixes path not found")
        return []

    # Steam-ярлыки: appid и подсказка о префиксе
    try:
        from steam_shortcuts import find_shortcut_for_game, prefix_hint_from_shortcut
    except Exception as e:
        logger.debug(f"Steam shortcuts unavailable: {e}")
        find_shortcut_for_game = None  # type: ignore
        prefix_hint_from_shortcut = None  # type: ignore

    games: List[Dict[str, Any]] = []
    used_prefixes: set = set()

    # 1. Игры из .desktop файлов
    for info in _collect_desktop_games(portproton_root):
        game_name = info["name"]
        shortcut = find_shortcut_for_game(game_name) if find_shortcut_for_game else None
        hint_path = prefix_hint_from_shortcut(shortcut) if (shortcut and prefix_hint_from_shortcut) else ""

        prefix_path = find_prefix_for_game(
            game_name,
            info.get("desktop_file", ""),
            prefixes_path,
            info.get("exec", ""),
            hint_path,
        )
        if not prefix_path:
            logger.debug(f"No prefix found for game: {game_name}")
            continue

        used_prefixes.add(str(prefix_path.resolve()))
        candidates = detect_save_candidates(prefix_path, game_name)

        game: Dict[str, Any] = {
            "name": game_name,
            "desktopFile": info.get("desktop_file", ""),
            "prefixPath": str(prefix_path),
            "exec": info.get("exec", ""),
            "savePaths": [c["path"] for c in candidates],
            "saveCandidates": candidates,
            "hasSaves": len(candidates) > 0,
        }
        if shortcut:
            game["steamAppId"] = shortcut.get("appid")
        games.append(game)

    # 2. Префиксы без .desktop
    for game in discover_prefix_games(prefixes_path, used_prefixes):
        shortcut = find_shortcut_for_game(game["name"]) if find_shortcut_for_game else None
        if shortcut:
            game["steamAppId"] = shortcut.get("appid")

        candidates = detect_save_candidates(Path(game["prefixPath"]), game["name"])
        game["savePaths"] = [c["path"] for c in candidates]
        game["saveCandidates"] = candidates
        game["hasSaves"] = len(candidates) > 0
        games.append(game)

    # 3. Общий префикс: несколько игр на один префикс
    by_prefix: Dict[str, List[str]] = {}
    for game in games:
        by_prefix.setdefault(game["prefixPath"], []).append(game["name"])
    for game in games:
        shared = by_prefix.get(game["prefixPath"], [])
        if len(shared) > 1:
            game["sharedPrefix"] = True
            game["sharedWith"] = [n for n in shared if n != game["name"]]

    games.sort(key=lambda g: g["name"].lower())

    if use_cache:
        try:
            from cache_manager import cache_manager
            cache_manager.set("portproton_games", games)
        except ImportError:
            pass

    logger.info(f"Found {len(games)} PortProton games")
    return games
