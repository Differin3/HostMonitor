<?php
require_once __DIR__ . '/db_config.php';

function getDbConnection(bool $reset = false) {
    static $pdo = null;

    if ($reset) {
        $pdo = null;
        return null;
    }

    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = db_config_load();
    $replicaOn = db_replica_enabled($cfg);
    $state = db_ha_state_load();
    $failback = !empty($cfg['replica_failback']);
    $now = time();
    $primary = db_endpoint($cfg, 'primary');

    $skipPrimary = false;
    if ($replicaOn && ($state['role'] ?? '') === 'replica') {
        if (!$failback) {
            $skipPrimary = true;
        } else {
            $lastTry = (int)($state['last_primary_try'] ?? 0);
            if ($lastTry > 0 && ($now - $lastTry) < 20) {
                $skipPrimary = true;
            }
        }
    }

    $primaryErr = '';
    if (!$skipPrimary) {
        try {
            $pdo = db_try_connect($primary, $replicaOn ? 12 : 8);
            if (($state['role'] ?? 'primary') !== 'primary') {
                db_ha_state_save('primary', '');
            }
            return $pdo;
        } catch (Throwable $e) {
            $primaryErr = $e->getMessage();
            error_log('Database connection error (primary): ' . $primaryErr);
            if (!$replicaOn) {
                throw new Exception('Не удалось подключиться к базе данных');
            }
        }
    }

    if ($replicaOn) {
        try {
            $replica = db_endpoint($cfg, 'replica');
            $pdo = db_try_connect($replica, 12);
            if (!$skipPrimary || ($state['role'] ?? '') !== 'replica') {
                $reason = $skipPrimary ? (string)($state['reason'] ?? 'failover') : $primaryErr;
                db_ha_state_save('replica', $reason, $skipPrimary ? (int)($state['last_primary_try'] ?? $now) : $now);
            }
            return $pdo;
        } catch (Throwable $e) {
            error_log('Database connection error (replica): ' . $e->getMessage());
        }
    }

    if ($skipPrimary) {
        try {
            $pdo = db_try_connect($primary, 12);
            db_ha_state_save('primary', '');
            return $pdo;
        } catch (Throwable $e) {
            error_log('Database connection error (primary retry): ' . $e->getMessage());
        }
    }

    throw new Exception('Не удалось подключиться к базе данных');
}
