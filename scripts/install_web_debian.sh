#!/bin/bash
# Установка и настройка nginx + PHP-FPM для веб-интерфейса на Debian/Ubuntu
set -euo pipefail

log() { echo "[install-web] $1"; }

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WEB_ROOT="${WEB_ROOT:-/var/www/monitoring}"
NGINX_CONF_SRC="${PROJECT_ROOT}/nginx/monitoring.conf"
NGINX_CONF_DST="/etc/nginx/sites-available/monitoring"

log "Установка nginx и PHP-FPM"
sudo apt update
sudo apt install -y nginx php-fpm php-pgsql php-mysql php-cli

log "Копирование PHP-приложения в ${WEB_ROOT}"
sudo mkdir -p "${WEB_ROOT}"
sudo rsync -a --delete "${PROJECT_ROOT}/monitoring/" "${WEB_ROOT}/"

log "Копирование frontend в ${WEB_ROOT}/frontend"
sudo mkdir -p "${WEB_ROOT}/frontend"
sudo rsync -a --delete "${PROJECT_ROOT}/frontend/" "${WEB_ROOT}/frontend/"

log "Развёртывание nginx-конфигурации"
sudo cp "${NGINX_CONF_SRC}" "${NGINX_CONF_DST}"
sudo ln -sf "${NGINX_CONF_DST}" /etc/nginx/sites-enabled/monitoring
if [ -f /etc/nginx/sites-enabled/default ]; then
    sudo rm /etc/nginx/sites-enabled/default
fi

log "Настройка прав владельца www-data"
sudo chown -R www-data:www-data "${WEB_ROOT}"

log "Проверка конфигурации nginx"
sudo nginx -t
log "Перезапуск nginx"
sudo systemctl restart nginx

log "Веб-интерфейс установлен. Откройте http://<ваш-домен> в браузере."
