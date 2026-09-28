#!/usr/bin/env python3
"""
Поведенческий слепок агента: снимает «отпечаток» MonitoringAgent — список
методов и фактический вывод всех сборщиков без обращения к сети.

Нужен для проверки рефакторинга main.py на модули: снимок снимается ДО
изменений, затем после, и результаты сравниваются. Любая потеря метода
или изменение поведения сборщика ломает сравнение.

Запуск:
    python3 tests/agent_fingerprint.py capture <outfile.json>
    python3 tests/agent_fingerprint.py compare  tests/agent_fingerprint.golden.json
    python3 tests/agent_fingerprint.py methods <outfile.json>

Требуется: pip install psutil requests

ОГРАНИЧЕНИЕ: эталон снят на конкретной машине (дистрибутив, число CPU,
наличие docker/smartctl). На другой машине compare даст расхождения по
составу процессов, портов и платформы — это ожидаемо. Харнесс нужен как
страховка именно при рефакторинге на том же хосте, а не как переносимый
юнит-тест.
"""
from __future__ import annotations

import contextlib
import inspect
import json
import os
import re
import signal
import subprocess
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
AGENT_DIR = REPO_ROOT / "agent"
sys.path.insert(0, str(AGENT_DIR.parent))
sys.path.insert(0, str(AGENT_DIR))

# Агент при старте читает окружение; задаём заглушки, чтобы ничего не ходило наружу.
os.environ.setdefault("NODE_TOKEN", "fingerprint-token")
os.environ.setdefault("MASTER_URL", "http://127.0.0.1:1/")  # порт 1 — заведомо мёртвый
os.environ.setdefault("NODE_NAME", "fingerprint-node")
os.environ.setdefault("COLLECT_INTERVAL", "60")
os.environ.setdefault("SNMP_ENABLED", "false")
os.environ.setdefault("LLDP_PASSIVE", "false")
os.environ.setdefault("LLDP_ACTIVE_POLL_KNOWN", "false")
os.environ.setdefault("UPNP_ENABLED", "false")

# Сеть блокируем: любой реальный запрос к панели должен упасть, а не уйти в сеть.
import requests  # noqa: E402

_real_send = requests.Session.request


def _blocked(self, method, url, *a, **kw):  # noqa: ANN001
    raise requests.exceptions.ConnectionError("fingerprint: сеть заблокирована")


requests.Session.request = _blocked

import main as agent_main  # noqa: E402

Agent = agent_main.MonitoringAgent


VOLATILE_KEYS = {
    "cpu_percent", "memory_percent", "mem_percent", "cpu", "load", "load1",
    "load5", "load15", "load_avg", "rx_bytes", "tx_bytes", "rx", "tx", "bytes",
    "used", "available", "free", "percent", "usage", "uptime", "uptime_seconds",
    "uptime_sec", "total", "inodes", "disk_used", "disk_free", "speed_rx",
    "speed_tx", "memory_used", "network_in", "network_out",
    "network_in_total", "network_out_total", "power_on_hours",
}

# Методы, читающие системный журнал: содержимое зависит от того, что агент
# сам же напечатал в stdout (в контейнере journald это видит), поэтому
# сравниваем только форму, а не значения.
SHAPE_ONLY = {"_physical_net_bytes", "_disk_usage_main"}

# Методы, читающие системный журнал: сравниваем только тип результата.
JOURNAL_SHAPED = {"_journalctl_lines", "collect_system_logs", "collect_logs",
                  "collect_ssh_auth_logs"}

# Списки, состав которых зависит от текущей машины.
LEN_VOLATILE = {"collect_ports", "collect_processes", "collect_network_interfaces",
                "collect_neighbors", "collect_docker_snapshot", "collect_docker_networks"}


def type_only(value):
    return {"__type__": type(value).__name__}


