#!/bin/bash
# Установка агента HostMonitor на ноде (Linux). База данных агенту не нужна.
set -euo pipefail

REPO_URL="${1:-https://github.com/Differin3/HostMonitor}"
BRANCH="${2:-main}"
INSTALL_DIR="${3:-/opt/monitoring}"

echo "[install_agent] Пакеты..."
sudo apt-get update -y
# iproute2/procps — метрики/соседи; libpcap — scapy LLDP; snmp — fallback snmpwalk
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    git python3 python3-pip iproute2 procps \
    libpcap0.8 || true
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y snmp || true
# Опционально: системный scapy (если pip недоступен / для CAP sniff)
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y python3-scapy || true

# Проверяем, доступен ли модуль venv
if python3 -c "import venv" &>/dev/null; then
    echo "[install_agent] Модуль venv доступен, будем использовать его."
else
    echo "[install_agent] Модуль venv не найден. Пытаемся установить python3-venv..."
    if sudo apt-get install -y python3-venv 2>/dev/null; then
        echo "[install_agent] python3-venv установлен."
    else
        echo "[install_agent] Не удалось установить python3-venv. Используем virtualenv через pipx..."
        sudo apt-get install -y pipx
        pipx ensurepath
        pipx install virtualenv
        export PATH="$PATH:$HOME/.local/bin"
    fi
fi

echo "[install_agent] Репозиторий → ${INSTALL_DIR}"
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

echo "[install_agent] Python venv"
# Функция создания venv
create_venv() {
    if python3 -c "import venv" &>/dev/null; then
        if python3 -m venv .venv --without-pip 2>/dev/null; then
            # Если создался без pip, установим pip через ensurepip или get-pip
            .venv/bin/python3 -m ensurepip --upgrade 2>/dev/null || {
                echo "[install_agent] ensurepip не работает, устанавливаем pip через get-pip.py"
                curl -sS https://bootstrap.pypa.io/get-pip.py | .venv/bin/python3
            }
            return 0
        else
            echo "[install_agent] Создание venv через python3 -m venv не удалось (возможно отсутствует ensurepip)."
            return 1
        fi
    else
        return 1
    fi
}

if ! create_venv; then
    echo "[install_agent] Используем virtualenv через pipx..."
    sudo apt-get install -y pipx
    pipx ensurepath
    export PATH="$PATH:$HOME/.local/bin"
    if ! command -v virtualenv &>/dev/null; then
        pipx install virtualenv
    fi
    virtualenv .venv
fi

# Активируем venv и ставим зависимости
source .venv/bin/activate
REQ="${INSTALL_DIR}/agent/requirements.txt"
if [[ ! -f "${REQ}" ]]; then
    REQ="${INSTALL_DIR}/requirements.txt"
fi
if [[ -f "${REQ}" ]]; then
    pip install --upgrade pip
    pip install -r "${REQ}"
    # LLDP passive (опционально; без root/CAP_NET_RAW sniff не заведётся)
    pip install "scapy>=2.5.0" || echo "[install_agent] scapy не установлен — пассивный LLDP будет выключен"
fi
deactivate

# Создание пользователя monitoring
if ! id "monitoring" &>/dev/null; then
    echo "[install_agent] Пользователь monitoring"
    sudo useradd -r -s /usr/sbin/nologin -d "${INSTALL_DIR}" monitoring 2>/dev/null || \
        sudo useradd -r -s /bin/bash -d "${INSTALL_DIR}" monitoring 2>/dev/null || true
fi

if id "monitoring" &>/dev/null; then
    sudo chown -R monitoring:monitoring "${INSTALL_DIR}"
    getent group docker >/dev/null && sudo usermod -aG docker monitoring || true
    getent group adm >/dev/null && sudo usermod -aG adm monitoring || true
    getent group systemd-journal >/dev/null && sudo usermod -aG systemd-journal monitoring || true
    SUDOERS_FILE="/etc/sudoers.d/monitoring-agent"
    if [[ ! -f "${SUDOERS_FILE}" ]]; then
        printf 'monitoring ALL=(ALL) NOPASSWD: /usr/sbin/ufw, /usr/sbin/iptables, /usr/sbin/iptables-save, /usr/bin/chown -R monitoring:monitoring %s/.git\n' "${INSTALL_DIR}" | sudo tee "${SUDOERS_FILE}" >/dev/null
        sudo chmod 0440 "${SUDOERS_FILE}"
    else
        if ! grep -qF 'chown' "${SUDOERS_FILE}" 2>/dev/null; then
            printf 'monitoring ALL=(ALL) NOPASSWD: /usr/sbin/ufw, /usr/sbin/iptables, /usr/sbin/iptables-save, /usr/bin/chown -R monitoring:monitoring %s/.git\n' "${INSTALL_DIR}" | sudo tee -a "${SUDOERS_FILE}" >/dev/null
            sudo chmod 0440 "${SUDOERS_FILE}"
        fi
    fi
    SERVICE_USER="monitoring"
