# Агент для сбора метрик на нодах
import time  # таймеры
import psutil  # системные метрики
import requests  # HTTP-запросы
import json  # JSON
import subprocess  # внешние команды
import os  # окружение
import pathlib  # пути
import shutil  # утилиты для проверки бинарей
import re  # разбор SSH-логов
import socket  # IP шлюза из /proc/net/route
import struct  # разбор little-endian gateway
from datetime import datetime  # время
from http.server import BaseHTTPRequestHandler, HTTPServer  # health-check сервер
from threading import Thread, Lock  # фоновый поток
from typing import Optional  # типы

try:
    from . import upnp as upnp_mod
except ImportError:
    import upnp as upnp_mod


def load_node_conf(path: str = "node.conf") -> None:
    # Загрузка node.conf (KEY=\"VAL\") в переменные окружения для Debian/Ubuntu
    # Пробуем несколько путей: рядом с main.py, в /opt/monitoring/, в /opt/monitoring/agent/, в текущей директории
    script_dir = pathlib.Path(__file__).parent.absolute()
    possible_paths = [
        script_dir / path,  # /opt/monitoring/agent/node.conf
        pathlib.Path("/opt/monitoring/agent") / path,
        pathlib.Path.cwd() / path,
        pathlib.Path("/opt/monitoring") / path,
        pathlib.Path(path),
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
        self.upnp_devices = []
        self._upnp_lock = Lock()
        self._upnp_alive_at = 0.0
        self._log_cursors = self._load_log_cursors()
    
    @staticmethod
    def _gpu_num(value, as_int=False):
        text = str(value).strip().replace('%', '')
        if text.upper() in ('', '[N/A]', 'N/A', 'NA', '-', 'NONE'):
            return 0 if as_int else 0.0
        try:
            number = float(text)
        except (TypeError, ValueError):
            return 0 if as_int else 0.0
        return int(number) if as_int else number

    def collect_gpu_info(self):
        # NVIDIA nvidia-smi, иначе AMD rocm-smi. [N/A] не роняет весь снимок.
        gpu_info = []
        try:
            result = subprocess.run(
                ['nvidia-smi', '--query-gpu=index,name,utilization.gpu,memory.used,memory.total,temperature.gpu',
                 '--format=csv,noheader,nounits'],
                capture_output=True, text=True, timeout=5
            )
            if result.returncode == 0:
                import csv
                from io import StringIO
                for parts in csv.reader(StringIO(result.stdout or '')):
                    if len(parts) < 6:
                        continue
                    gpu_info.append({
                        'index': self._gpu_num(parts[0], True),
                        'name': parts[1],
                        'vendor': 'nvidia',
                        'utilization': self._gpu_num(parts[2]),
                        'memory_used': self._gpu_num(parts[3], True),
                        'memory_total': self._gpu_num(parts[4], True),
                        'temperature': self._gpu_num(parts[5]),
                    })
                if gpu_info:
                    return gpu_info
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass
        except Exception as e:
            _log(f"Error collecting NVIDIA GPU info: {e}")
        try:
            result = subprocess.run(
                ['rocm-smi', '--showid', '--showtemp', '--showuse', '--showmemuse', '--csv'],
                capture_output=True, text=True, timeout=5
            )
            if result.returncode == 0:
                lines = result.stdout.strip().split('\n')
                for i, line in enumerate(lines[1:], 1):
                    if not line.strip():
                        continue
                    parts = line.split(',')
                    gpu_info.append({
                        'index': i - 1,
                        'name': f'AMD GPU {i - 1}',
                        'vendor': 'amd',
                        'utilization': self._gpu_num(parts[2] if len(parts) > 2 else 0),
                        'memory_used': self._gpu_num(parts[3] if len(parts) > 3 else 0, True),
                        'memory_total': 0,
                        'temperature': self._gpu_num(parts[1] if len(parts) > 1 else 0),
                    })
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass
        except Exception as e:
            _log(f"Error collecting AMD GPU info: {e}")
        return gpu_info

    def _physical_net_bytes(self):
        # Хостовой трафик: физические NIC. Если их счётчики нулевые (только bridge) — берём bridge без veth/lo.
        try:
            pernic = psutil.net_io_counters(pernic=True) or {}
        except Exception:
            pernic = {}
        phys_recv = phys_sent = 0
        bridge_recv = bridge_sent = 0
        for name, io in pernic.items():
            recv = getattr(io, 'bytes_recv', 0) or 0
            sent = getattr(io, 'bytes_sent', 0) or 0
            low = (name or '').lower()
            if low == 'lo' or low.startswith('lo:') or low.startswith('veth') or low.startswith('tun') or low.startswith('tap'):
                continue
            if re.match(r'^(docker|br-|virbr|cni|flannel|calico|kube)', low):
                bridge_recv += recv
                bridge_sent += sent
                continue
            phys_recv += recv
            phys_sent += sent
        if phys_recv or phys_sent:
            return phys_recv, phys_sent
        return bridge_recv, bridge_sent

    def _disk_usage_main(self):
        skip_fs = {'tmpfs', 'devtmpfs', 'overlay', 'squashfs', 'aufs', 'ramfs', 'proc', 'sysfs', 'cgroup', 'cgroup2', 'iso9660'}
        skip_mp = ('/boot', '/dev', '/run', '/sys', '/proc', '/snap', '/var/lib/docker', '/var/lib/containers')
        best = None
        try:
            parts = psutil.disk_partitions(all=False) or []
        except Exception:
            parts = []
        for part in parts:
            fstype = (part.fstype or '').lower()
            mount = part.mountpoint or ''
            if fstype in skip_fs:
                continue
            if any(mount == m or mount.startswith(m + '/') for m in skip_mp):
                continue
            try:
                usage = psutil.disk_usage(mount)
            except (OSError, PermissionError):
                continue
            if usage.total <= 0:
                continue
            if best is None or usage.total > best['total']:
                best = {
                    'percent': usage.percent,
                    'total': usage.total,
                    'used': usage.used,
                    'mount': mount,
                }
        if best:
            return best
        try:
            usage = psutil.disk_usage('/')
            return {'percent': usage.percent, 'total': usage.total, 'used': usage.used, 'mount': '/'}
        except Exception:
            return {'percent': 0, 'total': 0, 'used': 0, 'mount': '/'}

    def collect_metrics(self):
        cpu_percent = psutil.cpu_percent(interval=1)
        memory = psutil.virtual_memory()
        swap = psutil.swap_memory()
        disk = self._disk_usage_main()
        bytes_recv, bytes_sent = self._physical_net_bytes()
        gpu_info = self.collect_gpu_info()
        load1 = 0.0
        try:
            load1 = float(psutil.getloadavg()[0])
        except (AttributeError, OSError, ValueError):
            pass

        network_in_diff = 0
        network_out_diff = 0
        if self.first_network_read:
            self.last_network_in = bytes_recv
            self.last_network_out = bytes_sent
            self.first_network_read = False
        else:
            network_in_diff = max(0, bytes_recv - self.last_network_in)
            network_out_diff = max(0, bytes_sent - self.last_network_out)
            self.last_network_in = bytes_recv
            self.last_network_out = bytes_sent

        metrics = {
            "node_name": self.node_name,
            "timestamp": datetime.now().isoformat(),
            "cpu_percent": cpu_percent,
            "cpu_count": psutil.cpu_count() or 0,
            "load_avg": round(load1, 2),
            "memory_percent": memory.percent,
            "memory_total": memory.total,
            "memory_used": memory.used,
            "swap_percent": swap.percent,
            "disk_percent": disk['percent'],
            "disk_total": disk['total'],
            "disk_used": disk['used'],
            "disk_mount": disk['mount'],
            "network_in": network_in_diff,
            "network_out": network_out_diff,
            "network_in_total": bytes_recv,
            "network_out_total": bytes_sent,
        }

        if gpu_info:
            metrics['gpu'] = gpu_info

        return metrics

    def collect_processes(self, limit=80):
        # Два прохода cpu_percent без sleep на каждый PID — иначе цикл агента растягивается на минуты.
        primed = []
        for proc in psutil.process_iter(['pid', 'name', 'status']):
            try:
                proc.cpu_percent(None)
                primed.append(proc)
            except (psutil.NoSuchProcess, psutil.AccessDenied, psutil.ZombieProcess):
                continue
        time.sleep(0.2)
        rows = []
        for proc in primed:
            try:
                info = proc.as_dict(['pid', 'name', 'status'])
                rows.append({
                    "pid": info.get('pid') or 0,
                    "name": info.get('name') or 'unknown',
                    "cpu_percent": proc.cpu_percent(None) or 0,
                    "memory_percent": proc.memory_percent() or 0,
                    "status": info.get('status') or '',
                })
            except (psutil.NoSuchProcess, psutil.AccessDenied, psutil.ZombieProcess):
                continue
            except Exception as e:
                _log(f"Error collecting process: {e}")
        rows.sort(key=lambda r: (r['cpu_percent'], r['memory_percent']), reverse=True)
        return rows[: max(1, int(limit))]
    
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
            elif command.startswith('docker-logs'):
                parts = command.split()
                if len(parts) < 2:
                    _log("ERROR: docker-logs requires container_id")
                    return False
                cid = parts[1]
                if not re.match(r'^[a-zA-Z0-9][a-zA-Z0-9_.-]*$', cid):
                    _log(f"ERROR: invalid container id for docker-logs: {cid}")
                    return False
                try:
                    tail = int(parts[2]) if len(parts) > 2 else 200
                except ValueError:
                    tail = 200
                tail = max(1, min(tail, 2000))
                logs = self.collect_container_logs([{"container_id": cid, "name": cid[:12]}], tail=tail)
                if logs:
                    self.send_logs(logs)
                else:
                    self.send_logs([{
                        "type": "container",
                        "container_id": cid,
                        "level": "info",
                        "message": "(нет вывода docker logs)",
                        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                    }])
                return True
            elif command.startswith('docker'):
                # docker start|stop|restart <id>
                parts = command.split()
                if len(parts) >= 3:
                    action = parts[1]
                    container_id = parts[2]
                    if action not in ('start', 'stop', 'restart'):
                        _log(f"Blocked docker action: {action}")
                        return False
                    if not re.match(r'^[a-zA-Z0-9][a-zA-Z0-9_.-]*$', container_id):
                        _log(f"ERROR: invalid container id: {container_id}")
                        return False
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
            elif command.startswith('get-process-logs'):
                # Получение логов процесса по запросу
                _log(f"=== EXECUTING get-process-logs COMMAND ===")
                _log(f"Full command: {command}")
                parts = command.split()
                _log(f"Command parts: {parts}, count: {len(parts)}")
                
                if len(parts) < 2:
                    _log("ERROR: get-process-logs requires PID")
                    return False
                
                try:
                    pid = int(parts[1])
                except ValueError:
                    _log(f"ERROR: Invalid PID: {parts[1]}")
                    return False
                
                limit = 1000
                from_time = None
                to_time = None
                
                # Парсим опции
                i = 2
                while i < len(parts):
                    if parts[i] == '--from' and i + 1 < len(parts):
                        from_time = parts[i + 1].strip("'\"")  # Убираем кавычки если есть
                        i += 2
                    elif parts[i] == '--to' and i + 1 < len(parts):
                        to_time = parts[i + 1].strip("'\"")  # Убираем кавычки если есть
                        i += 2
                    elif parts[i] == '--limit' and i + 1 < len(parts):
                        try:
                            limit = int(parts[i + 1])
                        except ValueError:
                            _log(f"WARNING: Invalid limit value: {parts[i + 1]}, using default 1000")
                        i += 2
                    else:
                        i += 1
                
                _log(f"Parsed: PID={pid}, limit={limit}, from={from_time}, to={to_time}")
                _log(f"Collecting process logs for PID {pid}, limit={limit}, from={from_time}, to={to_time}")
                
                # Собираем логи процесса
                process_logs = self.collect_process_logs(pid=pid, limit=limit)
                _log(f"Collected {len(process_logs)} process logs")
                
                # Фильтруем по времени если указано
                if from_time or to_time:
                    filtered_logs = []
                    for log in process_logs:
                        log_time = datetime.strptime(log['timestamp'], "%Y-%m-%d %H:%M:%S")
                        if from_time:
                            from_dt = datetime.fromtimestamp(int(from_time)) if from_time.isdigit() else datetime.strptime(from_time, "%Y-%m-%d %H:%M:%S")
                            if log_time < from_dt:
                                continue
                        if to_time:
                            to_dt = datetime.fromtimestamp(int(to_time)) if to_time.isdigit() else datetime.strptime(to_time, "%Y-%m-%d %H:%M:%S")
                            if log_time > to_dt:
                                continue
                        filtered_logs.append(log)
                    process_logs = filtered_logs
                
                # Отправляем логи на сервер через специальный endpoint для результата команды
                if process_logs:
                    _log(f"Sending {len(process_logs)} process logs to server as command result")
                    try:
                        resp = _request_with_retry(
                            "POST",
                            f"{self.master_url}/api/processes.php?action=command-result",
                            json={
                                'command': command,
                                'logs': process_logs
                            },
                            headers=self.headers,
                            verify=_get_verify(),
                            timeout=30
                        )
                        if resp and resp.status_code in (200, 201):
                            _log(f"Process logs sent as command result successfully: {len(process_logs)} logs, status={resp.status_code}")
                            _log(f"=== get-process-logs COMMAND COMPLETED ===")
                            return True
                        else:
                            _log(f"Failed to send process logs as command result: status={resp.status_code if resp else 'no response'}")
                            if resp:
                                _log(f"Response text: {resp.text[:200]}")
                            # Fallback: отправляем через обычный send_logs
                            _log("Fallback: sending logs via send_logs")
                            self.send_logs(process_logs)
                            _log(f"=== get-process-logs COMMAND COMPLETED (fallback) ===")
                            return True
                    except Exception as e:
                        _log(f"Error sending process logs as command result: {e}")
                        import traceback
                        _log(f"Traceback: {traceback.format_exc()}")
                        # Fallback: отправляем через обычный send_logs
                        _log("Fallback: sending logs via send_logs")
                        self.send_logs(process_logs)
                        _log(f"=== get-process-logs COMMAND COMPLETED (fallback) ===")
                        return True
                else:
                    _log(f"No logs found for PID {pid}")
                    # Отправляем пустой результат
                    try:
                        resp = _request_with_retry(
                            "POST",
                            f"{self.master_url}/api/processes.php?action=command-result",
                            json={
                                'command': command,
                                'logs': []
                            },
                            headers=self.headers,
                            verify=_get_verify(),
                            timeout=30
                        )
                        if resp:
                            _log(f"Empty result sent: status={resp.status_code}")
                        _log(f"=== get-process-logs COMMAND COMPLETED (no logs) ===")
                    except Exception as e:
                        _log(f"Error sending empty result: {e}")
                    return True
            elif command.startswith('upnp'):
                return self.handle_upnp_command(command)
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
                    
                    _log(f"Executing firewall command: {action} {port}/{proto}")
                    
                    # Пытаемся использовать ufw, если доступен
                    ufw_cmd = None
                    if shutil.which("ufw"):
                        # Проверяем, запущен ли от root или нужен sudo
                        if os.geteuid() == 0:
                            # Запущен от root, sudo не нужен
                            if action == "allow":
                                ufw_cmd = ["ufw", "allow", f"{port}/{proto}"]
                            elif action == "deny":
                                ufw_cmd = ["ufw", "deny", f"{port}/{proto}"]
                        else:
                            # Запущен от обычного пользователя, нужен sudo
                            if action == "allow":
                                ufw_cmd = ["sudo", "ufw", "allow", f"{port}/{proto}"]
                            elif action == "deny":
                                ufw_cmd = ["sudo", "ufw", "deny", f"{port}/{proto}"]
                        if ufw_cmd:
                            result = subprocess.run(ufw_cmd, capture_output=True, text=True, check=False)
                            if result.returncode == 0:
                                _log(f"ufw command succeeded: {action} {port}/{proto}")
                                return True
                            else:
                                _log(f"ufw command failed: {result.stderr}")
                    
                    # Fallback на iptables (только для tcp/udp)
                    if proto in ("tcp", "udp") and shutil.which("iptables"):
                        # Определяем, нужен ли sudo
                        sudo_prefix = [] if os.geteuid() == 0 else ["sudo"]
                        
                        if action == "allow":
                            # Добавляем правило ACCEPT (idempotent - проверяем существование)
                            result1 = subprocess.run(
                                sudo_prefix + ["iptables", "-C", "INPUT", "-p", proto, "--dport", str(port), "-j", "ACCEPT"],
                                check=False,
                            )
                            if result1.returncode != 0:
                                # Правило не существует, добавляем
                                result2 = subprocess.run(
                                    sudo_prefix + ["iptables", "-A", "INPUT", "-p", proto, "--dport", str(port), "-j", "ACCEPT"],
                                    capture_output=True, text=True, check=False,
                                )
                                if result2.returncode == 0:
                                    _log(f"iptables allow rule added: {port}/{proto}")
                                    return True
                                else:
                                    _log(f"iptables allow failed: {result2.stderr}")
                            else:
                                _log(f"iptables allow rule already exists: {port}/{proto}")
                                return True
                        elif action == "deny":
                            # Удаляем правило ACCEPT, если есть, и добавляем DROP
                            subprocess.run(
                                sudo_prefix + ["iptables", "-D", "INPUT", "-p", proto, "--dport", str(port), "-j", "ACCEPT"],
                                check=False,
                            )
                            result = subprocess.run(
                                sudo_prefix + ["iptables", "-A", "INPUT", "-p", proto, "--dport", str(port), "-j", "DROP"],
                                capture_output=True, text=True, check=False,
                            )
                            if result.returncode == 0:
                                _log(f"iptables deny rule added: {port}/{proto}")
                                return True
                            else:
                                _log(f"iptables deny failed: {result.stderr}")
                    
                    _log(f"firewall backend not available for command: {command}")
                    return False
        except Exception as e:
            _log(f"Error executing command: {e}")
            import traceback
            _log(f"Traceback: {traceback.format_exc()}")
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
    
    @staticmethod
    def _docker_pct(value):
        try:
            return float(str(value).replace('%', '').strip() or 0)
        except (TypeError, ValueError):
            return 0.0

    @staticmethod
    def _norm_container_status(state_status, raw_status=''):
        st = (state_status or '').strip().lower()
        mapping = {
            'running': 'running',
            'paused': 'paused',
            'restarting': 'restarting',
            'exited': 'stopped',
            'dead': 'stopped',
            'created': 'stopped',
            'removing': 'stopped',
        }
        if st in mapping:
            return mapping[st]
        raw = (raw_status or '').lower()
        if 'paused' in raw:
            return 'paused'
        if raw.startswith('up') or 'restarting' in raw:
            return 'restarting' if 'restarting' in raw else 'running'
        return 'stopped'

    def _docker_inspect(self, ids, timeout=20):
        items = []
        for i in range(0, len(ids), 40):
            chunk = ids[i:i + 40]
            result = subprocess.run(
                ['docker', 'inspect', *chunk],
                capture_output=True, text=True, timeout=timeout,
            )
            if result.returncode != 0:
                _log(f"docker inspect failed: {(result.stderr or '')[:200]}")
                continue
            try:
                data = json.loads(result.stdout or '[]')
            except json.JSONDecodeError:
                continue
            if isinstance(data, list):
                items.extend(data)
        return items

    def collect_docker_snapshot(self):
        # Полный снимок контейнеров и docker-сетей. None = docker недоступен (не затираем панель).
        try:
            listed = subprocess.run(
                ['docker', 'ps', '-aq'],
                capture_output=True, text=True, timeout=8,
            )
        except FileNotFoundError:
            return None
        except Exception as e:
            _log(f"Error collecting docker snapshot: {e}")
            return None

        if listed.returncode != 0:
            err = ((listed.stderr or '') + (listed.stdout or '')).lower()
            if 'not found' in err or listed.returncode == 127:
                return None
            _log(f"docker ps failed: {(listed.stderr or listed.stdout or '')[:200]}")
            return {'containers': [], 'networks': []}

        ids = [line.strip() for line in listed.stdout.splitlines() if line.strip()]
        inspected = self._docker_inspect(ids) if ids else []
        stats_map = {}
        running_ids = [
            (item.get('Id') or '')[:12]
            for item in inspected
            if ((item.get('State') or {}).get('Status') or '').lower() == 'running'
        ]
        if running_ids:
            try:
                stats = subprocess.run(
                    ['docker', 'stats', '--no-stream', '--format', '{{.ID}}|{{.CPUPerc}}|{{.MemPerc}}', *running_ids],
                    capture_output=True, text=True, timeout=20,
                )
                if stats.returncode == 0:
                    for line in stats.stdout.splitlines():
                        parts = line.split('|')
                        if len(parts) >= 3:
                            stats_map[parts[0].strip()] = (
                                self._docker_pct(parts[1]),
                                self._docker_pct(parts[2]),
                            )
            except Exception as e:
                _log(f"docker stats failed: {e}")

        containers = []
        for item in inspected:
            cid = item.get('Id') or ''
            name = (item.get('Name') or '').lstrip('/') or cid[:12]
            state = item.get('State') or {}
            raw_status = state.get('Status') or ''
            if state.get('Error'):
                raw_status = f"{raw_status} ({state.get('Error')})"
            elif item.get('State', {}).get('FinishedAt') and raw_status == 'exited':
                raw_status = item.get('State', {}).get('Status') or raw_status
            cfg = item.get('Config') or {}
            host = item.get('HostConfig') or {}
            nets_obj = ((item.get('NetworkSettings') or {}).get('Networks')) or {}
            networks = []
            ipv4 = ''
            for net_name, net in nets_obj.items():
                ip = (net or {}).get('IPAddress') or ''
                networks.append({
                    'name': net_name,
                    'ipv4': ip,
                    'gateway': (net or {}).get('Gateway') or '',
                })
                if ip and not ipv4:
                    ipv4 = ip
            ports = []
            bindings = ((item.get('NetworkSettings') or {}).get('Ports')) or {}
            for spec, hosts in bindings.items():
                if '/' in spec:
                    cport, proto = spec.split('/', 1)
                else:
                    cport, proto = spec, 'tcp'
                try:
                    cport_n = int(cport)
                except ValueError:
                    continue
                if not hosts:
                    continue
                for bind in hosts:
                    host_port = (bind or {}).get('HostPort') or ''
                    if not host_port:
                        continue
                    try:
                        host_n = int(host_port)
                    except ValueError:
                        continue
                    ports.append({
                        'host': host_n,
                        'host_ip': (bind or {}).get('HostIp') or '',
                        'container': cport_n,
                        'protocol': proto,
                    })
            cpu, mem = 0.0, 0.0
            for key, val in stats_map.items():
                if cid.startswith(key) or key.startswith(cid[:12]):
                    cpu, mem = val
                    break
            containers.append({
                'container_id': cid,
                'name': name,
                'image': cfg.get('Image') or '',
                'status': self._norm_container_status(state.get('Status'), raw_status),
                'raw_status': raw_status,
                'cpu_percent': cpu,
                'memory_percent': mem,
                'ipv4': ipv4,
                'network_mode': host.get('NetworkMode') or '',
                'networks': networks,
                'ports': ports,
            })

        return {
            'containers': containers,
            'networks': self.collect_docker_networks(),
        }

    def collect_docker_networks(self):
        networks = []
        try:
            listed = subprocess.run(
                ['docker', 'network', 'ls', '-q'],
                capture_output=True, text=True, timeout=8,
            )
            if listed.returncode != 0:
                return []
            ids = [line.strip() for line in listed.stdout.splitlines() if line.strip()]
            if not ids:
                return []
            inspected = []
            for i in range(0, len(ids), 40):
                chunk = ids[i:i + 40]
                result = subprocess.run(
                    ['docker', 'network', 'inspect', *chunk],
                    capture_output=True, text=True, timeout=15,
                )
                if result.returncode != 0:
                    continue
                try:
                    data = json.loads(result.stdout or '[]')
                except json.JSONDecodeError:
                    continue
                if isinstance(data, list):
                    inspected.extend(data)
            for net in inspected:
                ipam = (((net.get('IPAM') or {}).get('Config')) or [{}])
                subnet = ''
                gateway = ''
                if ipam and isinstance(ipam[0], dict):
                    subnet = ipam[0].get('Subnet') or ''
                    gateway = ipam[0].get('Gateway') or ''
                members = []
                for cid, info in (net.get('Containers') or {}).items():
                    ip = (info or {}).get('IPv4Address') or ''
                    if '/' in ip:
                        ip = ip.split('/', 1)[0]
                    members.append({
                        'id': cid,
                        'name': ((info or {}).get('Name') or cid[:12]).lstrip('/'),
                        'ipv4': ip,
                    })
                networks.append({
                    'network_id': (net.get('Id') or '')[:64],
                    'name': net.get('Name') or '',
                    'driver': net.get('Driver') or '',
                    'scope': net.get('Scope') or '',
                    'subnet': subnet,
                    'gateway': gateway,
                    'containers': members,
                })
        except FileNotFoundError:
            return []
        except Exception as e:
            _log(f"Error collecting docker networks: {e}")
        return networks

    def collect_containers(self):
        snap = self.collect_docker_snapshot()
        if snap is None:
            return None
        return snap.get('containers') or []
    
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
    
    @staticmethod
    def _skip_virtual_iface(name: str) -> bool:
        return bool(re.match(
            r'^(lo(\d+)?$|docker|veth|br-|virbr|cni|flannel|calico|kube)',
            name or '',
            re.I,
        ))

    @staticmethod
    def _normalize_ipv6(addr: str) -> str:
        return (addr or '').split('%', 1)[0].strip()

    @classmethod
    def _ipv6_rank(cls, addr: str) -> int:
        a = cls._normalize_ipv6(addr).lower()
        if not a or a in ('::', '::1') or a.startswith('ff'):
            return 0
        if a.startswith('fe80:'):
            return 1
        return 2

    def collect_default_gateways(self):
        gateways4 = {}
        gateways6 = {}
        try:
            with open('/proc/net/route', encoding='utf-8') as fh:
                next(fh, None)
                for line in fh:
                    fields = line.split()
                    if len(fields) < 3:
                        continue
                    iface, dest, gw_hex = fields[0], fields[1], fields[2]
                    if dest != '00000000' or gw_hex == '00000000':
                        continue
                    try:
                        gateways4[iface] = socket.inet_ntoa(struct.pack('<L', int(gw_hex, 16)))
                    except (OSError, ValueError, struct.error):
                        continue
        except OSError:
            pass
        try:
            with open('/proc/net/ipv6_route', encoding='utf-8') as fh:
                for line in fh:
                    fields = line.split()
                    if len(fields) < 10:
                        continue
                    dest, dest_prefix, _src, _src_pfx, gw_hex, _metric, _ref, _use, _flags, iface = fields[:10]
                    if dest != '0' * 32 or dest_prefix != '00' or gw_hex == '0' * 32:
                        continue
                    try:
                        gateways6[iface] = socket.inet_ntop(socket.AF_INET6, bytes.fromhex(gw_hex))
                    except (OSError, ValueError):
                        continue
        except OSError:
            pass
        return gateways4, gateways6

    def collect_neighbors(self):
        rows = []
        seen = set()

        def add_row(ip, mac, iface, family):
            ip = (ip or '').split('%', 1)[0].strip()
            mac = (mac or '').lower()
            iface = iface or ''
            if not ip or self._skip_virtual_iface(iface):
                return
            if mac in ('', '*', '00:00:00:00:00:00'):
                mac = ''
            key = (ip, iface)
            if key in seen:
                return
            seen.add(key)
            rows.append({'ip': ip, 'mac': mac, 'iface': iface, 'family': family})

        try:
            result = subprocess.run(
                ['ip', '-o', 'neigh', 'show'],
                capture_output=True, text=True, timeout=2, check=False,
            )
            if result.returncode == 0:
                for line in result.stdout.splitlines():
                    parts = line.split()
                    if len(parts) < 3 or 'FAILED' in parts or 'INCOMPLETE' in parts:
                        continue
                    ip = parts[0]
                    iface = ''
                    mac = ''
                    if 'dev' in parts:
                        iface = parts[parts.index('dev') + 1] if parts.index('dev') + 1 < len(parts) else ''
                    if 'lladdr' in parts:
                        mac = parts[parts.index('lladdr') + 1] if parts.index('lladdr') + 1 < len(parts) else ''
                    family = 6 if ':' in ip else 4
                    add_row(ip, mac, iface, family)
        except (FileNotFoundError, subprocess.TimeoutExpired, OSError):
            pass

        if not rows:
            try:
                with open('/proc/net/arp', encoding='utf-8') as fh:
                    next(fh, None)
                    for line in fh:
                        fields = line.split()
                        if len(fields) < 6:
                            continue
                        ip, _hw, flags, mac, _mask, iface = fields[:6]
                        if flags in ('0x0', '0x00'):
                            continue
                        add_row(ip, mac, iface, 4)
            except OSError:
                pass
        return rows

    def collect_network_interfaces(self):
        interfaces = []
        try:
            net_if_addrs = psutil.net_if_addrs()
            net_if_stats = psutil.net_if_stats()
            net_io_counters = psutil.net_io_counters(pernic=True)
            gateways4, gateways6 = self.collect_default_gateways()

            for interface_name, addrs in net_if_addrs.items():
                if self._skip_virtual_iface(interface_name):
                    continue

                stats = net_if_stats.get(interface_name)
                io_counters = net_io_counters.get(interface_name)

                ipv4 = None
                netmask = None
                ipv6 = None
                ipv6_netmask = None
                ipv6_best = 0
                for addr in addrs:
                    family = getattr(addr, 'family', None)
                    if family == socket.AF_INET:
                        if not ipv4:
                            ipv4 = addr.address
                            netmask = addr.netmask
                    elif family == socket.AF_INET6:
                        rank = self._ipv6_rank(addr.address)
                        if rank > ipv6_best:
                            ipv6_best = rank
                            ipv6 = self._normalize_ipv6(addr.address)
                            ipv6_netmask = addr.netmask

                interfaces.append({
                    'name': interface_name,
                    'ip': ipv4 or 'N/A',
                    'ipv6': ipv6 or '',
                    'netmask': netmask or 'N/A',
                    'ipv6_netmask': ipv6_netmask or '',
                    'gateway': gateways4.get(interface_name) or '',
                    'gateway6': gateways6.get(interface_name) or '',
                    'status': 'up' if stats and stats.isup else 'down',
                    'speed': stats.speed if stats else 0,
                    'rx_bytes': io_counters.bytes_recv if io_counters else 0,
                    'tx_bytes': io_counters.bytes_sent if io_counters else 0
                })
        except Exception as e:
            _log(f"Error collecting network interfaces: {e}")
        return interfaces

    def send_network_interfaces(self, interfaces, neighbors=None):
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/ports.php?action=interfaces",
            json={"interfaces": interfaces, "neighbors": neighbors or []},
            headers=self.headers,
            verify=_get_verify(),
        )
        if resp and resp.status_code in (200, 201):
            return True
        body = ''
        if resp is not None:
            try:
                body = (resp.text or '')[:200]
            except Exception:
                body = ''
        _log(f"Error sending network interfaces: HTTP {resp.status_code if resp else 'no response'} {body}".strip())
        return False

    def collect_upnp(self):
        enabled = os.getenv("UPNP_ENABLED", "true").lower() == "true"
        if not enabled:
            return []
        try:
            mx = int(os.getenv("UPNP_MX", "3"))
            timeout = float(os.getenv("UPNP_TIMEOUT", "8"))
            if timeout <= mx:
                timeout = float(mx) + 5.0
            devices = upnp_mod.discover(mx=mx, timeout=timeout)
            self.upnp_devices = devices
            _log(f"UPnP discovery found {len(devices)} device(s)")
            return devices
        except Exception as e:
            _log(f"UPnP discovery error: {e}")
            return None

    def send_upnp(self, devices):
        try:
            resp = _request_with_retry(
                "POST",
                f"{self.master_url}/api/upnp.php",
                json={"devices": devices, "node_name": self.node_name},
                headers=self.headers,
                verify=_get_verify(),
                timeout=20,
            )
            if resp and resp.status_code in (200, 201):
                return True
            _log(f"UPnP send failed: {resp.status_code if resp else 'no response'}")
        except Exception as e:
            _log(f"Error sending UPnP data: {e}")
        return False

    def send_upnp_gone(self, udn: str):
        if not udn:
            return False
        try:
            resp = _request_with_retry(
                "POST",
                f"{self.master_url}/api/upnp.php",
                json={"gone": [udn], "node_name": self.node_name},
                headers=self.headers,
                verify=_get_verify(),
                timeout=10,
            )
            return bool(resp and resp.status_code in (200, 201))
        except Exception as e:
            _log(f"UPnP gone send failed: {e}")
            return False

    def _on_upnp_event(self, kind: str, payload: dict):
        if kind == "byebye":
            udn = payload.get("udn") or ""
            self.send_upnp_gone(udn)
            with self._upnp_lock:
                self.upnp_devices = [d for d in self.upnp_devices if d.get("udn") != udn]
            return
        if kind == "alive":
            now = time.time()
            if now - self._upnp_alive_at < 15:
                return
            self._upnp_alive_at = now
            Thread(target=self._upnp_refresh_quiet, daemon=True).start()
            return
        if kind == "gena":
            udn = payload.get("udn") or ""
            props = payload.get("props") or {}
            with self._upnp_lock:
                for device in self.upnp_devices:
                    if udn and device.get("udn") != udn:
                        continue
                    extra = device.setdefault("extra", {})
                    extra.update(props)
                    if props.get("ConnectionStatus"):
                        device["connection_status"] = props["ConnectionStatus"]
                    if props.get("ExternalIPAddress"):
                        device["wan_ip"] = props["ExternalIPAddress"]
                    if props.get("PhysicalLinkStatus"):
                        device["wan_link"] = props["PhysicalLinkStatus"]
                    break
            if props:
                self.send_upnp(list(self.upnp_devices))

    def _upnp_refresh_quiet(self):
        devices = self.collect_upnp()
        if devices is not None:
            self.send_upnp(devices)

    def handle_upnp_command(self, command: str) -> bool:
        parts = command.split()
        if len(parts) < 2:
            _log("Invalid UPnP command")
            return False
        action = parts[1].lower()
        devices = self.upnp_devices or self.collect_upnp() or []
        if action == "scan":
            self.send_upnp(devices)
            return True
        udn = None
        if "--udn" in parts:
            idx = parts.index("--udn")
            if idx + 1 < len(parts):
                udn = parts[idx + 1]
        device = upnp_mod.find_device(devices, udn)
        if not device:
            _log("No UPnP IGD device available for mapping")
            return False
        try:
            if action in ("addmap", "add-mapping") and len(parts) >= 6:
                ext_port = int(parts[2])
                int_ip = parts[3]
                int_port = int(parts[4])
                proto = parts[5]
                desc = "HostMonitor"
                for token in parts[6:]:
                    if token == "--udn":
                        break
                    desc = token
                    break
                upnp_mod.add_port_mapping(device, ext_port, int_ip, int_port, proto, desc)
                snap = self.collect_upnp()
                if snap is not None:
                    self.send_upnp(snap)
                return True
            if action in ("delmap", "delete-mapping") and len(parts) >= 4:
                ext_port = int(parts[2])
                proto = parts[3]
                upnp_mod.delete_port_mapping(device, ext_port, proto)
                snap = self.collect_upnp()
                if snap is not None:
                    self.send_upnp(snap)
                return True
        except Exception as e:
            _log(f"UPnP command failed: {e}")
            return False
        _log(f"Unknown UPnP command: {command}")
        return False
    
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
                        ['journalctl', '-p', 'info', '--no-pager', '-n', str(limit), f'_PID={pid}', '--output=short'], 
                        capture_output=True, text=True, timeout=5
                    )
                    if result.returncode == 0:
                        for line in result.stdout.strip().split('\n'):
                            if not line.strip():
                                continue
                            
                            # Парсим формат journalctl short: MMM DD HH:MM:SS hostname process[pid]: message
                            timestamp_str = None
                            process_info = None
                            message = line.strip()
                            
                            # Извлекаем timestamp из начала строки
                            timestamp_match = re.search(r'^(\w+\s+\d+\s+\d+:\d+:\d+)', line)
                            if timestamp_match:
                                try:
                                    time_str = timestamp_match.group(1)
                                    current_year = datetime.now().year
                                    parsed_time = datetime.strptime(f"{current_year} {time_str}", "%Y %b %d %H:%M:%S")
                                    timestamp_str = parsed_time.strftime("%Y-%m-%d %H:%M:%S")
                                except:
                                    timestamp_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                            else:
                                timestamp_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                            
                            # Извлекаем процесс и PID
                            process_match = re.search(r'\w+\s+\d+\s+\d+:\d+:\d+\s+\S+\s+(\S+?)(?:\[(\d+)\])?:', line)
                            if process_match:
                                process_info = process_match.group(1)
                                # PID уже известен из параметра
                            else:
                                parts = line.split(':', 2)
                                if len(parts) >= 3:
                                    process_info = parts[1].strip() if len(parts) > 1 else 'system'
                            
                            # Извлекаем сообщение
                            msg_parts = line.split(':', 2)
                            if len(msg_parts) >= 3:
                                message = msg_parts[2].strip()
                            
                            if not message:
                                continue
                            
                            # Определяем уровень логирования
                            level = 'info'
                            if 'error' in message.lower() or 'failed' in message.lower():
                                level = 'error'
                            elif 'warning' in message.lower() or 'warn' in message.lower():
                                level = 'warning'
                            
                            logs.append({
                                'type': 'process',
                                'pid': pid,
                                'level': level,
                                'message': message,
                                'process': process_info or 'system',
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
                                    if not line.strip():
                                        continue
                                    
                                    # Парсим формат syslog: Dec  3 22:34:27 hostname process[pid]: message
                                    # Или: Dec  3 22:34:27 hostname process: message
                                    timestamp_str = None
                                    process_info = None
                                    pid = None
                                    message = line.strip()
                                    
                                    # Пытаемся извлечь timestamp и процесс из начала строки
                                    # Формат: MMM DD HH:MM:SS hostname process[pid]: message
                                    timestamp_match = re.search(r'^(\w+\s+\d+\s+\d+:\d+:\d+)', line)
                                    if timestamp_match:
                                        # Парсим дату и время
                                        try:
                                            time_str = timestamp_match.group(1)
                                            # Получаем текущий год для парсинга
                                            current_year = datetime.now().year
                                            # Парсим формат "Dec  3 22:34:27"
                                            parsed_time = datetime.strptime(f"{current_year} {time_str}", "%Y %b %d %H:%M:%S")
                                            timestamp_str = parsed_time.strftime("%Y-%m-%d %H:%M:%S")
                                        except:
                                            timestamp_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                                    else:
                                        timestamp_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                                    
                                    # Извлекаем процесс и PID: process[pid] или process:
                                    process_match = re.search(r'\w+\s+\d+\s+\d+:\d+:\d+\s+\S+\s+(\S+?)(?:\[(\d+)\])?:', line)
                                    if process_match:
                                        process_info = process_match.group(1)
                                        if process_match.group(2):
                                            pid = int(process_match.group(2))
                                    else:
                                        # Fallback: берем часть после timestamp и хоста
                                        parts = line.split(':', 2)
                                        if len(parts) >= 3:
                                            process_info = parts[1].strip() if len(parts) > 1 else 'system'
                                            # Пытаемся извлечь PID из process_info
                                            pid_match = re.search(r'\[(\d+)\]', process_info)
                                            if pid_match:
                                                pid = int(pid_match.group(1))
                                                process_info = re.sub(r'\[\d+\]', '', process_info).strip()
                                    
                                    # Извлекаем сообщение (после последнего двоеточия)
                                    msg_parts = line.split(':', 2)
                                    if len(msg_parts) >= 3:
                                        message = msg_parts[2].strip()
                                    else:
                                        message = line.strip()
                                    
                                    if not message:
                                        continue
                                    
                                    level = 'info'
                                    if 'error' in message.lower() or 'failed' in message.lower():
                                        level = 'error'
                                    elif 'warning' in message.lower() or 'warn' in message.lower():
                                        level = 'warning'
                                    
                                    logs.append({
                                        'type': 'process',
                                        'pid': pid,
                                        'level': level,
                                        'message': message,
                                        'process': process_info or 'system',
                                        'timestamp': timestamp_str
                                    })
                    except (FileNotFoundError, subprocess.TimeoutExpired):
                        continue
        except Exception as e:
            _log(f"Error collecting process logs: {e}")
        return logs

    def _log_cursor_path(self):
        candidates = [
            pathlib.Path("/opt/monitoring/agent/log.cursor.json"),
            pathlib.Path("/opt/monitoring/log.cursor.json"),
            pathlib.Path(__file__).resolve().parent / "log.cursor.json",
        ]
        for path in candidates:
            if path.parent.exists():
                return path
        return pathlib.Path("log.cursor.json")

    def _load_log_cursors(self):
        path = self._log_cursor_path()
        try:
            if path.exists():
                data = json.loads(path.read_text(encoding="utf-8") or "{}")
                if isinstance(data, dict):
                    return data
        except Exception:
            pass
        return {}

    def _save_log_cursors(self):
        try:
            path = self._log_cursor_path()
            path.write_text(json.dumps(self._log_cursors), encoding="utf-8")
        except Exception as e:
            _log(f"Failed to save log cursors: {e}")

    @staticmethod
    def _parse_journal_iso(line: str):
        # 2026-08-19T15:22:01+03:00 host process[pid]: message
        match = re.match(
            r'^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2})?)\s+\S+\s+(\S+?)(?:\[(\d+)\])?:\s*(.*)$',
            line.strip(),
        )
        if not match:
            return None
        iso, process, pid, message = match.groups()
        ts = iso.replace("T", " ")[:19]
        try:
            pid_n = int(pid) if pid else None
        except ValueError:
            pid_n = None
        return ts, iso, process, pid_n, message

    @staticmethod
    def _log_level(message: str):
        low = (message or "").lower()
        if any(w in low for w in ("error", "failed", "fatal", "panic", "denied")):
            return "error"
        if "warn" in low:
            return "warning"
        return "info"

    def _only_new_logs(self, key: str, items: list):
        last = self._log_cursors.get(key)
        out = []
        newest = last
        for item in items:
            fp = item.get("_fp") or ""
            if last and fp <= last:
                continue
            out.append(item)
            if not newest or fp > newest:
                newest = fp
        if newest and newest != last:
            self._log_cursors[key] = newest
            self._save_log_cursors()
        for item in out:
            item.pop("_fp", None)
        return out

    def collect_system_logs(self, limit=150):
        # Хвост syslog/journald только новыми строками, тип system — иначе вкладка «Системные» пустая.
        parsed = []
        since = self._log_cursors.get("system_since")
        try:
            cmd = ["journalctl", "-p", "info", "--no-pager", "-n", str(limit), "--output=short-iso"]
            if since:
                cmd.extend(["--since", since])
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=8)
            if result.returncode == 0:
                last_iso = since
                for line in result.stdout.splitlines():
                    row = self._parse_journal_iso(line)
                    if not row:
                        continue
                    ts, iso, process, pid, message = row
                    if not message:
                        continue
                    parsed.append({
                        "type": "system",
                        "level": self._log_level(message),
                        "message": f"{process}: {message}" if process else message,
                        "timestamp": ts,
                        "_fp": f"{iso}|{message[:160]}",
                    })
                    last_iso = iso
                if last_iso:
                    self._log_cursors["system_since"] = last_iso.replace("T", " ")[:19]
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass
        except Exception as e:
            _log(f"journalctl system logs failed: {e}")

        if not parsed:
            for log_file in ("/var/log/syslog", "/var/log/messages"):
                if not os.path.exists(log_file):
                    continue
                try:
                    result = subprocess.run(
                        ["tail", "-n", str(limit), log_file],
                        capture_output=True, text=True, timeout=5,
                    )
                except (FileNotFoundError, subprocess.TimeoutExpired):
                    continue
                if result.returncode != 0:
                    continue
                for line in result.stdout.splitlines():
                    if not line.strip():
                        continue
                    timestamp_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    timestamp_match = re.search(r"^(\w+\s+\d+\s+\d+:\d+:\d+)", line)
                    if timestamp_match:
                        try:
                            parsed_time = datetime.strptime(
                                f"{datetime.now().year} {timestamp_match.group(1)}",
                                "%Y %b %d %H:%M:%S",
                            )
                            timestamp_str = parsed_time.strftime("%Y-%m-%d %H:%M:%S")
                        except Exception:
                            pass
                    process_info = "system"
                    process_match = re.search(
                        r"\w+\s+\d+\s+\d+:\d+:\d+\s+\S+\s+(\S+?)(?:\[(\d+)\])?:",
                        line,
                    )
                    if process_match:
                        process_info = process_match.group(1)
                    msg_parts = line.split(":", 2)
                    message = msg_parts[2].strip() if len(msg_parts) >= 3 else line.strip()
                    if not message:
                        continue
                    parsed.append({
                        "type": "system",
                        "level": self._log_level(message),
                        "message": f"{process_info}: {message}",
                        "timestamp": timestamp_str,
                        "_fp": f"{timestamp_str}|{message[:160]}",
                    })
                break
        return self._only_new_logs("system_fp", parsed)

    def collect_ssh_auth_logs(self, limit=200):
        # Сбор логов аутентификации SSH (auth_ssh) с ограничением объёма
        logs = []

        def _parse_ssh_event(message: str):
            # Возвращает (username, ip, port, success[True/False/None], summary_message)
            msg = message.strip()
            user = None
            ip = None
            port = None
            success = None

            # Успешные авторизации - разные типы
            m = re.search(r"Accepted (?:password|publickey|keyboard-interactive) for (\S+) from ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
            if m:
                user, ip, port = m.group(1), m.group(2), int(m.group(3))
                success = True
                summary = f"SUCCESS user={user} ip={ip} port={port}"
                return user, ip, port, success, f"{summary} | {msg}"

            # Неудачные попытки с паролем
            m = re.search(r"Failed password for (invalid user )?(\S+) from ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
            if m:
                invalid_prefix, user, ip, port = m.group(1) or "", m.group(2), m.group(3), int(m.group(4))
                success = False
                invalid = "invalid " if invalid_prefix else ""
                summary = f"FAIL user={invalid}{user} ip={ip} port={port}"
                return user, ip, port, success, f"{summary} | {msg}"

            # Невалидный пользователь
            m = re.search(r"Invalid user (\S+) from ([0-9\.]+)(?: port (\d+))?", msg, re.IGNORECASE)
            if m:
                user, ip = m.group(1), m.group(2)
                port = int(m.group(3)) if m.group(3) else None
                success = False
                port_str = f" port={port}" if port else ""
                summary = f"FAIL user=invalid {user} ip={ip}{port_str}"
                return user, ip, port, success, f"{summary} | {msg}"

            # Authentication failure из pam - извлекаем IP и пользователя отдельно
            m = re.search(r"authentication failure.*rhost=([0-9\.]+)", msg, re.IGNORECASE)
            if m:
                ip = m.group(1)
                # Пытаемся найти user= в сообщении
                user_match = re.search(r"user=(\S+)", msg, re.IGNORECASE)
                user = user_match.group(1) if user_match else None
                success = False
                user_str = f" user={user}" if user else ""
                summary = f"FAIL{user_str} ip={ip}"
                return user, ip, None, success, f"{summary} | {msg}"

            # Disconnected from - извлекаем пользователя и IP
            m = re.search(r"Disconnected from (?:authenticating user |invalid user )?(\S+) ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
            if m:
                user, ip, port = m.group(1), m.group(2), int(m.group(3))
                summary = f"INFO disconnected user={user} ip={ip} port={port}"
                return user, ip, port, None, f"{summary} | {msg}"

            # Received disconnect from
            m = re.search(r"Received disconnect from ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
            if m:
                ip, port = m.group(1), int(m.group(2))
                summary = f"INFO disconnect ip={ip} port={port}"
                return None, ip, port, None, f"{summary} | {msg}"

            # Connection closed/reset
            m = re.search(r"Connection (?:closed by|reset by|from) ([0-9\.]+)(?: port (\d+))?", msg, re.IGNORECASE)
            if m:
                ip = m.group(1)
                port = int(m.group(2)) if m.group(2) else None
                port_str = f" port={port}" if port else ""
                summary = f"INFO connection ip={ip}{port_str}"
                return None, ip, port, None, f"{summary} | {msg}"

            # Пытаемся извлечь IP из любого сообщения (fallback)
            ip_match = re.search(r"\b([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\b", msg)
            if ip_match and not ip:
                ip = ip_match.group(1)
                # Пытаемся найти порт рядом с IP
                port_match = re.search(rf"{re.escape(ip)}:(\d+)", msg)
                if port_match:
                    port = int(port_match.group(1))
                # Пытаемся найти пользователя в сообщении
                user_match = re.search(r"(?:user=|for )(\S+)", msg, re.IGNORECASE)
                if user_match:
                    user = user_match.group(1)

            return user, ip, port, success, msg

        def _ssh_entry(line, process_info, message, timestamp_str):
            username, ip, port, success, summary = _parse_ssh_event(message)
            if success is True:
                level = "info"
            elif success is False:
                level = "error"
            else:
                level = self._log_level(message)
            entry = {
                "type": "auth_ssh",
                "level": level,
                "message": summary or message,
                "process": process_info or "sshd",
                "timestamp": timestamp_str,
                "username": username,
                "ip": ip,
                "port": port,
                "raw_message": line.strip(),
                "_fp": f"{timestamp_str}|{(line or message)[:160]}",
            }
            if success is True:
                entry["success"] = True
            elif success is False:
                entry["success"] = False
            return entry

        parsed = []
        try:
            since = self._log_cursors.get("ssh_since")
            cmd = [
                "journalctl", "-t", "sshd", "-u", "ssh", "-u", "sshd",
                "--no-pager", "-n", str(limit), "--output=short-iso",
            ]
            if since:
                cmd.extend(["--since", since])
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=8)
            if result.returncode == 0 and (result.stdout or "").strip():
                last_iso = since
                for line in result.stdout.splitlines():
                    if not line.strip():
                        continue
                    row = self._parse_journal_iso(line)
                    if row:
                        ts, iso, process, _pid, message = row
                        parsed.append(_ssh_entry(line, process, message, ts))
                        last_iso = iso
                    else:
                        parsed.append(_ssh_entry(
                            line, "sshd", line.strip(),
                            datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                        ))
                if last_iso:
                    self._log_cursors["ssh_since"] = last_iso.replace("T", " ")[:19]
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass
        except Exception as e:
            _log(f"journalctl ssh logs failed: {e}")

        if not parsed:
            for auth_file in ("/var/log/auth.log", "/var/log/secure"):
                if not os.path.exists(auth_file):
                    continue
                try:
                    result = subprocess.run(
                        ["tail", "-n", str(limit * 3), auth_file],
                        capture_output=True, text=True, timeout=5,
                    )
                except (FileNotFoundError, subprocess.TimeoutExpired):
                    continue
                if result.returncode not in (0, 1):
                    continue
                for line in result.stdout.splitlines():
                    if "sshd" not in line.lower():
                        continue
                    timestamp_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                    timestamp_match = re.search(r"^(\w+\s+\d+\s+\d+:\d+:\d+)", line)
                    if timestamp_match:
                        try:
                            parsed_time = datetime.strptime(
                                f"{datetime.now().year} {timestamp_match.group(1)}",
                                "%Y %b %d %H:%M:%S",
                            )
                            timestamp_str = parsed_time.strftime("%Y-%m-%d %H:%M:%S")
                        except Exception:
                            pass
                    parts = line.split(":", 2)
                    message = parts[2].strip() if len(parts) >= 3 else line.strip()
                    parsed.append(_ssh_entry(line, "sshd", message, timestamp_str))
                break

        logs = self._only_new_logs("ssh_fp", parsed)
        _log(f"collect_ssh_auth_logs: new={len(logs)}")
        return logs

    def collect_logs(self):
        logs = []
        try:
            if hasattr(psutil, "boot_time"):
                boot_ts = int(psutil.boot_time())
                if self._log_cursors.get("boot_sent") != boot_ts:
                    boot_time = datetime.fromtimestamp(boot_ts)
                    logs.append({
                        "type": "system",
                        "level": "info",
                        "message": f"System boot at {boot_time.strftime('%Y-%m-%d %H:%M:%S')}",
                        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                    })
                    self._log_cursors["boot_sent"] = boot_ts
                    self._save_log_cursors()
            system_logs = self.collect_system_logs(limit=150)
            logs.extend(system_logs)
            ssh_logs = self.collect_ssh_auth_logs(limit=200)
            logs.extend(ssh_logs)
            _log(f"collect_logs: system={len(system_logs)}, ssh_auth={len(ssh_logs)}, total={len(logs)}")
        except Exception as e:
            _log(f"Error collecting logs: {e}")
        return logs
    
    def send_logs(self, logs):
        # Отправка логов на сервер (с детальным логированием по SSH-логам)
        if not logs:
            _log("send_logs: nothing to send (0 logs)")
            return True
        total = len(logs)
        ssh_auth_count = sum(1 for l in logs if l.get("type") == "auth_ssh")
        container_count = sum(1 for l in logs if l.get("type") == "container")
        other_count = total - ssh_auth_count - container_count
        _log(f"send_logs: total={total}, ssh_auth={ssh_auth_count}, container={container_count}, other={other_count}")
        if ssh_auth_count > 0:
            try:
                sample = next(l for l in logs if l.get("type") == "auth_ssh")
                msg = str(sample.get("message", ""))[:200]
                proc = sample.get("process", "")
                ts = sample.get("timestamp", "")
                _log(f"send_logs: ssh_auth sample ts={ts}, process={proc}, msg={msg}")
            except StopIteration:
                pass
            except Exception as e:
                _log(f"send_logs: error while logging ssh_auth sample: {e}")
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/logs.php",
            json=logs,
            headers=self.headers,
            verify=_get_verify(),
        )
        ok = bool(resp and resp.status_code in (200, 201))
        _log(f"send_logs: result status={resp.status_code if resp else 'no response'}, ok={ok}")
        if resp:
            try:
                data = resp.json()
                _log(f"send_logs: response json={data}")
            except Exception as e:
                _log(f"send_logs: failed to parse response json: {e}, text={resp.text[:200] if hasattr(resp, 'text') else ''}")
        return ok

    def collect_container_logs(self, containers, tail=50):
        # Сбор логов Docker-контейнеров через docker logs (по запросу, не каждый цикл)
        logs = []
        for c in containers:
            cid = c.get("container_id") or c.get("id")
            if not cid:
                continue
            name = c.get("name") or cid[:12]
            try:
                result = subprocess.run(
                    ["docker", "logs", "--tail", str(tail), cid],
                    stdout=subprocess.PIPE,
                    stderr=subprocess.STDOUT,
                    text=True,
                    timeout=15,
                )
                now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                output = result.stdout or ''
                if result.returncode != 0 and not output.strip():
                    logs.append({
                        "type": "container",
                        "container_id": cid,
                        "level": "error",
                        "message": f"docker logs failed for {name}",
                        "timestamp": now,
                    })
                    continue
                for line in output.splitlines():
                    line = line.strip()
                    if not line:
                        continue
                    level = 'error' if re.search(r'\b(error|fatal|panic)\b', line, re.I) else 'info'
                    logs.append(
                        {
                            "type": "container",
                            "container_id": cid,
                            "level": level,
                            "message": line,
                            "timestamp": now,
                        }
                    )
            except Exception as e:
                _log(f"Error collecting logs for container {cid}: {e}")
        return logs
    
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
        if os.getenv("UPNP_ENABLED", "true").lower() == "true":
            try:
                upnp_mod.start_background(self._on_upnp_event)
                _log("UPnP SSDP NOTIFY + GENA listeners started")
            except Exception as e:
                _log(f"UPnP listeners failed: {e}")
        
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
            
            # Снимок Docker: контейнеры + сети. Пустой список тоже отправляем, чтобы панель не держала призрак.
            docker_snap = self.collect_docker_snapshot()
            if docker_snap is not None:
                _log(
                    f"Sending {len(docker_snap.get('containers') or [])} containers, "
                    f"{len(docker_snap.get('networks') or [])} docker networks"
                )
                self.send_containers(docker_snap)
            
            # Собираем и отправляем порты (реже)
            ports = self.collect_ports()
            if ports:
                _log(f"Sending {len(ports)} ports")
                self.send_ports(ports)
            
            # Собираем и отправляем сетевые интерфейсы (реже, раз в несколько циклов)
            if cycle % 5 == 0:
                interfaces = self.collect_network_interfaces()
                neighbors = self.collect_neighbors()
                _log(f"Sending {len(interfaces)} network interfaces, {len(neighbors)} neighbors")
                if not self.send_network_interfaces(interfaces, neighbors):
                    _log("Error: failed to send network interfaces")

            if os.getenv("UPNP_ENABLED", "true").lower() == "true":
                upnp_every = max(1, int(os.getenv("UPNP_INTERVAL_CYCLES", "2")))
                if cycle == 1 or cycle % upnp_every == 0:
                    devices = self.collect_upnp()
                    if devices is None:
                        _log("UPnP discovery failed, keeping last snapshot")
                    else:
                        _log(f"Sending {len(devices)} UPnP devices")
                        if not self.send_upnp(devices):
                            _log("Error: failed to send UPnP snapshot")
            
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

