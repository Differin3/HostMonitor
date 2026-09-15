"""Fill manufacturer from model and model from manufacturer + UPnP strings."""
from __future__ import annotations

import re
from typing import Any, Dict, List, Optional, Tuple

GENERIC_NAME = re.compile(
    r"^(router|internet\s*gateway(?:\s*device)?|root\s*device|upnp(?:\s*device)?|gateway|device)$",
    re.I,
)

# (regex on joined blob, manufacturer, canonical model)
RULES: List[Tuple[str, str, str]] = [
    (r"ccr2004-1g-12s\+2xs", "MikroTik", "CCR2004-1G-12S+2XS"),
    (r"ccr2004", "MikroTik", "CCR2004"),
    (r"ccr2116", "MikroTik", "CCR2116-12G-4S+"),
    (r"rb4011", "MikroTik", "RB4011iGS+RM"),
    (r"rb3011", "MikroTik", "RB3011UiAS-RM"),
    (r"rb2011", "MikroTik", "RB2011UiAS-RM"),
    (r"crs328-24p-4s\+", "MikroTik", "CRS328-24P-4S+"),
    (r"crs326-24g-2s\+", "MikroTik", "CRS326-24G-2S+"),
    (r"crs\d+", "MikroTik", ""),
    (r"\bhap\b|rbd52|rb952|rb750", "MikroTik", "hAP"),
    (r"mikrotik|routerboard|routeros|\bccr\d|\brb\d", "MikroTik", ""),
    (r"c9200l-24p-4g", "Cisco Systems", "C9200L-24P-4G"),
    (r"c9200l-48p-4x", "Cisco Systems", "C9200L-48P-4X"),
    (r"c9200l-24t-4g", "Cisco Systems", "C9200L-24T-4G"),
    (r"c9200", "Cisco Systems", ""),
    (r"isr4331", "Cisco Systems", "ISR4331/K9"),
    (r"isr4321", "Cisco Systems", "ISR4321/K9"),
    (r"isr4351", "Cisco Systems", "ISR4351/K9"),
    (r"isr4431", "Cisco Systems", "ISR4431/K9"),
    (r"c2960", "Cisco Systems", ""),
    (r"cisco|meraki|linksys", "Cisco Systems", ""),
    (r"s5735-l24p4x", "Huawei", "S5735-L24P4X-A"),
    (r"s5735-l48p4x", "Huawei", "S5735-L48P4X-A"),
    (r"s5735", "Huawei", ""),
    (r"ar6120", "Huawei", "AR6120-S"),
    (r"ar6\d{3}", "Huawei", ""),
    (r"huawei", "Huawei", ""),
    (r"keenetic", "Keenetic", ""),
    (r"tp-?link|archer|tl-w[rs]|tl-sg|deco|mercusys", "TP-Link", ""),
    (r"d-?link|dir-\d|dgs-\d", "D-Link", ""),
    (r"asus|rt-ax|rt-ac", "ASUS", ""),
    (r"zyxel", "Zyxel", ""),
    (r"ubiquiti|unifi|edgerouter|\budm\b|\busg\b", "Ubiquiti", ""),
    (r"netgear|nighthawk", "NETGEAR", ""),
    (r"xiaomi|miwifi|redmi", "Xiaomi", ""),
    (r"zte|zxhn", "ZTE", ""),
    (r"eltex", "Eltex", ""),
    (r"fortinet|fortigate", "Fortinet", ""),
    (r"juniper", "Juniper", ""),
    (r"aruba|\bhpe\b", "HPE Aruba", ""),
    (r"synology", "Synology", ""),
    (r"qnap", "QNAP", ""),
    (r"avm|fritz", "AVM", ""),
    (r"tenda", "Tenda", ""),
    (r"openwrt|lede", "OpenWrt", ""),
]


def _blob(device: Dict[str, Any]) -> str:
    parts = [
        device.get("manufacturer"),
        device.get("model_name"),
        device.get("model_number"),
        device.get("friendly_name"),
        device.get("model_description"),
        device.get("ssdp_server"),
        device.get("software"),
    ]
    return " ".join(str(p) for p in parts if p).lower()


def _empty(value: Optional[str]) -> bool:
    v = (value or "").strip()
    return v == "" or v in ("—", "-", "N/A", "n/a", "unknown")


def _vendor_of_mfr(name: str) -> str:
    s = name.lower()
    mapping = (
        ("cisco", "cisco"), ("meraki", "cisco"),
        ("huawei", "huawei"),
        ("mikrotik", "mikrotik"), ("routerboard", "mikrotik"),
        ("keenetic", "keenetic"),
        ("tp-link", "tplink"), ("tplink", "tplink"),
        ("d-link", "dlink"), ("dlink", "dlink"),
        ("asus", "asus"),
        ("zyxel", "zyxel"),
        ("ubiquiti", "ubiquiti"),
        ("netgear", "netgear"),
        ("xiaomi", "xiaomi"),
        ("zte", "zte"),
        ("eltex", "eltex"),
        ("fortinet", "fortinet"),
        ("juniper", "juniper"),
        ("aruba", "aruba"),
        ("synology", "synology"),
        ("qnap", "qnap"),
        ("tenda", "tenda"),
    )
    for needle, vendor in mapping:
        if needle in s:
            return vendor
    return ""


def enrich_identity(device: Dict[str, Any]) -> Dict[str, Any]:
    blob = _blob(device)
    matched_mfr = ""
    matched_model = ""
    for pattern, manufacturer, model in RULES:
        if re.search(pattern, blob):
            matched_mfr = manufacturer
            matched_model = model
            break

    if _empty(device.get("manufacturer")) and matched_mfr:
        device["manufacturer"] = matched_mfr

    if _empty(device.get("model_name")):
        if not _empty(device.get("model_number")):
            device["model_name"] = str(device.get("model_number")).strip()
        elif matched_model:
            device["model_name"] = matched_model
        else:
            friendly = str(device.get("friendly_name") or "").strip()
            if friendly and not GENERIC_NAME.match(friendly):
                device["model_name"] = friendly

    if _empty(device.get("manufacturer")) and not _empty(device.get("model_name")):
        model_blob = str(device.get("model_name")).lower()
        for pattern, manufacturer, _model in RULES:
            if re.search(pattern, model_blob):
                device["manufacturer"] = manufacturer
                break

    if not _empty(device.get("manufacturer")) and _empty(device.get("model_name")):
        vendor = _vendor_of_mfr(str(device.get("manufacturer")))
        for pattern, manufacturer, model in RULES:
            if _vendor_of_mfr(manufacturer) != vendor:
                continue
            if not model:
                continue
            if re.search(pattern, blob):
                device["model_name"] = model
                break

    return device