else
    sudo chown -R "$(whoami)":"$(whoami)" "${INSTALL_DIR}" || true
    SERVICE_USER="root"
fi

# Установка systemd-сервиса
UNIT_SRC="${INSTALL_DIR}/systemd/monitoring-agent.service"
UNIT_DST="/etc/systemd/system/monitoring-agent.service"
sudo cp "${UNIT_SRC}" "${UNIT_DST}"
sudo sed -i "s|WorkingDirectory=.*|WorkingDirectory=${INSTALL_DIR}/agent|g" "${UNIT_DST}"
sudo sed -i "s|Environment=\"PATH=.*\"|Environment=\"PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:${INSTALL_DIR}/.venv/bin\"|g" "${UNIT_DST}"
sudo sed -i "s|ExecStart=.*|ExecStart=${INSTALL_DIR}/.venv/bin/python3 ${INSTALL_DIR}/agent/main.py|g" "${UNIT_DST}"
if grep -q "^User=" "${UNIT_DST}"; then
    sudo sed -i "s|^User=.*|User=${SERVICE_USER}|g" "${UNIT_DST}"
elif [[ "${SERVICE_USER}" != "root" ]]; then
    sudo sed -i "/^\[Service\]/a User=${SERVICE_USER}" "${UNIT_DST}"
fi
# LLDP sniff без полного root: CAP_NET_RAW / CAP_NET_ADMIN
if ! grep -q "^AmbientCapabilities=" "${UNIT_DST}"; then
    sudo sed -i "/^\[Service\]/a AmbientCapabilities=CAP_NET_RAW CAP_NET_ADMIN" "${UNIT_DST}"
fi
if ! grep -q "^CapabilityBoundingSet=" "${UNIT_DST}"; then
    sudo sed -i "/^\[Service\]/a CapabilityBoundingSet=CAP_NET_RAW CAP_NET_ADMIN CAP_NET_BIND_SERVICE" "${UNIT_DST}"
fi
if ! grep -q "^NoNewPrivileges=" "${UNIT_DST}"; then
    # CAP из unit применяются при старте; NoNewPrivileges=false чтобы AmbientCapabilities работали
    sudo sed -i "/^\[Service\]/a NoNewPrivileges=false" "${UNIT_DST}"
fi
sudo systemctl daemon-reload

# Git: dubious ownership (root vs monitoring / TrueNAS dataset)
GIT_DROPIN_DIR="/etc/systemd/system/monitoring-agent.service.d"
sudo mkdir -p "${GIT_DROPIN_DIR}"
sudo tee "${GIT_DROPIN_DIR}/git-safe.conf" >/dev/null <<'EOF'
[Service]
Environment=GIT_CONFIG_COUNT=1
Environment=GIT_CONFIG_KEY_0=safe.directory
Environment=GIT_CONFIG_VALUE_0=*
Environment=GIT_TERMINAL_PROMPT=0
EOF
sudo systemctl daemon-reload


NODE_CONF="${INSTALL_DIR}/agent/node.conf"

# Если конфиг передан через переменную окружения – копируем
if [[ -n "${NODE_CONF_SRC:-}" && -f "${NODE_CONF_SRC}" ]]; then
    sudo cp "${NODE_CONF_SRC}" "${NODE_CONF}"
    sudo chown "${SERVICE_USER}:${SERVICE_USER}" "${NODE_CONF}"
    sudo chmod 640 "${NODE_CONF}"
fi

