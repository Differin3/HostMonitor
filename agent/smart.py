# S.M.A.R.T. дисков
import json  # JSON
import os  # окружение
import subprocess  # внешние команды
try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry


class SmartMixin:
    def _smartctl_device_flags(self, device_name: str) -> list:
        """Определяем флаги для smartctl в зависимости от типа контроллера/хоста.
        ZFS-системы (TrueNAS, Proxmox) и RAID-контроллеры требуют специальных флагов."""
        flags = []
        dev = device_name.lower()

        # TrueNAS/FreeBSD: SCSI/SAS диски
        if self._platform.get('is_truenas') or self._platform.get('is_freebsd'):
            if dev.startswith('/dev/da') or dev.startswith('/dev/ada'):
                flags = ['-d', 'scsi']
            elif dev.startswith('/dev/nvd') or dev.startswith('/dev/nvme'):
                flags = ['-d', 'nvme']

        # Proxmox: ZFS pool диски
        if self._platform.get('is_proxmox') and self._platform.get('has_zfs'):
            if dev.startswith('/dev/sd') or dev.startswith('/dev/scsi'):
                flags = ['-d', 'scsi']
            elif dev.startswith('/dev/nvme') or dev.startswith('/dev/nvd'):
                flags = ['-d', 'nvme']

        # Areca RAID controller (TrueNAS /.large storage)
        if dev.startswith('/dev/areca') or 'areca' in dev:
            flags = ['-d', 'areca,1']  # enclosure 1

        # 3ware / tw_cli
        if dev.startswith('/dev/tw'):
            flags = ['-d', '3ware,0']

        # MegaRAID / megaraid
        if 'megaraid' in dev or dev.startswith('/dev/bus/mega'):
            flags = ['-d', 'megaraid,0']

        return flags

    def collect_smart(self):
        """Сбор S.M.A.R.T. данных со всех дисков через smartctl.
        Поддерживает ZFS-системы (TrueNAS, Proxmox), RAID-контроллеры и FreeBSD."""
        drives = []
        try:
            if not self._platform.get('has_smartctl'):
                return drives

            # Сканируем устройства с fallback для разных контроллеров
            devices = []

            # 1. Стандартное сканирование (works for most Linux systems)
            scan = subprocess.run(
                ['smartctl', '--scan', '-j'],
                capture_output=True, text=True, timeout=10
            )
            if scan.returncode == 0 and scan.stdout.strip():
                scan_data = json.loads(scan.stdout)
                devices = scan_data.get('smartctl', {}).get('devices', [])

            # 2. Fallback: lsblk (Linux)
            if not devices and not self._platform.get('is_freebsd'):
                try:
                    lsblk = subprocess.run(
                        ['lsblk', '-Jdnpo', 'NAME,TYPE,SIZE,MODEL,SERIAL'],
                        capture_output=True, text=True, timeout=5
                    )
                    if lsblk.returncode == 0 and lsblk.stdout.strip():
                        blk = json.loads(lsblk.stdout)
                        for dev in blk.get('blockdevices', []):
                            name = dev.get('name', '')
                            if name and dev.get('type') == 'disk':
                                devices.append({'name': name, 'info_name': name})
                except (FileNotFoundError, subprocess.TimeoutExpired):
                    pass

            # 3. FreeBSD: camcontrol + geom
            if self._platform.get('is_freebsd') and not devices:
                try:
                    cam = subprocess.run(
                        ['camcontrol', 'devlist', '-v'],
                        capture_output=True, text=True, timeout=5
                    )
                    if cam.returncode == 0:
                        for line in cam.stdout.splitlines():
                            # Format: <SEAGATE ST4000NM0023 0006>  at scbus0 target 0 lun 0 (pass0,da0)
                            if '/dev/' in line:
                                import re as _re
                                m = _re.search(r'(/dev/\w+)', line)
                                if m:
                                    dev_path = m.group(1)
                                    devices.append({'name': dev_path, 'info_name': dev_path})
                except (FileNotFoundError, subprocess.TimeoutExpired):
                    pass

            # 4. ZFS: пробуем устройства из zpool status
            if not devices and self._platform.get('has_zfs'):
                try:
                    zpool = subprocess.run(
                        ['zpool', 'status', '-P'],
                        capture_output=True, text=True, timeout=5
                    )
                    if zpool.returncode == 0:
                        import re as _re
                        for line in zpool.stdout.splitlines():
                            m = _re.match(r'\s+(/dev/\S+)', line)
                            if m:
                                dev_path = m.group(1)
                                if dev_path not in [d.get('name') for d in devices]:
                                    devices.append({'name': dev_path, 'info_name': dev_path})
                except (FileNotFoundError, subprocess.TimeoutExpired):
                    pass

            for dev in devices:
                device_name = dev.get('name') or dev.get('info_name') or ''
                if not device_name:
                    continue

                try:
                    drive_info = self._collect_smart_drive(device_name)
                    if drive_info:
                        drives.append(drive_info)
                except Exception as e:
                    _log(f"SMART error for {device_name}: {e}")

        except FileNotFoundError:
            pass
        except Exception as e:
            _log(f"SMART collection error: {e}")

        return drives

    def _collect_smart_drive(self, device_name):
        """Сбор S.M.A.R.T. данных для одного диска (с поддержкой разных контроллеров)."""
        extra_flags = self._smartctl_device_flags(device_name)

        # Получаем информацию о диске
        cmd_info = ['smartctl', '-i', '-j'] + extra_flags + [device_name]
        info_result = subprocess.run(
            cmd_info,
            capture_output=True, text=True, timeout=10
        )
        if info_result.returncode != 0 and info_result.returncode != 64:
            # Fallback: пробуем другие флаги если первый не сработал
            if not extra_flags:
                return None
            for fallback_flags in [['-d', 'scsi'], ['-d', 'nvme'], []]:
                cmd_info = ['smartctl', '-i', '-j'] + fallback_flags + [device_name]
                info_result = subprocess.run(
                    cmd_info,
                    capture_output=True, text=True, timeout=10
                )
                if info_result.returncode in (0, 64):
                    extra_flags = fallback_flags
                    break
            else:
                return None

        info = json.loads(info_result.stdout) if info_result.stdout.strip() else {}
        disk_info = info.get('smart_status', {})
        model_info = info.get('model_name', '') or info.get('model_family', '')
        serial = info.get('serial_number', '')
        firmware = info.get('firmware_version', '')
        capacity = info.get('user_capacity', {})
        if isinstance(capacity, dict):
            capacity = capacity.get('bytes', 0)
        rotation = info.get('rotation_rate')
        interface = info.get('interface_type', '')
        sata_ver = info.get('sata_version', '')

        # Определяем статус здоровья
        health = 'unknown'
        if disk_info:
            if disk_info.get('passed', False):
                health = 'ok'
            else:
                health = 'failed'

        # Получаем все SMART атрибуты
        cmd_attr = ['smartctl', '-A', '-j'] + extra_flags + [device_name]
        attr_result = subprocess.run(
            cmd_attr,
            capture_output=True, text=True, timeout=10
        )

        attributes = []
        temperature = None
        power_on_hours = None

        if attr_result.returncode == 0 or attr_result.returncode == 64:
            attr_data = json.loads(attr_result.stdout) if attr_result.stdout.strip() else {}
            smart_table = attr_data.get('ata_smart_attributes', {}).get('table', [])

            for attr in smart_table:
                attr_id = attr.get('id', 0)
                name = attr.get('name', '')
                value = attr.get('value', 0)
                worst = attr.get('worst', 0)
                thresh = attr.get('thresh', 0)
                flags_raw = attr.get('flags', {})
                flags = flags_raw.get('string', '') if isinstance(flags_raw, dict) else str(flags_raw)
                raw = attr.get('raw', {})
                raw_value = raw.get('value', 0) if isinstance(raw, dict) else raw

                attributes.append({
                    'id': attr_id,
                    'name': name,
                    'value': value,
                    'worst': worst,
                    'threshold': thresh,
                    'raw': raw_value,
                    'flags': flags,
                })

                if name == 'Temperature_Celsius' or attr_id == 194:
                    temperature = int(raw_value) if raw_value else None
                if name == 'Power_On_Hours' or attr_id == 9:
                    power_on_hours = int(raw_value) if raw_value else None

        # Если smartctl не дал атрибутов — проверяем NVRM
        if not attributes and health == 'unknown':
            health = 'unsupported'

        # Пытаемся определить корзину (bay) из /sys
        bay = self._detect_drive_bay(device_name)

        # Обновляем статус по атрибутам
        if health == 'ok':
            health = self._evaluate_smart_health(attributes)

        return {
            'device': device_name,
            'model': model_info,
            'serial': serial,
            'firmware': firmware,
            'capacity': capacity,
            'rotation_rate': rotation,
            'interface': interface,
            'sata_version': sata_ver,
            'temperature': temperature,
            'power_on_hours': power_on_hours,
            'health': health,
            'bay': bay,
            'attributes': attributes,
        }

    def _evaluate_smart_health(self, attributes):
        """Оценка состояния здоровья по критическим атрибутам SMART."""
        critical_attrs = {
            5: ('Reallocated_Sector_Ct', 10, 50),
            197: ('Current_Pending_Sector', 1, 10),
            198: ('Offline_Uncorrectable', 1, 10),
        }
        for attr in attributes:
            aid = attr.get('id', 0)
            if aid in critical_attrs:
                raw = int(attr.get('raw', 0) or 0)
                _, warn, crit = critical_attrs[aid]
                if raw >= crit:
                    return 'failed'
                if raw >= warn:
                    return 'warning'
        return 'ok'

    def _detect_drive_bay(self, device_name):
        """Попытка определить номер корзины (bay) для данного диска."""
        # Пробуем прочитать bay из /sys/block/*/enclosure_device/
        base = os.path.basename(device_name)
        sys_paths = [
            f'/sys/block/{base}/device/enclosure_device',
            f'/sys/class/block/{base}/device/enclosure_device',
        ]
        for path in sys_paths:
            try:
                if os.path.exists(path):
                    with open(path, 'r') as f:
                        bay_str = f.read().strip()
                        if bay_str.isdigit():
                            return int(bay_str)
            except (OSError, ValueError):
                pass

        # Пробуем lsblk для enclosure info
        try:
            result = subprocess.run(
                ['lsblk', '-Jdno', 'NAME,SERIAL', device_name],
                capture_output=True, text=True, timeout=5
            )
            if result.returncode == 0 and result.stdout.strip():
                data = json.loads(result.stdout)
                for dev in data.get('blockdevices', []):
                    # Если устройство в enclosur'е — попробуем определить по位置у
                    pass
        except Exception:
            pass

        return None

    def send_smart(self, drives):
        """Отправка S.M.A.R.T. данных на мастер-сервер."""
        if not drives:
            return True
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/smart.php",
            json={'drives': drives},
            headers=self.headers,
            verify=_get_verify(),
        )
        if resp and resp.status_code in (200, 201):
            return True
        _log(f"Failed to send SMART data: status={resp.status_code if resp else 'no response'}")
        return False
