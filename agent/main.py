# Агент для сбора метрик на нодах
import time  # таймеры
import psutil  # системные метрики
import requests  # HTTP-запросы
import json  # JSON
import subprocess  # внешние команды
import os  # окружение
import pathlib  # пути
import shutil  # утилиты для проверки бинарей
from datetime import datetime  # время
from http.server import BaseHTTPRequestHandler, HTTPServer  # health-check сервер
from threading import Thread  # фоновый поток
from typing import Optional  # типы


def load_node_conf(path: str = "node.conf") -> None:
    # Загрузка node.conf (KEY=\"VAL\") в переменные окружения для Debian/Ubuntu
    # Пробуем несколько путей: рядом с main.py, в /opt/monitoring/, в /opt/monitoring/agent/, в текущей директории
    script_dir = pathlib.Path(__file__).parent.absolute()
    possible_paths = [
        pathlib.Path("/opt/monitoring") / path,  # /opt/monitoring/node.conf (основной путь, приоритет)
        pathlib.Path.cwd() / path,  # текущая рабочая директория
        script_dir / path,  # /opt/monitoring/agent/node.conf (рядом с main.py)
        pathlib.Path("/opt/monitoring/agent") / path,  # явный абсолютный путь
        pathlib.Path(path),  # относительный путь от текущей директории
    ]
    
    cfg_path = None
    for p in possible_paths:
        try:
            p_abs = p.resolve()
            if p_abs.exists() and p_abs.is_file():
                cfg_path = p_abs
                break
        except (OSError, RuntimeError) as e:
            continue
    
    if not cfg_path:
        print(f"[agent] Error: node.conf not found. Searched in:")
        for p in possible_paths:
            exists = "✓" if p.exists() else "✗"
            print(f"  {exists} {p}")
        print(f"[agent] Please create node.conf in one of these locations with NODE_TOKEN, MASTER_URL, NODE_NAME")
        return
    
    print(f"[agent] Loading node.conf from: {cfg_path}")
    try:
        loaded_count = 0
        for line in cfg_path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" not in line:
                continue
            key, val = line.split("=", 1)
            key = key.strip()
            # Удаляем кавычки и пробелы более тщательно
            val = val.strip()
            if val.startswith('"') and val.endswith('"'):
                val = val[1:-1]
            elif val.startswith("'") and val.endswith("'"):
                val = val[1:-1]
            val = val.strip()
            if key:
                # Перезаписываем переменные окружения из node.conf (приоритет выше дефолтных)
                os.environ[key] = val
                loaded_count += 1
                # Логируем важные переменные (без значений для безопасности)
                if key in ["NODE_TOKEN", "MASTER_URL", "NODE_NAME"]:
                    print(f"[agent] Loaded {key}={'***' + val[-10:] if key == 'NODE_TOKEN' and len(val) > 10 else val if key != 'NODE_TOKEN' else '***'}")
                    if key == "NODE_TOKEN":
                        print(f"[agent] NODE_TOKEN length after parsing: {len(val)}")
        print(f"[agent] Loaded {loaded_count} variables from node.conf")
    except Exception as e:
        print(f"[agent] Error loading node.conf: {e}")
        import traceback
        traceback.print_exc()


def _get_verify():
    # Параметр verify для requests: путь к сертификату или булево из конфига
    tls_cert_path = os.getenv("TLS_CERT_PATH", "")
    tls_verify = os.getenv("TLS_VERIFY", "true").lower() == "true"
    return tls_cert_path or tls_verify


def _log(msg: str) -> None:
    # Простой лог с временем в stdout
    print(f"[agent] {datetime.now().isoformat()} {msg}")


def _request_with_retry(method: str, url: str, **kwargs) -> Optional[requests.Response]:
    # HTTP-запрос с повторами и задержкой
    timeout = kwargs.pop("timeout", 10)
    max_retries = int(os.getenv("MAX_RETRIES", "3"))
    retry_delay = int(os.getenv("RETRY_DELAY", "5"))
    for attempt in range(1, max_retries + 1):
        try:
            resp = requests.request(method, url, timeout=timeout, **kwargs)
            return resp
        except Exception as e:
            _log(f"request error ({attempt}/{max_retries}) {method} {url}: {e}")
            if attempt == max_retries:
                return None
            time.sleep(retry_delay)
    return None


class _HealthHandler(BaseHTTPRequestHandler):
    # HTTP handler для health-check
    def do_GET(self):  # обработка GET
        if self.path != "/health":
            self.send_response(404)
            self.end_headers()
            return
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(b'{"status":"ok"}')

    def log_message(self, format, *args):
        # Отключаем стандартный лог http.server
        return


def start_health_server(port: Optional[int] = None) -> None:
    # Запуск простого HTTP health-check сервера в отдельном потоке
    if port is None:
        port = int(os.getenv("HEALTH_PORT", "0"))
    if port <= 0:
        return
    server = HTTPServer(("0.0.0.0", port), _HealthHandler)

    def _run():
        _log(f"health-check server started on 0.0.0.0:{port}")
        try:
            server.serve_forever()
        except Exception as e:
            _log(f"health-check server stopped: {e}")

    Thread(target=_run, daemon=True).start()


