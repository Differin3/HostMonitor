#!/bin/sh
# Entrypoint панели HostMonitor (docker compose).
#
# Задачи:
#  1) выдать права на запись в monitoring/data/ — туда панель пишет db.local.php,
#     db.active.php, panel.local.php, web.local.php, login_attempts.json и data/ssl/;
#  2) применить схему через scripts/init_db.php — канонический путь из
#     database/README_MYSQL.md. В отличие от `mysql < schema_mysql.sql` он
#     выполняет только DDL + сиды settings/providers и НЕ заливает демо-ноды.
#
# Схема применяется на каждом старте: db_apply_schema() идемпотентна
# (CREATE TABLE IF NOT EXISTS, ошибки 1050/1060/1061/1062 глушатся).
set -eu

DOCROOT=/var/www/html
WEB_USER=www-data

log() { echo "[hm-entrypoint] $*"; }

# Панель подключает ассеты как /frontend/... относительно DocumentRoot, который
# указывает на monitoring/. Поэтому frontend/ должен лежать внутри docroot —
# при монтировании только корня репозитория нужен симлинк (второй bind-mount
# продублировал бы каталог).
if [ -d "$DOCROOT/frontend" ] && [ ! -e "$DOCROOT/monitoring/frontend" ]; then
    ln -sfn "$DOCROOT/frontend" "$DOCROOT/monitoring/frontend"
    log "frontend/ — симлинк в корень документов создан"
fi

if [ "$(id -u)" = "0" ]; then
    # Каталог приходит bind-mount'ом с хоста, поэтому права выставляем явно.
    if [ -d "$DOCROOT/monitoring/data" ]; then
        chown -R "$WEB_USER":"$WEB_USER" "$DOCROOT/monitoring/data"
        chmod -R u+rwX,g+rwX "$DOCROOT/monitoring/data"
        find "$DOCROOT/monitoring/data" -type f -name '.htaccess' -exec chmod 0644 {} +
        log "monitoring/data/ — права выставлены для $WEB_USER"
    fi
else
    log "запуск не от root: права на monitoring/data/ выставляются вручную на хосте"
fi

# Ждём БД, но ограниченно — при недоступной БД панель всё равно поднимается
# и показывает свою страницу ошибки подключения.
db_host="${DB_HOST:-mysql}"
db_port="${DB_PORT:-3306}"
db_name="${DB_NAME:-monitoring}"
i=0
while [ "$i" -lt 30 ]; do
    if php -r 'exit(@fsockopen($argv[1], (int)$argv[2]) ? 0 : 1);' "$db_host" "$db_port" 2>/dev/null; then
        log "БД $db_host:$db_port доступна"
        break
    fi
    i=$((i + 1))
    [ "$i" -eq 1 ] && log "ожидание БД $db_host:$db_port ..."
    sleep 2
done
if [ "$i" -ge 30 ]; then
    log "БД $db_host:$db_port не ответила за 60s — пропускаем применение схемы"
else
    if php "$DOCROOT/scripts/init_db.php"; then
        log "схема «$db_name» актуальна"
    else
        log "init_db.php завершился с ошибкой — панель стартует, проверьте логи"
    fi
fi

log "запуск apache"
exec "$@"
