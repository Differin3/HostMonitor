#!/bin/bash
# Установка зависимостей панели и MySQL на Debian/Ubuntu.
# Базу и таблицы создаёт этот скрипт. Администратора панели — мастер setup.php в браузере.
set -euo pipefail

log() { echo "[install] $1"; }

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
VENV_PATH="${PROJECT_ROOT}/.venv"
DB_NAME="${DB_NAME:-monitoring}"
DB_USER="${DB_USER:-monitoring}"
DB_HOST="${DB_HOST:-localhost}"
DB_PORT="${DB_PORT:-3306}"
SCHEMA="${PROJECT_ROOT}/database/schema_mysql.sql"
DATA_DIR="${PROJECT_ROOT}/monitoring/data"
DB_LOCAL="${DATA_DIR}/db.local.php"

if [[ ! -f "${SCHEMA}" ]]; then
    echo "[install] Не найден ${SCHEMA}" >&2
    exit 1
fi

# ----------------------------------------------------------------------
# Интерактивный выбор типа базы данных
# ----------------------------------------------------------------------
USE_LOCAL_DB=""
if [[ -z "${DB_PASSWORD:-}" && -z "${DB_HOST:-}" ]]; then
    echo "Выберите способ настройки базы данных:"
    echo "  1) Установить MariaDB локально и создать базу автоматически"
    echo "  2) Использовать уже существующую удалённую БД (или локальную, но не управляемую скриптом)"
    read -p "Введите 1 или 2: " db_choice
    case "$db_choice" in
        1) USE_LOCAL_DB=true ;;
        2) USE_LOCAL_DB=false ;;
        *) echo "Неверный выбор, завершение."; exit 1 ;;
    esac
else
    # Если пароль или хост заданы через переменные окружения – считаем, что это удалённая БД
    USE_LOCAL_DB=false
fi

# Если выбрана локальная БД, запрашиваем пароль только если он не задан
if [[ "$USE_LOCAL_DB" == true ]]; then
    if [[ -z "${DB_PASSWORD:-}" ]]; then
        DB_PASSWORD="$(openssl rand -hex 16)"
        PASSWORD_GENERATED=1
    else
        PASSWORD_GENERATED=0
    fi
else
    # Удалённая БД – запрашиваем параметры, если они не заданы через окружение
    if [[ -z "${DB_HOST:-}" ]]; then
        read -p "Введите хост БД (по умолчанию localhost): " input_host
        DB_HOST="${input_host:-localhost}"
    fi
    if [[ -z "${DB_PORT:-}" ]]; then
        read -p "Введите порт БД (по умолчанию 3306): " input_port
        DB_PORT="${input_port:-3306}"
    fi
    if [[ -z "${DB_NAME:-}" ]]; then
        read -p "Введите имя базы данных (по умолчанию monitoring): " input_name
        DB_NAME="${input_name:-monitoring}"
    fi
    if [[ -z "${DB_USER:-}" ]]; then
        read -p "Введите пользователя БД (по умолчанию monitoring): " input_user
        DB_USER="${input_user:-monitoring}"
    fi
    if [[ -z "${DB_PASSWORD:-}" ]]; then
        read -s -p "Введите пароль пользователя БД: " DB_PASSWORD
        echo
        if [[ -z "$DB_PASSWORD" ]]; then
            echo "Пароль не может быть пустым." >&2
            exit 1
        fi
    fi
    PASSWORD_GENERATED=0
fi

log "Обновление индексов пакетов"
sudo apt-get update -y

# Устанавливаем общие пакеты (Python, PHP, git и т.д.)
log "Установка Python, PHP, git, curl, openssl"
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    python3 python3-pip python3-venv git curl openssl \
    php-cli php-mysql php-mbstring php-xml php-curl

# Если выбрана локальная БД – устанавливаем MariaDB и запускаем
if [[ "$USE_LOCAL_DB" == true ]]; then
    log "Установка MariaDB"
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
        mariadb-server mariadb-client

    log "Запуск MariaDB"
    sudo systemctl enable --now mariadb
    for _ in $(seq 1 30); do
        if sudo mysqladmin ping --silent 2>/dev/null; then
            break
        fi
        sleep 1
    done
    if ! sudo mysqladmin ping --silent 2>/dev/null; then
        echo "[install] MariaDB не отвечает" >&2
        exit 1
    fi

    mysql_root() {
        sudo mysql --protocol=socket -u root "$@"
    }

    log "Создание базы ${DB_NAME} и пользователя ${DB_USER}"
    mysql_root -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql_root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
    mysql_root -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';"
    mysql_root -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';"
    mysql_root -e "ALTER USER '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';"
    mysql_root -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    mysql_root -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';"
    mysql_root -e "FLUSH PRIVILEGES;"
