<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
session_write_close();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
$pdo = getDbConnection();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── EXPORT ────────────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT
            name, host, port, node_token, secret_key, country,
            provider_name, provider_url,
            billing_amount, billing_currency, billing_period,
            last_payment_date, next_payment_date
        FROM nodes
        ORDER BY id
    ");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $export = [
        'version'     => 1,
        'exported_at' => date('Y-m-d\TH:i:s'),
        'panel_name'  => setting_get('system_name', 'HostMonitor'),
        'node_count'  => count($nodes),
        'nodes'       => $nodes,
    ];

    if (isset($_GET['download'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="nodes-export-' . date('Ymd-His') . '.json"');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── IMPORT ────────────────────────────────────────────────────────
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['nodes']) || !is_array($data['nodes'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid format: expecting { "nodes": [...] }']);
    exit;
}

$importNodes = $data['nodes'];
$mode = $data['mode'] ?? 'skip'; // skip | overwrite
$results = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

// Проверяем какие колонки существуют
$existingCols = [];
$colStmt = $pdo->query("SHOW COLUMNS FROM nodes");
while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
    $existingCols[] = $col['Field'];
}

$allCols = [
    'name', 'host', 'port', 'node_token', 'secret_key', 'country',
    'provider_name', 'provider_url',
    'billing_amount', 'billing_currency', 'billing_period',
    'last_payment_date', 'next_payment_date',
];
$insertCols = array_intersect($allCols, $existingCols);

$pdo->beginTransaction();
try {
    foreach ($importNodes as $idx => $node) {
        $name = trim((string)($node['name'] ?? ''));
        $host = trim((string)($node['host'] ?? ''));

        if ($name === '' || $host === '') {
            $results['errors'][] = "Row {$idx}: name and host are required";
            continue;
        }

        // Проверяем существование
        $check = $pdo->prepare("SELECT id FROM nodes WHERE name = ? LIMIT 1");
        $check->execute([$name]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);

        if ($existing && $mode !== 'overwrite') {
            $results['skipped']++;
            continue;
        }

        // Генерируем токен/ключ если не переданы
        $values = [];
        foreach ($insertCols as $col) {
            $val = $node[$col] ?? null;
            if ($col === 'node_token' && (empty($val) || $val === null)) {
                $val = generateNodeToken();
            }
            if ($col === 'secret_key' && (empty($val) || $val === null)) {
                $val = generateSecretKey();
            }
            if ($col === 'port' && ($val === null || $val === '')) {
                $val = 22;
            }
            if ($col === 'billing_currency' && (empty($val) || $val === null)) {
                $val = 'RUB';
            }
            if ($col === 'billing_period' && ($val === null || $val === '')) {
                $val = 30;
            }
            $values[] = $val;
        }

        if ($existing) {
            // UPDATE
            $setParts = [];
            $setValues = [];
            foreach ($insertCols as $col) {
                if (in_array($col, ['name'], true)) continue; // name — PK-аналог
                $setParts[] = "`{$col}` = ?";
                $setValues[] = $node[$col] ?? null;
            }
            if (!empty($setParts)) {
                $setValues[] = $existing['id'];
                $updateSql = "UPDATE nodes SET " . implode(', ', $setParts) . " WHERE id = ?";
                $upd = $pdo->prepare($updateSql);
                $upd->execute($setValues);
                $results['updated']++;
            } else {
                $results['skipped']++;
            }
        } else {
            // INSERT
            $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
            $colNames = implode(', ', array_map(fn($c) => "`{$c}`", $insertCols));
            $ins = $pdo->prepare("INSERT INTO nodes ({$colNames}, status) VALUES ({$placeholders}, 'offline')");
            $ins->execute($values);
            $results['created']++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Import failed: ' . $e->getMessage()]);
    exit;
}

$results['message'] = "Import complete: {$results['created']} created, {$results['updated']} updated, {$results['skipped']} skipped";
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
