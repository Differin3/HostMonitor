# Самообновление агента и работа с git
import os  # окружение
import pathlib  # пути
import subprocess  # внешние команды
import time  # таймеры
try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry


class UpdaterMixin:
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

    def install_root(self) -> pathlib.Path:
        # /opt/monitoring/agent/main.py → /opt/monitoring
        return pathlib.Path(__file__).resolve().parent.parent

    @staticmethod
    def _commit_same(a: str, b: str) -> bool:
        a = (a or '').strip().lower()
        b = (b or '').strip().lower()
        if not a or not b:
            return False
        return a.startswith(b) or b.startswith(a)

    def _ensure_git_runtime_env(self) -> None:
        """Чтобы любой git (даже без -c) видел safe.directory=*."""
        os.environ.setdefault('GIT_CONFIG_COUNT', '1')
        os.environ.setdefault('GIT_CONFIG_KEY_0', 'safe.directory')
        os.environ.setdefault('GIT_CONFIG_VALUE_0', '*')
        os.environ.setdefault('GIT_TERMINAL_PROMPT', '0')

    def _git_cmd(self, root: pathlib.Path, *args: str, timeout: int = 120) -> subprocess.CompletedProcess:
        self._ensure_git_runtime_env()
        cmd = [
            'git',
            '-c', 'safe.directory=*',
            '-c', f'safe.directory={root}',
            '-C', str(root),
            *args,
        ]
        return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)

    def _ensure_git_safe_directory(self, root: pathlib.Path) -> None:
        """Пишем safe.directory в .git/config (без --system — часто Permission denied)."""
        self._ensure_git_runtime_env()
        root_s = str(root)
        git_dir = root / '.git'
        if not git_dir.exists():
            return
        try:
            self._git_cmd(root, 'config', '--local', '--unset-all', 'safe.directory', timeout=10)
        except Exception:
            pass
        try:
            self._git_cmd(root, 'config', '--local', '--add', 'safe.directory', '*', timeout=10)
            self._git_cmd(root, 'config', '--local', '--add', 'safe.directory', root_s, timeout=10)
        except Exception:
            pass

    @staticmethod
    def _git_error_human(raw: str, root: str) -> str:
        t = (raw or '').lower()
        if 'dubious ownership' in t or 'safe.directory' in t:
            return (
                f'Git блокирует каталог (safe.directory). На ноде один раз: '
                f"sudo git -c 'safe.directory=*' -C {root} fetch origin && "
                f"sudo git -c 'safe.directory=*' -C {root} reset --hard origin/main && "
                f'sudo systemctl restart monitoring-agent'
            )
        if 'permission denied' in t or 'cannot open' in t or 'index.lock' in t or 'unable to create' in t:
            return (
                f'Нет прав на запись в {root}/.git. Обновите от root: '
                f"sudo git -c 'safe.directory=*' -C {root} fetch origin && "
                f"sudo git -c 'safe.directory=*' -C {root} reset --hard origin/main && "
                f'sudo systemctl restart monitoring-agent'
            )
        if 'could not resolve' in t or 'unable to access' in t or 'failed to connect' in t or 'timed out' in t:
            return 'Нет доступа к origin (сеть/DNS/GitHub). Проверьте исходящий HTTPS с ноды.'
        if 'not a git' in t:
            return f'Установка не git-репозиторий: {root}'
        text = (raw or 'git error').strip()
        return text[:700]

    def _git_fetch_origin(self, root: pathlib.Path) -> subprocess.CompletedProcess:
        last = None
        for attempt in range(3):
            last = self._git_cmd(root, 'fetch', 'origin', '--prune', timeout=120)
            if last.returncode == 0:
                return last
            err = (last.stderr or last.stdout or '').lower()
            if 'permission' in err or 'insufficient' in err:
                if self._try_fix_git_permissions(root):
                    continue
                break
            if 'dubious' in err:
                break
            time.sleep(1.5 * (attempt + 1))
        return last

    def _try_fix_git_permissions(self, root: pathlib.Path) -> bool:
        """Попытка починить права на .git через sudo chown (без пароля)."""
        import getpass
        user = getpass.getuser()
        git_dir = str(root / '.git')
        try:
            proc = subprocess.run(
                ['sudo', '-n', 'chown', '-R', f'{user}:{user}', git_dir],
                capture_output=True, text=True, timeout=30,
            )
            if proc.returncode == 0:
                return True
        except Exception:
            pass
        return False

    def agent_version_info(self) -> dict:
        root = self.install_root()
        version = '0.0.0'
        ver_file = pathlib.Path(__file__).resolve().parent / 'VERSION'
        try:
            if ver_file.is_file():
                version = ver_file.read_text(encoding='utf-8').strip().splitlines()[0].strip() or version
        except Exception:
            pass
        commit = ''
        branch = ''
        dirty = False
        try:
            if (root / '.git').exists():
                probe = self._git_cmd(root, 'rev-parse', '--short', 'HEAD', timeout=10)
                if probe.returncode == 0:
                    commit = (probe.stdout or '').strip()
                br = self._git_cmd(root, 'rev-parse', '--abbrev-ref', 'HEAD', timeout=10)
                if br.returncode == 0:
                    branch = (br.stdout or '').strip()
                dirty = self._git_cmd(root, 'diff', '--quiet', timeout=10).returncode != 0
        except Exception as e:
            _log(f"agent version git probe failed: {e}")
        return {
            'agent_version': version,
            'agent_commit': commit,
            'agent_branch': branch or 'main',
            'agent_dirty': dirty,
            'install_root': str(root),
        }

    def check_agent_update(self) -> dict:
        root = self.install_root()
        self._ensure_git_safe_directory(root)
        info = self.agent_version_info()
        if not (root / '.git').is_dir():
            return {
                **info,
                'ok': False,
                'update_available': False,
                'error': f'Установка не через git ({root})',
            }
        try:
            fetch = self._git_fetch_origin(root)
            if fetch.returncode != 0:
                err = (fetch.stderr or fetch.stdout or 'git fetch failed').strip()
                return {
                    **info,
                    'ok': False,
                    'update_available': False,
                    'error': self._git_error_human(err, str(root)),
                }
            branch = info.get('agent_branch') or 'main'
            if branch in ('HEAD', '', None):
                branch = 'main'
            remote_ref = f'origin/{branch}'
            remote_probe = self._git_cmd(root, 'rev-parse', '--short', remote_ref, timeout=10)
            if remote_probe.returncode != 0:
                remote_probe = self._git_cmd(root, 'rev-parse', '--short', 'origin/main', timeout=10)
                remote_ref = 'origin/main'
            if remote_probe.returncode != 0:
                err = (remote_probe.stderr or 'origin/main not found').strip()
                return {
                    **info,
                    'ok': False,
                    'update_available': False,
                    'error': self._git_error_human(err, str(root)),
                }
            remote = (remote_probe.stdout or '').strip()
            local = info.get('agent_commit') or ''
            available = bool(remote and local and not self._commit_same(remote, local))
            return {
                **info,
                'ok': True,
                'update_available': available,
                'agent_remote_commit': remote,
                'remote_ref': remote_ref,
                'error': None,
            }
        except Exception as e:
            return {**info, 'ok': False, 'update_available': False, 'error': self._git_error_human(str(e), str(root))}

    def update_agent(self) -> dict:
        # Команда с панели: fetch + reset --hard к origin (node.conf в .gitignore).
        # Не блокируем из‑за локальных правок tracked-файлов — иначе TrueNAS/ручные правки вечно «ошибка».
        checked = self.check_agent_update()
        if not checked.get('ok'):
            return checked
        root = self.install_root()
        remote_ref = checked.get('remote_ref') or 'origin/main'
        before = checked.get('agent_commit') or ''
        if not checked.get('update_available'):
            return {
                **checked,
                'ok': True,
                'updated': False,
                'message': f'Уже на {remote_ref} ({before or "unknown"})',
            }
        try:
            was_dirty = bool(checked.get('agent_dirty'))
            # Коммит до обновления: если ниже что-то сломается, возвращаемся
            # на него. Раньше отката не было вовсе: после reset --hard старый
            # код уже недоступен, и битое обновление уводило юнит в
            # crash-loop при StartLimitBurst=5.
            rev = self._git_cmd(root, 'rev-parse', 'HEAD', timeout=10)
            previous_commit = (rev.stdout or '').strip() if rev.returncode == 0 else ''
            reset = self._git_cmd(root, 'reset', '--hard', remote_ref, timeout=180)
            if reset.returncode != 0:
                # fallback: pull --ff-only
                branch = remote_ref.split('/', 1)[-1]
                pull = self._git_cmd(root, 'pull', '--ff-only', 'origin', branch, timeout=180)
                if pull.returncode != 0:
                    err = (reset.stderr or pull.stderr or 'git reset/pull failed').strip()
                    return {
                        **checked,
                        'ok': False,
                        'updated': False,
                        'error': self._git_error_human(err, str(root)),
                    }
            req = root / 'agent' / 'requirements.txt'
            pip = root / '.venv' / 'bin' / 'pip'
            if not pip.is_file():
                pip = root / '.venv' / 'bin' / 'pip3'
            if pip.is_file() and req.is_file():
                # returncode проверялся: раньше он игнорировался, и упавший pip
                # молча проглатывался, а агент всё равно перезапускался.
                try:
                    pip_run = subprocess.run(
                        [str(pip), 'install', '-q', '-r', str(req)],
                        capture_output=True, text=True, timeout=300,
                    )
                except Exception as exc:
                    pip_run = None
                    pip_err = f'{type(exc).__name__}: {exc}'
                else:
                    pip_err = (pip_run.stderr or pip_run.stdout or '').strip()
                if pip_run is None or pip_run.returncode != 0:
                    detail = (pip_err or 'без вывода')[-1500:]
                    if previous_commit:
                        back = self._git_cmd(root, 'reset', '--hard', previous_commit, timeout=120)
                        _log(
                            f'rollback after failed pip: rc={getattr(pip_run, "returncode", "exc")} '
                            f'prev={previous_commit[:8]} back_rc={back.returncode}'
                        )
                        return {
                            **checked,
                            'ok': False,
                            'updated': False,
                            'rolled_back': back.returncode == 0,
                            'error': 'Не удалось обновить зависимости, выполнен откат: ' + detail,
                        }
                    return {
                        **checked,
                        'ok': False,
                        'updated': False,
                        'rolled_back': False,
                        'error': 'Не удалось обновить зависимости: ' + detail,
                    }
            after = self.agent_version_info()
            self._exit_after_command = True
            msg = f"Обновлено {before} → {after.get('agent_commit')}"
            if was_dirty:
                msg += ' (локальные правки tracked сброшены)'
            return {
                **after,
                'ok': True,
                'updated': True,
                'update_available': False,
                'agent_remote_commit': after.get('agent_commit'),
                'message': msg,
                'error': None,
            }
        except Exception as e:
            return {
                **checked,
                'ok': False,
                'updated': False,
                'error': self._git_error_human(str(e), str(root)),
            }

    def report_agent_update(self, payload: dict) -> None:
        try:
            _request_with_retry(
                'POST',
                f'{self.master_url}/api/agent_update.php',
                params={'action': 'report'},
                json=payload,
                headers=self.headers,
                verify=_get_verify(),
                timeout=15,
            )
        except Exception as e:
            _log(f'agent_update report failed: {e}')
