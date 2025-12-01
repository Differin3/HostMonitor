# HostMonitor — система мониторинга серверов

## Архитектура

- **Backend-агент**: Python (`agent/main.py`, `agent/config.py`) собирает метрики/процессы/порты/контейнеры и отправляет их на PHP API.
- **Веб-интерфейс и API**: PHP (`monitoring/*.php`, `monitoring/api/*.php`) + JS (`frontend/js/*.js`) работают через `/monitoring` и `/api`.
- **База данных**: MySQL/MariaDB (схема в `schema_mysql.sql`).
- **Деплой**: Linux (nginx + PHP-FPM + systemd для agent/web) или Windows dev (XAMPP, каталог `xampp_dev/*`).

## Требования

- Debian 11/12 или Ubuntu 20.04+
- Python 3.9+
- MySQL/MariaDB 10.5+
- PHP 8.0+ с расширениями: mysql, json
- Nginx (для production)

---

## 🚀 Быстрая установка

### Установка панели управления (мастер-сервер)

**Одна команда:**

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh)
```

Или с указанием репозитория:

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh) https://github.com/Differin3/HostMonitor
```

**Что делает:**
- Запрашивает выбор веб-сервера (nginx или Python)
- Запрашивает порт для веб-интерфейса (по умолчанию: 80 для nginx, 8080 для Python)
- Устанавливает все зависимости (Python, MySQL, PHP)
- Клонирует репозиторий в `/opt/monitoring`
- Настраивает базу данных
- Устанавливает и настраивает выбранный веб-сервер
- Готово к использованию!

**После установки:**
- Откройте панель управления в браузере: `http://your-server-ip:PORT` или `http://your-domain:PORT`
- Порт будет указан в сообщении после установки
- Создайте ноду и экспортируйте конфиг для установки агента

---

### Установка агента на ноде

**Одна команда:**

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_agent.sh) https://github.com/Differin3/HostMonitor
```

**Что делает:**
- Устанавливает зависимости (Python, git)
- Клонирует репозиторий в `/opt/monitoring`
- Создает виртуальное окружение
- Устанавливает Python-зависимости
- Готов к настройке конфига

**После установки:**

1. **Получите конфиг из панели управления:**
   - Зайдите в панель управления
   - Создайте ноду или откройте существующую
   - Нажмите "Экспорт конфига" или скопируйте конфиг

2. **Сохраните конфиг на сервере ноды:**
   ```bash
   sudo nano /opt/monitoring/agent/node.conf
   ```
   
   Вставьте конфиг (пример):
   ```
   MASTER_URL="https://your-master-server.com"
   NODE_NAME="node-1"
   NODE_TOKEN="your-node-token"
   COLLECT_INTERVAL=60
   TLS_VERIFY=false
   ```

3. **Запустите агента:**
   ```bash
   # Как systemd сервис (рекомендуется)
   sudo cp /opt/monitoring/systemd/monitoring-agent.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now monitoring-agent
   
   # Или вручную
   cd /opt/monitoring
   source .venv/bin/activate
   python agent/main.py
   ```

4. **Проверьте статус:**
   ```bash
   sudo systemctl status monitoring-agent
   ```

---

## 📋 Детальная установка

### Установка панели управления (локально)

Если репозиторий уже склонирован:

```bash
# 1. Установка зависимостей и настройка БД
sudo ./install.sh

# 2. Установка веб-интерфейса
chmod +x scripts/install_web_debian.sh
sudo scripts/install_web_debian.sh
```

### Установка агента (локально)

```bash
# Склонировать репозиторий
git clone https://github.com/Differin3/HostMonitor.git /opt/monitoring
cd /opt/monitoring

# Установить зависимости
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
deactivate

# Получите конфиг из панели управления и сохраните в agent/node.conf
# Затем запустите агента:

# Как systemd сервис (рекомендуется)
sudo cp systemd/monitoring-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now monitoring-agent

# Или вручную
source .venv/bin/activate
python agent/main.py
```

---

## ⚙️ Конфигурация

### Конфигурация агента

Агент настраивается через файл `agent/node.conf` или переменные окружения:

**Файл `agent/node.conf`:**
```
MASTER_URL="https://your-master-server.com"
NODE_NAME="node-1"
NODE_TOKEN="your-node-token"
COLLECT_INTERVAL=60
TLS_VERIFY=false
```

**Или переменные окружения:**
```bash
export MASTER_URL=https://your-master-server.com
export NODE_NAME=node-1
export NODE_TOKEN=your-node-token
export COLLECT_INTERVAL=60
```

### Конфигурация базы данных

Настроить через переменные окружения:
```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=monitoring
export DB_USER=monitoring
export DB_PASSWORD=password
```

Или отредактировать `monitoring/includes/database.php`.

### Конфигурация Python веб-сервера (dev/staging)

Если используете Python веб-сервер вместо nginx, можно настроить порт:

**Через переменные окружения:**
```bash
export WEB_PORT=8080  # Порт по умолчанию: 8080
export WEB_HOST=0.0.0.0  # Адрес по умолчанию: 0.0.0.0
```

**Через параметры командной строки:**
```bash
python3 scripts/python_web_server.py --host 0.0.0.0 --port 8080
```

**В systemd сервисе:**
Отредактируйте `/etc/systemd/system/monitoring-web.service`:
```ini
[Service]
Environment="WEB_PORT=8080"
Environment="WEB_HOST=0.0.0.0"
```

После изменения перезагрузите сервис:
```bash
sudo systemctl daemon-reload
sudo systemctl restart monitoring-web
```

---

## 🔧 Дополнительные настройки

### Настройка домена и SSL

```bash
# Установить домен
scripts/set_domain.sh example.com www.example.com

# Настроить SSL (Let's Encrypt)
scripts/configure_ssl_letsencrypt.sh example.com admin@example.com www.example.com
```

### Docker

Запуск через Docker Compose:
```bash
cd docker
docker-compose up -d
```

**Примечание:** В Docker Compose веб-интерфейс доступен на порту **8080** (маппинг `8080:80`).

---

## 📖 Использование

1. **Установите панель управления:**
   ```bash
   bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh)
   ```

2. **Создайте ноду в панели управления:**
   - Зайдите в панель управления
   - Создайте новую ноду
   - Нажмите "Экспорт конфига" или скопируйте конфиг

3. **Установите агент на ноде:**
   ```bash
   bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_agent.sh) https://github.com/Differin3/HostMonitor
   ```

4. **Настройте конфиг агента:**
   - Сохраните конфиг из панели в `/opt/monitoring/agent/node.conf`
   - Или скопируйте конфиг через веб-интерфейс

5. **Запустите агента:**
   ```bash
   sudo cp /opt/monitoring/systemd/monitoring-agent.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now monitoring-agent
   ```

6. **Проверьте статус:**
   - В панели управления нода должна появиться как "online"
   - Метрики начнут собираться автоматически
   - Проверка: `sudo systemctl status monitoring-agent`

---

## 🆘 Поддержка

- **Документация**: См. файлы в `frontend/docs/` и `monitoring/README.md`
- **Проблемы**: Проверьте логи `sudo journalctl -u monitoring-agent -f`

