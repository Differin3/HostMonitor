#!/usr/bin/env php
<?php
// Опрос наблюдаемых СУБД. cron каждые 5 минут.
require_once __DIR__ . '/../monitoring/includes/database.php';
require_once __DIR__ . '/../monitoring/includes/db_monitor.php';

$pdo = getDbConnection();
dbmon_ensure_tables($pdo);
$list = dbmon_probe_all($pdo, false);
$online = 0;
foreach ($list as $row) {
    if (($row['status'] ?? '') === 'online') {
        $online++;
    }
}
echo '[dbmon] ' . count($list) . ' баз, онлайн ' . $online . PHP_EOL;
