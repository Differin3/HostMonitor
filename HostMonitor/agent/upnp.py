"""UPnP / SSDP discovery, SCPD, IGD SOAP, GENA and SSDP NOTIFY."""
from __future__ import annotations

import json
import os
import re
import select
import socket
import struct
import threading
import time
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET
from http.server import BaseHTTPRequestHandler, HTTPServer
from typing import Any, Callable, Dict, List, Optional, Set
from urllib.parse import urljoin, urlparse

try:
    from . import snmp_if
    from . import net_ident
except ImportError:
    import snmp_if
    import net_ident


SSDP_ADDR = "239.255.255.250"
SSDP_PORT = 1900
IFACE_SKIP = re.compile(
    r"^(lo|docker|veth|br-|virbr|cni|flannel|calico|kube|tun|wg|tailscale)",
    re.I,
)
IGD_HINTS = (
    "InternetGatewayDevice",
    "WANConnectionDevice",
    "WANDevice",
    "WANIPConnection",
    "WANPPPConnection",
    "WANCommonInterfaceConfig",
)

DEFAULT_ST = [
    "upnp:rootdevice",
    "ssdp:all",
    "urn:schemas-upnp-org:device:InternetGatewayDevice:1",
    "urn:schemas-upnp-org:device:InternetGatewayDevice:2",
    "urn:schemas-upnp-org:service:WANIPConnection:1",
    "urn:schemas-upnp-org:service:WANIPConnection:2",
    "urn:schemas-upnp-org:service:WANPPPConnection:1",
    "urn:schemas-upnp-org:service:WANPPPConnection:2",
    "urn:schemas-upnp-org:service:WANCommonInterfaceConfig:1",
    "urn:schemas-upnp-org:service:Layer3Forwarding:1",
    "urn:schemas-upnp-org:service:LANHostConfigManagement:1",
    "urn:schemas-upnp-org:service:WLANConfiguration:1",
    "urn:schemas-upnp-org:device:MediaRenderer:1",
    "urn:schemas-upnp-org:device:MediaServer:1",
    "urn:schemas-upnp-org:device:Printer:1",
    "urn:schemas-upnp-org:device:Basic:1",
    "urn:dial-multiscreen-org:service:dial:1",
]

_bg_stop = threading.Event()
_bg_lock = threading.Lock()
_gena_sids: Dict[str, str] = {}
_gena_callback = ""
_event_cb: Optional[Callable[[str, Dict[str, Any]], None]] = None
_last_devices: List[Dict[str, Any]] = []


def _local_name(tag: str) -> str:
    if "}" in tag:
        return tag.split("}", 1)[1]
    return tag


def _find_text(el: Optional[ET.Element], name: str) -> str:
    if el is None:
        return ""
    for child in el.iter():
        if _local_name(child.tag) == name:
            return (child.text or "").strip()
    return ""


def _children(el: ET.Element, name: str) -> List[ET.Element]:
    return [c for c in list(el) if _local_name(c.tag) == name]


def _http_get(url: str, timeout: float = 4.0) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": "HostMonitor-UPnP/1.1"})
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read().decode("utf-8", errors="replace")


def _http_raw(url: str, method: str, headers: Dict[str, str], body: bytes = b"", timeout: float = 5.0) -> tuple:
    req = urllib.request.Request(url, data=body or None, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.status, dict(resp.headers.items()), resp.read()
    except urllib.error.HTTPError as exc:
        payload = exc.read() if exc.fp else b""
        return exc.code, dict(exc.headers.items()) if exc.headers else {}, payload


def _lan_ipv4s() -> List[str]:
    ips: List[str] = []
    try:
        import psutil
        stats = psutil.net_if_stats()
        for name, addrs in psutil.net_if_addrs().items():
            if IFACE_SKIP.search(name or ""):
                continue
            st = stats.get(name)
            if st is not None and not st.isup:
                continue
            for addr in addrs:
                family = getattr(addr, "family", None)
                if family not in (socket.AF_INET, 2):
                    continue
                ip = addr.address
                if not ip or ip.startswith("127.") or ip.startswith("172.17."):
                    continue
                ips.append(ip)
    except Exception:
        pass
    if not ips:
        try:
            for info in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET):
                ip = info[4][0]
                if ip and not ip.startswith("127."):
                    ips.append(ip)
        except Exception:
            pass
    return list(dict.fromkeys(ips))


