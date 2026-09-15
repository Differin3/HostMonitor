import psutil
import logging
import asyncio
from typing import Dict, List, Optional
from pathlib import Path

logger = logging.getLogger(__name__)

# Процессы PortProton
PORTPROTON_PROCESSES = ["portproton", "wine", "proton"]

def find_portproton_processes() -> List[Dict[str, any]]:
    """Поиск запущенных процессов PortProton"""
    processes = []
    
    for proc in psutil.process_iter(['pid', 'name', 'cmdline', 'cwd']):
        try:
            proc_info = proc.info
            name = proc_info.get('name', '').lower()
            cmdline = ' '.join(proc_info.get('cmdline', [])).lower()
            
            # Проверяем, является ли процесс связанным с PortProton
            if any(pp_proc in name or pp_proc in cmdline for pp_proc in PORTPROTON_PROCESSES):
                processes.append({
                    "pid": proc_info['pid'],
                    "name": proc_info['name'],
                    "cmdline": proc_info.get('cmdline', []),
                    "cwd": proc_info.get('cwd')
                })
        except (psutil.NoSuchProcess, psutil.AccessDenied):
            continue
    
    return processes

def get_game_name_from_process(process: Dict) -> Optional[str]:
    """Определение имени игры из процесса"""
    # Пытаемся определить игру по рабочей директории процесса
    cwd = process.get('cwd')
    if cwd:
        cwd_path = Path(cwd)
        # Ищем путь к префиксу PortProton
        parts = cwd_path.parts
        if 'prefixes' in parts:
            idx = parts.index('prefixes')
            if idx + 1 < len(parts):
                return parts[idx + 1]
    
    # Альтернативно, пытаемся определить по cmdline
    cmdline = process.get('cmdline', [])
    for arg in cmdline:
        if 'prefixes' in arg:
            parts = Path(arg).parts
            if 'prefixes' in parts:
                idx = parts.index('prefixes')
                if idx + 1 < len(parts):
                    return parts[idx + 1]
    
    return None

class GameMonitor:
    def __init__(self, sync_callback):
        self.sync_callback = sync_callback
        self.monitored_processes = {}
        self.running = False
    
    async def start_monitoring(self):
        """Запуск мониторинга процессов"""
        self.running = True
        logger.info("Started game process monitoring")
        
        while self.running:
            try:
                current_processes = find_portproton_processes()
                current_pids = {p['pid']: p for p in current_processes}
                
                # Проверяем завершенные процессы
                for pid, process in list(self.monitored_processes.items()):
                    if pid not in current_pids:
                        # Процесс завершился
                        game_name = process.get('game_name')
                        if game_name:
                            logger.info(f"Game process ended: {game_name}")
                            await self.sync_callback(game_name, process.get('save_paths', []))
                        del self.monitored_processes[pid]
                
                # Добавляем новые процессы
                for pid, process in current_pids.items():
                    if pid not in self.monitored_processes:
                        game_name = get_game_name_from_process(process)
                        if game_name:
                            process['game_name'] = game_name
                            self.monitored_processes[pid] = process
                            logger.info(f"Started monitoring process: {game_name} (PID: {pid})")
                
                await asyncio.sleep(5)  # Проверяем каждые 5 секунд
            except Exception as e:
                logger.error(f"Error in monitoring loop: {e}")
                await asyncio.sleep(5)
    
    def stop_monitoring(self):
        """Остановка мониторинга"""
        self.running = False
        logger.info("Stopped game process monitoring")
