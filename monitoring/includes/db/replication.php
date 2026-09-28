<?php
declare(strict_types=1);

// Каталог monitoring/includes. Модули лежат уровнем ниже, поэтому их
// собственный __DIR__ на уровень глубже; пути конфигурации и данных
// должны считаться от каталога, где лежит db_config.php.
if (!defined('DB_INCLUDES_DIR')) {
    define('DB_INCLUDES_DIR', dirname(__DIR__));
}

/**
 * Репликация данных.
 *
 * Копирование баз по чанкам с сохранением FK и PK, пинги, оценка объёма таблиц, восстановление проверок целостности.
 *
 * Часть monitoring/includes/db_config.php; подключается оттуда.
 * Напрямую этот файл не включают.
 */

function db_try_connect(array $ep, int $timeout = 3): PDO
{
    $name = (string)($ep['name'] ?? '');
    if ($name === '') {
        throw new InvalidArgumentException('Не указано имя базы');
    }
    $pdo = db_pdo($ep, $name, $timeout);
    $pdo->query('SELECT 1');
    db_sync_timezone($pdo);
    return $pdo;
}

function db_ping_endpoint(array $ep, int $timeout = 3): array
{
    $started = microtime(true);
    try {
        db_try_connect($ep, $timeout);
        return [
            'ok' => true,
            'ms' => (int)round((microtime(true) - $started) * 1000),
            'error' => null,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'ms' => (int)round((microtime(true) - $started) * 1000),
            'error' => $e->getMessage(),
        ];
    }
}

function db_list_base_tables(PDO $pdo): array
{
    $stmt = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'');
    $tables = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = (string)$row[0];
    }
    return $tables;
}

function db_strip_foreign_keys(string $ddl): string
{
    $ddl = preg_replace('/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN KEY\s*\([^)]+\)\s*REFERENCES\s*`[^`]+`\s*\([^)]+\)(?:\s+ON DELETE (?:SET NULL|CASCADE|RESTRICT|NO ACTION))?(?:\s+ON UPDATE (?:SET NULL|CASCADE|RESTRICT|NO ACTION))?/i', '', $ddl) ?? $ddl;
    $ddl = preg_replace('/,?\s*FOREIGN KEY\s*\([^)]+\)\s*REFERENCES\s*`[^`]+`\s*\([^)]+\)(?:\s+ON DELETE (?:SET NULL|CASCADE|RESTRICT|NO ACTION))?(?:\s+ON UPDATE (?:SET NULL|CASCADE|RESTRICT|NO ACTION))?/i', '', $ddl) ?? $ddl;
    return preg_replace('/,\s*\)/', ')', $ddl) ?? $ddl;
}

function db_insert_batch(PDO $pdo, string $table, array $cols, array $rows): void
{
    if ($rows === [] || $cols === []) {
        return;
    }
    $colSql = implode(',', array_map('db_quote_ident', $cols));
    $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $sql = 'INSERT INTO ' . db_quote_ident($table) . " ({$colSql}) VALUES "
        . implode(',', array_fill(0, count($rows), $placeholders));
    $params = [];
    foreach ($rows as $row) {
        foreach ($cols as $col) {
            $params[] = $row[$col] ?? null;
        }
    }
    $pdo->prepare($sql)->execute($params);
}

function db_copy_table_schema(PDO $src, PDO $dst, string $table): void
{
    $quoted = db_quote_ident($table);
    $createRow = $src->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC) ?: [];
    $ddl = (string)($createRow['Create Table'] ?? $createRow['Create View'] ?? '');
    if ($ddl === '') {
        $vals = array_values($createRow);
        $ddl = (string)($vals[1] ?? '');
    }
    if ($ddl === '') {
        throw new RuntimeException('Не удалось прочитать схему таблицы ' . $table);
    }
    $dst->exec('DROP TABLE IF EXISTS ' . $quoted);
    $dst->exec(db_strip_foreign_keys($ddl));
}

