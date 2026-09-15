#!/bin/bash
# Настройка списка доменов в nginx-конфиге (безопасная замена server_name)
set -euo pipefail

if [ $# -lt 1 ]; then
    echo "Использование: $0 example.com [www.example.com ...]"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
CONF="${SCRIPT_DIR}/../nginx/monitoring.conf"
SERVER_NAMES="$*"
TIMESTAMP="$(date +%Y%m%d%H%M%S)"
BACKUP="${CONF}.bak.${TIMESTAMP}"

if [ ! -f "$CONF" ]; then
    echo "Не найден конфиг: $CONF"
    exit 1
fi

echo "Создаю резервную копию ${BACKUP}"
sudo cp "$CONF" "$BACKUP"

echo "Прописываю server_name ${SERVER_NAMES}"
sudo perl -0pi -e "s/server_name\s+[^;]+;/server_name ${SERVER_NAMES};/" "$CONF"

echo "Готово. Проверьте конфиг: sudo nginx -t && sudo systemctl reload nginx"


