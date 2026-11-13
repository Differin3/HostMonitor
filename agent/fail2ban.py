# fail2ban.py - сбор состояния fail2ban на ноде
#
# Собираем не логи, а сам демон: версию, список джейлов, счётчики фильтра,
# текущие параметры и активные баны. Панель строит историю диффом снимков,
# потому что sqlite fail2ban хранит баны только сутки (dbpurgeage=1d).
from __future__ import annotations

import ipaddress
import os
import re
import shutil
import subprocess
import time
from typing import Any, Dict, List, Optional

try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry


# Значение параметра, когда fail2ban вернул не число, а "1h"/"10m".
_DURATION_UNITS = {'s': 1, 'm': 60, 'h': 3600, 'd': 86400, 'w': 604800}

# Параметры, которые панели разрешено менять, и границы значений.
# bantime=-1 — «навсегда», это штатное значение fail2ban.
FAIL2BAN_PARAM_LIMITS = {
    'maxretry': (1, 100),
    'findtime': (60, 86400),
    'bantime': (-1, 31536000),
}
FAIL2BAN_JAIL_RE = re.compile(r'^[A-Za-z0-9_.@-]{1,64}$')
FAIL2BAN_MANAGED_PATH = '/etc/fail2ban/jail.d/90-hostmonitor.local'


def _parse_duration(raw: str) -> Optional[int]:
    text = (raw or '').strip()
    if not text:
        return None
    m = re.search(r'-?\d+', text)
    if not m:
        return None
    number = int(m.group(0))
    unit = text[m.end():m.end() + 1].lower()
    if unit in _DURATION_UNITS:
        return number * _DURATION_UNITS[unit]
    return number


def fail2ban_client_path() -> Optional[str]:
    path = shutil.which('fail2ban-client')
    if path:
        return path
    for candidate in ('/usr/bin/fail2ban-client', '/usr/local/bin/fail2ban-client'):
        if os.path.isfile(candidate) and os.access(candidate, os.X_OK):
            return candidate
    return None


def parse_version(output: str) -> Optional[str]:
    m = re.search(r'Fail2Ban\s+v?([0-9][0-9A-Za-z.\-]*)', output or '')
    return m.group(1) if m else None


def parse_jail_list(output: str) -> List[str]:
    jails: List[str] = []
    for line in (output or '').splitlines():
        if 'Jail list:' not in line:
            continue
        raw = line.split('Jail list:', 1)[1]
        for name in re.split(r'[,\s]+', raw):
            name = name.strip()
            if name and re.match(r'^[A-Za-z0-9_.@-]{1,64}$', name):
                jails.append(name)
    return jails


def _field(line: str, name: str) -> Optional[str]:
    # Строки дерева выглядят как "|  |- Currently failed:\t3" или
    # "   `- File list:\t/var/log/auth.log" — ключ ищем по подстроке.
    if name not in line:
        return None
    return line.split(name, 1)[1].lstrip(':').strip()


def parse_jail_status(output: str) -> Dict[str, Any]:
    """Счётчики и источники логов из `fail2ban-client status <jail>`.

    0.11.x пишет "File list:", 1.1.x с systemd-backend — "Journal matches:".
    """
    result: Dict[str, Any] = {
        'currently_failed': 0,
        'total_failed': 0,
        'currently_banned': 0,
        'total_banned': 0,
        'logpath': None,
        'backend': None,
        'ips': [],
    }
    for line in (output or '').splitlines():
        for key, field in (
            ('Currently failed', 'currently_failed'),
            ('Total failed', 'total_failed'),
            ('Currently banned', 'currently_banned'),
            ('Total banned', 'total_banned'),
        ):
            value = _field(line, key)
            if value is not None and value.isdigit():
                result[field] = int(value)
        if 'Banned IP list' in line:
            value = _field(line, 'Banned IP list') or ''
            result['ips'] = [ip for ip in value.split() if ip]
        if 'Journal matches' in line:
            result['backend'] = 'systemd'
            if result['logpath'] is None:
                result['logpath'] = _field(line, 'Journal matches')
        elif 'File list' in line:
            result['backend'] = result['backend'] or 'file'
            if result['logpath'] is None:
                result['logpath'] = _field(line, 'File list')
    return result


