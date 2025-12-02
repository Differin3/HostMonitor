<?php
// API для системы обновлений
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? null;
$pdo = getDbConnection();

// Для GET запросов (история, проверка) проверяем сессию пользователя
// Для POST запросов от агента (report, result) проверяем токен
$nodeInfo = null;
if ($method === 'GET') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
} elseif ($method === 'POST' && ($action === 'report' || $action === 'result')) {
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
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
    } elseif ($method === 'POST') {
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
            
            if (empty($updates)) {
                error_log("Warning: No updates received from node {$nodeName}");
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
                // Удаляем старые обновления для этой ноды
                $deleteStmt = $pdo->prepare("DELETE FROM node_updates WHERE node_id = ?");
                $deleteStmt->execute([$nodeId]);
                $deleted = $deleteStmt->rowCount();
                error_log("Deleted {$deleted} old updates for node {$nodeName}");
                
                // Сохраняем новые обновления
                $insertStmt = $pdo->prepare("INSERT INTO node_updates 
                    (node_id, node_name, package, current_version, new_version, priority, os_name, os_version, kernel_version) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
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
                    'error' => 'Database error: ' . $e->getMessage(),
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
            }
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        // Обработка запросов от пользователя
        if ($action === 'check') {
            $nodeId = $_GET['node_id'] ?? null;
            
            // Отправляем команду проверки обновлений
            if ($nodeId) {
                // Проверка для конкретной ноды
                $stmt = $pdo->prepare("SELECT id, name FROM nodes WHERE id = ? AND status = 'online'");
                $stmt->execute([$nodeId]);
                $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Проверка для всех онлайн нод
                $stmt = $pdo->query("SELECT id, name FROM nodes WHERE status = 'online'");
                $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            foreach ($nodes as $node) {
                // Проверяем, нет ли уже активной команды для этой ноды
                $checkStmt = $pdo->prepare("SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?");
                $checkStmt->execute([$node['id']]);
                $currentNode = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                // Не устанавливаем команду check-updates, если уже есть pending команда
                if ($currentNode && $currentNode['command_status'] === 'pending' && $currentNode['last_command']) {
                    // Проверяем, не истекла ли команда (более 5 минут)
                    $commandAge = time() - strtotime($currentNode['command_timestamp']);
                    if ($commandAge < 300) { // 5 минут
                        error_log("Skipping check-updates for node {$node['name']} (ID: {$node['id']}): already has pending command {$currentNode['last_command']} (age: {$commandAge}s)");
                        continue;
                    } else {
                        // Команда слишком старая, очищаем её
                        error_log("Clearing stale command for node {$node['name']} (ID: {$node['id']}): command {$currentNode['last_command']} is {$commandAge}s old");
                        $clearStmt = $pdo->prepare("UPDATE nodes SET last_command = NULL, command_status = NULL, command_timestamp = NULL WHERE id = ?");
                        $clearStmt->execute([$node['id']]);
                    }
                }
                
                // Не ставим check-updates если уже есть check-updates в pending (даже если недавно выполнилась)
                if ($currentNode && $currentNode['last_command'] === 'check-updates' && $currentNode['command_status'] === 'pending') {
                    $commandAge = time() - strtotime($currentNode['command_timestamp']);
                    if ($commandAge < 60) { // 1 минута - не ставим новую команду если недавно уже была check-updates
                        error_log("Skipping check-updates for node {$node['name']} (ID: {$node['id']}): check-updates was queued {$commandAge}s ago");
                        continue;
                    }
                }
                
                // Сохраняем команду проверки обновлений
                $updateStmt = $pdo->prepare("UPDATE nodes SET last_command = 'check-updates', command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
                $updateStmt->execute([$node['id']]);
                error_log("Queued check-updates command for node {$node['name']} (ID: {$node['id']})");
            }
            
            // Получаем все доступные обновления из БД с информацией о статусе установки
            // Проверяем также историю установок для определения успешных установок
            if ($nodeId) {
                $updatesStmt = $pdo->prepare("SELECT nu.*, n.id as node_id, COALESCE(n.name, nu.node_name) as node_name,
                    n.command_status, n.last_command, n.command_timestamp,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM update_history uh 
                            WHERE uh.node_id = nu.node_id 
                            AND uh.package = nu.package 
                            AND uh.success = 1 
                            AND uh.timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                        ) THEN 'completed'
                        WHEN n.last_command IS NOT NULL 
                            AND n.last_command LIKE CONCAT('install-update ', nu.package, '%')
                            AND n.command_status = 'pending' 
                            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'pending'
                        WHEN n.last_command IS NOT NULL 
                            AND n.last_command LIKE CONCAT('install-update ', nu.package, '%')
                            AND n.command_status = 'completed' 
                            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'installing'
                        ELSE 'available'
                    END as install_status
                    FROM node_updates nu 
                    JOIN nodes n ON nu.node_id = n.id 
                    WHERE nu.node_id = ?
                    ORDER BY nu.priority DESC, nu.package ASC");
                $updatesStmt->execute([$nodeId]);
            } else {
                $updatesStmt = $pdo->query("SELECT nu.*, n.id as node_id, COALESCE(n.name, nu.node_name) as node_name,
                    n.command_status, n.last_command, n.command_timestamp,
                    CASE 
                        WHEN EXISTS (
                            SELECT 1 FROM update_history uh 
                            WHERE uh.node_id = nu.node_id 
                            AND uh.package = nu.package 
                            AND uh.success = 1 
                            AND uh.timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                        ) THEN 'completed'
                        WHEN n.last_command IS NOT NULL 
                            AND n.last_command LIKE CONCAT('install-update ', nu.package, '%')
                            AND n.command_status = 'pending' 
                            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'pending'
                        WHEN n.last_command IS NOT NULL 
                            AND n.last_command LIKE CONCAT('install-update ', nu.package, '%')
                            AND n.command_status = 'completed' 
                            AND n.command_timestamp > DATE_SUB(NOW(), INTERVAL 10 MINUTE) THEN 'installing'
                        ELSE 'available'
                    END as install_status
                    FROM node_updates nu 
                    JOIN nodes n ON nu.node_id = n.id 
                    ORDER BY nu.priority DESC, nu.package ASC");
            }
            $allUpdates = $updatesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Форматируем обновления для фронтенда
            $formattedUpdates = [];
            foreach ($allUpdates as $update) {
                $formattedUpdates[] = [
                    'package' => $update['package'],
                    'current_version' => $update['current_version'] ?? '-',
                    'new_version' => $update['new_version'] ?? '-',
                    'priority' => $update['priority'] ?? 'normal',
                    'node_id' => $update['node_id'],
                    'node_name' => $update['node_name']
                ];
            }
            
            // Подсчитываем статистику
            $totalUpdates = count($formattedUpdates);
            $securityUpdates = 0;
            foreach ($formattedUpdates as $update) {
                if ($update['priority'] === 'security') {
                    $securityUpdates++;
                }
            }
            
            // Получаем время последней проверки
            $lastCheckStmt = $pdo->query("SELECT MAX(last_check) as last_check FROM node_updates");
            $lastCheckRow = $lastCheckStmt->fetch(PDO::FETCH_ASSOC);
            $lastCheck = $lastCheckRow['last_check'] ?? date('Y-m-d H:i:s');
            
            echo json_encode([
                'success' => true,
                'updates' => $formattedUpdates,
                'total_updates' => $totalUpdates,
                'security_updates' => $securityUpdates,
                'last_check' => $lastCheck
            ]);
        } elseif ($action === 'install') {
            $data = json_decode(file_get_contents('php://input'), true);
            $updates = $data['updates'] ?? [];
            
            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No updates specified']);
                exit;
            }
            
            $queued = 0;
            $errors = [];
            
            foreach ($updates as $update) {
                $nodeId = $update['node_id'] ?? null;
                $package = $update['package'] ?? '';
                $version = $update['new_version'] ?? '';
                $nodeName = $update['node_name'] ?? '';
                
                if (!$nodeId) {
                    $errors[] = "Node ID not specified for package {$package}";
                    continue;
                }
                
                // Проверяем что нода онлайн
                $nodeStmt = $pdo->prepare("SELECT name, status FROM nodes WHERE id = ?");
                $nodeStmt->execute([$nodeId]);
                $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$node) {
                    $errors[] = "Node ID {$nodeId} not found";
                    continue;
                }
                
                if ($node['status'] !== 'online') {
                    $errors[] = "Node {$node['name']} is offline, cannot install updates";
                    continue;
                }
                
                // Отправляем команду установки обновления на ноду
                // Включаем версию в команду для отчёта
                $command = "install-update {$package}";
                if ($version) {
                    $command .= " {$version}";
                }
                
                // Проверяем, нет ли уже активной команды для этой ноды
                $checkStmt = $pdo->prepare("SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?");
                $checkStmt->execute([$nodeId]);
                $currentNode = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($currentNode && $currentNode['command_status'] === 'pending' && $currentNode['last_command']) {
                    // Если команда - это check-updates, очищаем её и разрешаем установку
                    if ($currentNode['last_command'] === 'check-updates') {
                        error_log("Clearing check-updates command for node {$node['name']} (ID: {$nodeId}) to allow update installation");
                        $clearStmt = $pdo->prepare("UPDATE nodes SET last_command = NULL, command_status = NULL, command_timestamp = NULL WHERE id = ?");
                        $clearStmt->execute([$nodeId]);
                    } else {
                        // Если это другая команда (например, install-update), не разрешаем новую установку
                        error_log("WARNING: Node {$node['name']} (ID: {$nodeId}) already has pending command: {$currentNode['last_command']}. Skipping new command.");
                        $errors[] = "Node {$node['name']} already has a pending command: {$currentNode['last_command']}";
                        continue;
                    }
                }
                
                $updateStmt = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
                $updateStmt->execute([$command, $nodeId]);
                
                error_log("=== QUEUED UPDATE INSTALLATION ===");
                error_log("Node: {$node['name']} (ID: {$nodeId})");
                error_log("Package: {$package}");
                error_log("Version: {$version}");
                error_log("Command: {$command}");
                error_log("Command saved to database");
                $queued++;
            }
            
            echo json_encode([
                'success' => true,
                'queued' => $queued,
                'errors' => $errors,
                'message' => "Queued {$queued} update(s) for installation. Results will appear in history when agent completes."
            ]);
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
    echo json_encode(['error' => $e->getMessage()]);
}

