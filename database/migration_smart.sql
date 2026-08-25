-- SMART monitoring tables for existing installations

CREATE TABLE IF NOT EXISTS smart_drives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    model VARCHAR(255) DEFAULT NULL,
    serial_number VARCHAR(255) DEFAULT NULL,
    firmware_version VARCHAR(100) DEFAULT NULL,
    capacity_bytes BIGINT DEFAULT NULL,
    rotation_rate INT DEFAULT NULL,
    interface_type VARCHAR(50) DEFAULT NULL,
    sata_version VARCHAR(50) DEFAULT NULL,
    temperature SMALLINT DEFAULT NULL,
    power_on_hours BIGINT DEFAULT NULL,
    health_status VARCHAR(20) DEFAULT 'unknown',
    bay_number INT DEFAULT NULL,
    last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_node_device (node_id, device_name),
    INDEX idx_node_id (node_id),
    INDEX idx_health (health_status),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS smart_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    attribute_id SMALLINT NOT NULL,
    attribute_name VARCHAR(100) NOT NULL,
    attribute_value BIGINT DEFAULT NULL,
    worst_value BIGINT DEFAULT NULL,
    threshold_value BIGINT DEFAULT NULL,
    raw_value BIGINT DEFAULT NULL,
    flags VARCHAR(20) DEFAULT NULL,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_node_device (node_id, device_name),
    INDEX idx_attribute (attribute_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_node_ts (node_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
