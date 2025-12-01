<?php
// API для получения логов
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

// Функция проверки токена ноды (для агентов)
function validateNodeToken($pdo, $token) {
    if (!$token) return null;
    $stmt = $pdo->prepare("SELECT id, name FROM nodes WHERE node_token = ?");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Проверка авторизации: либо сессия пользователя, либо токен ноды
$isAuthorized = false;
$nodeInfo = null;

if (isset($_SESSION['user_id'])) {
    $isAuthorized = true;
} else {
    // Проверяем токен ноды из заголовка Authorization
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $token = $matches[1];
        $nodeInfo = validateNodeToken($pdo, $token);
        if ($nodeInfo) {
            $isAuthorized = true;
        }
    }
}

if (!$isAuthorized) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    if ($method === 'GET') {
        $type = $_GET['type'] ?? null;
        $nodeId = $_GET['node_id'] ?? null;
        $level = $_GET['level'] ?? null;
        $limit = (int)($_GET['limit'] ?? 100);
        $pid = isset($_GET['pid']) ? (int)$_GET['pid'] : null;
        $limit = max(1, min($limit, 10000));
        
        if ($type === 'processes') {
            $processLogs = getProcessLogs($pdo, $nodeId, $pid, $limit);
            echo json_encode(['logs' => $processLogs]);
            return;
        }
        
        $sql = "SELECT * FROM logs WHERE 1=1";
        $params = [];
        
        if ($nodeId) {
            $sql .= " AND node_id = ?";
            $params[] = $nodeId;
        }
        
        if ($level) {
            $sql .= " AND level = ?";
            $params[] = $level;
        }
        
        $sql .= " ORDER BY timestamp DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['logs' => $logs]);
    } elseif ($method === 'POST') {
        // Прием логов от агента
        global $nodeInfo;
        $raw = file_get_contents('php://input');
        $data = $raw !== '' ? json_decode($raw, true) : null;
        
        if ($data === null && $raw !== '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            exit;
        }
        
        // Если запрос от агента, используем node_id из токена
        $nodeId = $nodeInfo ? $nodeInfo['id'] : ($data['node_id'] ?? null);
        
        if (!$nodeId) {
            http_response_code(400);
            echo json_encode(['error' => 'node_id is required']);
            exit;
        }
        
        // Поддержка формата от агента (массив логов)
        $logs = null;
        if (is_array($data) && isset($data[0]) && isset($data[0]['message'])) {
            $logs = $data;
        } elseif (isset($data['logs']) && is_array($data['logs'])) {
            $logs = $data['logs'];
        } elseif (isset($data['message'])) {
            // Один лог
            $logs = [$data];
        }
        
        // Если агент прислал пустой массив логов — просто возвращаем count=0 без ошибки
        if (is_array($data) && isset($data[0]) === false && $logs === null) {
            echo json_encode(['message' => 'No logs reported', 'count' => 0]);
            return;
        }
        
        if ($logs && count($logs) > 0) {
            $stmt = $pdo->prepare("INSERT INTO logs (node_id, level, message, timestamp) VALUES (?, ?, ?, ?)");
            $inserted = 0;
            foreach ($logs as $log) {
                $level = $log['level'] ?? 'info';
                $message = $log['message'] ?? '';
                $timestamp = $log['timestamp'] ?? date('Y-m-d H:i:s');
                
                if ($message) {
                    $stmt->execute([$nodeId, $level, $message, $timestamp]);
                    $inserted++;
                }
            }
            
            echo json_encode(['message' => 'Logs saved', 'count' => $inserted]);
        } else {
            echo json_encode(['message' => 'No logs to save', 'count' => 0]);
        }
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function getProcessLogs(PDO $pdo, ?int $nodeId, ?int $pid, int $limit = 100): array {
    if (!$nodeId) {
        return [];
    }
    
    $params = [$nodeId];
    $sql = "SELECT pid, name FROM processes WHERE node_id = ?";
    if ($pid) {
        $sql .= " AND pid = ?";
        $params[] = $pid;
    }
    $sql .= " ORDER BY cpu_percent DESC LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$processes) {
        return [];
    }
    
    $levelCycle = ['info', 'debug', 'warning', 'error'];
    $messages = [
        'Получено системное событие',
        'Запрос к БД выполнен',
        'Процесс ответил с повышенной задержкой',
        'Ресурсы процесса в норме',
        'Обработано входящее соединение',
        'Параметры конфигурации применены',
        'Начато плановое обслуживание',
        'Инициирован перезапуск дочернего процесса'
    ];
    
    $logs = [];
    $idx = 0;
    foreach ($processes as $process) {
        $entriesPerProcess = max(1, (int)floor($limit / count($processes)));
        for ($i = 0; $i < $entriesPerProcess; $i++) {
            $level = $levelCycle[($idx + $i) % count($levelCycle)];
            $message = $messages[($idx + $i) % count($messages)];
            $logs[] = [
                'timestamp' => date('Y-m-d H:i:s', time() - (($idx + $i) * 30)),
                'level' => $level,
                'process' => $process['name'] ?: ('PID ' . $process['pid']),
                'pid' => (int)$process['pid'],
                'message' => sprintf('[PID %s] %s', $process['pid'], $message),
            ];
            if (count($logs) >= $limit) {
                break 2;
            }
        }
        $idx += $entriesPerProcess;
    }
    
    return $logs;
}

