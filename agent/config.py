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
HEALTH_PORT = int(os.getenv("HEALTH_PORT", "0"))  # порт для /health

