"""Автоматическая установка Python-зависимостей плагина.

Decky Loader добавляет в sys.path папку плагина ``py_modules`` и системные
пути Python, но НЕ устанавливает requirements.txt сам. Поэтому плагин сам
ставит недостающие пакеты при первом запуске — пользователю ничего делать
не нужно.

Установка идёт в ``<plugin>/py_modules`` (через ``pip --target``), что не
затрагивает системный Python и обходит ограничение PEP 668.
"""

import asyncio
import importlib
import importlib.util
import logging
import os
import shutil
import subprocess
import sys
import threading
from pathlib import Path
from typing import Dict, List

logger = logging.getLogger(__name__)

# Имя модуля -> pip-требование
PACKAGE_SPECS: Dict[str, str] = {
    "psutil": "psutil>=5.9.0",
    "requests": "requests>=2.31.0",
    "boto3": "boto3>=1.35.0",
    "paramiko": "paramiko>=3.4.0",
    "yaml": "PyYAML>=6.0",
}

# Провайдер -> нужные ему модули
PROVIDER_PACKAGES: Dict[str, List[str]] = {
    "webdav": ["requests"],
    "s3": ["boto3"],
    "ftp": [],  # ftplib входит в стандартную библиотеку
    "sftp": ["paramiko"],
    "auto_sync": ["psutil"],
    "ludusavi": ["yaml"],
    "all": ["psutil", "requests", "boto3", "paramiko", "yaml"],
}

_install_lock = threading.Lock()


def _plugin_root() -> Path:
    env = os.environ.get("DECKY_PLUGIN_DIR")
    if env:
        return Path(env)
    return Path(__file__).resolve().parent.parent


def py_modules_dir() -> Path:
    return _plugin_root() / "py_modules"


def _ensure_py_modules_on_path() -> None:
    """Создаёт py_modules и добавляет его в sys.path (Decky делает это же)."""
    target = py_modules_dir()
    try:
        target.mkdir(parents=True, exist_ok=True)
    except OSError as e:
        logger.warning(f"Cannot create {target}: {e}")
    path = str(target)
    if path not in sys.path:
        sys.path.insert(0, path)
    importlib.invalidate_caches()


def _module_available(name: str) -> bool:
    try:
        return importlib.util.find_spec(name) is not None
    except (ImportError, ValueError):
        return False


def _python_candidates() -> List[str]:
    candidates: List[str] = []
    exe = sys.executable or ""
    if exe and "PluginLoader" not in exe and "decky" not in Path(exe).name.lower():
        candidates.append(exe)

    which = shutil.which("python3")
    if which:
        candidates.append(which)
    if Path("/usr/bin/python3").exists():
        candidates.append("/usr/bin/python3")

    # убираем дубликаты, сохраняя порядок
    seen = set()
    result: List[str] = []
    for c in candidates:
        if c not in seen:
            seen.add(c)
            result.append(c)
    return result


def _pip_available(python: str) -> bool:
    try:
        r = subprocess.run(
            [python, "-m", "pip", "--version"],
            capture_output=True,
            timeout=60,
        )
        return r.returncode == 0
    except Exception:
        return False


def _ensure_pip(python: str) -> bool:
    if _pip_available(python):
        return True
    try:
        subprocess.run(
            [python, "-m", "ensurepip", "--upgrade"],
            capture_output=True,
            timeout=300,
        )
    except Exception as e:
        logger.warning(f"ensurepip failed for {python}: {e}")
    return _pip_available(python)


def _pip_env() -> Dict[str, str]:
    env = os.environ.copy()
    env["PIP_BREAK_SYSTEM_PACKAGES"] = "1"
    env["PIP_DISABLE_PIP_VERSION_CHECK"] = "1"
    env["PIP_NO_INPUT"] = "1"
    return env


def _run_pip(python: str, args: List[str]) -> bool:
    try:
        r = subprocess.run(
            [python, "-m", "pip", "install", *args],
            capture_output=True,
            text=True,
            timeout=900,
            env=_pip_env(),
        )
        if r.returncode != 0:
            logger.warning(f"pip install failed ({python}): {(r.stderr or '')[-1500:]}")
            return False
        return True
    except Exception as e:
        logger.warning(f"pip install error ({python}): {e}")
        return False


def _install_specs(specs: List[str]) -> bool:
    if not specs:
        return True

    target = py_modules_dir()
    target.mkdir(parents=True, exist_ok=True)

    ver = f"{sys.version_info.major}.{sys.version_info.minor}"
    abi = f"cp{sys.version_info.major}{sys.version_info.minor}"

    base = ["--target", str(target), "--upgrade", "--no-input", "--disable-pip-version-check"]

    for python in _python_candidates():
        if not _ensure_pip(python):
            continue

        # 1) Бинарные wheel'ы ровно под версию Python, в которой работает плагин
        pinned = base + [
            "--only-binary=:all:",
            "--python-version", ver,
            "--implementation", "cp",
            "--abi", abi,
        ] + specs
        if _run_pip(python, pinned):
            _ensure_py_modules_on_path()
            return True

        # 2) Обычная установка в py_modules
        if _run_pip(python, base + specs):
            _ensure_py_modules_on_path()
            return True

        # 3) В пользовательский site-packages (Decky добавляет его в sys.path)
        user = ["--user", "--upgrade", "--no-input", "--disable-pip-version-check"] + specs
        if _run_pip(python, user):
            importlib.invalidate_caches()
            return True

    return False


def ensure_dependencies(provider: str = "all") -> Dict[str, object]:
    """Ставит отсутствующие зависимости для указанного провайдера (блокирующе)."""
    modules = PROVIDER_PACKAGES.get(provider)
    if modules is None:
        modules = PROVIDER_PACKAGES["all"]

    _ensure_py_modules_on_path()

    with _install_lock:
        missing = [m for m in modules if not _module_available(m)]
        if not missing:
            return {"success": True, "installed": [], "missing": []}

        specs = [PACKAGE_SPECS[m] for m in missing if m in PACKAGE_SPECS]
        logger.info(f"Installing missing dependencies for '{provider}': {missing}")
        _install_specs(specs)

        importlib.invalidate_caches()
        still_missing = [m for m in modules if not _module_available(m)]
        installed = [m for m in missing if m not in still_missing]
        if still_missing:
            logger.error(f"Dependencies still missing: {still_missing}")
        else:
            logger.info(f"Dependencies ready: {installed}")

        return {
            "success": not still_missing,
            "installed": installed,
            "missing": still_missing,
        }


async def ensure_dependencies_async(provider: str = "all") -> Dict[str, object]:
    """Неблокирующая версия ensure_dependencies (pip в отдельном потоке)."""
    loop = asyncio.get_running_loop()
    return await loop.run_in_executor(None, ensure_dependencies, provider)
