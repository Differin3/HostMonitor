# Агент для сбора метрик на нодах — точка входа и цикл опроса
# Сборщики разнесены по mixin-модулям рядом: system, network, docker,
# smart, logs, topology, transport, updater. Общие помощники — runtime.py.
import os  # окружение
import pathlib  # пути
import psutil  # системные метрики
import time  # таймеры
from threading import Lock  # фоновый поток

try:
    from .runtime import _log, load_node_conf, start_health_server
except ImportError:
    from runtime import _log, load_node_conf, start_health_server

try:
    from . import upnp as upnp_mod
    from . import lldp_discovery as lldp_mod
except ImportError:
    import upnp as upnp_mod
    import lldp_discovery as lldp_mod



try:
    from .system import SystemMixin
    from .transport import TransportMixin
    from .updater import UpdaterMixin
    from .docker import DockerMixin
    from .smart import SmartMixin
    from .network import NetworkMixin
    from .topology import TopologyMixin
    from .logs import LogsMixin
except ImportError:
    from system import SystemMixin
    from transport import TransportMixin
    from updater import UpdaterMixin
    from docker import DockerMixin
    from smart import SmartMixin
    from network import NetworkMixin
    from topology import TopologyMixin
    from logs import LogsMixin


class MonitoringAgent(
    SystemMixin,
    TransportMixin,
    UpdaterMixin,
    DockerMixin,
    SmartMixin,
    NetworkMixin,
    TopologyMixin,
    LogsMixin,
):
    # Инициализация общего состояния и цикл опроса; остальное — в mixin-модулях.

    def __init__(self, master_url, node_name, node_token):
        self.master_url = master_url
        self.node_name = node_name
        self.node_token = node_token
        self.headers = {"Authorization": f"Bearer {node_token}", "Content-Type": "application/json"}
        self.auth = None
        self.signed_headers = self.headers
        try:
            if AgentAuth is not None and os.getenv("NODE_ID") and os.getenv("NODE_SECRET_B64"):
                try:
                    nid = int(os.getenv("NODE_ID"))
                except Exception:
                    nid = 0
                self.auth = AgentAuth(nid, os.getenv("NODE_SECRET_B64"))
                if self.auth.can_sign:
                    _log(f"Ed25519 auth enabled for node_id={nid}")
        except Exception as e:
            _log(f"auth init failed: {e}")
        # Храним предыдущие значения для расчета расхода трафика
        self.last_network_in = 0
        self.last_network_out = 0
        self.last_collect_time = time.time()
        self.first_network_read = True
        self.upnp_devices = []
        self._upnp_lock = Lock()
        self._upnp_alive_at = 0.0
        self.lldp_cache = {}  # key -> neighbor info (passive + active)
        self._lldp_devices = []  # последний снимок LLDP/SNMP-устройств (для merge с UPnP)
        self._cdp_hosts = set()  # mgmt-IP соседей, найденных через CDP (коммутаторы/роутеры)
        # Буфер метрик для retry при transient ошибках
        self._pending_metrics = None
        self._pending_processes = None
        self._cycle_counter = 0
        # Pre-prime CPU: первый вызов cpu_percent() всегда возвращает 0.0
        try:
            psutil.cpu_percent(interval=None)
        except Exception:
            pass
        self._lldp_lock = Lock()
        # Async GPU: кэш + фоновый поток
        self._gpu_cache = []
        self._gpu_lock = Lock()
        self._gpu_thread = None
        self._log_cursors = self._load_log_cursors()
        # Детекция платформы (TrueNAS, Proxmox, Synology, FreeBSD и т.д.)
        self._platform = self._detect_platform()
        _log(f"Platform: {self._platform['os_name']} ({self._platform['os_family']}) "
             f"on {self._platform['arch']}, ZFS={'yes' if self._platform['has_zfs'] else 'no'} "
             f"Docker={'yes' if self._platform['has_docker'] else 'no'}")

    def run(self):
        # Основной цикл агента
        collect_interval = int(os.getenv("COLLECT_INTERVAL", "60"))
        heartbeat_interval = int(os.getenv("HEARTBEAT_INTERVAL", "15"))  # Heartbeat каждые 15 секунд по умолчанию
        _log(f"Agent started, collection interval: {collect_interval}s, heartbeat interval: {heartbeat_interval}s")
        try:
            self._ensure_git_runtime_env()
            self._ensure_git_safe_directory(self.install_root())
        except Exception as e:
            _log(f"git safe.directory bootstrap failed: {e}")
        if os.getenv("UPNP_ENABLED", "true").lower() == "true":
            try:
                upnp_mod.start_background(self._on_upnp_event)
                _log("UPnP SSDP NOTIFY + GENA listeners started")
            except Exception as e:
                _log(f"UPnP listeners failed: {e}")
        try:
            if lldp_mod.start_passive(self._on_lldp_device):
                iface = os.getenv("LLDP_LISTEN_INTERFACE", "").strip() or "auto"
                _log(f"LLDP passive sniff started (iface={iface})")
        except Exception as e:
            _log(f"LLDP passive start failed: {e}")
        
        # Запускаем фоновый сбор GPU данных (не блокирует основной цикл)
        self._start_gpu_collector()
        
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
                    last_heartbeat = current_time  # откладываем следующую попытку
            
            _log(f"Cycle {cycle}: collecting metrics...")
            
            # Проверяем команды от мастера (логи / docker / updates и т.д.)
            if not self.run_pending_command(quiet=False):
                if cycle % 10 == 0:
                    _log(f"No pending commands found (cycle {cycle})")
            
            # Повторная отправка буферизованных метрик при transient ошибках
            if self._pending_metrics is not None:
                _log("Retrying buffered metrics from previous failed cycle...")
                if self.send_data(self._pending_metrics, self._pending_processes):
                    _log("Buffered metrics sent successfully")
            
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
            
            # S.M.A.R.T. данные дисков (раз в 5 циклов = ~5 минут)
            if cycle % 5 == 0:
                smart_drives = self.collect_smart()
                if smart_drives:
                    _log(f"Sending SMART data for {len(smart_drives)} drive(s)")
                    if not self.send_smart(smart_drives):
                        _log("Error: failed to send SMART data")
            
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
                        devices = list(self.upnp_devices or [])
                    else:
                        _log(f"UPnP discovery found {len(devices)} device(s)")
                    try:
                        lldp_devices = self._poll_lldp_devices()
                    except Exception as e:
                        _log(f"LLDP poll error: {e}")
                        lldp_devices = []
                    all_devices = self.merge_devices(devices or [], lldp_devices or [])
                    _log(f"Sending {len(all_devices)} devices (UPnP+LLDP)")
                    if all_devices and not self.send_upnp(all_devices):
                        _log("Error: failed to send UPnP/LLDP snapshot")
            elif os.getenv("LLDP_PASSIVE", "true").lower() == "true" or os.getenv("LLDP_ACTIVE_POLL_KNOWN", "true").lower() == "true":
                # UPnP выключен — всё равно шлём LLDP-снимок
                lldp_every = max(1, int(os.getenv("UPNP_INTERVAL_CYCLES", "2")))
                if cycle == 1 or cycle % lldp_every == 0:
                    try:
                        lldp_devices = self._poll_lldp_devices()
                    except Exception as e:
                        _log(f"LLDP poll error: {e}")
                        lldp_devices = []
                    if lldp_devices:
                        _log(f"Sending {len(lldp_devices)} LLDP devices")
                        if not self.send_upnp(lldp_devices):
                            _log("Error: failed to send LLDP snapshot")
            
            # Собираем и отправляем логи (реже, раз в несколько циклов)
            logs = self.collect_logs()
            if logs and not self.send_logs(logs):
                _log("Error: failed to send logs")
            
            _log(f"Cycle {cycle} completed, sleeping {collect_interval}s (heartbeat every {heartbeat_interval}s)...")
            # Не sleep(60) целиком: иначе last_seen устаревает и панель мигает offline.
            # Будим каждые heartbeat_interval: heartbeat + проверка pending-команд
            # (иначе docker-logs / get-process-logs ждут до конца COLLECT_INTERVAL).
            # Overrun compensation: deadline считается от начала цикла, а не от его конца.
            deadline = current_time + collect_interval
            while True:
                now = time.time()
                if now - last_heartbeat >= heartbeat_interval:
                    if self.send_heartbeat():
                        last_heartbeat = now
                    else:
                        last_heartbeat = now  # откладываем следующую попытку на heartbeat_interval
                    # Быстрый отклик на логи контейнеров/процессов и прочие команды
                    self.run_pending_command(quiet=True)
                remaining = deadline - time.time()
                if remaining <= 0:
                    break
                time.sleep(min(float(heartbeat_interval), remaining))

