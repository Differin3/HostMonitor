# Сбор системных метрик: CPU, память, диск, GPU, процессы, ОС
import os  # окружение
import psutil  # системные метрики
import re  # разбор SSH-логов
import shutil  # утилиты для проверки бинарей
import subprocess  # внешние команды
import sys  # sys.exit
import time  # таймеры
from threading import Thread
from datetime import datetime  # время
try:
    from .runtime import _log
except ImportError:
    from runtime import _log


class SystemMixin:
    def _detect_platform(self) -> dict:
        """Детекция ОС/платформы: TrueNAS, Proxmox, Synology, FreeBSD и т.д."""
        import platform as _platform_mod

        info = {
            'os_name': 'unknown',
            'os_family': 'unknown',
            'os_version': '',
            'arch': _platform_mod.machine() or 'unknown',
            'kernel': _platform_mod.release() or '',
            'has_zfs': False,
            'has_docker': False,
            'has_smartctl': False,
            'is_truenas': False,
            'is_proxmox': False,
            'is_synology': False,
            'is_freebsd': False,
            'is_vm': False,
        }

        # Определяем ОС
        try:
            import distro as _distro_mod
            info['os_name'] = _distro_mod.id() or 'unknown'
            info['os_family'] = _distro_mod.like() or info['os_name']
            info['os_version'] = _distro_mod.version() or ''
        except ImportError:
            # Fallback: читаем /etc/os-release
            try:
                with open('/etc/os-release') as f:
                    for line in f:
                        if line.startswith('ID='):
                            info['os_name'] = line.split('=', 1)[1].strip().strip('"')
                        elif line.startswith('ID_LIKE='):
                            info['os_family'] = line.split('=', 1)[1].strip().strip('"')
                        elif line.startswith('VERSION_ID='):
                            info['os_version'] = line.split('=', 1)[1].strip().strip('"')
            except OSError:
                pass

        # FreeBSD
        if sys.platform.startswith('freebsd') or info['os_name'] == 'freebsd':
            info['is_freebsd'] = True
            info['os_family'] = 'freebsd'

        # TrueNAS detection
        if os.path.exists('/etc/truenas') or os.path.exists('/data/truenas-version'):
            info['is_truenas'] = True
        # TrueNAS Scale uses midclt, TrueNAS Core uses freenas-api
        if os.path.exists('/usr/local/bin/midclt') or os.path.exists('/usr/sbin/midclt'):
            info['is_truenas'] = True
        # Check for TrueNAS via /etc/platform
        try:
            with open('/etc/platform', 'r') as f:
                platform_str = f.read().strip().lower()
                if 'truenas' in platform_str or 'freenas' in platform_str:
                    info['is_truenas'] = True
        except OSError:
            pass

        # Proxmox detection
        if os.path.exists('/usr/bin/pveversion') or os.path.exists('/etc/pve'):
            info['is_proxmox'] = True
        try:
            result = subprocess.run(['pveversion'], capture_output=True, text=True, timeout=3)
            if result.returncode == 0 and 'pve-manager' in result.stdout:
                info['is_proxmox'] = True
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass

        # Synology detection
        if os.path.exists('/etc/synoinfo.conf'):
            info['is_synology'] = True
        if os.path.exists('/usr/syno/sbin/synouser'):
            info['is_synology'] = True

        # ZFS detection
        try:
            result = subprocess.run(['zpool', 'list', '-H', '-o', 'name'],
                                    capture_output=True, text=True, timeout=5)
            if result.returncode == 0 and result.stdout.strip():
                info['has_zfs'] = True
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass

        # Docker detection
        try:
            result = subprocess.run(['docker', 'info', '--format', '{{.ServerVersion}}'],
                                    capture_output=True, text=True, timeout=5)
            if result.returncode == 0 and result.stdout.strip():
                info['has_docker'] = True
        except (FileNotFoundError, subprocess.TimeoutExpired):
            pass

        # smartctl detection
        info['has_smartctl'] = shutil.which('smartctl') is not None

        # VM detection (если гипервизор — это тоже "специализированная" система)
        try:
            with open('/sys/class/dmi/id/product_name', 'r') as f:
                product = f.read().strip().lower()
                if any(vm in product for vm in ('virtualbox', 'vmware', 'kvm', 'qemu', 'hyper-v', 'xen')):
                    info['is_vm'] = True
        except OSError:
            pass
        # Fallback: dmesg с VM
        if not info['is_vm']:
            try:
                result = subprocess.run(['dmesg'], capture_output=True, text=True, timeout=3)
                if result.returncode == 0:
                    dmesg = result.stdout.lower()
                    if any(vm in dmesg for vm in ('vmware', 'virtualbox', 'kvm', 'hyperv', 'xen')):
                        info['is_vm'] = True
            except (FileNotFoundError, subprocess.TimeoutExpired):
                pass

        return info

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

    def _gpu_collector_loop(self):
        """Фоновый поток: собирает GPU данные каждые 30с, не блокируя основной цикл."""
        while True:
            try:
                gpu = self.collect_gpu_info()
                with self._gpu_lock:
                    self._gpu_cache = gpu
            except Exception as e:
                _log(f"GPU collector error: {e}")
            time.sleep(30)

    def _start_gpu_collector(self):
        """Запуск фонового потока сбора GPU данных."""
        self._gpu_thread = Thread(target=self._gpu_collector_loop, daemon=True)
        self._gpu_thread.start()
        _log("Async GPU collector started (30s interval)")

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

        # TrueNAS/FreeBSD: ZFS datasets — psutil уже видит их через statvfs
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

        # TrueNAS/FreeBSD: если не нашли через psutil, пробуем zpool list
        if not best and self._platform.get('has_zfs'):
            try:
                result = subprocess.run(
                    ['zpool', 'list', '-Hp', '-o', 'name,alloc,capacity,size'],
                    capture_output=True, text=True, timeout=5
                )
                if result.returncode == 0:
                    for line in result.stdout.splitlines():
                        parts = line.split()
                        if len(parts) >= 4:
                            pool_name = parts[0]
                            # Пропускаем boot pool — он маленький
                            if pool_name == 'boot-pool':
                                continue
                            try:
                                alloc = int(parts[1])
                                capacity_pct = int(parts[2])
                                total = int(parts[3])
                                # Находим mountpoint для pool
                                mp_result = subprocess.run(
                                    ['zpool', 'list', '-Hp', '-o', 'mountpoint', pool_name],
                                    capture_output=True, text=True, timeout=3
                                )
                                mount = mp_result.stdout.strip() if mp_result.returncode == 0 else f'/{pool_name}'
                                used = alloc
                                percent = capacity_pct
                                if total > 0 and (best is None or total > best['total']):
                                    best = {
                                        'percent': percent,
                                        'total': total,
                                        'used': used,
                                        'mount': mount,
                                    }
                            except (ValueError, IndexError):
                                continue
            except (FileNotFoundError, subprocess.TimeoutExpired):
                pass

        if best:
            return best
        try:
            usage = psutil.disk_usage('/')
            return {'percent': usage.percent, 'total': usage.total, 'used': usage.used, 'mount': '/'}
        except Exception:
            return {'percent': 0, 'total': 0, 'used': 0, 'mount': '/'}

    def collect_metrics(self):
        # Non-blocking: возвращает CPU% с момента последнего вызова (~60с средняя)
        cpu_percent = psutil.cpu_percent(interval=None)
        memory = psutil.virtual_memory()
        swap = psutil.swap_memory()
        disk = self._disk_usage_main()
        bytes_recv, bytes_sent = self._physical_net_bytes()
        # GPU: читаем из кэша (собирается в фоновом потоке, не блокирует цикл)
        with self._gpu_lock:
            gpu_info = list(self._gpu_cache) if self._gpu_cache else []
        load1 = 0.0
        try:
            load1 = float(psutil.getloadavg()[0])
        except (AttributeError, OSError, ValueError):
            pass

        now = time.time()
        elapsed = max(0.1, now - self.last_collect_time)
        self.last_collect_time = now

        network_in_sec = 0.0
        network_out_sec = 0.0
        if self.first_network_read:
            self.last_network_in = bytes_recv
            self.last_network_out = bytes_sent
            self.first_network_read = False
        else:
            network_in_sec = max(0, (bytes_recv - self.last_network_in)) / elapsed
            network_out_sec = max(0, (bytes_sent - self.last_network_out)) / elapsed
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
            "network_in": round(network_in_sec, 2),
            "network_out": round(network_out_sec, 2),
            "network_in_total": bytes_recv,
            "network_out_total": bytes_sent,
            # Платформа
            "os_name": self._platform.get('os_name', ''),
            "os_family": self._platform.get('os_family', ''),
            "os_version": self._platform.get('os_version', ''),
            "arch": self._platform.get('arch', ''),
            "kernel": self._platform.get('kernel', ''),
            "is_truenas": self._platform.get('is_truenas', False),
            "is_proxmox": self._platform.get('is_proxmox', False),
            "is_synology": self._platform.get('is_synology', False),
            "is_freebsd": self._platform.get('is_freebsd', False),
            "has_zfs": self._platform.get('has_zfs', False),
        }
        try:
            if hasattr(psutil, "boot_time"):
                boot_ts = int(psutil.boot_time())
                metrics["boot_time"] = boot_ts
                metrics["uptime_sec"] = max(0, int(time.time()) - boot_ts)
        except Exception:
            pass

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
