<?php
/**
 * api/billing_payment_link.php
 *
 * Привязка / отвязка платежа к ноде.
 *
 * POST   {payment_id, node_id}  — привязать (или перепривязать) платёж
 * DELETE {payment_id}           — отвязать платёж (node_id = NULL)
 *
 * После привязки пересчитывает nodes.last_payment_date и
 * nodes.next_payment_date по последнему платежу этой ноды + billing_period.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

// CSRF только для мутаций (POST/DELETE)
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (in_array($method, ['POST', 'DELETE', 'PUT', 'PATCH'], true)) {
    require_csrf();
}

session_write_close();
header('Content-Type: application/json; charset=utf-8');

if (!in_array($method, ['POST', 'DELETE'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Читаем body: для POST — JSON, для DELETE — тоже JSON (fetch позволяет body)
$raw = file_get_contents('php://input');
$body = [];
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

// Fallback: если клиент передал form-data вместо JSON — берём из $_POST
if (empty($body) && !empty($_POST)) {
    $body = $_POST;
}

$paymentId = isset($body['payment_id']) ? (int)$body['payment_id'] : 0;
$nodeId    = null;
if ($method === 'POST') {
    if (isset($body['node_id']) && $body['node_id'] !== '' && $body['node_id'] !== null) {
        $nodeId = (int)$body['node_id'];
    }
}

if ($paymentId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'payment_id required']);
    exit;
}
if ($method === 'POST' && (!$nodeId || $nodeId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'node_id required']);
    exit;
}

/** Пересчитывает даты ноды по последнему платежу. Возвращает массив для ответа. */
function recalcNodePaymentDates(PDO $pdo, int $nodeId): array
{
    // Период биллинга (по умолчанию 30 дней)
    $stmt = $pdo->prepare("SELECT billing_period FROM nodes WHERE id = ? LIMIT 1");
    $stmt->execute([$nodeId]);
    $period = $stmt->fetchColumn();
    if ($period === false) {
        return ['error' => 'node_not_found'];
    }
    $period = (int)$period;
    if ($period <= 0) {
        $period = 30;
    }

    // Последний платёж этой ноды
    $stmt = $pdo->prepare(
        "SELECT payment_date FROM payments WHERE node_id = ? ORDER BY payment_date DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$nodeId]);
    $last = $stmt->fetchColumn();

    if ($last === false) {
        // Платежей у ноды не осталось — сбрасываем даты
        $upd = $pdo->prepare("UPDATE nodes SET last_payment_date = NULL, next_payment_date = NULL WHERE id = ?");
        $upd->execute([$nodeId]);
        return ['last_payment_date' => null, 'next_payment_date' => null];
    }

    $next = date('Y-m-d', strtotime($last . ' + ' . $period . ' days'));
    $upd = $pdo->prepare("UPDATE nodes SET last_payment_date = ?, next_payment_date = ? WHERE id = ?");
    $upd->execute([$last, $next, $nodeId]);
    return ['last_payment_date' => $last, 'next_payment_date' => $next];
}

$pdo = getDbConnection();

try {
    // Проверим, что платёж существует
    $stmt = $pdo->prepare("SELECT id, node_id, amount, payment_date FROM payments WHERE id = ? LIMIT 1");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        http_response_code(404);
        echo json_encode(['error' => 'payment not found']);
        exit;
    }

    $pdo->beginTransaction();

    if ($method === 'POST') {
        // Проверим ноду
        $stmt = $pdo->prepare("SELECT id, name FROM nodes WHERE id = ? LIMIT 1");
        $stmt->execute([$nodeId]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$node) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'node not found']);
            exit;
        }

        $previousNodeId = $payment['node_id'] !== null ? (int)$payment['node_id'] : null;

        // Привязываем платёж
        $upd = $pdo->prepare("UPDATE payments SET node_id = ? WHERE id = ?");
        $upd->execute([$nodeId, $paymentId]);

        // Пересчитываем даты у новой ноды
        $newDates = recalcNodePaymentDates($pdo, $nodeId);

        // Если платёж был привязан к другой ноде — пересчитать и её
        $prevDates = null;
        if ($previousNodeId !== null && $previousNodeId !== $nodeId) {
            $prevDates = recalcNodePaymentDates($pdo, $previousNodeId);
        }

        $pdo->commit();

        echo json_encode([
            'success'           => true,
            'action'            => $previousNodeId === null ? 'linked' : ($previousNodeId === $nodeId ? 'noop' : 'relinked'),
            'payment_id'        => $paymentId,
            'node_id'           => $nodeId,
            'node_name'         => $node['name'] ?? null,
            'previous_node_id'  => $previousNodeId,
            'last_payment_date' => $newDates['last_payment_date'] ?? null,
            'next_payment_date' => $newDates['next_payment_date'] ?? null,
            'previous_node'     => $prevDates,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // DELETE — отвязать
    $previousNodeId = $payment['node_id'] !== null ? (int)$payment['node_id'] : null;

    $upd = $pdo->prepare("UPDATE payments SET node_id = NULL WHERE id = ?");
    $upd->execute([$paymentId]);

    $prevDates = null;
    if ($previousNodeId !== null) {
        $prevDates = recalcNodePaymentDates($pdo, $previousNodeId);
    }

    $pdo->commit();

    echo json_encode([
        'success'           => true,
        'action'            => 'unlinked',
        'payment_id'        => $paymentId,
        'previous_node_id'  => $previousNodeId,
        'previous_node'     => $prevDates,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (function_exists('app_log_error')) {
        app_log_error('billing_payment_link.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    } else {
        error_log('[billing_payment_link] ' . $e->getMessage());
    }
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        // Поле debug можно убрать на продакшене
        'debug' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}