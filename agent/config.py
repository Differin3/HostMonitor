# Конфигурация агента
import os

MASTER_URL = os.getenv("MASTER_URL", "https://master-server:8000")
NODE_NAME = os.getenv("NODE_NAME", "node-1")
NODE_TOKEN = os.getenv("NODE_TOKEN", "")
COLLECT_INTERVAL = int(os.getenv("COLLECT_INTERVAL", "60"))  # секунды

# TLS
TLS_VERIFY = os.getenv("TLS_VERIFY", "true").lower() == "true"
TLS_CERT_PATH = os.getenv("TLS_CERT_PATH", "")

# Надёжность
MAX_RETRIES = int(os.getenv("MAX_RETRIES", "3"))  # количество повторов
RETRY_DELAY = int(os.getenv("RETRY_DELAY", "5"))  # пауза между повторами (секунды)

# Health-check HTTP сервер агента (0 = выключен)
UPNP_ENABLED = os.getenv("UPNP_ENABLED", "true").lower() == "true"
UPNP_MX = int(os.getenv("UPNP_MX", "3"))
UPNP_TIMEOUT = float(os.getenv("UPNP_TIMEOUT", "8"))
UPNP_INTERVAL_CYCLES = int(os.getenv("UPNP_INTERVAL_CYCLES", "2"))
UPNP_GENA_PORT = int(os.getenv("UPNP_GENA_PORT", "0"))

# SNMP / LLDP
SNMP_ENABLED = os.getenv("SNMP_ENABLED", "true").lower() == "true"
SNMP_COMMUNITY = os.getenv("SNMP_COMMUNITY", "public")
SNMP_TIMEOUT = float(os.getenv("SNMP_TIMEOUT", "0.8"))
SNMP_TARGETS = os.getenv("SNMP_TARGETS", "")
LLDP_PASSIVE = os.getenv("LLDP_PASSIVE", "true").lower() == "true"
LLDP_LISTEN_INTERFACE = os.getenv("LLDP_LISTEN_INTERFACE", "")
LLDP_ACTIVE_POLL_KNOWN = os.getenv("LLDP_ACTIVE_POLL_KNOWN", "true").lower() == "true"

