#!/bin/bash
# Полная установка backend (API + агент) и зависимостей на Debian/Ubuntu
set -euo pipefail

log() { echo "[install] $1"; }

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
VENV_PATH="${PROJECT_ROOT}/.venv"
DB_NAME=${DB_NAME:-monitoring}
DB_USER=${DB_USER:-monitoring}
DB_PASSWORD=${DB_PASSWORD:-password}

log "Обновление пакетов"
sudo apt update
sudo apt upgrade -y

log "Установка зависимостей (Python/PostgreSQL/Docker/nginx/php)"
sudo apt install -y python3 python3-pip python3-venv git curl \
    postgresql postgresql-contrib \
    docker.io docker-compose \
    nginx php-fpm php-pgsql php-mysql \
    certbot python3-certbot-nginx

log "Включение сервисов PostgreSQL и Docker"
sudo systemctl enable --now postgresql
sudo systemctl enable --now docker

if [ ! -d "${VENV_PATH}" ]; then
    log "Создание виртуального окружения ${VENV_PATH}"
    python3 -m venv "${VENV_PATH}"
fi

log "Установка Python-зависимостей"
source "${VENV_PATH}/bin/activate"
pip install --upgrade pip
pip install -r "${PROJECT_ROOT}/requirements.txt"
deactivate

log "Инициализация базы данных ${DB_NAME}"
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASSWORD}';"
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER};"
sudo -u postgres psql -d "${DB_NAME}" -f "${PROJECT_ROOT}/database/schema.sql"

log "Выдача прав на скрипты запуска"
chmod +x "${PROJECT_ROOT}/run_master.sh" \
         "${PROJECT_ROOT}/run_agent.sh" \
         "${PROJECT_ROOT}/install.sh"

cat <<EOF
========================================
Установка завершена.
Переменные окружения по умолчанию:
  DB_NAME=${DB_NAME}
  DB_USER=${DB_USER}
  DB_PASSWORD=${DB_PASSWORD}

Запуск мастер-сервера:   ./run_master.sh
Запуск агента:           ./run_agent.sh
Для веб-интерфейса выполните scripts/install_web_debian.sh
========================================
EOF

