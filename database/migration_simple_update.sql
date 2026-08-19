-- Упрощенная миграция базы данных (без проверок)
-- Используйте этот файл если уверены что индексы и поля еще не созданы
-- ВАЖНО: Может выдать ошибки если индексы/поля уже существуют!

USE monitoring;

-- 1. Добавление поля command_result в nodes
ALTER TABLE nodes ADD COLUMN command_result TEXT NULL AFTER command_timestamp;

-- 2. Изменение типа id на BIGINT
ALTER TABLE logs MODIFY COLUMN id BIGINT AUTO_INCREMENT;
ALTER TABLE process_logs MODIFY COLUMN id BIGINT AUTO_INCREMENT;
ALTER TABLE process_logs MODIFY COLUMN pid INT NULL;
ALTER TABLE container_logs MODIFY COLUMN id BIGINT AUTO_INCREMENT;
ALTER TABLE ssh_auth_logs MODIFY COLUMN id BIGINT AUTO_INCREMENT;
ALTER TABLE metrics MODIFY COLUMN id BIGINT AUTO_INCREMENT;

-- 3. Добавление составных индексов
CREATE INDEX idx_node_timestamp ON logs (node_id, timestamp);
CREATE INDEX idx_node_timestamp ON process_logs (node_id, timestamp);
CREATE INDEX idx_node_pid ON process_logs (node_id, pid);
CREATE INDEX idx_node_timestamp ON container_logs (node_id, timestamp);
CREATE INDEX idx_node_timestamp ON ssh_auth_logs (node_id, timestamp);
CREATE INDEX idx_node_ip ON ssh_auth_logs (node_id, ip_address);

-- 4. Добавление начальных настроек
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('log_retention_days', '30'),
('logs_per_page', '100'),
('log_max_rows', '1000000'),
('log_max_rows_per_node', '100000'),
('collect_interval', '60');

SELECT 'Migration completed!' AS status;