fi

# Создаём каталог для данных и генерируем db.local.php
mkdir -p "${DATA_DIR}"
HM_DB_FILE="${DB_LOCAL}" \
HM_DB_HOST="${DB_HOST}" \
HM_DB_PORT="${DB_PORT}" \
HM_DB_NAME="${DB_NAME}" \
HM_DB_USER="${DB_USER}" \
HM_DB_PASSWORD="${DB_PASSWORD}" \
php -r '
$path = getenv("HM_DB_FILE");
$cfg = [
    "host" => getenv("HM_DB_HOST") ?: "localhost",
    "port" => getenv("HM_DB_PORT") ?: "3306",
    "name" => getenv("HM_DB_NAME") ?: "monitoring",
    "user" => getenv("HM_DB_USER") ?: "monitoring",
    "password" => (string)getenv("HM_DB_PASSWORD"),
    "replica_enabled" => false,
    "replica_failback" => true,
    "replica" => [
        "host" => "",
        "port" => "3306",
        "name" => "",
        "user" => "",
        "password" => "",
    ],
];
$php = "<?php\n// Сгенерировано install.sh. Не коммитить.\nreturn " . var_export($cfg, true) . ";\n";
if (file_put_contents($path, $php, LOCK_EX) === false) {
    fwrite(STDERR, "Не удалось записать {$path}\n");
    exit(1);
}
chmod($path, 0600);
'

# Если выбрана локальная БД – схема уже создана. Для удалённой спрашиваем.
if [[ "$USE_LOCAL_DB" == false ]]; then
    echo "Вы хотите применить схему базы данных (создать таблицы) на удалённой БД?"
    read -p "Применить схему? (y/n): " apply_schema
    if [[ "$apply_schema" == "y" || "$apply_schema" == "Y" ]]; then
        log "Применение схемы"
        php "${PROJECT_ROOT}/scripts/init_db.php"
    else
        log "Пропускаем применение схемы. Таблицы нужно создать вручную или позже запустить scripts/init_db.php"
    fi
else
    log "Применение схемы (локальная БД)"
    php "${PROJECT_ROOT}/scripts/init_db.php"
fi

# Создание venv (панель на PHP, venv нужен для python_web_server.py)
if [[ ! -d "${VENV_PATH}" ]]; then
    log "Создание Python venv"
    python3 -m venv "${VENV_PATH}"
fi

# Установка прав на исполнение
chmod +x "${PROJECT_ROOT}/install.sh" \
         "${PROJECT_ROOT}/scripts/install_panel.sh" \
         "${PROJECT_ROOT}/scripts/install_agent.sh" \
         "${PROJECT_ROOT}/scripts/install_web_debian.sh" \
         "${PROJECT_ROOT}/scripts/probe_databases.php" \
         "${PROJECT_ROOT}/run_agent.sh" 2>/dev/null || true

# Сохраняем учётные данные в отдельный файл
CREDS="${PROJECT_ROOT}/.db-credentials"
umask 077
cat > "${CREDS}" <<EOF
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASSWORD=${DB_PASSWORD}
EOF
chmod 600 "${CREDS}"

# Финальное сообщение
cat <<EOF

========================================
$(if [[ "$USE_LOCAL_DB" == true ]]; then
    echo "MySQL готова. Базу создавать вручную не нужно."
else
    echo "Настройка для удалённой БД завершена. Убедитесь, что БД доступна."
fi)

  База:       ${DB_NAME}
  Пользователь: ${DB_USER}
  Хост:       ${DB_HOST}:${DB_PORT}
  Конфиг:     ${DB_LOCAL}
  Креды:      ${CREDS}

Администратора панели задайте в браузере на странице первого запуска (setup.php).
$( [[ "${PASSWORD_GENERATED}" == "1" ]] && echo "Пароль MySQL сгенерирован и записан в ${CREDS} — сохраните его." )
$( [[ "$USE_LOCAL_DB" == false && "${apply_schema:-}" != "y" && "${apply_schema:-}" != "Y" ]] && echo "Схема БД не была применена. Запустите php scripts/init_db.php для создания таблиц." )

Дальше:
  панель + nginx:  sudo scripts/install_web_debian.sh
  или полный цикл: bash scripts/install_panel.sh
========================================
EOF