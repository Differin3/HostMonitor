#!/usr/bin/env python3
"""
Скрипт для скачивания библиотеки Lucide локально
"""
import requests
import urllib3
from pathlib import Path

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

ICONS_DIR = Path("frontend/icons/lucide")
ICONS_DIR.mkdir(parents=True, exist_ok=True)

def download_lucide_library():
    """Скачивает minified версию библиотеки Lucide"""
    print("📥 Скачивание библиотеки Lucide...")
    
    # Скачиваем minified версию
    url = "https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"
    file_path = ICONS_DIR / "lucide.min.js"
    
    try:
        response = requests.get(url, timeout=30, verify=False)
        if response.status_code == 200:
            file_path.write_bytes(response.content)
            size_kb = len(response.content) / 1024
            print(f"✅ Скачано: lucide.min.js ({size_kb:.1f} KB)")
            return True
        else:
            print(f"❌ Ошибка {response.status_code}")
            return False
    except Exception as e:
        print(f"❌ Ошибка: {e}")
        return False

if __name__ == "__main__":
    download_lucide_library()

