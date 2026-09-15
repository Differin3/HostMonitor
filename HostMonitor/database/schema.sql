-- Схема базы данных
CREATE DATABASE monitoring;

\c monitoring;

-- Таблица нод
CREATE TABLE nodes (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) UNIQUE NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INTEGER DEFAULT 22,
    status VARCHAR(20) DEFAULT 'offline',
    last_seen TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Подключение
    secret_key TEXT,
    country VARCHAR(2),
    node_token VARCHAR(255),
    -- Биллинг
    provider_name VARCHAR(255),
    provider_url VARCHAR(500),
    billing_amount DECIMAL(10, 2),
    billing_currency VARCHAR(3) DEFAULT 'RUB',
    billing_period INTEGER DEFAULT 30,
    last_payment_date DATE,
    next_payment_date DATE
);

-- Таблица платежей
CREATE TABLE payments (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id) ON DELETE CASCADE,
    amount DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'RUB',
    payment_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица метрик
CREATE TABLE metrics (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id),
    cpu_percent FLOAT,
    memory_percent FLOAT,
    disk_percent FLOAT,
    network_in FLOAT,
    network_out FLOAT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица процессов
CREATE TABLE processes (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id),
    pid INTEGER,
    name VARCHAR(255),
    cpu_percent FLOAT,
    memory_percent FLOAT,
    status VARCHAR(20),
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица контейнеров
CREATE TABLE containers (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id),
    container_id VARCHAR(255),
    name VARCHAR(255),
    image VARCHAR(255),
    status VARCHAR(50),
    cpu_percent FLOAT,
    memory_percent FLOAT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица пользователей
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) DEFAULT 'viewer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица провайдеров
CREATE TABLE providers (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) UNIQUE NOT NULL,
    url VARCHAR(500),
    favicon_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица логов (системные/общие)
CREATE TABLE logs (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id),
    level VARCHAR(20) DEFAULT 'info',
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    type VARCHAR(32) DEFAULT 'system'
);

-- Таблица логов процессов
-- Таблица настроек системы
CREATE TABLE IF NOT EXISTS settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_settings_key ON settings(setting_key);

-- Начальные настройки
INSERT INTO settings (setting_key, setting_value) VALUES
('log_retention_days', '30')
ON CONFLICT (setting_key) DO NOTHING;

CREATE TABLE process_logs (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id) ON DELETE CASCADE,
    pid INTEGER,
    process VARCHAR(255),
    level VARCHAR(20) DEFAULT 'info',
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_process_logs_node_id ON process_logs(node_id);
CREATE INDEX idx_process_logs_pid ON process_logs(pid);
CREATE INDEX idx_process_logs_process ON process_logs(process);
CREATE INDEX idx_process_logs_level ON process_logs(level);
CREATE INDEX idx_process_logs_timestamp ON process_logs(timestamp);

-- Таблица настроек системы
CREATE TABLE IF NOT EXISTS settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_settings_key ON settings(setting_key);

-- Начальные настройки
INSERT INTO settings (setting_key, setting_value) VALUES
('log_retention_days', '30')
ON CONFLICT (setting_key) DO NOTHING;

-- Таблица логов контейнеров
CREATE TABLE container_logs (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id) ON DELETE CASCADE,
    container_id VARCHAR(255) NOT NULL,
    level VARCHAR(20) DEFAULT 'info',
    message TEXT NOT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица SSH-логов аутентификации (структурированная)
CREATE TABLE ssh_auth_logs (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id) ON DELETE CASCADE,
    level VARCHAR(20) DEFAULT 'info',
    process VARCHAR(255),
    username VARCHAR(255),
    ip_address VARCHAR(45),
    port INTEGER,
    success BOOLEAN,
    message TEXT NOT NULL,      -- краткое описание (SUCCESS/FAIL ...)
    raw_message TEXT,           -- исходная строка из sshd
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Индексы для производительности
CREATE INDEX idx_metrics_node_id ON metrics(node_id);
CREATE INDEX idx_metrics_timestamp ON metrics(timestamp);
CREATE INDEX idx_processes_node_id ON processes(node_id);
CREATE INDEX idx_containers_node_id ON containers(node_id);

-- UPnP: сетевое оборудование
CREATE TABLE IF NOT EXISTS upnp_devices (
    id SERIAL PRIMARY KEY,
    node_id INTEGER REFERENCES nodes(id) ON DELETE CASCADE,
    udn VARCHAR(255) NOT NULL,
    friendly_name VARCHAR(255),
    manufacturer VARCHAR(255),
    manufacturer_url VARCHAR(500),
    model_name VARCHAR(255),
    model_number VARCHAR(100),
    model_description VARCHAR(500),
    serial_number VARCHAR(255),
    device_type VARCHAR(255),
    presentation_url VARCHAR(500),
    location_url VARCHAR(1000),
    host VARCHAR(255),
    ssdp_st VARCHAR(255),
    ssdp_server VARCHAR(255),
    is_igd INTEGER DEFAULT 0,
    connection_status VARCHAR(50),
    wan_ip VARCHAR(45),
    uptime INTEGER DEFAULT 0,
    link_bitrate_up BIGINT DEFAULT 0,
    link_bitrate_down BIGINT DEFAULT 0,
    bytes_sent BIGINT DEFAULT 0,
    bytes_received BIGINT DEFAULT 0,
    last_seen TIMESTAMP NULL,
    software VARCHAR(255),
    ports TEXT,
    hardware_version VARCHAR(255),
    wan_link VARCHAR(50),
    extra TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (node_id, udn)
);

CREATE TABLE IF NOT EXISTS upnp_services (
    id SERIAL PRIMARY KEY,
    device_id INTEGER REFERENCES upnp_devices(id) ON DELETE CASCADE,
    service_type VARCHAR(255),
    service_id VARCHAR(255),
    control_url VARCHAR(1000),
    scpd_url VARCHAR(1000),
    event_url VARCHAR(1000)
);

CREATE TABLE IF NOT EXISTS upnp_port_mappings (
    id SERIAL PRIMARY KEY,
    device_id INTEGER REFERENCES upnp_devices(id) ON DELETE CASCADE,
    remote_host VARCHAR(255) DEFAULT '',
    external_port INTEGER,
    protocol VARCHAR(10),
    internal_port INTEGER,
    internal_client VARCHAR(45),
    enabled INTEGER DEFAULT 1,
    description VARCHAR(255),
    lease_duration INTEGER DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (device_id, external_port, protocol, remote_host)
);

