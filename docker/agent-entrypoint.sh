#!/usr/bin/env bash
# Контейнерный запуск агента HostMonitor.
# Первый старт: enroll по одноразовому коду -> node.conf -> main.py.
# node.conf лежит на volume (/data), поэтому переживает перезапуск контейнера.
set -euo pipefail

CONF_DIR="${AGENT_CONF_DIR:-/data}"
CONF="${CONF_DIR}/node.conf"
LIVE_CONF="/opt/monitoring/agent/node.conf"

log() { echo "[hm-agent] $*"; }
die() { echo "[hm-agent] ОШИБКА: $*" >&2; exit 1; }

mkdir -p "$CONF_DIR"

if [ ! -s "$CONF" ] && [ -n "${ENROLL_TOKEN:-}" ]; then
    [ -n "${MASTER_URL:-}" ] || die "задан ENROLL_TOKEN, но пуст MASTER_URL."
    log "Enroll: обмен одноразового кода на Ed25519-ключ..."
    args=(--master "$MASTER_URL" --token "$ENROLL_TOKEN" --config "$CONF")
    [ -n "${NODE_NAME:-}" ] && args+=(--name "$NODE_NAME")
    python3 /opt/monitoring/agent/enroll.py "${args[@]}" \
        || die "enroll не удался. Проверьте MASTER_URL и что код из панели ещё не истёк (15 минут)."
fi

if [ ! -s "$CONF" ] && [ -n "${NODE_ID:-}" ] && [ -n "${NODE_SECRET_B64:-}" ]; then
    log "Создаю node.conf из переменных окружения (NODE_ID/NODE_SECRET_B64)."
    {
        [ -n "${MASTER_URL:-}" ] && echo "MASTER_URL=${MASTER_URL}"
        [ -n "${NODE_NAME:-}" ] && echo "NODE_NAME=${NODE_NAME}"
        echo "NODE_ID=${NODE_ID}"
        echo "NODE_SECRET_B64=${NODE_SECRET_B64}"
        [ -n "${NODE_PUBLIC_KEY_B64:-}" ] && echo "NODE_PUBLIC_KEY_B64=${NODE_PUBLIC_KEY_B64}"
    } > "$CONF"
fi

if [ -s "$CONF" ]; then
    cp -f "$CONF" "$LIVE_CONF"
    log "Конфигурация готова: $LIVE_CONF"
elif [ -z "${MASTER_URL:-}" ]; then
    die "нет node.conf и не задан MASTER_URL. Задайте ENROLL_TOKEN из панели («Подключить ноду») или MASTER_URL вместе с NODE_ID/NODE_SECRET_B64."
else
    log "node.conf нет — работаю на переменных окружения (NODE_ID/NODE_SECRET_B64 либо NODE_TOKEN)."
fi

log "Старт агента: master=${MASTER_URL:-см. node.conf}"
exec python3 /opt/monitoring/agent/main.py
