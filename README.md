# HostMonitor — система мониторинга серверов

## Архитектура

- Backend-агент: Python (`agent/main.py`, `agent/config.py`) собирает метрики/процессы/порты и шлёт их на веб/API.
- Веб-интерфейс и API: PHP (`monitoring/*.php`, `monitoring/api/*.php`) + JS (`frontend/js/*.js`) работают через `/monitoring` и `/api`.
- База данных: MySQL/MariaDB (основной сценарий, схема в `schema_mysql.sql`), PostgreSQL-скрипт в `database/schema.sql` используется как референс.
- Деплой: Linux (nginx + PHP-FPM + systemd для master/agent/web) или Windows dev (XAMPP, каталог `xampp_dev/*`).

## Требования

- Debian 11/12 или Ubuntu 20.04+
- Python 3.9+
- MySQL/MariaDB 10.5+ (или PostgreSQL 13+ при использовании `database/schema.sql`)
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
sudo apt install -y python3 python3-pip python3-venv postgresql postgresql-contrib \
    docker.io docker-compose nginx php-fpm php-pgsql
```

2. Создать виртуальное окружение:
```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

3. Настроить базу данных:
```bash
sudo -u postgres psql -c "CREATE USER monitoring WITH PASSWORD 'password';"
sudo -u postgres psql -c "CREATE DATABASE monitoring OWNER monitoring;"
sudo -u postgres psql -d monitoring -f database/schema.sql
```

4. Настроить права:
```bash
chmod +x run_master.sh run_agent.sh
```

## Запуск

### Главный сервер

```bash
./run_master.sh
```

Или как systemd сервис:
```bash
sudo cp systemd/monitoring-master.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable monitoring-master
sudo systemctl start monitoring-master
```

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
1. `sudo ./install.sh` — backend (Python-агент + PostgreSQL API слой), PostgreSQL, Docker, nginx, PHP.
2. Веб-интерфейс: либо `scripts/install_web_debian.sh` (nginx + PHP-FPM), либо `python_web_server.py` (dev).
3. Настроить домен/SSL (см. скрипты выше).
4. Запустить master/agent (скрипты или systemd units).

## Конфигурация

Настроить переменные окружения в:
- `master/api/config.py` - главный сервер
- `agent/config.py` - агент

Или через переменные окружения:
```bash
export DB_HOST=localhost
export DB_NAME=monitoring
export MASTER_URL=https://master-server:8000
export NODE_NAME=node-1
export NODE_TOKEN=your-token-here
```

