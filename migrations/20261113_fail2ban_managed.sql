-- Этап 3: managed rules. Панель хранит желаемое состояние джейлов и
-- перезаписывает 90-hostmonitor.local целиком (desired state), а агент
-- присылает хеш и содержимое файла для сверки и алерта о ручной правке.

CREATE TABLE IF NOT EXISTS fail2ban_jail_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    jail VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    maxretry INT NULL,
    findtime INT NULL,
    bantime INT NULL,
    ignoreip TEXT NULL,
    updated_by INT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_node_jail (node_id, jail),
    KEY idx_node (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fail2ban_managed_state (
    node_id INT PRIMARY KEY,
    file_path VARCHAR(255) NOT NULL DEFAULT '',
    file_hash CHAR(64) NULL,
    file_content MEDIUMTEXT NULL,
    desired_hash CHAR(64) NULL,
    drift TINYINT(1) NOT NULL DEFAULT 0,
    reported_at DATETIME NULL,
    applied_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
