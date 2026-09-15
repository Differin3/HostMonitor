import json
import logging
import os
import time
from pathlib import Path
from typing import Optional

logger = logging.getLogger(__name__)

CACHE_DIR = Path.home() / ".config" / "gamesync"
CACHE_FILE = CACHE_DIR / "cache.json"
CACHE_TTL = 300  # 5 минут

class CacheManager:
    def __init__(self):
        self.cache = {}
        self.load_cache()
    
    def load_cache(self):
        """Загрузка кэша из файла"""
        if CACHE_FILE.exists():
            try:
                with open(CACHE_FILE, 'r', encoding='utf-8') as f:
                    self.cache = json.load(f)
                logger.info("Loaded cache from file")
            except Exception as e:
                logger.error(f"Error loading cache: {e}")
                self.cache = {}
    
    def save_cache(self):
        """Сохранение кэша в файл"""
        CACHE_DIR.mkdir(parents=True, exist_ok=True)
        try:
            with open(CACHE_FILE, 'w', encoding='utf-8') as f:
                json.dump(self.cache, f, indent=2)
            try:
                os.chmod(CACHE_FILE, 0o600)
            except OSError:
                pass
        except Exception as e:
            logger.error(f"Error saving cache: {e}")
    
    def get(self, key: str) -> Optional[any]:
        """Получение значения из кэша"""
        if key not in self.cache:
            return None
        
        entry = self.cache[key]
        if time.time() - entry.get('timestamp', 0) > CACHE_TTL:
            # Кэш устарел
            del self.cache[key]
            return None
        
        return entry.get('value')
    
    def set(self, key: str, value: any):
        """Установка значения в кэш"""
        self.cache[key] = {
            'value': value,
            'timestamp': time.time()
        }
        self.save_cache()
    
    def clear(self):
        """Очистка кэша"""
        self.cache = {}
        if CACHE_FILE.exists():
            CACHE_FILE.unlink()
        logger.info("Cache cleared")
    
    def invalidate(self, key: str):
        """Инвалидация конкретного ключа"""
        if key in self.cache:
            del self.cache[key]
            self.save_cache()

# Глобальный экземпляр кэш-менеджера
cache_manager = CacheManager()
