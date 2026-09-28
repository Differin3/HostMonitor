# Общие помощники агента: конфиг, логирование, HTTP с ретраями, health-сервер
import os  # окружение
import pathlib  # пути
import requests  # HTTP-запросы
import time  # таймеры
from http.server import BaseHTTPRequestHandler, HTTPServer
from threading import Thread
from datetime import datetime  # время
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)


def load_node_conf(path: str = "node.conf") -> None:
    # Загрузка node.conf (KEY=\"VAL\") в переменные окружения для Debian/Ubuntu
    # Пробуем несколько путей: рядом с main.py, в /opt/monitoring/, в /opt/monitoring/agent/, в текущей директории
    # TrueNAS: также проверяем /mnt/pool/monitoring/ и другие ZFS dataset пути
    script_dir = pathlib.Path(__file__).parent.absolute()
    possible_paths = [
        script_dir / path,  # /opt/monitoring/agent/node.conf
        pathlib.Path("/opt/monitoring/agent") / path,
        pathlib.Path.cwd() / path,
        pathlib.Path("/opt/monitoring") / path,
        pathlib.Path(path),
        # TrueNAS Core: ZFS dataset
        pathlib.Path("/mnt/pool/monitoring/agent") / path,
        pathlib.Path("/mnt/tank/monitoring/agent") / path,
        # TrueNAS Scale: standard path (but also check /mnt)
        pathlib.Path("/mnt/data/monitoring/agent") / path,
        # FreeBSD common
        pathlib.Path("/usr/local/etc/monitoring/agent") / path,
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
        _ALLOWED_CONF_KEYS = {
            'NODE_TOKEN', 'MASTER_URL', 'NODE_NAME', 'COLLECT_INTERVAL',
            'HEALTH_PORT', 'TLS_VERIFY', 'TLS_CERT_PATH',
            'ALLOW_DANGEROUS_COMMANDS', 'SNMP_COMMUNITY', 'SMART_INTERVAL',
            'MASTER_URL_INSECURE',
        }
        _BLOCKED_CONF_KEYS = {
            'LD_PRELOAD', 'LD_LIBRARY_PATH', 'PYTHONPATH', 'PYTHONSTARTUP',
            'PATH', 'IFS', 'CDPATH', 'ENV', 'BASH_ENV',
        }
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
                if key in _BLOCKED_CONF_KEYS:
                    print(f"[agent] WARNING: ignoring dangerous key '{key}' in node.conf")
                    continue
                if key not in _ALLOWED_CONF_KEYS:
                    print(f"[agent] WARNING: ignoring unknown key '{key}' in node.conf (not in allowlist)")
                    continue
                # Перезаписываем переменные окружения из node.conf (приоритет выше дефолтных)
                os.environ[key] = val
                loaded_count += 1
                # Логируем важные переменные (без значений для безопасности)
                if key in ["NODE_TOKEN", "MASTER_URL", "NODE_NAME"]:
                    if key == "NODE_TOKEN":
                        print(f"[agent] Loaded {key}={'set (len=' + str(len(val)) + ')' if val else '(empty)'}")
                    else:
                        print(f"[agent] Loaded {key}=***")
        print(f"[agent] Loaded {loaded_count} variables from node.conf")
    except Exception as e:
        print(f"[agent] Error loading node.conf: {e}")
        import traceback
        traceback.print_exc()


def _get_verify():
    # Параметр verify для requests: путь к сертификату или булево из конфига
    tls_cert_path = os.getenv("TLS_CERT_PATH", "")
    tls_verify = os.getenv("TLS_VERIFY", "true").lower() == "true"
    if not tls_verify and not tls_cert_path:
        _log("WARNING: TLS verification is DISABLED (TLS_VERIFY=false). All connections are vulnerable to MITM attacks.")
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
    server = HTTPServer(("127.0.0.1", port), _HealthHandler)

    def _run():
        _log(f"health-check server started on 127.0.0.1:{port}")
        try:
            server.serve_forever()
        except Exception as e:
            _log(f"health-check server stopped: {e}")

    Thread(target=_run, daemon=True).start()