class Fail2banMixin:
    def _f2b_run(self, client: str, args: List[str], timeout: int = 6):
        try:
            return subprocess.run(
                [client] + args,
                capture_output=True,
                text=True,
                timeout=timeout,
                env=os.environ.copy(),
            )
        except subprocess.TimeoutExpired:
            _log(f"fail2ban-client {' '.join(args)}: timeout {timeout}s", level='warning')
            return None
        except Exception as e:
            _log(f"fail2ban-client {' '.join(args)} failed: {e}", level='warning')
            return None

    def _f2b_get_int(self, client: str, jail: str, param: str, timeout: int) -> Optional[int]:
        res = self._f2b_run(client, ['get', jail, param], timeout=timeout)
        if not res or res.returncode != 0:
            return None
        return _parse_duration((res.stdout or '').strip())

    def collect_fail2ban(self) -> Dict[str, Any]:
        """Снимок состояния fail2ban. Отсутствие демона — не ошибка."""
        client = fail2ban_client_path()
        payload: Dict[str, Any] = {
            'installed': client is not None,
            'version': None,
            'active': False,
            'error': None,
            'collected_at': int(time.time()),
            'jails': [],
            'bans': [],
        }
        if not client:
            return payload

        timeout = max(2, min(30, int(os.getenv('FAIL2BAN_TIMEOUT', '6'))))
        max_bans = max(1, int(os.getenv('FAIL2BAN_MAX_BANS', '2000')))

        ver = self._f2b_run(client, ['--version'], timeout=timeout)
        if ver and ver.returncode == 0:
            payload['version'] = parse_version((ver.stdout or '') + (ver.stderr or ''))

        status = self._f2b_run(client, ['status'], timeout=timeout)
        if not status or status.returncode != 0:
            err = ((status.stderr or status.stdout) if status else '').strip()
            payload['error'] = (err or 'fail2ban-client status failed')[:250]
            _log(f"fail2ban not active: {payload['error']}", level='warning')
            return payload

        payload['active'] = True
        jails = parse_jail_list(status.stdout or '')
        ban_count = 0
        jail_rows: List[Dict[str, Any]] = []

        for jail in jails:
            res = self._f2b_run(client, ['status', jail], timeout=timeout)
            if not res or res.returncode != 0:
                _log(f"fail2ban status {jail} failed", level='warning')
                continue
            row: Dict[str, Any] = {'jail': jail}
            row.update(parse_jail_status(res.stdout or ''))
            ips = row.pop('ips', [])
            row['maxretry'] = self._f2b_get_int(client, jail, 'maxretry', timeout)
            row['findtime'] = self._f2b_get_int(client, jail, 'findtime', timeout)
            row['bantime'] = self._f2b_get_int(client, jail, 'bantime', timeout)
            jail_rows.append(row)
            for ip in ips:
                if ban_count >= max_bans:
                    _log(f"fail2ban: ban list truncated at {max_bans}", level='warning')
                    break
                payload['bans'].append({'jail': jail, 'ip': ip})
                ban_count += 1

        payload['jails'] = jail_rows
        _log(
            f"fail2ban: {len(jail_rows)} jail(s), {len(payload['bans'])} active ban(s), "
            f"version={payload['version']}",
            level='debug',
        )
        return payload

    def send_fail2ban(self, payload: Dict[str, Any]) -> bool:
        resp = _request_with_retry(
            'POST',
            f"{self.master_url}/api/fail2ban.php",
            json=payload,
            headers=getattr(self, 'signed_headers', self.headers),
            verify=_get_verify(),
            timeout=20,
        )
        ok = bool(resp and resp.status_code in (200, 201))
        if not ok:
            _log(
                f"send_fail2ban failed: status={resp.status_code if resp else 'no response'}",
                level='error',
            )
        return ok

    # ------------------------------------------------------------------
    # Фаза 2: write-операции по командам панели.
    # ------------------------------------------------------------------

    def _f2b_fail(self, message: str) -> bool:
        self._command_error = message
        _log(f"fail2ban command failed: {message}", level='error')
        return False

    def _f2b_managed_read(self, path: str):
        """Прочитать наш jail.d/*.local: секции и порядок их объявления."""
        sections: Dict[str, Dict[str, str]] = {}
        order: List[str] = []
        current: Optional[str] = None
        try:
            with open(path, 'r', encoding='utf-8') as fh:
                for line in fh:
                    text = line.strip()
                    if text.startswith('[') and text.endswith(']'):
                        current = text[1:-1].strip()
                        if current and current not in sections:
                            sections[current] = {}
                            order.append(current)
                    elif current and '=' in text and not text.startswith(('#', ';')):
                        key, value = text.split('=', 1)
                        key = key.strip()
                        if key:
                            sections[current][key] = value.strip()
        except FileNotFoundError:
            pass
        return sections, order

    def _f2b_managed_write(self, path: str, sections: Dict[str, Dict[str, str]], order: List[str]) -> None:
        lines = [
            '# Managed by HostMonitor. Файл перезаписывается панелью,',
            '# ручные правки будут потеряны при следующем изменении.',
            '',
        ]
        for name in order:
            lines.append(f'[{name}]')
            for key, value in sections[name].items():
                lines.append(f'{key} = {value}')
            lines.append('')
        directory = os.path.dirname(path)
        if directory:
            os.makedirs(directory, exist_ok=True)
        tmp = f'{path}.tmp'
        with open(tmp, 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(lines))
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)

    def _f2b_rollback_runtime(self, client: str, jail: str, values: Dict[str, int]) -> None:
        for key, value in values.items():
            self._f2b_run(client, ['set', jail, key, str(value)], timeout=10)

    def execute_fail2ban_command(self, command: str) -> bool:
        """fail2ban-unban <jail> <ip> | fail2ban-set <jail> k=v [k=v ...]."""
        client = fail2ban_client_path()
        if not client:
            return self._f2b_fail('fail2ban-client не найден на ноде')

        parts = (command or '').split()
        if len(parts) < 2 or not FAIL2BAN_JAIL_RE.match(parts[1]):
            return self._f2b_fail(f'Некорректная команда fail2ban: {command!r}')
        action, jail = parts[0], parts[1]

        if action == 'fail2ban-unban':
            if len(parts) != 3:
                return self._f2b_fail('fail2ban-unban: нужен ровно один IP')
            try:
                ip = str(ipaddress.ip_address(parts[2]))
            except ValueError:
                return self._f2b_fail(f'Некорректный IP: {parts[2]}')
            res = self._f2b_run(client, ['set', jail, 'unbanip', ip], timeout=15)
            output = ((res.stdout or '') + (res.stderr or '')).strip() if res else 'таймаут'
            if res is None or res.returncode != 0:
                return self._f2b_fail(f'unbanip {ip} в {jail}: {output or "ошибка"}')
            _log(f'fail2ban: unban {ip} in {jail}: {output}')
            return True

        if action != 'fail2ban-set':
            return self._f2b_fail(f'Неизвестная команда fail2ban: {action}')

        params: Dict[str, int] = {}
        for token in parts[2:]:
            if '=' not in token:
                return self._f2b_fail(f'Ожидалось key=value, получено: {token}')
            key, raw = token.split('=', 1)
            key = key.strip().lower()
            if key not in FAIL2BAN_PARAM_LIMITS:
                return self._f2b_fail(f'Параметр {key} не поддерживается')
            try:
                value = int(raw)
            except ValueError:
                return self._f2b_fail(f'{key}: ожидается целое число')
            low, high = FAIL2BAN_PARAM_LIMITS[key]
            if value < low or value > high:
                return self._f2b_fail(f'{key}: допустимо {low}..{high}, получено {value}')
            params[key] = value
        if not params:
            return self._f2b_fail('fail2ban-set: не переданы параметры')

        path = os.getenv('FAIL2BAN_JAIL_LOCAL', FAIL2BAN_MANAGED_PATH)
        old_runtime: Dict[str, int] = {}
        for key in params:
            current = self._f2b_get_int(client, jail, key, 10)
            if current is not None:
                old_runtime[key] = current

        # 1. Рантайм: применяется сразу, без рестарта демона.
        for key, value in params.items():
            res = self._f2b_run(client, ['set', jail, key, str(value)], timeout=15)
            output = ((res.stdout or '') + (res.stderr or '')).strip() if res else 'таймаут'
            if res is None or res.returncode != 0:
                self._f2b_rollback_runtime(client, jail, old_runtime)
                return self._f2b_fail(f'{jail} {key}={value}: {output or "ошибка"}')

        # 2. Персистентность: jail.d/*.local перекрывает jail.local и jail.d/*.conf.
        sections, order = self._f2b_managed_read(path)
        if jail not in sections:
            sections[jail] = {}
            order.append(jail)
        sections[jail].update({key: str(value) for key, value in params.items()})

        backup = f'{path}.bak' if os.path.isfile(path) else None
        if backup:
            shutil.copy2(path, backup)
        try:
            self._f2b_managed_write(path, sections, order)
        except Exception as e:
            self._f2b_rollback_runtime(client, jail, old_runtime)
            return self._f2b_fail(f'не удалось записать {path}: {e}')

        # 3. Проверка конфига: битый файл не должен пережить рестарт демона.
        test = self._f2b_run(client, ['-t'], timeout=20)
        test_out = ((test.stdout or '') + (test.stderr or '')).strip() if test else 'таймаут'
        if test is None or test.returncode != 0:
            if backup:
                shutil.copy2(backup, path)
            else:
                try:
                    os.remove(path)
                except FileNotFoundError:
                    pass
            self._f2b_rollback_runtime(client, jail, old_runtime)
            return self._f2b_fail(f'fail2ban-client -t отклонил конфиг: {test_out}')

        reload_res = self._f2b_run(client, ['reload', jail], timeout=30)
        if reload_res is None or reload_res.returncode != 0:
            reload_out = ((reload_res.stdout or '') + (reload_res.stderr or '')).strip() if reload_res else 'таймаут'
            # Конфиг валиден и рантайм уже применён — это предупреждение, не откат.
            _log(f'fail2ban reload {jail} failed after successful set: {reload_out}', level='warning')

        summary = ', '.join(f'{key}={value}' for key, value in params.items())
        _log(f'fail2ban: {jail} updated ({summary}) via {path}')
        return True
