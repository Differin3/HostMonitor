"""LLDP discovery: passive sniff (scapy) + active SNMP RemTable poll.

Passive requires root + optional `scapy`. Active uses built-in SNMPv2c walker
(snmp_if) and falls back to `snmpwalk` if present. pysnmp is optional.
"""
from __future__ import annotations

import os
import re
import socket
import subprocess
import threading
import time
from typing import Any, Callable, Dict, List, Optional, Set

try:
    from . import snmp_if
except ImportError:
    import snmp_if  # type: ignore

# IEEE 802.1AB LLDP RemTable (1.0.8802.1.1.2.1.4.1)
LLDP_REM_CHASSIS_SUBTYPE = "1.0.8802.1.1.2.1.4.1.1.4"
LLDP_REM_CHASSIS_ID = "1.0.8802.1.1.2.1.4.1.1.5"
LLDP_REM_PORT_SUBTYPE = "1.0.8802.1.1.2.1.4.1.1.6"
LLDP_REM_PORT_ID = "1.0.8802.1.1.2.1.4.1.1.7"
LLDP_REM_PORT_DESC = "1.0.8802.1.1.2.1.4.1.1.8"
LLDP_REM_SYS_NAME = "1.0.8802.1.1.2.1.4.1.1.9"
LLDP_REM_SYS_DESC = "1.0.8802.1.1.2.1.4.1.1.10"
LLDP_REM_MAN_ADDR = "1.0.8802.1.1.2.1.4.2.1.3"  # lldpRemManAddrIfId (index embeds addr)

ETHERTYPE_LLDP = 0x88CC

_IP_RE = re.compile(r"^(?:\d{1,3}\.){3}\d{1,3}$")
_MAC_RE = re.compile(r"^([0-9a-f]{2}[:-]){5}[0-9a-f]{2}$", re.I)

_passive_stop = threading.Event()
_passive_thread: Optional[threading.Thread] = None
_passive_cb: Optional[Callable[[Dict[str, Any]], None]] = None
_passive_lock = threading.Lock()
_passive_seen: Set[str] = set()


def _norm_mac(value: Any) -> str:
    raw = str(value or "").strip().lower().replace("-", ":")
    if not raw:
        return ""
    # hex without separators (from SNMP OCTET STRING)
    if re.fullmatch(r"[0-9a-f]{12}", raw):
        return ":".join(raw[i : i + 2] for i in range(0, 12, 2))
    if _MAC_RE.match(raw):
        parts = raw.replace("-", ":").split(":")
        return ":".join(p.zfill(2) for p in parts)
    # binary-ish repr from snmpwalk
    if " " in raw and all(len(p) <= 2 for p in raw.split()):
        try:
            return ":".join(f"{int(p, 16):02x}" for p in raw.split())
        except ValueError:
            pass
    return raw


def _norm_ip(value: Any) -> str:
    text = str(value or "").strip()
    if not text:
        return ""
    if "%" in text:
        text = text.split("%", 1)[0]
    if _IP_RE.match(text):
        return text
    return ""


def _decode_chassis(subtype: Any, chassis: Any) -> Dict[str, str]:
    st = int(subtype or 0) if str(subtype or "").isdigit() else 0
    raw = chassis
    mac = ""
    ip = ""
    if st == 4:  # macAddress
        mac = _norm_mac(raw)
    elif st == 5:  # networkAddress — often 1 + IPv4
        if isinstance(raw, (bytes, bytearray)):
            if len(raw) >= 5 and raw[0] == 1:
                ip = ".".join(str(b) for b in raw[1:5])
            else:
                mac = _norm_mac(raw.hex())
        else:
            text = str(raw)
            ip = _norm_ip(text) or ""
            if not ip:
                mac = _norm_mac(text)
    else:
        text = str(raw or "")
        mac = _norm_mac(text)
        ip = _norm_ip(text)
    return {"mac": mac, "ip": ip, "chassis_raw": str(raw or ""), "chassis_subtype": str(st)}


