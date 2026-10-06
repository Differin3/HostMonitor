<?php
/**
 * Команды агентов: выдача (админ), забор и отчёт (сама нода).
 *
 *   GET  /api/nodes/command.php                 — нода забирает свою команду
 *                                                 (аналог get-command, канал node_commands)
 *   POST /api/nodes/command.php {action:"issue",
 *        node_id, command, args?, ttl_seconds?, totp_code?}  — админ
 *   POST {action:"report", command, status, result}          — нода отчитывается
 *
 * Опасные команды требуют TOTP админа (или ALLOW_DANGEROUS_COMMANDS=true).
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/commands.php';

if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return strlen((string)$s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) {
        return $length === null ? substr((string)$s, $start) : substr((string)$s, $start, $length);
    }
}

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();
$auth = require_api_auth($pdo);

$isAdmin = $auth['auth'] === 'session';
$agentNode = $auth['node'] ?? null;

try {
    if ($method === 'GET') {
        // Только нода (своя) или legacy-агент: видит свою команду.
        if (!$agentNode) {
            json_error('Agents only', 403);
        }
        require_scope($auth, 'commands:read');
        $cmd = command_pull($pdo, (int)$agentNode['id']);
        if ($cmd === null) {
            echo json_encode(['status' => 'no-command', 'command' => null]);
        } else {
            echo json_encode([
                'status'         => 'ok',
                'command'        => $cmd['command'],
                'command_id'     => $cmd['id'],
                'args'           => $cmd['args'],
                'command_status' => 'pending',
            ]);
        }
        return;
    }

    if ($method === 'POST') {
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            json_error('Invalid JSON', 400);
        }
        $action = (string)($data['action'] ?? '');

        if ($action === 'issue') {
            if (!$isAdmin) {
                json_error('Forbidden: admin session required', 403);
            }
            $nodeId = (int)($data['node_id'] ?? 0);
            $command = trim((string)($data['command'] ?? ''));
            $args = isset($data['args'])
                ? (is_string($data['args']) ? $data['args'] : json_encode($data['args']))
                : null;
            $ttl = $data['ttl_seconds'] ?? null;
            $totpCode = $data['totp_code'] ?? null;

            [$status, $payload] = command_issue($pdo, $auth, $nodeId, $command, $args, $ttl, $totpCode);
            http_response_code($status);
            echo json_encode($payload);
            return;
        }

        if ($action === 'report') {
            if ($agentNode) {
                $nodeId = (int)$agentNode['id'];
            } elseif ($isAdmin) {
                $nodeId = (int)($data['node_id'] ?? 0);
                if ($nodeId <= 0) {
                    json_error('node_id required', 400);
                }
            } else {
                json_error('Forbidden', 403);
            }

            $commandStatus = (string)($data['status'] ?? 'completed');
            $command = trim((string)($data['command'] ?? ''));
            $resultField = trim((string)($data['result'] ?? ($data['error'] ?? '')));
            if (mb_strlen($resultField) > 4000) {
                $resultField = mb_substr($resultField, 0, 4000);
            }

            [$status, $payload] = command_report($pdo, $nodeId, $command, $commandStatus, $resultField);
            http_response_code($status);
            echo json_encode($payload);
            return;
        }

        json_error('Unknown action', 400);
    }

    json_error('Method not allowed', 405);
} catch (Throwable $e) {
    error_log('nodes/command.php error: ' . $e->getMessage());
    json_error('Internal server error', 500);
}