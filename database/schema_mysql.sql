-- Схема базы данных MySQL
CREATE DATABASE IF NOT EXISTS monitoring CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE monitoring;

-- Таблица нод
CREATE TABLE IF NOT EXISTS nodes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT DEFAULT 22,
    status VARCHAR(20) DEFAULT 'offline',
    last_seen TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    secret_key TEXT,
    country VARCHAR(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    node_token VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    provider_name VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    provider_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    billing_amount DECIMAL(10, 2),
    billing_currency VARCHAR(3) DEFAULT 'RUB' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    billing_period INT DEFAULT 30,
    last_payment_date DATE,
    next_payment_date DATE,
    last_command VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    command_status VARCHAR(20) DEFAULT 'pending' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    command_timestamp TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_provider (provider_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица платежей
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    amount DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'RUB',
    payment_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id),
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица метрик
CREATE TABLE IF NOT EXISTS metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    cpu_percent FLOAT,
    memory_percent FLOAT,
    disk_percent FLOAT,
    network_in FLOAT,
    network_out FLOAT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица GPU метрик
CREATE TABLE IF NOT EXISTS gpu_metrics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    gpu_index INT,
    gpu_name VARCHAR(255),
    vendor VARCHAR(20),
    utilization FLOAT,
    memory_used BIGINT,
    memory_total BIGINT,
    temperature FLOAT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица процессов
CREATE TABLE IF NOT EXISTS processes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    pid INT,
    name VARCHAR(255),
    cpu_percent FLOAT,
    memory_percent FLOAT,
    status VARCHAR(20),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица контейнеров
CREATE TABLE IF NOT EXISTS containers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    container_id VARCHAR(255),
    name VARCHAR(255),
    image VARCHAR(255),
    status VARCHAR(50),
    cpu_percent FLOAT,
    memory_percent FLOAT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица пользователей
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'viewer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица провайдеров
CREATE TABLE IF NOT EXISTS providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    favicon_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица алертов
CREATE TABLE IF NOT EXISTS alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    level VARCHAR(20) DEFAULT 'info',
    title VARCHAR(255) NOT NULL,
    message TEXT,
    resolved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id),
    INDEX idx_resolved (resolved),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица логов
CREATE TABLE IF NOT EXISTS logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    level VARCHAR(20) DEFAULT 'info',
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    type VARCHAR(32) DEFAULT 'system',
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id),
    INDEX idx_level (level),
    INDEX idx_timestamp (timestamp),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица портов
CREATE TABLE IF NOT EXISTS ports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    port INT NOT NULL,
    type VARCHAR(10) DEFAULT 'tcp',
    process_name VARCHAR(255),
    pid INT,
    status VARCHAR(20) DEFAULT 'open',
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id),
    INDEX idx_port (port),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тестовые данные
-- Пароль для admin: admin (хеш bcrypt)
INSERT INTO users (username, password_hash, role) VALUES 
('admin', '$2y$10$fs/VkiH4LAjThi91CT.NouK7gmTXsKf.Y7xlsBOofMjHtuQvIbMfC', 'admin')
ON DUPLICATE KEY UPDATE username=username;

INSERT INTO providers (name, url, favicon_url) VALUES 
('firstbyte.ru', 'https://billing.firstbyte.ru', 'https://www.google.com/s2/favicons?domain=firstbyte.ru'),
('cloud4box.com', 'https://client.cloud4box.com', 'https://www.google.com/s2/favicons?domain=cloud4box.com'),
('my.aeza.net', 'https://my.aeza.net', 'https://www.google.com/s2/favicons?domain=aeza.net'),
('DigitalOcean', 'https://www.digitalocean.com', 'https://www.google.com/s2/favicons?domain=digitalocean.com'),
('AWS', 'https://aws.amazon.com', 'https://www.google.com/s2/favicons?domain=aws.amazon.com'),
('Azure', 'https://azure.microsoft.com', 'https://www.google.com/s2/favicons?domain=azure.microsoft.com'),
('Google Cloud', 'https://cloud.google.com', 'https://www.google.com/s2/favicons?domain=cloud.google.com'),
('Hetzner', 'https://www.hetzner.com', 'https://www.google.com/s2/favicons?domain=hetzner.com')
ON DUPLICATE KEY UPDATE name=name;