def _soap(control_url: str, service_type: str, action: str, args: Optional[Dict[str, Any]] = None, timeout: float = 5.0) -> Dict[str, str]:
    args = args or {}
    body_args = "".join(f"<{k}>{v}</{k}>" for k, v in args.items())
    envelope = (
        '<?xml version="1.0"?>'
        '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" '
        's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
        "<s:Body>"
        f'<u:{action} xmlns:u="{service_type}">{body_args}</u:{action}>'
        "</s:Body></s:Envelope>"
    )
    req = urllib.request.Request(
        control_url,
        data=envelope.encode("utf-8"),
        method="POST",
        headers={
            "Content-Type": 'text/xml; charset="utf-8"',
            "SOAPAction": f'"{service_type}#{action}"',
            "User-Agent": "HostMonitor-UPnP/1.1",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            xml_text = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        xml_text = exc.read().decode("utf-8", errors="replace") if exc.fp else ""
        raise RuntimeError(f"SOAP {action} failed HTTP {exc.code}: {xml_text[:180]}") from exc
    result: Dict[str, str] = {}
    try:
        root = ET.fromstring(xml_text)
        for child in root.iter():
            name = _local_name(child.tag)
            if child.text and name not in ("Envelope", "Body", action, f"{action}Response", "Fault"):
                result[name] = child.text.strip()
            elif name.lower() in ("newportlisting", "portlisting") and (child.text or list(child)):
                result["NewPortListing"] = ET.tostring(child, encoding="unicode") if list(child) else (child.text or "")
    except ET.ParseError:
        pass
    return result


def _parse_ssdp(text: str, addr: str, default_st: str = "") -> Optional[Dict[str, str]]:
    headers: Dict[str, str] = {}
    for line in text.split("\r\n"):
        if ":" in line:
            key, val = line.split(":", 1)
            headers[key.strip().lower()] = val.strip()
    loc = headers.get("location")
    if not loc:
        return None
    return {
        "location": loc,
        "st": headers.get("st") or headers.get("nt") or default_st,
        "usn": headers.get("usn", ""),
        "server": headers.get("server", ""),
        "nts": headers.get("nts", ""),
        "from": addr,
    }


def _ssdp_discover(st_list: List[str], mx: int, timeout: float) -> List[Dict[str, str]]:
    found: Dict[str, Dict[str, str]] = {}
    ips = _lan_ipv4s() or ["0.0.0.0"]
    socks: List[socket.socket] = []
    try:
        for ip in ips:
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            try:
                sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_TTL, 4)
            except OSError:
                pass
            try:
                if ip != "0.0.0.0":
                    sock.setsockopt(socket.IPPROTO_IP, socket.IP_MULTICAST_IF, socket.inet_aton(ip))
                    sock.bind((ip, 0))
                else:
                    sock.bind(("", 0))
            except OSError:
                try:
                    sock.bind(("", 0))
                except OSError:
                    sock.close()
                    continue
            sock.setblocking(False)
            socks.append(sock)
            for st in st_list:
                payload = (
                    "M-SEARCH * HTTP/1.1\r\n"
                    f"HOST: {SSDP_ADDR}:{SSDP_PORT}\r\n"
                    'MAN: "ssdp:discover"\r\n'
                    f"MX: {mx}\r\n"
                    f"ST: {st}\r\n"
                    "\r\n"
                ).encode("utf-8")
                try:
                    sock.sendto(payload, (SSDP_ADDR, SSDP_PORT))
                except OSError:
                    pass
        deadline = time.time() + timeout
        while time.time() < deadline and socks:
            ready, _, _ = select.select(socks, [], [], min(0.4, max(0.05, deadline - time.time())))
            for sock in ready:
                try:
                    data, addr = sock.recvfrom(65535)
                except OSError:
                    continue
                parsed = _parse_ssdp(data.decode("utf-8", errors="replace"), addr[0])
                if parsed:
                    found[parsed["location"]] = parsed
    finally:
        for sock in socks:
            sock.close()
    return list(found.values())


def _fetch_scpd(svc: Dict[str, Any]) -> List[str]:
    url = svc.get("scpd_url") or ""
    if not url:
        return []
    try:
        xml_text = _http_get(url, timeout=4.0)
        root = ET.fromstring(xml_text)
    except Exception:
        return []
    actions = []
    for el in root.iter():
        if _local_name(el.tag) == "name" and el.text:
            parent = None
            # action/name
            actions.append(el.text.strip())
    # SCPD has stateVariable/name too — keep unique, SOAP actions are typically Unique
    # Prefer actionList only
    names: List[str] = []
    for alist in root.iter():
        if _local_name(alist.tag) == "actionList":
            for action in list(alist):
                if _local_name(action.tag) != "action":
                    continue
                nm = _find_text(action, "name")
                if nm:
                    names.append(nm)
    return list(dict.fromkeys(names))


def _svc_can(svc: Optional[Dict[str, Any]], action: str) -> bool:
    if not svc:
        return False
    acts = svc.get("actions")
    if not acts:
        return True
    return action in acts


def _soap_if(svc: Optional[Dict[str, Any]], action: str, args: Optional[Dict[str, Any]] = None) -> Dict[str, str]:
    if not svc or not _svc_can(svc, action):
        return {}
    try:
        return _soap(svc["control_url"], svc["service_type"], action, args)
    except Exception:
        return {}


def _parse_device(dev_el: ET.Element, base_url: str, ssdp: Dict[str, str]) -> Dict[str, Any]:
    device_type = _find_text(dev_el, "deviceType")
    udn = _find_text(dev_el, "UDN")
    services = []
    for slist in _children(dev_el, "serviceList"):
        for svc in _children(slist, "service"):
            services.append({
                "service_type": _find_text(svc, "serviceType"),
                "service_id": _find_text(svc, "serviceId"),
                "control_url": urljoin(base_url, _find_text(svc, "controlURL")),
                "scpd_url": urljoin(base_url, _find_text(svc, "SCPDURL")),
                "event_url": urljoin(base_url, _find_text(svc, "eventSubURL")),
                "actions": [],
            })
    nested = []
    for dlist in _children(dev_el, "deviceList"):
        for child in _children(dlist, "device"):
            nested.append(_parse_device(child, base_url, ssdp))
    parsed = urlparse(ssdp.get("location") or base_url)
    return {
        "udn": udn,
        "friendly_name": _find_text(dev_el, "friendlyName"),
        "manufacturer": _find_text(dev_el, "manufacturer"),
        "manufacturer_url": _find_text(dev_el, "manufacturerURL"),
        "model_name": _find_text(dev_el, "modelName"),
        "model_number": _find_text(dev_el, "modelNumber"),
        "model_description": _find_text(dev_el, "modelDescription"),
        "serial_number": _find_text(dev_el, "serialNumber"),
        "hardware_version": _find_text(dev_el, "hardwareVersion"),
        "device_type": device_type,
        "presentation_url": urljoin(base_url, _find_text(dev_el, "presentationURL")) if _find_text(dev_el, "presentationURL") else "",
        "software": _find_text(dev_el, "softwareVersion"),
        "location_url": ssdp.get("location", ""),
        "host": parsed.hostname or ssdp.get("from", ""),
        "ssdp_st": ssdp.get("st", ""),
        "ssdp_server": ssdp.get("server", ""),
        "is_igd": int(any(h.lower() in (device_type or "").lower() for h in IGD_HINTS)),
        "services": services,
        "embedded": nested,
    }


def _service(device: Dict[str, Any], *needles: str) -> Optional[Dict[str, str]]:
    for svc in device.get("services") or []:
        st = (svc.get("service_type") or "").lower()
        sid = (svc.get("service_id") or "").lower()
        if any(n.lower() in st or n.lower() in sid for n in needles):
            return svc
    return None


def _services(device: Dict[str, Any], *needles: str) -> List[Dict[str, Any]]:
    out = []
    for svc in device.get("services") or []:
        st = (svc.get("service_type") or "").lower()
        if any(n.lower() in st for n in needles):
            out.append(svc)
    return out


def _all_services(device: Dict[str, Any]) -> List[Dict[str, str]]:
    out = list(device.get("services") or [])
    for child in device.get("embedded") or []:
        out.extend(_all_services(child))
    return out


def _is_internal_upnp(device: Dict[str, Any]) -> bool:
    dt = (device.get("device_type") or "").lower()
    return any(x in dt for x in ("wandevice", "wanconnectiondevice", "landevice"))


def _load_actions(device: Dict[str, Any]) -> None:
    for svc in device.get("services") or []:
        if svc.get("scpd_url"):
            svc["actions"] = _fetch_scpd(svc)


def _pick_wan_service(device: Dict[str, Any]) -> Optional[Dict[str, Any]]:
    l3 = _service(device, "Layer3Forwarding")
    if l3:
        row = _soap_if(l3, "GetDefaultConnectionService")
        spec = row.get("NewDefaultConnectionService") or row.get("DefaultConnectionService") or ""
        if spec:
            needle = spec.lower()
            for svc in device.get("services") or []:
                st = (svc.get("service_type") or "").lower()
                sid = (svc.get("service_id") or "").lower()
                if st and st in needle:
                    return svc
                if "wanipconnection" in needle and "wanipconnection" in st:
                    return svc
                if "wanpppconnection" in needle and "wanpppconnection" in st:
                    return svc
                if sid and sid in needle:
                    return svc
            device.setdefault("extra", {})["default_connection"] = spec
    return _service(device, "WANIPConnection", "WANPPPConnection")


def _parse_port_listing(xml_text: str) -> List[Dict[str, Any]]:
    mappings = []
    if not xml_text:
        return mappings
    try:
        root = ET.fromstring(xml_text)
    except ET.ParseError:
        try:
            root = ET.fromstring(f"<root>{xml_text}</root>")
        except ET.ParseError:
            return mappings

    def grab(el: ET.Element) -> Dict[str, str]:
        row = {}
        for child in el.iter():
            name = _local_name(child.tag)
            if child.text:
                row[name] = child.text.strip()
        return row

    entries = []
    for el in root.iter():
        name = _local_name(el.tag).lower()
        if name in ("portmappingentry", "portmapping", "entry"):
            entries.append(el)
    if not entries:
        entries = [root]
    seen = set()
    for el in entries:
        row = grab(el)
        ext = row.get("NewExternalPort") or row.get("ExternalPort") or row.get("externalPort")
        if not ext:
            continue
        proto = (row.get("NewProtocol") or row.get("Protocol") or "TCP").upper()
        key = (ext, proto, row.get("NewRemoteHost") or "")
        if key in seen:
            continue
        seen.add(key)
        mappings.append({
            "remote_host": row.get("NewRemoteHost") or row.get("RemoteHost") or "",
            "external_port": int(ext),
            "protocol": proto,
            "internal_port": int(row.get("NewInternalPort") or row.get("InternalPort") or 0),
            "internal_client": row.get("NewInternalClient") or row.get("InternalClient") or "",
            "enabled": 1 if str(row.get("NewEnabled") or row.get("Enabled") or "1") in ("1", "true", "True") else 0,
            "description": row.get("NewPortMappingDescription") or row.get("Description") or "",
            "lease_duration": int(row.get("NewLeaseDuration") or row.get("LeaseDuration") or 0),
        })
    return mappings


def _collect_mappings(wan: Dict[str, Any]) -> List[Dict[str, Any]]:
    mappings: List[Dict[str, Any]] = []
    if _svc_can(wan, "GetListOfPortMappings"):
        for proto in ("TCP", "UDP", ""):
            row = _soap_if(wan, "GetListOfPortMappings", {
                "NewStartPort": 0,
                "NewEndPort": 65535,
                "NewProtocol": proto,
                "NewManage": 1,
                "NewNumberOfPorts": 1000,
            })
            listing = row.get("NewPortListing") or row.get("PortListing") or ""
            mappings.extend(_parse_port_listing(listing))
            if mappings and proto == "":
                break
        # unique
        uniq = {}
        for m in mappings:
            uniq[(m["external_port"], m["protocol"], m.get("remote_host") or "")] = m
        mappings = list(uniq.values())
    if mappings:
        return mappings
    for idx in range(0, 128):
        try:
            row = _soap(wan["control_url"], wan["service_type"], "GetGenericPortMappingEntry", {"NewPortMappingIndex": idx})
        except Exception:
            break
        ext = row.get("NewExternalPort") or row.get("ExternalPort")
        if not ext:
            break
        mappings.append({
            "remote_host": row.get("NewRemoteHost") or "",
            "external_port": int(ext),
            "protocol": (row.get("NewProtocol") or "TCP").upper(),
            "internal_port": int(row.get("NewInternalPort") or 0),
            "internal_client": row.get("NewInternalClient") or "",
            "enabled": 1 if str(row.get("NewEnabled", "1")) in ("1", "true", "True") else 0,
            "description": row.get("NewPortMappingDescription") or "",
            "lease_duration": int(row.get("NewLeaseDuration") or 0),
        })
    return mappings


def _enrich_igd(device: Dict[str, Any]) -> None:
    extra = device.setdefault("extra", {})
    wan = _pick_wan_service(device)
    common = _service(device, "WANCommonInterfaceConfig")
    if wan:
        ip = _soap_if(wan, "GetExternalIPAddress")
        device["wan_ip"] = ip.get("NewExternalIPAddress") or ip.get("ExternalIPAddress") or device.get("wan_ip") or ""
        v6 = _soap_if(wan, "GetExternalIPv6Address")
        if v6.get("NewExternalIPv6Address"):
            extra["wan_ipv6"] = v6.get("NewExternalIPv6Address")
        status = _soap_if(wan, "GetStatusInfo")
        device["connection_status"] = status.get("NewConnectionStatus") or status.get("ConnectionStatus") or device.get("connection_status") or ""
        device["uptime"] = int(status.get("NewUptime") or status.get("Uptime") or device.get("uptime") or 0)
        ctype = _soap_if(wan, "GetConnectionTypeInfo")
        if ctype.get("NewConnectionType"):
            extra["connection_type"] = ctype.get("NewConnectionType")
        nat = _soap_if(wan, "GetNATRSIPStatus")
        if nat:
            extra["nat_enabled"] = nat.get("NewNATEnabled") or nat.get("NATEnabled") or ""
        device["port_mappings"] = _collect_mappings(wan)
        device["is_igd"] = 1
    if common:
        link = _soap_if(common, "GetCommonLinkProperties")
        device["link_bitrate_down"] = int(link.get("NewLayer1DownstreamMaxBitRate") or device.get("link_bitrate_down") or 0)
        device["link_bitrate_up"] = int(link.get("NewLayer1UpstreamMaxBitRate") or device.get("link_bitrate_up") or 0)
        if not device.get("connection_status"):
            device["connection_status"] = link.get("NewPhysicalLinkStatus") or ""
        sent = _soap_if(common, "GetTotalBytesSent")
        device["bytes_sent"] = int(sent.get("NewTotalBytesSent") or device.get("bytes_sent") or 0)
        recv = _soap_if(common, "GetTotalBytesReceived")
        device["bytes_received"] = int(recv.get("NewTotalBytesReceived") or device.get("bytes_received") or 0)
        pkts = _soap_if(common, "GetTotalPacketsSent")
        extra["packets_sent"] = int(pkts.get("NewTotalPacketsSent") or 0)
        pkr = _soap_if(common, "GetTotalPacketsReceived")
        extra["packets_received"] = int(pkr.get("NewTotalPacketsReceived") or 0)

    eth = _service(device, "WANEthernetLinkConfig")
    if eth:
        link = _soap_if(eth, "GetEthernetLinkStatus")
        status = (link.get("NewLinkStatus") or link.get("LinkStatus") or "").strip()
        if status:
            device["wan_link"] = status
            if not device.get("connection_status"):
                device["connection_status"] = status


def _enrich_lan_hosts(device: Dict[str, Any]) -> None:
    extra = device.setdefault("extra", {})
    lan = _service(device, "LANHostConfigManagement")
    if lan:
        mask = _soap_if(lan, "GetSubnetMask")
        routers = _soap_if(lan, "GetIPRoutersList")
        domain = _soap_if(lan, "GetDomainName")
        rng = _soap_if(lan, "GetAddressRange")
        dns = _soap_if(lan, "GetDNSServers")
        extra["dhcp"] = {
            "subnet_mask": mask.get("NewSubnetMask") or mask.get("SubnetMask") or "",
            "routers": routers.get("NewIPRouters") or routers.get("IPRouters") or "",
            "domain": domain.get("NewDomainName") or domain.get("DomainName") or "",
            "range_min": rng.get("NewMinAddress") or "",
            "range_max": rng.get("NewMaxAddress") or "",
            "dns": dns.get("NewDNSServers") or dns.get("DNSServers") or "",
        }

    hosts_svc = _service(device, "Hosts:1", ":Hosts:", "LANHostConfigManagement")
    hosts: List[Dict[str, Any]] = []
    # TR-064 / UPnP Hosts
    for svc in _services(device, "Hosts"):
        n = _soap_if(svc, "GetHostNumberOfEntries")
        count = int(n.get("NewHostNumberOfEntries") or n.get("HostNumberOfEntries") or 0)
        for idx in range(min(count, 128)):
            row = _soap_if(svc, "GetGenericHostEntry", {"NewIndex": idx})
            if not row and _svc_can(svc, "GetGenericHostEntry"):
                break
            mac = row.get("NewMACAddress") or row.get("MACAddress") or ""
            ip = row.get("NewIPAddress") or row.get("IPAddress") or ""
            if not mac and not ip:
                continue
            hosts.append({
                "ip": ip,
                "mac": mac,
                "name": row.get("NewHostName") or row.get("HostName") or "",
                "active": row.get("NewActive") or row.get("Active") or "",
                "interface": row.get("NewInterfaceType") or row.get("InterfaceType") or "",
            })
        path = _soap_if(svc, "X_AVM-DE_GetHostListPath")
        if path.get("NewX_AVM-DE_HostListPath"):
            extra["avm_hostlist"] = path.get("NewX_AVM-DE_HostListPath")
    if hosts:
        extra["hosts"] = hosts
        device["lan_hosts"] = hosts


def _enrich_wlan(device: Dict[str, Any]) -> None:
    radios = []
    clients: List[Dict[str, Any]] = []
    for svc in _services(device, "WLANConfiguration"):
        ssid_row = _soap_if(svc, "GetSSID")
        ssid = ssid_row.get("NewSSID") or ssid_row.get("SSID") or ""
        bssid = _soap_if(svc, "GetBSSID").get("NewBSSID") or ""
        ch = _soap_if(svc, "GetChannelInfo") or {}
        channel = ch.get("NewChannel") or ch.get("Channel") or _soap_if(svc, "GetChannel").get("NewChannel") or ""
        status = _soap_if(svc, "GetStatus").get("NewStatus") or _soap_if(svc, "GetInfo").get("NewStatus") or ""
        sec = _soap_if(svc, "GetSecurityKeys")
        assoc = _soap_if(svc, "GetTotalAssociations")
        ncli = int(assoc.get("NewTotalAssociations") or assoc.get("TotalAssociations") or 0)
        radio = {
            "ssid": ssid,
            "bssid": bssid,
            "channel": channel,
            "status": status,
            "clients": ncli,
            "service_id": svc.get("service_id") or "",
        }
        if sec.get("NewKeyPassphrase"):
            radio["security"] = "psk"
        radios.append(radio)
        for idx in range(min(ncli, 64)):
            row = _soap_if(svc, "GetGenericAssociatedDeviceInfo", {"NewAssociatedDeviceIndex": idx})
            mac = row.get("NewAssociatedDeviceMACAddress") or row.get("MACAddress") or ""
            if not mac:
                continue
            clients.append({
                "mac": mac,
                "ip": row.get("NewAssociatedDeviceIPAddress") or "",
                "rssi": row.get("NewX_AVM-DE_AssociatedDeviceSignalStrength") or row.get("Signal") or "",
                "ssid": ssid,
            })
    extra = device.setdefault("extra", {})
    if radios:
        extra["wlan"] = radios
        device["wlan_ssid"] = radios[0].get("ssid") or ""
        device["wlan_channel"] = str(radios[0].get("channel") or "")
        device["wlan_bssid"] = radios[0].get("bssid") or ""
    if clients:
        extra["wlan_clients"] = clients


def _enrich_media_printer(device: Dict[str, Any]) -> None:
    extra = device.setdefault("extra", {})
    dt = (device.get("device_type") or "").lower()
    av = _service(device, "AVTransport")
    if av:
        info = _soap_if(av, "GetTransportInfo", {"InstanceID": 0})
        extra["media"] = {
            "state": info.get("CurrentTransportState") or info.get("NewCurrentTransportState") or "",
            "status": info.get("CurrentTransportStatus") or "",
            "speed": info.get("CurrentSpeed") or "",
        }
        pos = _soap_if(av, "GetMediaInfo", {"InstanceID": 0})
        if pos.get("CurrentURI") or pos.get("NewCurrentURI"):
            extra["media"]["uri"] = pos.get("CurrentURI") or pos.get("NewCurrentURI")
    rc = _service(device, "RenderingControl")
    if rc:
        vol = _soap_if(rc, "GetVolume", {"InstanceID": 0, "Channel": "Master"})
        extra.setdefault("media", {})["volume"] = vol.get("CurrentVolume") or vol.get("NewCurrentVolume") or ""
    cm = _service(device, "ConnectionManager")
    if cm and "media" not in extra:
        proto = _soap_if(cm, "GetProtocolInfo")
        extra["media"] = {
            "sink": (proto.get("Sink") or proto.get("NewSink") or "")[:180],
            "source": (proto.get("Source") or proto.get("NewSource") or "")[:180],
        }
    prn = _service(device, "PrintBasic", "Printer")
    if prn or "printer" in dt:
        attrs = _soap_if(prn, "GetPrinterAttributes") if prn else {}
        info = _soap_if(prn, "GetPrinterInfo") if prn else {}
        extra["printer"] = {
            "state": attrs.get("PrinterState") or info.get("NewPrinterState") or info.get("PrinterState") or device.get("connection_status") or "",
            "name": info.get("PrinterName") or device.get("friendly_name") or "",
        }
        if extra["printer"]["state"]:
            device["connection_status"] = extra["printer"]["state"]


def _enrich_device(device: Dict[str, Any]) -> None:
    _load_actions(device)
    dt = (device.get("device_type") or "").lower()
    if "internetgatewaydevice" in dt or _service(device, "WANIPConnection", "WANPPPConnection", "Layer3Forwarding"):
        device["is_igd"] = 1
        try:
            _enrich_igd(device)
        except Exception:
            pass
    else:
        device["is_igd"] = int(device.get("is_igd") or 0)
    try:
        _enrich_lan_hosts(device)
    except Exception:
        pass
    try:
        _enrich_wlan(device)
    except Exception:
        pass
    try:
        _enrich_media_printer(device)
    except Exception:
        pass


def discover(st_list: Optional[List[str]] = None, mx: int = 3, timeout: float = 8.0) -> List[Dict[str, Any]]:
    wait = timeout if timeout > mx else float(mx) + 5.0
    seen_loc = {row["location"]: row for row in _ssdp_discover(st_list or DEFAULT_ST, mx, wait)}
    by_udn: Dict[str, Dict[str, Any]] = {}
    for ssdp in seen_loc.values():
        try:
            xml_text = _http_get(ssdp["location"])
            root = ET.fromstring(xml_text)
        except Exception:
            continue
        base = ssdp["location"]
        url_base = _find_text(root, "URLBase")
        if url_base:
            base = url_base
        root_dev = None
        for el in root.iter():
            if _local_name(el.tag) == "device":
                root_dev = el
                break
        if root_dev is None:
            continue
        parsed = _parse_device(root_dev, base, ssdp)
        parsed["services"] = _all_services(parsed)
        parsed.pop("embedded", None)
        if _is_internal_upnp(parsed):
            continue
        udn = parsed.get("udn") or parsed.get("location_url")
        if not udn:
            continue
        prev = by_udn.get(udn)
        if prev and len(parsed.get("services") or []) <= len(prev.get("services") or []):
            continue
        by_udn[udn] = parsed

    devices: List[Dict[str, Any]] = []
    for device in by_udn.values():
        net_ident.enrich_identity(device)
        _enrich_device(device)
        host = str(device.get("host") or "")
        dt = (device.get("device_type") or "").lower()
        ports = []
        if host and not any(x in dt for x in ("printer", "media", "renderer")):
            try:
                ports = snmp_if.collect_ports(host)
            except Exception:
                ports = []
        wan_up = str(device.get("wan_link") or device.get("connection_status") or "").lower() in ("up", "connected")
        if not ports and (device.get("wan_link") or device.get("is_igd")):
            ports = [{
                "name": "WAN",
                "type": "copper",
                "up": wan_up,
                "speed": int((device.get("link_bitrate_down") or 0) / 1000000) if device.get("link_bitrate_down") else 0,
            }]
        device["ports"] = ports
        devices.append(device)
    with _bg_lock:
        _last_devices[:] = devices
    _gena_refresh(devices)
    return devices


def add_port_mapping(device: Dict[str, Any], ext_port: int, int_ip: str, int_port: int, proto: str = "TCP", desc: str = "HostMonitor", lease: int = 0) -> Dict[str, str]:
    wan = _pick_wan_service(device) or _service(device, "WANIPConnection", "WANPPPConnection")
    if not wan:
        raise RuntimeError("WAN connection service not found")
    return _soap(wan["control_url"], wan["service_type"], "AddPortMapping", {
        "NewRemoteHost": "",
        "NewExternalPort": ext_port,
        "NewProtocol": proto.upper(),
        "NewInternalPort": int_port,
        "NewInternalClient": int_ip,
        "NewEnabled": 1,
        "NewPortMappingDescription": desc,
        "NewLeaseDuration": lease,
    })


def delete_port_mapping(device: Dict[str, Any], ext_port: int, proto: str = "TCP") -> Dict[str, str]:
    wan = _pick_wan_service(device) or _service(device, "WANIPConnection", "WANPPPConnection")
    if not wan:
        raise RuntimeError("WAN connection service not found")
    return _soap(wan["control_url"], wan["service_type"], "DeletePortMapping", {
        "NewRemoteHost": "",
        "NewExternalPort": ext_port,
        "NewProtocol": proto.upper(),
    })


def find_device(devices: List[Dict[str, Any]], udn: Optional[str] = None) -> Optional[Dict[str, Any]]:
    if udn:
        for d in devices:
            if d.get("udn") == udn:
                return d
    for d in devices:
        if d.get("is_igd"):
            return d
    return devices[0] if devices else None


def _emit(kind: str, payload: Dict[str, Any]) -> None:
    cb = _event_cb
    if cb:
        try:
            cb(kind, payload)
        except Exception:
            pass


def _ssdp_listen_loop() -> None:
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM, socket.IPPROTO_UDP)
    try:
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
        except (AttributeError, OSError):
            pass
        try:
            sock.bind(("", SSDP_PORT))
        except OSError:
            return
        joined = False
        for ip in _lan_ipv4s() or ["0.0.0.0"]:
            try:
                mreq = socket.inet_aton(SSDP_ADDR) + socket.inet_aton(ip if ip != "0.0.0.0" else "0.0.0.0")
                sock.setsockopt(socket.IPPROTO_IP, socket.IP_ADD_MEMBERSHIP, mreq)
                joined = True
            except OSError:
                continue
        if not joined:
            try:
                sock.setsockopt(
                    socket.IPPROTO_IP,
                    socket.IP_ADD_MEMBERSHIP,
                    struct.pack("4sL", socket.inet_aton(SSDP_ADDR), socket.INADDR_ANY),
                )
            except OSError:
                pass
        sock.settimeout(1.0)
        while not _bg_stop.is_set():
            try:
                data, addr = sock.recvfrom(65535)
            except socket.timeout:
                continue
            except OSError:
                break
            text = data.decode("utf-8", errors="replace")
            if not text.upper().startswith("NOTIFY"):
                continue
            parsed = _parse_ssdp(text, addr[0])
            if not parsed:
                continue
            nts = (parsed.get("nts") or "").lower()
            usn = parsed.get("usn") or ""
            udn = usn.split("::", 1)[0] if usn else ""
            if "ssdp:byebye" in nts:
                _emit("byebye", {"udn": udn, "usn": usn, "location": parsed.get("location")})
            elif "ssdp:alive" in nts or "ssdp:update" in nts:
                _emit("alive", parsed)
    finally:
        sock.close()


