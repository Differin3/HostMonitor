import sys
import os
from pathlib import Path

# Определяем путь к плагину
plugin_dir = Path(os.getcwd())
if not (plugin_dir / "backend" / "main.py").exists():
    try:
        if '__file__' in globals():
            plugin_dir = Path(__file__).parent
    except:
        pass

# Добавляем backend в sys.path для импорта модулей
backend_path = plugin_dir / "backend"
if str(backend_path) not in sys.path:
    sys.path.insert(0, str(backend_path))

# Импортируем класс Plugin из backend/main.py
# Используем импорт модуля с алиасом, чтобы избежать конфликта имен
import importlib.util
backend_main_path = backend_path / "main.py"
spec = importlib.util.spec_from_file_location("backend_plugin", backend_main_path)
backend_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backend_module)
Plugin = backend_module.Plugin
