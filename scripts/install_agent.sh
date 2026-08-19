#!/bin/bash
# Установка агента HostMonitor на ноде (Linux). База данных агенту не нужна.
set -euo pipefail

REPO_URL="${1:-https://github.com/Differin3/HostMonitor}"
BRANCH="${2:-main}"
INSTALL_DIR="${3:-/opt/monitoring}"

echo "[install_agent] Пакеты..."
sudo apt-get update -y
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    git python3 python3-venv python3-pip iproute2 procps

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
if [[ ! -d ".venv" ]]; then
    python3 -m venv .venv
fi
# shellcheck disable=SC1091
source .venv/bin/activate
REQ="${INSTALL_DIR}/agent/requirements.txt"
if [[ ! -f "${REQ}" ]]; then
    REQ="${INSTALL_DIR}/requirements.txt"
fi
if [[ -f "${REQ}" ]]; then
    pip install --upgrade pip
    pip install -r "${REQ}"
fi
deactivate

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
        echo "monitoring ALL=(ALL) NOPASSWD: /usr/sbin/ufw, /usr/sbin/iptables, /usr/sbin/iptables-save" | sudo tee "${SUDOERS_FILE}" >/dev/null
        sudo chmod 0440 "${SUDOERS_FILE}"
    fi
    SERVICE_USER="monitoring"
else
    sudo chown -R "$(whoami)":"$(whoami)" "${INSTALL_DIR}" || true
    SERVICE_USER="root"
fi

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
sudo systemctl daemon-reload

NODE_CONF="${INSTALL_DIR}/agent/node.conf"
if [[ -n "${NODE_CONF_SRC:-}" && -f "${NODE_CONF_SRC}" ]]; then
    sudo cp "${NODE_CONF_SRC}" "${NODE_CONF}"
    sudo chown "${SERVICE_USER}:${SERVICE_USER}" "${NODE_CONF}"
    sudo chmod 640 "${NODE_CONF}"
fi

if [[ -f "${NODE_CONF}" ]]; then
    echo "[install_agent] Найден ${NODE_CONF} — включаю сервис"
    sudo systemctl enable --now monitoring-agent
    sudo systemctl --no-pager --full status monitoring-agent || true
else
    echo "[install_agent] Сервис установлен, но не запущен: нет ${NODE_CONF}"
fi

echo ""
echo "=========================================="
echo "Агент не использует MySQL — он шлёт JSON на панель."
echo ""
echo "1. В панели: Ноды → Создать ноду → скопировать конфиг"
echo "2. sudo nano ${NODE_CONF}"
echo "3. sudo systemctl enable --now monitoring-agent"
echo "4. sudo systemctl status monitoring-agent"
echo "=========================================="
