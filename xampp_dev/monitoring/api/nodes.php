<?php
// Простая проверка сессии для API
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../includes/database.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

try {
    switch ($method) {
        case 'GET':
            handleGet($pdo);
            break;
        case 'POST':
            handlePost($pdo);
            break;
        case 'PUT':
            handlePut($pdo);
            break;
        case 'DELETE':
            handleDelete($pdo);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function generateSecretKey() {
    return base64_encode(random_bytes(64));
}

function generateNodeToken() {
    return bin2hex(random_bytes(32));
}

function handleGet($pdo) {
    $id = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? null;
    
    if ($action === 'generate-key') {
        echo json_encode(['secret_key' => generateSecretKey()]);
        return;
    }
    
    if ($action === 'generate-config') {
        $nodeId = $_GET['node_id'] ?? null;
        if (!$nodeId) {
            http_response_code(400);
            echo json_encode(['error' => 'Node ID required']);
            return;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
        $stmt->execute([$nodeId]);
        $node = $stmt->fetch();
        
        if (!$node) {
            http_response_code(404);
            echo json_encode(['error' => 'Node not found']);
            return;
        }
        
        $config = generateAgentConfig($node);
        echo json_encode(['config' => $config]);
        return;
    }
    
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
        $stmt->execute([$id]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$node) {
            http_response_code(404);
            echo json_encode(['error' => 'Node not found']);
            return;
        }
        
        // Вычисляем uptime и ping динамически
        $node['uptime'] = calculateUptime($node);
        $node['ping'] = pingNode($node['host']);
        
        echo json_encode(['node' => $node]);
    } else {
        // Получаем provider_id из таблицы providers
        $stmt = $pdo->query("SELECT n.*, p.id as provider_id, p.favicon_url 
                            FROM nodes n 
                            LEFT JOIN providers p ON n.provider_name = p.name 
                            ORDER BY n.created_at DESC");
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Вычисляем uptime и ping для всех нод
        foreach ($nodes as &$node) {
            $node['uptime'] = calculateUptime($node);
            $node['ping'] = pingNode($node['host']);
        }
        
        echo json_encode(['nodes' => $nodes]);
    }
}

function calculateUptime($node) {
    if (!$node['last_seen'] || $node['status'] !== 'online') {
        return 0;
    }
    $lastSeen = strtotime($node['last_seen']);
    $now = time();
    return max(0, $now - $lastSeen);
}

function pingNode($host) {
    // Простая проверка доступности хоста
    $start = microtime(true);
    $connection = @fsockopen($host, 80, $errno, $errstr, 1);
    if ($connection) {
        fclose($connection);
        return round((microtime(true) - $start) * 1000);
    }
    return null;
}

function generateAgentConfig($node) {
    $masterUrl = getenv('MASTER_URL') ?: 'http://localhost:8000';
    
    $config = "# Конфигурация агента мониторинга\n";
    $config .= "# Сгенерировано автоматически\n\n";
    $config .= "MASTER_URL=\"" . $masterUrl . "\"\n";
    $config .= "NODE_NAME=\"" . $node['name'] . "\"\n";
    $config .= "NODE_TOKEN=\"" . $node['node_token'] . "\"\n";
    $config .= "COLLECT_INTERVAL=60\n";
    $config .= "TLS_VERIFY=false\n\n";
    $config .= "# Установка зависимостей:\n";
    $config .= "# pip install -r requirements.txt\n\n";
    $config .= "# Запуск:\n";
    $config .= "# python agent/main.py\n";
    
    return $config;
}

function handlePost($pdo) {
    $action = $_GET['action'] ?? null;
    
    // Обработка специальных действий
    if ($action === 'refresh') {
        $nodeId = $_GET['id'] ?? null;
        if ($nodeId) {
            refreshNode($pdo, $nodeId);
            return;
        }
    }
    
    if ($action === 'refresh-all') {
        refreshAllNodes($pdo);
        return;
    }
    
    // Обычное создание ноды
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $name = $data['name'] ?? '';
    $host = $data['host'] ?? '';
    $port = (int)($data['port'] ?? 2222);
    $country = $data['country'] ?? null;
    $secretKey = $data['secret_key'] ?? generateSecretKey();
    $nodeToken = $data['node_token'] ?? generateNodeToken();
    
    $providerName = $data['provider_name'] ?? null;
    $providerUrl = $data['provider_url'] ?? null;
    $billingAmount = $data['billing_amount'] ? (float)$data['billing_amount'] : null;
    $billingPeriod = (int)($data['billing_period'] ?? 30);
    $lastPaymentDate = $data['last_payment_date'] ?? null;
    $nextPaymentDate = $data['next_payment_date'] ?? null;
    
    if (empty($name) || empty($host)) {
        http_response_code(400);
        echo json_encode(['error' => 'Name and host are required']);
        return;
    }
    
    // Если указан провайдер, получаем его URL
    if ($providerName && !$providerUrl) {
        $providerStmt = $pdo->prepare("SELECT url FROM providers WHERE name = ?");
        $providerStmt->execute([$providerName]);
        $provider = $providerStmt->fetch();
        if ($provider) {
            $providerUrl = $provider['url'];
        }
    }
    
    $sql = "INSERT INTO nodes (name, host, port, country, secret_key, node_token, provider_name, provider_url, 
            billing_amount, billing_period, last_payment_date, next_payment_date, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'offline')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $name, $host, $port, $country, $secretKey, $nodeToken, $providerName, $providerUrl, 
        $billingAmount, $billingPeriod, $lastPaymentDate, $nextPaymentDate
    ]);
    
    $id = $pdo->lastInsertId();
    
    http_response_code(201);
    echo json_encode(['id' => $id, 'message' => 'Node created', 'node_token' => $nodeToken]);
}