def shape_of(value) -> dict:
    # Длину не сравниваем: число строк журнала «плавает», потому что агент сам
    # печатает в stdout, а journald это видит.
    if isinstance(value, (list, tuple)):
        keys = sorted({k for item in value if isinstance(item, dict) for k in item})
        return {"keys": keys,
                "types": sorted({type(v).__name__ for item in value
                                 if isinstance(item, dict) for v in item.values()})}
    return norm(value)


def norm(value, depth=0):
    """Нормализует вывод сборщика: убирает то, что меняется между запусками."""
    if depth > 6:
        return "<depth>"
    if isinstance(value, dict):
        out = {}
        for k, v in sorted(value.items(), key=lambda kv: str(kv[0])):
            key = str(k)
            # pid всегда разный — сравниваем наличие поля, а не значение.
            if key in ("pid", "ppid", "parent_pid"):
                out[key] = "<PID>" if v not in (None, 0) else 0
            # Метрики нагрузки и счётчики трафика меняются каждый запуск.
            elif key in VOLATILE_KEYS and isinstance(v, (int, float)):
                out[key] = "<VAR>"
            else:
                out[key] = norm(v, depth + 1)
        return out
    if isinstance(value, (list, tuple)):
        # Состав списков (процессы, порты) зависит от машины: сравниваем
        # форму, а порядок и количество приводим к каноническому виду.
        return {"__list__": len(value), "sample": [norm(v, depth + 1) for v in value[:3]]}
    if isinstance(value, (int, float, str, bool)) or value is None:
        return value
    return f"<{type(value).__name__}>"