class MonitoringAgent:
    def __init__(self, master_url, node_name, node_token):
        self.master_url = master_url
        self.node_name = node_name
        self.node_token = node_token
        self.headers = {"Authorization": f"Bearer {node_token}", "Content-Type": "application/json"}
        # Храним предыдущие значения для расчета расхода трафика
        self.last_network_in = 0
        self.last_network_out = 0
        self.first_network_read = True
    
    def collect_gpu_info(self):
        # Сбор информации о GPU (NVIDIA через nvidia-smi, AMD через rocm-smi)
        gpu_info = []
        try:
            # Пробуем NVIDIA GPU через nvidia-smi
            result = subprocess.run(
                ['nvidia-smi', '--query-gpu=index,name,utilization.gpu,memory.used,memory.total,temperature.gpu', 
                 '--format=csv,noheader,nounits'], 
                capture_output=True, text=True, timeout=5
            )
            if result.returncode == 0:
                for line in result.stdout.strip().split('\n'):
                    if line.strip():
                        parts = [p.strip() for p in line.split(',')]
                        if len(parts) >= 6:
                            gpu_info.append({
                                'index': int(parts[0]),
                                'name': parts[1],
                                'vendor': 'nvidia',
                                'utilization': float(parts[2]),
                                'memory_used': int(parts[3]),
                                'memory_total': int(parts[4]),
                                'temperature': float(parts[5])
                            })
        except (FileNotFoundError, subprocess.TimeoutExpired):
            # nvidia-smi не найден - пробуем AMD
            try:
                result = subprocess.run(
                    ['rocm-smi', '--showid', '--showtemp', '--showuse', '--showmemuse', '--csv'],
                    capture_output=True, text=True, timeout=5
                )
                if result.returncode == 0:
                    # Парсим вывод rocm-smi (формат может отличаться)
                    lines = result.stdout.strip().split('\n')
                    for i, line in enumerate(lines[1:], 1):  # Пропускаем заголовок
                        if line.strip():
                            parts = line.split(',')
                            if len(parts) >= 4:
                                gpu_info.append({
                                    'index': i - 1,
                                    'name': f'AMD GPU {i-1}',
                                    'vendor': 'amd',
                                    'utilization': float(parts[2]) if len(parts) > 2 else 0,
                                    'memory_used': int(parts[3]) if len(parts) > 3 else 0,
                                    'memory_total': 0,  # rocm-smi может не показывать total
                                    'temperature': float(parts[1]) if len(parts) > 1 else 0
                                })
            except (FileNotFoundError, subprocess.TimeoutExpired):
                # Нет GPU или команды недоступны - это нормально
                pass
        except Exception as e:
            _log(f"Error collecting GPU info: {e}")
        return gpu_info
    
    def collect_metrics(self):
        # Сбор метрик системы
        cpu_percent = psutil.cpu_percent(interval=1)
        memory = psutil.virtual_memory()
        disk = psutil.disk_usage('/')
        network = psutil.net_io_counters()
        gpu_info = self.collect_gpu_info()
        
        # Расчет расхода трафика (разница между измерениями)
        network_in_diff = 0
        network_out_diff = 0
        if self.first_network_read:
            # Первое чтение - сохраняем значения, расход = 0
            self.last_network_in = network.bytes_recv
            self.last_network_out = network.bytes_sent
            self.first_network_read = False
        else:
            # Считаем разницу
            network_in_diff = max(0, network.bytes_recv - self.last_network_in)
            network_out_diff = max(0, network.bytes_sent - self.last_network_out)
            # Обновляем сохраненные значения
            self.last_network_in = network.bytes_recv
            self.last_network_out = network.bytes_sent
        
        metrics = {
            "node_name": self.node_name,
            "timestamp": datetime.now().isoformat(),
            "cpu_percent": cpu_percent,
            "memory_percent": memory.percent,
            "memory_total": memory.total,
            "memory_used": memory.used,
            "disk_percent": disk.percent,
            "disk_total": disk.total,
            "disk_used": disk.used,
            "network_in": network_in_diff,  # Расход за период
            "network_out": network_out_diff,  # Расход за период
            "network_in_total": network.bytes_recv,  # Всего получено
            "network_out_total": network.bytes_sent  # Всего отправлено
        }
        
        # Добавляем информацию о GPU если есть
        if gpu_info:
            metrics['gpu'] = gpu_info
        
        return metrics
    
    def collect_processes(self):
        # Сбор информации о процессах
        processes = []
        for proc_info in psutil.process_iter(['pid', 'name', 'status']):
            try:
                pid = proc_info.info['pid']
                # Получаем объект Process для вызова методов
                proc = psutil.Process(pid)
                # Явно вызываем методы для получения актуальных значений CPU и памяти
                cpu_percent = proc.cpu_percent(interval=0.1)
                memory_percent = proc.memory_percent()
                processes.append({
                    "pid": pid,
                    "name": proc_info.info['name'],
                    "cpu_percent": cpu_percent if cpu_percent is not None else 0,
                    "memory_percent": memory_percent if memory_percent is not None else 0,
                    "status": proc_info.info['status']
                })
            except (psutil.NoSuchProcess, psutil.AccessDenied, psutil.ZombieProcess):
                # Процесс завершился или нет доступа - пропускаем
                pass
            except Exception as e:
                # Другие ошибки - логируем и пропускаем
                _log(f"Error collecting process {proc_info.info.get('pid', 'unknown')}: {e}")
                pass
        return processes
    
    def send_data(self, metrics, processes):
        # Отправка данных на главный сервер
        data = {"metrics": metrics, "processes": processes}
        _log(f"Sending metrics to {self.master_url}/api/metrics.php")
        # Логируем наличие токена для отладки (без самого токена)
        token_info = f"Token present: {bool(self.node_token)}, length: {len(self.node_token) if self.node_token else 0}"
        if self.node_token:
            # Показываем первые 4 и последние 4 символа для проверки
            token_preview = f"{self.node_token[:4]}...{self.node_token[-4:]}" if len(self.node_token) > 8 else "***"
            token_info += f", preview: {token_preview}"
        _log(token_info)
        # Логируем заголовок Authorization (первые символы)
        auth_header_preview = self.headers.get("Authorization", "NOT SET")
        if auth_header_preview != "NOT SET":
            auth_preview = auth_header_preview[:20] + "..." if len(auth_header_preview) > 20 else auth_header_preview
            _log(f"Authorization header: {auth_preview}")
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/metrics.php",
            json=data,
            headers=self.headers,
            verify=_get_verify(),
        )
        if not resp or resp.status_code not in (200, 201):
            _log(f"Failed to send metrics: status={resp.status_code if resp else 'no response'}")
            return False
        # Проверяем содержимое ответа на наличие ошибки
        try:
            resp_data = resp.json()
            if isinstance(resp_data, dict) and 'error' in resp_data:
                error_msg = resp_data.get('error', 'Unknown error')
                _log(f"Failed to send metrics: {error_msg} (status={resp.status_code})")
                if 'Unauthorized' in error_msg:
                    _log(f"Authorization failed. Check NODE_TOKEN in node.conf. Token length: {len(self.node_token) if self.node_token else 0}")
                return False
        except Exception as e:
            _log(f"Error parsing response: {e}")
        _log(f"Metrics sent successfully: status={resp.status_code}")
        proc_resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/processes.php",
            json={"processes": processes},
            headers=self.headers,
            verify=_get_verify(),
        )
        if not proc_resp or proc_resp.status_code not in (200, 201):
            _log(f"Failed to send processes: status={proc_resp.status_code if proc_resp else 'no response'}")
            return False
        # Проверяем содержимое ответа на наличие ошибки
        try:
            proc_data = proc_resp.json()
            if isinstance(proc_data, dict) and 'error' in proc_data:
                _log(f"Failed to send processes: {proc_data.get('error')}")
                return False
        except:
            pass
        _log(f"Processes sent successfully: status={proc_resp.status_code}")
        return True
    
    def check_commands(self):
        # Проверка команд от мастера (GET /api/nodes.php?action=get-command)
        url = f"{self.master_url}/api/nodes.php"
        params = {"id": self.node_name, "action": "get-command"}
        _log(f"Checking for commands: GET {url}?id={self.node_name}&action=get-command")
        
        resp = _request_with_retry(
            "GET",
            url,
            params=params,
            headers=self.headers,
            verify=_get_verify(),
        )
        
        if not resp:
            _log("No response from server when checking commands")
            return None
            
        _log(f"Command check response: status={resp.status_code}")
        
        if resp.status_code == 200:
            try:
                data = resp.json()
                _log(f"Command check response data: {data}")
            except Exception as e:
                _log(f"bad JSON in get-command: {e}, response text: {resp.text[:200]}")
                return None
            status = data.get('status')  # новый формат: status = ok / no-command
            command = data.get('command')
            command_status = data.get('command_status')
            
            _log(f"Command check parsed: status={status}, command={command}, command_status={command_status}")
            
            if status not in (None, 'ok', 'no-command'):
                _log(f"Unexpected status in command check: {status}")
                return None
            if command and command_status == 'pending':
                _log(f"Found pending command: {command}")
                return command
            else:
                _log(f"No pending command found (command={command}, command_status={command_status})")
        else:
            _log(f"Command check failed: HTTP {resp.status_code}, response: {resp.text[:200]}")
        return None
    
    def get_os_info(self):
        # Получение информации об ОС из /etc/os-release
        os_info = {
            'os_name': 'Unknown',
            'os_version': 'Unknown',
            'os_id_like': 'Unknown',
            'kernel_version': 'Unknown'
        }
        try:
            # Пробуем прочитать /etc/os-release
            if os.path.exists('/etc/os-release'):
                with open('/etc/os-release', 'r', encoding='utf-8') as f:
                    for line in f:
                        line = line.strip()
                        if '=' in line:
                            key, value = line.split('=', 1)
                            value = value.strip('"\'')
                            if key == 'NAME':
                                os_info['os_name'] = value
                            elif key == 'VERSION_ID':
                                os_info['os_version'] = value
                            elif key == 'ID_LIKE':
                                os_info['os_id_like'] = value
                            elif key == 'ID' and os_info['os_id_like'] == 'Unknown':
                                os_info['os_id_like'] = value
            # Fallback на lsb_release
            if os_info['os_name'] == 'Unknown':
                try:
                    result = subprocess.run(['lsb_release', '-a'], capture_output=True, text=True, timeout=5)
                    if result.returncode == 0:
                        for line in result.stdout.split('\n'):
                            if ':' in line:
                                key, value = line.split(':', 1)
                                key = key.strip()
                                value = value.strip()
                                if key == 'Description':
                                    os_info['os_name'] = value
                                elif key == 'Release':
                                    os_info['os_version'] = value
                except (FileNotFoundError, subprocess.TimeoutExpired):
                    pass
            # Получаем версию ядра
            try:
                result = subprocess.run(['uname', '-r'], capture_output=True, text=True, timeout=2)
                if result.returncode == 0:
                    os_info['kernel_version'] = result.stdout.strip()
            except (FileNotFoundError, subprocess.TimeoutExpired):
                pass
        except Exception as e:
            _log(f"Error getting OS info: {e}")
        return os_info
    
    def check_updates(self):
        # Проверка доступных обновлений через apt для Debian/Ubuntu
        updates = []
        try:
            # Обновляем список пакетов (с таймаутом)
            _log("Updating package list...")
            update_result = subprocess.run(
                ['apt-get', 'update'],
                capture_output=True,
                text=True,
                timeout=60,
                check=False
            )
            if update_result.returncode != 0:
                _log(f"apt-get update failed: {update_result.stderr[:200]}")
                return updates
            
            # Получаем список обновляемых пакетов через apt list --upgradable
            _log("Checking for upgradable packages...")
            result = subprocess.run(
                ['apt', 'list', '--upgradable'],
                capture_output=True,
                text=True,
                timeout=30
            )
            
            if result.returncode != 0:
                _log(f"apt list failed: {result.stderr[:200]}")
                return updates
            
            # Парсим вывод apt list --upgradable
            # Формат: package/version,version [upgradable from: version]
            lines = result.stdout.strip().split('\n')
            for line in lines[1:]:  # Пропускаем заголовок
                if not line or 'Listing...' in line:
                    continue
                # Формат: package/version,version [upgradable from: version]
                if '/' in line and 'upgradable' in line:
                    try:
                        # Извлекаем имя пакета и версии
                        parts = line.split()
                        if len(parts) >= 1:
                            pkg_info = parts[0]  # package/version,version
                            if '/' in pkg_info:
                                pkg_name, versions = pkg_info.split('/', 1)
                                # Версии могут быть: version,version или просто version
                                if ',' in versions:
                                    new_version, _ = versions.split(',', 1)
                                else:
                                    new_version = versions
                                
                                # Получаем текущую версию из apt-cache
                                current_version = 'Unknown'
                                try:
                                    cache_result = subprocess.run(
                                        ['apt-cache', 'show', pkg_name],
                                        capture_output=True,
                                        text=True,
                                        timeout=5
                                    )
                                    if cache_result.returncode == 0:
                                        for cache_line in cache_result.stdout.split('\n'):
                                            if cache_line.startswith('Version:'):
                                                current_version = cache_line.split(':', 1)[1].strip()
                                                break
                                except Exception:
                                    pass
                                
                                # Определяем приоритет (security/important/normal)
                                priority = 'normal'
                                try:
                                    # Проверяем через apt-cache policy, есть ли security updates
                                    policy_result = subprocess.run(
                                        ['apt-cache', 'policy', pkg_name],
                                        capture_output=True,
                                        text=True,
                                        timeout=5
                                    )
                                    if 'security' in policy_result.stdout.lower():
                                        priority = 'security'
                                    elif 'important' in policy_result.stdout.lower():
                                        priority = 'important'
                                except Exception:
                                    pass
                                
                                updates.append({
                                    'package': pkg_name,
                                    'current_version': current_version,
                                    'new_version': new_version,
                                    'priority': priority,
                                    'node_id': None,  # Будет заполнено при отправке
                                    'node_name': self.node_name
                                })
                    except Exception as e:
                        _log(f"Error parsing update line '{line}': {e}")
                        continue
            
            _log(f"Found {len(updates)} available updates")
        except subprocess.TimeoutExpired:
            _log("Timeout while checking updates")
        except FileNotFoundError:
            _log("apt/apt-get not found, skipping update check")
        except Exception as e:
            _log(f"Error checking updates: {e}")
        return updates
    
    def install_update(self, package):
        # Установка обновления пакета через apt-get
        error_message = None
        installed_version = None
        
        _log(f"=== INSTALL_UPDATE START ===")
        _log(f"Package: {package}")
        
        try:
            _log(f"Running: apt-get install -y {package}")
            result = subprocess.run(
                ['apt-get', 'install', '-y', package],
                capture_output=True,
                text=True,
                timeout=300,  # 5 минут на установку
                check=False
            )
            
            _log(f"apt-get exit code: {result.returncode}")
            _log(f"apt-get stdout length: {len(result.stdout)} chars")
            _log(f"apt-get stderr length: {len(result.stderr)} chars")
            
            if result.stdout:
                _log(f"apt-get stdout (last 500 chars): {result.stdout[-500:]}")
            if result.stderr:
                _log(f"apt-get stderr (last 500 chars): {result.stderr[-500:]}")
            
            if result.returncode == 0:
                _log(f"apt-get install succeeded for {package}")
                # Получаем установленную версию пакета
                try:
                    _log(f"Checking installed version with: dpkg-query -W -f=${{Version}} {package}")
                    version_result = subprocess.run(
                        ['dpkg-query', '-W', '-f=${Version}', package],
                        capture_output=True,
                        text=True,
                        timeout=5
                    )
                    _log(f"dpkg-query exit code: {version_result.returncode}")
                    if version_result.returncode == 0:
                        installed_version = version_result.stdout.strip()
                        _log(f"Installed version from dpkg-query: {installed_version}")
                    else:
                        _log(f"dpkg-query failed: {version_result.stderr}")
                except Exception as e:
                    _log(f"Exception getting version: {e}")
                    import traceback
                    _log(f"Traceback: {traceback.format_exc()}")
                _log(f"=== INSTALL_UPDATE SUCCESS ===")
                return True, installed_version, None
            else:
                error_message = result.stderr[:500] if result.stderr else result.stdout[:500] or 'Unknown error'
                _log(f"apt-get install FAILED for {package}")
                _log(f"Error message: {error_message}")
                _log(f"=== INSTALL_UPDATE FAILED ===")
                return False, None, error_message
        except subprocess.TimeoutExpired:
            error_message = f"Timeout while installing {package} (exceeded 5 minutes)"
            _log(f"=== INSTALL_UPDATE TIMEOUT ===")
            _log(error_message)
            return False, None, error_message
        except Exception as e:
            error_message = f"Error installing {package}: {str(e)}"
            _log(f"=== INSTALL_UPDATE EXCEPTION ===")
            _log(error_message)
            import traceback
            _log(f"Traceback: {traceback.format_exc()}")
            return False, None, error_message
    
    def send_updates(self, updates, os_info=None):
        # Отправка списка обновлений на сервер
        if os_info is None:
            os_info = self.get_os_info()
        
        # Добавляем node_id к каждому обновлению (если доступен)
        # node_id будет заполнен на сервере на основе токена
        
        data = {
            'node_name': self.node_name,
            'os_info': os_info,
            'updates': updates
        }
        
        _log(f"Sending {len(updates)} updates to server")
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/updates.php?action=report",
            json=data,
            headers=self.headers,
            verify=_get_verify(),
            timeout=30
        )
        
        if resp and resp.status_code in (200, 201):
            _log("Updates sent successfully")
            return True
        else:
            _log(f"Failed to send updates: status={resp.status_code if resp else 'no response'}")
            return False
    
    def report_update_result(self, package, success, version=None, message=None):
        # Отправка результата установки обновления на сервер
        if version is None:
            version = ''
        if message is None:
            message = 'Update installed successfully' if success else 'Update installation failed'
        
        data = {
            'package': package,
            'version': version,
            'success': success,
            'message': message,
            'node_name': self.node_name
        }
        
        _log(f"Reporting update result for {package}: success={success}, version={version}")
        _log(f"Data to send: {data}")
        _log(f"URL: {self.master_url}/api/updates.php?action=result")
        
        try:
            resp = _request_with_retry(
                "POST",
                f"{self.master_url}/api/updates.php?action=result",
                json=data,
                headers=self.headers,
                verify=_get_verify(),
                timeout=30
            )
            
            if resp and resp.status_code in (200, 201):
                _log(f"Update result reported successfully: status={resp.status_code}")
                return True
            else:
                _log(f"Failed to report update result: status={resp.status_code if resp else 'no response'}")
                if resp:
                    _log(f"Response text: {resp.text[:200]}")
                return False
        except Exception as e:
            _log(f"Exception while reporting update result: {e}")
            import traceback
            _log(f"Traceback: {traceback.format_exc()}")
            return False
    
    def execute_command(self, command):
        # Выполнение команды (с базовой фильтрацией)
        # ОПАСНЫЕ КОМАНДЫ ОТКЛЮЧЕНЫ ПО УМОЛЧАНИЮ
        allow_dangerous = os.getenv("ALLOW_DANGEROUS_COMMANDS", "false").lower() == "true"
        
        try:
            if command.startswith('reboot'):
                # Перезагрузка системы - ОПАСНАЯ КОМАНДА
                if not allow_dangerous:
                    _log("BLOCKED: reboot command is disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.")
                    return False
                _log("WARNING: Executing reboot command (dangerous operation)")
                subprocess.run(['sudo', 'reboot'], check=False)
                return True
            elif command.startswith('shutdown'):
                # Выключение системы - ОПАСНАЯ КОМАНДА
                if not allow_dangerous:
                    _log("BLOCKED: shutdown command is disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.")
                    return False
                _log("WARNING: Executing shutdown command (dangerous operation)")
                subprocess.run(['sudo', 'shutdown', '-h', 'now'], check=False)
                return True
            elif command.startswith('kill'):
                # Убить процесс
                parts = command.split()
                if len(parts) > 1:
                    pid = int(parts[1])
                    proc = psutil.Process(pid)
                    proc.kill()
                    return True
            elif command.startswith('restart'):
                # Перезапуск процесса: сейчас реализуем как завершение процесса по PID
                parts = command.split()
                if len(parts) > 1:
                    pid = int(parts[1])
                    proc = psutil.Process(pid)
                    proc.terminate()
                    return True
            elif command.startswith('docker'):
                # Docker команды
                parts = command.split()
                if len(parts) >= 3:
                    action = parts[1]  # start, stop, restart
                    container_id = parts[2]
                    subprocess.run(['docker', action, container_id], check=False)
                    return True
            elif command.startswith('check-updates'):
                # Проверка обновлений
                os_info = self.get_os_info()
                updates = self.check_updates()
                # Отправляем обновления на сервер
                self.send_updates(updates, os_info)
                return True
            elif command.startswith('install-update'):
                # Установка обновления
                _log(f"=== INSTALL-UPDATE COMMAND START ===")
                _log(f"Full command: {command}")
                parts = command.split()
                _log(f"Command parts: {parts}, count: {len(parts)}")
                
                if len(parts) >= 2:
                    package = parts[1]
                    # Получаем версию из команды (если указана)
                    expected_version = parts[2] if len(parts) >= 3 else None
                    _log(f"Package to install: {package}")
                    _log(f"Expected version: {expected_version}")
                    
                    # Устанавливаем обновление
                    _log(f"Calling install_update({package})...")
                    success, installed_version, error_message = self.install_update(package)
                    _log(f"Install_update returned: success={success}, version={installed_version}, error={error_message}")
                    
                    # Используем установленную версию или ожидаемую
                    version = installed_version or expected_version or ''
                    _log(f"Final version to report: {version}")
                    
                    # Формируем сообщение
                    if success:
                        message = f"Package {package} updated successfully"
                        if installed_version:
                            message += f" to version {installed_version}"
                    else:
                        message = f"Failed to install {package}"
                        if error_message:
                            message += f": {error_message}"
                    
                    _log(f"Result message: {message}")
                    
                    # Отправляем результат на сервер
                    _log(f"Sending update result to server: package={package}, success={success}, version={version}")
                    result_sent = self.report_update_result(package, success, version, message)
                    _log(f"Update result sent successfully: {result_sent}")
                    _log(f"=== INSTALL-UPDATE COMMAND END ===")
                    return success
                else:
                    _log(f"ERROR: Invalid install-update command format: {command}")
                    _log(f"Expected format: install-update <package> [version]")
                    _log(f"Got {len(parts)} parts instead of at least 2")
                return False
            elif command.startswith('firewall'):
                # Firewall команды (простая обёртка над ufw/iptables)
                parts = command.split()
                if len(parts) >= 3:
                    action = parts[1]  # allow, deny
                    port_proto = parts[2]  # port/proto
                    port_str, proto = (port_proto.split('/', 1) + ['tcp'])[:2]
                    try:
                        port = int(port_str)
                    except ValueError:
                        _log(f"invalid firewall port: {port_str}")
                        return False
                    # Пытаемся использовать ufw, если доступен
                    ufw_cmd = None
                    if shutil.which("ufw"):
                        if action == "allow":
                            ufw_cmd = ["ufw", "allow", f"{port}/{proto}"]
                        elif action == "deny":
                            ufw_cmd = ["ufw", "deny", f"{port}/{proto}"]
                    if ufw_cmd:
                        subprocess.run(ufw_cmd, check=False)
                        return True
                    # Fallback на iptables (только для tcp/udp)
                    if proto in ("tcp", "udp") and shutil.which("iptables"):
                        if action == "allow":
                            # Удаляем блокирующее правило, если было (idempotent)
                            subprocess.run(
                                ["iptables", "-D", "INPUT", "-p", proto, "--dport", str(port), "-j", "DROP"],
                                check=False,
                            )
                            return True
                        elif action == "deny":
                            subprocess.run(
                                ["iptables", "-A", "INPUT", "-p", proto, "--dport", str(port), "-j", "DROP"],
                                check=False,
                            )
                            return True
                    _log(f"firewall backend not available for command: {command}")
                    return False
        except Exception as e:
            print(f"Error executing command: {e}")
        return False
    
    def report_command_status(self, command, status):
        # Отчет о статусе выполнения команды
        url = f"{self.master_url}/api/nodes.php"
        params = {"id": self.node_name, "action": "command-status"}
        data = {"command": command, "status": status}
        
        _log(f"Reporting command status: command={command}, status={status}")
        _log(f"POST {url}?id={self.node_name}&action=command-status")
        _log(f"Request data: {data}")
        
        resp = _request_with_retry(
            "POST",
            url,
            params=params,
            json=data,
            headers=self.headers,
            verify=_get_verify(),
        )
        
        if resp:
            _log(f"Command status report response: status={resp.status_code}")
            if resp.status_code not in (200, 201):
                _log(f"Command status report failed: {resp.text[:200]}")
        else:
            _log("No response when reporting command status")
    
    def collect_containers(self):
        # Сбор информации о контейнерах Docker
        containers = []
        try:
            result = subprocess.run(['docker', 'ps', '-a', '--format', '{{.ID}}|{{.Names}}|{{.Image}}|{{.Status}}'], 
                                  capture_output=True, text=True, timeout=5)
            if result.returncode == 0:
                for line in result.stdout.strip().split('\n'):
                    if line:
                        parts = line.split('|')
                        if len(parts) >= 4:
                            containers.append({
                                'container_id': parts[0],
                                'name': parts[1],
                                'image': parts[2],
                                'status': parts[3]
                            })
        except Exception as e:
            print(f"Error collecting containers: {e}")
        return containers
    
    def collect_ports(self):
        # Сбор информации об открытых портах
        ports = []
        try:
            # Используем ss для получения открытых портов с процессами
            result = subprocess.run(['ss', '-tlnp'], capture_output=True, text=True, timeout=5)
            if result.returncode != 0:
                result = subprocess.run(['netstat', '-tlnp'], capture_output=True, text=True, timeout=5)
            
            if result.returncode == 0:
                for line in result.stdout.strip().split('\n')[1:]:  # Пропускаем заголовок
                    if 'LISTEN' in line or 'ESTAB' in line:
                        parts = line.split()
                        if len(parts) >= 4:
                            addr = parts[3]
                            if ':' in addr:
                                port = addr.split(':')[-1]
                                # Пытаемся извлечь PID и имя процесса
                                pid = None
                                process_name = None
                                if len(parts) > 4:
                                    proc_info = parts[-1]
                                    if 'pid=' in proc_info:
                                        try:
                                            pid = int(proc_info.split('pid=')[1].split(',')[0])
                                            # Получаем имя процесса по PID
                                            try:
                                                proc = psutil.Process(pid)
                                                process_name = proc.name()
                                            except:
                                                pass
                                        except:
                                            pass
                                
                                ports.append({
                                    'port': int(port),
                                    'type': 'tcp',
                                    'status': 'open',
                                    'pid': pid,
                                    'process_name': process_name
                                })
        except Exception as e:
            print(f"Error collecting ports: {e}")
        return ports
    
    def collect_network_interfaces(self):
        # Сбор информации о сетевых интерфейсах
        interfaces = []
        try:
            net_if_addrs = psutil.net_if_addrs()
            net_if_stats = psutil.net_if_stats()
            net_io_counters = psutil.net_io_counters(pernic=True)
            
            for interface_name, addrs in net_if_addrs.items():
                # Пропускаем loopback
                if interface_name == 'lo':
                    continue
                
                # Получаем статистику интерфейса
                stats = net_if_stats.get(interface_name)
                io_counters = net_io_counters.get(interface_name)
                
                # Получаем IP адреса
                ipv4 = None
                netmask = None
                for addr in addrs:
                    if addr.family == 2:  # IPv4
                        ipv4 = addr.address
                        netmask = addr.netmask
                        break
                
                interfaces.append({
                    'name': interface_name,
                    'ip': ipv4 or 'N/A',
                    'netmask': netmask or 'N/A',
                    'status': 'up' if stats and stats.isup else 'down',
                    'speed': stats.speed if stats else 0,
                    'rx_bytes': io_counters.bytes_recv if io_counters else 0,
                    'tx_bytes': io_counters.bytes_sent if io_counters else 0
                })
        except Exception as e:
            _log(f"Error collecting network interfaces: {e}")
        return interfaces
    
    def send_network_interfaces(self, interfaces):
        # Отправка данных о сетевых интерфейсах
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/ports.php?action=interfaces",
            json={"interfaces": interfaces},
            headers=self.headers,
            verify=_get_verify(),
        )
        return bool(resp and resp.status_code in (200, 201))
    
    def send_containers(self, containers):
        # Отправка данных о контейнерах
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/containers.php",
            json=containers,
            headers=self.headers,
            verify=_get_verify(),
        )
        return bool(resp and resp.status_code in (200, 201))
    
    def send_ports(self, ports):
        # Отправка данных о портах
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/ports.php",
            json=ports,
            headers=self.headers,
            verify=_get_verify(),
        )
        return bool(resp and resp.status_code in (200, 201))
    
    def collect_process_logs(self, pid=None, limit=100):
        # Сбор логов процессов из journalctl или /var/log
        logs = []
        try:
            if pid:
                # Логи конкретного процесса через journalctl
                try:
                    result = subprocess.run(
                        ['journalctl', '-p', 'info', '--no-pager', '-n', str(limit), f'_PID={pid}'], 
                        capture_output=True, text=True, timeout=5
                    )
                    if result.returncode == 0:
                        for line in result.stdout.strip().split('\n'):
                            if line.strip():
                                # Парсим формат journalctl: дата время хост процесс[PID]: сообщение
                                parts = line.split(':', 3)
                                if len(parts) >= 4:
                                    timestamp_str = ' '.join(parts[0:2])
                                    process_info = parts[2].strip()
                                    message = parts[3].strip()
                                    
                                    # Определяем уровень логирования
                                    level = 'info'
                                    if 'error' in message.lower() or 'failed' in message.lower():
                                        level = 'error'
                                    elif 'warning' in message.lower() or 'warn' in message.lower():
                                        level = 'warning'
                                    
                                    logs.append({
                                        'pid': pid,
                                        'level': level,
                                        'message': message,
                                        'process': process_info,
                                        'timestamp': timestamp_str
                                    })
                except (FileNotFoundError, subprocess.TimeoutExpired):
                    pass
            else:
                # Общие системные логи из /var/log
                log_files = ['/var/log/syslog', '/var/log/messages']
                for log_file in log_files:
                    try:
                        if os.path.exists(log_file):
                            # Читаем последние строки
                            result = subprocess.run(
                                ['tail', '-n', str(limit), log_file],
                                capture_output=True, text=True, timeout=5
                            )
                            if result.returncode == 0:
                                for line in result.stdout.strip().split('\n'):
                                    if line.strip():
                                        # Парсим формат syslog
                                        parts = line.split(':', 2)
                                        if len(parts) >= 3:
                                            timestamp_str = parts[0].strip()
                                            process_info = parts[1].strip() if len(parts) > 1 else 'system'
                                            message = parts[2].strip()
                                            
                                            level = 'info'
                                            if 'error' in message.lower() or 'failed' in message.lower():
                                                level = 'error'
                                            elif 'warning' in message.lower() or 'warn' in message.lower():
                                                level = 'warning'
                                            
                                            logs.append({
                                                'type': 'system',
                                                'level': level,
                                                'message': message,
                                                'process': process_info,
                                                'timestamp': timestamp_str
                                            })
                    except (FileNotFoundError, subprocess.TimeoutExpired):
                        continue
        except Exception as e:
            _log(f"Error collecting process logs: {e}")
        return logs
    
    def collect_ssh_auth_logs(self, limit=200):
        # Сбор логов аутентификации SSH (auth_ssh) с ограничением объёма
        logs = []
        try:
            # Пытаемся через journalctl (предпочтительно)
            try:
                result = subprocess.run(
                    ['journalctl', '-u', 'ssh', '-u', 'sshd', '--no-pager', '-n', str(limit)],
                    capture_output=True, text=True, timeout=5
                )
                if result.returncode == 0:
                    for line in result.stdout.strip().split('\n'):
                        if not line.strip():
                            continue
                        parts = line.split(':', 3)
                        if len(parts) >= 4:
                            timestamp_str = ' '.join(parts[0:2])
                            process_info = parts[2].strip()
                            message = parts[3].strip()
                        else:
                            timestamp_str = datetime.now().isoformat()
                            process_info = 'sshd'
                            message = line.strip()
                        msg_lower = message.lower()
                        level = 'info'
                        if 'failed password' in msg_lower or 'authentication failure' in msg_lower:
                            level = 'warning'
                        if 'error' in msg_lower:
                            level = 'error'
                        logs.append({
                            'type': 'auth_ssh',
                            'level': level,
                            'message': message,
                            'process': process_info,
                            'timestamp': timestamp_str
                        })
                    return logs
            except (FileNotFoundError, subprocess.TimeoutExpired):
                pass
            
            # Fallback: читаем /var/log/auth.log или /var/log/secure (последние N строк, фильтр по sshd)
            auth_files = ['/var/log/auth.log', '/var/log/secure']
            for auth_file in auth_files:
                try:
                    if not os.path.exists(auth_file):
                        continue
                    result = subprocess.run(
                        ['grep', 'sshd', auth_file],
                        capture_output=True, text=True, timeout=5
                    )
                    if result.returncode not in (0, 1):
                        continue
                    lines = [l for l in result.stdout.strip().split('\n') if l.strip()]
                    if not lines:
                        continue
                    # Берём только последние limit строк
                    for line in lines[-limit:]:
                        parts = line.split(':', 2)
                        if len(parts) >= 3:
                            timestamp_str = parts[0].strip()
                            process_info = parts[1].strip()
                            message = parts[2].strip()
                        else:
                            timestamp_str = datetime.now().isoformat()
                            process_info = 'sshd'
                            message = line.strip()
                        msg_lower = message.lower()
                        level = 'info'
                        if 'failed password' in msg_lower or 'authentication failure' in msg_lower:
                            level = 'warning'
                        if 'error' in msg_lower:
                            level = 'error'
                        logs.append({
                            'type': 'auth_ssh',
                            'level': level,
                            'message': message,
                            'process': process_info,
                            'timestamp': timestamp_str
                        })
                except (FileNotFoundError, subprocess.TimeoutExpired):
                    continue
        except Exception as e:
            _log(f"Error collecting SSH auth logs: {e}")
        return logs
    
    def collect_logs(self):
        # Сбор системных и аутентификационных логов
        logs = []
        try:
            # Собираем логи о критических событиях системы
            if hasattr(psutil, 'boot_time'):
                boot_time = datetime.fromtimestamp(psutil.boot_time())
                uptime = datetime.now() - boot_time
                if uptime.total_seconds() < 3600:  # Система недавно загрузилась
                    logs.append({
                        'level': 'info',
                        'message': f'System boot detected, uptime: {uptime}',
                        'timestamp': datetime.now().isoformat()
                    })
            
            # Собираем реальные логи процессов
            process_logs = self.collect_process_logs(limit=100)
            logs.extend(process_logs)
            
            # Собираем логи SSH-аутентификации
            ssh_logs = self.collect_ssh_auth_logs(limit=200)
            logs.extend(ssh_logs)
        except Exception as e:
            _log(f"Error collecting logs: {e}")
        return logs
    
    def send_logs(self, logs):
        # Отправка логов на сервер
        if not logs:
            return True
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/logs.php",
            json=logs,
            headers=self.headers,
            verify=_get_verify(),
        )
        return bool(resp and resp.status_code in (200, 201))
    
    def send_heartbeat(self):
        # Отправка heartbeat для обновления статуса ноды
        try:
            _log(f"Sending heartbeat to {self.master_url}/api/nodes.php?action=heartbeat")
            resp = _request_with_retry(
                "POST",
                f"{self.master_url}/api/nodes.php?action=heartbeat",
                json={"timestamp": datetime.now().isoformat()},
                headers=self.headers,
                verify=_get_verify(),
                timeout=5  # Короткий таймаут для heartbeat
            )
            if resp and resp.status_code in (200, 201):
                _log(f"Heartbeat sent successfully: status={resp.status_code}")
                return True
            else:
                _log(f"Heartbeat failed: status={resp.status_code if resp else 'no response'}")
        except Exception as e:
            _log(f"Heartbeat failed: {e}")
        return False
    
    def run(self):
        # Основной цикл агента
        collect_interval = int(os.getenv("COLLECT_INTERVAL", "60"))
        heartbeat_interval = int(os.getenv("HEARTBEAT_INTERVAL", "15"))  # Heartbeat каждые 15 секунд по умолчанию
        _log(f"Agent started, collection interval: {collect_interval}s, heartbeat interval: {heartbeat_interval}s")
        
        cycle = 0
        last_heartbeat = 0
        
        while True:
            cycle += 1
            current_time = time.time()
            
            # Отправляем heartbeat чаще, чем метрики (для быстрого обнаружения падения)
            if current_time - last_heartbeat >= heartbeat_interval:
                if self.send_heartbeat():
                    last_heartbeat = current_time
                else:
                    _log("Warning: heartbeat failed, will retry")
            
            _log(f"Cycle {cycle}: collecting metrics...")
            
            # Проверяем команды от мастера
            command = self.check_commands()
            if command:
                _log(f"=== EXECUTING COMMAND ===")
                _log(f"Command received: {command}")
                _log(f"Node: {self.node_name}")
                try:
                    success = self.execute_command(command)
                    _log(f"Command execution result: success={success}")
                    self.report_command_status(command, 'completed' if success else 'failed')
                    _log(f"=== COMMAND EXECUTION COMPLETE ===")
                except Exception as e:
                    _log(f"ERROR executing command: {e}")
                    import traceback
                    _log(f"Traceback: {traceback.format_exc()}")
                    self.report_command_status(command, 'failed')
            else:
                # Логируем только раз в 10 циклов, чтобы не засорять логи
                if cycle % 10 == 0:
                    _log(f"No pending commands found (cycle {cycle})")
            
            # Собираем и отправляем метрики
            metrics = self.collect_metrics()
            processes = self.collect_processes()
            _log(f"Collected {len(processes)} processes, CPU: {metrics.get('cpu_percent', 0):.1f}%, Memory: {metrics.get('memory_percent', 0):.1f}%")
            if not self.send_data(metrics, processes):
                _log("Error: failed to send metrics/processes")
            
            # Собираем и отправляем контейнеры (реже)
            containers = self.collect_containers()
            if containers:
                _log(f"Sending {len(containers)} containers")
                self.send_containers(containers)
            
            # Собираем и отправляем порты (реже)
            ports = self.collect_ports()
            if ports:
                _log(f"Sending {len(ports)} ports")
                self.send_ports(ports)
            
            # Собираем и отправляем сетевые интерфейсы (реже, раз в несколько циклов)
            if cycle % 5 == 0:  # Каждые 5 циклов
                interfaces = self.collect_network_interfaces()
                if interfaces:
                    _log(f"Sending {len(interfaces)} network interfaces")
                    self.send_network_interfaces(interfaces)
            
            # Собираем и отправляем логи (реже, раз в несколько циклов)
            logs = self.collect_logs()
            if logs and not self.send_logs(logs):
                _log("Error: failed to send logs")
            
            _log(f"Cycle {cycle} completed, sleeping {collect_interval}s...")
            time.sleep(collect_interval)

