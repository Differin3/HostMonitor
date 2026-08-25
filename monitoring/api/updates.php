<?php
// API для системы обновлений
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;
$pdo = getDbConnection();

/** Колонка очереди пакетной установки (один pending на ноду). */
function updates_ensure_queued_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec("ALTER TABLE node_updates ADD COLUMN install_queued TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
        // уже есть
    }
    $done = true;
}

updates_ensure_queued_column($pdo);

/** SQL-фрагмент статуса установки пакета */
function updates_install_status_sql(): string
{
    return "CASE 
        WHEN EXISTS (
            SELECT 1 FROM update_history uh 
            WHERE uh.node_id = nu.node_id 
            AND uh.package = nu.package 
            AND uh.success = 1 
            AND uh.timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ) THEN 'completed'
        WHEN COALESCE(nu.install_queued, 0) = 1
            AND n.last_command IN ('install-updates', 'install-update-batch')
            AND n.command_status = 'running'
            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 60 MINUTE) THEN 'installing'
        WHEN COALESCE(nu.install_queued, 0) = 1
            AND n.last_command IN ('install-updates', 'install-update-batch')
            AND n.command_status = 'pending'
            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 60 MINUTE) THEN 'pending'
        WHEN COALESCE(nu.install_queued, 0) = 1
            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 60 MINUTE) THEN 'pending'
        WHEN n.last_command IS NOT NULL 
            AND n.last_command LIKE CONCAT('install-update ', nu.package, '%')
            AND n.command_status = 'pending' 
            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 60 MINUTE) THEN 'pending'
        WHEN n.last_command IS NOT NULL 
            AND n.last_command LIKE CONCAT('install-update ', nu.package, '%')
            AND n.command_status IN ('completed', 'running', 'installing')
            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 60 MINUTE) THEN 'installing'
        ELSE 'available'
    END as install_status";
}