def device_from_lldp(info: Dict[str, Any]) -> Dict[str, Any]:
    """Normalize LLDP neighbor into upnp_devices-compatible dict (needs udn)."""
    mac = _norm_mac(info.get("mac") or info.get("chassis_mac") or "")
    ip = _norm_ip(info.get("ip") or info.get("mgmt_ip") or info.get("host") or "")
    name = (info.get("sys_name") or info.get("friendly_name") or info.get("hostname") or "").strip()
    desc = (info.get("sys_desc") or info.get("model_description") or "").strip()
    port_id = (info.get("port_id") or "").strip()
    port_desc = (info.get("port_desc") or "").strip()
    source = info.get("source") or "lldp"
    key = mac or ip or name or port_id or "unknown"
    udn = f"lldp:{key}"
    host = ip or ""
    return {
        "udn": udn,
        "friendly_name": name or (f"LLDP {mac}" if mac else f"LLDP {ip or key}"),
        "manufacturer": info.get("manufacturer") or "",
        "manufacturer_url": "",
        "model_name": info.get("model_name") or "",
        "model_number": "",
        "model_description": desc[:500],
        "serial_number": mac or "",
        "device_type": "urn:hostmonitor:device:LLDPNeighbor:1",
        "presentation_url": "",
        "location_url": "",
        "host": host,
        "ssdp_st": "lldp",
        "ssdp_server": source,
        "software": "",
        "is_igd": 0,
        "hardware_version": "",
        "ports": info.get("ports") or [],
        "mac": mac,
        "extra": {
            "discovery": "lldp",
            "source": source,
            "mgmt_ip": ip,
            "mac": mac,
            "port_id": port_id,
            "port_desc": port_desc,
            "local_port": info.get("local_port") or "",
            "chassis_id": info.get("chassis_raw") or mac,
            "sys_desc": desc,
        },
    }


def parse_lldp_tlv(payload: bytes) -> Dict[str, Any]:
    """Minimal LLDP TLV parser (type 7 bits + length 9 bits)."""
    out: Dict[str, Any] = {}
    i = 0
    while i + 2 <= len(payload):
        hdr = (payload[i] << 8) | payload[i + 1]
        i += 2
        tlv_type = hdr >> 9
        tlv_len = hdr & 0x1FF
        if tlv_type == 0:
            break
        if i + tlv_len > len(payload):
            break
        value = payload[i : i + tlv_len]
        i += tlv_len
        if tlv_type == 1 and value:  # Chassis ID
            st = value[0]
            body = value[1:]
            decoded = _decode_chassis(st, body.hex() if st == 4 else body)
            out.update(decoded)
            if st == 4:
                out["mac"] = _norm_mac(body.hex())
            elif st == 5 and len(body) >= 5 and body[0] == 1:
                out["ip"] = ".".join(str(b) for b in body[1:5])
        elif tlv_type == 2 and value:  # Port ID
            out["port_id"] = value[1:].decode("utf-8", errors="replace").strip() or value[1:].hex()
        elif tlv_type == 4:
            out["ttl"] = int.from_bytes(value[:2], "big") if len(value) >= 2 else 0
        elif tlv_type == 5:
            out["sys_name"] = value.decode("utf-8", errors="replace").strip()
        elif tlv_type == 6:
            out["sys_desc"] = value.decode("utf-8", errors="replace").strip()
        elif tlv_type == 8:  # Management Address
            if len(value) >= 6:
                addr_len = value[0]
                if addr_len >= 5 and value[1] == 1 and len(value) >= 1 + addr_len:
                    out["ip"] = ".".join(str(b) for b in value[2:6])
    return out


