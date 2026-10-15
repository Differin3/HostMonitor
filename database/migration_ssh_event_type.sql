-- Структурирование SSH-логов.
--
-- 1. Добавляет машинный тип события event_type (accepted/failed/invalid_user/
--    max_attempts/pam_failure/disconnect/connection_closed/session/other),
--    чтобы панель могла фильтровать и считать статистику по типам.
-- 2. Удаляет мусорные строки journalctl («-- Journal begins at ...»,
--    «-- No entries --»), которые раньше попадали в журнал как события.
-- 3. Бэкфиллит тип для уже накопленных записей.
--
-- На панели то же самое делает logs_ensure_ssh_schema() автоматически при
-- первом запросе к api/logs.php; этот файл нужен для ручного применения.
-- MySQL/MariaDB.

ALTER TABLE ssh_auth_logs ADD COLUMN event_type VARCHAR(32) NULL AFTER process;
ALTER TABLE ssh_auth_logs ADD INDEX idx_event_type (event_type);

DELETE FROM ssh_auth_logs WHERE message LIKE '-- %' OR raw_message LIKE '-- %';

UPDATE ssh_auth_logs SET event_type = 'accepted'         WHERE event_type IS NULL AND success = 1;
UPDATE ssh_auth_logs SET event_type = 'max_attempts'     WHERE event_type IS NULL AND message LIKE '%maximum authentication attempts%';
UPDATE ssh_auth_logs SET event_type = 'pam_failure'      WHERE event_type IS NULL AND message LIKE '%authentication failure%';
UPDATE ssh_auth_logs SET event_type = 'invalid_user'     WHERE event_type IS NULL AND success = 0 AND (message LIKE '%invalid user%' OR message LIKE '%Invalid user%' OR message LIKE '%user=invalid%');
UPDATE ssh_auth_logs SET event_type = 'failed'           WHERE event_type IS NULL AND success = 0;
UPDATE ssh_auth_logs SET event_type = 'disconnect'       WHERE event_type IS NULL AND message LIKE '%disconnect%';
UPDATE ssh_auth_logs SET event_type = 'connection_closed' WHERE event_type IS NULL AND (message LIKE '%Connection closed%' OR message LIKE '%Connection reset%' OR message LIKE '%INFO connection%');
UPDATE ssh_auth_logs SET event_type = 'session'          WHERE event_type IS NULL AND message LIKE '%session %for user%';
UPDATE ssh_auth_logs SET event_type = 'other'            WHERE event_type IS NULL;
