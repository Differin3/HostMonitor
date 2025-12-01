<?php
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
    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handleGet($pdo) {
    $nodeId = $_GET['node_id'] ?? null;
    $containerId = $_GET['container_id'] ?? null;
    $logs = isset($_GET['logs']) && $_GET['logs'] == '1';
    $network = isset($_GET['network']) && $_GET['network'] == '1';
    $limit = isset($_GET['limit']) ? max(1, min((int)$_GET['limit'], 10000)) : 200;
    
    // Если запрашиваются логи конкретного контейнера
    if ($logs && $containerId && $nodeId) {
        $logsData = getContainerLogs($pdo, $nodeId, $containerId, $limit);
        echo json_encode(['logs' => $logsData]);
        return;
    }
    
    // Если запрашиваются данные сети
    if ($network && $nodeId) {
        // Получаем контейнеры для ноды
        $stmt = $pdo->prepare("SELECT * FROM containers WHERE node_id = ?");
        $stmt->execute([$nodeId]);
        $containers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Генерируем тестовые данные сети на основе контейнеров
        $networks = [];
        $connections = [];
        $ports = [];
        
        // Создаем сети на основе контейнеров
        $networkNames = ['bridge', 'host', 'none'];
        foreach ($networkNames as $netName) {
            $netContainers = array_slice($containers, 0, rand(1, min(3, count($containers))));
            $networks[] = [
                'name' => $netName,
                'driver' => $netName === 'bridge' ? 'bridge' : ($netName === 'host' ? 'host' : 'null'),
                'containers' => count($netContainers)
            ];
        }
        
        // Создаем связи между контейнерами
        if (count($containers) > 1) {
            for ($i = 0; $i < min(3, count($containers) - 1); $i++) {
                $from = $containers[$i]['name'] ?? "container-{$i}";
                $to = $containers[$i + 1]['name'] ?? "container-" . ($i + 1);
                $connections[] = [
                    'from' => $from,
                    'to' => $to,
                    'network' => 'bridge'
                ];
            }
        }
        
        // Создаем порты на основе контейнеров
        $portNumbers = [80, 443, 3306, 5432, 6379, 8080, 9000];
        foreach (array_slice($containers, 0, min(5, count($containers))) as $container) {
            $portNum = $portNumbers[array_rand($portNumbers)];
            $ports[] = [
                'container' => $container['name'] ?? 'unknown',
                'host' => $portNum,
                'container_port' => $portNum,
                'protocol' => 'tcp'
            ];
        }
        
        echo json_encode([
            'networks' => $networks,
            'connections' => $connections,
            'ports' => $ports
        ]);
        return;
    }
    
    if ($nodeId) {
        $stmt = $pdo->prepare("SELECT * FROM containers WHERE node_id = ? ORDER BY name");
        $stmt->execute([$nodeId]);
        $containers = $stmt->fetchAll();
        echo json_encode(['containers' => $containers]);
    } else {
        $stmt = $pdo->query("SELECT * FROM containers ORDER BY timestamp DESC");
        $containers = $stmt->fetchAll();
        echo json_encode(['containers' => $containers]);
    }
}

function getContainerLogs($pdo, $nodeId, $containerId, $limit = 200) {
    $stmt = $pdo->prepare("SELECT name FROM containers WHERE node_id = ? AND container_id = ? LIMIT 1");
    $stmt->execute([$nodeId, $containerId]);
    $container = $stmt->fetch(PDO::FETCH_ASSOC);
    $displayName = $container['name'] ?? substr($containerId, 0, 12);
    
    $levels = ['info', 'debug', 'warning', 'error'];
    $messages = [
        'Обновление образа завершено',
        'Контейнер перезапущен',
        'Завершена проверка готовности',
        'Получены метрики ресурса',
        'Выполнено соединение с внешним сервисом',
        'Очередь задач пуста',
        'Высокая загрузка CPU внутри контейнера',
        'Перезапуск приложения внутри контейнера',
        'Грейсфулл-шатдаун завершён',
        'Подключение к базе данных успешно'
    ];
    
    $logs = [];
    for ($i = 0; $i < $limit; $i++) {
        $level = $levels[$i % count($levels)];
        $message = $messages[$i % count($messages)];
        $logs[] = [
            'timestamp' => date('Y-m-d H:i:s', time() - ($i * 45)),
            'level' => $level,
            'message' => sprintf('[%s] %s (container_id=%s)', $displayName, $message, substr($containerId, 0, 12))
        ];
    }
    
    return $logs;
}

