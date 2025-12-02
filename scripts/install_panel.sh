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

# MariaDB будет установлен через install.sh

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
  # Устанавливаем MariaDB если еще не установлен
  if ! systemctl is-active --quiet mariadb 2>/dev/null && ! systemctl is-active --quiet mysql 2>/dev/null; then
    if ! command -v mariadb &> /dev/null && ! command -v mysql &> /dev/null; then
      echo "[install_panel] Установка MariaDB..."
      sudo apt install -y mariadb-server
      sudo systemctl enable --now mariadb
    fi
  fi
  sudo ./install.sh
else
  echo "[install_panel] install.sh не найден или не исполняемый, пропускаем..."
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

# Создаем пользователя monitoring если его нет
if ! id "monitoring" &>/dev/null; then
  echo "[install_panel] Создание пользователя monitoring..."
  sudo useradd -r -s /bin/false -d ${INSTALL_DIR} -m monitoring 2>/dev/null || \
  sudo useradd -r -s /bin/bash -d ${INSTALL_DIR} -m monitoring 2>/dev/null || \
  echo "[install_panel] Не удалось создать пользователя monitoring, будет использован root"
fi

# Устанавливаем права на директорию
if id "monitoring" &>/dev/null; then
  sudo chown -R monitoring:monitoring ${INSTALL_DIR} 2>/dev/null || true
  SERVICE_USER="monitoring"
else
  sudo chown -R $(whoami):$(whoami) ${INSTALL_DIR} 2>/dev/null || true
  SERVICE_USER="root"
  echo "[install_panel] Внимание: сервисы будут запущены от root (пользователь monitoring не создан)"
fi

