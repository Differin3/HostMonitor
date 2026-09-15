-- UPnP tables for existing installations
CREATE TABLE IF NOT EXISTS upnp_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NULL,
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
    is_igd TINYINT(1) DEFAULT 0,
    connection_status VARCHAR(50),
    wan_ip VARCHAR(45),
    uptime INT DEFAULT 0,
    link_bitrate_up BIGINT DEFAULT 0,
    link_bitrate_down BIGINT DEFAULT 0,
    bytes_sent BIGINT DEFAULT 0,
    bytes_received BIGINT DEFAULT 0,
    last_seen TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_node_udn (node_id, udn),
    INDEX idx_node_id (node_id),
    INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE upnp_devices ADD COLUMN IF NOT EXISTS software VARCHAR(255) DEFAULT NULL;
ALTER TABLE upnp_devices ADD COLUMN IF NOT EXISTS ports TEXT DEFAULT NULL;
ALTER TABLE upnp_devices ADD COLUMN IF NOT EXISTS hardware_version VARCHAR(255) DEFAULT NULL;
ALTER TABLE upnp_devices ADD COLUMN IF NOT EXISTS wan_link VARCHAR(50) DEFAULT NULL;
ALTER TABLE upnp_devices ADD COLUMN IF NOT EXISTS extra TEXT DEFAULT NULL;

CREATE TABLE IF NOT EXISTS upnp_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    service_type VARCHAR(255),
    service_id VARCHAR(255),
    control_url VARCHAR(1000),
    scpd_url VARCHAR(1000),
    event_url VARCHAR(1000),
    INDEX idx_device_id (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upnp_port_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    remote_host VARCHAR(255) DEFAULT '',
    external_port INT,
    protocol VARCHAR(10),
    internal_port INT,
    internal_client VARCHAR(45),
    enabled TINYINT(1) DEFAULT 1,
    description VARCHAR(255),
    lease_duration INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_map (device_id, external_port, protocol, remote_host),
    INDEX idx_device_id (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