def _passive_sniff_loop(iface: Optional[str], on_device: Callable[[Dict[str, Any]], None]) -> None:
    try:
        from scapy.all import Ether, sniff  # type: ignore
    except Exception as exc:
        print(f"[lldp] scapy unavailable, passive LLDP disabled: {exc}", flush=True)
        return

    def _handle(pkt) -> None:
        try:
            if not pkt.haslayer(Ether):
                return
            eth = pkt[Ether]
            if int(eth.type) != ETHERTYPE_LLDP:
                return
            payload = bytes(eth.payload)
            info = parse_lldp_tlv(payload)
            info["source"] = "lldp-passive"
            info["local_port"] = iface or ""
            if eth.src:
                info.setdefault("mac", _norm_mac(eth.src))
            key = f"{info.get('mac')}|{info.get('ip')}|{info.get('sys_name')}|{info.get('port_id')}"
            with _passive_lock:
                if key in _passive_seen:
                    return
                _passive_seen.add(key)
            on_device(info)
        except Exception as exc:
            print(f"[lldp] passive handle error: {exc}", flush=True)

    try:
        sniff(
            iface=iface or None,
            store=False,
            stop_filter=lambda _: _passive_stop.is_set(),
            prn=_handle,
            filter="ether proto 0x88cc",
            quiet=True,
        )
    except Exception as exc:
        print(f"[lldp] passive sniff failed: {exc}", flush=True)


def start_passive(on_device: Callable[[Dict[str, Any]], None], iface: Optional[str] = None) -> bool:
    """Start background LLDP sniff. Returns False if disabled/unavailable."""
    global _passive_thread, _passive_cb
    if os.getenv("LLDP_PASSIVE", "true").lower() != "true":
        return False
    if _passive_thread and _passive_thread.is_alive():
        return True
    _passive_stop.clear()
    _passive_cb = on_device
    listen_iface = iface or os.getenv("LLDP_LISTEN_INTERFACE", "").strip() or None
    _passive_thread = threading.Thread(
        target=_passive_sniff_loop,
        args=(listen_iface, on_device),
        name="lldp-passive",
        daemon=True,
    )
    _passive_thread.start()
    return True


def stop_passive() -> None:
    _passive_stop.set()


def _walk_lldp_table(host: str, community: str, timeout: float) -> List[Dict[str, Any]]:
    cols = {
        "chassis_subtype": snmp_if.walk_column(host, community, LLDP_REM_CHASSIS_SUBTYPE, timeout, limit=120),
        "chassis_id": snmp_if.walk_column(host, community, LLDP_REM_CHASSIS_ID, timeout, limit=120),
        "port_subtype": snmp_if.walk_column(host, community, LLDP_REM_PORT_SUBTYPE, timeout, limit=120),
        "port_id": snmp_if.walk_column(host, community, LLDP_REM_PORT_ID, timeout, limit=120),
        "port_desc": snmp_if.walk_column(host, community, LLDP_REM_PORT_DESC, timeout, limit=120),
        "sys_name": snmp_if.walk_column(host, community, LLDP_REM_SYS_NAME, timeout, limit=120),
        "sys_desc": snmp_if.walk_column(host, community, LLDP_REM_SYS_DESC, timeout, limit=120),
    }
    indexes = set()
    for mapping in cols.values():
        indexes.update(mapping.keys())
    neighbors: List[Dict[str, Any]] = []
    for idx in sorted(indexes):
        decoded = _decode_chassis(cols["chassis_subtype"].get(idx), cols["chassis_id"].get(idx))
        port_val = cols["port_id"].get(idx)
        port_id = str(port_val or "").strip()
        if isinstance(port_val, (bytes, bytearray)):
            try:
                port_id = port_val.decode("utf-8", errors="replace").strip() or port_val.hex()
            except Exception:
                port_id = port_val.hex()
        info = {
            "source": f"lldp-snmp:{host}",
            "polled_from": host,
            "sys_name": str(cols["sys_name"].get(idx) or "").strip(),
            "sys_desc": str(cols["sys_desc"].get(idx) or "").strip(),
            "port_id": port_id,
            "port_desc": str(cols["port_desc"].get(idx) or "").strip(),
            "mac": decoded.get("mac") or "",
            "ip": decoded.get("ip") or "",
            "chassis_raw": decoded.get("chassis_raw") or "",
            "index": idx,
        }
        # Index of RemTable is timeMark.localPortNum.remoteIndex — keep local port num
        parts = str(idx).split(".")
        if len(parts) >= 2:
            info["local_port"] = parts[1]
        neighbors.append(info)
    return neighbors


