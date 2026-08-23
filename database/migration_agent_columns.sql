-- Agent version / update columns on nodes.
-- MySQL: без IF NOT EXISTS (синтаксис MariaDB). Ошибку Duplicate column name можно игнорировать.
-- Панель сама добавляет колонки через nodes_ensure_agent_columns() — этот файл для ручного запуска.

ALTER TABLE nodes ADD COLUMN agent_version VARCHAR(32) NULL;
ALTER TABLE nodes ADD COLUMN agent_commit VARCHAR(64) NULL;
ALTER TABLE nodes ADD COLUMN agent_remote_commit VARCHAR(64) NULL;
ALTER TABLE nodes ADD COLUMN agent_branch VARCHAR(64) NULL;
ALTER TABLE nodes ADD COLUMN agent_update_available TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE nodes ADD COLUMN agent_updated_at TIMESTAMP NULL;
ALTER TABLE nodes ADD COLUMN command_result TEXT NULL;
