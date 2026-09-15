#!/usr/bin/env php
<?php
// Ночная очистка логов и метрик. Сроки берутся из настроек панели.
// php cleanup_logs.php

require_once __DIR__ . '/../monitoring/includes/database.php';
require_once __DIR__ . '/../monitoring/includes/retention.php';

$pdo = getDbConnection();
$cfg = retention_config();

echo "[cleanup] logs={$cfg['log_days']}d metrics={$cfg['metrics_days']}d alerts={$cfg['alerts_days']}d updates={$cfg['updates_days']}d\n";

$result = retention_run($pdo, true);

foreach ($result['deleted'] as $table => $count) {
    if ((int)$count > 0) {
        echo "[cleanup] {$table}: {$count}\n";
    }
}
echo "[cleanup] всего удалено: {$result['total']}\n";
