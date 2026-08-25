<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/panel_update.php';

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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'check') {
        $fetch = !isset($_GET['local']) || $_GET['local'] !== '1';
        echo json_encode(panel_update_check($fetch));
        exit;
    }
    if ($method === 'POST' && $action === 'apply') {
        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        $force = !empty($_GET['force']) || !empty($_POST['force']) || !empty($body['force']);
        echo json_encode(panel_update_apply((bool)$force));
        exit;
    }
    json_error('Invalid action', 400);
} catch (Throwable $e) {
    json_exception($e, true);
}
