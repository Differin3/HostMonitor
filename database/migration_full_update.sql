-- Полная миграция базы данных
-- Обновление для поддержки большого количества нод и загрузки логов с нод
-- ВАЖНО: Выполняйте на резервной копии БД перед применением на продакшене!

USE monitoring;

-- ============================================
-- 1. Добавление поля command_result в nodes
-- ============================================
SET @column_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE table_schema = 'monitoring' AND table_name = 'nodes' AND column_name = 'command_result');
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE nodes ADD COLUMN command_result TEXT NULL AFTER command_timestamp',
    'SELECT "Column command_result already exists in nodes" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 2. Изменение типа id с INT на BIGINT в таблицах логов
-- ============================================

-- logs
ALTER TABLE logs MODIFY COLUMN id BIGINT AUTO_INCREMENT;

-- process_logs
ALTER TABLE process_logs MODIFY COLUMN id BIGINT AUTO_INCREMENT;
ALTER TABLE process_logs MODIFY COLUMN pid INT NULL;

-- container_logs
ALTER TABLE container_logs MODIFY COLUMN id BIGINT AUTO_INCREMENT;

-- ssh_auth_logs
ALTER TABLE ssh_auth_logs MODIFY COLUMN id BIGINT AUTO_INCREMENT;

-- auth_logs (если существует)
SET @table_exists = (SELECT COUNT(*) FROM information_schema.tables 
    WHERE table_schema = 'monitoring' AND table_name = 'auth_logs');
SET @sql = IF(@table_exists > 0,
    'ALTER TABLE auth_logs MODIFY COLUMN id BIGINT AUTO_INCREMENT',
    'SELECT "Table auth_logs does not exist, skipping" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- metrics
ALTER TABLE metrics MODIFY COLUMN id BIGINT AUTO_INCREMENT;

-- ============================================
-- 3. Добавление составных индексов для оптимизации
-- ============================================

-- Индекс для logs (node_id + timestamp)
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = 'monitoring' AND table_name = 'logs' AND index_name = 'idx_node_timestamp');
SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_node_timestamp ON logs (node_id, timestamp)',
    'SELECT "Index idx_node_timestamp already exists on logs" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Индекс для process_logs (node_id + timestamp)
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = 'monitoring' AND table_name = 'process_logs' AND index_name = 'idx_node_timestamp');
SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_node_timestamp ON process_logs (node_id, timestamp)',
    'SELECT "Index idx_node_timestamp already exists on process_logs" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Индекс для process_logs (node_id + pid)
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = 'monitoring' AND table_name = 'process_logs' AND index_name = 'idx_node_pid');
SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_node_pid ON process_logs (node_id, pid)',
    'SELECT "Index idx_node_pid already exists on process_logs" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Индекс для container_logs (node_id + timestamp)
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = 'monitoring' AND table_name = 'container_logs' AND index_name = 'idx_node_timestamp');
SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_node_timestamp ON container_logs (node_id, timestamp)',
    'SELECT "Index idx_node_timestamp already exists on container_logs" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Индекс для ssh_auth_logs (node_id + timestamp)
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = 'monitoring' AND table_name = 'ssh_auth_logs' AND index_name = 'idx_node_timestamp');
SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_node_timestamp ON ssh_auth_logs (node_id, timestamp)',
    'SELECT "Index idx_node_timestamp already exists on ssh_auth_logs" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Индекс для ssh_auth_logs (node_id + ip_address)
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = 'monitoring' AND table_name = 'ssh_auth_logs' AND index_name = 'idx_node_ip');
SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_node_ip ON ssh_auth_logs (node_id, ip_address)',
    'SELECT "Index idx_node_ip already exists on ssh_auth_logs" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Индекс для auth_logs (user_id + timestamp) если таблица существует
SET @table_exists = (SELECT COUNT(*) FROM information_schema.tables 
    WHERE table_schema = 'monitoring' AND table_name = 'auth_logs');
SET @index_exists = (SELECT COUNT(*) FROM information_schema.statistics 
    WHERE table_schema = 'monitoring' AND table_name = 'auth_logs' AND index_name = 'idx_user_timestamp');
SET @sql = IF(@table_exists > 0 AND @index_exists = 0,
    'CREATE INDEX idx_user_timestamp ON auth_logs (user_id, timestamp)',
    'SELECT "Index idx_user_timestamp already exists or table does not exist" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 4. Добавление начальных настроек (если их нет)
-- ============================================

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('log_retention_days', '30'),
('logs_per_page', '100'),
('log_max_rows', '1000000'),
('log_max_rows_per_node', '100000'),
('collect_interval', '60');

-- ============================================
-- 5. Проверка результатов миграции
-- ============================================

SELECT 
    'Migration completed successfully!' AS status,
    'All log tables now use BIGINT for id' AS message1,
    'Composite indexes added for performance' AS message2,
    'command_result column added to nodes table' AS message3;

-- Показать текущую структуру таблиц
SELECT 
    TABLE_NAME,
    COLUMN_NAME,
    DATA_TYPE,
    IS_NULLABLE,
    COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'monitoring' 
    AND TABLE_NAME IN ('logs', 'process_logs', 'container_logs', 'ssh_auth_logs', 'auth_logs', 'metrics', 'nodes')
    AND (COLUMN_NAME = 'id' OR COLUMN_NAME = 'pid' OR COLUMN_NAME = 'command_result')
ORDER BY TABLE_NAME, COLUMN_NAME;

-- Показать добавленные индексы
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'monitoring' 
    AND TABLE_NAME IN ('logs', 'process_logs', 'container_logs', 'ssh_auth_logs', 'auth_logs')
    AND INDEX_NAME LIKE 'idx_%'
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;

