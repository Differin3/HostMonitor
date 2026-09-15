#!/usr/bin/env python3
"""
Скрипт для скачивания иконок Lucide, используемых в проекте
"""
import os
import re
import requests
import urllib3
from pathlib import Path
from urllib.parse import urljoin

# Отключаем предупреждения SSL для Windows
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# Базовый URL для скачивания SVG иконок Lucide
LUCIDE_ICONS_BASE = "https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/"

# Директория для сохранения иконок
ICONS_DIR = Path("frontend/icons/lucide")
ICONS_DIR.mkdir(parents=True, exist_ok=True)

def extract_icons_from_files():
    """Извлекает все используемые иконки из PHP и JS файлов"""
    icons = set()
    
    # Иконки из menuItems в layout.php
    menu_icons = [
        'home', 'server', 'settings', 'bar-chart-2', 'wallet', 
        'trending-up', 'activity', 'network', 'cpu', 'box', 
        'file-text', 'package', 'moon'  # moon для темы
    ]
    icons.update(menu_icons)
    
    # Валидные имена иконок (только буквы, цифры и дефисы)
    valid_icon_pattern = re.compile(r'^[a-z0-9-]+$')
    
    # Сканируем все PHP файлы
    monitoring_dir = Path("monitoring")
    if monitoring_dir.exists():
        for php_file in monitoring_dir.rglob("*.php"):
            try:
                content = php_file.read_text(encoding='utf-8')
                # Ищем data-lucide="icon-name" (исключаем PHP переменные)
                matches = re.findall(r'data-lucide=["\']([^"\']+)["\']', content)
                for match in matches:
                    # Фильтруем только валидные имена иконок
                    if valid_icon_pattern.match(match.strip()):
                        icons.add(match.strip())
            except Exception as e:
                print(f"Ошибка при чтении {php_file}: {e}")
    
    # Сканируем JS файлы
    frontend_dir = Path("frontend/js")
    if frontend_dir.exists():
        for js_file in frontend_dir.rglob("*.js"):
            try:
                content = js_file.read_text(encoding='utf-8')
                matches = re.findall(r'data-lucide=["\']([^"\']+)["\']', content)
                for match in matches:
                    if valid_icon_pattern.match(match.strip()):
                        icons.add(match.strip())
            except Exception as e:
                print(f"Ошибка при чтении {js_file}: {e}")
    
    return sorted(icons)

def download_icon(icon_name):
    """Скачивает одну иконку"""
    # Маппинг альтернативных имен иконок (правильные имена в Lucide)
    # В проекте используются имена через data-lucide, но в репозитории могут быть другие
    icon_mapping = {
        'alert-circle': 'alert-circle',  # Правильное имя
        'bar-chart': 'bar-chart',  # Правильное имя  
        'bar-chart-2': 'bar-chart-2',  # Правильное имя
        'check-circle': 'circle-check',  # В Lucide это circle-check
        'edit': 'pencil',  # В Lucide это pencil
        'home': 'home',  # Правильное имя
        'pie-chart': 'chart-pie',  # В Lucide это chart-pie
        'unlock': 'unlock',  # Правильное имя
    }
    
    # Альтернативные варианты для поиска
    alternative_names = {
        'alert-circle': ['alert-circle', 'alert'],
        'bar-chart': ['bar-chart', 'chart-bar'],
        'bar-chart-2': ['bar-chart-2', 'bar-chart'],
        'home': ['home', 'house'],
        'pie-chart': ['chart-pie', 'pie-chart'],
        'unlock': ['unlock', 'lock-open'],
    }
    
    file_path = ICONS_DIR / f"{icon_name}.svg"
    
    # Пропускаем если файл уже существует
    if file_path.exists():
        print(f"⏭️  Пропущено (уже есть): {icon_name}.svg")
        return True
    
    # Используем маппинг если есть
    actual_name = icon_mapping.get(icon_name, icon_name)
    url = urljoin(LUCIDE_ICONS_BASE, f"{actual_name}.svg")
    
    try:
        response = requests.get(url, timeout=10, verify=False)
        if response.status_code == 200:
            file_path.write_text(response.text, encoding='utf-8')
            if actual_name != icon_name:
                print(f"✅ Скачано: {icon_name}.svg (из {actual_name})")
            else:
                print(f"✅ Скачано: {icon_name}.svg")
            return True
        else:
            # Пробуем альтернативные варианты из маппинга
            alternatives = alternative_names.get(icon_name, [])
            # Добавляем общие альтернативы
            alternatives.extend([
                icon_name.replace('-2', ''),
                icon_name.replace('-circle', ''),
                icon_name.replace('circle-', ''),
            ])
            
            for alt in alternatives:
                if alt and alt != icon_name and alt != actual_name:
                    alt_url = urljoin(LUCIDE_ICONS_BASE, f"{alt}.svg")
                    try:
                        alt_response = requests.get(alt_url, timeout=5, verify=False)
                        if alt_response.status_code == 200:
                            file_path.write_text(alt_response.text, encoding='utf-8')
                            print(f"✅ Скачано (альтернатива): {icon_name}.svg -> {alt}.svg")
                            return True
                    except:
                        continue
            print(f"⚠️  Не найдено: {icon_name} (но работает через CDN!)")
            return False
    except Exception as e:
        print(f"❌ Ошибка при скачивании {icon_name}: {e}")
        return False

def main():
    print("🔍 Поиск используемых иконок...")
    icons = extract_icons_from_files()
    
    print(f"\n📋 Найдено {len(icons)} уникальных иконок:")
    for icon in icons:
        print(f"  - {icon}")
    
    print(f"\n📥 Скачивание иконок в {ICONS_DIR}...")
    downloaded = 0
    failed = 0
    
    for icon in icons:
        if download_icon(icon):
            downloaded += 1
        else:
            failed += 1
    
    print(f"\n✅ Готово! Скачано: {downloaded}, Ошибок: {failed}")
    print(f"📁 Иконки сохранены в: {ICONS_DIR.absolute()}")

if __name__ == "__main__":
    main()

