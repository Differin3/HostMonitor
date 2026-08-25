<?php
declare(strict_types=1);

/**
 * Сроки хранения истории и пакетная очистка таблиц, которые растут от агентов.
 */
function retention_int(string $key, int $default, int $min, int $max): int
{
    $raw = function_exists('setting_get') ? setting_get($key, (string)$default) : (string)$default;
    $n = (int)$raw;
    if ($n < $min) {
        return $default;
    }
    return max($min, min($max, $n));
}

function retention_config(): array
{
    return [
        'log_days' => retention_int('log_retention_days', 30, 1, 365),
        'metrics_days' => retention_int('metrics_retention_days', 14, 2, 90),
        'alerts_days' => retention_int('alerts_retention_days', 30, 7, 365),
        'updates_days' => retention_int('update_history_days', 90, 14, 365),
        'log_max_rows' => retention_int('log_max_rows', 1000000, 10000, 10000000),
        'log_max_rows_per_node' => retention_int('log_max_rows_per_node', 100000, 0, 1000000),
        'batch' => 4000,
    ];
}

function retention_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
    );
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function retention_ensure_indexes(PDO $pdo): void
{
    $indexes = [
        ['metrics', 'idx_node_ts', 'ALTER TABLE metrics ADD INDEX idx_node_ts (node_id, timestamp)'],
        ['gpu_metrics', 'idx_gpu_node_ts', 'ALTER TABLE gpu_metrics ADD INDEX idx_gpu_node_ts (node_id, timestamp)'],
        ['alerts', 'idx_alerts_resolved_created', 'ALTER TABLE alerts ADD INDEX idx_alerts_resolved_created (resolved, created_at)'],
    ];
    foreach ($indexes as [$table, $name, $sql]) {
        try {
            if (!retention_index_exists($pdo, $table, $name)) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
            error_log('[retention] index ' . $name . ': ' . $e->getMessage());
        }
    }
}

function retention_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function retention_delete_before(PDO $pdo, string $table, string $column, string $cutoff, int $batch): int
{
    if (!retention_table_exists($pdo, $table)) {
        return 0;
    }
    $deleted = 0;
    $sql = "DELETE FROM `{$table}` WHERE `{$column}` < ? LIMIT {$batch}";
    $stmt = $pdo->prepare($sql);
    for ($i = 0; $i < 80; $i++) {
        $stmt->execute([$cutoff]);
        $n = $stmt->rowCount();
        $deleted += $n;
        if ($n < $batch) {
            break;
        }
    }
    return $deleted;
}

