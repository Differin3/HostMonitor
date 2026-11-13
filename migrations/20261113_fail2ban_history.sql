-- История изменений managed-правил: кто и когда менял параметры джейла.
-- Нужна, чтобы разбирать «почему файл на ноде стал таким» без git-а по панели.

CREATE TABLE IF NOT EXISTS fail2ban_settings_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    jail VARCHAR(64) NOT NULL,
    action ENUM('save','delete') NOT NULL,
    old_values TEXT NULL,
    new_values TEXT NULL,
    user_id INT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_node_created (node_id, created_at),
    KEY idx_jail (jail)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
