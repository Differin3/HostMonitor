-- Platform columns on nodes (TrueNAS, Proxmox, FreeBSD, ZFS).
-- MySQL: без IF NOT EXISTS (синтаксис MariaDB). Ошибку Duplicate column name можно игнорировать.
-- Панель сама добавляет колонки через nodes_ensure_agent_columns() — этот файл для ручного запуска.

ALTER TABLE nodes ADD COLUMN os_name VARCHAR(64) NULL;
ALTER TABLE nodes ADD COLUMN os_family VARCHAR(64) NULL;
ALTER TABLE nodes ADD COLUMN os_version VARCHAR(64) NULL;
ALTER TABLE nodes ADD COLUMN arch VARCHAR(32) NULL;
ALTER TABLE nodes ADD COLUMN kernel VARCHAR(64) NULL;
ALTER TABLE nodes ADD COLUMN is_truenas TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE nodes ADD COLUMN is_proxmox TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE nodes ADD COLUMN is_synology TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE nodes ADD COLUMN is_freebsd TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE nodes ADD COLUMN has_zfs TINYINT(1) NOT NULL DEFAULT 0;
