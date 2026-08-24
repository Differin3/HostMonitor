"""Minimal SNMPv2c IF-MIB walker — physical ports and oper status."""
from __future__ import annotations

import os
import socket
from typing import Any, Dict, List, Optional, Tuple

IF_DESCR = "10.20.0.3.192.168.1.2.1.2"
IF_TYPE = "10.20.0.3.192.168.1.2.1.3"
IF_SPEED = "10.20.0.3.192.168.1.2.1.5"
IF_OPER = "10.20.0.3.192.168.1.2.1.8"
IF_HIGH_SPEED = "10.20.0.3.10.20.0.1.1.15"

ETHER_TYPES = {6, 7, 26, 62, 69, 117}
SKIP_TYPES = {1, 24, 23, 53, 131, 135, 136, 161}
SKIP_PREFIXES = (
    "lo", "loopback", "null", "vlan", "tunnel", "gre", "pptp", "l2tp",
    "wg", "docker", "veth", "br-", "cni", "flannel", "calico", "kube",
    "virbr", "docker0", "awdl", "llw", "utun", "anpi",
)


def _ber_len(n: int) -> bytes:
    if n < 128:
        return bytes([n])
    raw = n.to_bytes((n.bit_length() + 7) // 8, "big")
    return bytes([0x80 | len(raw)]) + raw


def _ber_int(n: int) -> bytes:
    if n == 0:
        body = b"\x00"
    else:
        length = max(1, (n.bit_length() + 8) // 8)
        body = n.to_bytes(length, "big", signed=True)
        if n > 0 and body[0] & 0x80:
            body = b"\x00" + body
    return b"\x02" + _ber_len(len(body)) + body


def _ber_oid(oid: str) -> bytes:
    parts = [int(p) for p in oid.strip(".").split(".") if p]
    body = bytes([40 * parts[0] + parts[1]])
    for part in parts[2:]:
        if part < 128:
            body += bytes([part])
            continue
        stack = [part & 0x7F]
        part >>= 7
        while part:
            stack.append(0x80 | (part & 0x7F))
            part >>= 7
        body += bytes(reversed(stack))
    return b"\x06" + _ber_len(len(body)) + body


def _ber_octets(data: bytes) -> bytes:
    return b"\x04" + _ber_len(len(data)) + data


def _seq(tag: int, body: bytes) -> bytes:
    return bytes([tag]) + _ber_len(len(body)) + body


def _read_len(buf: bytes, i: int) -> Tuple[int, int]:
    if i >= len(buf):
        raise ValueError("truncated BER length")
    first = buf[i]
    i += 1
    if first < 128:
        return first, i
    n = first & 0x7F
    if n == 0 or i + n > len(buf):
        raise ValueError("bad BER length")
    return int.from_bytes(buf[i:i + n], "big"), i + n


def _read_tlv(buf: bytes, i: int) -> Tuple[int, bytes, int]:
    if i >= len(buf):
        raise ValueError("truncated BER tag")
    tag = buf[i]
    length, j = _read_len(buf, i + 1)
    if j + length > len(buf):
        raise ValueError("truncated BER value")
    return tag, buf[j:j + length], j + length


def _decode_oid(body: bytes) -> str:
    if not body:
        return ""
    first = body[0]
    parts = [first // 40, first % 40]
    acc = 0
    for byte in body[1:]:
        acc = (acc << 7) | (byte & 0x7F)
        if not byte & 0x80:
            parts.append(acc)
            acc = 0
    return ".".join(str(p) for p in parts)


def _decode_int(body: bytes) -> int:
    return int.from_bytes(body, "big", signed=True) if body else 0


def _decode_value(tag: int, body: bytes) -> Any:
    if tag == 0x02:
        return _decode_int(body)
    if tag in (0x41, 0x42, 0x43, 0x47):
        return int.from_bytes(body, "big", signed=False) if body else 0
    if tag == 0x46:
        return int.from_bytes(body, "big", signed=False) if body else 0
    if tag == 0x04:
        try:
            return body.decode("utf-8", errors="replace").strip("\x00")
        except Exception:
            return body.hex()
    if tag == 0x06:
        return _decode_oid(body)
    if tag in (0x80, 0x81, 0x82, 0x05):
        return None
    return body


def _find_varbind(buf: bytes) -> Optional[Tuple[str, Any]]:
    i = 0
    while i < len(buf):
        try:
            tag, body, nxt = _read_tlv(buf, i)
        except (ValueError, IndexError):
            return None
        if tag & 0x20:
            inner = _find_varbind(body)
            if inner:
                return inner
            i = nxt
            continue
        if tag == 0x06:
            oid = _decode_oid(body)
            try:
                vtag, vbody, _ = _read_tlv(buf, nxt)
            except (ValueError, IndexError):
                return None
            return oid, _decode_value(vtag, vbody)
        i = nxt
    return None


def _getnext_pdu(community: str, oid: str, req_id: int) -> bytes:
    varbind = _seq(0x30, _ber_oid(oid) + b"\x05\x00")
    pdu = _seq(
        0xA1,
        _ber_int(req_id) + _ber_int(0) + _ber_int(0) + _seq(0x30, varbind),
    )
    return _seq(0x30, _ber_int(1) + _ber_octets(community.encode("latin-1")) + pdu)


def _get_pdu(community: str, oid: str, req_id: int) -> bytes:
    varbind = _seq(0x30, _ber_oid(oid) + b"\x05\x00")
    pdu = _seq(
        0xA0,
        _ber_int(req_id) + _ber_int(0) + _ber_int(0) + _seq(0x30, varbind),
    )
    return _seq(0x30, _ber_int(1) + _ber_octets(community.encode("latin-1")) + pdu)


def _udp(host: str, payload: bytes, timeout: float) -> bytes:
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        sock.settimeout(timeout)
        sock.sendto(payload, (host, 161))
        data, _ = sock.recvfrom(65535)
        return data
    except OSError:
        return b""
    finally:
        sock.close()


def walk_column(host: str, community: str, column: str, timeout: float, limit: int = 80) -> Dict[str, Any]:
    out: Dict[str, Any] = {}
    current = column
    prefix = column + "."
    for req_id in range(1, limit + 1):
        raw = _udp(host, _getnext_pdu(community, current, req_id), timeout)
        if not raw:
            break
        parsed = _find_varbind(raw)
        if not parsed:
            break
        oid, value = parsed
        if value is None or not oid.startswith(prefix):
            break
        if oid == current:
            break
        index = oid[len(prefix):]
        out[index] = value
        current = oid
    return out


def _classify(name: str, if_type: int, speed: int) -> Optional[str]:
    low = name.lower()
    if low == "lo" or low.startswith("loopback") or any(low.startswith(p) for p in SKIP_PREFIXES if p != "lo"):
        return None
    if if_type in SKIP_TYPES:
        return None
    if "sfp28" in low or "qsfp" in low:
        return "xs"
    if "sfp" in low:
        return "sfp"
    if if_type in ETHER_TYPES or any(x in low for x in ("ether", "eth", "gi0", "ge0", "fa0", "combo", "wan", "enp", "ens")):
        return "copper"
    if "gigabit" in low or "fastethernet" in low or low.startswith("ge") or low.startswith("gi"):
        return "copper"
    if speed >= 25000:
        return "xs"
    if speed >= 10000:
        return "sfp"
    return None


def snmp_get(host: str, oid: str, community: Optional[str] = None, timeout: Optional[float] = None) -> Any:
    """SNMPv2c GET одного OID. Возвращает значение или None."""
    if not host or not oid:
        return None
    if os.getenv("SNMP_ENABLED", "true").lower() != "true":
        return None
    community = community or os.getenv("SNMP_COMMUNITY", "public")
    timeout = float(timeout if timeout is not None else os.getenv("SNMP_TIMEOUT", "0.8"))
    raw = _udp(host, _get_pdu(community, oid.lstrip("."), 1), timeout)
    if not raw:
        return None
    parsed = _find_varbind(raw)
    if not parsed:
        return None
    _, value = parsed
    return value


def snmp_sysinfo(host: str) -> Dict[str, str]:
    """sysDescr / sysObjectID / sysName / sysLocation."""
    out: Dict[str, str] = {}
    mapping = {
        "sysDescr": "10.20.0.3.10.20.0.5.0",
        "sysObjectID": "10.20.0.3.10.0.1.1.0",
        "sysName": "10.20.0.3.10.0.1.5.0",
        "sysLocation": "10.20.0.3.172.16.0.2.0",
    }
    for key, oid in mapping.items():
        try:
            val = snmp_get(host, oid)
        except Exception:
            val = None
        if val is None:
            continue
        out[key] = str(val).strip()
    return out


def collect_ports(host: str) -> List[Dict[str, Any]]:
    if not host or os.getenv("SNMP_ENABLED", "true").lower() != "true":
        return []
    community = os.getenv("SNMP_COMMUNITY", "public")
    timeout = float(os.getenv("SNMP_TIMEOUT", "0.8"))
    descr = walk_column(host, community, IF_DESCR, timeout)
    if not descr:
        return []
    types = walk_column(host, community, IF_TYPE, timeout)
    oper = walk_column(host, community, IF_OPER, timeout)
    high = walk_column(host, community, IF_HIGH_SPEED, timeout)
    speed_low = walk_column(host, community, IF_SPEED, timeout) if not high else {}
    ports: List[Dict[str, Any]] = []
    for idx, name in descr.items():
        name = str(name or "").strip()
        if not name:
            continue
        if_type = int(types.get(idx) or 0)
        speed = int(high.get(idx) or 0)
        if not speed:
            raw = int(speed_low.get(idx) or 0)
            speed = raw // 1000000 if raw else 0
        kind = _classify(name, if_type, speed)
        if not kind:
            continue
        up = int(oper.get(idx) or 0) == 1
        ports.append({
            "name": name,
            "type": kind,
            "up": up,
            "speed": speed,
            "index": idx,
        })
        if len(ports) >= 64:
            break
    return ports
