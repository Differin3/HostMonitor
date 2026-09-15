<div align="center">

# <img src="frontend/icons/lucide/server.svg" width="32" height="32" alt="Server"> HostMonitor

### Современная система мониторинга серверов с веб-интерфейсом

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Python](https://img.shields.io/badge/Python-3.9+-green.svg)](https://www.python.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0+-orange.svg)](https://www.mysql.com/)

**Мониторинг серверов в реальном времени | Метрики | Процессы | Порты | Контейнеры**

[🚀 Быстрая установка](#-быстрая-установка) • [📖 Документация](#-документация) • [<img src="frontend/icons/lucide/settings.svg" width="16" height="16" alt="Settings"> Конфигурация](#️-конфигурация) • [🛡️ Безопасность](#️-безопасность)

</div>

---

## <img src="frontend/icons/lucide/file-text.svg" width="20" height="20" alt="Contents"> Содержание

- [Возможности](#-возможности)
- [Архитектура](#-архитектура)
- [Требования](#-требования)
- [Быстрая установка](#-быстрая-установка)
- [Конфигурация](#️-конфигурация)
- [Использование](#-использование)
- [Безопасность](#️-безопасность)
- [Поддержка](#-поддержка)

---

## ✨ Возможности

<table>
<tr>
<td width="50%">

### <img src="frontend/icons/lucide/activity.svg" width="20" height="20" alt="Monitoring"> Мониторинг
- <img src="frontend/icons/lucide/trending-up.svg" width="16" height="16" alt="Trending"> **Метрики в реальном времени**: CPU, RAM, Disk, Network
- <img src="frontend/icons/lucide/server.svg" width="16" height="16" alt="Server"> **GPU мониторинг**: Загрузка, память, температура
- <img src="frontend/icons/lucide/cpu.svg" width="16" height="16" alt="CPU"> **Процессы**: Список запущенных процессов с деталями
- <img src="frontend/icons/lucide/network.svg" width="16" height="16" alt="Network"> **Порты**: Мониторинг открытых портов и соединений
- <img src="frontend/icons/lucide/box.svg" width="16" height="16" alt="Box"> **Контейнеры**: Docker контейнеры и их статусы

</td>
<td width="50%">

### <img src="frontend/icons/lucide/palette.svg" width="20" height="20" alt="Interface"> Интерфейс
- <img src="frontend/icons/lucide/home.svg" width="16" height="16" alt="Home"> **Современный веб-интерфейс**: Адаптивный дизайн
- <img src="frontend/icons/lucide/home.svg" width="16" height="16" alt="Home"> **Дашборд**: Обзор всех нод на одной странице
- <img src="frontend/icons/lucide/trending-up.svg" width="16" height="16" alt="Charts"> **Графики**: Визуализация метрик и трендов
- <img src="frontend/icons/lucide/bell.svg" width="16" height="16" alt="Notifications"> **Уведомления**: Система алертов и уведомлений
- <img src="frontend/icons/lucide/wallet.svg" width="16" height="16" alt="Billing"> **Биллинг**: Учет расходов на провайдеров

</td>
</tr>
</table>

### <img src="frontend/icons/lucide/shield.svg" width="20" height="20" alt="Security"> Безопасность
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Аутентификация пользователей (сессии + Bearer token)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Токены для агентов (NODE_TOKEN + Bearer)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> CSRF-защита всех POST-запросов (X-CSRF-Token header)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Security-заголовки (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Brute-force защита (5 попыток / 15 мин lockout)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Защита от SQL-инъекций (PDO prepared statements)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Защита от XSS (esc/escapeHtml для всех пользовательских данных)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Защита от XXE (LIBXML_NONET для XML-парсинга)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Защита от SSRF (allowlist URL, запрет FOLLOWLOCATION)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Защита от command injection (escapeshellarg, валидация параметров)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> TLS/SSL поддержка с валидацией сертификатов
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Безопасность сессий (HttpOnly, SameSite=Lax, Secure при HTTPS)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Регенерация session ID после логина
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Generic ошибки (утечка $e->getMessage() устранена)
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Агент: allowlist node.conf, запрет опасных команд без флага
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Агент: health server только на 127.0.0.1
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Агент: отказ от запуска без HTTPS (MASTER_URL_INSECURE для override)

### ⚡ Производительность
- ⚡ Легковесный агент (Python)
- ⚡ Быстрый веб-интерфейс (PHP + JS)
- ⚡ Оптимизированные SQL запросы (batch INSERT, 8 индексов для retention)
- ⚡ Кэширование метрик
- ⚡ Свертывание дублей логов на стороне агента
- ⚡ Тримминг raw_message перед отправкой (≤500 символов)
- ⚡ Тонкая настройка retention (500K / 50K строк на ноду)

### <img src="frontend/icons/lucide/hard-drive.svg" width="20" height="20" alt="SMART"> Расширенные возможности
- <img src="frontend/icons/lucide/hard-drive.svg" width="16" height="16" alt="SMART"> **SMART мониторинг**: Состояние дисков, температура, наработка,bad sectors
- <img src="frontend/icons/lucide/refresh-cw.svg" width="16" height="16" alt="Refresh"> **DB HA**: Синхронизация базы данных между primary/replica
- <img src="frontend/icons/lucide/network.svg" width="16" height="16" alt="Network"> **LLDP/SNMP**: Автообнаружение сетевого оборудования
- <img src="frontend/icons/lucide/upload.svg" width="16" height="16" alt="Export"> **Экспорт нод**: JSON-экспорт/импорт конфигураций (токены автоматически исключаются)
- <img src="frontend/icons/lucide/cpu.svg" width="16" height="16" alt="GPU"> **GPU мониторинг**: Загрузка, память, температура
- <img src="frontend/icons/lucide/shield.svg" width="16" height="16" alt="Security"> **Agent updates**: Удалённое обновление агентов через панель
- <img src="frontend/icons/lucide/server.svg" width="16" height="16" alt="Server"> **Panel updates**: Самообновление панели из Git

---

## <img src="frontend/icons/lucide/building.svg" width="20" height="20" alt="Architecture"> Архитектура

<div align="center">

```mermaid
graph TB
    A[Веб-интерфейс<br/>PHP + JavaScript] --> B[API Endpoints<br/>PHP REST API]
    B --> C[(База данных<br/>MySQL/MariaDB)]
    D[Агент<br/>Python] --> B
    D --> E[Сбор метрик<br/>CPU, RAM, Disk, Network]
    D --> F[Сбор процессов<br/>psutil]
    D --> G[Сбор портов<br/>netstat/ss]
    D --> H[Сбор контейнеров<br/>Docker API]
```

</div>

### Компоненты системы

| <img src="frontend/icons/lucide/server.svg" width="16" height="16" alt="Component"> Компонент | Технология | Описание |
|-----------|-----------|----------|
| **<img src="frontend/icons/lucide/settings.svg" width="16" height="16" alt="Settings"> Backend-агент** | Python 3.9+ | Собирает метрики, процессы, порты, контейнеры и отправляет на API |
| **<img src="frontend/icons/lucide/home.svg" width="16" height="16" alt="Home"> Веб-интерфейс** | PHP 8.0+ + JavaScript | Панель управления с дашбордом и настройками |
| **<img src="frontend/icons/lucide/network.svg" width="16" height="16" alt="Network"> API** | PHP REST API | Обработка запросов от агентов и веб-интерфейса |
| **<img src="frontend/icons/lucide/database.svg" width="16" height="16" alt="Database"> База данных** | MySQL/MariaDB | Хранение метрик, нод, пользователей, настроек |
| **<img src="frontend/icons/lucide/globe.svg" width="16" height="16" alt="Globe"> Веб-сервер** | Nginx / Python HTTP Server | Обслуживание веб-интерфейса |

---

## <img src="frontend/icons/lucide/package.svg" width="20" height="20" alt="Requirements"> Требования

### Минимальные требования

<table>
<tr>
<th><img src="frontend/icons/lucide/settings.svg" width="16" height="16" alt="Settings"> Компонент</th>
<th>Версия</th>
<th>Примечание</th>
</tr>
<tr>
<td><strong><img src="frontend/icons/lucide/server.svg" width="16" height="16" alt="Server"> ОС</strong></td>
<td>Debian 11/12, Ubuntu 20.04+</td>
<td>Linux для production, Windows для dev</td>
</tr>
<tr>
<td><strong>🐍 Python</strong></td>
<td>3.9+</td>
<td>Для агента и веб-сервера</td>
</tr>
<tr>
<td><strong>🐘 PHP</strong></td>
<td>8.0+</td>
<td>С расширениями: <code>mysql</code>, <code>json</code></td>
</tr>
<tr>
<td><strong><img src="frontend/icons/lucide/database.svg" width="16" height="16" alt="Database"> База данных</strong></td>
<td>MySQL 8.0+ / MariaDB 10.5+</td>
<td>Для хранения данных</td>
</tr>
<tr>
<td><strong><img src="frontend/icons/lucide/globe.svg" width="16" height="16" alt="Globe"> Веб-сервер</strong></td>
<td>Nginx (production)</td>
<td>Опционально: Python HTTP Server для dev</td>
</tr>
</table>

### Дополнительные зависимости

- **Git** - для клонирования репозитория
- **Systemd** - для управления сервисами (Linux)
- **Docker** (опционально) - для контейнеризации

---

## 🚀 Быстрая установка

### <img src="frontend/icons/lucide/server.svg" width="20" height="20" alt="Server"> Установка панели управления (мастер-сервер)

<details>
<summary><b>🔽 Развернуть инструкцию</b></summary>

#### 🚀 Вариант 1: Автоматическая установка (рекомендуется)

**<img src="frontend/icons/lucide/play.svg" width="16" height="16" alt="Play"> Одна команда для установки:**

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh)
```

Или с указанием репозитория:

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh) https://github.com/Differin3/HostMonitor
```

**<img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Что делает скрипт:**
- <img src="frontend/icons/lucide/settings.svg" width="16" height="16" alt="Settings"> Запрашивает выбор веб-сервера (nginx или Python)
- <img src="frontend/icons/lucide/globe.svg" width="16" height="16" alt="Globe"> Запрашивает порт для веб-интерфейса (по умолчанию: 80 для nginx, 8080 для Python)
- <img src="frontend/icons/lucide/download.svg" width="16" height="16" alt="Download"> Устанавливает все зависимости (Python, MariaDB, PHP)
- 🌿 Клонирует репозиторий в `/opt/monitoring`
- <img src="frontend/icons/lucide/database.svg" width="16" height="16" alt="Database"> Настраивает базу данных
- <img src="frontend/icons/lucide/server.svg" width="16" height="16" alt="Server"> Устанавливает и настраивает выбранный веб-сервер
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Готово к использованию!

**<img src="frontend/icons/lucide/arrow-right.svg" width="16" height="16" alt="Arrow"> После установки:**
1. <img src="frontend/icons/lucide/globe.svg" width="16" height="16" alt="Globe"> Откройте панель: `http://your-server-ip:PORT`
2. На первом заходе откроется мастер: задайте логин администратора панели. **Базу MySQL создавать вручную не нужно** — её уже создал `install.sh`.
3. <img src="frontend/icons/lucide/plus.svg" width="16" height="16" alt="Plus"> Создайте ноду и экспортируйте конфиг для агента

#### Вариант 2: Ручная установка

```bash
# 1. Установка зависимостей и настройка БД
sudo ./install.sh

# 2. Установка веб-интерфейса
chmod +x scripts/install_web_debian.sh
sudo scripts/install_web_debian.sh
```

</details>

### <img src="frontend/icons/lucide/settings.svg" width="20" height="20" alt="Settings"> Установка агента на ноде

<details>
<summary><b>🔽 Развернуть инструкцию</b></summary>

#### 🚀 Автоматическая установка

**<img src="frontend/icons/lucide/play.svg" width="16" height="16" alt="Play"> Одна команда:**

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_agent.sh) https://github.com/Differin3/HostMonitor
```

**<img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Что делает скрипт:**
- <img src="frontend/icons/lucide/download.svg" width="16" height="16" alt="Download"> Устанавливает зависимости (Python, git)
- 🌿 Клонирует репозиторий в `/opt/monitoring`
- 📁 Создает виртуальное окружение
- <img src="frontend/icons/lucide/package.svg" width="16" height="16" alt="Package"> Устанавливает Python-зависимости
- <img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Готов к настройке конфига

#### <img src="frontend/icons/lucide/settings.svg" width="20" height="20" alt="Settings"> Настройка после установки

1. **<img src="frontend/icons/lucide/download.svg" width="16" height="16" alt="Download"> Получите конфиг из панели управления:**
   - <img src="frontend/icons/lucide/home.svg" width="16" height="16" alt="Home"> Зайдите в панель управления
   - <img src="frontend/icons/lucide/plus.svg" width="16" height="16" alt="Plus"> Создайте ноду или откройте существующую
   - <img src="frontend/icons/lucide/download.svg" width="16" height="16" alt="Download"> Нажмите "Экспорт конфига" или скопируйте конфиг

2. **<img src="frontend/icons/lucide/file-text.svg" width="16" height="16" alt="File"> Сохраните конфиг на сервере ноды:**
   ```bash
   sudo nano /opt/monitoring/agent/node.conf
   ```
   
   Вставьте конфиг (пример):
   ```ini
   MASTER_URL="https://your-master-server.com"
   NODE_NAME="node-1"
   NODE_TOKEN="your-node-token"
   COLLECT_INTERVAL=60
   TLS_VERIFY=false
   ```

3. **<img src="frontend/icons/lucide/play.svg" width="16" height="16" alt="Play"> Запустите агента:**
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

4. **<img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Проверьте статус:**
   ```bash
   sudo systemctl status monitoring-agent
   ```

</details>

---

## <img src="frontend/icons/lucide/settings.svg" width="20" height="20" alt="Settings"> Конфигурация

### <img src="frontend/icons/lucide/settings.svg" width="20" height="20" alt="Settings"> Конфигурация агента

Агент настраивается через файл `agent/node.conf` или переменные окружения:

**Файл `agent/node.conf`:**
```ini
MASTER_URL="https://your-master-server.com"
NODE_NAME="node-1"
NODE_TOKEN="your-node-token"
COLLECT_INTERVAL=60
HEARTBEAT_INTERVAL=15
TLS_VERIFY=false
TLS_CERT_PATH=""
```

**Дополнительные опции (node.conf или env):**
```ini
# Опасные команды (kill, reboot, firewall, update-agent) — только с этим флагом
ALLOW_DANGEROUS_COMMANDS=true

# Интервал сбора SMART данных (в циклах, по умолчанию 5)
SMART_INTERVAL=5

# SNMP community string
SNMP_COMMUNITY=public

# Health-check сервер порт (0 = отключен)
HEALTH_PORT=8081

# Отказ от HTTPS (только для dev, НЕ для production)
MASTER_URL_INSECURE=1
```

> **Безопасность**: `node.conf` загружает только разрешённые ключи (allowlist). Опасные переменные окружения (`LD_PRELOAD`, `PATH`, `PYTHONPATH` и др.) блокируются.

**Или переменные окружения:**
```bash
export MASTER_URL=https://your-master-server.com
export NODE_NAME=node-1
export NODE_TOKEN=your-node-token
export COLLECT_INTERVAL=60
```

### <img src="frontend/icons/lucide/database.svg" width="20" height="20" alt="Database"> Конфигурация базы данных

Настроить через переменные окружения:
```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=monitoring
export DB_USER=monitoring
export DB_PASSWORD=password
```

Или отредактировать `monitoring/includes/database.php`.

### <img src="frontend/icons/lucide/globe.svg" width="20" height="20" alt="Globe"> Конфигурация веб-сервера

**Python веб-сервер (dev/staging):**
```bash
export WEB_PORT=8080  # Порт по умолчанию: 8080
export WEB_HOST=0.0.0.0  # Адрес по умолчанию: 0.0.0.0
```

**Nginx (production):**
См. конфигурацию в `nginx/monitoring.conf`

### <img src="frontend/icons/lucide/lock.svg" width="20" height="20" alt="Lock"> Настройка домена и SSL

```bash
# Установить домен
scripts/set_domain.sh example.com www.example.com

# Настроить SSL (Let's Encrypt)
scripts/configure_ssl_letsencrypt.sh example.com admin@example.com www.example.com
```

---

## 📖 Использование

### <img src="frontend/icons/lucide/file-text.svg" width="20" height="20" alt="File"> Пошаговая инструкция

<ol>
<li>
<strong><img src="frontend/icons/lucide/server.svg" width="16" height="16" alt="Server"> Установите панель управления:</strong>
<pre><code>bash &lt;(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh)</code></pre>
</li>

<li>
<strong><img src="frontend/icons/lucide/plus.svg" width="16" height="16" alt="Plus"> Создайте ноду в панели управления:</strong>
<ul>
<li><img src="frontend/icons/lucide/home.svg" width="16" height="16" alt="Home"> Зайдите в панель управления</li>
<li><img src="frontend/icons/lucide/plus.svg" width="16" height="16" alt="Plus"> Создайте новую ноду</li>
<li><img src="frontend/icons/lucide/download.svg" width="16" height="16" alt="Download"> Нажмите "Экспорт конфига" или скопируйте конфиг</li>
</ul>
</li>

<li>
<strong><img src="frontend/icons/lucide/settings.svg" width="16" height="16" alt="Settings"> Установите агент на ноде:</strong>
<pre><code>bash &lt;(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_agent.sh) https://github.com/Differin3/HostMonitor</code></pre>
</li>

<li>
<strong><img src="frontend/icons/lucide/settings.svg" width="16" height="16" alt="Settings"> Настройте конфиг агента:</strong>
<ul>
<li><img src="frontend/icons/lucide/file-text.svg" width="16" height="16" alt="File"> Сохраните конфиг из панели в <code>/opt/monitoring/agent/node.conf</code></li>
<li><img src="frontend/icons/lucide/file-text.svg" width="16" height="16" alt="File"> Или скопируйте конфиг через веб-интерфейс</li>
</ul>
</li>

<li>
<strong><img src="frontend/icons/lucide/play.svg" width="16" height="16" alt="Play"> Запустите агента:</strong>
<pre><code>sudo cp /opt/monitoring/systemd/monitoring-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now monitoring-agent</code></pre>
</li>

<li>
<strong><img src="frontend/icons/lucide/check-circle.svg" width="16" height="16" alt="Check"> Проверьте статус:</strong>
<ul>
<li>📊 В панели управления нода должна появиться как "online"</li>
<li><img src="frontend/icons/lucide/trending-up.svg" width="16" height="16" alt="Trending"> Метрики начнут собираться автоматически</li>
<li><img src="frontend/icons/lucide/info.svg" width="16" height="16" alt="Info"> Проверка: <code>sudo systemctl status monitoring-agent</code></li>
</ul>
</li>
</ol>

### 🐳 Docker

Запуск через Docker Compose:

```bash
cd docker
docker-compose up -d
```

**<img src="frontend/icons/lucide/info.svg" width="16" height="16" alt="Info"> Примечание:** В Docker Compose веб-интерфейс доступен на порту **8080** (маппинг `8080:80`).

---

## 🛡️ Безопасность

### Проведённый аудит

Полный аудит безопасности проведён в несколько раундов. Найдено и исправлено **40+ уязвимостей**:

### Исправленные проблемы (по категориям)

#### <img src="frontend/icons/lucide/x-circle.svg" width="16" height="16" alt="XSS"> XSS (Cross-Site Scripting)
- Все пользовательские данные экранируются через `esc()` / `escapeHtml()` перед вставкой в innerHTML
- `onclick`-хендлеры используют `JSON.stringify()` для безопасной передачи строк
- `node.id` кастуется через `parseInt()` для атрибутов и обработчиков событий
- `provider.url` валидируется через `https?://` regex перед вставкой в `href`
- `iconSrc` и `fallbackLetter` экранируются через `escHtmlAttr()` для атрибутов

#### <img src="frontend/icons/lucide/x-circle.svg" width="16" height="16" alt="Injection"> Injection
- **Command injection** (processes.php, upnp.php): приведение к int для IP и port
- **Command injection** (ports.php): валидация port (1-65535) и proto (tcp/udp)
- **SQL injection**: все запросы через PDO prepared statements
- **XXE**: `LIBXML_NONET` для всех `simplexml_load_string()`
- **SSRF**: allowlist URL, запрет `CURLOPT_FOLLOWLOCATION`
- **node.conf**: allowlist ключей, блокировка `LD_PRELOAD`, `PATH` и др.

#### <img src="frontend/icons/lucide/x-circle.svg" width="16" height="16" alt="Auth"> Аутентификация и сессии
- **CSRF**: токен в сессии + `X-CSRF-Token` header для всех POST-запросов
- **Brute-force**: 5 попыток / 15 мин lockout (файловый rate-limiter)
- **Session fixation**: `session_regenerate_id(true)` после логина
- **Session cookie**: `HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS

#### <img src="frontend/icons/lucide/x-circle.svg" width="16" height="16" alt="Info"> Информационная утечка
- `$e->getMessage()` заменён на generic ошибки во всех API-файлах
- Токены не логируются (только length/presence)
- Экспорт нод автоматически исключает `node_token` и `secret_key`

#### <img src="frontend/icons/lucide/x-circle.svg" width="16" height="16" alt="Agent"> Агент (Python)
- Все опасные команды (`kill`, `reboot`, `firewall`, `update-agent`) требуют `ALLOW_DANGEROUS_COMMANDS=true`
- `install-update`: regex-валидация имени пакета `^[a-zA-Z0-9][a-zA-Z0-9+._:-]*$`
- Health server: биндится на `127.0.0.1` (не `0.0.0.0`)
- `MASTER_URL`: отказ от запуска без HTTPS (override: `MASTER_URL_INSECURE=1`)
- TLS_VERIFY=false: предупреждение в логах при отключении проверки

#### <img src="frontend/icons/lucide/x-circle.svg" width="16" height="16" alt="Headers"> HTTP-заголовки
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `X-XSS-Protection: 1; mode=block`
- `Referrer-Policy: strict-origin-when-cross-origin`

### Завершённые задачи

- [x] SMART мониторинг (агент + API + PHP + JS + CSS)
- [x] Фикс timezones (MySQL ↔ PHP синхронизация)
- [x] Git permissions fix (sudoers + agent auto-fix)
- [x] Nodes export/import (JSON, токены исключаются)
- [x] Оптимизация БД (batch INSERT, 8 индексов, retention 500K/50K)
- [x] Heartbeat reliability (один запрос вместо retry-цикла)
- [x] CSRF защита (интеграция во все POST-эндпоинты)
- [x] XXE защита (LIBXML_NONET)
- [x] Brute-force защита (rate-limiter)
- [x] XSS защита (все JS-файлы)
- [x] Command injection защита (все API-файлы)
- [x] SSRF защита (allowlist URL)
- [x] Agent security hardening (node.conf allowlist, HTTPS enforcement)
- [x] Устранение утечек ошибок ($e->getMessage())
- [x] Security-заголовки
- [x] Session cookie Secure flag

---

## 🆘 Поддержка

### 📚 Документация

- <img src="frontend/icons/lucide/file-text.svg" width="16" height="16" alt="File"> **API документация**: См. файлы в `frontend/docs/api-contracts.md`
- <img src="frontend/icons/lucide/palette.svg" width="16" height="16" alt="Palette"> **UI/UX документация**: См. файлы в `frontend/docs/uiux.md`
- <img src="frontend/icons/lucide/database.svg" width="16" height="16" alt="Database"> **База данных**: Схемы в `database/schema_mysql.sql`

### <img src="frontend/icons/lucide/search.svg" width="20" height="20" alt="Search"> Отладка

**Проверка логов агента:**
```bash
sudo journalctl -u monitoring-agent -f
```

**Проверка логов веб-сервера:**
```bash
# Nginx
sudo tail -f /var/log/nginx/error.log

# Python веб-сервер
sudo journalctl -u monitoring-web -f
```

**Проверка подключения к БД:**
```bash
mysql -u monitoring -p monitoring
```

### 🐛 Сообщить о проблеме

Если вы нашли баг или у вас есть предложение:
1. Проверьте существующие [Issues](https://github.com/Differin3/HostMonitor/issues)
2. Создайте новый Issue с подробным описанием проблемы
3. Приложите логи и скриншоты (если применимо)

---

## <img src="frontend/icons/lucide/file-text.svg" width="20" height="20" alt="File"> Лицензия

Этот проект распространяется под лицензией MIT. См. файл [LICENSE](LICENSE) для подробностей.

---

<div align="center">

**Сделано с ❤️ для мониторинга серверов**

[⬆ Наверх](#-hostmonitor)

</div>
