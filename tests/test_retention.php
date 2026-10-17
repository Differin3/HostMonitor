<?php
/**
 * Тесты очистки истории (retention).
 *
 * Главная регрессия, ради которой тест и появился: кнопка «Очистить историю
 * сейчас» в настройках отдавала Internal server error. Причина — очистка
 * «по нодам» (retention_trim_per_node) гоняла `SELECT node_id ... GROUP BY
 * node_id` по всем таблицам из списка, включая auth_logs. У auth_logs —
 * журнала входов на самой панели — колонки node_id нет, запрос падал, а
 * исключение не ловилось и превращало ручную очистку в 500. Автоматический
 * тик (applyRowCaps=false) до этого кода не доходил, поэтому баг был только
 * у ручной очистки.
 *
 * Статическая часть проверяет, что защита в исходниках на месте, и идёт
 * всегда. Живая часть (реальная MySQL, чужеродная схема) помечается skip,
 * если TEST_DB_DSN не задан — как в test_auth.php.
 *
 * Запуск:  php tests/test_retention.php
 * С DB:    TEST_DB_DSN='mysql:host=127.0.0.1;dbname=monitoring' php tests/test_retention.php
 * Выход:   0 — все проверки прошли, 1 — есть падения.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/monitoring/includes/retention.php';
require_once __DIR__ . '/lib/test_db.php';

$passed = 0;
$failed = 0;
$skipped = 0;

function check(bool $ok, string $name, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  ok   {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function skip(string $name, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  skip {$name} — {$why}\n";
}

$root = dirname(__DIR__);
$retention = (string)file_get_contents($root . '/monitoring/includes/retention.php');
$settingsApi = (string)file_get_contents($root . '/monitoring/api/settings.php');

// —— 1. Защита в исходниках ——
echo "== Очистка устойчива к таблицам без узла/времени ==\n";

check(
    strpos($retention, 'function retention_column_exists') !== false,
    'есть проверка существования колонки'
);
check(
    strpos($retention, "retention_column_exists(\$pdo, \$table, 'node_id')") !== false,
    'прижимание по нодам пропускает таблицы без node_id'
);
check(
    strpos($retention, "retention_column_exists(\$pdo, \$table, 'timestamp')") !== false,
    'прижимание по времени пропускает таблицы без timestamp'
);
check(
    strpos($retention, "retention_column_exists(\$pdo, \$table, \$column)") !== false,
    'удаление по сроку проверяет колонку, а не только таблицу'
);
check(
    strpos($retention, "'errors'") !== false && strpos($retention, 'catch (Throwable') !== false,
    'одна сбойная таблица не роняет всю очистку (сбор ошибок)'
);
check(
    strpos($settingsApi, 'cleanup_history') !== false,
    'API настроек содержит действие ручной очистки'
);

// —— 2. Живая проверка на чужеродной схеме ——
if (test_db_env() === null) {
    skip(
        'живая очистка на схеме без node_id',
        'нет TEST_DB_DSN — запустите с тестовой MySQL, чтобы проверить интеграцию'
    );
} else {
    echo "\n== Живая очистка: журнал панели без node_id ==\n";
    $pdo = test_db_connect();
    test_db_ensure_schema($pdo);

    // auth_logs как в панели: журнал входов, без node_id.
    $pdo->exec('DROP TABLE IF EXISTS auth_logs');
    $pdo->exec(
        'CREATE TABLE auth_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100),
            event_type VARCHAR(30) NOT NULL,
            message TEXT,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $old = date('Y-m-d H:i:s', time() - 400 * 86400);
    $pdo->exec(
        "INSERT INTO auth_logs (username, event_type, message, timestamp)
         VALUES ('admin', 'login', 'ok', '{$old}')"
    );

    $threw = null;
    $result = null;
    try {
        $result = retention_run($pdo, true);
    } catch (Throwable $e) {
        $threw = $e;
    }

    check(
        $threw === null,
        'ручная очистка (applyRowCaps=true) не падает на журнале без node_id',
        $threw !== null ? get_class($threw) . ': ' . $threw->getMessage() : ''
    );

    if ($threw === null) {
        check(
            is_array($result['deleted'] ?? null) && $result['deleted'] !== [],
            'результат содержит разбивку по таблицам'
        );
        check(
            array_key_exists('errors', $result) && is_array($result['errors']),
            'результат содержит список ошибок'
        );
        check(
            !isset($result['errors']['auth_logs']) && !isset($result['errors']['auth_logs_node']),
            'auth_logs не попал в ошибки (прижимание корректно пропущено)'
        );
        check(
            is_int($result['total'] ?? null),
            'итоговое число удалённых строк посчитано'
        );
    }
}

echo "\nПройдено: {$passed}, провалено: {$failed}, пропущено: {$skipped}\n";
exit($failed === 0 ? 0 : 1);