if __name__ == "__main__":
    import sys
    print(f"[agent] Starting agent...", flush=True)
    print(f"[agent] Python version: {sys.version}", flush=True)
    print(f"[agent] Current working directory: {os.getcwd()}", flush=True)
    print(f"[agent] Script location: {pathlib.Path(__file__).absolute()}", flush=True)
    
    try:
        load_node_conf()  # сначала поднимаем node.conf в окружение
    except Exception as e:
        print(f"[agent] ERROR loading node.conf: {e}", flush=True)
        import traceback
        traceback.print_exc()
        sys.exit(1)
    
    # Читаем переменные из окружения после загрузки node.conf
    master_url = os.getenv("MASTER_URL", "https://master-server:8000")
    node_name = os.getenv("NODE_NAME", "node-1")
    node_token = os.getenv("NODE_TOKEN", "")
    collect_interval = int(os.getenv("COLLECT_INTERVAL", "60"))
    health_port = int(os.getenv("HEALTH_PORT", "0"))
    
    print(f"[agent] Configuration loaded:")
    print(f"  MASTER_URL: {master_url}")
    print(f"  NODE_NAME: {node_name}")
    print(f"  NODE_TOKEN: {'***' + node_token[-10:] if node_token else '(not set)'}")
    print(f"  COLLECT_INTERVAL: {collect_interval}")

    if not node_token:
        print("[agent] ERROR: NODE_TOKEN is not set. Please configure the agent.")
        print(f"[agent] MASTER_URL: {master_url}")
        print(f"[agent] NODE_NAME: {node_name}")
        print(f"[agent] NODE_TOKEN from env: {os.getenv('NODE_TOKEN', 'NOT SET')}")
        print("[agent] Check that node.conf exists and contains NODE_TOKEN=...")
        exit(1)
    
    print(f"[agent] Initializing agent with token length: {len(node_token)}")
    agent = MonitoringAgent(master_url=master_url, node_name=node_name, node_token=node_token)
    if health_port > 0:
        start_health_server(health_port)
    agent.run()

