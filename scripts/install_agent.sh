#!/bin/bash
# Установка агента HostMonitor на ноде из GitHub (Linux)

set -euo pipefail # строгий режим bash

REPO_URL="${1:-https://github.com/Differin3/HostMonitor}" # URL репозитория
BRANCH="${2:-main}" # ветка
INSTALL_DIR="${3:-/opt/monitoring}" # каталог установки
MASTER_URL="${4:-}" # URL master-сервера (обязательно)
NODE_NAME="${5:-}" # имя ноды (обязательно)
NODE_TOKEN="${6:-}" # токен ноды (обязательно)

if [[ -z "$MASTER_URL" || -z "$NODE_NAME" || -z "$NODE_TOKEN" ]]; then
  echo "[install_agent] Использование: $0 [REPO_URL] [BRANCH] [INSTALL_DIR] <MASTER_URL> <NODE_NAME> <NODE_TOKEN>" # подсказка
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

echo "[install_agent] Настройка systemd-сервиса агента..." # systemd unit
if [[ -f "systemd/monitoring-agent.service" ]]; then
  sudo cp systemd/monitoring-agent.service /etc/systemd/system/
  sudo sed -i "s|WorkingDirectory=/opt/monitoring|WorkingDirectory=${INSTALL_DIR}|g" /etc/systemd/system/monitoring-agent.service
  sudo sed -i "s|Environment=\"PATH=/opt/monitoring/.venv/bin\"|Environment=\"PATH=${INSTALL_DIR}/.venv/bin\"|g" /etc/systemd/system/monitoring-agent.service
  sudo sed -i "s|ExecStart=/opt/monitoring/.venv/bin/python3 /opt/monitoring/agent/main.py|ExecStart=${INSTALL_DIR}/.venv/bin/python3 ${INSTALL_DIR}/agent/main.py|g" /etc/systemd/system/monitoring-agent.service
  sudo sed -i "s|Environment=\"MASTER_URL=https://master-server:8000\"|Environment=\"MASTER_URL=${MASTER_URL}\"|g" /etc/systemd/system/monitoring-agent.service
  sudo sed -i "s|Environment=\"NODE_NAME=node-1\"|Environment=\"NODE_NAME=${NODE_NAME}\"|g" /etc/systemd/system/monitoring-agent.service
  sudo sed -i "s|Environment=\"NODE_TOKEN=your-token-here\"|Environment=\"NODE_TOKEN=${NODE_TOKEN}\"|g" /etc/systemd/system/monitoring-agent.service
  sudo systemctl daemon-reload
  echo "[install_agent] Агент установлен. Запуск: sudo systemctl enable --now monitoring-agent" # подсказка запуска
else
  echo "[install_agent] Ошибка: systemd/monitoring-agent.service не найден в репозитории" # нет юнита
  exit 1
fi

установки агента  