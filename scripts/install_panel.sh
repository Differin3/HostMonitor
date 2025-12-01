#!/bin/bash
# Авто-установка панели мониторинга с GitHub и базовой конфигурацией

set -euo pipefail # строгий режим bash

REPO_URL="${1:-}" # URL репозитория (обязательный аргумент)
BRANCH="${2:-main}" # ветка по умолчанию
INSTALL_DIR="${3:-/opt/monitoring}" # каталог установки

if [[ -z "$REPO_URL" ]]; then
  echo "[install_panel] Использование: $0 <GIT_REPO_URL> [BRANCH] [INSTALL_DIR]" # подсказка
  exit 1
fi

echo "[install_panel] Обновление пакетов..." # apt update
sudo apt update -y

echo "[install_panel] Установка зависимостей (git, python, nginx, php, db-клиенты)..." # базовые пакеты
sudo apt install -y git python3 python3-venv python3-pip nginx php-fpm php-cli php-cgi php-mysql mariadb-client postgresql-client

echo "[install_panel] Клонирование/обновление репозитория в ${INSTALL_DIR}..." # git clone/pull
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

echo "[install_panel] Создание Python venv и установка зависимостей..." # venv + pip
if [[ ! -d ".venv" ]]; then
  python3 -m venv .venv
fi
source .venv/bin/activate
if [[ -f "requirements.txt" ]]; then
  pip install --upgrade pip
  pip install -r requirements.txt
fi
deactivate

echo "[install_panel] Запуск установочного скрипта backend (если есть)..." # backend install
if [[ -x "./install.sh" ]]; then
  sudo ./install.sh
fi

echo "[install_panel] Установка веб-интерфейса (если есть install_web_debian.sh)..." # web install
if [[ -x "scripts/install_web_debian.sh" ]]; then
  chmod +x scripts/install_web_debian.sh
  sudo scripts/install_web_debian.sh
fi

echo "[install_panel] Развёртывание nginx-конфига (если есть nginx/monitoring.conf)..." # nginx config
if [[ -f "nginx/monitoring.conf" ]]; then
  sudo mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled
  sudo cp nginx/monitoring.conf /etc/nginx/sites-available/monitoring
  sudo ln -sf /etc/nginx/sites-available/monitoring /etc/nginx/sites-enabled/monitoring
  sudo nginx -t
  sudo systemctl reload nginx
fi

echo "[install_panel] Установка systemd-сервисов (если есть systemd/*.service)..." # systemd units
if compgen -G "systemd/*.service" > /dev/null; then
  for unit in systemd/*.service; do
    sudo cp "$unit" /etc/systemd/system/
  done
  sudo systemctl daemon-reload
  [[ -f "systemd/monitoring-master.service" ]] && sudo systemctl enable --now monitoring-master || true
  [[ -f "systemd/monitoring-agent.service" ]] && echo "[install_panel] Для агента задайте NODE_* и включите вручную: sudo systemctl enable --now monitoring-agent" # подсказка по агенту
  [[ -f "systemd/monitoring-web.service" ]] && sudo systemctl enable --now monitoring-web || true
fi

echo "[install_panel] Установка завершена. Проверьте доступ к панели через nginx/указанный порт." # финальное сообщение


