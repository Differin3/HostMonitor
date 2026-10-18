<?php
/**
 * Тесты per-interface метрик (страница «Сеть»).
 *
 * Что появилось и зачем: агент теперь отдаёт счётчики по каждому сетевому
 * интерфейсу (трафик/с, ошибки, дропы), панель складывает их в
 * network_interface_metrics и отдаёт историю через metrics.php?interfaces=1.
 * Раньше эти данные жили только в снапшоте network_interfaces (текущее
 * состояние без истории), поэтому «ошибки на линке растут» увидеть было
 * нельзя.
 *
 * Статическая часть проверяет, что сбор, хранение, выдача и очистка на месте,
 * и идёт всегда. Живая часть (реальная MySQL) помечается skip без TEST_DB_DSN
 * — как в test_retention.php и test_auth.php.
 *
 * Запуск:  php tests/test_interface_metrics.php
 * С DB:    TEST_DB_DSN='mysql:host=127.0.0.1;dbname=monitoring' php tests/test_interface_metrics.php
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
$read = static fn(string $rel): string => (string)file_get_contents($root . '/' . $rel);

$metricsApi = $read('monitoring/api/metrics.php');
$portsApi = $read('monitoring/api/ports.php');
$retention = $read('monitoring/includes/retention.php');
$schema = $read('database/schema_mysql.sql');
$agentSystem = $read('agent/system.py');
$agentMain = $read('agent/main.py');
$agentNetwork = $read('agent/network.py');

// —— 1. Агент собирает per-interface счётчики ——
echo "== Агент отдаёт счётчики по интерфейсам ==\n";

check(
    strpos($agentSystem, 'def _collect_iface_rates') !== false,
    'есть сбор per-interface счётчиков в system.py'
);
check(
    strpos($agentSystem, 'psutil.net_io_counters(pernic=True)') !== false,
    'счётчики берутся из psutil по каждому интерфейсу'
);
foreach (["'lo'", "'veth'", "'tun'", "'tap'"] as $skipToken) {
    check(
        strpos($agentSystem, $skipToken) !== false,
        'служебный интерфейс ' . $skipToken . ' исключается из сбора'
    );
}
check(
    strpos($agentSystem, '"interfaces": iface_metrics') !== false,
    'per-interface данные попадают в payload метрик'
);
check(
    strpos($agentMain, 'last_iface_counters') !== false,
    'состояние прошлого опроса для расчёта Б/с хранится в агенте'
);
check(
    strpos($agentNetwork, 'def _mac_from_sysfs') !== false && strpos($agentNetwork, "'mac'") !== false,
    'снапшот интерфейсов обогащён MAC из /sys/class/net'
);

// —— 2. Панель хранит и отдаёт историю ——
echo "\n== Панель хранит историю по интерфейсам ==\n";

check(
    strpos($metricsApi, 'function metrics_ensure_iface_table') !== false,
    'есть создание таблицы network_interface_metrics'
);
check(
    strpos($metricsApi, 'CREATE TABLE IF NOT EXISTS network_interface_metrics') !== false,
    'таблица network_interface_metrics создаётся рантаймом'
);
foreach (['rx_sec', 'tx_sec', 'errin', 'errout', 'dropin', 'dropout'] as $col) {
    check(
        strpos($metricsApi, $col) !== false,
        'в таблице есть колонка ' . $col
    );
}
check(
    strpos($metricsApi, "\$row['interfaces']") !== false,
    'POST метрик раскладывает интерфейсы построчно'
);
check(
    strpos($metricsApi, 'network_interface_metrics') !== false
        && strpos($metricsApi, "!empty(\$_GET['interfaces'])") !== false,
    'GET ?interfaces=1 обрабатывается отдельным путём'
);
check(
    strpos($metricsApi, 'MAX(errin + errout + dropin + dropout)') !== false,
    'ошибки за бакет считаются как прирост кумулятивного счётчика'
);

// —— 3. Снапшот интерфейсов и соседи на странице «Сеть» ——
echo "\n== Снапшот интерфейсов (MAC) и соседи ==\n";

check(
    strpos($portsApi, 'function ensure_network_interfaces_mac_column') !== false,
    'колонка MAC добавляется отдельной ensure-функцией'
);
check(
    strpos($portsApi, 'ADD COLUMN mac VARCHAR(32)') !== false,
    'миграция MAC идемпотентна (ALTER ... ADD COLUMN)'
);
check(
    strpos($portsApi, "network_interfaces (node_id, name, mac, ip") !== false,
    'MAC сохраняется вместе с интерфейсом'
);
check(
    strpos($portsApi, "=== 'neighbors'") !== false,
    'есть выдача соседей (action=neighbors)'
);

// —— 4. Схема и очистка знают о новой таблице ——
echo "\n== Схема и retention ==\n";

check(
    strpos($schema, 'CREATE TABLE IF NOT EXISTS network_interface_metrics') !== false,
    'таблица network_interface_metrics описана в schema_mysql.sql'
);
check(
    strpos($schema, 'CREATE TABLE IF NOT EXISTS network_interfaces') !== false,
    'таблица network_interfaces описана в schema_mysql.sql'
);
check(
    strpos($retention, "'network_interface_metrics'") !== false,
    'retention чистит network_interface_metrics'
);
check(
    strpos($retention, 'idx_ifm_node_ts') !== false,
    'retention создаёт индекс по (node_id, timestamp)'
);

// —— 5. Живая проверка на реальной MySQL ——
if (test_db_env() === null) {
    skip(
        'живое хранение и очистка network_interface_metrics',
        'нет TEST_DB_DSN — запустите с тестовой MySQL, чтобы проверить интеграцию'
    );
} else {
    echo "\n== Живая проверка: запись и очистка строк интерфейсов ==\n";
    $pdo = test_db_connect();
    test_db_ensure_schema($pdo);

    $columns = $pdo->query('SHOW COLUMNS FROM network_interface_metrics')->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(static fn(array $c): string => (string)$c['Field'], $columns);
    check(
        $columns !== [],
        'таблица network_interface_metrics существует в живой базе'
    );
    foreach (['node_id', 'name', 'rx_sec', 'tx_sec', 'errin', 'dropin', 'timestamp'] as $col) {
        check(in_array($col, $names, true), 'живая таблица содержит колонку ' . $col);
    }

    $nodeId = $pdo->query('SELECT id FROM nodes LIMIT 1')->fetchColumn();
    if (!$nodeId) {
        $pdo->exec("INSERT INTO nodes (name, host) VALUES ('iface-test', '127.0.0.1')");
        $nodeId = (int)$pdo->lastInsertId();
    }
    $nodeId = (int)$nodeId;

    $old = date('Y-m-d H:i:s', time() - 400 * 86400);
    $pdo->prepare(
        'INSERT INTO network_interface_metrics
            (node_id, name, rx_bytes, tx_bytes, rx_sec, tx_sec, errin, errout, dropin, dropout, timestamp)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$nodeId, 'eth0', 1000, 2000, 10.5, 20.5, 3, 1, 0, 2, $old]);

    $latest = $pdo->prepare(
        'SELECT rx_sec, tx_sec, errin, dropout FROM network_interface_metrics
         WHERE node_id = ? AND name = ? ORDER BY timestamp DESC LIMIT 1'
    );
    $latest->execute([$nodeId, 'eth0']);
    $row = $latest->fetch(PDO::FETCH_ASSOC);
    check(
        $row !== false && (float)$row['rx_sec'] === 10.5 && (int)$row['errin'] === 3,
        'per-interface строка читается обратно без потерь'
    );

    retention_run($pdo, false);
    $left = $pdo->prepare(
        'SELECT COUNT(*) FROM network_interface_metrics WHERE node_id = ? AND name = ? AND timestamp < ?'
    );
    $left->execute([$nodeId, 'eth0', date('Y-m-d H:i:s', time() - 30 * 86400)]);
    check(
        (int)$left->fetchColumn() === 0,
        'retention удаляет устаревшие строки интерфейсов'
    );

    $pdo->prepare('DELETE FROM network_interface_metrics WHERE node_id = ?')->execute([$nodeId]);
}

echo "\nПройдено: {$passed}, провалено: {$failed}, пропущено: {$skipped}\n";
exit($failed === 0 ? 0 : 1);
