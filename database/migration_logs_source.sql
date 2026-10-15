-- Структурирование системных логов.
--
-- 1. Добавляет колонку source — процесс/юнит-источник (sshd, kernel, CRON,
--    python3, ...). Раньше источник был склеен с сообщением в виде
--    "process: message", из-за чего нельзя было фильтровать по источнику.
-- 2. Бэкфиллит source для уже накопленных записей: префикс до первого
--    двоеточия, если он похож на имя процесса (без пробелов, до 64 символов).
--    Само сообщение не трогаем — интерфейс отрезает префикс при показе.
--
-- На панели то же самое делает logs_ensure_system_schema() автоматически при
-- первом запросе к api/logs.php; этот файл нужен для ручного применения.
-- MySQL/MariaDB.

ALTER TABLE logs ADD COLUMN source VARCHAR(128) NULL AFTER level;
ALTER TABLE logs ADD INDEX idx_source (source);

UPDATE logs
SET source = SUBSTRING_INDEX(message, ':', 1)
WHERE source IS NULL
  AND message LIKE '%:%'
  AND SUBSTRING_INDEX(message, ':', 1) REGEXP '^[A-Za-z0-9_.@/-]{1,64}$';
