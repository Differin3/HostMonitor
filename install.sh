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

log "Установка зависимостей (Python/MySQL/Docker/nginx/php)"
sudo apt install -y python3 python3-pip python3-venv git curl \
    mysql-server \
    docker.io docker-compose \
    nginx php-fpm php-mysql \
    certbot python3-certbot-nginx

log "Включение сервисов MySQL и Docker"
sudo systemctl enable --now mysql
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
mysql -u root -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || \
    sudo mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';" 2>/dev/null || \
    sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
mysql -u root -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';" 2>/dev/null || \
    sudo mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
mysql -u root -e "FLUSH PRIVILEGES;" 2>/dev/null || sudo mysql -e "FLUSH PRIVILEGES;"
mysql -u root "${DB_NAME}" < "${PROJECT_ROOT}/schema_mysql.sql" 2>/dev/null || \
    sudo mysql "${DB_NAME}" < "${PROJECT_ROOT}/schema_mysql.sql"

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