def norm_sorted(value, depth=0, bucket_len=False):
    """Как norm, но для списков с плавающим порядком — сортирует образец."""
    if isinstance(value, (list, tuple)):
        items = sorted((norm(v, depth + 1) for v in value[:40]),
                       key=lambda x: json.dumps(x, sort_keys=True, ensure_ascii=False))
        # Состав процессов/портов/интерфейсов меняется между запусками
        # (сам тест тоже занимает ресурсы), поэтому длину огрубляем.
        length = (len(value) // 10) * 10 if bucket_len else len(value)
        return {"__list__": length, "sample": items[:3]}
    return norm(value, depth)


# Сборщики, безопасные для запуска без сети и без прав root.
COLLECTORS = [
    "get_os_info",
    "collect_metrics",
    "collect_processes",
    "collect_ports",
    "collect_network_interfaces",
    "collect_default_gateways",
    "collect_neighbors",
    "collect_smart",
    "collect_docker_snapshot",
    "collect_docker_networks",
    "collect_system_logs",
    "collect_ssh_auth_logs",
    "collect_logs",
    "collect_upnp",
]

# Значения для обязательных позиционных параметров — по имени параметра.
# Вызовы строятся по реальной сигнатуре, поэтому таблица не рассинхронизируется
# с кодом: неизвестное имя получает безопасный дефолт.
ARG_SAMPLES = {
    "value": "12,5%",
    "v": "1,5 ГБ",
    "n": 1024,
    "bytes": 1024,
    "text": '<img src=x onerror="alert(1)">',
    "s": "hello",
    "name": "eth0",
    "device": "/dev/sda",
    "device_name": "/dev/sda",
    "attributes": [{"id": 5, "raw": "100", "thresh": "5", "worst": "5",
                    "value": "100", "name": "Reallocated_Sector_Ct"}],
    "path": Path("/tmp/fingerprint-does-not-exist"),
    "root": Path("/tmp/fingerprint-does-not-exist"),
    "line": "2024-01-01T10:00:00+00:00 host proc[1]: hello",
    "default_pid": 0,
    "default_process": None,
    "key": "system",
    "items": [{"message": "hello", "timestamp": "2024-01-01T10:00:00"}],
    "logs": [],
    "pid": 1,
    "state_status": "running",
    "raw_status": "",
    "addr": "fe80::1%eth0",
    "a": "abc123",
    "b": "abc1234",
    "raw": "fatal: not a git repository",
    "upnp_list": [],
    "lldp_list": [],
    "host": "192.0.2.1",
    "query_args": ["-p", "info"],
    "limit": 10,
    "timeout": 5,
    "ids": [],
    "value_": "1",
    "as_int": False,
}

# Методы, которые нельзя звать с синтетическими аргументами: у них есть
# побочные эффекты (сеть отключена, но запись на диск/долгий цикл — нет).
SKIP_NOARG = {"run", "start", "stop", "close", "join"}

# Методы с побочными эффектами: для них проверяем только наличие.
EXISTENCE_ONLY = {
    "collect_containers", "collect_container_logs", "report_command_status",
    "report_update_result", "report_agent_update", "send_heartbeat", "send_data",
    "check_commands", "check_updates", "check_agent_update", "install_update",
    "run", "execute_command", "run_pending_command", "handle_upnp_command",
    "_on_lldp_device", "_on_upnp_event", "_start_gpu_collector", "_gpu_collector_loop",
    "_upnp_refresh_quiet", "_send_upnp_with_lldp", "_start_command_heartbeat",
    "_stop_command_heartbeat", "send_containers", "send_ports", "send_smart",
    "send_upnp", "send_upnp_gone", "send_network_interfaces", "send_logs",
    "send_updates", "send_neighbors", "send_default_gateways", "send_metrics",
    "send_os_info", "_handle_command", "_process_commands", "_send",
    "_post", "_request", "_put", "_delete",
    # Обновление агента: fetch + reset --hard. Никогда не вызывать.
    "update_agent", "_git_fetch_origin", "panel_git_branch", "panel_git_list",
    # Заглушка _git_cmd ниже — не метод агента, в перебор не попадает.
    "_git_cmd",
}

# ── Заглушка git ────────────────────────────────────────────────────────────
# Любой мутирующий git проходит через _git_cmd (fetch/reset/pull/checkout).
# update_agent() делает `git reset --hard origin/dev` и однажды затёр три
# коммита прямо во время прогона этого теста. Поэтому перекрываем вход
# на уровне метода: никакой список исключений не спасёт, если новый
# мутирующий метод попадёт в перебор.
GIT_CALLS_BLOCKED = []


def _deny_git_cmd(*args, **kwargs):  # noqa: ANN002, ANN003
    # self и root — единственное, что нужно для диагностики; repr объекта
    # содержит адрес в памяти и портит сравнение, поэтому не пишем его.
    GIT_CALLS_BLOCKED.append([str(args[1]) if len(args) > 1 else ""])
    raise RuntimeError("fingerprint: _git_cmd заблокирован (тест не имеет права менять git)")


Agent._git_cmd = _deny_git_cmd


def head_guard():
    """Запоминает HEAD, чтобы поймать внешний сдвиг ветки во время прогона."""
    try:
        return subprocess.run(["git", "rev-parse", "HEAD"], capture_output=True,
                              text=True, cwd=REPO_ROOT, timeout=30).stdout.strip()
    except (OSError, subprocess.SubprocessError):
        return ""


def build_args(fn) -> list:
    """Строит позиционные аргументы по реальной сигнатуре метода."""
    args = []
    for p in inspect.signature(fn).parameters.values():
        if p.kind in (p.VAR_POSITIONAL, p.VAR_KEYWORD):
            continue
        if p.default is not inspect.Parameter.empty:
            continue
        args.append(ARG_SAMPLES.get(p.name))
    return args


class _Timeout(Exception):
    pass


@contextlib.contextmanager
def time_limit(seconds: int):
    """Ограничивает вызов, чтобы зависший метод не повесил снятие слепка."""
    def handler(signum, frame):  # noqa: ANN001
        raise _Timeout
    old = signal.signal(signal.SIGALRM, handler)
    signal.alarm(seconds)
    try:
        yield
    finally:
        signal.alarm(0)
        signal.signal(signal.SIGALRM, old)


def all_members(cls):
    """Все непубличные имена класса по всей MRO.

    vars(cls) возвращает только собственные атрибуты, а после разбиения
    main.py на mixin-модули методы лежат в базах — такой перебор молча
    считал бы их потерянными.
    """
    names = set()
    for klass in cls.__mro__:
        if klass is object:
            continue
        names.update(n for n in vars(klass) if not n.startswith("__"))
    return names


def capture() -> dict:
    head_before = head_guard()
    agent = Agent(master_url="http://127.0.0.1:1", node_name="fingerprint-node",
                  node_token="fingerprint-token")
    out: dict = {"collectors": {}, "helpers": {}, "presence": [], "errors": {}}

    for name in COLLECTORS:
        fn = getattr(agent, name, None)
        if fn is None:
            out["errors"][name] = "МЕТОД ОТСУТСТВУЕТ"
            continue
        try:
            with time_limit(120):
                raw = fn()
            if name in JOURNAL_SHAPED:
                out["collectors"][name] = type_only(raw)
            elif name in SHAPE_ONLY:
                out["collectors"][name] = shape_of(raw)
            else:
                out["collectors"][name] = norm_sorted(raw, bucket_len=name in LEN_VOLATILE)
        except Exception as exc:  # noqa: BLE001 - фиксируем и сравниваем и ошибки
            out["errors"][name] = type(exc).__name__

    # Полное покрытие: каждый метод обязан существовать, иначе рефакторинг
    # потерял кусок функциональности.
    out["presence"] = sorted(all_members(Agent))

    called = set(COLLECTORS)
    for name in sorted(all_members(Agent)):
        if name.startswith("__") or name in called or name in EXISTENCE_ONLY \
                or name in SKIP_NOARG:
            continue
        fn = getattr(agent, name, None)
        if not callable(fn):
            continue
        called.add(name)
        try:
            with time_limit(30):
                raw = fn(*build_args(fn))
            if name in JOURNAL_SHAPED:
                out["helpers"][name] = type_only(raw)
            elif name in SHAPE_ONLY:
                out["helpers"][name] = shape_of(raw)
            else:
                out["helpers"][name] = norm_sorted(raw, bucket_len=name in LEN_VOLATILE)
        except _Timeout:
            out["helpers"][name] = "<TIMEOUT>"
        except Exception as exc:  # noqa: BLE001
            out["errors"][name] = f"{type(exc).__name__}: {str(exc)[:70]}"

    out["helpers"] = dict(sorted(out["helpers"].items()))
    out["errors"] = dict(sorted(out["errors"].items()))
    out["head_before"] = head_before
    out["head_after"] = head_guard()
    if GIT_CALLS_BLOCKED:
        out["git_calls_blocked"] = GIT_CALLS_BLOCKED[:5]
    return out


def _canon_sig(text: str) -> str:
    """Убирает приватные квалификаторы модулей из сигнатуры.

    repr() pathlib.Path зависит от версии интерпретатора: на 3.14 это
    "pathlib.Path", на 3.13 — "pathlib._local.Path". Эталон обязан быть
    одинаковым на любой версии, иначе структурная сверка в CI означает
    не «структура сломалась», а «у раннера другая версия Python».
    """
    return re.sub(r"\._[a-zA-Z]\w*\.", ".", text)


def methods() -> dict:
    names = sorted(n for n in all_members(Agent)
                   if callable(getattr(Agent, n, None)))
    sigs = {}
    for n in names:
        try:
            sigs[n] = _canon_sig(str(inspect.signature(getattr(Agent, n))))
        except (TypeError, ValueError):
            sigs[n] = "?"
    # Нестабильные во времени поля вырезаем.
    agent = Agent(master_url="http://127.0.0.1:1", node_name="n", node_token="t")
    attrs = sorted(k for k in vars(agent) if not k.startswith("__"))
    return {"count": len(names), "names": names, "signatures": sigs, "instance_attrs": attrs}


def scrub(text: str) -> str:
    """Выкидывает изменчивые значения (время, pid, загрузка, пути venv)."""
    text = re.sub(r"\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\"]*", "<TS>", text)
    text = re.sub(r"/root/scratch/venv[\w./-]*", "<VENV>", text)
    # Хеши коммитов меняются с каждым коммитом — иначе эталон протухает сам.
    # Lookahead требует хотя бы одну hex-букву, иначе под шаблон попадают
    # голые числа вроде boot_time=1790533958 и JSON перестаёт парситься.
    text = re.sub(r"\b(?=[0-9a-f]*[a-f])[0-9a-f]{7,40}\b", "<SHA>", text)
    # pid нормализуется в norm() по ключу — regex по тексту JSON ломал бы
    # и значения-строки, и синтаксис файла.
    return text


def main() -> int:
    mode = sys.argv[1] if len(sys.argv) > 1 else "capture"
    # Агент пишет в stdout через _log(), поэтому JSON всегда уходит в файл,
    # иначе вывод нельзя распарсить.
    if mode == "capture":
        if len(sys.argv) < 3:
            print("usage: agent_fingerprint.py capture <outfile.json>", file=sys.stderr)
            return 2
        payload = scrub(json.dumps(capture(), indent=2, sort_keys=True, ensure_ascii=False))
        Path(sys.argv[2]).write_text(payload + "\n", encoding="utf-8")
        print(f"слепок записан в {sys.argv[2]}", file=sys.stderr)
        return 0
    if mode == "methods":
        if len(sys.argv) < 3:
            print("usage: agent_fingerprint.py methods [--check] <outfile.json|golden.json>",
                  file=sys.stderr)
            return 2
        rest = sys.argv[2:]
        check = "--check" in rest
        args = [a for a in rest if a != "--check"]
        if not args:
            print("usage: agent_fingerprint.py methods [--check] <outfile.json|golden.json>",
                  file=sys.stderr)
            return 2
        target = args[0]
        if check:
            # Машинно-независимая часть: имена, сигнатуры и набор полей
            # экземпляра. Именно её имеет смысл проверять в CI, где нет ни
            # Docker, ни SMART, ни того же набора сетевых интерфейсов, что и
            # на машине, где снимался эталон.
            with open(target, encoding="utf-8") as fh:
                golden = json.load(fh)
            current = methods()
            diffs = []
            for name in sorted(set(golden.get("names", [])) - set(current["names"])):
                diffs.append(f"ПРОПАЛ метод: {name}")
            for name in sorted(set(current["names"]) - set(golden.get("names", []))):
                diffs.append(f"ПОЯВИЛСЯ метод: {name}")
            for section in ("signatures", "instance_attrs"):
                g = golden.get(section, {})
                c = current.get(section)
                if isinstance(c, dict):
                    for name in sorted(set(g) | set(c)):
                        if g.get(name) != c.get(name):
                            diffs.append(f"{section}.{name}: было {g.get(name)} стало {c.get(name)}")
                else:
                    for name in sorted(set(g) - set(c)):
                        diffs.append(f"{section}: пропало {name}")
                    for name in sorted(set(c) - set(g)):
                        diffs.append(f"{section}: появилось {name}")
            if diffs:
                print(f"РАСХОЖДЕНИЙ: {len(diffs)}")
                for d in diffs:
                    print("  " + d)
                return 1
            print(f"Совпадает: методов={current['count']} полей={len(current['instance_attrs'])}")
            return 0
        Path(target).write_text(
            json.dumps(methods(), indent=2, sort_keys=True, ensure_ascii=False) + "\n",
            encoding="utf-8")
        print(f"список методов записан в {target}", file=sys.stderr)
        return 0
    if mode == "typehints":
        # Python 3.14 вычисляет аннотации лениво (PEP 649), поэтому
        # необъявленный Optional в сигнатуре не даёт ошибки на импорте — а на
        # 3.13 и нише падает сразу, и агент не запускается вовсе. Здесь
        # аннотации разрешаются принудительно, поэтому проверка ловит
        # неразрешимое имя независимо от версии интерпретатора.
        import importlib
        import pkgutil
        import typing

        agent_dir = AGENT_DIR
        if str(agent_dir) not in sys.path:
            sys.path.insert(0, str(agent_dir))
        failures = []
        checked = 0
        for info in sorted(pkgutil.iter_modules([str(agent_dir)]), key=lambda m: m.name):
            if info.name in ("main",) or info.ispkg:
                continue
            try:
                mod = importlib.import_module(info.name)
            except Exception as exc:  # noqa: BLE001
                failures.append(f"{info.name}: не импортируется: {type(exc).__name__}: {exc}")
                continue
            for attr in dir(mod):
                obj = getattr(mod, attr)
                if not (inspect.isfunction(obj) or inspect.isclass(obj)):
                    continue
                if getattr(obj, "__module__", None) != info.name:
                    continue
                try:
                    typing.get_type_hints(obj)
                    checked += 1
                except Exception as exc:  # noqa: BLE001
                    failures.append(
                        f"{info.name}.{attr}: аннотации не разрешаются: {type(exc).__name__}: {exc}")
        if failures:
            print(f"НЕРАЗРЕШИМЫХ АННОТАЦИЙ: {len(failures)}")
            for f in failures:
                print("  " + f)
            return 1
        print(f"Аннотации разрешаются: модулей проверено, функций={checked}")
        return 0
    if mode == "compare":
        with open(sys.argv[2], encoding="utf-8") as fh:
            golden = json.load(fh)
        current = json.loads(scrub(json.dumps(capture(), indent=2, sort_keys=True, ensure_ascii=False)))
        diffs = []
        # Tripwire: тест не имеет права сдвигать ветку. Раньше update_agent()
        # делал `git reset --hard origin/dev` прямо во время прогона.
        if (current.get("head_before") and current.get("head_before")
                != current.get("head_after")):
            print("ТРЕВОГА: во время прогона сдвинулся HEAD репозитория: "
                  f"{current['head_before'][:12]} -> {current['head_after'][:12]}")
            return 2
        if current.get("git_calls_blocked"):
            # Это не авария: блокировка и есть ожидаемый результат. Значит
            # перебор дошёл до метода, который лезет в git, — просто фиксируем.
            print(f"info: git-вызовы перехвачены заглушкой ({len(current['git_calls_blocked'])}): "
                  f"{current['git_calls_blocked']}")
        # Потеря/появление метода — самое важное, проверяем явно.
        g_pres = set(golden.get("presence", []))
        c_pres = set(current.get("presence", []))
        for name in sorted(g_pres - c_pres):
            diffs.append(f"ПОТЕРЯН метод: {name}")
        for name in sorted(c_pres - g_pres):
            diffs.append(f"ПОЯВИЛСЯ новый метод: {name}")
        for section in ("collectors", "helpers", "errors"):
            g = golden.get(section, {})
            c = current.get(section, {})
            for key in sorted(set(g) | set(c)):
                if g.get(key) != c.get(key):
                    diffs.append(f"{section}.{key}:\n    было:  {json.dumps(g.get(key), ensure_ascii=False)[:300]}\n    стало: {json.dumps(c.get(key), ensure_ascii=False)[:300]}")
        if diffs:
            print(f"РАСХОЖДЕНИЙ: {len(diffs)}")
            for d in diffs:
                print("  " + d)
            return 1
        print(f"Совпадает: collectors={len(current['collectors'])} "
              f"helpers={len(current['helpers'])} presence={len(current['presence'])} "
              f"errors={len(current['errors'])}")
        return 0
    print(__doc__)
    return 2


if __name__ == "__main__":
    raise SystemExit(main())
