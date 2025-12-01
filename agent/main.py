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
from config import TLS_VERIFY, TLS_CERT_PATH, MAX_RETRIES, RETRY_DELAY, HEALTH_PORT  # конфиг


def load_node_conf(path: str = "node.conf") -> None:
    # Загрузка node.conf (KEY=\"VAL\") в переменные окружения для Debian/Ubuntu
    cfg_path = pathlib.Path(__file__).with_name(path)
    if not cfg_path.exists():
        return
    try:
        for line in cfg_path.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" not in line:
                continue
            key, val = line.split("=", 1)
            key = key.strip()
            val = val.strip().strip('"').strip("'")
            if key and key not in os.environ:
                os.environ[key] = val
    except Exception as e:
        print(f"Error loading node.conf: {e}")


def _get_verify():
    # Параметр verify для requests: путь к сертификату или булево из конфига
    return TLS_CERT_PATH or TLS_VERIFY


def _log(msg: str) -> None:
    # Простой лог с временем в stdout
    print(f"[agent] {datetime.now().isoformat()} {msg}")


def _request_with_retry(method: str, url: str, **kwargs) -> Optional[requests.Response]:
    # HTTP-запрос с повторами и задержкой
    timeout = kwargs.pop("timeout", 10)
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            resp = requests.request(method, url, timeout=timeout, **kwargs)
            return resp
        except Exception as e:
            _log(f"request error ({attempt}/{MAX_RETRIES}) {method} {url}: {e}")
            if attempt == MAX_RETRIES:
                return None
            time.sleep(RETRY_DELAY)
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


def start_health_server(port: int) -> None:
    # Запуск простого HTTP health-check сервера в отдельном потоке
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
        self.headers = {"Authorization": f"Bearer {node_token}"}
    
    def collect_metrics(self):
        # Сбор метрик системы
        cpu_percent = psutil.cpu_percent(interval=1)
        memory = psutil.virtual_memory()
        disk = psutil.disk_usage('/')
        network = psutil.net_io_counters()
        
        return {
            "node_name": self.node_name,
            "timestamp": datetime.now().isoformat(),
            "cpu_percent": cpu_percent,
            "memory_percent": memory.percent,
            "memory_total": memory.total,
            "memory_used": memory.used,
            "disk_percent": disk.percent,
            "disk_total": disk.total,
            "disk_used": disk.used,
            "network_in": network.bytes_recv,
            "network_out": network.bytes_sent
        }
    
    def collect_processes(self):
        # Сбор информации о процессах
        processes = []
        for proc in psutil.process_iter(['pid', 'name', 'cpu_percent', 'memory_percent', 'status']):
            try:
                processes.append({
                    "pid": proc.info['pid'],
                    "name": proc.info['name'],
                    "cpu_percent": proc.info['cpu_percent'],
                    "memory_percent": proc.info['memory_percent'],
                    "status": proc.info['status']
                })
            except (psutil.NoSuchProcess, psutil.AccessDenied):
                pass
        return processes
    
    def send_data(self, metrics, processes):
        # Отправка данных на главный сервер
        data = {"metrics": metrics, "processes": processes}
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/metrics.php",
            json=data,
            headers=self.headers,
            verify=_get_verify(),
        )
        if not resp or resp.status_code not in (200, 201):
            return False
        proc_resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/processes.php",
            json={"processes": processes},
            headers=self.headers,
            verify=_get_verify(),
        )
        return bool(proc_resp and proc_resp.status_code in (200, 201))
    
    def check_commands(self):
        # Проверка команд от мастера (GET /api/nodes.php?action=get-command)
        resp = _request_with_retry(
            "GET",
            f"{self.master_url}/api/nodes.php",
            params={"id": self.node_name, "action": "get-command"},
            headers=self.headers,
            verify=_get_verify(),
        )
        if resp and resp.status_code == 200:
            try:
                data = resp.json()
            except Exception as e:
                _log(f"bad JSON in get-command: {e}")
                return None
            status = data.get('status')  # новый формат: status = ok / no-command
            if status not in (None, 'ok', 'no-command'):
                return None
            if data.get('command') and data.get('command_status') == 'pending':
                return data.get('command')
        return None
    
    def execute_command(self, command):
        # Выполнение команды (с базовой фильтрацией)
        try:
            if command.startswith('reboot'):
                # Перезагрузка системы
                subprocess.run(['sudo', 'reboot'], check=False)
                return True
            elif command.startswith('shutdown'):
                # Выключение системы
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
        _request_with_retry(
            "POST",
            f"{self.master_url}/api/nodes.php",
            params={"id": self.node_name, "action": "command-status"},
            json={"command": command, "status": status},
            headers=self.headers,
            verify=_get_verify(),
        )
    
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
    
    def collect_logs(self):
        # Сбор системных логов (опционально, можно расширить)
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
            
            # Проверяем критические процессы
            critical_processes = ['systemd', 'init', 'kernel']
            for proc in psutil.process_iter(['name']):
                try:
                    if proc.info['name'] in critical_processes:
                        logs.append({
                            'level': 'debug',
                            'message': f"Critical process {proc.info['name']} is running",
                            'timestamp': datetime.now().isoformat()
                        })
                        break
                except (psutil.NoSuchProcess, psutil.AccessDenied):
                    pass
        except Exception as e:
            print(f"Error collecting logs: {e}")
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
    
    def run(self):
        # Основной цикл агента
        from config import COLLECT_INTERVAL
        
        while True:
            # Проверяем команды от мастера
            command = self.check_commands()
            if command:
                print(f"Executing command: {command}")
                success = self.execute_command(command)
                self.report_command_status(command, 'completed' if success else 'failed')
            
            # Собираем и отправляем метрики
            metrics = self.collect_metrics()
            processes = self.collect_processes()
            if not self.send_data(metrics, processes):
                print("Error: failed to send metrics/processes")
            
            # Собираем и отправляем контейнеры (реже)
            containers = self.collect_containers()
            if containers:
                self.send_containers(containers)
            
            # Собираем и отправляем порты (реже)
            ports = self.collect_ports()
            if ports:
                self.send_ports(ports)
            
            # Собираем и отправляем логи (реже, раз в несколько циклов)
            logs = self.collect_logs()
            if logs and not self.send_logs(logs):
                print("Error: failed to send logs")
            
            time.sleep(COLLECT_INTERVAL)

if __name__ == "__main__":
    load_node_conf()  # сначала поднимаем node.conf в окружение
    from config import MASTER_URL, NODE_NAME, NODE_TOKEN, COLLECT_INTERVAL, HEALTH_PORT

    if not NODE_TOKEN:
        print("Error: NODE_TOKEN is not set. Please configure the agent.")
        exit(1)

    agent = MonitoringAgent(master_url=MASTER_URL, node_name=NODE_NAME, node_token=NODE_TOKEN)
    if HEALTH_PORT > 0:
        start_health_server(HEALTH_PORT)
    agent.run()

