# Сеть: интерфейсы, шлюзы, соседи, LLDP-опрос
import os  # окружение
import psutil  # системные метрики
import re  # разбор SSH-логов
import socket  # IP шлюза из /proc/net/route
import struct  # разбор little-endian gateway
import subprocess  # внешние команды
import time  # таймеры
try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry

try:
    from . import lldp_discovery as lldp_mod
except ImportError:
    import lldp_discovery as lldp_mod


class NetworkMixin:
    @staticmethod
    def _skip_virtual_iface(name: str) -> bool:
        return bool(re.match(
            r'^(lo(\d+)?$|docker|veth|br-|virbr|cni|flannel|calico|kube|'
            r'epair|tun|tap|pflog|pfsync|lagg|bridge|fair-share|vtnet)',
            name or '',
            re.I,
        ))

    @staticmethod
    def _normalize_ipv6(addr: str) -> str:
        return (addr or '').split('%', 1)[0].strip()

    @classmethod
    def _ipv6_rank(cls, addr: str) -> int:
        a = cls._normalize_ipv6(addr).lower()
        if not a or a in ('::', '::1') or a.startswith('ff'):
            return 0
        if a.startswith('fe80:'):
            return 1
        return 2

    def collect_default_gateways(self):
        gateways4 = {}
        gateways6 = {}

        # Linux: /proc/net/route
        if not self._platform.get('is_freebsd'):
            try:
                with open('/proc/net/route', encoding='utf-8') as fh:
                    next(fh, None)
                    for line in fh:
                        fields = line.split()
                        if len(fields) < 3:
                            continue
                        iface, dest, gw_hex = fields[0], fields[1], fields[2]
                        if dest != '00000000' or gw_hex == '00000000':
                            continue
                        try:
                            gateways4[iface] = socket.inet_ntoa(struct.pack('<L', int(gw_hex, 16)))
                        except (OSError, ValueError, struct.error):
                            continue
            except OSError:
                pass
            try:
                with open('/proc/net/ipv6_route', encoding='utf-8') as fh:
                    for line in fh:
                        fields = line.split()
                        if len(fields) < 10:
                            continue
                        dest, dest_prefix, _src, _src_pfx, gw_hex, _metric, _ref, _use, _flags, iface = fields[:10]
                        if dest != '0' * 32 or dest_prefix != '00' or gw_hex == '0' * 32:
                            continue
                        try:
                            gateways6[iface] = socket.inet_ntop(socket.AF_INET6, bytes.fromhex(gw_hex))
                        except (OSError, ValueError):
                            continue
            except OSError:
                pass

        # FreeBSD: route get default
        if self._platform.get('is_freebsd'):
            try:
                result = subprocess.run(
                    ['route', '-n', 'get', 'default'],
                    capture_output=True, text=True, timeout=3
                )
                if result.returncode == 0:
                    for line in result.stdout.splitlines():
                        line = line.strip()
                        if line.startswith('gateway:'):
                            gw = line.split(':', 1)[1].strip()
                            if gw and gw != 'link#0':
                                gateways4['default'] = gw
                        elif line.startswith('interface:'):
                            iface = line.split(':', 1)[1].strip()
                            if iface and 'default' in gateways4:
                                gateways4[iface] = gateways4.pop('default')
            except (FileNotFoundError, subprocess.TimeoutExpired):
                pass

            try:
                result = subprocess.run(
                    ['route', '-n', 'get', '-inet6', 'default'],
                    capture_output=True, text=True, timeout=3
                )
                if result.returncode == 0:
                    for line in result.stdout.splitlines():
                        line = line.strip()
                        if line.startswith('gateway:'):
                            gw = line.split(':', 1)[1].strip()
                            if gw and gw != 'link#0':
                                gateways6['default'] = gw
                        elif line.startswith('interface:'):
                            iface = line.split(':', 1)[1].strip()
                            if iface and 'default' in gateways6:
                                gateways6[iface] = gateways6.pop('default')
            except (FileNotFoundError, subprocess.TimeoutExpired):
                pass

        return gateways4, gateways6

    def collect_neighbors(self):
        rows = []
        seen = set()

        def add_row(ip, mac, iface, family):
            ip = (ip or '').split('%', 1)[0].strip()
            mac = (mac or '').lower()
            iface = iface or ''
            if not ip or self._skip_virtual_iface(iface):
                return
            if mac in ('', '*', '00:00:00:00:00:00'):
                mac = ''
            key = (ip, iface)
            if key in seen:
                return
            seen.add(key)
            rows.append({'ip': ip, 'mac': mac, 'iface': iface, 'family': family})

        # Linux: ip neigh
        try:
            result = subprocess.run(
                ['ip', '-o', 'neigh', 'show'],
                capture_output=True, text=True, timeout=2, check=False,
            )
            if result.returncode == 0:
                for line in result.stdout.splitlines():
                    parts = line.split()
                    if len(parts) < 3 or 'FAILED' in parts or 'INCOMPLETE' in parts:
                        continue
                    ip = parts[0]
                    iface = ''
                    mac = ''
                    if 'dev' in parts:
                        iface = parts[parts.index('dev') + 1] if parts.index('dev') + 1 < len(parts) else ''
                    if 'lladdr' in parts:
                        mac = parts[parts.index('lladdr') + 1] if parts.index('lladdr') + 1 < len(parts) else ''
                    family = 6 if ':' in ip else 4
                    add_row(ip, mac, iface, family)
        except (FileNotFoundError, subprocess.TimeoutExpired, OSError):
            pass

        # Linux fallback: /proc/net/arp
        if not rows:
            try:
                with open('/proc/net/arp', encoding='utf-8') as fh:
                    next(fh, None)
                    for line in fh:
                        fields = line.split()
                        if len(fields) < 6:
                            continue
                        ip, _hw, flags, mac, _mask, iface = fields[:6]
                        if flags in ('0x0', '0x00'):
                            continue
                        add_row(ip, mac, iface, 4)
            except OSError:
                pass

        # FreeBSD: arp -a
        if self._platform.get('is_freebsd') and not rows:
            try:
                result = subprocess.run(
                    ['arp', '-a', '-n'],
                    capture_output=True, text=True, timeout=3
                )
                if result.returncode == 0:
                    for line in result.stdout.splitlines():
                        # Format: ? (192.168.1.1) at 00:11:22:33:44:55 on em0 expires in 120 seconds [ethernet]
                        import re as _re
                        m = _re.search(r'\((\d+\.\d+\.\d+\.\d+)\)\s+at\s+([0-9a-f:]+)\s+on\s+(\S+)', line, _re.I)
                        if m:
                            ip, mac, iface = m.group(1), m.group(2), m.group(3)
                            if mac not in ('(incomplete)', 'ff:ff:ff:ff:ff:ff'):
                                add_row(ip, mac, iface, 4)
            except (FileNotFoundError, subprocess.TimeoutExpired):
                pass

        # FreeBSD: ndp -a (IPv6)
        if self._platform.get('is_freebsd'):
            try:
                result = subprocess.run(
                    ['ndp', '-a'],
                    capture_output=True, text=True, timeout=3
                )
                if result.returncode == 0:
                    for line in result.stdout.splitlines():
                        import re as _re
                        m = _re.search(r'([0-9a-f:]+)\s+([\w.]+)\s+(\S+)', line, _re.I)
                        if m:
                            ipv6, mac, iface = m.group(1), m.group(2), m.group(3)
                            if ':' in ipv6:
                                add_row(ipv6, mac, iface, 6)
            except (FileNotFoundError, subprocess.TimeoutExpired):
                pass

        return rows

    def collect_network_interfaces(self):
        interfaces = []
        try:
            net_if_addrs = psutil.net_if_addrs()
            net_if_stats = psutil.net_if_stats()
            net_io_counters = psutil.net_io_counters(pernic=True)
            gateways4, gateways6 = self.collect_default_gateways()

            for interface_name, addrs in net_if_addrs.items():
                if self._skip_virtual_iface(interface_name):
                    continue

                stats = net_if_stats.get(interface_name)
                io_counters = net_io_counters.get(interface_name)

                ipv4 = None
                netmask = None
                ipv6 = None
                ipv6_netmask = None
                ipv6_best = 0
                for addr in addrs:
                    family = getattr(addr, 'family', None)
                    if family == socket.AF_INET:
                        if not ipv4:
                            ipv4 = addr.address
                            netmask = addr.netmask
                    elif family == socket.AF_INET6:
                        rank = self._ipv6_rank(addr.address)
                        if rank > ipv6_best:
                            ipv6_best = rank
                            ipv6 = self._normalize_ipv6(addr.address)
                            ipv6_netmask = addr.netmask

                interfaces.append({
                    'name': interface_name,
                    'ip': ipv4 or 'N/A',
                    'ipv6': ipv6 or '',
                    'netmask': netmask or 'N/A',
                    'ipv6_netmask': ipv6_netmask or '',
                    'gateway': gateways4.get(interface_name) or '',
                    'gateway6': gateways6.get(interface_name) or '',
                    'status': 'up' if stats and stats.isup else 'down',
                    'speed': stats.speed if stats else 0,
                    'rx_bytes': io_counters.bytes_recv if io_counters else 0,
                    'tx_bytes': io_counters.bytes_sent if io_counters else 0
                })
        except Exception as e:
            _log(f"Error collecting network interfaces: {e}")
        return interfaces

    def send_network_interfaces(self, interfaces, neighbors=None):
        resp = _request_with_retry(
            "POST",
            f"{self.master_url}/api/ports.php?action=interfaces",
            json={"interfaces": interfaces, "neighbors": neighbors or []},
            headers=self.headers,
            verify=_get_verify(),
        )
        if resp and resp.status_code in (200, 201):
            return True
        body = ''
        if resp is not None:
            try:
                body = (resp.text or '')[:200]
            except Exception:
                body = ''
        _log(f"Error sending network interfaces: HTTP {resp.status_code if resp else 'no response'} {body}".strip())
        return False

    def _on_lldp_device(self, info):
        """Колбэк пассивного LLDP: кладём соседа в кеш."""
        if not isinstance(info, dict):
            return
        mac = (info.get("mac") or "").lower()
        ip = (info.get("ip") or info.get("mgmt_ip") or "").strip()
        name = (info.get("sys_name") or "").strip()
        key = mac or ip or name or info.get("port_id") or f"anon-{int(time.time())}"
        row = dict(info)
        row["seen_at"] = time.time()
        row.setdefault("source", "lldp-passive")
        with self._lldp_lock:
            prev = self.lldp_cache.get(key) or {}
            prev.update({k: v for k, v in row.items() if v not in (None, "", [])})
            self.lldp_cache[key] = prev
        _log(f"LLDP passive neighbor: {name or key} ip={ip or '-'} mac={mac or '-'}")

    def _get_known_hosts(self):
        """IP известных устройств: UPnP hosts + SNMP_TARGETS + LLDP cache."""
        hosts = []
        seen = set()

        def add(ip):
            ip = (ip or "").strip().split("%", 1)[0]
            if not ip or ip in seen:
                return
            # skip link-local / obvious junk
            if ip.startswith("127.") or ip.startswith("169.254.") or ip == "0.0.0.0":
                return
            seen.add(ip)
            hosts.append(ip)

        for target in lldp_mod.parse_snmp_targets():
            add(target)

        for target in getattr(self, "_cdp_hosts", ()) or ():
            add(target)

        with self._upnp_lock:
            devices = list(self.upnp_devices or [])
        for device in devices:
            add(device.get("host") or device.get("wan_ip") or "")
            for host in device.get("lan_hosts") or (device.get("extra") or {}).get("hosts") or []:
                if isinstance(host, dict):
                    add(host.get("ip") or "")
                else:
                    add(str(host))

        with self._lldp_lock:
            for row in self.lldp_cache.values():
                add(row.get("ip") or row.get("mgmt_ip") or row.get("host") or "")

        return hosts

    def _poll_lldp_devices(self):
        """Активный опрос LLDP RemTable + SNMP sysinfo/ports по известным IP."""
        enabled = os.getenv("LLDP_ACTIVE_POLL_KNOWN", "true").lower() == "true"
        if not enabled and os.getenv("LLDP_PASSIVE", "true").lower() != "true":
            return []

        devices = []
        seen_udn = set()
        seen_names = set()
        seen_ip = set()
        pending_cdp = []

        def push(device):
            udn = device.get("udn") or ""
            nm = (device.get("friendly_name") or "").strip().lower()
            if not udn or udn in seen_udn:
                return
            if nm and nm in seen_names:
                return
            seen_udn.add(udn)
            if nm:
                seen_names.add(nm)
            devices.append(device)

        # 1) Пассивный кеш → устройства (+ SNMP enrich если есть IP)
        with self._lldp_lock:
            passive_rows = list(self.lldp_cache.values())
        for row in passive_rows:
            info = dict(row)
            ip = (info.get("ip") or info.get("mgmt_ip") or info.get("host") or "").strip()
            if ip and enabled:
                try:
                    info = lldp_mod.enrich_host(ip, info)
                except Exception as e:
                    _log(f"LLDP enrich {ip}: {e}")
            push(lldp_mod.device_from_lldp(info))

        if not enabled:
            return devices

        # 2) SNMP RemTable с известных хостов + собственный host
        for host in self._get_known_hosts():
            try:
                neighbors = lldp_mod.poll_remote_table(host)
            except Exception as e:
                _log(f"LLDP SNMP poll {host}: {e}")
                neighbors = []
            for nb in neighbors:
                info = dict(nb)
                # Если у соседа нет mgmt IP — всё равно регистрируем; иначе enrich
                nb_ip = (info.get("ip") or "").strip()
                if nb_ip:
                    try:
                        info = lldp_mod.enrich_host(nb_ip, info)
                    except Exception:
                        pass
                # Также сохраняем в кеш
                key = (info.get("mac") or nb_ip or info.get("sys_name") or info.get("port_id") or host)
                with self._lldp_lock:
                    prev = self.lldp_cache.get(key) or {}
                    prev.update({k: v for k, v in info.items() if v not in (None, "", [])})
                    prev["seen_at"] = time.time()
                    self.lldp_cache[key] = prev
                push(lldp_mod.device_from_lldp(info))

            # Сам polled host как устройство (если отвечает на SNMP)
            try:
                self_info = lldp_mod.enrich_host(host, {"source": f"snmp-target:{host}", "ip": host})
                if self_info.get("ports") or self_info.get("sys_name"):
                    push(lldp_mod.device_from_lldp(self_info))
                # Собираем CDP-соседей с management-IP — обработаем после всех хостов
                for nb in (self_info.get("cdp") or []):
                    nb_ip = str(nb.get("ip") or "").strip()
                    if not nb_ip or nb_ip in seen_ip:
                        continue
                    seen_ip.add(nb_ip)
                    self._cdp_hosts.add(nb_ip)
                    pending_cdp.append({
                        "ip": nb_ip,
                        "name": str(nb.get("device_id") or "").strip(),
                        "platform": str(nb.get("platform") or "").strip(),
                        "via": host,
                        "port": str(nb.get("device_port") or "").strip(),
                    })
            except Exception:
                pass

        # 3) CDP-соседи (коммутаторы/роутеры) — добавляем тех, кого ещё нет по имени
        for nb in pending_cdp:
            nm = nb["name"].strip().lower()
            if nm and nm in seen_names:
                continue
            nb_ip = nb["ip"]
            try:
                nb_info = lldp_mod.enrich_host(nb_ip, {"source": f"cdp:{nb['via']}", "ip": nb_ip})
            except Exception:
                nb_info = {"source": f"cdp:{nb['via']}", "ip": nb_ip}
            if not nb_info.get("sys_name"):
                nb_info["sys_name"] = nb["name"] or nb_ip
            if not nb_info.get("sys_desc"):
                nb_info["sys_desc"] = nb["platform"]
            nb_info["cdp_via"] = nb["via"]
            nb_info["cdp_port"] = nb["port"]
            push(lldp_mod.device_from_lldp(nb_info))

        self._lldp_devices = list(devices)
        _log(f"LLDP poll produced {len(devices)} device(s), cache={len(self.lldp_cache)}")
        return devices

    @staticmethod
    def merge_devices(upnp_list, lldp_list):
        """Объединяет UPnP и LLDP по IP/MAC, без дублей, дополняя поля."""
        merged = []
        by_udn = {}
        by_ip = {}
        by_mac = {}

        def _mac_of(d):
            mac = (d.get("mac") or "").lower()
            if mac:
                return mac
            extra = d.get("extra") if isinstance(d.get("extra"), dict) else {}
            return (extra.get("mac") or d.get("serial_number") or "").lower()

        def _ip_of(d):
            return (d.get("host") or d.get("wan_ip") or (d.get("extra") or {}).get("mgmt_ip") or "").strip()

        def _merge_into(dst, src):
            for key, val in src.items():
                if key == "extra":
                    continue
                if val in (None, "", [], {}):
                    continue
                cur = dst.get(key)
                if cur in (None, "", [], {}):
                    dst[key] = val
                elif key == "ports" and isinstance(val, list) and isinstance(cur, list):
                    if len(val) > len(cur):
                        dst[key] = val
            src_extra = src.get("extra") if isinstance(src.get("extra"), dict) else {}
            dst_extra = dst.get("extra") if isinstance(dst.get("extra"), dict) else {}
            if src_extra or dst_extra:
                combined = dict(dst_extra)
                combined.update({k: v for k, v in src_extra.items() if v not in (None, "", [])})
                # отметим источники
                sources = set()
                for s in (dst_extra.get("discovery"), src_extra.get("discovery"), dst.get("ssdp_st"), src.get("ssdp_st")):
                    if s:
                        sources.add(str(s))
                if sources:
                    combined["discovery"] = "+".join(sorted(sources))
                dst["extra"] = combined

        for device in list(upnp_list or []) + list(lldp_list or []):
            if not isinstance(device, dict):
                continue
            udn = (device.get("udn") or "").strip()
            ip = _ip_of(device)
            mac = _mac_of(device)

            target = None
            if udn and udn in by_udn:
                target = by_udn[udn]
            elif ip and ip in by_ip:
                target = by_ip[ip]
            elif mac and mac in by_mac:
                target = by_mac[mac]

            if target is None:
                row = dict(device)
                if isinstance(device.get("extra"), dict):
                    row["extra"] = dict(device["extra"])
                merged.append(row)
                if udn:
                    by_udn[udn] = row
                if ip:
                    by_ip[ip] = row
                if mac:
                    by_mac[mac] = row
            else:
                _merge_into(target, device)
                if udn:
                    by_udn[udn] = target
                if ip:
                    by_ip[ip] = target
                if mac:
                    by_mac[mac] = target

        return merged
