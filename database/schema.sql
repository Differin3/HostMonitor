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

-- Индексы для производительности
CREATE INDEX idx_metrics_node_id ON metrics(node_id);
CREATE INDEX idx_metrics_timestamp ON metrics(timestamp);
CREATE INDEX idx_processes_node_id ON processes(node_id);
CREATE INDEX idx_containers_node_id ON containers(node_id);

