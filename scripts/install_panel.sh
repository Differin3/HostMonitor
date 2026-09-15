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

echo ""
echo "Адрес прослушивания (bind):"
echo "  1) 0.0.0.0  — все интерфейсы (локальный + публичный IP)"
echo "  2) 127.0.0.1 — только локально (без доступа из сети)"
echo "  3) Указать конкретный IP вручную"
read -r -p "Ваш выбор [1]: " BIND_CHOICE
BIND_CHOICE="${BIND_CHOICE:-1}"
case "${BIND_CHOICE}" in
    1) WEB_HOST="0.0.0.0" ;;
    2) WEB_HOST="127.0.0.1" ;;
    3)
        read -r -p "Введите IP-адрес для прослушивания: " WEB_HOST_INPUT
        WEB_HOST="${WEB_HOST_INPUT:-0.0.0.0}"
        ;;
    *) WEB_HOST="0.0.0.0" ;;
esac
echo "Сервер: ${WEB_SERVER}, хост: ${WEB_HOST}, порт: ${WEB_PORT}"
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
    DB_HOST="${DB_HOST:-localhost}"
    DB_PORT="${DB_PORT:-3306}"
    DB_NAME="${DB_NAME:-monitoring}"
    DB_USER="${DB_USER:-monitoring}"
else
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

sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    git curl openssl python3 python3-venv python3-pip \
    php-cli php-mysql php-mbstring php-xml php-curl

if [[ "${WEB_SERVER}" == "nginx" ]]; then
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y nginx php-fpm
else
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y php-cgi
fi

if [[ "$DB_INSTALL_LOCAL" == "1" ]]; then
    echo "[install_panel] Установка MariaDB (локальная БД)"
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mariadb-server mariadb-client
    sudo systemctl enable --now mariadb
else
    echo "[install_panel] Пропускаем установку MariaDB (используется внешняя БД)"
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
chmod +x install.sh scripts/install_web_debian.sh scripts/install_agent.sh scripts/panel_git.sh 2>/dev/null || true

# ----------------------------------------------------------------------
echo "[install_panel] Запуск install.sh с параметрами БД"
export DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD DB_INSTALL_LOCAL
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
echo "[install_panel] Обновление панели из git (panel_git.sh + sudoers)"
PANEL_GIT_SCRIPT="${INSTALL_DIR}/scripts/panel_git.sh"
if [[ -f "${PANEL_GIT_SCRIPT}" ]]; then
    sudo chmod 755 "${PANEL_GIT_SCRIPT}"
fi
if id "monitoring" &>/dev/null; then
    PHP_USERS=()
    for u in www-data nginx monitoring; do
        id "${u}" &>/dev/null && PHP_USERS+=("${u}")
    done
    if [[ ${#PHP_USERS[@]} -gt 0 && -f "${PANEL_GIT_SCRIPT}" ]]; then
        SUDOERS_FILE="/etc/sudoers.d/hostmonitor-panel-git"
        {
            echo "# HostMonitor: обновление панели из веб-интерфейса"
            for PHP_USER in "${PHP_USERS[@]}"; do
                if [[ "${PHP_USER}" != "monitoring" ]]; then
                    echo "${PHP_USER} ALL=(monitoring) NOPASSWD: ${PANEL_GIT_SCRIPT}"
                fi
            done
        } | sudo tee "${SUDOERS_FILE}" >/dev/null
        sudo chmod 440 "${SUDOERS_FILE}"
        sudo visudo -cf "${SUDOERS_FILE}" >/dev/null
        echo "[install_panel] sudoers: ${PHP_USERS[*]} → panel_git.sh"
    fi
    # safe.directory
    sudo -u monitoring git config --global --add safe.directory "${INSTALL_DIR}" 2>/dev/null || true
    PANEL_CFG="${INSTALL_DIR}/monitoring/data/panel.local.php"
    if [[ ! -f "${PANEL_CFG}" ]]; then
        sudo tee "${PANEL_CFG}" >/dev/null <<EOF
<?php
return [
    'repo_root' => '${INSTALL_DIR}',
    'git_wrapper' => '${PANEL_GIT_SCRIPT}',
    'git_sudo_user' => 'monitoring',
];
EOF
        sudo chmod 600 "${PANEL_CFG}"
        sudo chown "${SERVICE_USER}:${SERVICE_USER}" "${PANEL_CFG}" 2>/dev/null || true
    fi
fi

# ----------------------------------------------------------------------
if [[ "${WEB_SERVER}" == "nginx" ]]; then
    echo "[install_panel] Настройка nginx + PHP-FPM"
    export WEB_PORT WEB_HOST
    sudo -E scripts/install_web_debian.sh
else
    echo "[install_panel] Настройка systemd monitoring-web (Python)"
    if [[ -f systemd/monitoring-web.service ]]; then
        sudo cp systemd/monitoring-web.service /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|WorkingDirectory=.*|WorkingDirectory=${INSTALL_DIR}|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|ExecStart=.*|ExecStart=${INSTALL_DIR}/.venv/bin/python3 ${INSTALL_DIR}/scripts/python_web_server.py|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"WEB_PORT=.*\"|Environment=\"WEB_PORT=${WEB_PORT}\"|g" /etc/systemd/system/monitoring-web.service
        # Используем выбранный хост вместо 0.0.0.0
        sudo sed -i "s|Environment=\"WEB_HOST=.*\"|Environment=\"WEB_HOST=${WEB_HOST}\"|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"PATH=.*\"|Environment=\"PATH=${INSTALL_DIR}/.venv/bin:/usr/local/bin:/usr/bin:/bin\"|g" /etc/systemd/system/monitoring-web.service
        if grep -q "^User=" /etc/systemd/system/monitoring-web.service; then
            sudo sed -i "s|^User=.*|User=${SERVICE_USER}|g" /etc/systemd/system/monitoring-web.service
        else
            sudo sed -i "/^\[Service\]/a User=${SERVICE_USER}" /etc/systemd/system/monitoring-web.service
        fi
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
        # Формируем MASTER_URL для агента, если нужно – используем WEB_HOST и WEB_PORT
        if [[ "${WEB_HOST}" == "0.0.0.0" ]]; then
            SERVER_IP="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
        else
            SERVER_IP="${WEB_HOST}"
        fi
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
