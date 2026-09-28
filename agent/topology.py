# UPnP-топология и обработка событий
import os  # окружение
import time  # таймеры
from threading import Thread
try:
    from .runtime import _log, _get_verify, _request_with_retry
except ImportError:
    from runtime import _log, _get_verify, _request_with_retry

try:
    from . import upnp as upnp_mod
except ImportError:
    import upnp as upnp_mod


class TopologyMixin:
    def collect_upnp(self):
        enabled = os.getenv("UPNP_ENABLED", "true").lower() == "true"
        if not enabled:
            return []
        try:
            mx = int(os.getenv("UPNP_MX", "3"))
            timeout = float(os.getenv("UPNP_TIMEOUT", "8"))
            if timeout <= mx:
                timeout = float(mx) + 5.0
            devices = upnp_mod.discover(mx=mx, timeout=timeout)
            with self._upnp_lock:
                self.upnp_devices = devices
            _log(f"UPnP discovery found {len(devices)} device(s)")
            return devices
        except Exception as e:
            _log(f"UPnP discovery error: {e}")
            return []

    def send_upnp(self, devices):
        try:
            resp = _request_with_retry(
                "POST",
                f"{self.master_url}/api/upnp.php",
                json={"devices": devices, "node_name": self.node_name},
                headers=self.headers,
                verify=_get_verify(),
                timeout=20,
            )
            if resp and resp.status_code in (200, 201):
                return True
            _log(f"UPnP send failed: {resp.status_code if resp else 'no response'}")
        except Exception as e:
            _log(f"Error sending UPnP data: {e}")
        return False

    def send_upnp_gone(self, udn: str):
        if not udn:
            return False
        try:
            resp = _request_with_retry(
                "POST",
                f"{self.master_url}/api/upnp.php",
                json={"gone": [udn], "node_name": self.node_name},
                headers=self.headers,
                verify=_get_verify(),
                timeout=10,
            )
            return bool(resp and resp.status_code in (200, 201))
        except Exception as e:
            _log(f"UPnP gone send failed: {e}")
            return False

    def _on_upnp_event(self, kind: str, payload: dict):
        if kind == "byebye":
            udn = payload.get("udn") or ""
            self.send_upnp_gone(udn)
            with self._upnp_lock:
                self.upnp_devices = [d for d in self.upnp_devices if d.get("udn") != udn]
            return
        if kind == "alive":
            now = time.time()
            if now - self._upnp_alive_at < 15:
                return
            self._upnp_alive_at = now
            Thread(target=self._upnp_refresh_quiet, daemon=True).start()
            return
        if kind == "gena":
            udn = payload.get("udn") or ""
            props = payload.get("props") or {}
            with self._upnp_lock:
                for device in self.upnp_devices:
                    if udn and device.get("udn") != udn:
                        continue
                    extra = device.setdefault("extra", {})
                    extra.update(props)
                    if props.get("ConnectionStatus"):
                        device["connection_status"] = props["ConnectionStatus"]
                    if props.get("ExternalIPAddress"):
                        device["wan_ip"] = props["ExternalIPAddress"]
                    if props.get("PhysicalLinkStatus"):
                        device["wan_link"] = props["PhysicalLinkStatus"]
                    break
            if props:
                self._send_upnp_with_lldp(list(self.upnp_devices))

    def _send_upnp_with_lldp(self, upnp_devices):
        """Отправка UPnP-снимка вместе с последними LLDP/SNMP-устройствами.

        Иначе периодические UPnP-обновления затирают LLDP/SNMP-устройства на панели
        (upnp_prune_missing удаляет их как отсутствующие в снимке).
        """
        try:
            merged = self.merge_devices(upnp_devices or [], list(getattr(self, "_lldp_devices", []) or []))
        except Exception as e:
            _log(f"merge UPnP+LLDP failed: {e}")
            merged = upnp_devices or []
        return self.send_upnp(merged)

    def _upnp_refresh_quiet(self):
        devices = self.collect_upnp()
        if devices is not None:
            self._send_upnp_with_lldp(devices)

    def handle_upnp_command(self, command: str) -> bool:
        parts = command.split()
        if len(parts) < 2:
            _log("Invalid UPnP command")
            return False
        action = parts[1].lower()
        devices = self.upnp_devices or self.collect_upnp() or []
        if action == "scan":
            self._send_upnp_with_lldp(devices)
            return True
        udn = None
        if "--udn" in parts:
            idx = parts.index("--udn")
            if idx + 1 < len(parts):
                udn = parts[idx + 1]
        device = upnp_mod.find_device(devices, udn)
        if not device:
            _log("No UPnP IGD device available for mapping")
            return False
        try:
            if action in ("addmap", "add-mapping") and len(parts) >= 6:
                ext_port = int(parts[2])
                int_ip = parts[3]
                int_port = int(parts[4])
                proto = parts[5]
                desc = "HostMonitor"
                for token in parts[6:]:
                    if token == "--udn":
                        break
                    desc = token
                    break
                upnp_mod.add_port_mapping(device, ext_port, int_ip, int_port, proto, desc)
                snap = self.collect_upnp()
                if snap is not None:
                    self._send_upnp_with_lldp(snap)
                return True
            if action in ("delmap", "delete-mapping") and len(parts) >= 4:
                ext_port = int(parts[2])
                proto = parts[3]
                upnp_mod.delete_port_mapping(device, ext_port, proto)
                snap = self.collect_upnp()
                if snap is not None:
                    self._send_upnp_with_lldp(snap)
                return True
        except Exception as e:
            _log(f"UPnP command failed: {e}")
            return False
        _log(f"Unknown UPnP command: {command}")
        return False