class _GenaHandler(BaseHTTPRequestHandler):
    def do_NOTIFY(self):  # noqa: N802
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""
        sid = (self.headers.get("SID") or "").strip()
        self.send_response(200)
        self.end_headers()
        props: Dict[str, str] = {}
        try:
            root = ET.fromstring(body.decode("utf-8", errors="replace") or "<n/>")
            for child in root.iter():
                name = _local_name(child.tag)
                if child.text and name not in ("propertyset", "property"):
                    props[name] = child.text.strip()
        except ET.ParseError:
            pass
        udn = _gena_sids.get(sid, "")
        _emit("gena", {"sid": sid, "udn": udn, "props": props})

    def log_message(self, fmt, *args):
        return


def _gena_server_loop(server: HTTPServer) -> None:
    while not _bg_stop.is_set():
        server.handle_request()


def _gena_subscribe(device: Dict[str, Any]) -> None:
    if not _gena_callback:
        return
    for svc in device.get("services") or []:
        event_url = svc.get("event_url") or ""
        st = (svc.get("service_type") or "").lower()
        if not event_url:
            continue
        if not any(x in st for x in ("wanipconnection", "wanpppconnection", "wancommon", "wlanconfiguration", "avtransport")):
            continue
        status, headers, _ = _http_raw(event_url, "SUBSCRIBE", {
            "CALLBACK": f"<{_gena_callback}>",
            "NT": "upnp:event",
            "TIMEOUT": "Second-300",
            "User-Agent": "HostMonitor-UPnP/1.1",
        }, timeout=4.0)
        sid = headers.get("SID") or headers.get("Sid") or ""
        if status in (200, 201) and sid:
            _gena_sids[sid] = device.get("udn") or ""


