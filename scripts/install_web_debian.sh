#!/bin/bash
# nginx + PHP-FPM для панели HostMonitor (Debian/Ubuntu)
set -euo pipefail

log() { echo "[install-web] $1"; }

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WEB_ROOT="${WEB_ROOT:-/var/www/monitoring}"
WEB_PORT="${WEB_PORT:-80}"
NGINX_CONF_SRC="${PROJECT_ROOT}/nginx/monitoring.conf"
NGINX_CONF_DST="/etc/nginx/sites-available/monitoring"

detect_php_fpm_sock() {
    local sock
    for sock in \
        /run/php/php-fpm.sock \
        /var/run/php/php-fpm.sock \
        /run/php/php8.4-fpm.sock \
        /run/php/php8.3-fpm.sock \
        /run/php/php8.2-fpm.sock \
        /run/php/php8.1-fpm.sock \
        /run/php/php8.0-fpm.sock
    do
        if [[ -S "${sock}" ]]; then
            echo "${sock}"
            return 0
        fi
    done
    sock="$(ls /run/php/php*-fpm.sock /var/run/php/php*-fpm.sock 2>/dev/null | head -n 1 || true)"
    if [[ -n "${sock}" && -S "${sock}" ]]; then
        echo "${sock}"
        return 0
    fi
    return 1
}

log "Установка nginx и PHP-FPM"
sudo apt-get update -y
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    nginx php-fpm php-cli php-mysql php-mbstring php-xml php-curl rsync

PHP_FPM_UNIT="$(systemctl list-unit-files 'php*-fpm.service' --no-legend 2>/dev/null | awk '{print $1}' | head -n 1 || true)"
if [[ -n "${PHP_FPM_UNIT}" ]]; then
    sudo systemctl enable --now "${PHP_FPM_UNIT}"
else
    sudo systemctl enable --now php-fpm 2>/dev/null || \
        sudo systemctl enable --now php8.2-fpm 2>/dev/null || \
        sudo systemctl enable --now php8.3-fpm 2>/dev/null || true
fi

PHP_SOCK="$(detect_php_fpm_sock || true)"
if [[ -z "${PHP_SOCK}" ]]; then
    echo "[install-web] Не найден сокет PHP-FPM в /run/php. Проверьте: systemctl status 'php*-fpm'" >&2
    exit 1
fi
log "PHP-FPM сокет: ${PHP_SOCK}"

log "Копирование панели в ${WEB_ROOT}"
sudo mkdir -p "${WEB_ROOT}/frontend" "${WEB_ROOT}/database" "${WEB_ROOT}/data"
sudo rsync -a --delete \
    --exclude 'data/db.local.php' \
    --exclude 'data/db.active.php' \
    --exclude 'data/retention.last' \
    "${PROJECT_ROOT}/monitoring/" "${WEB_ROOT}/"
sudo rsync -a --delete "${PROJECT_ROOT}/frontend/" "${WEB_ROOT}/frontend/"
sudo cp -a "${PROJECT_ROOT}/database/schema_mysql.sql" "${WEB_ROOT}/database/schema_mysql.sql"
if [[ -f "${PROJECT_ROOT}/monitoring/data/db.local.php" && ! -f "${WEB_ROOT}/data/db.local.php" ]]; then
    sudo cp -a "${PROJECT_ROOT}/monitoring/data/db.local.php" "${WEB_ROOT}/data/db.local.php"
fi
sudo cp -a "${PROJECT_ROOT}/monitoring/data/.htaccess" "${WEB_ROOT}/data/.htaccess" 2>/dev/null || true
sudo cp -a "${PROJECT_ROOT}/monitoring/data/index.php" "${WEB_ROOT}/data/index.php" 2>/dev/null || true

log "nginx"
sudo cp "${NGINX_CONF_SRC}" "${NGINX_CONF_DST}"
sudo sed -i "s|unix:__PHP_FPM_SOCK__|unix:${PHP_SOCK}|g" "${NGINX_CONF_DST}"
sudo sed -i "s|listen 80;|listen ${WEB_PORT};|g" "${NGINX_CONF_DST}"
sudo ln -sf "${NGINX_CONF_DST}" /etc/nginx/sites-enabled/monitoring
if [[ -f /etc/nginx/sites-enabled/default ]]; then
    sudo rm /etc/nginx/sites-enabled/default
fi

log "Права www-data"
sudo chown -R www-data:www-data "${WEB_ROOT}"
sudo chmod 750 "${WEB_ROOT}/data"
if [[ -f "${WEB_ROOT}/data/db.local.php" ]]; then
    sudo chmod 640 "${WEB_ROOT}/data/db.local.php"
fi

sudo nginx -t
sudo systemctl reload nginx || sudo systemctl restart nginx

log "Готово. Откройте http://<ip>:${WEB_PORT} — при первом заходе мастер создаст администратора панели."
