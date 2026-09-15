-- Миграция: добавление поля command_result в таблицу nodes
-- Для хранения результатов выполнения команд агента

USE monitoring;

ALTER TABLE nodes ADD COLUMN command_result TEXT NULL AFTER command_timestamp;

SELECT 'Migration completed: command_result column added to nodes table' AS status;