def _gena_refresh(devices: List[Dict[str, Any]]) -> None:
    if not _gena_callback:
        return
    for device in devices:
        try:
            _gena_subscribe(device)
        except Exception:
            pass


def start_background(on_event: Optional[Callable[[str, Dict[str, Any]], None]] = None) -> None:
    global _event_cb, _gena_callback
    _event_cb = on_event
    _bg_stop.clear()
    threading.Thread(target=_ssdp_listen_loop, name="upnp-ssdp-notify", daemon=True).start()
    lan = (_lan_ipv4s() or ["127.0.0.1"])[0]
    port = int(os.getenv("UPNP_GENA_PORT", "0") or 0)
    try:
        server = HTTPServer(("0.0.0.0", port), _GenaHandler)
        server.timeout = 1.0
        bound = server.server_address[1]
        _gena_callback = f"http://{lan}:{bound}/gena"
        threading.Thread(target=_gena_server_loop, args=(server,), name="upnp-gena", daemon=True).start()
    except OSError:
        _gena_callback = ""


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser(description="HostMonitor UPnP scan")
    parser.add_argument("--scan", action="store_true")
    parser.add_argument("--json", action="store_true")
    parser.add_argument("--timeout", type=float, default=float(os.getenv("UPNP_TIMEOUT", "8")))
    parser.add_argument("--mx", type=int, default=int(os.getenv("UPNP_MX", "3")))
    args = parser.parse_args()
    if args.scan:
        found = discover(mx=args.mx, timeout=args.timeout)
        if args.json:
            print(json.dumps(found, ensure_ascii=False, default=str))
        else:
            print(f"found {len(found)} device(s)")
            for d in found:
                print(f"  {d.get('friendly_name')} {d.get('host')} igd={d.get('is_igd')}")
