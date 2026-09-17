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
        $branch = trim((string)($_GET['branch'] ?? ''));
        echo json_encode(panel_update_check($fetch, $branch));
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
        $branch = trim((string)($_GET['branch'] ?? ($_POST['branch'] ?? ($body['branch'] ?? ''))));
        echo json_encode(panel_update_apply((bool)$force, $branch));
        exit;
    }
    if ($method === 'POST' && $action === 'select') {
        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        $branch = trim((string)($_POST['branch'] ?? ($body['branch'] ?? '')));
        if ($branch !== '' && !in_array($branch, panel_git_branch_list(panel_repo_root()), true)) {
            json_error('Бранч ' . $branch . ' недоступен', 400);
        }
        $cfg = panel_config_load();
        $cfg['update_branch'] = $branch;
        panel_config_save($cfg);
        echo json_encode(['success' => true, 'selected_branch' => $branch]);
        exit;
    }
    json_error('Invalid action', 400);
} catch (Throwable $e) {
    error_log('panel_update.php error: ' . $e->getMessage());
    json_error('Internal server error', 500);
}
