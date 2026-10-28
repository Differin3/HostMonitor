# Сбор логов: journald, syslog, SSH-аутентификация
import json  # JSON
import os  # окружение
import pathlib  # пути
import psutil  # системные метрики
import re  # разбор SSH-логов
import subprocess  # внешние команды
from datetime import datetime  # время
try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry


class LogsMixin:
    def collect_process_logs(self, pid=None, limit=100):
        # Сбор логов процесса: journalctl по PID/COMM/unit, fallback syslog
        logs = []
        limit = max(1, min(int(limit or 100), 5000))
        try:
            if not pid:
                return self._collect_syslog_tail(limit)

            pid = int(pid)
            comm = None
            exe = None
            try:
                proc = psutil.Process(pid)
                comm = (proc.name() or '').strip() or None
                try:
                    exe = proc.exe()
                except (psutil.Error, OSError):
                    exe = None
            except (psutil.NoSuchProcess, psutil.AccessDenied, psutil.Error):
                pass

            unit = self._systemd_unit_for_pid(pid)
            seen = set()

            queries = [[f'_PID={pid}']]
            if comm:
                safe_comm = re.sub(r'[^a-zA-Z0-9_.+-]', '', comm)[:64]
                if safe_comm:
                    queries.append([f'_COMM={safe_comm}'])
                    queries.append([f'SYSLOG_IDENTIFIER={safe_comm}'])
            if unit:
                queries.append(['-u', unit])
            if exe and os.path.isfile(exe):
                queries.append([exe])

            for q in queries:
                if len(logs) >= limit:
                    break
                batch = self._journalctl_lines(q, limit=limit, timeout=12)
                for row in batch:
                    key = (row.get('timestamp'), row.get('message'))
                    if key in seen:
                        continue
                    seen.add(key)
                    row['type'] = 'process'
                    row['pid'] = pid
                    if not row.get('process'):
                        row['process'] = comm or unit or 'process'
                    logs.append(row)

            if not logs and comm:
                for path in ('/var/log/syslog', '/var/log/messages'):
                    if not os.path.exists(path):
                        continue
                    try:
                        result = subprocess.run(
                            ['tail', '-n', str(min(limit * 5, 2000)), path],
                            capture_output=True, text=True, timeout=5,
                        )
                        if result.returncode != 0:
                            continue
                        for line in result.stdout.splitlines():
                            if comm not in line and f'[{pid}]' not in line:
                                continue
                            parsed = self._parse_syslog_line(line, default_pid=pid, default_process=comm)
                            if not parsed:
                                continue
                            key = (parsed['timestamp'], parsed['message'])
                            if key in seen:
                                continue
                            seen.add(key)
                            logs.append(parsed)
                            if len(logs) >= limit:
                                break
                    except (FileNotFoundError, subprocess.TimeoutExpired, OSError):
                        continue

            if not logs:
                now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                hint = comm or f'pid={pid}'
                logs.append({
                    'type': 'process',
                    'pid': pid,
                    'level': 'info',
                    'process': comm or 'process',
                    'message': f'Нет записей journalctl/syslog для {hint}. Процесс может не писать в journal.',
                    'timestamp': now,
                })
        except Exception as e:
            _log(f"Error collecting process logs: {e}", level="error")
        return logs[:limit]

    def _systemd_unit_for_pid(self, pid):
        try:
            path = f'/proc/{pid}/cgroup'
            if not os.path.exists(path):
                return None
            with open(path, 'r', encoding='utf-8', errors='replace') as f:
                text = f.read()
            m = re.search(r'/([^/]+\.service)(?:$|[\s/])', text)
            if m:
                return m.group(1)
        except OSError:
            pass
        return None

    def _journalctl_lines(self, query_args, limit=100, timeout=12):
        rows = []
        cmd = ['journalctl', '--no-pager', '-n', str(limit), '-o', 'short-iso'] + list(query_args)
        try:
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
            if result.returncode != 0:
                return rows
            for line in (result.stdout or '').splitlines():
                parsed = self._parse_journal_short_iso(line)
                if parsed:
                    rows.append(parsed)
        except (FileNotFoundError, subprocess.TimeoutExpired, OSError) as e:
            _log(f"journalctl failed ({query_args}): {e}", level="warning")
        return rows

    def _parse_journal_short_iso(self, line):
        line = (line or '').rstrip()
        if not line.strip():
            return None
        timestamp_str = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        process_info = 'system'
        message = line
        m = re.match(
            r'^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)?)\s+\S+\s+(\S+?)(?:\[\d+\])?:\s*(.*)$',
            line,
        )
        if m:
            try:
                ts = m.group(1).replace('Z', '+00:00')
                if re.search(r'[+-]\d{4}$', ts):
                    ts = ts[:-2] + ':' + ts[-2:]
                parsed_time = datetime.fromisoformat(ts)
                timestamp_str = parsed_time.strftime("%Y-%m-%d %H:%M:%S")
            except ValueError:
                pass
            process_info = m.group(2)
            message = m.group(3).strip() or line
        else:
            m2 = re.match(
                r'^(\w+\s+\d+\s+\d+:\d+:\d+)\s+\S+\s+(\S+?)(?:\[\d+\])?:\s*(.*)$',
                line,
            )
            if m2:
                try:
                    current_year = datetime.now().year
                    parsed_time = datetime.strptime(
                        f"{current_year} {m2.group(1)}", "%Y %b %d %H:%M:%S"
                    )
                    timestamp_str = parsed_time.strftime("%Y-%m-%d %H:%M:%S")
                except ValueError:
                    pass
                process_info = m2.group(2)
                message = m2.group(3).strip() or line

        if not message:
            return None
        level = 'info'
        low = message.lower()
        if 'error' in low or 'failed' in low or 'fatal' in low:
            level = 'error'
        elif 'warning' in low or 'warn' in low:
            level = 'warning'
        return {
            'level': level,
            'message': message,
            'process': process_info,
            'timestamp': timestamp_str,
        }

    def _parse_syslog_line(self, line, default_pid=None, default_process=None):
        parsed = self._parse_journal_short_iso(line)
        if not parsed:
            return None
        parsed['type'] = 'process'
        parsed['pid'] = default_pid
        if default_process and parsed.get('process') in (None, 'system'):
            parsed['process'] = default_process
        return parsed

    def _collect_syslog_tail(self, limit=100):
        logs = []
        for log_file in ('/var/log/syslog', '/var/log/messages'):
            try:
                if not os.path.exists(log_file):
                    continue
                result = subprocess.run(
                    ['tail', '-n', str(limit), log_file],
                    capture_output=True, text=True, timeout=5,
                )
                if result.returncode != 0:
                    continue
                for line in result.stdout.strip().split('\n'):
                    parsed = self._parse_syslog_line(line)
                    if parsed:
                        logs.append(parsed)
            except (FileNotFoundError, subprocess.TimeoutExpired, OSError):
                continue
        return logs[-limit:]

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
            _log(f"Failed to save log cursors: {e}", level="warning")

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

    @classmethod
    def _system_entry_from_journal(cls, line: str):
        """Строит запись системного лога из строки journalctl.

        Источник (process/юнит) кладётся в отдельное поле ``source``, а
        ``message`` остаётся «чистым» — без префикса ``process:``. Возвращает
        None для служебных баннеров journalctl и нераспознанных строк.
        """
        if cls._is_journal_noise(line):
            return None
        row = cls._parse_journal_iso(line)
        if not row:
            return None
        ts, iso, process, pid, message = row
        if not message:
            return None
        return {
            "type": "system",
            "level": cls._log_level(message),
            "source": process or None,
            "message": message,
            "timestamp": ts,
            "_fp": f"{iso}|{message[:160]}",
        }

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
        journal_detail = "journalctl не запускался"
        try:
            cmd = ["journalctl", "-p", "info", "--no-pager", "-n", str(limit), "--output=short-iso"]
            if since:
                cmd.extend(["--since", since])
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=8)
            if result.returncode == 0:
                lines = [
                    line for line in result.stdout.splitlines()
                    if line.strip() and not self._is_journal_noise(line)
                ]
                journal_detail = f"journalctl rc=0, пригодных строк {len(lines)}"
                last_iso = since
                for line in lines:
                    entry = self._system_entry_from_journal(line)
                    if not entry:
                        continue
                    parsed.append(entry)
                    last_iso = entry["_fp"].split("|", 1)[0]
                if last_iso:
                    self._log_cursors["system_since"] = last_iso.replace("T", " ")[:19]
            else:
                journal_detail = f"journalctl rc={result.returncode}: {(result.stderr or '').strip()[:120]}"
        except FileNotFoundError:
            journal_detail = "journalctl не найден"
        except subprocess.TimeoutExpired:
            journal_detail = "journalctl не ответил за 8 с"
        except Exception as e:
            journal_detail = f"journalctl ошибка: {e}"
            _log(f"journalctl system logs failed: {e}", level="warning")

        syslog_found = False
        if not parsed:
            for log_file in ("/var/log/syslog", "/var/log/messages"):
                if not os.path.exists(log_file):
                    continue
                syslog_found = True
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
                    if not line.strip() or self._is_journal_noise(line):
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
                        "source": process_info or None,
                        "message": message,
                        "timestamp": timestamp_str,
                        "_fp": f"{timestamp_str}|{message[:160]}",
                    })
                break

        # Ни journald, ни syslog не дали ни одной строки (обычно контейнер без /run/log/journal
        # и /var/log). Одна запись в час с причиной: пустая вкладка «Логи» ничего не объясняет.
        never_had_data = not parsed
        last_diag = getattr(self, "_system_logs_diag_at", None)
        diag_due = last_diag is None or (datetime.now() - last_diag).total_seconds() >= 3600
        if never_had_data and diag_due:
            self._system_logs_diag_at = datetime.now()
            ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            message = (
                f"Системные логи не собираются: {journal_detail}; "
                f"syslog-файлы: {'найдены, но пустые' if syslog_found else 'отсутствуют'}. "
                "Агент работает без доступа к журналу хоста (контейнер?)"
            )
            parsed.append({
                "type": "system",
                "level": "warning",
                "source": "monitoring-agent",
                "message": message,
                "timestamp": ts,
                "_fp": f"{ts}|{message[:160]}",
            })
        return self._only_new_logs("system_fp", parsed)

    @staticmethod
    def _is_journal_noise(line):
        """Отсекает служебные строки journalctl и пустые записи.

        journalctl без подходящих записей печатает баннеры вида
        ``-- Journal begins at ...`` и ``-- No entries --``; раньше они
        попадали в журнал как «события» и засоряли его.
        """
        s = (line or "").strip()
        if not s:
            return True
        if s.startswith("--"):
            return True
        return False

    @staticmethod
    def _parse_ssh_event(message):
        """Разбирает строку sshd в структурированное событие.

        Возвращает dict: username, ip, port, success (True/False/None),
        event_type, summary. event_type — машинный тип события для фильтров
        и статистики на панели (accepted/failed/invalid_user/max_attempts/
        pam_failure/disconnect/connection_closed/session/other).
        """
        msg = (message or "").strip()
        user = None
        ip = None
        port = None
        success = None

        def result(event_type, summary):
            return {
                "username": user,
                "ip": ip,
                "port": port,
                "success": success,
                "event_type": event_type,
                "summary": summary,
            }

        # Успешные авторизации — разные типы
        m = re.search(r"Accepted (?:password|publickey|keyboard-interactive) for (\S+) from ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
        if m:
            user, ip, port = m.group(1), m.group(2), int(m.group(3))
            success = True
            return result("accepted", f"SUCCESS user={user} ip={ip} port={port}")

        # Превышение числа попыток аутентификации
        m = re.search(r"maximum authentication attempts exceeded for (?:invalid user )?(\S+) from ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
        if m:
            user, ip, port = m.group(1), m.group(2), int(m.group(3))
            success = False
            return result("max_attempts", f"FAIL max attempts user={user} ip={ip} port={port}")

        # Неудачные попытки с паролем
        m = re.search(r"Failed password for (invalid user )?(\S+) from ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
        if m:
            invalid_prefix, user, ip, port = m.group(1) or "", m.group(2), m.group(3), int(m.group(4))
            success = False
            invalid = "invalid " if invalid_prefix else ""
            etype = "invalid_user" if invalid_prefix else "failed"
            return result(etype, f"FAIL user={invalid}{user} ip={ip} port={port}")

        # Неудачные попытки по публичному ключу/интерактиву
        m = re.search(r"Failed (?:publickey|keyboard-interactive) for (invalid user )?(\S+) from ([0-9\.]+)(?: port (\d+))?", msg, re.IGNORECASE)
        if m:
            invalid_prefix, user, ip, port_raw = m.group(1) or "", m.group(2), m.group(3), m.group(4)
            success = False
            port = int(port_raw) if port_raw else None
            invalid = "invalid " if invalid_prefix else ""
            etype = "invalid_user" if invalid_prefix else "failed"
            return result(etype, f"FAIL user={invalid}{user} ip={ip}")

        # Невалидный пользователь
        m = re.search(r"Invalid user (\S+) from ([0-9\.]+)(?: port (\d+))?", msg, re.IGNORECASE)
        if m:
            user, ip = m.group(1), m.group(2)
            port = int(m.group(3)) if m.group(3) else None
            success = False
            port_str = f" port={port}" if port else ""
            return result("invalid_user", f"FAIL user=invalid {user} ip={ip}{port_str}")

        # Authentication failure из pam — извлекаем IP и пользователя отдельно
        m = re.search(r"authentication failure.*rhost=([0-9\.]+)", msg, re.IGNORECASE)
        if m:
            ip = m.group(1)
            user_match = re.search(r"user=(\S+)", msg, re.IGNORECASE)
            user = user_match.group(1) if user_match else None
            success = False
            user_str = f" user={user}" if user else ""
            return result("pam_failure", f"FAIL{user_str} ip={ip}")

        # Открытие/закрытие сессии pam_unix
        m = re.search(r"session (opened|closed) for user (\S+)", msg, re.IGNORECASE)
        if m:
            action, user = m.group(1).lower(), m.group(2)
            return result("session", f"INFO session {action} user={user}")

        # Disconnected from — пользователь и IP
        m = re.search(r"Disconnected from (?:authenticating |invalid )?user (\S+) ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
        if m:
            user, ip, port = m.group(1), m.group(2), int(m.group(3))
            return result("disconnect", f"INFO disconnected user={user} ip={ip} port={port}")
        m = re.search(r"Disconnected from ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
        if m:
            ip, port = m.group(1), int(m.group(2))
            return result("disconnect", f"INFO disconnect ip={ip} port={port}")

        # Received disconnect from
        m = re.search(r"Received disconnect from ([0-9\.]+) port (\d+)", msg, re.IGNORECASE)
        if m:
            ip, port = m.group(1), int(m.group(2))
            return result("disconnect", f"INFO disconnect ip={ip} port={port}")

        # Connection closed/reset
        m = re.search(r"Connection (?:closed by|reset by|from)\s+(?:authenticating user |invalid user )?(?:(\S+)\s+)?([0-9\.]+)(?: port (\d+))?", msg, re.IGNORECASE)
        if m:
            user = m.group(1) or None
            ip = m.group(2)
            port = int(m.group(3)) if m.group(3) else None
            port_str = f" port={port}" if port else ""
            user_str = f" user={user}" if user else ""
            return result("connection_closed", f"INFO connection{user_str} ip={ip}{port_str}")

        # Fallback: пытаемся вытащить IP/порт/пользователя из любого сообщения
        ip_match = re.search(r"\b([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})\b", msg)
        if ip_match:
            ip = ip_match.group(1)
            port_match = re.search(rf"{re.escape(ip)}:(\d+)", msg)
            if port_match:
                port = int(port_match.group(1))
            user_match = re.search(r"(?:user=|for )(\S+)", msg, re.IGNORECASE)
            if user_match:
                user = user_match.group(1)

        return result("other", msg)

    def collect_ssh_auth_logs(self, limit=200):
        # Сбор логов аутентификации SSH (auth_ssh) с ограничением объёма
        def _ssh_entry(line, process_info, message, timestamp_str):
            event = self._parse_ssh_event(message)
            success = event["success"]
            if success is True:
                level = "info"
            elif success is False:
                level = "error"
            else:
                level = self._log_level(message)
            entry = {
                "type": "auth_ssh",
                "event_type": event["event_type"],
                "level": level,
                "message": event["summary"] or message,
                "process": process_info or "sshd",
                "timestamp": timestamp_str,
                "username": event["username"],
                "ip": event["ip"],
                "port": event["port"],
                "raw_message": line.strip()[:500],
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
                "journalctl", "-t", "sshd", "-t", "ssh",
                "--no-pager", "-n", str(limit), "--output=short-iso",
            ]
            if since:
                cmd.extend(["--since", since])
            result = subprocess.run(cmd, capture_output=True, text=True, timeout=8)
            if result.returncode == 0 and (result.stdout or "").strip():
                last_iso = since
                for line in result.stdout.splitlines():
                    if self._is_journal_noise(line):
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
            _log(f"journalctl ssh logs failed: {e}", level="warning")

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
                    if self._is_journal_noise(line):
                        continue
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
        logs = self._collapse_ssh_duplicates(logs)
        _log(f"collect_ssh_auth_logs: new={len(logs)}", level="debug")
        return logs

    @staticmethod
    def _ssh_collapse_key(entry: dict) -> tuple:
        return (
            entry.get('event_type') or '',
            entry.get('username') or '',
            entry.get('ip') or '',
            entry.get('success'),
        )

    @staticmethod
    def _collapse_ssh_duplicates(logs: list) -> list:
        """Схлопывает повторяющиеся SSH-события от одного IP за один цикл.
        Например, 50 brute-force попыток от 1.2.3.4 → 1 запись с message "... (×50)".
        Экономит до 80% строк в ssh_auth_logs при атаках."""
        if len(logs) <= 1:
            return logs
        buckets: dict[tuple, dict] = {}
        order: list[tuple] = []
        for entry in logs:
            if entry.get('type') != 'auth_ssh':
                continue
            key = LogsMixin._ssh_collapse_key(entry)
            if key in buckets:
                buckets[key]['count'] += 1
                if entry.get('timestamp', '') > buckets[key]['last_ts']:
                    buckets[key]['last_ts'] = entry.get('timestamp', '')
                    buckets[key]['last'] = entry
            else:
                buckets[key] = {'count': 1, 'first': entry, 'last': entry, 'last_ts': entry.get('timestamp', '')}
                order.append(key)
        result = []
        for entry in logs:
            if entry.get('type') != 'auth_ssh':
                result.append(entry)
                continue
            key = LogsMixin._ssh_collapse_key(entry)
            if key not in buckets:
                continue
            b = buckets[key]
            del buckets[key]
            if b['count'] > 1:
                e = dict(b['last'])
                orig_msg = e.get('message', '')
                e['message'] = f"{orig_msg} (×{b['count']})"
                if 'raw_message' in e:
                    e['raw_message'] = e['raw_message'][:500]
                result.append(e)
            else:
                e = dict(b['first'])
                if 'raw_message' in e:
                    e['raw_message'] = e['raw_message'][:500]
                result.append(e)
        return result

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
                        "source": "system",
                        "message": f"System boot at {boot_time.strftime('%Y-%m-%d %H:%M:%S')}",
                        "timestamp": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                    })
                    self._log_cursors["boot_sent"] = boot_ts
                    self._save_log_cursors()
            system_logs = self.collect_system_logs(limit=150)
            logs.extend(system_logs)
            ssh_logs = self.collect_ssh_auth_logs(limit=200)
            logs.extend(ssh_logs)
            _log(f"collect_logs: system={len(system_logs)}, ssh_auth={len(ssh_logs)}, total={len(logs)}", level="debug")
        except Exception as e:
            _log(f"Error collecting logs: {e}", level="error")
        return logs

    def send_logs(self, logs):
        # Отправка логов на сервер (с детальным логированием по SSH-логам)
        if not logs:
            _log("send_logs: nothing to send (0 logs)", level="debug")
            return True
        total = len(logs)
        ssh_auth_count = sum(1 for l in logs if l.get("type") == "auth_ssh")
        container_count = sum(1 for l in logs if l.get("type") == "container")
        other_count = total - ssh_auth_count - container_count
        _log(f"send_logs: total={total}, ssh_auth={ssh_auth_count}, container={container_count}, other={other_count}", level="debug")
        if ssh_auth_count > 0:
            try:
                sample = next(l for l in logs if l.get("type") == "auth_ssh")
                msg = str(sample.get("message", ""))[:200]
                proc = sample.get("process", "")
                ts = sample.get("timestamp", "")
                _log(f"send_logs: ssh_auth sample ts={ts}, process={proc}, msg={msg}", level="debug")
            except StopIteration:
                pass
            except Exception as e:
                _log(f"send_logs: error while logging ssh_auth sample: {e}", level="warning")
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/logs.php",
            json=logs,
            headers=self.headers,
            verify=_get_verify(),
            timeout=20,
        )
        ok = bool(resp and resp.status_code in (200, 201))
        _log(f"send_logs: result status={resp.status_code if resp else 'no response'}, ok={ok}", level="debug")
        if not ok:
            _log(f"send_logs: failed to send {total} logs (status={resp.status_code if resp else 'no response'})", level="error")
        if resp:
            try:
                data = resp.json()
                _log(f"send_logs: response json={data}", level="debug")
            except Exception as e:
                _log(f"send_logs: failed to parse response json: {e}, text={resp.text[:200] if hasattr(resp, 'text') else ''}", level="warning")
        return ok
