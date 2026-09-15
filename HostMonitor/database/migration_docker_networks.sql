-- Docker container networks / published ports snapshot
ALTER TABLE containers ADD COLUMN networks TEXT NULL;
ALTER TABLE containers ADD COLUMN ports TEXT NULL;
ALTER TABLE containers ADD COLUMN ipv4 VARCHAR(45) NULL;
ALTER TABLE containers ADD COLUMN network_mode VARCHAR(128) NULL;
ALTER TABLE containers ADD COLUMN raw_status VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS docker_networks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    network_id VARCHAR(64) NOT NULL,
    name VARCHAR(255),
    driver VARCHAR(64),
    scope VARCHAR(32),
    subnet VARCHAR(64),
    gateway VARCHAR(45),
    containers TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_node_net (node_id, network_id),
    INDEX idx_node_id (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
