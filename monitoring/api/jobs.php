<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/jobs.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    json_error('Unauthorized', 401);
}
if (($_SESSION['role'] ?? 'admin') !== 'admin') {
    json_error('Forbidden', 403);
}
require_csrf();
session_write_close();

$userId = (int)$_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$raw = file_get_contents('php://input') ?: '';
$body = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

try {
    $pdo = getDbConnection();
    if (!$pdo) {
        json_error('Нет соединения с базой данных панели', 503);
    }
    jobs_ensure_tables($pdo);

    // Один ответ на все частые случаи: активные задачи, счётчик
    // непрочитанных и признак живого воркера. Колокольчик опрашивает
    // только его, чтобы не ходить в БД за каждым прогрессом отдельно.
    if ($method === 'GET' && $action === 'bell') {
        $unseen = jobs_unseen_ids($pdo, $userId, 20);
        echo json_encode([
            'ok' => true,
            'worker_alive' => jobs_worker_alive(),
            'unseen' => count($unseen),
            'active' => jobs_active($pdo),
            'recent' => jobs_list($pdo, 20),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        $kind = isset($_GET['kind']) ? trim((string)$_GET['kind']) : null;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        echo json_encode([
            'ok' => true,
            'worker_alive' => jobs_worker_alive(),
            'jobs' => jobs_list($pdo, $limit, $kind),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'GET' && $action === 'status') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            json_error('Не указан id задачи');
        }
        $stmt = $pdo->prepare('SELECT * FROM background_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            json_error('Задача не найдена', 404);
        }
        echo json_encode(['ok' => true, 'job' => jobs_row_to_array($row)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'start') {
        $kind = trim((string)($body['kind'] ?? $_POST['kind'] ?? ''));
        if ($kind === '') {
            json_error('Не указан вид задачи');
        }
        if (!jobs_kind_allowed($kind)) {
            json_error('Неизвестный вид задачи: ' . $kind, 400);
        }
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];
        $title = trim((string)($body['title'] ?? ''));
        $id = jobs_enqueue($pdo, $kind, $payload, $title, $userId);
        echo json_encode(['ok' => true, 'id' => $id, 'job_id' => $id], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'cancel') {
        $id = (int)($body['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            json_error('Не указан id задачи');
        }
        if (!jobs_request_cancel($pdo, $id)) {
            json_error('Задача уже завершена или не найдена', 409);
        }
        echo json_encode(['ok' => true, 'cancel_requested' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && $action === 'ack') {
        $ids = $body['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
        jobs_mark_seen($pdo, $userId, $ids);
        echo json_encode(['ok' => true, 'unseen' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    json_error('Unknown action');
} catch (Throwable $e) {
    json_exception($e, true);
}