# Если конфига нет – предлагаем создать интерактивно
if [[ ! -f "${NODE_CONF}" ]]; then
    echo ""
    echo "=========================================="
    echo "Файл конфигурации агента не найден: ${NODE_CONF}"
    echo "Создадим его сейчас."
    echo "=========================================="
    read -r -p "Введите URL панели (например, http://192.168.1.100:5443): " MASTER_URL
    read -r -p "Введите токен ноды (скопируйте из панели): " NODE_TOKEN
    read -r -p "Введите порт для агента (по умолчанию 2222): " PORT
    PORT="${PORT:-2222}"

    NODE_NAME_DEFAULT="$(hostname -s 2>/dev/null || echo node-1)"
    read -r -p "Имя ноды [${NODE_NAME_DEFAULT}]: " NODE_NAME
    NODE_NAME="${NODE_NAME:-$NODE_NAME_DEFAULT}"
    read -r -p "Интерфейс для LLDP (пусто = авто, напр. eth0): " LLDP_IFACE

    sudo mkdir -p "$(dirname "${NODE_CONF}")"
    sudo tee "${NODE_CONF}" >/dev/null <<EOF
MASTER_URL="${MASTER_URL}"
NODE_TOKEN="${NODE_TOKEN}"
NODE_NAME="${NODE_NAME}"
NODE_PORT="${PORT}"
COLLECT_INTERVAL=60
HEARTBEAT_INTERVAL=15
UPNP_ENABLED=true
UPNP_INTERVAL_CYCLES=2
UPNP_MX=3
UPNP_TIMEOUT=8
UPNP_GENA_PORT=0
SNMP_ENABLED=true
SNMP_COMMUNITY="public"
SNMP_TIMEOUT=0.8
# SNMP_TARGETS="192.168.1.1"
LLDP_PASSIVE=true
LLDP_ACTIVE_POLL_KNOWN=true
EOF
    if [[ -n "${LLDP_IFACE}" ]]; then
        echo "LLDP_LISTEN_INTERFACE=\"${LLDP_IFACE}\"" | sudo tee -a "${NODE_CONF}" >/dev/null
    fi
    echo "TLS_VERIFY=false" | sudo tee -a "${NODE_CONF}" >/dev/null
    sudo chown "${SERVICE_USER}:${SERVICE_USER}" "${NODE_CONF}"
    sudo chmod 640 "${NODE_CONF}"
    echo "[install_agent] Конфиг создан: ${NODE_CONF}"
fi

# Дописать LLDP/SNMP в уже существующий конфиг, если ключей ещё нет
if [[ -f "${NODE_CONF}" ]]; then
    ensure_conf_key() {
        local key="$1"
        local line="$2"
        if ! grep -qE "^[[:space:]]*${key}=" "${NODE_CONF}" 2>/dev/null; then
            echo "${line}" | sudo tee -a "${NODE_CONF}" >/dev/null
            echo "[install_agent] Добавлено в node.conf: ${key}"
        fi
    }
    ensure_conf_key "UPNP_ENABLED" 'UPNP_ENABLED=true'
    ensure_conf_key "SNMP_ENABLED" 'SNMP_ENABLED=true'
    ensure_conf_key "SNMP_COMMUNITY" 'SNMP_COMMUNITY="public"'
    ensure_conf_key "SNMP_TIMEOUT" 'SNMP_TIMEOUT=0.8'
    ensure_conf_key "LLDP_PASSIVE" 'LLDP_PASSIVE=true'
    ensure_conf_key "LLDP_ACTIVE_POLL_KNOWN" 'LLDP_ACTIVE_POLL_KNOWN=true'
fi

# Запускаем сервис, если конфиг есть
if [[ -f "${NODE_CONF}" ]]; then
    echo "[install_agent] Найден ${NODE_CONF} — включаю сервис"
    sudo systemctl enable --now monitoring-agent
    sudo systemctl --no-pager --full status monitoring-agent || true
else
    echo "[install_agent] Сервис установлен, но не запущен: нет ${NODE_CONF}"
    echo "Создайте конфиг вручную или запустите скрипт снова, указав параметры."
fi

echo ""
echo "=========================================="
echo "Агент не использует MySQL — он шлёт JSON на панель."
echo ""
echo "1. В панели: Ноды → Создать ноду → скопировать / скачать конфиг"
echo "2. При необходимости отредактируйте: sudo nano ${NODE_CONF}"
echo "3. Перезапустите: sudo systemctl restart monitoring-agent"
echo "4. Статус: sudo systemctl status monitoring-agent"
echo ""
echo "LLDP: LLDP_PASSIVE=true (нужны scapy + CAP_NET_RAW в unit)."
echo "SNMP: SNMP_COMMUNITY / SNMP_TARGETS для активного опроса соседей."
echo "=========================================="
