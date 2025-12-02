<div align="center">

# 🖥️ HostMonitor

### Современная система мониторинга серверов с веб-интерфейсом

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Python](https://img.shields.io/badge/Python-3.9+-green.svg)](https://www.python.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0+-orange.svg)](https://www.mysql.com/)

**Мониторинг серверов в реальном времени | Метрики | Процессы | Порты | Контейнеры**

[🚀 Быстрая установка](#-быстрая-установка) • [📖 Документация](#-документация) • [⚙️ Конфигурация](#️-конфигурация) • [🐛 Баги и исправления](#-последние-исправления)

</div>

---

## 📋 Содержание

- [Возможности](#-возможности)
- [Архитектура](#-архитектура)
- [Требования](#-требования)
- [Быстрая установка](#-быстрая-установка)
- [Конфигурация](#️-конфигурация)
- [Использование](#-использование)
- [Последние исправления](#-последние-исправления)
- [Поддержка](#-поддержка)

---

## ✨ Возможности

<table>
<tr>
<td width="50%">

### 📊 Мониторинг
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/activity.svg" width="16" height="16" alt="activity"> **Метрики в реальном времени**: CPU, RAM, Disk, Network
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/cpu.svg" width="16" height="16" alt="cpu"> **GPU мониторинг**: Загрузка, память, температура
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/cpu.svg" width="16" height="16" alt="cpu"> **Процессы**: Список запущенных процессов с деталями
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/network.svg" width="16" height="16" alt="network"> **Порты**: Мониторинг открытых портов и соединений
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/box.svg" width="16" height="16" alt="box"> **Контейнеры**: Docker контейнеры и их статусы

</td>
<td width="50%">

### 🎨 Интерфейс
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/home.svg" width="16" height="16" alt="home"> **Современный веб-интерфейс**: Адаптивный дизайн
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/activity.svg" width="16" height="16" alt="activity"> **Дашборд**: Обзор всех нод на одной странице
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/bar-chart-2.svg" width="16" height="16" alt="bar-chart-2"> **Графики**: Визуализация метрик и трендов
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/alert-circle.svg" width="16" height="16" alt="alert-circle"> **Уведомления**: Система алертов и уведомлений
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/wallet.svg" width="16" height="16" alt="wallet"> **Биллинг**: Учет расходов на провайдеров

</td>
</tr>
</table>

### 🔐 Безопасность
- ✅ Аутентификация пользователей
- ✅ Токены для агентов
- ✅ Защита API endpoints
- ✅ TLS/SSL поддержка

### 🚀 Производительность
- ⚡ Легковесный агент (Python)
- ⚡ Быстрый веб-интерфейс (PHP + JS)
- ⚡ Оптимизированные SQL запросы
- ⚡ Кэширование метрик

---

## 🏗️ Архитектура

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

| <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/server.svg" width="20" height="20" alt="server"> Компонент | Технология | Описание |
|-----------|-----------|----------|
| **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/cpu.svg" width="16" height="16" alt="cpu"> Backend-агент** | Python 3.9+ | Собирает метрики, процессы, порты, контейнеры и отправляет на API |
| **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/home.svg" width="16" height="16" alt="home"> Веб-интерфейс** | PHP 8.0+ + JavaScript | Панель управления с дашбордом и настройками |
| **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/activity.svg" width="16" height="16" alt="activity"> API** | PHP REST API | Обработка запросов от агентов и веб-интерфейса |
| **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/database.svg" width="16" height="16" alt="database"> База данных** | MySQL/MariaDB | Хранение метрик, нод, пользователей, настроек |
| **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/globe.svg" width="16" height="16" alt="globe"> Веб-сервер** | Nginx / Python HTTP Server | Обслуживание веб-интерфейса |

---

## 📦 Требования

### Минимальные требования

<table>
<tr>
<th><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/settings.svg" width="16" height="16" alt="settings"> Компонент</th>
<th>Версия</th>
<th>Примечание</th>
</tr>
<tr>
<td><strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/server.svg" width="16" height="16" alt="server"> ОС</strong></td>
<td>Debian 11/12, Ubuntu 20.04+</td>
<td>Linux для production, Windows для dev</td>
</tr>
<tr>
<td><strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/code.svg" width="16" height="16" alt="code"> Python</strong></td>
<td>3.9+</td>
<td>Для агента и веб-сервера</td>
</tr>
<tr>
<td><strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/code.svg" width="16" height="16" alt="code"> PHP</strong></td>
<td>8.0+</td>
<td>С расширениями: <code>mysql</code>, <code>json</code></td>
</tr>
<tr>
<td><strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/database.svg" width="16" height="16" alt="database"> База данных</strong></td>
<td>MySQL 8.0+ / MariaDB 10.5+</td>
<td>Для хранения данных</td>
</tr>
<tr>
<td><strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/globe.svg" width="16" height="16" alt="globe"> Веб-сервер</strong></td>
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

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/server.svg" width="20" height="20" alt="server"> Установка панели управления (мастер-сервер)

<details>
<summary><b><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/chevron-down.svg" width="16" height="16" alt="chevron-down"> Развернуть инструкцию</b></summary>

#### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/rocket.svg" width="16" height="16" alt="rocket"> Вариант 1: Автоматическая установка (рекомендуется)

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/play.svg" width="16" height="16" alt="play"> Одна команда для установки:**

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh)
```

Или с указанием репозитория:

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh) https://github.com/Differin3/HostMonitor
```

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check-circle.svg" width="16" height="16" alt="check-circle"> Что делает скрипт:**
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/settings.svg" width="16" height="16" alt="settings"> Запрашивает выбор веб-сервера (nginx или Python)
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/globe.svg" width="16" height="16" alt="globe"> Запрашивает порт для веб-интерфейса (по умолчанию: 80 для nginx, 8080 для Python)
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/download.svg" width="16" height="16" alt="download"> Устанавливает все зависимости (Python, MariaDB, PHP)
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/git-branch.svg" width="16" height="16" alt="git-branch"> Клонирует репозиторий в `/opt/monitoring`
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/database.svg" width="16" height="16" alt="database"> Настраивает базу данных
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/server.svg" width="16" height="16" alt="server"> Устанавливает и настраивает выбранный веб-сервер
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Готово к использованию!

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/arrow-right.svg" width="16" height="16" alt="arrow-right"> После установки:**
1. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/globe.svg" width="16" height="16" alt="globe"> Откройте панель управления: `http://your-server-ip:PORT` или `http://your-domain:PORT`
2. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/info.svg" width="16" height="16" alt="info"> Порт будет указан в сообщении после установки
3. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/plus.svg" width="16" height="16" alt="plus"> Создайте ноду и экспортируйте конфиг для установки агента

#### Вариант 2: Ручная установка

```bash
# 1. Установка зависимостей и настройка БД
sudo ./install.sh

# 2. Установка веб-интерфейса
chmod +x scripts/install_web_debian.sh
sudo scripts/install_web_debian.sh
```

</details>

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/cpu.svg" width="20" height="20" alt="cpu"> Установка агента на ноде

<details>
<summary><b><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/chevron-down.svg" width="16" height="16" alt="chevron-down"> Развернуть инструкцию</b></summary>

#### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/rocket.svg" width="16" height="16" alt="rocket"> Автоматическая установка

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/play.svg" width="16" height="16" alt="play"> Одна команда:**

```bash
bash <(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_agent.sh) https://github.com/Differin3/HostMonitor
```

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check-circle.svg" width="16" height="16" alt="check-circle"> Что делает скрипт:**
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/download.svg" width="16" height="16" alt="download"> Устанавливает зависимости (Python, git)
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/git-branch.svg" width="16" height="16" alt="git-branch"> Клонирует репозиторий в `/opt/monitoring`
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/folder.svg" width="16" height="16" alt="folder"> Создает виртуальное окружение
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/package.svg" width="16" height="16" alt="package"> Устанавливает Python-зависимости
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Готов к настройке конфига

#### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/settings.svg" width="16" height="16" alt="settings"> Настройка после установки

1. **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/download.svg" width="16" height="16" alt="download"> Получите конфиг из панели управления:**
   - <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/home.svg" width="16" height="16" alt="home"> Зайдите в панель управления
   - <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/plus.svg" width="16" height="16" alt="plus"> Создайте ноду или откройте существующую
   - <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/download.svg" width="16" height="16" alt="download"> Нажмите "Экспорт конфига" или скопируйте конфиг

2. **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/file-text.svg" width="16" height="16" alt="file-text"> Сохраните конфиг на сервере ноды:**
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

3. **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/play.svg" width="16" height="16" alt="play"> Запустите агента:**
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

4. **<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check-circle.svg" width="16" height="16" alt="check-circle"> Проверьте статус:**
   ```bash
   sudo systemctl status monitoring-agent
   ```

</details>

---

## ⚙️ Конфигурация

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/settings.svg" width="20" height="20" alt="settings"> Конфигурация агента

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

**Или переменные окружения:**
```bash
export MASTER_URL=https://your-master-server.com
export NODE_NAME=node-1
export NODE_TOKEN=your-node-token
export COLLECT_INTERVAL=60
```

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/database.svg" width="20" height="20" alt="database"> Конфигурация базы данных

Настроить через переменные окружения:
```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=monitoring
export DB_USER=monitoring
export DB_PASSWORD=password
```

Или отредактировать `monitoring/includes/database.php`.

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/globe.svg" width="20" height="20" alt="globe"> Конфигурация веб-сервера

**Python веб-сервер (dev/staging):**
```bash
export WEB_PORT=8080  # Порт по умолчанию: 8080
export WEB_HOST=0.0.0.0  # Адрес по умолчанию: 0.0.0.0
```

**Nginx (production):**
См. конфигурацию в `nginx/monitoring.conf`

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/lock.svg" width="20" height="20" alt="lock"> Настройка домена и SSL

```bash
# Установить домен
scripts/set_domain.sh example.com www.example.com

# Настроить SSL (Let's Encrypt)
scripts/configure_ssl_letsencrypt.sh example.com admin@example.com www.example.com
```

---

## 📖 Использование

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/list-ordered.svg" width="20" height="20" alt="list-ordered"> Пошаговая инструкция

<ol>
<li>
<strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/server.svg" width="16" height="16" alt="server"> Установите панель управления:</strong>
<pre><code>bash &lt;(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_panel.sh)</code></pre>
</li>

<li>
<strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/plus.svg" width="16" height="16" alt="plus"> Создайте ноду в панели управления:</strong>
<ul>
<li><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/home.svg" width="16" height="16" alt="home"> Зайдите в панель управления</li>
<li><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/plus.svg" width="16" height="16" alt="plus"> Создайте новую ноду</li>
<li><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/download.svg" width="16" height="16" alt="download"> Нажмите "Экспорт конфига" или скопируйте конфиг</li>
</ul>
</li>

<li>
<strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/cpu.svg" width="16" height="16" alt="cpu"> Установите агент на ноде:</strong>
<pre><code>bash &lt;(curl -sSL https://raw.githubusercontent.com/Differin3/HostMonitor/main/scripts/install_agent.sh) https://github.com/Differin3/HostMonitor</code></pre>
</li>

<li>
<strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/settings.svg" width="16" height="16" alt="settings"> Настройте конфиг агента:</strong>
<ul>
<li><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/file-text.svg" width="16" height="16" alt="file-text"> Сохраните конфиг из панели в <code>/opt/monitoring/agent/node.conf</code></li>
<li><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/copy.svg" width="16" height="16" alt="copy"> Или скопируйте конфиг через веб-интерфейс</li>
</ul>
</li>

<li>
<strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/play.svg" width="16" height="16" alt="play"> Запустите агента:</strong>
<pre><code>sudo cp /opt/monitoring/systemd/monitoring-agent.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now monitoring-agent</code></pre>
</li>

<li>
<strong><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check-circle.svg" width="16" height="16" alt="check-circle"> Проверьте статус:</strong>
<ul>
<li><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/activity.svg" width="16" height="16" alt="activity"> В панели управления нода должна появиться как "online"</li>
<li><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/bar-chart-2.svg" width="16" height="16" alt="bar-chart-2"> Метрики начнут собираться автоматически</li>
<li><img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/info.svg" width="16" height="16" alt="info"> Проверка: <code>sudo systemctl status monitoring-agent</code></li>
</ul>
</li>
</ol>

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/box.svg" width="20" height="20" alt="box"> Docker

Запуск через Docker Compose:

```bash
cd docker
docker-compose up -d
```

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/info.svg" width="16" height="16" alt="info"> Примечание:** В Docker Compose веб-интерфейс доступен на порту **8080** (маппинг `8080:80`).

---

## 🐛 Последние исправления

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check-circle.svg" width="20" height="20" alt="check-circle"> Исправление бага с отображением нод (2024)

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/alert-circle.svg" width="16" height="16" alt="alert-circle"> Проблема:**
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/server.svg" width="16" height="16" alt="server"> Ноды со статусом `offline` пропадали из списка
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/copy.svg" width="16" height="16" alt="copy"> При статусе `online` появлялись дубликаты нод
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/refresh-cw.svg" width="16" height="16" alt="refresh-cw"> Нестабильное отображение при перезагрузке страницы

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/search.svg" width="16" height="16" alt="search"> Причина:**
1. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/code.svg" width="16" height="16" alt="code"> Сложная логика ручного удаления дубликатов по ID
2. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/database.svg" width="16" height="16" alt="database"> Автоматическое обновление статуса в БД при каждом GET-запросе
3. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/link.svg" width="16" height="16" alt="link"> Отсутствие JOIN с таблицей providers

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/wrench.svg" width="16" height="16" alt="wrench"> Решение:**
1. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Возврат к простому LEFT JOIN запросу (как в старой версии)
2. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Убрано автоматическое обновление статуса в БД - статус обновляется только через heartbeat/refresh
3. <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Упрощена логика удаления дубликатов

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check-circle.svg" width="16" height="16" alt="check-circle"> Результат:**
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Все ноды отображаются корректно (online/offline)
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Нет дубликатов
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Стабильное отображение
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/check.svg" width="16" height="16" alt="check"> Меньше нагрузка на БД

**<img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/file-text.svg" width="16" height="16" alt="file-text"> Файлы:**
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/code.svg" width="16" height="16" alt="code"> `monitoring/api/nodes.php` - основной файл с исправлениями

---

## 🆘 Поддержка

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/book.svg" width="20" height="20" alt="book"> Документация

- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/file-text.svg" width="16" height="16" alt="file-text"> **API документация**: См. файлы в `frontend/docs/api-contracts.md`
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/palette.svg" width="16" height="16" alt="palette"> **UI/UX документация**: См. файлы в `frontend/docs/uiux.md`
- <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/database.svg" width="16" height="16" alt="database"> **База данных**: Схемы в `database/schema_mysql.sql`

### <img src="https://raw.githubusercontent.com/lucide-icons/lucide/main/icons/search.svg" width="20" height="20" alt="search"> Отладка

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

## 📄 Лицензия

Этот проект распространяется под лицензией MIT. См. файл [LICENSE](LICENSE) для подробностей.

---

<div align="center">

**Сделано с ❤️ для мониторинга серверов**

[⬆ Наверх](#-hostmonitor)

</div>
