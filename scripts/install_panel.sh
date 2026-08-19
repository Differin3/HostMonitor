#!/bin/bash
# Установка панели HostMonitor: репозиторий, MySQL (локально или внешняя), nginx/Python-сервер.
set -euo pipefail

REPO_URL="${1:-https://github.com/Differin3/HostMonitor}"
BRANCH="${2:-main}"
INSTALL_DIR="${3:-/opt/monitoring}"

echo ""
echo "=========================================="
echo "Веб-сервер панели:"
echo "  1) nginx + PHP-FPM  (production)"
echo "  2) Python + php-cgi (dev / без nginx)"
read -r -p "Ваш выбор [1]: " WEB_SERVER_CHOICE
WEB_SERVER_CHOICE="${WEB_SERVER_CHOICE:-1}"

if [[ "${WEB_SERVER_CHOICE}" == "2" ]]; then
    WEB_SERVER="python"
    DEFAULT_PORT=8080
else
    WEB_SERVER="nginx"
    DEFAULT_PORT=80
fi

read -r -p "Порт веб-интерфейса [${DEFAULT_PORT}]: " WEB_PORT
WEB_PORT="${WEB_PORT:-${DEFAULT_PORT}}"
echo "Сервер: ${WEB_SERVER}, порт: ${WEB_PORT}"
echo "=========================================="

# ---- Выбор типа базы данных ----
echo "Тип базы данных:"
echo "  1) Установить MariaDB локально (автоматически)"
echo "  2) Использовать уже существующую удалённую/локальную БД"
read -r -p "Ваш выбор [1]: " DB_CHOICE
DB_CHOICE="${DB_CHOICE:-1}"

DB_INSTALL_LOCAL=0
if [[ "$DB_CHOICE" == "1" ]]; then
    DB_INSTALL_LOCAL=1
    # Параметры по умолчанию (можно переопределить через переменные окружения)
    DB_HOST="${DB_HOST:-localhost}"
    DB_PORT="${DB_PORT:-3306}"
    DB_NAME="${DB_NAME:-monitoring}"
    DB_USER="${DB_USER:-monitoring}"
    # Пароль сгенерирует install.sh, если не задан
else
    # Удалённая / существующая БД – запрашиваем параметры
    read -r -p "Хост БД [localhost]: " input_host
    DB_HOST="${input_host:-localhost}"
    read -r -p "Порт БД [3306]: " input_port
    DB_PORT="${input_port:-3306}"
    read -r -p "Имя базы данных [monitoring]: " input_name
    DB_NAME="${input_name:-monitoring}"
    read -r -p "Пользователь БД [monitoring]: " input_user
    DB_USER="${input_user:-monitoring}"
    read -r -s -p "Пароль пользователя БД: " DB_PASSWORD
    echo
    if [[ -z "$DB_PASSWORD" ]]; then
        echo "Пароль не может быть пустым." >&2
        exit 1
    fi
fi
echo "=========================================="

# ----------------------------------------------------------------------
echo "[install_panel] Установка базовых пакетов..."
sudo apt-get update -y

# Общие пакеты (всегда нужны)
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    git curl openssl python3 python3-venv python3-pip \
    php-cli php-mysql php-mbstring php-xml php-curl

# Дополнительные пакеты для веб-сервера
if [[ "${WEB_SERVER}" == "nginx" ]]; then
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y nginx php-fpm
else
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y php-cgi
fi

# Установка MariaDB только если выбрана локальная БД
if [[ "$DB_INSTALL_LOCAL" == "1" ]]; then
    echo "[install_panel] Установка MariaDB (локальная БД)"
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client
    sudo systemctl enable --now mariadb
else
    echo "[install_panel] Пропускаем установку MariaDB (используется внешняя БД)"
    # Для удалённой БД можно установить только клиент (опционально)
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-client || true
fi

# ----------------------------------------------------------------------
echo "[install_panel] Клонирование репозитория → ${INSTALL_DIR}"
if [[ -d "${INSTALL_DIR}/.git" ]]; then
    sudo git -C "${INSTALL_DIR}" fetch --all --prune
    sudo git -C "${INSTALL_DIR}" checkout "${BRANCH}"
    sudo git -C "${INSTALL_DIR}" pull --ff-only origin "${BRANCH}"
else
    sudo mkdir -p "${INSTALL_DIR}"
    sudo chown "$(whoami)":"$(whoami)" "${INSTALL_DIR}"
    git clone -b "${BRANCH}" --depth 1 "${REPO_URL}" "${INSTALL_DIR}"
fi

cd "${INSTALL_DIR}"
chmod +x install.sh scripts/install_web_debian.sh scripts/install_agent.sh 2>/dev/null || true

# ----------------------------------------------------------------------
echo "[install_panel] Запуск install.sh с параметрами БД"

# Экспортируем переменные, чтобы install.sh не задавал вопросы
export DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DB_INSTALL_LOCAL

# Запускаем install.sh с правами sudo, сохраняя окружение
sudo -E env "DB_HOST=$DB_HOST" "DB_PORT=$DB_PORT" "DB_NAME=$DB_NAME" \
          "DB_USER=$DB_USER" "DB_PASSWORD=$DB_PASSWORD" \
          "DB_INSTALL_LOCAL=$DB_INSTALL_LOCAL" \
          ./install.sh

# ----------------------------------------------------------------------
echo "[install_panel] Настройка пользователя monitoring"
if ! id "monitoring" &>/dev/null; then
    sudo useradd -r -s /usr/sbin/nologin -d "${INSTALL_DIR}" monitoring 2>/dev/null || \
        sudo useradd -r -s /bin/bash -d "${INSTALL_DIR}" monitoring 2>/dev/null || true