def _snmpwalk_fallback(host: str, community: str, oid: str, timeout: float) -> Dict[str, str]:
    out: Dict[str, str] = {}
    try:
        proc = subprocess.run(
            ["snmpwalk", "-v2c", "-c", community, "-t", str(max(1, int(timeout))), "-Oqn", host, oid],
            capture_output=True,
            text=True,
            timeout=max(3.0, timeout * 4),
            check=False,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired, OSError):
        return out
    prefix = oid.rstrip(".") + "."
    for line in (proc.stdout or "").splitlines():
        if " = " not in line:
            continue
        left, right = line.split(" = ", 1)
        left = left.strip().lstrip(".")
        if not left.startswith(prefix.lstrip(".")):
            # snmpwalk -Oqn may print numeric oid
            if not left.startswith(oid.lstrip(".")):
                continue
            index = left[len(oid.lstrip(".")) :].lstrip(".")
        else:
            index = left[len(prefix.lstrip(".")) :]
        val = right.strip().strip('"')
        if val.upper().startswith("HEX-STRING:"):
            val = val.split(":", 1)[1].strip()
        out[index] = val
    return out


def poll_remote_table(host: str) -> List[Dict[str, Any]]:
    """Active LLDP: read RemTable from a known device via SNMP."""
    if not host or os.getenv("SNMP_ENABLED", "true").lower() != "true":
        return []
    community = os.getenv("SNMP_COMMUNITY", "public")
    timeout = float(os.getenv("SNMP_TIMEOUT", "0.8"))
    try:
        rows = _walk_lldp_table(host, community, timeout)
    except Exception:
        rows = []
    if rows:
        return rows
    # Fallback via net-snmp snmpwalk
    names = _snmpwalk_fallback(host, community, LLDP_REM_SYS_NAME, timeout)
    if not names:
        return []
    chassis = _snmpwalk_fallback(host, community, LLDP_REM_CHASSIS_ID, timeout)
    chassis_st = _snmpwalk_fallback(host, community, LLDP_REM_CHASSIS_SUBTYPE, timeout)
    ports = _snmpwalk_fallback(host, community, LLDP_REM_PORT_ID, timeout)
    descs = _snmpwalk_fallback(host, community, LLDP_REM_SYS_DESC, timeout)
    out = []
    for idx, sys_name in names.items():
        decoded = _decode_chassis(chassis_st.get(idx), chassis.get(idx))
        out.append({
            "source": f"lldp-snmpwalk:{host}",
            "polled_from": host,
            "sys_name": sys_name,
            "sys_desc": descs.get(idx, ""),
            "port_id": ports.get(idx, ""),
            "mac": decoded.get("mac") or "",
            "ip": decoded.get("ip") or "",
            "chassis_raw": decoded.get("chassis_raw") or "",
            "index": idx,
        })
    return out


def enrich_host(host: str, base: Optional[Dict[str, Any]] = None) -> Dict[str, Any]:
    """SNMP sysinfo + IF-MIB ports for a management IP."""
    info = dict(base or {})
    info["host"] = host
    info["ip"] = info.get("ip") or host
    try:
        sysinfo = snmp_if.snmp_sysinfo(host)
    except Exception:
        sysinfo = {}
    if sysinfo.get("sysName"):
        info.setdefault("sys_name", sysinfo["sysName"])
        info.setdefault("friendly_name", sysinfo["sysName"])
    if sysinfo.get("sysDescr"):
        info.setdefault("sys_desc", sysinfo["sysDescr"])
    try:
        ports = snmp_if.collect_ports(host)
    except Exception:
        ports = []
    if ports:
        info["ports"] = ports
    info.setdefault("source", f"snmp:{host}")
    return info


def parse_snmp_targets(raw: Optional[str] = None) -> List[str]:
    text = raw if raw is not None else os.getenv("SNMP_TARGETS", "")
    hosts: List[str] = []
    for part in re.split(r"[\s,;]+", str(text or "")):
        ip = _norm_ip(part) or (part.strip() if part.strip() and not part.strip().startswith("#") else "")
        if ip and ip not in hosts:
            hosts.append(ip)
    return hosts