/** Список обновлений из БД без постановки команд агентам */
function updates_list_payload(PDO $pdo, $nodeId = null): array
{
    if ($nodeId) {
        $updatesStmt = $pdo->prepare("SELECT nu.*, n.id as node_id, COALESCE(n.name, nu.node_name) as node_name,
            n.command_status, n.last_command, n.command_timestamp,
            " . updates_install_status_sql() . "
            FROM node_updates nu 
            JOIN nodes n ON nu.node_id = n.id 
            WHERE nu.node_id = ?
            ORDER BY nu.priority DESC, nu.package ASC");
        $updatesStmt->execute([$nodeId]);
    } else {
        $updatesStmt = $pdo->query("SELECT nu.*, n.id as node_id, COALESCE(n.name, nu.node_name) as node_name,
            n.command_status, n.last_command, n.command_timestamp,
            " . updates_install_status_sql() . "
            FROM node_updates nu 
            JOIN nodes n ON nu.node_id = n.id 
            ORDER BY nu.priority DESC, nu.package ASC");
    }
    $allUpdates = $updatesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $formattedUpdates = [];
    $securityUpdates = 0;
    foreach ($allUpdates as $update) {
        $priority = $update['priority'] ?? 'normal';
        if ($priority === 'security') {
            $securityUpdates++;
        }
        $formattedUpdates[] = [
            'package' => $update['package'],
            'current_version' => $update['current_version'] ?? '-',
            'new_version' => $update['new_version'] ?? '-',
            'priority' => $priority,
            'node_id' => $update['node_id'],
            'node_name' => $update['node_name'],
            'install_status' => $update['install_status'] ?? 'available',
            'install_queued' => (int)($update['install_queued'] ?? 0),
        ];
    }

    $lastCheckStmt = $pdo->query("SELECT MAX(last_check) as last_check FROM node_updates");
    $lastCheckRow = $lastCheckStmt->fetch(PDO::FETCH_ASSOC);

    return [
        'success' => true,
        'updates' => $formattedUpdates,
        'total_updates' => count($formattedUpdates),
        'security_updates' => $securityUpdates,
        'last_check' => $lastCheckRow['last_check'] ?? date('Y-m-d H:i:s'),
    ];
}

// Для GET запросов (история, проверка) проверяем сессию пользователя
// Для POST запросов от агента (report, result, pending-install) проверяем токен
$nodeInfo = null;
if ($method === 'GET' && ($action === 'pending-install')) {
    $auth = require_api_auth($pdo);
    $nodeInfo = $auth['node'] ?? null;
    if (!$nodeInfo) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
} elseif ($method === 'GET') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    session_write_close();
} elseif ($method === 'POST' && ($action === 'report' || $action === 'result' || $action === 'pending-install')) {
    // Для action=report и action=result проверяем токен агента
    $auth = require_api_auth($pdo);
    $nodeInfo = $auth['node'];
    
    if (!$nodeInfo) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
} elseif ($method === 'POST') {
    // Для других POST запросов (check, install) проверяем сессию пользователя
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    session_write_close();
}

try {
    if ($method === 'GET') {
        if ($action === 'history') {
            $limit = (int)($_GET['limit'] ?? 100);
            $nodeId = $_GET['node_id'] ?? null;
            $status = $_GET['status'] ?? null; // 'success' или 'failed'
            
            $sql = "SELECT * FROM update_history WHERE 1=1";
            $params = [];
            
            if ($nodeId) {
                $sql .= " AND node_id = ?";
                $params[] = $nodeId;
            }
            
            if ($status === 'success') {
                $sql .= " AND success = 1";
            } elseif ($status === 'failed') {
                $sql .= " AND success = 0";
            }
            
            $sql .= " ORDER BY timestamp DESC LIMIT ?";
            $params[] = $limit;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Получаем статистику
            $statsSql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed
                FROM update_history";
            $statsParams = [];
            
            if ($nodeId) {
                $statsSql .= " WHERE node_id = ?";
                $statsParams[] = $nodeId;
            }
            
            $statsStmt = $pdo->prepare($statsSql);
            $statsStmt->execute($statsParams);
            $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'history' => $history,
                'stats' => $stats
            ]);
        } elseif ($action === 'list') {
            $nodeId = $_GET['node_id'] ?? null;
            echo json_encode(updates_list_payload($pdo, $nodeId), JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
    } elseif ($method === 'POST') {
        // Только чтение списка (без постановки check-updates) — для UI-poll
        if ($action === 'list') {
            $nodeId = $_GET['node_id'] ?? null;
            echo json_encode(updates_list_payload($pdo, $nodeId), JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Обработка отчетов от агентов
        if ($action === 'report') {
            
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON']);
                exit;
            }
            
            $updates = $data['updates'] ?? [];
            $osInfo = $data['os_info'] ?? [];
            $nodeName = $data['node_name'] ?? $nodeInfo['name'];
            $nodeId = $nodeInfo['id'];
            
            // Логируем полученные данные для отладки
            error_log("=== UPDATE REPORT ===");
            error_log("Node: {$nodeName} (ID: {$nodeId})");
            error_log("Updates count: " . count($updates));
            error_log("OS Info: " . json_encode($osInfo));
            if (count($updates) > 0) {
                error_log("First update sample: " . json_encode($updates[0]));
            }
            
            // Пустой отчёт = на ноде нет апдейтов: чистим список, но не трогаем install_queued
            if (empty($updates)) {
                try {
                    $clear = $pdo->prepare(
                        "DELETE FROM node_updates WHERE node_id = ? AND COALESCE(install_queued, 0) = 0"
                    );
                    $clear->execute([$nodeId]);
                    error_log("Cleared {$clear->rowCount()} non-queued updates for node {$nodeName} (empty report)");
                } catch (Throwable $e) {
                    error_log("Empty-report clear failed for {$nodeName}: " . $e->getMessage());
                }
                echo json_encode([
                    'success' => true,
                    'count' => 0,
                    'message' => "No updates to save for node {$nodeName}"
                ]);
                exit;
            }
            
            // Начинаем транзакцию
            $pdo->beginTransaction();
            
            try {
                // Удаляем только не в очереди установки — иначе report во время apt сбрасывает install_queued
                $deleteStmt = $pdo->prepare(
                    "DELETE FROM node_updates WHERE node_id = ? AND COALESCE(install_queued, 0) = 0"
                );
                $deleteStmt->execute([$nodeId]);
                $deleted = $deleteStmt->rowCount();
                error_log("Deleted {$deleted} non-queued updates for node {$nodeName}");
                
                // Upsert: версии обновляем, install_queued сохраняем
                $insertStmt = $pdo->prepare("INSERT INTO node_updates 
                    (node_id, node_name, package, current_version, new_version, priority, os_name, os_version, kernel_version, install_queued) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                    ON DUPLICATE KEY UPDATE
                        node_name = VALUES(node_name),
                        current_version = VALUES(current_version),
                        new_version = VALUES(new_version),
                        priority = VALUES(priority),
                        os_name = VALUES(os_name),
                        os_version = VALUES(os_version),
                        kernel_version = VALUES(kernel_version),
                        last_check = CURRENT_TIMESTAMP");
                
                $saved = 0;
                $errors = [];
                foreach ($updates as $update) {
                    try {
                        $package = $update['package'] ?? '';
                        if (empty($package)) {
                            error_log("Warning: Empty package name in update data");
                            continue;
                        }
                        
                        $result = $insertStmt->execute([
                            $nodeId,
                            $nodeName,
                            $package,
                            $update['current_version'] ?? '',
                            $update['new_version'] ?? '',
                            $update['priority'] ?? 'normal',
                            $osInfo['os_name'] ?? '',
                            $osInfo['os_version'] ?? '',
                            $osInfo['kernel_version'] ?? ''
                        ]);
                        
                        if ($result) {
                            $saved++;
                        } else {
                            $errors[] = "Failed to insert package {$package}";
                        }
                    } catch (PDOException $e) {
                        $errors[] = "Package {$package}: " . $e->getMessage();
                        error_log("Error saving update for node {$nodeId}, package {$package}: " . $e->getMessage());
                    } catch (Exception $e) {
                        $errors[] = "Package {$package}: " . $e->getMessage();
                        error_log("Error saving update for node {$nodeId}, package {$package}: " . $e->getMessage());
                    }
                }
                
                // Коммитим транзакцию
                $pdo->commit();
                
                error_log("Saved {$saved} updates for node {$nodeName} (ID: {$nodeId}), errors: " . count($errors));
                if (!empty($errors)) {
                    error_log("Update errors: " . implode(", ", $errors));
                }
                
                // Проверяем что данные действительно сохранились
                $verifyStmt = $pdo->prepare("SELECT COUNT(*) as count FROM node_updates WHERE node_id = ?");
                $verifyStmt->execute([$nodeId]);
                $verifyResult = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                $actualCount = $verifyResult['count'] ?? 0;
                
                if ($actualCount != $saved) {
                    error_log("WARNING: Saved {$saved} but found {$actualCount} in database for node {$nodeName}");
                } else {
                    error_log("Verified: {$actualCount} updates in database for node {$nodeName}");
                }
            } catch (Exception $e) {
                // Откатываем транзакцию при ошибке
                $pdo->rollBack();
                error_log("Transaction failed for node {$nodeName}: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Internal server error',
                    'count' => 0
                ]);
                exit;
            }
            
            echo json_encode([
                'success' => true,
                'count' => $saved,
                'message' => "Saved {$saved} updates for node {$nodeName}",
                'errors' => $errors
            ]);
            exit;
        } elseif ($action === 'result') {
            // Авторизация уже проверена выше для action=result
            
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid JSON']);
                exit;
            }
            
            $package = $data['package'] ?? '';
            $success = $data['success'] ?? false;
            $version = $data['version'] ?? '';
            $message = $data['message'] ?? ($success ? 'Update installed successfully' : 'Update installation failed');
            $nodeName = $data['node_name'] ?? $nodeInfo['name'];
            $nodeId = $nodeInfo['id'];
            
            // Логируем результат установки
            error_log("=== UPDATE INSTALL RESULT ===");
            error_log("Node: {$nodeName} (ID: {$nodeId})");
            error_log("Package: {$package}");
            error_log("Version: {$version}");
            error_log("Success: " . ($success ? 'yes' : 'no'));
            error_log("Message: {$message}");
            
            // Сохраняем результат в историю
            try {
                $historyStmt = $pdo->prepare("INSERT INTO update_history (node_id, node_name, package, version, success, message) VALUES (?, ?, ?, ?, ?, ?)");
                $historyStmt->execute([
                    $nodeId,
                    $nodeName,
                    $package,
                    $version,
                    $success ? 1 : 0,
                    $message
                ]);
                error_log("History entry saved successfully");
            } catch (Exception $e) {
                error_log("Error saving history: " . $e->getMessage());
            }
            
            // Если установка успешна, удаляем обновление из списка доступных
            if ($success) {
                try {
                    $deleteStmt = $pdo->prepare("DELETE FROM node_updates WHERE node_id = ? AND package = ?");
                    $deleteStmt->execute([$nodeId, $package]);
                    $deleted = $deleteStmt->rowCount();
                    error_log("Deleted {$deleted} update entry from node_updates");
                } catch (Exception $e) {
                    error_log("Error deleting update entry: " . $e->getMessage());
                }
            } else {
                // Снимаем флаг очереди, чтобы пакет снова был «доступен»
                try {
                    $pdo->prepare("UPDATE node_updates SET install_queued = 0 WHERE node_id = ? AND package = ?")
                        ->execute([$nodeId, $package]);
                } catch (Throwable $e) {
                    // ignore
                }
            }
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        // Обработка запросов от пользователя
        if ($action === 'check') {
            $nodeId = $_GET['node_id'] ?? null;
            
            // Отправляем команду проверки обновлений
            if ($nodeId) {
                $stmt = $pdo->prepare("SELECT id, name FROM nodes WHERE id = ? AND status = 'online'");
                $stmt->execute([$nodeId]);
                $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmt = $pdo->query("SELECT id, name FROM nodes WHERE status = 'online'");
                $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            foreach ($nodes as $node) {
                $checkStmt = $pdo->prepare("SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?");
                $checkStmt->execute([$node['id']]);
                $currentNode = $checkStmt->fetch(PDO::FETCH_ASSOC);
                $cmd = (string)($currentNode['last_command'] ?? '');
                $st = (string)($currentNode['command_status'] ?? '');
                $commandAge = $currentNode['command_timestamp']
                    ? (time() - strtotime($currentNode['command_timestamp']))
                    : 999999;

                // Не мешаем install / любой running-команде
                if (in_array($st, ['pending', 'running'], true) && $cmd !== '') {
                    $isInstall = in_array($cmd, ['install-updates', 'install-update-batch'], true)
                        || str_starts_with($cmd, 'install-update ');
                    $ttl = $isInstall || $st === 'running' ? 3600 : 300;
                    if ($commandAge < $ttl) {
                        error_log("Skipping check-updates for node {$node['name']}: busy {$cmd}/{$st} age={$commandAge}s");
                        continue;
                    }
                    error_log("Clearing stale command for node {$node['name']}: {$cmd}/{$st} age={$commandAge}s");
                    $pdo->prepare("UPDATE nodes SET last_command = NULL, command_status = NULL, command_timestamp = NULL WHERE id = ?")
                        ->execute([$node['id']]);
                }
                
                $pdo->prepare("UPDATE nodes SET last_command = 'check-updates', command_status = 'pending', command_timestamp = NOW() WHERE id = ?")
                    ->execute([$node['id']]);
                error_log("Queued check-updates command for node {$node['name']} (ID: {$node['id']})");
            }
            
            echo json_encode(updates_list_payload($pdo, $nodeId), JSON_UNESCAPED_UNICODE);
            exit;
        } elseif ($action === 'install') {
            $data = json_decode(file_get_contents('php://input'), true);
            $updates = $data['updates'] ?? [];
            
            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No updates specified']);
                exit;
            }

            // Группируем по ноде: одна pending-команда на ноду, список пакетов в БД
            $byNode = [];
            $errors = [];
            foreach ($updates as $update) {
                if (!is_array($update)) {
                    continue;
                }
                $nodeId = (int)($update['node_id'] ?? 0);
                $package = trim((string)($update['package'] ?? ''));
                if ($nodeId <= 0 || $package === '') {
                    $errors[] = 'Не указаны node_id/package';
                    continue;
                }
                if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9+._:-]*$/', $package)) {
                    $errors[] = "Некорректное имя пакета: {$package}";
                    continue;
                }
                $byNode[$nodeId][] = [
                    'package' => $package,
                    'new_version' => (string)($update['new_version'] ?? ''),
                ];
            }

            $queued = 0;
            $nodesQueued = 0;
            $nodeNames = [];

            foreach ($byNode as $nodeId => $pkgs) {
                $nodeStmt = $pdo->prepare("SELECT id, name, status, last_command, command_status FROM nodes WHERE id = ?");
                $nodeStmt->execute([$nodeId]);
                $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);
                if (!$node) {
                    $errors[] = "Нода #{$nodeId} не найдена";
                    continue;
                }
                if (($node['status'] ?? '') !== 'online') {
                    $errors[] = "Нода {$node['name']} офлайн";
                    continue;
                }

                $pendingCmd = trim((string)($node['last_command'] ?? ''));
                $pendingStatus = (string)($node['command_status'] ?? '');
                if (in_array($pendingStatus, ['pending', 'running'], true) && $pendingCmd !== '') {
                    $allowReplace = $pendingStatus === 'pending'
                        && (
                            in_array($pendingCmd, ['check-updates', 'install-updates', 'install-update-batch'], true)
                            || str_starts_with($pendingCmd, 'install-update ')
                        );
                    if ($allowReplace) {
                        $pdo->prepare("UPDATE nodes SET last_command = NULL, command_status = NULL, command_timestamp = NULL WHERE id = ?")
                            ->execute([$nodeId]);
                        // Не сбрасываем install_queued целиком — ниже выставим нужные пакеты
                    } else {
                        $errors[] = "Нода {$node['name']} занята командой: {$pendingCmd} ({$pendingStatus})";
                        continue;
                    }
                }

                $mark = $pdo->prepare("UPDATE node_updates SET install_queued = 1 WHERE node_id = ? AND package = ?");
                $marked = 0;
                foreach ($pkgs as $p) {
                    $mark->execute([$nodeId, $p['package']]);
                    if ($mark->rowCount() > 0) {
                        $marked++;
                    } else {
                        // пакета нет в node_updates — всё равно ставим флаг если вставим? нет, только известные
                        $errors[] = "Пакет {$p['package']} не найден в списке обновлений ноды {$node['name']}";
                    }
                }
                if ($marked === 0) {
                    continue;
                }

                $payload = json_encode([
                    'packages' => array_values(array_unique(array_map(static fn($p) => $p['package'], $pkgs))),
                    'count' => $marked,
                ], JSON_UNESCAPED_UNICODE);

                $pdo->prepare(
                    "UPDATE nodes SET last_command = 'install-updates', command_status = 'pending',
                     command_timestamp = NOW(), command_result = ? WHERE id = ?"
                )->execute([$payload, $nodeId]);

                $queued += $marked;
                $nodesQueued++;
                $nodeNames[] = (string)$node['name'];
            }

            $msg = $nodesQueued > 0
                ? "В очередь: {$queued} пакет(ов) на {$nodesQueued} нод(ах): " . implode(', ', $nodeNames)
                : 'Не удалось поставить обновления в очередь';
            if ($errors) {
                $msg .= '. Ошибок: ' . count($errors);
            }

            echo json_encode([
                'success' => $queued > 0,
                'queued' => $queued,
                'nodes' => $nodesQueued,
                'errors' => $errors,
                'message' => $msg,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } elseif ($action === 'pending-install') {
            // Агент забирает список пакетов для batch-установки
            if (!$nodeInfo) {
                http_response_code(401);
                echo json_encode(['error' => 'Unauthorized']);
                exit;
            }
            $nodeId = (int)$nodeInfo['id'];
            $stmt = $pdo->prepare(
                "SELECT package, current_version, new_version, priority
                 FROM node_updates
                 WHERE node_id = ? AND COALESCE(install_queued, 0) = 1
                 ORDER BY FIELD(priority, 'security', 'important', 'normal'), package ASC"
            );
            try {
                $stmt->execute([$nodeId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $rows = [];
            }
            echo json_encode([
                'success' => true,
                'node_id' => $nodeId,
                'packages' => array_column($rows, 'package'),
                'updates' => $rows,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("Error in updates API: " . $e->getMessage());
    echo json_encode(['error' => 'Internal server error']);
}

