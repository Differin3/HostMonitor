# Сбор данных по контейнерам и портам
import json  # JSON
import psutil  # системные метрики
import re  # разбор SSH-логов
import subprocess  # внешние команды
from datetime import datetime  # время
try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry


class DockerMixin:
    @staticmethod
    def _docker_pct(value):
        try:
            return float(str(value).replace('%', '').strip() or 0)
        except (TypeError, ValueError):
            return 0.0

    @staticmethod
    def _norm_container_status(state_status, raw_status=''):
        st = (state_status or '').strip().lower()
        mapping = {
            'running': 'running',
            'paused': 'paused',
            'restarting': 'restarting',
            'exited': 'stopped',
            'dead': 'stopped',
            'created': 'stopped',
            'removing': 'stopped',
        }
        if st in mapping:
            return mapping[st]
        raw = (raw_status or '').lower()
        if 'paused' in raw:
            return 'paused'
        if raw.startswith('up') or 'restarting' in raw:
            return 'restarting' if 'restarting' in raw else 'running'
        return 'stopped'

    def _docker_inspect(self, ids, timeout=20):
        items = []
        for i in range(0, len(ids), 40):
            chunk = ids[i:i + 40]
            result = subprocess.run(
                ['docker', 'inspect', *chunk],
                capture_output=True, text=True, timeout=timeout,
            )
            if result.returncode != 0:
                _log(f"docker inspect failed: {(result.stderr or '')[:200]}")
                continue
            try:
                data = json.loads(result.stdout or '[]')
            except json.JSONDecodeError:
                continue
            if isinstance(data, list):
                items.extend(data)
        return items

    def collect_docker_snapshot(self):
        # Полный снимок контейнеров и docker-сетей. None = docker недоступен (не затираем панель).
        try:
            listed = subprocess.run(
                ['docker', 'ps', '-aq'],
                capture_output=True, text=True, timeout=8,
            )
        except FileNotFoundError:
            return None
        except Exception as e:
            _log(f"Error collecting docker snapshot: {e}")
            return None

        if listed.returncode != 0:
            err = ((listed.stderr or '') + (listed.stdout or '')).lower()
            if 'not found' in err or listed.returncode == 127:
                return None
            _log(f"docker ps failed: {(listed.stderr or listed.stdout or '')[:200]}")
            return {'containers': [], 'networks': []}

        ids = [line.strip() for line in listed.stdout.splitlines() if line.strip()]
        inspected = self._docker_inspect(ids) if ids else []
        stats_map = {}
        running_ids = [
            (item.get('Id') or '')[:12]
            for item in inspected
            if ((item.get('State') or {}).get('Status') or '').lower() == 'running'
        ]
        if running_ids:
            try:
                stats = subprocess.run(
                    ['docker', 'stats', '--no-stream', '--format', '{{.ID}}|{{.CPUPerc}}|{{.MemPerc}}', *running_ids],
                    capture_output=True, text=True, timeout=20,
                )
                if stats.returncode == 0:
                    for line in stats.stdout.splitlines():
                        parts = line.split('|')
                        if len(parts) >= 3:
                            stats_map[parts[0].strip()] = (
                                self._docker_pct(parts[1]),
                                self._docker_pct(parts[2]),
                            )
            except Exception as e:
                _log(f"docker stats failed: {e}")

        containers = []
        for item in inspected:
            cid = item.get('Id') or ''
            name = (item.get('Name') or '').lstrip('/') or cid[:12]
            state = item.get('State') or {}
            raw_status = state.get('Status') or ''
            if state.get('Error'):
                raw_status = f"{raw_status} ({state.get('Error')})"
            elif item.get('State', {}).get('FinishedAt') and raw_status == 'exited':
                raw_status = item.get('State', {}).get('Status') or raw_status
            cfg = item.get('Config') or {}
            host = item.get('HostConfig') or {}
            nets_obj = ((item.get('NetworkSettings') or {}).get('Networks')) or {}
            networks = []
            ipv4 = ''
            for net_name, net in nets_obj.items():
                ip = (net or {}).get('IPAddress') or ''
                networks.append({
                    'name': net_name,
                    'ipv4': ip,
                    'gateway': (net or {}).get('Gateway') or '',
                })
                if ip and not ipv4:
                    ipv4 = ip
            ports = []
            bindings = ((item.get('NetworkSettings') or {}).get('Ports')) or {}
            for spec, hosts in bindings.items():
                if '/' in spec:
                    cport, proto = spec.split('/', 1)
                else:
                    cport, proto = spec, 'tcp'
                try:
                    cport_n = int(cport)
                except ValueError:
                    continue
                if not hosts:
                    continue
                for bind in hosts:
                    host_port = (bind or {}).get('HostPort') or ''
                    if not host_port:
                        continue
                    try:
                        host_n = int(host_port)
                    except ValueError:
                        continue
                    ports.append({
                        'host': host_n,
                        'host_ip': (bind or {}).get('HostIp') or '',
                        'container': cport_n,
                        'protocol': proto,
                    })
            cpu, mem = 0.0, 0.0
            for key, val in stats_map.items():
                if cid.startswith(key) or key.startswith(cid[:12]):
                    cpu, mem = val
                    break
            containers.append({
                'container_id': cid,
                'name': name,
                'image': cfg.get('Image') or '',
                'status': self._norm_container_status(state.get('Status'), raw_status),
                'raw_status': raw_status,
                'cpu_percent': cpu,
                'memory_percent': mem,
                'ipv4': ipv4,
                'network_mode': host.get('NetworkMode') or '',
                'networks': networks,
                'ports': ports,
            })

        return {
            'containers': containers,
            'networks': self.collect_docker_networks(),
        }

    def collect_docker_networks(self):
        networks = []
        try:
            listed = subprocess.run(
                ['docker', 'network', 'ls', '-q'],
                capture_output=True, text=True, timeout=8,
            )
            if listed.returncode != 0:
                return []
            ids = [line.strip() for line in listed.stdout.splitlines() if line.strip()]
            if not ids:
                return []
            inspected = []
            for i in range(0, len(ids), 40):
                chunk = ids[i:i + 40]
                result = subprocess.run(
                    ['docker', 'network', 'inspect', *chunk],
                    capture_output=True, text=True, timeout=15,
                )
                if result.returncode != 0:
                    continue
                try:
                    data = json.loads(result.stdout or '[]')
                except json.JSONDecodeError:
                    continue
                if isinstance(data, list):
                    inspected.extend(data)
            for net in inspected:
                ipam = (((net.get('IPAM') or {}).get('Config')) or [{}])
                subnet = ''
                gateway = ''
                if ipam and isinstance(ipam[0], dict):
                    subnet = ipam[0].get('Subnet') or ''
                    gateway = ipam[0].get('Gateway') or ''
                members = []
                for cid, info in (net.get('Containers') or {}).items():
                    ip = (info or {}).get('IPv4Address') or ''
                    if '/' in ip:
                        ip = ip.split('/', 1)[0]
                    members.append({
                        'id': cid,
                        'name': ((info or {}).get('Name') or cid[:12]).lstrip('/'),
                        'ipv4': ip,
                    })
                networks.append({
                    'network_id': (net.get('Id') or '')[:64],
                    'name': net.get('Name') or '',
                    'driver': net.get('Driver') or '',
                    'scope': net.get('Scope') or '',
                    'subnet': subnet,
                    'gateway': gateway,
                    'containers': members,
                })
        except FileNotFoundError:
            return []
        except Exception as e:
            _log(f"Error collecting docker networks: {e}")
        return networks

    def collect_containers(self):
        snap = self.collect_docker_snapshot()
        if snap is None:
            return []
        return snap.get('containers') or []

    def collect_ports(self):
        # Сбор информации об открытых портах
        ports = []
        try:
            # Используем ss для получения открытых портов с процессами
            result = subprocess.run(['ss', '-tlnp'], capture_output=True, text=True, timeout=5)
            if result.returncode != 0:
                result = subprocess.run(['netstat', '-tlnp'], capture_output=True, text=True, timeout=5)
            
            if result.returncode == 0:
                for line in result.stdout.strip().split('\n')[1:]:  # Пропускаем заголовок
                    if 'LISTEN' in line or 'ESTAB' in line:
                        parts = line.split()
                        if len(parts) >= 4:
                            addr = parts[3]
                            if ':' in addr:
                                port = addr.split(':')[-1].rstrip(']')
                                # Пытаемся извлечь PID и имя процесса
                                pid = None
                                process_name = None
                                if len(parts) > 4:
                                    proc_info = parts[-1]
                                    if 'pid=' in proc_info:
                                        try:
                                            pid = int(proc_info.split('pid=')[1].split(',')[0])
                                            # Получаем имя процесса по PID
                                            try:
                                                proc = psutil.Process(pid)
                                                process_name = proc.name()
                                            except Exception:
                                                pass
                                        except (ValueError, TypeError):
                                            pass
                                
                                try:
                                    port_num = int(port)
                                except ValueError:
                                    continue
                                ports.append({
                                    'port': port_num,
                                    'type': 'tcp',
                                    'status': 'open',
                                    'pid': pid,
                                    'process_name': process_name
                                })
        except Exception as e:
            print(f"Error collecting ports: {e}")
        return ports

    def send_containers(self, containers):
        # Отправка данных о контейнерах
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/containers.php",
            json=containers,
            headers=self.headers,
            verify=_get_verify(),
        )
        return bool(resp and resp.status_code in (200, 201))

    def send_ports(self, ports):
        # Отправка данных о портах
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/ports.php",
            json=ports,
            headers=self.headers,
            verify=_get_verify(),
        )
        return bool(resp and resp.status_code in (200, 201))

    def collect_container_logs(self, containers, tail=50):
        # Сбор логов Docker-контейнеров через docker logs (по запросу, не каждый цикл)
        logs = []
        for c in containers:
            cid = c.get("container_id") or c.get("id")
            if not cid:
                continue
            name = c.get("name") or cid[:12]
            try:
                result = subprocess.run(
                    ["docker", "logs", "--tail", str(tail), "--timestamps", cid],
                    stdout=subprocess.PIPE,
                    stderr=subprocess.STDOUT,
                    text=True,
                    timeout=15,
                )
                now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
                output = result.stdout or ''
                if result.returncode != 0 and not output.strip():
                    logs.append({
                        "type": "container",
                        "container_id": cid,
                        "level": "error",
                        "message": f"docker logs failed for {name}",
                        "timestamp": now,
                    })
                    continue
                for line in output.splitlines():
                    line = line.strip()
                    if not line:
                        continue
                    ts = now
                    msg = line
                    # docker --timestamps: 2024-01-15T12:34:56.123456789Z message
                    m = re.match(
                        r'^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\s+(.*)$',
                        line,
                    )
                    if m:
                        try:
                            ts = datetime.strptime(m.group(1), "%Y-%m-%dT%H:%M:%S").strftime("%Y-%m-%d %H:%M:%S")
                        except ValueError:
                            pass
                        msg = m.group(2).strip() or line
                    level = 'error' if re.search(r'\b(error|fatal|panic)\b', msg, re.I) else 'info'
                    logs.append(
                        {
                            "type": "container",
                            "container_id": cid,
                            "level": level,
                            "message": msg,
                            "timestamp": ts,
                        }
                    )
            except Exception as e:
                _log(f"Error collecting logs for container {cid}: {e}")
        return logs