function db_table_pk_column(PDO $pdo, string $table): ?string
{
    $quoted = db_quote_ident($table);
    try {
        $stmt = $pdo->query('SHOW KEYS FROM ' . $quoted . " WHERE Key_name = 'PRIMARY'");
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[] = (string)($row['Column_name'] ?? '');
        }
        if (count($cols) === 1 && $cols[0] !== '') {
            return $cols[0];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return null;
}

function db_table_approx_rows(PDO $pdo, string $table): int
{
    $quoted = db_quote_ident($table);
    try {
        $n = $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
        return max(0, (int)$n);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Копирует порцию строк таблицы. Короткий запрос — чтобы не ловить HTTP 504 у nginx.
 *
 * @return array{rows:int,next_offset:int,next_cursor:?string,table_done:bool,pk:?string}
 */
function db_copy_table_chunk(
    PDO $src,
    PDO $dst,
    string $table,
    int $offset = 0,
    ?string $cursor = null,
    int $limit = 200,
    float $maxSeconds = 5.0
): array {
    // Маленькие порции: удалённый SkySQL/SSL + nginx иначе отдают HTTP 504.
    $limit = max(25, min(400, $limit));
    $quoted = db_quote_ident($table);
    $pk = db_table_pk_column($src, $table);
    $started = microtime(true);
    $count = 0;
    $nextCursor = $cursor;
    $batch = [];
    $cols = null;
    $insertBatch = 40;

    if ($pk !== null) {
        $pkQuoted = db_quote_ident($pk);
        if ($cursor !== null && $cursor !== '') {
            $stmt = $src->prepare("SELECT * FROM {$quoted} WHERE {$pkQuoted} > ? ORDER BY {$pkQuoted} ASC LIMIT {$limit}");
            $stmt->execute([$cursor]);
        } else {
            $stmt = $src->query("SELECT * FROM {$quoted} ORDER BY {$pkQuoted} ASC LIMIT {$limit}");
        }
    } else {
        $stmt = $src->query("SELECT * FROM {$quoted} LIMIT {$limit} OFFSET " . max(0, $offset));
    }

    $stoppedEarly = false;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($cols === null) {
            $cols = array_keys($row);
        }
        $batch[] = $row;
        if ($pk !== null) {
            $nextCursor = (string)($row[$pk] ?? $nextCursor);
        }
        if (count($batch) >= $insertBatch) {
            db_insert_batch($dst, $table, $cols, $batch);
            $count += count($batch);
            $batch = [];
            if ((microtime(true) - $started) >= $maxSeconds) {
                $stoppedEarly = true;
                break;
            }
        }
    }
    if ($batch !== [] && $cols !== null) {
        db_insert_batch($dst, $table, $cols, $batch);
        $count += count($batch);
    }
    $stmt->closeCursor();

    // table_done только если выборка исчерпана, а не из‑за лимита времени.
    $tableDone = !$stoppedEarly && $count < $limit;
    return [
        'rows' => $count,
        'next_offset' => $offset + $count,
        'next_cursor' => $pk !== null ? $nextCursor : null,
        'table_done' => $tableDone,
        'pk' => $pk,
    ];
}

function db_copy_table(PDO $src, PDO $dst, string $table): int
{
    db_copy_table_schema($src, $dst, $table);
    $offset = 0;
    $cursor = null;
    $total = 0;
    do {
        $chunk = db_copy_table_chunk($src, $dst, $table, $offset, $cursor, 2000, 60.0);
        $total += $chunk['rows'];
        $offset = $chunk['next_offset'];
        $cursor = $chunk['next_cursor'];
    } while (!$chunk['table_done']);
    return $total;
}

function db_sync_endpoints(string $direction): array
{
    if ($direction !== 'to_replica' && $direction !== 'to_primary') {
        throw new InvalidArgumentException('direction: to_replica или to_primary');
    }
    $cfg = db_config_load();
    if (!db_replica_enabled($cfg)) {
        throw new RuntimeException('Сначала включите резервную базу');
    }
    $primary = db_endpoint($cfg, 'primary');
    $replica = db_endpoint($cfg, 'replica');
    if (db_endpoint_same($primary, $replica)) {
        throw new RuntimeException('Основная и резервная база совпадают — копировать некуда');
    }
    if ($direction === 'to_replica') {
        return [
            'src' => $primary,
            'dst' => $replica,
            'source_label' => 'основная',
            'target_label' => 'резервная',
        ];
    }
    return [
        'src' => $replica,
        'dst' => $primary,
        'source_label' => 'резервная',
        'target_label' => 'основная',
    ];
}

function db_sync_restore_checks(PDO $dst): void
{
    $dst->exec('SET UNIQUE_CHECKS=1');
    $dst->exec('SET FOREIGN_KEY_CHECKS=1');
}

function db_copy_database(PDO $src, PDO $dst): array
{
    @set_time_limit(0);
    $dst->exec('SET FOREIGN_KEY_CHECKS=0');
    $dst->exec('SET UNIQUE_CHECKS=0');
    $tables = db_list_base_tables($src);
    $copied = [];
    foreach ($tables as $table) {
        $copied[$table] = db_copy_table($src, $dst, $table);
    }
    db_sync_restore_checks($dst);
    return ['tables' => $copied, 'table_count' => count($copied), 'row_count' => array_sum($copied)];
}
