<?php
/**
 * Создаёт таблицы в уже существующей MySQL-базе.
 * Креды берутся из monitoring/data/db.local.php или переменных окружения.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/includes/database.php';

$cfg = db_config_load();
$name = db_ident((string)$cfg['name']);

$server = db_pdo($cfg, null, 8);
try {
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (PDOException $e) {
    fwrite(STDERR, "[init_db] CREATE DATABASE: " . $e->getMessage() . PHP_EOL);
}

$pdo = db_pdo($cfg, $name, 8);
db_apply_schema($pdo);
fwrite(STDOUT, "[init_db] Схема применена к «{$name}»\n");
