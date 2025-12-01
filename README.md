# HostMonitor — система мониторинга серверов

## Архитектура

- Backend-агент: Python (`agent/main.py`, `agent/config.py`) собирает метрики/процессы/порты/контейнеры и отправляет их на PHP API.
- Веб-интерфейс и API: PHP (`monitoring/*.php`, `monitoring/api/*.php`) + JS (`frontend/js/*.js`) работают через `/monitoring` и `/api`.
- База данных: MySQL/MariaDB (схема в `schema_mysql.sql`), PostgreSQL-скрипт в `database/schema.sql` используется как референс.
- Деплой: Linux (nginx + PHP-FPM + systemd для agent/web) или Windows dev (XAMPP, каталог `xampp_dev/*`).

## Требования

- Debian 11/12 или Ubuntu 20.04+
- Python 3.9+
- MySQL/MariaDB 10.5+
- PHP 8.0+ с расширениями: mysql, json
- Nginx или Apache (для production)
- Docker (опционально)

## Установка на Debian

### Автоматическая установка

```bash
chmod +x install.sh
sudo ./install.sh
```

### Ручная установка

1. Установить зависимости:
```bash
sudo apt update
sudo apt install -y python3 python3-pip python3-venv mysql-server \
    docker.io docker-compose nginx php-fpm php-mysql php-cli
```

2. Создать виртуальное окружение:
```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

3. Настроить базу данных:
```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS monitoring CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS 'monitoring'@'localhost' IDENTIFIED BY 'password';"
sudo mysql -e "GRANT ALL PRIVILEGES ON monitoring.* TO 'monitoring'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
sudo mysql monitoring < schema_mysql.sql
```

4. Настроить права:
```bash
chmod +x run_agent.sh
```

## Запуск

### Веб-интерфейс

См. раздел "Веб-интерфейс" ниже для вариантов запуска (nginx + PHP-FPM или Python HTTP сервер).

### Агент на ноде

```bash
./run_agent.sh
```

Или как systemd сервис:
```bash
sudo cp systemd/monitoring-agent.service /etc/systemd/system/
# Отредактировать переменные окружения в файле
sudo systemctl daemon-reload
sudo systemctl enable monitoring-agent
sudo systemctl start monitoring-agent
```

## Docker

Запуск через Docker Compose:
```bash
cd docker
docker-compose up -d
```

## Nginx

Настроить Nginx:
```bash
sudo cp nginx/monitoring.conf /etc/nginx/sites-available/monitoring
sudo ln -s /etc/nginx/sites-available/monitoring /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

## Веб-интерфейс

### Вариант A. nginx + PHP-FPM (production)
1. `chmod +x scripts/install_web_debian.sh && sudo scripts/install_web_debian.sh` — копирует PHP/Frontend и подключает конфиг nginx.
2. `scripts/set_domain.sh example.com www.example.com` — безопасно прописывает `server_name` (создаёт backup).
3. `scripts/configure_ssl_letsencrypt.sh example.com admin@example.com www.example.com` — выдаёт сертификат Let's Encrypt и включает `certbot.timer`.

### Вариант B. Встроенный Python HTTP сервер (dev/staging)
```bash
sudo apt install php-cgi
python3 scripts/python_web_server.py --host 0.0.0.0 --port 8080
```
Сервер использует `php-cgi`, поэтому весь функционал PHP сохраняется. Для автозапуска:
```bash
sudo cp systemd/monitoring-web.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now monitoring-web
```

## Полный деплой (кратко)
1. `sudo ./install.sh` — установка Python-агента, MySQL, Docker, nginx, PHP.
2. Веб-интерфейс: либо `scripts/install_web_debian.sh` (nginx + PHP-FPM), либо `python_web_server.py` (dev).
3. Настроить домен/SSL (см. скрипты выше).
4. Запустить агент: `./run_agent.sh` или через systemd unit.

## Конфигурация

### Агент

Настроить переменные окружения в `agent/config.py` или через переменные окружения:
```bash
export MASTER_URL=https://master-server/monitoring
export NODE_NAME=node-1
export NODE_TOKEN=your-token-here
export COLLECT_INTERVAL=60
```

Или создать файл `agent/node.conf`:
```
MASTER_URL="https://master-server/monitoring"
NODE_NAME="node-1"
NODE_TOKEN="your-token-here"
```

### База данных

Настроить подключение к MySQL через переменные окружения или в `monitoring/includes/database.php`:
```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=monitoring
export DB_USER=monitoring
export DB_PASSWORD=password
```