echo "[install_panel] Установка systemd-сервисов..." # systemd units
if compgen -G "systemd/*.service" > /dev/null; then
  for unit in systemd/*.service; do
    unit_name=$(basename "$unit")
    
    # Пропускаем monitoring-master.service (не используется в этой архитектуре)
    if [[ "$unit_name" == "monitoring-master.service" ]]; then
      continue
    fi
    
    sudo cp "$unit" /etc/systemd/system/
    
    # Настройка monitoring-web.service для Python сервера
    if [[ "$unit_name" == "monitoring-web.service" ]]; then
      if [[ "$WEB_SERVER" == "python" ]]; then
        # Устанавливаем порт и пути для Python сервера
        sudo sed -i "s|WorkingDirectory=.*|WorkingDirectory=${INSTALL_DIR}|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|ExecStart=.*|ExecStart=${INSTALL_DIR}/.venv/bin/python3 ${INSTALL_DIR}/scripts/python_web_server.py|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"WEB_PORT=.*\"|Environment=\"WEB_PORT=${WEB_PORT}\"|g" /etc/systemd/system/monitoring-web.service
        sudo sed -i "s|Environment=\"WEB_HOST=.*\"|Environment=\"WEB_HOST=0.0.0.0\"|g" /etc/systemd/system/monitoring-web.service
        # Определяем реальный IP сервера для MASTER_URL
        SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo "")
        if [[ -n "$SERVER_IP" ]]; then
          # Добавляем или обновляем MASTER_URL в systemd сервисе
          if grep -q "Environment=\"MASTER_URL=" /etc/systemd/system/monitoring-web.service; then
            sudo sed -i "s|Environment=\"MASTER_URL=.*\"|Environment=\"MASTER_URL=http://${SERVER_IP}:${WEB_PORT}\"|g" /etc/systemd/system/monitoring-web.service
          else
            sudo sed -i "/Environment=\"WEB_HOST=/a Environment=\"MASTER_URL=http://${SERVER_IP}:${WEB_PORT}\"" /etc/systemd/system/monitoring-web.service
          fi
        fi
        # Устанавливаем PATH с системными путями для поиска php-cgi (важно: системные пути должны быть включены)
        sudo sed -i "s|Environment=\"PATH=.*\"|Environment=\"PATH=${INSTALL_DIR}/.venv/bin:/usr/local/bin:/usr/bin:/bin\"|g" /etc/systemd/system/monitoring-web.service
        # Явно указываем путь к php-cgi
        if ! grep -q "Environment=\"PHP_CGI=" /etc/systemd/system/monitoring-web.service; then
          sudo sed -i "/Environment=\"PATH=/a Environment=\"PHP_CGI=/usr/bin/php-cgi\"" /etc/systemd/system/monitoring-web.service
        else
          sudo sed -i "s|Environment=\"PHP_CGI=.*\"|Environment=\"PHP_CGI=/usr/bin/php-cgi\"|g" /etc/systemd/system/monitoring-web.service
        fi
        # Добавляем переменные окружения для БД (если они были заданы)
        if [[ -n "${DB_NAME:-}" ]]; then
          if ! grep -q "Environment=\"DB_NAME=" /etc/systemd/system/monitoring-web.service; then
            sudo sed -i "/Environment=\"PHP_CGI=/a Environment=\"DB_HOST=${DB_HOST:-localhost}\"\nEnvironment=\"DB_PORT=${DB_PORT:-3306}\"\nEnvironment=\"DB_NAME=${DB_NAME}\"\nEnvironment=\"DB_USER=${DB_USER:-monitoring}\"\nEnvironment=\"DB_PASSWORD=${DB_PASSWORD:-password}\"" /etc/systemd/system/monitoring-web.service
          else
            sudo sed -i "s|Environment=\"DB_NAME=.*\"|Environment=\"DB_NAME=${DB_NAME}\"|g" /etc/systemd/system/monitoring-web.service
            sudo sed -i "s|Environment=\"DB_USER=.*\"|Environment=\"DB_USER=${DB_USER:-monitoring}\"|g" /etc/systemd/system/monitoring-web.service
            sudo sed -i "s|Environment=\"DB_PASSWORD=.*\"|Environment=\"DB_PASSWORD=${DB_PASSWORD:-password}\"|g" /etc/systemd/system/monitoring-web.service
          fi
        fi
        # Устанавливаем пользователя (или root если monitoring не создан)
        if grep -q "^User=" /etc/systemd/system/monitoring-web.service; then
          sudo sed -i "s|^User=.*|User=${SERVICE_USER}|g" /etc/systemd/system/monitoring-web.service
        else
          sudo sed -i "/^\[Service\]/a User=${SERVICE_USER}" /etc/systemd/system/monitoring-web.service
        fi
      else
        # Для nginx не запускаем monitoring-web.service
        sudo systemctl disable monitoring-web.service 2>/dev/null || true
        continue
      fi
    fi
    
    # Настройка monitoring-agent.service
    if [[ "$unit_name" == "monitoring-agent.service" ]]; then
      sudo sed -i "s|WorkingDirectory=.*|WorkingDirectory=${INSTALL_DIR}|g" /etc/systemd/system/monitoring-agent.service
      sudo sed -i "s|Environment=\"PATH=.*\"|Environment=\"PATH=${INSTALL_DIR}/.venv/bin\"|g" /etc/systemd/system/monitoring-agent.service
      sudo sed -i "s|ExecStart=.*|ExecStart=${INSTALL_DIR}/.venv/bin/python3 ${INSTALL_DIR}/agent/main.py|g" /etc/systemd/system/monitoring-agent.service
      # Устанавливаем пользователя
      if grep -q "^User=" /etc/systemd/system/monitoring-agent.service; then
        sudo sed -i "s|^User=.*|User=${SERVICE_USER}|g" /etc/systemd/system/monitoring-agent.service
      else
        sudo sed -i "/^\[Service\]/a User=${SERVICE_USER}" /etc/systemd/system/monitoring-agent.service
      fi
      echo "[install_panel] Для агента задайте NODE_* и включите вручную: sudo systemctl enable --now monitoring-agent" # подсказка по агенту
    fi
  done
  sudo systemctl daemon-reload
  
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
  echo ""
  echo "Проверка статуса сервиса:"
  sudo systemctl status monitoring-web --no-pager -l || true
fi
echo ""
echo "Следующие шаги:"
echo "1. Проверьте статус сервисов:"
echo "   sudo systemctl status monitoring-web"
echo "2. Если сервис не запущен, проверьте логи:"
echo "   sudo journalctl -u monitoring-web -f"
echo "3. Откройте панель управления в браузере"
echo "4. Создайте ноду в панели"
echo "5. Экспортируйте конфиг для установки агента"
echo "=========================================="


