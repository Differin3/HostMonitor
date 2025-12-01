<?php
// Простая проверка сессии для API
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

function generateFaviconUrl($url) {
    if (!$url) return null;
    $trimmed = trim($url);
    if (!$trimmed) return null;
    
    // Если это уже полный URL с протоколом
    if (preg_match('/^https?:\/\//i', $trimmed)) {
        try {
            $parsed = parse_url($trimmed);
            $domain = $parsed['host'] ?? null;
            if ($domain) {
                return "https://www.google.com/s2/favicons?sz=64&domain=" . urlencode($domain);
            }
        } catch (Exception $e) {
            // Ignore
        }
        return $trimmed;
    }
    
    // Если это домен без протокола
    $domain = preg_split('/[\/?#]/', $trimmed)[0];
    return "https://www.google.com/s2/favicons?sz=64&domain=" . urlencode($domain);
}

function handleGet($pdo) {
    global $nodeInfo;
    $id = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? null;
    
    // Обработка get-command для агентов (единый формат ответа)
    if ($action === 'get-command') {
        $result = [
            'status' => 'no-command',
            'command' => null,
            'command_status' => null,
            'command_timestamp' => null,
        ];

        if (!$nodeInfo) {
            // Если нет токена, пытаемся найти по имени
            $nodeName = $_GET['id'] ?? null;
            if ($nodeName) {
                $stmt = $pdo->prepare("SELECT * FROM nodes WHERE name = ?");
                $stmt->execute([$nodeName]);
                $node = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($node && $node['command_status'] === 'pending' && $node['last_command']) {
                    $result = [
                        'status' => 'ok',
                        'command' => $node['last_command'],
                        'command_status' => $node['command_status'],
                        'command_timestamp' => $node['command_timestamp'],
                    ];
                }
            }
        } else {
            // Используем node_id из токена
            $stmt = $pdo->prepare("SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?");
            $stmt->execute([$nodeInfo['id']]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($node && $node['command_status'] === 'pending' && $node['last_command']) {
                $result = [
                    'status' => 'ok',
                    'command' => $node['last_command'],
                    'command_status' => $node['command_status'],
                    'command_timestamp' => $node['command_timestamp'],
                ];
            }
        }

        echo json_encode($result);
        return;
    }
    
    if ($action === 'generate-key') {
        echo json_encode(['secret_key' => generateSecretKey()]);
        return;
    }
    
    if ($action === 'generate-config') {
        $nodeId = $_GET['node_id'] ?? $_GET['id'] ?? null;
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
        // Получаем provider_id и favicon_url из таблицы providers
        $stmt = $pdo->query("SELECT n.*, 
                            COALESCE(p.id, NULL) as provider_id, 
                            COALESCE(p.favicon_url, NULL) as favicon_url,
                            COALESCE(n.name, '') as name
                            FROM nodes n 
                            LEFT JOIN providers p ON n.provider_name = p.name 
                            WHERE n.name IS NOT NULL AND n.name != ''
                            ORDER BY n.created_at DESC");
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Вычисляем uptime и ping для всех нод
        foreach ($nodes as &$node) {
            $node['uptime'] = calculateUptime($node);
            $node['ping'] = pingNode($node['host']);
            // Гарантируем, что имя всегда есть
            if (empty($node['name'])) {
                $node['name'] = "Node {$node['id']}";
            }
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
    // Если установлена переменная окружения MASTER_URL, используем её
    if (getenv('MASTER_URL')) {
        $masterUrl = getenv('MASTER_URL');
        $parsedUrl = parse_url($masterUrl);
        $masterHost = $parsedUrl['host'] ?? 'localhost';
        $masterPort = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? '443' : '80');
    } else {
        // Автоматическое определение URL мастера из текущего запроса
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        
        // Получаем хост из HTTP_HOST (может содержать порт)
        $httpHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        
        // Разделяем хост и порт, если порт указан в HTTP_HOST
        if (strpos($httpHost, ':') !== false) {
            list($host, $port) = explode(':', $httpHost, 2);
        } else {
            $host = $httpHost;
            // Используем SERVER_PORT, если порт не указан в HTTP_HOST
            $port = $_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? '443' : '80');
        }
        
        $masterHost = $host;
        $masterPort = $port;
        
        // Формируем URL (не добавляем стандартные порты)
        $masterUrl = $scheme . '://' . $host;
        if (($scheme === 'http' && $port != '80') || ($scheme === 'https' && $port != '443')) {
            $masterUrl .= ':' . $port;
        }
    }
    
    $config = "# Конфигурация агента мониторинга\n";
    $config .= "# Сгенерировано автоматически\n";
    $config .= "# Дата: " . date('Y-m-d H:i:s') . "\n\n";
    $config .= "MASTER_URL=\"" . $masterUrl . "\"\n";
    $config .= "MASTER_HOST=\"" . $masterHost . "\"\n";
    $config .= "MASTER_PORT=\"" . $masterPort . "\"\n";
    $config .= "NODE_NAME=\"" . $node['name'] . "\"\n";
    $config .= "NODE_HOST=\"" . ($node['host'] ?? '') . "\"\n";
    $config .= "NODE_PORT=\"" . ($node['port'] ?? '2222') . "\"\n";
    $config .= "NODE_TOKEN=\"" . $node['node_token'] . "\"\n";
    $config .= "COLLECT_INTERVAL=60\n";
    $config .= "TLS_VERIFY=false\n";
    $config .= "TLS_CERT_PATH=\"\"\n\n";
    $config .= "# Установка зависимостей:\n";
    $config .= "# pip install -r requirements.txt\n\n";
    $config .= "# Запуск:\n";
    $config .= "# python agent/main.py\n";
    
    return $config;
}

function handlePost($pdo) {
    $action = $_GET['action'] ?? null;
    $nodeId = $_GET['id'] ?? null;
    
    // Обработка действий с нодами: POST /api/nodes.php?id={id}&action=node-action
    if ($action && $nodeId) {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $nodeAction = $data['action'] ?? $action;
        
        if (in_array($nodeAction, ['reboot', 'shutdown', 'sync'])) {
            executeNodeAction($pdo, $nodeId, $nodeAction);
            return;
        }
    }
    
    // Обработка специальных действий
    if ($action === 'refresh') {
        if ($nodeId) {
            refreshNode($pdo, $nodeId);
            return;
        }
    }
    
    if ($action === 'refresh-all') {
        refreshAllNodes($pdo);
        return;
    }
    
    // Отчет о статусе команды от агента: POST /api/nodes.php?id={name}&action=command-status
    if ($action === 'command-status') {
        global $nodeInfo;
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }
        
        // Если запрос от агента с токеном, используем node_id из токена
        $targetNodeId = null;
        if ($nodeInfo) {
            $targetNodeId = $nodeInfo['id'];
        } else {
            // Иначе ищем по имени или id
            $stmt = $pdo->prepare("SELECT * FROM nodes WHERE name = ? OR id = ?");
            $stmt->execute([$nodeId, $nodeId]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$node) {
                http_response_code(404);
                echo json_encode(['error' => 'Node not found']);
                return;
            }
            $targetNodeId = $node['id'];
        }
        
        $commandStatus = $data['status'] ?? 'completed'; // completed / failed / pending
        $updateStmt = $pdo->prepare("UPDATE nodes SET command_status = ? WHERE id = ?");
        $updateStmt->execute([$commandStatus, $targetNodeId]);
        
        echo json_encode(['status' => 'ok', 'success' => true, 'message' => 'Command status updated', 'command_status' => $commandStatus]);
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
    $billingAmount = isset($data['billing_amount']) && $data['billing_amount'] !== '' ? (float)$data['billing_amount'] : null;
    $billingPeriod = isset($data['billing_period']) ? (int)$data['billing_period'] : 30;
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
    
    // Сначала получаем текущие данные ноды
    $currentStmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
    $currentStmt->execute([$id]);
    $currentNode = $currentStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentNode) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        return;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        return;
    }
    
    // Используем переданные значения или текущие из БД
    $name = $data['name'] ?? $currentNode['name'];
    $host = $data['host'] ?? $currentNode['host'];
    $port = isset($data['port']) ? (int)$data['port'] : (int)$currentNode['port'];
    $country = $data['country'] ?? $currentNode['country'];
    $providerName = $data['provider_name'] ?? $currentNode['provider_name'];
    $providerUrl = $data['provider_url'] ?? $currentNode['provider_url'];
    $billingAmount = isset($data['billing_amount']) && $data['billing_amount'] !== '' ? (float)$data['billing_amount'] : $currentNode['billing_amount'];
    $billingPeriod = isset($data['billing_period']) ? (int)$data['billing_period'] : ($currentNode['billing_period'] ?? 30);
    $lastPaymentDate = $data['last_payment_date'] ?? $currentNode['last_payment_date'];
    $nextPaymentDate = $data['next_payment_date'] ?? $currentNode['next_payment_date'];
    
    // Если указан провайдер, получаем его URL и favicon_url
    if ($providerName) {
        $providerStmt = $pdo->prepare("SELECT url, favicon_url FROM providers WHERE name = ?");
        $providerStmt->execute([$providerName]);
        $provider = $providerStmt->fetch();
        if ($provider) {
            if (!$providerUrl) {
                $providerUrl = $provider['url'];
            }
            // Если у провайдера нет favicon_url, но есть url, генерируем его
            if (!$provider['favicon_url'] && $provider['url']) {
                $generatedFavicon = generateFaviconUrl($provider['url']);
                if ($generatedFavicon) {
                    $updateFaviconStmt = $pdo->prepare("UPDATE providers SET favicon_url = ? WHERE name = ?");
                    $updateFaviconStmt->execute([$generatedFavicon, $providerName]);
                }
            }
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

function executeNodeAction($pdo, $nodeId, $action) {
    $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        return;
    }
    
    // Сохраняем команду в БД для выполнения агентом
    $stmt = $pdo->prepare("UPDATE nodes SET last_command = ?, command_status = 'pending', command_timestamp = NOW() WHERE id = ?");
    $stmt->execute([$action, $nodeId]);
    
    // TODO: Реальная реализация через SSH или API агента
    // Пока возвращаем успех
    echo json_encode([
        'success' => true,
        'message' => "Command '{$action}' queued for node",
        'node_id' => $nodeId,
        'action' => $action
    ]);
}

// Endpoint для получения информации о сети: GET /api/nodes.php?id={id}&action=network
if ($method === 'GET' && isset($_GET['id']) && isset($_GET['action']) && $_GET['action'] === 'network') {
    $nodeId = $_GET['id'];
    
    $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
    $stmt->execute([$nodeId]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$node) {
        http_response_code(404);
        echo json_encode(['error' => 'Node not found']);
        exit;
    }
    
    // Получаем последние метрики сети
    $metricsStmt = $pdo->prepare("SELECT network_in, network_out FROM metrics WHERE node_id = ? ORDER BY timestamp DESC LIMIT 1");
    $metricsStmt->execute([$nodeId]);
    $metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Получаем порты ноды
    $portsStmt = $pdo->prepare("SELECT port, type, status FROM ports WHERE node_id = ?");
    $portsStmt->execute([$nodeId]);
    $ports = $portsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // TODO: Реальная реализация получения информации о сетевых интерфейсах через агент
    $networkData = [
        'interfaces' => [
            [
                'name' => 'eth0',
                'ip' => $node['host'] ?? '0.0.0.0',
                'status' => $node['status'] === 'online' ? 'up' : 'down',
                'speed' => '1000',
                'rx_bytes' => $metrics['network_in'] ?? 0,
                'tx_bytes' => $metrics['network_out'] ?? 0
            ]
        ],
        'ports' => $ports,
        'connections' => []
    ];
    
    echo json_encode(['network' => $networkData]);
    exit;
}
