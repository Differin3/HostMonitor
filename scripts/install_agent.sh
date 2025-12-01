#!/bin/bash
# Установка агента HostMonitor на ноде из GitHub (Linux)

set -euo pipefail # строгий режим bash

REPO_URL="${1:-https://github.com/Differin3/HostMonitor}" # URL репозитория
BRANCH="${2:-main}" # ветка
INSTALL_DIR="${3:-/opt/monitoring}" # каталог установки

if [[ -z "$REPO_URL" ]]; then
  echo "[install_agent] Использование: $0 <REPO_URL> [BRANCH] [INSTALL_DIR]"
  echo "[install_agent] Пример: $0 https://github.com/Differin3/HostMonitor"
  exit 1
fi

echo "[install_agent] Обновление пакетов..." # apt update
sudo apt update -y

echo "[install_agent] Установка зависимостей (git, python)..." # базовые пакеты
sudo apt install -y git python3 python3-venv python3-pip

echo "[install_agent] Клонирование/обновление репозитория в ${INSTALL_DIR}..." # git clone/pull
if [[ -d "${INSTALL_DIR}/.git" ]]; then
  sudo git -C "${INSTALL_DIR}" fetch --all --prune
  sudo git -C "${INSTALL_DIR}" checkout "${BRANCH}"
  sudo git -C "${INSTALL_DIR}" pull --ff-only origin "${BRANCH}"
else
  sudo mkdir -p "${INSTALL_DIR}"
  sudo chown "$(whoami)":"$(whoami)" "${INSTALL_DIR}"
  git clone -b "${BRANCH}" --depth 1 "${REPO_URL}" "${INSTALL_DIR}"
fi

cd "${INSTALL_DIR}" # переходим в каталог проекта

echo "[install_agent] Создание Python venv и установка зависимостей..." # venv + pip
if [[ ! -d ".venv" ]]; then
  python3 -m venv .venv
fi
source .venv/bin/activate
if [[ -f "requirements.txt" ]]; then
  pip install --upgrade pip
  pip install -r requirements.txt
fi
deactivate

echo "[install_agent] Установка завершена!"
echo ""
echo "=========================================="
echo "Следующие шаги:"
echo "1. Получите конфиг из панели управления"
echo "2. Сохраните конфиг в: ${INSTALL_DIR}/agent/node.conf"
echo "3. Запустите агента:"
echo "   sudo cp ${INSTALL_DIR}/systemd/monitoring-agent.service /etc/systemd/system/"
echo "   sudo systemctl daemon-reload"
echo "   sudo systemctl enable --now monitoring-agent"
echo "=========================================="  