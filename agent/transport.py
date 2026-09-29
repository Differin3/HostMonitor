# Отправка данных на панель, очередь команд и heartbeat
import os  # окружение
import psutil  # системные метрики
import re  # разбор SSH-логов
import requests  # HTTP-запросы
import shutil  # утилиты для проверки бинарей
import subprocess  # внешние команды
import time  # таймеры
from threading import Thread
from datetime import datetime  # время
try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry


class TransportMixin:
    def send_data(self, metrics, processes):
        # Отправка данных на главный сервер с буферизацией при ошибках
        self._cycle_counter += 1
        metrics["cycle_id"] = self._cycle_counter
        data = {"metrics": metrics, "processes": processes}
        _log(f"Sending metrics to {self.master_url}/api/metrics.php")
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/metrics.php",
            json=data,
            headers=self.headers,
            verify=_get_verify(),
            timeout=20,
        )
        if not resp or resp.status_code not in (200, 201):
            _log(f"Failed to send metrics: status={resp.status_code if resp else 'no response'}")
            # Буферизуем для повторной попытки в следующем цикле
            self._pending_metrics = metrics
            self._pending_processes = processes
            return False
        # Проверяем содержимое ответа на наличие ошибки
        try:
            resp_data = resp.json()
            if isinstance(resp_data, dict) and 'error' in resp_data:
                error_msg = resp_data.get('error', 'Unknown error')
                _log(f"Failed to send metrics: {error_msg} (status={resp.status_code})")
                if 'Unauthorized' in error_msg:
                    _log("Authorization failed. Check NODE_TOKEN in node.conf.")
                self._pending_metrics = metrics
                self._pending_processes = processes
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
        except Exception:
            pass
        _log(f"Processes sent successfully: status={proc_resp.status_code}")
        # Успешно отправили — очищаем буфер
        self._pending_metrics = None
        self._pending_processes = None
        return True

    def check_commands(self, quiet=False):
        # Проверка команд от мастера (GET /api/nodes.php?action=get-command)
        url = f"{self.master_url}/api/nodes.php"
        params = {"id": self.node_name, "action": "get-command"}
        if not quiet:
            _log(f"Checking for commands: GET {url}?id={self.node_name}&action=get-command")
        
        resp = _request_with_retry(
            "GET",
            url,
            params=params,
            headers=self.headers,
            verify=_get_verify(),
        )
        
        if not resp:
            if not quiet:
                _log("No response from server when checking commands")
            return None
            
        if not quiet:
            _log(f"Command check response: status={resp.status_code}")
        
        if resp.status_code == 200:
            try:
                data = resp.json()
                if not quiet:
                    _log(f"Command check response data: {data}")
            except Exception as e:
                _log(f"bad JSON in get-command: {e}, response text: {resp.text[:200]}")
                return None
            status = data.get('status')  # новый формат: status = ok / no-command
            command = data.get('command')
            command_status = data.get('command_status')
            
            if not quiet:
                _log(f"Command check parsed: status={status}, command={command}, command_status={command_status}")
            
            if status not in (None, 'ok', 'no-command'):
                _log(f"Unexpected status in command check: {status}")
                return None
            if (command and command_status in ('pending', 'running')):
                _log(f"Found pending command: {command}")
                return command
            elif not quiet:
                _log(f"No pending command found (command={command}, command_status={command_status})")
        else:
            _log(f"Command check failed: HTTP {resp.status_code}, response: {resp.text[:200]}")
        return None

    def _start_command_heartbeat(self):
        """Пока идёт длинная команда (apt), шлём heartbeat чтобы нода не ушла offline."""
        self._command_busy = True
        interval = max(10, int(os.getenv("HEARTBEAT_INTERVAL", "15")))

        def _loop():
            while getattr(self, '_command_busy', False):
                try:
                    self.send_heartbeat()
                except Exception as e:
                    _log(f"command-heartbeat failed: {e}")
                for _ in range(interval):
                    if not getattr(self, '_command_busy', False):
                        break
                    time.sleep(1)

        Thread(target=_loop, daemon=True, name='cmd-heartbeat').start()

    def _stop_command_heartbeat(self):
        self._command_busy = False

    def run_pending_command(self, quiet=False):
        """Забрать и выполнить одну pending-команду (если есть). True = была команда."""
        if getattr(self, '_command_busy', False):
            return False
        command = self.check_commands(quiet=quiet)
        if not command:
            return False
        _log(f"=== EXECUTING COMMAND ===")
        _log(f"Command received: {command}")
        _log(f"Node: {self.node_name}")
        self._start_command_heartbeat()
        # Причина неудачи: execute_command кладёт её в _command_error,
        # иначе панель узнаёт только факт провала.
        self._command_error = ''
        try:
            success = self.execute_command(command)
            _log(f"Command execution result: success={success}")
            self.report_command_status(
                command,
                'completed' if success else 'failed',
                '' if success else (getattr(self, '_command_error', '') or 'команда не выполнена'),
            )
            _log(f"=== COMMAND EXECUTION COMPLETE ===")
            if getattr(self, '_exit_after_command', False):
                _log('Exiting after agent update so systemd Restart=always picks up new code')
                time.sleep(1)
                os._exit(0)
        except Exception as e:
            _log(f"ERROR executing command: {e}")
            import traceback
            _log(f"Traceback: {traceback.format_exc()}")
            self.report_command_status(command, 'failed', f'{type(e).__name__}: {e}')
        finally:
            self._stop_command_heartbeat()
        return True

    def execute_command(self, command):
        # Выполнение команды (с базовой фильтрацией)
        # ОПАСНЫЕ КОМАНДЫ ОТКЛЮЧЕНЫ ПО УМОЛЧАНИЮ
        allow_dangerous = os.getenv("ALLOW_DANGEROUS_COMMANDS", "false").lower() == "true"
        # Причина последнего провала: её читает run_pending_command и
        # отправляет на панель, иначе UI показывает голое «ошибка».
        self._command_error = ''
        
        try:
            if command in ('check-agent-update', 'check-agent-updates'):
                result = self.check_agent_update()
                self.report_agent_update(result)
                _log(f"check-agent-update: {result}")
                if not result.get('ok'):
                    self._command_error = str(result.get('error') or 'проверка обновления агента не удалась')
                return bool(result.get('ok'))
            if command in ('update-agent', 'upgrade-agent'):
                if not allow_dangerous:
                    _log("BLOCKED: update-agent command is disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.")
                    self._command_error = ('Команда заблокирована политикой безопасности. '
                                           'Разрешите её переменной ALLOW_DANGEROUS_COMMANDS=true в окружении агента.')
                    return False
                result = self.update_agent()
                self.report_agent_update(result)
                _log(f"update-agent: {result}")
                if result.get('updated'):
                    self._exit_after_command = True
                if not result.get('ok'):
                    self._command_error = str(result.get('error') or 'обновление агента не удалось')
                return bool(result.get('ok'))
            if command.startswith('reboot'):
                # Перезагрузка системы - ОПАСНАЯ КОМАНДА
                if not allow_dangerous:
                    _log("BLOCKED: reboot command is disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.")
                    self._command_error = ('Команда заблокирована политикой безопасности. '
                                           'Разрешите её переменной ALLOW_DANGEROUS_COMMANDS=true в окружении агента.')
                    return False
                _log("WARNING: Executing reboot command (dangerous operation)")
                subprocess.run(['sudo', 'reboot'], check=False, timeout=10)
                return True
            elif command.startswith('shutdown'):
                # Выключение системы - ОПАСНАЯ КОМАНДА
                if not allow_dangerous:
                    _log("BLOCKED: shutdown command is disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.")
                    self._command_error = ('Команда заблокирована политикой безопасности. '
                                           'Разрешите её переменной ALLOW_DANGEROUS_COMMANDS=true в окружении агента.')
                    return False
                _log("WARNING: Executing shutdown command (dangerous operation)")
                subprocess.run(['sudo', 'shutdown', '-h', 'now'], check=False, timeout=10)
                return True
            elif command.startswith('kill'):
                # Убить процесс — ОПАСНАЯ КОМАНДА
                if not allow_dangerous:
                    _log("BLOCKED: kill command is disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.")
                    self._command_error = ('Команда заблокирована политикой безопасности. '
                                           'Разрешите её переменной ALLOW_DANGEROUS_COMMANDS=true в окружении агента.')
                    return False
                parts = command.split()
                if len(parts) > 1:
                    try:
                        pid = int(parts[1])
                    except ValueError:
                        _log(f"ERROR: invalid PID for kill: {parts[1]}")
                        return False
                    if pid <= 1:
                        _log(f"BLOCKED: refusing to kill PID {pid} (init/kernel)")
                        return False
                    try:
                        proc = psutil.Process(pid)
                        proc.kill()
                        _log(f"Killed process PID={pid} name={proc.name()}")
                        return True
                    except psutil.NoSuchProcess:
                        _log(f"ERROR: process PID={pid} not found")
                        return False
            elif command.startswith('restart'):
                # Перезапуск процесса — ОПАСНАЯ КОМАНДА
                if not allow_dangerous:
                    _log("BLOCKED: restart command is disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.")
                    self._command_error = ('Команда заблокирована политикой безопасности. '
                                           'Разрешите её переменной ALLOW_DANGEROUS_COMMANDS=true в окружении агента.')
                    return False
                parts = command.split()
                if len(parts) > 1:
                    try:
                        pid = int(parts[1])
                    except ValueError:
                        _log(f"ERROR: invalid PID for restart: {parts[1]}")
                        return False
                    if pid <= 1:
                        _log(f"BLOCKED: refusing to restart PID {pid} (init/kernel)")
                        return False
                    try:
                        proc = psutil.Process(pid)
                        proc.terminate()
                        _log(f"Terminated process PID={pid} name={proc.name()}")
                        return True
                    except psutil.NoSuchProcess:
                        _log(f"ERROR: process PID={pid} not found")
                        return False
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
                    subprocess.run(['docker', action, container_id], check=False, timeout=30)
                    return True
            elif command.startswith('check-updates'):
                # Проверка обновлений
                os_info = self.get_os_info()
                updates = self.check_updates()
                # Отправляем обновления на сервер
                self.send_updates(updates, os_info)
                return True
            elif command.startswith('install-updates') or command.startswith('install-update-batch'):
                # Пакетная установка: список пакетов с панели (pending-install)
                _log('=== INSTALL-UPDATES (batch) START ===')
                packages = []
                try:
                    resp = _request_with_retry(
                        'GET',
                        f"{self.master_url}/api/updates.php?action=pending-install",
                        headers=self.headers,
                        verify=_get_verify(),
                        timeout=20,
                    )
                    if resp and resp.status_code in (200, 201):
                        data = resp.json() if resp.text else {}
                        packages = list(data.get('packages') or [])
                except Exception as e:
                    _log(f'pending-install fetch failed: {e}')
                if not packages:
                    _log('No queued packages for install-updates')
                    return True

                # Безопасные имена пакетов
                safe = []
                for pkg in packages:
                    name = str(pkg or '').strip()
                    if re.match(r'^[a-zA-Z0-9][a-zA-Z0-9+._:-]*$', name):
                        safe.append(name)
                    else:
                        _log(f'Skip unsafe package name: {pkg}')
                        self.report_update_result(str(pkg), False, '', 'Unsafe package name')

                if not safe:
                    return False

                _log(f'Batch install {len(safe)} packages via apt-get')
                ok_all = True
                try:
                    # Один apt-get на всю пачку (быстрее, чем по одному)
                    timeout = min(3600, max(300, 60 * len(safe)))
                    result = subprocess.run(
                        ['apt-get', 'install', '-y', *safe],
                        capture_output=True,
                        text=True,
                        timeout=timeout,
                        check=False,
                    )
                    batch_ok = result.returncode == 0
                    err_tail = (result.stderr or result.stdout or '')[-800:]
                    if not batch_ok:
                        _log(f'Batch apt-get failed: {err_tail}')
                        # Fallback: по одному, чтобы частично успешные попали в историю
                        for package in safe:
                            success, installed_version, error_message = self.install_update(package)
                            ok_all = success and ok_all
                            msg = (
                                f'Package {package} updated successfully'
                                if success else
                                f'Failed to install {package}' + (f': {error_message}' if error_message else '')
                            )
                            self.report_update_result(package, success, installed_version or '', msg)
                    else:
                        for package in safe:
                            installed_version = ''
                            try:
                                version_result = subprocess.run(
                                    ['dpkg-query', '-W', '-f=${Version}', package],
                                    capture_output=True, text=True, timeout=5, check=False,
                                )
                                if version_result.returncode == 0:
                                    installed_version = (version_result.stdout or '').strip()
                            except Exception:
                                pass
                            self.report_update_result(
                                package, True, installed_version,
                                f'Package {package} updated successfully' + (f' to {installed_version}' if installed_version else ''),
                            )
                except subprocess.TimeoutExpired:
                    ok_all = False
                    for package in safe:
                        self.report_update_result(package, False, '', f'Timeout installing batch including {package}')
                except Exception as e:
                    ok_all = False
                    _log(f'Batch install exception: {e}')
                    for package in safe:
                        self.report_update_result(package, False, '', str(e)[:400])
                _log('=== INSTALL-UPDATES (batch) END ===')
                return ok_all
            elif command.startswith('install-update'):
                # Установка обновления
                _log(f"=== INSTALL-UPDATE COMMAND START ===")
                _log(f"Full command: {command}")
                parts = command.split()
                _log(f"Command parts: {parts}, count: {len(parts)}")
                
                if len(parts) >= 2:
                    package = parts[1]
                    if not re.match(r'^[a-zA-Z0-9][a-zA-Z0-9+._:-]*$', package):
                        _log(f"ERROR: invalid package name: {package}")
                        return False
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
                # Firewall команды — ОПАСНАЯ КОМАНДА
                if not allow_dangerous:
                    _log("BLOCKED: firewall command is disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.")
                    self._command_error = ('Команда заблокирована политикой безопасности. '
                                           'Разрешите её переменной ALLOW_DANGEROUS_COMMANDS=true в окружении агента.')
                    return False
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
                        _is_root = hasattr(os, 'geteuid') and os.geteuid() == 0
                        if _is_root:
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
                            result = subprocess.run(ufw_cmd, capture_output=True, text=True, check=False, timeout=30)
                            if result.returncode == 0:
                                _log(f"ufw command succeeded: {action} {port}/{proto}")
                                return True
                            else:
                                _log(f"ufw command failed: {result.stderr}")
                    
                    # Fallback на iptables (только для tcp/udp)
                    if proto in ("tcp", "udp") and shutil.which("iptables"):
                        # Определяем, нужен ли sudo
                        _is_root = hasattr(os, 'geteuid') and os.geteuid() == 0
                        sudo_prefix = [] if _is_root else ["sudo"]
                        
                        if action == "allow":
                            # Добавляем правило ACCEPT (idempotent - проверяем существование)
                            result1 = subprocess.run(
                                sudo_prefix + ["iptables", "-C", "INPUT", "-p", proto, "--dport", str(port), "-j", "ACCEPT"],
                                check=False, timeout=15,
                            )
                            if result1.returncode != 0:
                                # Правило не существует, добавляем
                                result2 = subprocess.run(
                                    sudo_prefix + ["iptables", "-A", "INPUT", "-p", proto, "--dport", str(port), "-j", "ACCEPT"],
                                    capture_output=True, text=True, check=False, timeout=15,
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
                                check=False, timeout=15,
                            )
                            result = subprocess.run(
                                sudo_prefix + ["iptables", "-A", "INPUT", "-p", proto, "--dport", str(port), "-j", "DROP"],
                                capture_output=True, text=True, check=False, timeout=15,
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

    def report_command_status(self, command, status, result=''):
        # Отчет о статусе выполнения команды
        url = f"{self.master_url}/api/nodes.php"
        params = {"id": self.node_name, "action": "command-status"}
        data = {"command": command, "status": status}
        if result:
            # Причина обязана уйти на панель: иначе в UI остаётся голое
            # «ошибка», а объяснение видно только в журнале ноды.
            data["result"] = str(result)[:4000]
        
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

    def send_heartbeat(self):
        # Отправка heartbeat для обновления статуса ноды + версии агента
        # Один быстрый запрос — без retry: следующий цикл повторит через heartbeat_interval
        try:
            info = self.agent_version_info()
            _log(f"Sending heartbeat to {self.master_url}/api/nodes.php?action=heartbeat")
            payload = {
                'timestamp': datetime.now().isoformat(),
                **info,
            }
            try:
                if hasattr(psutil, 'boot_time'):
                    boot_ts = int(psutil.boot_time())
                    payload['boot_time'] = boot_ts
                    payload['uptime_sec'] = max(0, int(time.time()) - boot_ts)
            except Exception:
                pass
            try:
                resp = requests.post(
                    f"{self.master_url}/api/nodes.php?action=heartbeat",
                    json=payload,
                    headers=self.headers,
                    verify=_get_verify(),
                    timeout=10,
                )
            except requests.exceptions.Timeout:
                _log("Heartbeat timeout (10s) — will retry next interval")
                return False
            except requests.exceptions.ConnectionError as e:
                _log(f"Heartbeat connection error: {e} — will retry next interval")
                return False
            if resp.status_code in (200, 201):
                _log(f"Heartbeat OK: status={resp.status_code} version={info.get('agent_version')} commit={info.get('agent_commit')}")
                return True
            else:
                _log(f"Heartbeat failed: status={resp.status_code}")
        except Exception as e:
            _log(f"Heartbeat exception: {e}")
        return False