function retention_approx_rows(PDO $pdo, string $table): int
{
    $stmt = $pdo->prepare(
        'SELECT TABLE_ROWS FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn();
}

function retention_trim_oldest(PDO $pdo, string $table, int $keep, int $batch): int
{
    if ($keep <= 0 || !retention_table_exists($pdo, $table)) {
        return 0;
    }
    $approx = retention_approx_rows($pdo, $table);
    if ($approx > 0 && $approx < (int)($keep * 1.15)) {
        return 0;
    }
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($count <= $keep) {
        return 0;
    }
    $need = min($count - $keep, $batch * 20);
    $deleted = 0;
    while ($need > 0) {
        $chunk = min($batch, $need);
        $n = $pdo->exec("DELETE FROM `{$table}` ORDER BY timestamp ASC LIMIT {$chunk}");
        $n = (int)$n;
        $deleted += $n;
        $need -= $n;
        if ($n < $chunk) {
            break;
        }
    }
    return $deleted;
}

function retention_trim_per_node(PDO $pdo, string $table, int $keep, int $batch): int
{
    if ($keep <= 0 || !retention_table_exists($pdo, $table)) {
        return 0;
    }
    $deleted = 0;
    $over = $pdo->query("SELECT node_id, COUNT(*) AS cnt FROM `{$table}` GROUP BY node_id HAVING cnt > {$keep}");
    if (!$over) {
        return 0;
    }
    while ($row = $over->fetch(PDO::FETCH_ASSOC)) {
        $nodeId = (int)$row['node_id'];
        $extra = (int)$row['cnt'] - $keep;
        while ($extra > 0) {
            $chunk = min($batch, $extra);
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE node_id = ? ORDER BY timestamp ASC LIMIT {$chunk}");
            $stmt->execute([$nodeId]);
            $n = $stmt->rowCount();
            $deleted += $n;
            $extra -= $n;
            if ($n < $chunk) {
                break;
            }
        }
    }
    return $deleted;
}

function retention_run(PDO $pdo, bool $applyRowCaps = true): array
{
    retention_ensure_indexes($pdo);
    $cfg = retention_config();
    $batch = $cfg['batch'];
    $logCutoff = date('Y-m-d H:i:s', time() - $cfg['log_days'] * 86400);
    $metricsCutoff = date('Y-m-d H:i:s', time() - $cfg['metrics_days'] * 86400);
    $alertsCutoff = date('Y-m-d H:i:s', time() - $cfg['alerts_days'] * 86400);
    $updatesCutoff = date('Y-m-d H:i:s', time() - $cfg['updates_days'] * 86400);

    $result = [
        'log_days' => $cfg['log_days'],
        'metrics_days' => $cfg['metrics_days'],
        'deleted' => [],
    ];

    $logTables = ['logs', 'process_logs', 'container_logs', 'ssh_auth_logs', 'auth_logs'];
    foreach ($logTables as $table) {
        $result['deleted'][$table] = retention_delete_before($pdo, $table, 'timestamp', $logCutoff, $batch);
    }

    $result['deleted']['metrics'] = retention_delete_before($pdo, 'metrics', 'timestamp', $metricsCutoff, $batch);
    $result['deleted']['gpu_metrics'] = retention_delete_before($pdo, 'gpu_metrics', 'timestamp', $metricsCutoff, $batch);
    $result['deleted']['database_metrics'] = retention_delete_before($pdo, 'database_metrics', 'timestamp', $metricsCutoff, $batch);
    $result['deleted']['smart_metrics'] = retention_delete_before($pdo, 'smart_metrics', 'timestamp', $metricsCutoff, $batch);
    $result['deleted']['update_history'] = retention_delete_before($pdo, 'update_history', 'timestamp', $updatesCutoff, $batch);

    $result['deleted']['alerts'] = 0;
    if (retention_table_exists($pdo, 'alerts')) {
        $stmt = $pdo->prepare("DELETE FROM alerts WHERE resolved = 1 AND created_at < ? LIMIT {$batch}");
        for ($i = 0; $i < 40; $i++) {
            $stmt->execute([$alertsCutoff]);
            $n = $stmt->rowCount();
            $result['deleted']['alerts'] += $n;
            if ($n < $batch) {
                break;
            }
        }
    }

    if ($applyRowCaps) {
        foreach ($logTables as $table) {
            $result['deleted'][$table . '_cap'] = retention_trim_oldest($pdo, $table, $cfg['log_max_rows'], $batch);
            if ($cfg['log_max_rows_per_node'] > 0) {
                $result['deleted'][$table . '_node'] = retention_trim_per_node($pdo, $table, $cfg['log_max_rows_per_node'], $batch);
            }
        }
    }

    $result['total'] = (int)array_sum($result['deleted']);
    return $result;
}

function retention_stamp_path(): string
{
    return dirname(__DIR__) . '/data/retention.last';
}

function retention_maybe_tick(PDO $pdo, int $minIntervalSec = 3600): void
{
    $path = retention_stamp_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $fh = @fopen($path, 'c+');
    if (!$fh) {
        return;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return;
    }
    $last = (int)stream_get_contents($fh);
    if ($last > 0 && (time() - $last) < $minIntervalSec) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return;
    }
    try {
        retention_run($pdo, false);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string)time());
        fflush($fh);
    } catch (Throwable $e) {
        error_log('[retention] tick: ' . $e->getMessage());
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}