function handlePut($pdo) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    $name = $data['name'] ?? '';
    $host = $data['host'] ?? '';
    $port = (int)($data['port'] ?? 2222);
    $country = $data['country'] ?? null;
    $providerName = $data['provider_name'] ?? null;
    $providerUrl = $data['provider_url'] ?? null;
    $billingAmount = $data['billing_amount'] ? (float)$data['billing_amount'] : null;
    $billingPeriod = (int)($data['billing_period'] ?? 30);
    $lastPaymentDate = $data['last_payment_date'] ?? null;
    $nextPaymentDate = $data['next_payment_date'] ?? null;
    
    // Если указан провайдер, получаем его URL
    if ($providerName && !$providerUrl) {
        $providerStmt = $pdo->prepare("SELECT url FROM providers WHERE name = ?");
        $providerStmt->execute([$providerName]);
        $provider = $providerStmt->fetch();
        if ($provider) {
            $providerUrl = $provider['url'];
        }
    }
    
    $sql = "UPDATE nodes SET name = ?, host = ?, port = ?, country = ?, provider_name = ?, provider_url = ?, 
            billing_amount = ?, billing_period = ?, last_payment_date = ?, next_payment_date = ? 
            WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $name, $host, $port, $country, $providerName, $providerUrl,
        $billingAmount, $billingPeriod, $lastPaymentDate, $nextPaymentDate, $id
    ]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        return;
    }
    
    echo json_encode(['message' => 'Node updated']);
}

function handleDelete($pdo) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        return;
    }
    
    $stmt = $pdo->prepare("DELETE FROM nodes WHERE id = ?");
    $stmt->execute([$id]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        return;
    }
    
    http_response_code(200);
    echo json_encode(['message' => 'Node deleted']);
}

function refreshNode($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
    $stmt->execute([$id]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        return;
    }
    
    $ping = pingNode($node['host']);
    $status = $ping !== null ? 'online' : 'offline';
    $uptime = $status === 'online' ? calculateUptime($node) : 0;
    
    // Обновляем статус и last_seen в БД
    $updateStmt = $pdo->prepare("UPDATE nodes SET status = ?, last_seen = NOW() WHERE id = ?");
    $updateStmt->execute([$status, $id]);
    
    echo json_encode(['success' => true, 'node' => ['id' => $id, 'status' => $status, 'ping' => $ping, 'uptime' => $uptime]]);
}

function refreshAllNodes($pdo) {
    $stmt = $pdo->query("SELECT * FROM nodes");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updated = 0;
    foreach ($nodes as $node) {
        $ping = pingNode($node['host']);
        $status = $ping !== null ? 'online' : 'offline';
        $uptime = $status === 'online' ? calculateUptime($node) : 0;
        
        $updateStmt = $pdo->prepare("UPDATE nodes SET status = ?, last_seen = NOW() WHERE id = ?");
        $updateStmt->execute([$status, $node['id']]);
        $updated++;
    }
    
    echo json_encode(['success' => true, 'updated' => $updated]);
}