-- Вставляем ноды, используя IGNORE чтобы избежать ошибок при повторном запуске
INSERT IGNORE INTO nodes (name, host, port, status, country, provider_name, provider_url, billing_amount, billing_currency, billing_period, next_payment_date, secret_key, node_token) VALUES 
('node-1', '192.168.1.10', 2222, 'online', 'RU', 'firstbyte.ru', 'https://billing.firstbyte.ru', 500.00, 'RUB', 30, DATE_ADD(CURDATE(), INTERVAL 30 DAY), SHA2(CONCAT('key1', NOW()), 256), SHA2(CONCAT('token1', NOW()), 256)),
('node-2', '192.168.1.11', 2222, 'online', 'NL', 'cloud4box.com', 'https://client.cloud4box.com', 750.00, 'RUB', 30, DATE_ADD(CURDATE(), INTERVAL 25 DAY), SHA2(CONCAT('key2', NOW()), 256), SHA2(CONCAT('token2', NOW()), 256)),
('node-3', '192.168.1.12', 2222, 'offline', 'DE', 'my.aeza.net', 'https://my.aeza.net', 300.00, 'RUB', 30, DATE_ADD(CURDATE(), INTERVAL 20 DAY), SHA2(CONCAT('key3', NOW()), 256), SHA2(CONCAT('token3', NOW()), 256)),
('node-4', '192.168.1.13', 2222, 'online', 'US', 'DigitalOcean', 'https://www.digitalocean.com', 1200.00, 'USD', 30, DATE_ADD(CURDATE(), INTERVAL 15 DAY), SHA2(CONCAT('key4', NOW()), 256), SHA2(CONCAT('token4', NOW()), 256)),
('node-5', '192.168.1.14', 2222, 'online', 'SG', 'Google Cloud', 'https://cloud.google.com', 800.00, 'USD', 30, DATE_ADD(CURDATE(), INTERVAL 10 DAY), SHA2(CONCAT('key5', NOW()), 256), SHA2(CONCAT('token5', NOW()), 256));

-- Вставляем платежи, используя подзапрос для получения ID нод по имени
INSERT IGNORE INTO payments (node_id, amount, currency, payment_date) 
SELECT id, 500.00, 'RUB', DATE_SUB(CURDATE(), INTERVAL 5 DAY) FROM nodes WHERE name = 'node-1'
UNION ALL
SELECT id, 750.00, 'RUB', DATE_SUB(CURDATE(), INTERVAL 10 DAY) FROM nodes WHERE name = 'node-2'
UNION ALL
SELECT id, 300.00, 'RUB', DATE_SUB(CURDATE(), INTERVAL 15 DAY) FROM nodes WHERE name = 'node-3'
UNION ALL
SELECT id, 750.00, 'RUB', DATE_SUB(CURDATE(), INTERVAL 1 DAY) FROM nodes WHERE name = 'node-2'
UNION ALL
SELECT id, 1200.00, 'USD', DATE_SUB(CURDATE(), INTERVAL 3 DAY) FROM nodes WHERE name = 'node-4'
UNION ALL
SELECT id, 800.00, 'USD', DATE_SUB(CURDATE(), INTERVAL 7 DAY) FROM nodes WHERE name = 'node-5'
UNION ALL
SELECT id, 500.00, 'RUB', DATE_SUB(CURDATE(), INTERVAL 35 DAY) FROM nodes WHERE name = 'node-1'
UNION ALL
SELECT id, 1200.00, 'USD', DATE_SUB(CURDATE(), INTERVAL 33 DAY) FROM nodes WHERE name = 'node-4';

