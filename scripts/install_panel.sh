#!/bin/bash
# Авто-установка панели мониторинга с GitHub и базовой конфигурацией

set -euo pipefail # строгий режим bash

REPO_URL="${1:-https://github.com/Differin3/HostMonitor}" # URL репозитория (по умолчанию)
BRANCH="${2:-main}" # ветка по умолчанию
INSTALL_DIR="${3:-/opt/monitoring}" # каталог установки

# Запрашиваем веб-сервер
echo ""
echo "=========================================="
echo "Выберите веб-сервер:"
echo "1) nginx (production, рекомендуется)"
echo "2) Python веб-сервер (dev/staging)"
read -p "Ваш выбор [1]: " WEB_SERVER_CHOICE
WEB_SERVER_CHOICE="${WEB_SERVER_CHOICE:-1}"

if [[ "$WEB_SERVER_CHOICE" == "2" ]]; then
  WEB_SERVER="python"
  DEFAULT_PORT=8080
else
  WEB_SERVER="nginx"
  DEFAULT_PORT=80
fi

# Запрашиваем порт для веб-интерфейса
read -p "Введите порт для веб-интерфейса [${DEFAULT_PORT}]: " WEB_PORT
WEB_PORT="${WEB_PORT:-${DEFAULT_PORT}}"
echo "Используется веб-сервер: ${WEB_SERVER}"
echo "Используется порт: ${WEB_PORT}"
echo "=========================================="
echo ""

echo "[install_panel] Обновление пакетов..." # apt update
sudo apt update -y

echo "[install_panel] Установка зависимостей..." # базовые пакеты
if [[ "$WEB_SERVER" == "nginx" ]]; then
  sudo apt install -y git python3 python3-venv python3-pip nginx php-fpm php-cli php-cgi php-mysql mariadb-client postgresql-client
else
  sudo apt install -y git python3 python3-venv python3-pip php-cli php-cgi php-mysql mariadb-client postgresql-client
fi

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

# Установка веб-сервера в зависимости от выбора
if [[ "$WEB_SERVER" == "nginx" ]]; then
  echo "[install_panel] Установка веб-интерфейса (nginx)..." # web install
  if [[ -x "scripts/install_web_debian.sh" ]]; then
    chmod +x scripts/install_web_debian.sh
    sudo scripts/install_web_debian.sh
  fi

  echo "[install_panel] Развёртывание nginx-конфига..." # nginx config
  if [[ -f "nginx/monitoring.conf" ]]; then
    sudo mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled
    sudo cp nginx/monitoring.conf /etc/nginx/sites-available/monitoring
    # Заменяем порт в конфиге nginx
    sudo sed -i "s|listen 80;|listen ${WEB_PORT};|g" /etc/nginx/sites-available/monitoring
    sudo ln -sf /etc/nginx/sites-available/monitoring /etc/nginx/sites-enabled/monitoring
    sudo nginx -t
    sudo systemctl reload nginx
  fi
else
  echo "[install_panel] Настройка Python веб-сервера..." # python web server
  # Копируем файлы в рабочую директорию если нужно
  # Python сервер работает из INSTALL_DIR, файлы уже там
fi

echo "[install_panel] Установка systemd-сервисов..." # systemd units
if compgen -G "systemd/*.service" > /dev/null; then
  for unit in systemd/*.service; do
    unit_name=$(basename "$unit")
    sudo cp "$unit" /etc/systemd/system/
    
    # Настройка monitoring-web.service для Python сервера
    if [[ "$unit_name" == "monitoring-web.service" ]]; then
      if [[ "$WEB_SERVER" == "python" ]]; then
        # Устанавливаем порт и пути для Python сервера
        sudo sed -i "s|WorkingDirectory=.*|WorkingDirectory=${INSTALL_DIR}|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"PATH=.*\"|Environment=\"PATH=${INSTALL_DIR}/.venv/bin\"|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|ExecStart=.*|ExecStart=${INSTALL_DIR}/.venv/bin/python3 ${INSTALL_DIR}/scripts/python_web_server.py|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"WEB_PORT=.*\"|Environment=\"WEB_PORT=${WEB_PORT}\"|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"WEB_HOST=.*\"|Environment=\"WEB_HOST=0.0.0.0\"|g" /etc/systemd/system/monitoring-web.service
      else
        # Для nginx не запускаем monitoring-web.service
        sudo systemctl disable monitoring-web.service 2>/dev/null || true
        continue
      fi
    fi
  done
  sudo systemctl daemon-reload
  
  [[ -f "systemd/monitoring-master.service" ]] && sudo systemctl enable --now monitoring-master || true
  [[ -f "systemd/monitoring-agent.service" ]] && echo "[install_panel] Для агента задайте NODE_* и включите вручную: sudo systemctl enable --now monitoring-agent" # подсказка по агенту
  
  if [[ "$WEB_SERVER" == "python" ]]; then
    [[ -f "systemd/monitoring-web.service" ]] && sudo systemctl enable --now monitoring-web || true
  fi
fi

echo ""
echo "=========================================="
echo "[install_panel] Установка завершена!"
echo ""
echo "Веб-сервер: ${WEB_SERVER}"
echo "Порт: ${WEB_PORT}"
echo ""
echo "Панель управления доступна по адресу:"
echo "  http://$(hostname -I | awk '{print $1}'):${WEB_PORT}"
echo "  или"
echo "  http://$(hostname -f 2>/dev/null || hostname):${WEB_PORT}"
echo ""
if [[ "$WEB_SERVER" == "python" ]]; then
  echo "Python веб-сервер запущен как systemd сервис: monitoring-web"
  echo "Управление: sudo systemctl status/start/stop/restart monitoring-web"
fi
echo ""
echo "Следующие шаги:"
echo "1. Откройте панель управления в браузере"
echo "2. Создайте ноду в панели"
echo "3. Экспортируйте конфиг для установки агента"
echo "=========================================="