if __name__ == "__main__":
    import sys
    print(f"[agent] Starting agent...", flush=True)
    print(f"[agent] Python version: {sys.version}", flush=True)
    print(f"[agent] Current working directory: {os.getcwd()}", flush=True)
    print(f"[agent] Script location: {pathlib.Path(__file__).absolute()}", flush=True)
    os.environ.setdefault('GIT_CONFIG_COUNT', '1')
    os.environ.setdefault('GIT_CONFIG_KEY_0', 'safe.directory')
    os.environ.setdefault('GIT_CONFIG_VALUE_0', '*')
    os.environ.setdefault('GIT_TERMINAL_PROMPT', '0')
    
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
    
    if not master_url.startswith("https://"):
        print(f"[agent] ERROR: MASTER_URL does not use HTTPS: {master_url}")
        print("[agent] Agent tokens and data would be transmitted in cleartext.")
        print("[agent] Set MASTER_URL to an https:// address, or add MASTER_URL_INSECURE=1 to node.conf to override.")
        if os.getenv("MASTER_URL_INSECURE", "0") != "1":
            print("[agent] Refusing to start without HTTPS. Add MASTER_URL_INSECURE=1 to node.conf to force start.")
            sys.exit(1)
        print("[agent] Continuing with insecure connection (MASTER_URL_INSECURE=1).")
    
    print(f"[agent] Configuration loaded:")
    print(f"  MASTER_URL: {master_url}")
    print(f"  NODE_NAME: {node_name}")
    print(f"  NODE_TOKEN: {'set (len=' + str(len(node_token)) + ')' if node_token else '(not set)'}")
    print(f"  COLLECT_INTERVAL: {collect_interval}")

    if not node_token:
        print("[agent] ERROR: NODE_TOKEN is not set. Please configure the agent.")
        print(f"[agent] MASTER_URL: {master_url}")
        print(f"[agent] NODE_NAME: {node_name}")
        print(f"[agent] NODE_TOKEN from env: {'set' if os.getenv('NODE_TOKEN') else 'NOT SET'}")
        print("[agent] Check that node.conf exists and contains NODE_TOKEN=...")
        exit(1)
    
    print(f"[agent] Initializing agent with token length: {len(node_token)}")
    agent = MonitoringAgent(master_url=master_url, node_name=node_name, node_token=node_token)
    if health_port > 0:
        start_health_server(health_port)
    agent.run()


try:
    from auth import AgentAuth
except Exception:
    AgentAuth = None
