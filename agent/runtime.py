# Общие помощники агента: конфиг, логирование, HTTP с ретраями, health-сервер
import json as _json  # сериализация тела для Ed25519-подписи
import os  # окружение
import pathlib  # пути
import requests  # HTTP-запросы
import time  # таймеры
from urllib.parse import urlsplit, urlencode  # путь для подписи
from http.server import BaseHTTPRequestHandler, HTTPServer
from threading import Thread
from datetime import datetime  # время
from typing import Optional  # аннотации вычисляются на импорте (Python < 3.14)
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)


# Единая точка подписи: main.py регистрирует AgentAuth, как только он готов,
# и все исходящие запросы к панели (не только transport) уходят с X-Agent-*.
_agent_ed25519_auth = None


def set_agent_ed25519_auth(auth) -> None:
    global _agent_ed25519_auth
    _agent_ed25519_auth = auth


def _sign_request(method: str, url: str, kwargs: dict) -> None:
    # Добавляет Ed25519-подпись в исходящий запрос (мутирует kwargs).
    # Путь подписывается ровно так, как его увидит панель: REQUEST_URI
    # с query string (см. agent_auth.php / agent_request_path).
    auth = _agent_ed25519_auth
    if auth is None or not getattr(auth, "can_sign", False):
        return
    parsed = urlsplit(url)
    path = parsed.path
    if parsed.query:
        path += "?" + parsed.query
    elif kwargs.get("params"):
        query = urlencode(kwargs["params"])
        if query:
            path += "?" + query
    body = b""
    if "data" in kwargs:
        raw = kwargs["data"]
        body = raw if isinstance(raw, bytes) else str(raw).encode("utf-8", errors="replace")
    elif "json" in kwargs:
        # Сериализуем один раз и отправляем как data=: панель подписывает
        # тело php://input ровно в тех байтах, что ушли на провод.
        # allow_nan=False — как в requests: NaN/Infinity не должны молча
        # уходить, иначе панель не сможет прочитать JSON.
        body = _json.dumps(kwargs["json"], allow_nan=False).encode("utf-8", errors="replace")
        kwargs["data"] = body
        kwargs.pop("json")
    headers = dict(kwargs.get("headers") or {})
    sig = auth.sign_request(method, path, body)
    headers.update(sig)
    headers.setdefault("Content-Type", "application/json")
    kwargs["headers"] = headers


def _request_once(method: str, url: str, **kwargs) -> Optional[requests.Response]:
    # Один запрос с подписью: без ретраев (heartbeat, короткие вспомогательные).
    _sign_request(method, url, kwargs)
    timeout = kwargs.pop("timeout", 10)
    return requests.request(method, url, timeout=timeout, **kwargs)


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
        _log("Error: node.conf not found. Searched in:", level="warning")
        for p in possible_paths:
            exists = "✓" if p.exists() else "✗"
            _log(f"  {exists} {p}", level="debug")
        _log("Please create node.conf with NODE_TOKEN, MASTER_URL, NODE_NAME", level="warning")
        return
    
    _log(f"Loading node.conf from: {cfg_path}", level="debug")
    try:
        loaded_count = 0
        _ALLOWED_CONF_KEYS = {
            'NODE_TOKEN', 'MASTER_URL', 'NODE_NAME', 'COLLECT_INTERVAL',
            'HEALTH_PORT', 'HEARTBEAT_INTERVAL', 'TLS_VERIFY', 'TLS_CERT_PATH',
              'ALLOW_DANGEROUS_COMMANDS', 'ALLOW_AGENT_UPDATES',
              'SNMP_COMMUNITY', 'SMART_INTERVAL',
            'MASTER_URL_INSECURE',
            'LOG_LEVEL',
            # retry/backoff
            'MAX_RETRIES', 'RETRY_DELAY',
            # UPnP / LLDP / SNMP (читаются в topology/network/snmp)
            'UPNP_ENABLED', 'UPNP_MX', 'UPNP_TIMEOUT', 'UPNP_GENA_PORT',
            'UPNP_INTERVAL_CYCLES',
            'LLDP_PASSIVE', 'LLDP_LISTEN_INTERFACE', 'LLDP_ACTIVE_POLL_KNOWN',
            'SNMP_ENABLED', 'SNMP_TARGETS', 'SNMP_TIMEOUT',
            # Ed25519-enroll: ключи, которые пишет agent/enroll.py
            'NODE_ID', 'NODE_SECRET_B64', 'NODE_PUBLIC_KEY_B64',
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
                    _log(f"WARNING: ignoring dangerous key '{key}' in node.conf", level="warning")
                    continue
                if key not in _ALLOWED_CONF_KEYS:
                    _log(f"WARNING: ignoring unknown key '{key}' in node.conf (not in allowlist)", level="warning")
                    continue
                # Перезаписываем переменные окружения из node.conf (приоритет выше дефолтных)
                os.environ[key] = val
                loaded_count += 1
                # Логируем важные переменные (без значений для безопасности)
                if key in ["NODE_TOKEN", "MASTER_URL", "NODE_NAME"]:
                    if key == "NODE_TOKEN":
                        _log(f"Loaded {key}={'set (len=' + str(len(val)) + ')' if val else '(empty)'}", level="debug")
                    else:
                        _log(f"Loaded {key}=***", level="debug")
        _log(f"Loaded {loaded_count} variables from node.conf", level="debug")
    except Exception as e:
        _log(f"Error loading node.conf: {e}", level="error")
        import traceback
        traceback.print_exc()


def _get_verify():
    # Параметр verify для requests: путь к сертификату или булево из конфига
    global _tls_warning_logged
    tls_cert_path = os.getenv("TLS_CERT_PATH", "")
    tls_verify = os.getenv("TLS_VERIFY", "true").lower() == "true"
    # Предупреждение печатаем один раз за процесс, а не на каждом запросе
    # (_get_verify вызывается ~17 раз за цикл).
    if not tls_verify and not tls_cert_path and not _tls_warning_logged:
        _tls_warning_logged = True
        _log(
            "TLS verification is DISABLED (TLS_VERIFY=false). "
            "All connections are vulnerable to MITM attacks.",
            level="warning",
        )
    return tls_cert_path or tls_verify


# Уровни логирования: debug < info < warning < error. LOG_LEVEL из node.conf.
# По умолчанию info — подробные шаги циклов (debug) в проде не пишутся.
_LOG_LEVELS = {"debug": 10, "info": 20, "warning": 30, "warn": 30, "error": 40}
_tls_warning_logged = False


def _log(msg: str, level: str = "info") -> None:
    # Структурная строка: [agent] <ISO-время> <LEVEL> <сообщение>.
    # flush=True — иначе journald получает логи пачками с задержкой.
    lvl = str(level).strip().lower()
    if _LOG_LEVELS.get(lvl, 20) < _LOG_LEVELS.get(os.getenv("LOG_LEVEL", "info").strip().lower(), 20):
        return
    print(f"[agent] {datetime.now().isoformat()} {lvl.upper():<7} {msg}", flush=True)


def _request_with_retry(method: str, url: str, **kwargs) -> Optional[requests.Response]:
    # HTTP-запрос с повторами и задержкой
    max_retries = int(os.getenv("MAX_RETRIES", "3"))
    retry_delay = int(os.getenv("RETRY_DELAY", "5"))
    for attempt in range(1, max_retries + 1):
        try:
            # Подпись пересчитывается на каждый attempt: timestamp не протухнет
            # во время паузы между ретраями (панель режет >60 c).
            resp = _request_once(method, url, **kwargs)
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