-- Добавляем метрики за последние 24 часа для каждой ноды
INSERT INTO metrics (node_id, cpu_percent, memory_percent, disk_percent, network_in, network_out, timestamp) VALUES 
(1, 15.3, 45.2, 32.1, 1024000, 2048000, DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(1, 18.5, 47.8, 33.2, 1056000, 2100000, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(1, 12.1, 43.5, 31.8, 980000, 1950000, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1, 20.3, 49.2, 34.5, 1120000, 2240000, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2, 22.7, 58.9, 41.5, 1536000, 3072000, DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(2, 25.1, 61.2, 42.8, 1600000, 3200000, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(2, 19.8, 56.4, 40.1, 1450000, 2900000, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(2, 28.3, 63.5, 44.2, 1720000, 3440000, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(3, 8.4, 32.6, 28.3, 512000, 1024000, DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(3, 10.2, 34.1, 29.5, 580000, 1160000, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(3, 7.1, 31.2, 27.8, 480000, 960000, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(3, 9.5, 33.8, 28.9, 540000, 1080000, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(4, 35.2, 65.8, 52.3, 2500000, 5000000, DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(4, 38.5, 68.1, 54.2, 2650000, 5300000, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(4, 32.1, 63.5, 50.8, 2350000, 4700000, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(4, 40.2, 70.3, 56.1, 2800000, 5600000, DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(5, 28.6, 55.2, 45.7, 1800000, 3600000, DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(5, 31.4, 57.8, 47.3, 1950000, 3900000, DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(5, 25.9, 52.6, 44.1, 1650000, 3300000, DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(5, 33.7, 59.5, 48.9, 2100000, 4200000, DATE_SUB(NOW(), INTERVAL 3 HOUR))
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO processes (node_id, pid, name, cpu_percent, memory_percent, status) VALUES 
(1, 1234, 'nginx', 5.2, 12.3, 'running'),
(1, 1235, 'mysql', 8.1, 25.4, 'running'),
(1, 1236, 'php-fpm', 3.5, 8.2, 'running'),
(1, 1237, 'redis', 1.8, 4.5, 'running'),
(2, 2345, 'apache', 12.5, 18.7, 'running'),
(2, 2346, 'redis', 2.3, 5.2, 'running'),
(2, 2347, 'postgres', 15.8, 32.1, 'running'),
(2, 2348, 'node', 4.2, 12.5, 'running'),
(3, 3456, 'node', 6.8, 15.9, 'running'),
(3, 3457, 'mongodb', 9.2, 28.4, 'running'),
(3, 3458, 'elasticsearch', 12.5, 45.2, 'running'),
(3, 3459, 'kibana', 3.1, 8.7, 'running'),
(4, 4567, 'docker', 2.1, 5.3, 'running'),
(4, 4568, 'k8s-kubelet', 8.5, 15.2, 'running'),
(4, 4569, 'nginx', 6.2, 10.8, 'running'),
(4, 4570, 'mysql', 18.3, 35.6, 'running'),
(5, 5678, 'nginx', 4.8, 9.2, 'running'),
(5, 5679, 'postgres', 11.2, 22.5, 'running'),
(5, 5680, 'redis', 2.5, 6.1, 'running'),
(5, 5681, 'python', 8.7, 18.3, 'running')
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO containers (node_id, container_id, name, image, status, cpu_percent, memory_percent) VALUES 
(1, 'abc123def456', 'web-server', 'nginx:latest', 'running', 15.3, 22.5),
(1, 'def456ghi789', 'database', 'postgres:15', 'running', 8.7, 45.2),
(1, 'ghi789jkl012', 'redis-cache', 'redis:7', 'running', 2.1, 5.8),
(1, 'jkl012mno345', 'app-backend', 'node:18', 'stopped', 0, 0),
(2, 'mno345pqr678', 'monitoring', 'prometheus:latest', 'running', 5.4, 12.3),
(2, 'pqr678stu901', 'grafana', 'grafana:latest', 'running', 3.2, 8.5),
(2, 'stu901vwx234', 'elasticsearch', 'elasticsearch:8', 'running', 18.5, 35.2),
(2, 'vwx234yza567', 'kibana', 'kibana:8', 'running', 4.1, 12.8),
(3, 'yza567bcd890', 'mongodb', 'mongo:6', 'running', 12.3, 28.5),
(3, 'bcd890efg123', 'node-app', 'node:18', 'running', 6.7, 15.2),
(3, 'efg123hij456', 'nginx-proxy', 'nginx:alpine', 'running', 2.5, 4.8),
(3, 'hij456klm789', 'redis', 'redis:7-alpine', 'stopped', 0, 0),
(4, 'klm789nop012', 'k8s-api', 'k8s-api-server:latest', 'running', 8.9, 18.5),
(4, 'nop012qrs345', 'k8s-scheduler', 'k8s-scheduler:latest', 'running', 3.2, 6.8),
(4, 'qrs345tuv678', 'nginx-lb', 'nginx:latest', 'running', 5.6, 10.2),
(4, 'tuv678wxy901', 'mysql-cluster', 'mysql:8.0', 'running', 22.3, 42.5),
(5, 'wxy901zab234', 'postgres-db', 'postgres:15', 'running', 11.2, 25.8),
(5, 'zab234cde567', 'redis-cluster', 'redis:7', 'running', 2.8, 6.5),
(5, 'cde567fgh890', 'python-api', 'python:3.11', 'running', 7.5, 14.2),
(5, 'fgh890ijk123', 'nginx-frontend', 'nginx:alpine', 'running', 3.1, 5.8)
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO alerts (node_id, level, title, message, resolved) VALUES 
(1, 'warning', 'Высокая загрузка CPU', 'CPU загрузка превышает 80%', FALSE),
(2, 'info', 'Нода перезапущена', 'Нода была перезапущена успешно', FALSE)
ON DUPLICATE KEY UPDATE id=id;

-- Добавляем логи для каждой ноды (по 20 записей)
INSERT INTO logs (node_id, level, message, timestamp) VALUES 
(1, 'info', 'Система мониторинга запущена', DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(1, 'info', 'Нода node-1 подключена', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(1, 'warning', 'Высокая загрузка CPU на node-1: 85%', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1, 'info', 'Обновление конфигурации завершено', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(1, 'debug', 'Проверка соединения с базой данных', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(1, 'info', 'Резервное копирование завершено успешно', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(1, 'warning', 'Недостаточно свободного места на диске', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(1, 'info', 'Сетевое соединение установлено', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(1, 'error', 'Ошибка при подключении к внешнему API', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(1, 'info', 'Процесс nginx перезапущен', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(1, 'warning', 'Высокое использование памяти: 78%', DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(1, 'info', 'Проверка безопасности завершена', DATE_SUB(NOW(), INTERVAL 11 HOUR)),
(1, 'debug', 'Логирование метрик активировано', DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(1, 'info', 'Обновление системы завершено', DATE_SUB(NOW(), INTERVAL 13 HOUR)),
(1, 'warning', 'Медленный ответ от сервера', DATE_SUB(NOW(), INTERVAL 14 HOUR)),
(1, 'info', 'Синхронизация данных завершена', DATE_SUB(NOW(), INTERVAL 15 HOUR)),
(1, 'error', 'Критическая ошибка в модуле обработки', DATE_SUB(NOW(), INTERVAL 16 HOUR)),
(1, 'info', 'Восстановление после сбоя выполнено', DATE_SUB(NOW(), INTERVAL 17 HOUR)),
(1, 'debug', 'Отладка сетевых подключений', DATE_SUB(NOW(), INTERVAL 18 HOUR)),
(1, 'info', 'Система работает стабильно', DATE_SUB(NOW(), INTERVAL 19 HOUR)),
(2, 'info', 'Нода node-2 подключена', DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(2, 'info', 'Инициализация сервисов', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(2, 'warning', 'Обнаружена подозрительная активность', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(2, 'info', 'Обновление конфигурации сети', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2, 'error', 'Ошибка подключения к node-3', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(2, 'info', 'База данных синхронизирована', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(2, 'warning', 'Превышен лимит запросов', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(2, 'info', 'Резервное копирование начато', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(2, 'debug', 'Проверка целостности данных', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(2, 'info', 'Оптимизация производительности', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(2, 'warning', 'Высокая сетевая нагрузка', DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(2, 'info', 'Обновление безопасности применено', DATE_SUB(NOW(), INTERVAL 11 HOUR)),
(2, 'error', 'Сбой в работе модуля кэширования', DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(2, 'info', 'Восстановление модуля кэширования', DATE_SUB(NOW(), INTERVAL 13 HOUR)),
(2, 'debug', 'Мониторинг активных соединений', DATE_SUB(NOW(), INTERVAL 14 HOUR)),
(2, 'info', 'Проверка обновлений системы', DATE_SUB(NOW(), INTERVAL 15 HOUR)),
(2, 'warning', 'Нестабильное соединение с внешним сервисом', DATE_SUB(NOW(), INTERVAL 16 HOUR)),
(2, 'info', 'Перезапуск сервисов выполнен', DATE_SUB(NOW(), INTERVAL 17 HOUR)),
(2, 'debug', 'Анализ производительности', DATE_SUB(NOW(), INTERVAL 18 HOUR)),
(2, 'info', 'Система готова к работе', DATE_SUB(NOW(), INTERVAL 19 HOUR)),
(3, 'info', 'Нода node-3 инициализирована', DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(3, 'warning', 'Низкая производительность диска', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(3, 'info', 'Запуск фоновых процессов', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(3, 'error', 'Критическая ошибка в системе', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(3, 'info', 'Восстановление системы', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(3, 'debug', 'Проверка конфигурации', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(3, 'info', 'Обновление протоколов безопасности', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(3, 'warning', 'Превышен порог использования ресурсов', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(3, 'info', 'Оптимизация запросов к базе данных', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(3, 'debug', 'Тестирование сетевых подключений', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(3, 'info', 'Ротация логов выполнена', DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(3, 'warning', 'Обнаружены устаревшие компоненты', DATE_SUB(NOW(), INTERVAL 11 HOUR)),
(3, 'info', 'Обновление компонентов системы', DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(3, 'error', 'Сбой при обновлении', DATE_SUB(NOW(), INTERVAL 13 HOUR)),
(3, 'info', 'Откат изменений выполнен', DATE_SUB(NOW(), INTERVAL 14 HOUR)),
(3, 'debug', 'Диагностика проблем производительности', DATE_SUB(NOW(), INTERVAL 15 HOUR)),
(3, 'info', 'Применение исправлений', DATE_SUB(NOW(), INTERVAL 16 HOUR)),
(3, 'warning', 'Нестабильная работа сервиса', DATE_SUB(NOW(), INTERVAL 17 HOUR)),
(3, 'info', 'Перезапуск проблемного сервиса', DATE_SUB(NOW(), INTERVAL 18 HOUR)),
(3, 'info', 'Система стабилизирована', DATE_SUB(NOW(), INTERVAL 19 HOUR)),
(4, 'info', 'Нода node-4 подключена', DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(4, 'info', 'Запуск Kubernetes кластера', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(4, 'warning', 'Высокая загрузка кластера', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(4, 'info', 'Масштабирование подов выполнено', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(4, 'debug', 'Мониторинг состояния подов', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(4, 'info', 'Обновление конфигурации кластера', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(4, 'warning', 'Недостаточно ресурсов для новых подов', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(4, 'info', 'Автомасштабирование активировано', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(4, 'error', 'Сбой в работе мастера кластера', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(4, 'info', 'Восстановление мастера кластера', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(4, 'debug', 'Проверка состояния нод кластера', DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(4, 'info', 'Синхронизация конфигураций', DATE_SUB(NOW(), INTERVAL 11 HOUR)),
(4, 'warning', 'Обнаружены конфликты в конфигурации', DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(4, 'info', 'Разрешение конфликтов', DATE_SUB(NOW(), INTERVAL 13 HOUR)),
(4, 'debug', 'Оптимизация распределения нагрузки', DATE_SUB(NOW(), INTERVAL 14 HOUR)),
(4, 'info', 'Обновление образов контейнеров', DATE_SUB(NOW(), INTERVAL 15 HOUR)),
(4, 'warning', 'Некоторые поды не отвечают', DATE_SUB(NOW(), INTERVAL 16 HOUR)),
(4, 'info', 'Перезапуск проблемных подов', DATE_SUB(NOW(), INTERVAL 17 HOUR)),
(4, 'debug', 'Анализ метрик кластера', DATE_SUB(NOW(), INTERVAL 18 HOUR)),
(4, 'info', 'Кластер работает стабильно', DATE_SUB(NOW(), INTERVAL 19 HOUR)),
(5, 'info', 'Нода node-5 подключена', DATE_SUB(NOW(), INTERVAL 0 HOUR)),
(5, 'info', 'Инициализация микросервисов', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(5, 'warning', 'Задержка в ответе API', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(5, 'info', 'Оптимизация запросов к базе данных', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(5, 'debug', 'Мониторинг производительности API', DATE_SUB(NOW(), INTERVAL 4 HOUR)),
(5, 'info', 'Обновление зависимостей', DATE_SUB(NOW(), INTERVAL 5 HOUR)),
(5, 'warning', 'Высокое потребление памяти приложением', DATE_SUB(NOW(), INTERVAL 6 HOUR)),
(5, 'info', 'Применение оптимизаций памяти', DATE_SUB(NOW(), INTERVAL 7 HOUR)),
(5, 'error', 'Ошибка при обработке запроса', DATE_SUB(NOW(), INTERVAL 8 HOUR)),
(5, 'info', 'Исправление ошибки обработки', DATE_SUB(NOW(), INTERVAL 9 HOUR)),
(5, 'debug', 'Тестирование новых функций', DATE_SUB(NOW(), INTERVAL 10 HOUR)),
(5, 'info', 'Развертывание обновления', DATE_SUB(NOW(), INTERVAL 11 HOUR)),
(5, 'warning', 'Обнаружены проблемы совместимости', DATE_SUB(NOW(), INTERVAL 12 HOUR)),
(5, 'info', 'Решение проблем совместимости', DATE_SUB(NOW(), INTERVAL 13 HOUR)),
(5, 'debug', 'Проверка целостности данных', DATE_SUB(NOW(), INTERVAL 14 HOUR)),
(5, 'info', 'Резервное копирование данных', DATE_SUB(NOW(), INTERVAL 15 HOUR)),
(5, 'warning', 'Медленная работа индексов БД', DATE_SUB(NOW(), INTERVAL 16 HOUR)),
(5, 'info', 'Оптимизация индексов базы данных', DATE_SUB(NOW(), INTERVAL 17 HOUR)),
(5, 'debug', 'Анализ производительности запросов', DATE_SUB(NOW(), INTERVAL 18 HOUR)),
(5, 'info', 'Система работает оптимально', DATE_SUB(NOW(), INTERVAL 19 HOUR))
ON DUPLICATE KEY UPDATE id=id;

INSERT INTO ports (node_id, port, type, process_name, pid, status) VALUES 
(1, 80, 'tcp', 'nginx', 1234, 'open'),
(1, 443, 'tcp', 'nginx', 1234, 'open'),
(1, 3306, 'tcp', 'mysql', 1235, 'open'),
(2, 22, 'tcp', 'sshd', 567, 'open'),
(2, 8080, 'tcp', 'node', 2346, 'open'),
(3, 22, 'tcp', 'sshd', 1000, 'open')
ON DUPLICATE KEY UPDATE id=id;

-- Таблица истории обновлений
CREATE TABLE IF NOT EXISTS update_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT,
    node_name VARCHAR(255),
    package VARCHAR(255),
    version VARCHAR(100),
    success BOOLEAN DEFAULT FALSE,
    message TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    INDEX idx_node_id (node_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблица доступных обновлений
CREATE TABLE IF NOT EXISTS node_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    node_name VARCHAR(255),
    package VARCHAR(255) NOT NULL,
    current_version VARCHAR(100),
    new_version VARCHAR(100),
    priority VARCHAR(20) DEFAULT 'normal',
    os_name VARCHAR(100),
    os_version VARCHAR(100),
    kernel_version VARCHAR(100),
    last_check TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_node_package (node_id, package),
    INDEX idx_node_id (node_id),
    INDEX idx_priority (priority),
    INDEX idx_last_check (last_check)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