function handlePost($pdo) {
    global $nodeInfo;
    
    $raw = file_get_contents('php://input');
    $data = $raw !== '' ? json_decode($raw, true) : null;
    
    if ($data === null && $raw !== '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    // Если запрос от агента, используем node_id из токена
    $nodeId = $nodeInfo ? $nodeInfo['id'] : ($data['node_id'] ?? null);
    
    if (!$nodeId) {
        http_response_code(400);
        echo json_encode(['error' => 'node_id is required']);
        return;
    }
    
    // Поддержка формата от агента (массив контейнеров)
    $containers = null;
    if (is_array($data) && isset($data[0]) && isset($data[0]['container_id'])) {
        $containers = $data;
    } elseif (isset($data['containers']) && is_array($data['containers'])) {
        $containers = $data['containers'];
    }
    
    // Если агент прислал пустой массив контейнеров — очищаем для ноды и возвращаем 200
    if (is_array($data) && isset($data[0]) === false && $containers === null) {
        $deleteStmt = $pdo->prepare("DELETE FROM containers WHERE node_id = ?");
        $deleteStmt->execute([$nodeId]);
        echo json_encode(['message' => 'No containers reported', 'count' => 0]);
        return;
    }
    
    if ($containers && count($containers) > 0) {
        // Удаляем старые контейнеры для этой ноды
        $deleteStmt = $pdo->prepare("DELETE FROM containers WHERE node_id = ?");
        $deleteStmt->execute([$nodeId]);
        
        // Вставляем новые контейнеры
        $stmt = $pdo->prepare("INSERT INTO containers (node_id, container_id, name, image, status, cpu_percent, memory_percent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($containers as $container) {
            $stmt->execute([
                $nodeId,
                $container['container_id'] ?? '',
                $container['name'] ?? 'unknown',
                $container['image'] ?? '',
                $container['status'] ?? 'stopped',
                $container['cpu_percent'] ?? 0,
                $container['memory_percent'] ?? 0
            ]);
        }
        
        http_response_code(201);
        echo json_encode(['message' => 'Containers updated', 'count' => count($containers)]);
        return;
    }
    
    // Старый формат (один контейнер)
    $containerId = $data['container_id'] ?? null;
    $name = $data['name'] ?? null;
    $image = $data['image'] ?? null;
    $status = $data['status'] ?? 'stopped';
    $cpuPercent = $data['cpu_percent'] ?? 0;
    $memoryPercent = $data['memory_percent'] ?? 0;
    
    if (!$containerId || !$name) {
        http_response_code(400);
        echo json_encode(['error' => 'container_id and name are required']);
        return;
    }
    
    // Проверяем существование контейнера
    $checkStmt = $pdo->prepare("SELECT id FROM containers WHERE node_id = ? AND container_id = ?");
    $checkStmt->execute([$nodeId, $containerId]);
    $existing = $checkStmt->fetch();
    
    if ($existing) {
        $updateStmt = $pdo->prepare("UPDATE containers SET name = ?, image = ?, status = ?, cpu_percent = ?, memory_percent = ? WHERE id = ?");
        $updateStmt->execute([$name, $image, $status, $cpuPercent, $memoryPercent, $existing['id']]);
    } else {
        $insertStmt = $pdo->prepare("INSERT INTO containers (node_id, container_id, name, image, status, cpu_percent, memory_percent) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insertStmt->execute([$nodeId, $containerId, $name, $image, $status, $cpuPercent, $memoryPercent]);
    }
    
    http_response_code(201);
    echo json_encode(['message' => 'Container updated']);
}

// Обработка действий с контейнерами: POST /api/containers.php?node_id={id}&container_id={cid}&action=start
if ($method === 'POST' && isset($_GET['node_id']) && isset($_GET['container_id']) && isset($_GET['action'])) {
    $nodeId = $_GET['node_id'];
    $containerId = $_GET['container_id'];
    $action = $_GET['action'];
    
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? $action;
    
    if (!in_array($action, ['start', 'stop', 'restart', 'logs'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use start, stop, restart, or logs']);
        exit;
    }
    
    // Проверяем существование контейнера
    $stmt = $pdo->prepare("SELECT * FROM containers WHERE node_id = ? AND container_id = ?");
    $stmt->execute([$nodeId, $containerId]);
    $container = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$container) {
        http_response_code(404);
        echo json_encode(['error' => 'Container not found']);
        exit;
    }
    
    // Получаем информацию о ноде
    $nodeStmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
    $nodeStmt->execute([$nodeId]);
    $node = $nodeStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        exit;
    }
    
    if ($action === 'logs') {
        // Возвращаем URL для получения логов
        $logsUrl = "/api/containers.php?node_id={$nodeId}&container_id={$containerId}&logs=1";
        echo json_encode([
            'success' => true,
            'logs_url' => $logsUrl,
            'container_id' => $containerId
        ]);
        exit;
    }
    
    // Сохраняем команду в БД для выполнения агентом
    $command = "docker {$action} {$containerId}";
    $stmt = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
    $stmt->execute([$command, $nodeId]);
    
    // TODO: Реальная реализация через SSH или Docker API
    echo json_encode([
        'success' => true,
        'message' => "Command '{$action}' queued for container",
        'node_id' => $nodeId,
        'container_id' => $containerId,
        'action' => $action
    ]);
    exit;
}