fi
if id "monitoring" &>/dev/null; then
    sudo chown -R monitoring:monitoring "${INSTALL_DIR}"
    getent group adm >/dev/null && sudo usermod -aG adm monitoring || true
    getent group systemd-journal >/dev/null && sudo usermod -aG systemd-journal monitoring || true
    SERVICE_USER="monitoring"
else
    sudo chown -R "$(whoami)":"$(whoami)" "${INSTALL_DIR}" || true
    SERVICE_USER="root"
fi

# ----------------------------------------------------------------------
if [[ "${WEB_SERVER}" == "nginx" ]]; then
    echo "[install_panel] Настройка nginx + PHP-FPM"
    export WEB_PORT
    sudo -E scripts/install_web_debian.sh
else
    echo "[install_panel] Настройка systemd monitoring-web (Python)"
    if [[ -f systemd/monitoring-web.service ]]; then
        sudo cp systemd/monitoring-web.service /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|WorkingDirectory=.*|WorkingDirectory=${INSTALL_DIR}|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|ExecStart=.*|ExecStart=${INSTALL_DIR}/.venv/bin/python3 ${INSTALL_DIR}/scripts/python_web_server.py|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"WEB_PORT=.*\"|Environment=\"WEB_PORT=${WEB_PORT}\"|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"WEB_HOST=.*\"|Environment=\"WEB_HOST=0.0.0.0\"|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"PATH=.*\"|Environment=\"PATH=${INSTALL_DIR}/.venv/bin:/usr/local/bin:/usr/bin:/bin\"|g" /etc/systemd/system/monitoring-web.service
        if grep -q "^User=" /etc/systemd/system/monitoring-web.service; then
            sudo sed -i "s|^User=.*|User=${SERVICE_USER}|g" /etc/systemd/system/monitoring-web.service
        else
            sudo sed -i "/^\[Service\]/a User=${SERVICE_USER}" /etc/systemd/system/monitoring-web.service
        fi
        # Подставляем параметры БД из учётных, если есть
        if [[ -f "${INSTALL_DIR}/.db-credentials" ]]; then
            while IFS='=' read -r key val; do
                [[ "${key}" == DB_* ]] || continue
                case "${key}" in
                    DB_NAME) DB_NAME="${val}" ;;
                    DB_USER) DB_USER="${val}" ;;
                    DB_PASSWORD) DB_PASSWORD="${val}" ;;
                    DB_HOST) DB_HOST="${val}" ;;
                esac
            done < <(sudo grep -E '^DB_(HOST|PORT|NAME|USER|PASSWORD)=' "${INSTALL_DIR}/.db-credentials")
            sudo sed -i "s|Environment=\"DB_NAME=.*\"|Environment=\"DB_NAME=${DB_NAME}\"|g" /etc/systemd/system/monitoring-web.service
            sudo sed -i "s|Environment=\"DB_USER=.*\"|Environment=\"DB_USER=${DB_USER}\"|g" /etc/systemd/system/monitoring-web.service
            sudo sed -i "s|Environment=\"DB_PASSWORD=.*\"|Environment=\"DB_PASSWORD=${DB_PASSWORD}\"|g" /etc/systemd/system/monitoring-web.service
            sudo sed -i "s|Environment=\"DB_HOST=.*\"|Environment=\"DB_HOST=${DB_HOST:-localhost}\"|g" /etc/systemd/system/monitoring-web.service
        fi
        SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
        if [[ -n "${SERVER_IP}" ]]; then
            if grep -q 'Environment="MASTER_URL=' /etc/systemd/system/monitoring-web.service; then
                sudo sed -i "s|Environment=\"MASTER_URL=.*\"|Environment=\"MASTER_URL=http://${SERVER_IP}:${WEB_PORT}\"|g" /etc/systemd/system/monitoring-web.service
            fi
        fi
        sudo systemctl daemon-reload
        sudo systemctl enable --now monitoring-web
    fi
fi

# ----------------------------------------------------------------------
echo "[install_panel] Настройка cron"
PHP_BIN="$(command -v php || echo /usr/bin/php)"
sudo tee /etc/cron.d/hostmonitor-cleanup >/dev/null <<EOF
15 3 * * * root ${PHP_BIN} ${INSTALL_DIR}/scripts/cleanup_logs.php >/var/log/hostmonitor-cleanup.log 2>&1
*/5 * * * * root ${PHP_BIN} ${INSTALL_DIR}/scripts/probe_databases.php >/var/log/hostmonitor-dbmon.log 2>&1
EOF
sudo chmod 644 /etc/cron.d/hostmonitor-cleanup

HOST_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || hostname)"
echo ""
echo "=========================================="
echo "Панель установлена."
echo "  http://${HOST_IP}:${WEB_PORT}"
echo ""
echo "База данных:"
if [[ "$DB_INSTALL_LOCAL" == "1" ]]; then
    echo "  Локальная MariaDB (создана автоматически)."
else
    echo "  Внешняя БД (${DB_HOST}:${DB_PORT}) — убедитесь, что она доступна."
fi
echo "  Имя: ${DB_NAME}, пользователь: ${DB_USER}"
echo ""
echo "Откройте адрес в браузере и задайте логин администратора панели."
echo "Агент на этом же сервере не включается — его ставят на ноды: scripts/install_agent.sh"
echo "=========================================="