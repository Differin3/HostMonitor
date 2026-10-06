-- =============================================================================
-- 001_ed25519_auth.sql — переход на Ed25519-подписи и разделение ролей
--
-- Идемпотентна: можно запускать повторно.
--   * CREATE TABLE IF NOT EXISTS — нативная идемпотентность;
--   * ADD COLUMN — MySQL не знает «IF NOT EXISTS», поэтому колонка добавляется
--     только если её нет в information_schema (динамический PREPARE).
--     Ветка «уже есть» — это 'DO 0', а не 'SELECT 1': набор строк от EXECUTE
--     остаётся непрочитанным в PHP-клиентах и ломает следующий запрос.
--
-- ВАЖНО: node_token / secret_key НЕ удаляются — существующие ноды продолжают
-- работать на Bearer-токенах до полной миграции.
--
-- Применение:
--   mysql -h<host> -u<user> -p <monitoring> < migrations/001_ed25519_auth.sql
-- =============================================================================

-- ─── 1. Колонки в nodes ───────────────────────────────────────────────────────

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'nodes'
        AND COLUMN_NAME  = 'public_key') = 0,
    'ALTER TABLE nodes ADD COLUMN public_key TEXT NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'nodes'
        AND COLUMN_NAME  = 'scopes') = 0,
    'ALTER TABLE nodes ADD COLUMN scopes JSON NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'nodes'
        AND COLUMN_NAME  = 'enrolled_at') = 0,
    'ALTER TABLE nodes ADD COLUMN enrolled_at DATETIME NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'nodes'
        AND COLUMN_NAME  = 'key_rotated_at') = 0,
    'ALTER TABLE nodes ADD COLUMN key_rotated_at DATETIME NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2. Одноразовые коды enrollment (TTL 15 мин) ─────────────────────────────

CREATE TABLE IF NOT EXISTS enrollment_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    created_by INT NULL,
    ip_address VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    node_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_expires_at (expires_at),
    INDEX idx_node_id (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3. Очередь команд (pull-модель, TTL, approval) ───────────────────────────

CREATE TABLE IF NOT EXISTS node_commands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    command VARCHAR(32) NOT NULL,
    args TEXT NULL,
    scope VARCHAR(64) NOT NULL,
    dangerous TINYINT(1) NOT NULL DEFAULT 0,
    require_2fa TINYINT(1) NOT NULL DEFAULT 0,
    approval_token CHAR(64) NULL,
    acknowledged TINYINT(1) NOT NULL DEFAULT 0,
    command_status VARCHAR(20) NULL,
    result TEXT NULL,
    error TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    INDEX idx_node_ack (node_id, acknowledged, expires_at),
    INDEX idx_node_created (node_id, created_at),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 4. Аудит команд ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS command_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    command_id INT NULL,
    node_id INT NOT NULL,
    command VARCHAR(32) NOT NULL,
    action VARCHAR(24) NOT NULL,
    actor_type VARCHAR(16) NOT NULL,
    actor_id INT NULL,
    detail TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_node_created (node_id, created_at),
    INDEX idx_command_id (command_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 5. Лог запросов агентов (аудит + детект replay) ──────────────────────────

CREATE TABLE IF NOT EXISTS agent_requests_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    endpoint VARCHAR(128) NOT NULL,
    method VARCHAR(8) NOT NULL,
    auth_type VARCHAR(16) NOT NULL,
    status SMALLINT NOT NULL DEFAULT 200,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_node_created (node_id, created_at),
    INDEX idx_status_created (status, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 6. Токены восстановления (TTL 30 мин, одноразовые) ──────────────────────

CREATE TABLE IF NOT EXISTS node_recovery_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_by INT NULL,
    ip_address VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token_hash (token_hash),
    INDEX idx_expires_at (expires_at),
    INDEX idx_node_id (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 7. История ротации ключей ────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS key_rotation_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    node_id INT NOT NULL,
    old_key_fp VARCHAR(64) NULL,
    new_key_fp VARCHAR(64) NULL,
    actor_type VARCHAR(16) NOT NULL,
    actor_id INT NULL,
    reason VARCHAR(32) NOT NULL DEFAULT 'rotate',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_node_created (node_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 8. Rate limit по username ────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    message VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username_created (username, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Таблицу могли создать до появления колонки message — докидываем.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = 'login_attempts'
        AND COLUMN_NAME  = 'message') = 0,
    'ALTER TABLE login_attempts ADD COLUMN message VARCHAR(255) NULL',
    'DO 0'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
