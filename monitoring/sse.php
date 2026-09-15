<?php
declare(strict_types=1);

/**
 * Server-Sent Events: потоковое обновление дашборда без перезагрузки страницы.
 * Формат: event: overview / charts + data: JSON
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}
// SSE держит соединение минутами — нельзя оставлять session lock
session_write_close();

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/dashboard_snapshot.php';

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}

set_time_limit(0);
ignore_user_abort(true);

$pdo = getDbConnection();

$tickSec = 3;
$chartEvery = 7;
$range = (string)($_GET['range'] ?? '1h');
if (!in_array($range, ['15m', '1h', '6h', '24h'], true)) {
    $range = '1h';
}

function sse_emit(string $event, $payload): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

function sse_ping(): void
{
    echo ': ping ' . time() . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

$tick = 0;
sse_emit('ready', ['range' => $range, 'interval' => $tickSec]);

while (!connection_aborted()) {
    $_SESSION['last_activity'] = time();

    try {
        sse_emit('overview', dashboard_overview($pdo));
        if ($tick === 0 || ($tick % $chartEvery) === 0) {
            sse_emit('charts', dashboard_charts($pdo, $range));
        }
    } catch (Throwable $e) {
        error_log('[sse] ' . $e->getMessage());
        sse_emit('error', ['message' => 'snapshot_failed']);
    }

    sse_ping();
    $tick++;

    if (connection_aborted()) {
        break;
    }
    sleep($tickSec);
}
