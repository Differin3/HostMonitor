<?php
declare(strict_types=1);

// Каталог monitoring/includes. Модули лежат уровнем ниже, поэтому их
// собственный __DIR__ на уровень глубже; пути конфигурации и данных
// должны считаться от каталога, где лежит db_config.php.
if (!defined('DB_INCLUDES_DIR')) {
    define('DB_INCLUDES_DIR', dirname(__DIR__));
}

/**
 * Схема и первичная инициализация.
 *
 * Применение schema.sql и определение состояния «пользователей ещё нет».
 *
 * Часть monitoring/includes/db_config.php; подключается оттуда.
 * Напрямую этот файл не включают.
 */

function db_schema_path(): string
{
    $name = 'schema_mysql.sql';
    $here = dirname(DB_INCLUDES_DIR);
    $candidates = [
        dirname($here) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $name,
        $here . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $name,
        $here . DIRECTORY_SEPARATOR . $name,
        $here . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $name,
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return $candidates[0];
}

function db_apply_schema(PDO $pdo): void
{
    $path = db_schema_path();
    if (!is_file($path)) {
        throw new RuntimeException('Не найден database/schema_mysql.sql');
    }
    $sql = (string)file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $chunks = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($chunks as $chunk) {
        $stmt = trim($chunk);
        if ($stmt === '') {
            continue;
        }
        $head = strtoupper(ltrim($stmt));
        if (strpos($head, 'CREATE DATABASE') === 0 || strpos($head, 'USE ') === 0) {
            continue;
        }
        $isDdl = strpos($head, 'CREATE') === 0 || strpos($head, 'ALTER') === 0;
        $isSeed = (bool)preg_match('/^INSERT\s+(IGNORE\s+)?INTO\s+(settings|providers)\b/i', $stmt);
        if (!$isDdl && !$isSeed) {
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if ($isSeed || in_array($code, [1050, 1060, 1061, 1062], true)) {
                continue;
            }
            throw $e;
        }
    }
}

function db_has_users(PDO $pdo): bool
{
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        return $n > 0;
    } catch (Throwable $e) {
        return false;
    }
}
