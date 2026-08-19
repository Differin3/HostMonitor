-- Extra host metrics persisted from the agent snapshot
ALTER TABLE metrics ADD COLUMN memory_used BIGINT NULL;
ALTER TABLE metrics ADD COLUMN memory_total BIGINT NULL;
ALTER TABLE metrics ADD COLUMN disk_used BIGINT NULL;
ALTER TABLE metrics ADD COLUMN disk_total BIGINT NULL;
ALTER TABLE metrics ADD COLUMN swap_percent FLOAT NULL;
ALTER TABLE metrics ADD COLUMN load_avg FLOAT NULL;
ALTER TABLE metrics ADD COLUMN cpu_count SMALLINT NULL;
