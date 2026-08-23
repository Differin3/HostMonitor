<?php
// Простая проверка сессии для API
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

if (!function_exists('nodes_ensure_agent_columns')) {
    function nodes_ensure_agent_columns(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        foreach ([
            "ALTER TABLE nodes ADD COLUMN agent_version VARCHAR(32) NULL",
            "ALTER TABLE nodes ADD COLUMN agent_commit VARCHAR(64) NULL",
            "ALTER TABLE nodes ADD COLUMN agent_remote_commit VARCHAR(64) NULL",
            "ALTER TABLE nodes ADD COLUMN agent_branch VARCHAR(64) NULL",
            "ALTER TABLE nodes ADD COLUMN agent_update_available TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE nodes ADD COLUMN agent_updated_at TIMESTAMP NULL",
            "ALTER TABLE nodes ADD COLUMN command_result TEXT NULL",
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                // column already exists
            }
        }
    }
}

nodes_ensure_agent_columns($pdo);

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
            $nodeId = $nodeInfo['id'];
            $nodeName = $nodeInfo['name'];
            
            // Проверяем команду по node_id (основной способ)
            $stmt = $pdo->prepare("SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?");
            $stmt->execute([$nodeId]);
            $node = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Дополнительная проверка - может быть команда поставлена по node_name
            if (!$node || !$node['last_command'] || $node['command_status'] !== 'pending') {
                $stmt2 = $pdo->prepare("SELECT last_command, command_status, command_timestamp FROM nodes WHERE name = ?");
                $stmt2->execute([$nodeName]);
                $node2 = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($node2 && $node2['command_status'] === 'pending' && $node2['last_command']) {
                    $node = $node2;
                    error_log("Command found by node_name={$nodeName}, node_id={$nodeId}, command={$node['last_command']}");
                }
            }
            
            if ($node && $node['command_status'] === 'pending' && $node['last_command']) {
                $result = [
                    'status' => 'ok',
                    'command' => $node['last_command'],
                    'command_status' => $node['command_status'],
                    'command_timestamp' => $node['command_timestamp'],
                ];
                error_log("Command found for node_id={$nodeId}, node_name={$nodeName}, command={$node['last_command']}");
            } else {
                error_log("No command found for node_id={$nodeId}, node_name={$nodeName}, status=" . ($node['command_status'] ?? 'NULL') . ", command=" . ($node['last_command'] ?? 'NULL'));
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
        
        // Получаем последние метрики из таблицы metrics
        $metricsStmt = $pdo->prepare("SELECT cpu_percent, memory_percent, disk_percent, network_in, network_out,
                                            memory_used, memory_total, disk_used, disk_total, swap_percent, load_avg, cpu_count
                                     FROM metrics
                                     WHERE node_id = ?
                                     ORDER BY timestamp DESC
                                     LIMIT 1");
        try {
            $metricsStmt->execute([$id]);
            $metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $metricsStmt = $pdo->prepare("SELECT cpu_percent, memory_percent, disk_percent, network_in, network_out
                                         FROM metrics
                                         WHERE node_id = ?
                                         ORDER BY timestamp DESC
                                         LIMIT 1");
            $metricsStmt->execute([$id]);
            $metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Получаем последние GPU метрики
        try {
            $gpuStmt = $pdo->prepare("SELECT gpu_index, gpu_name, vendor, utilization, memory_used, memory_total, temperature 
                                     FROM gpu_metrics 
                                     WHERE node_id = ? 
                                     ORDER BY timestamp DESC 
                                     LIMIT 10");
            $gpuStmt->execute([$id]);
            $gpuMetrics = $gpuStmt->fetchAll(PDO::FETCH_ASSOC);
            if ($gpuMetrics) {
                $node['gpu'] = $gpuMetrics;
                // Вычисляем среднюю загрузку GPU
                $avgGpuUtil = 0;
                if (count($gpuMetrics) > 0) {
                    $avgGpuUtil = array_sum(array_column($gpuMetrics, 'utilization')) / count($gpuMetrics);
                }
                $node['gpu_usage'] = round($avgGpuUtil, 1);
            } else {
                $node['gpu'] = [];
                $node['gpu_usage'] = null;
            }
        } catch (Exception $e) {
            // Таблица gpu_metrics может не существовать
            $node['gpu'] = [];
            $node['gpu_usage'] = null;
        }
        
        // Добавляем метрики в объект ноды (используем названия, которые ожидает фронтенд)
        if ($metrics) {
            $node['cpu_usage'] = (float)($metrics['cpu_percent'] ?? 0);
            $node['memory_usage'] = (float)($metrics['memory_percent'] ?? 0);
            $node['disk_usage'] = (float)($metrics['disk_percent'] ?? 0);
            $node['network_in'] = (float)($metrics['network_in'] ?? 0);
            $node['network_out'] = (float)($metrics['network_out'] ?? 0);
            $node['memory_used'] = (float)($metrics['memory_used'] ?? 0);
            $node['memory_total'] = (float)($metrics['memory_total'] ?? 0);
            $node['disk_used'] = (float)($metrics['disk_used'] ?? 0);
            $node['disk_total'] = (float)($metrics['disk_total'] ?? 0);
            $node['swap_percent'] = (float)($metrics['swap_percent'] ?? 0);
            $node['load_avg'] = (float)($metrics['load_avg'] ?? 0);
            $node['cpu_count'] = (int)($metrics['cpu_count'] ?? 0);
        } else {
            $node['cpu_usage'] = 0;
            $node['memory_usage'] = 0;
            $node['disk_usage'] = 0;
            $node['network_in'] = 0;
            $node['network_out'] = 0;
            $node['memory_used'] = 0;
            $node['memory_total'] = 0;
            $node['disk_used'] = 0;
            $node['disk_total'] = 0;
            $node['swap_percent'] = 0;
            $node['load_avg'] = 0;
            $node['cpu_count'] = 0;
        }
        
        echo json_encode(['node' => $node]);
    } else {
        // Получаем все ноды с LEFT JOIN для провайдеров (как в старой версии)
        $stmt = $pdo->query("SELECT n.*, 
                            COALESCE(p.id, NULL) as provider_id, 
                            COALESCE(p.favicon_url, NULL) as favicon_url
                            FROM nodes n 
                            LEFT JOIN providers p ON n.provider_name = p.name 
                            ORDER BY n.id ASC");
        $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Убираем дубликаты по ID (на случай если JOIN создал дубликаты)
        $uniqueNodes = [];
        $seenIds = [];
        foreach ($nodes as $node) {
            $nodeId = (int)$node['id'];
            if (!isset($seenIds[$nodeId])) {
                $seenIds[$nodeId] = true;
                $uniqueNodes[] = $node;
            }
        }
        $nodes = $uniqueNodes;

        // Подтягиваем последние метрики для всех нод одним запросом
        $metricsByNode = [];
        if (!empty($nodes)) {
            $nodeIds = array_column($nodes, 'id');
            $placeholders = implode(',', array_fill(0, count($nodeIds), '?'));

            // Берём последнюю запись metrics по timestamp для каждой ноды
            $metricsSql = "
                SELECT m.node_id,
                       m.cpu_percent,
                       m.memory_percent,
                       m.disk_percent,
                       m.network_in,
                       m.network_out,
                       m.memory_used,
                       m.memory_total,
                       m.disk_used,
                       m.disk_total,
                       m.swap_percent,
                       m.load_avg,
                       m.cpu_count
                FROM metrics m
                INNER JOIN (
                    SELECT node_id, MAX(timestamp) AS ts
                    FROM metrics
                    WHERE node_id IN ($placeholders)
                    GROUP BY node_id
                ) last ON last.node_id = m.node_id AND last.ts = m.timestamp
            ";
            try {
                $metricsStmt = $pdo->prepare($metricsSql);
                $metricsStmt->execute($nodeIds);
                while ($row = $metricsStmt->fetch(PDO::FETCH_ASSOC)) {
                    $metricsByNode[$row['node_id']] = $row;
                }
            } catch (Exception $e) {
                $metricsSql = "
                    SELECT m.node_id,
                           m.cpu_percent,
                           m.memory_percent,
                           m.disk_percent,
                           m.network_in,
                           m.network_out
                    FROM metrics m
                    INNER JOIN (
                        SELECT node_id, MAX(timestamp) AS ts
                        FROM metrics
                        WHERE node_id IN ($placeholders)
                        GROUP BY node_id
                    ) last ON last.node_id = m.node_id AND last.ts = m.timestamp
                ";
                $metricsStmt = $pdo->prepare($metricsSql);
                $metricsStmt->execute($nodeIds);
                while ($row = $metricsStmt->fetch(PDO::FETCH_ASSOC)) {
                    $metricsByNode[$row['node_id']] = $row;
                }
            }
        }
        
        // Вычисляем uptime и ping для всех нод, проверяем статус на основе last_seen
        // (но НЕ обновляем статус в БД автоматически - это делается только через heartbeat или refresh)
        // Offline только после ~3 циклов агента (COLLECT_INTERVAL≈60с), не из‑за ICMP ping
        $heartbeat_timeout = node_heartbeat_timeout_sec();
        foreach ($nodes as &$node) {
            // Проверяем статус на основе last_seen (только для отображения, без обновления БД)
            if ($node['last_seen']) {
                $lastSeenTimestamp = strtotime($node['last_seen']);
                $secondsSinceLastSeen = time() - $lastSeenTimestamp;
                
                // Если прошло больше timeout секунд - считаем ноду offline для отображения
                if ($secondsSinceLastSeen > $heartbeat_timeout) {
                    $node['status'] = 'offline';
                } else {
                    // Если last_seen свежий - нода online
                    $node['status'] = 'online';
                }
            }
            
            $node['uptime'] = calculateUptime($node);
            // Пинг только если нода была online (last_seen не NULL) или если явно запрошен refresh
            // Для новых нод без агента пинг будет null
            if ($node['last_seen'] || $node['status'] === 'online') {
                $node['ping'] = pingNode($node['host']);
            } else {
                $node['ping'] = null;
            }
            // Гарантируем, что имя всегда есть
            if (empty($node['name'])) {
                $node['name'] = "Node {$node['id']}";
            }

            // Подставляем последние метрики (для списков, графиков и трафика)
            if (isset($metricsByNode[$node['id']])) {
                $m = $metricsByNode[$node['id']];
                $node['cpu_usage'] = (float)($m['cpu_percent'] ?? 0);
                $node['memory_usage'] = (float)($m['memory_percent'] ?? 0);
                $node['disk_usage'] = (float)($m['disk_percent'] ?? 0);
                $node['network_in'] = (float)($m['network_in'] ?? 0);
                $node['network_out'] = (float)($m['network_out'] ?? 0);
                $node['memory_used'] = (float)($m['memory_used'] ?? 0);
                $node['memory_total'] = (float)($m['memory_total'] ?? 0);
                $node['disk_used'] = (float)($m['disk_used'] ?? 0);
                $node['disk_total'] = (float)($m['disk_total'] ?? 0);
                $node['swap_percent'] = (float)($m['swap_percent'] ?? 0);
                $node['load_avg'] = (float)($m['load_avg'] ?? 0);
                $node['cpu_count'] = (int)($m['cpu_count'] ?? 0);
            } else {
                $node['cpu_usage'] = 0.0;
                $node['memory_usage'] = 0.0;
                $node['disk_usage'] = 0.0;
                $node['network_in'] = 0.0;
                $node['network_out'] = 0.0;
                $node['memory_used'] = 0.0;
                $node['memory_total'] = 0.0;
                $node['disk_used'] = 0.0;
                $node['disk_total'] = 0.0;
                $node['swap_percent'] = 0.0;
                $node['load_avg'] = 0.0;
                $node['cpu_count'] = 0;
            }
            
            // Получаем GPU метрики для каждой ноды
            try {
                $gpuStmt = $pdo->prepare("SELECT gpu_index, gpu_name, vendor, utilization, memory_used, memory_total, temperature 
                                         FROM gpu_metrics 
                                         WHERE node_id = ? 
                                         ORDER BY timestamp DESC 
                                         LIMIT 10");
                $gpuStmt->execute([$node['id']]);
                $gpuMetrics = $gpuStmt->fetchAll(PDO::FETCH_ASSOC);
                if ($gpuMetrics) {
                    $node['gpu'] = $gpuMetrics;
                    // Вычисляем среднюю загрузку GPU
                    $avgGpuUtil = 0;
                    if (count($gpuMetrics) > 0) {
                        $avgGpuUtil = array_sum(array_column($gpuMetrics, 'utilization')) / count($gpuMetrics);
                    }
                    $node['gpu_usage'] = round($avgGpuUtil, 1);
                } else {
                    $node['gpu'] = [];
                    $node['gpu_usage'] = null;
                }
            } catch (Exception $e) {
                // Таблица gpu_metrics может не существовать
                $node['gpu'] = [];
                $node['gpu_usage'] = null;
            }
        }
        
        echo json_encode(['nodes' => $nodes]);
    }
}

function calculateUptime($node) {
    // Uptime - это время с момента первого heartbeat (first_seen) или created_at
    // Если нода offline или нет last_seen - uptime = 0
    if (!$node['last_seen'] || $node['status'] !== 'online') {
        return 0;
    }
    
    // Используем first_seen если есть, иначе created_at, иначе last_seen (как fallback)
    $startTime = null;
    if (!empty($node['first_seen'])) {
        $startTime = strtotime($node['first_seen']);
    } elseif (!empty($node['created_at'])) {
        $startTime = strtotime($node['created_at']);
    } else {
        // Fallback: считаем от last_seen (неправильно, но лучше чем 0)
        $startTime = strtotime($node['last_seen']);
    }
    
    $now = time();
    return max(0, $now - $startTime);
}

function pingNode($host) {
    if (empty($host)) {
        return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return null;
    }
    
    $command = "ping -c 1 -W 1 " . escapeshellarg($host) . " 2>/dev/null";
    $output = @shell_exec($command);
    
    if ($output && preg_match('/time=([\d.]+)\s*ms/', $output, $matches)) {
        return round((float)$matches[1]);
    }
    
    // Если ping недоступен, пробуем TCP подключение к порту 22 (SSH)
    $start = microtime(true);
    $connection = @fsockopen($host, 22, $errno, $errstr, 1);
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
        
        // Получаем реальный IP из заголовков (для прокси) или из запроса
        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($realIp) {
            // X-Forwarded-For может содержать несколько IP, берем первый
            $realIp = trim(explode(',', $realIp)[0]);
        }
        
        // Получаем хост из HTTP_HOST, но игнорируем 0.0.0.0 и localhost
        $httpHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
        
        // Если HTTP_HOST содержит 0.0.0.0 или localhost, определяем реальный IP сервера
        if ($httpHost && $httpHost !== '0.0.0.0' && $httpHost !== 'localhost' && strpos($httpHost, '0.0.0.0:') !== 0) {
            $host = $httpHost;
        } else {
            // Определяем реальный IP сервера
            // 1. Пробуем получить внешний IP через команду (самый надежный способ)
            $externalIp = @shell_exec("hostname -I 2>/dev/null | awk '{print \$1}' || curl -s ifconfig.me 2>/dev/null || curl -s ifconfig.co 2>/dev/null || echo ''");
            $externalIp = trim($externalIp);
            
            if ($externalIp && filter_var($externalIp, FILTER_VALIDATE_IP)) {
                $host = $externalIp;
            } else {
                // 2. Используем SERVER_ADDR (IP интерфейса сервера)
                $host = $_SERVER['SERVER_ADDR'] ?? 'localhost';
                
                // 3. Если это внутренний IP, пробуем получить первый не-localhost IP
                if (in_array($host, ['127.0.0.1', '::1', 'localhost', '0.0.0.0'])) {
                    $allIps = @shell_exec("hostname -I 2>/dev/null");
                    if ($allIps) {
                        $ips = array_filter(array_map('trim', explode(' ', trim($allIps))));
                        foreach ($ips as $ip) {
                            if ($ip && $ip !== '127.0.0.1' && $ip !== '::1' && filter_var($ip, FILTER_VALIDATE_IP)) {
                                $host = $ip;
                                break;
                            }
                        }
                    }
                }
            }
        }
        
        // Разделяем хост и порт, если порт указан
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host, 2);
        } else {
            // Используем SERVER_PORT, если порт не указан в HTTP_HOST
            $port = $_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? '443' : '80');
        }
        
        // Если хост все еще 0.0.0.0, используем SERVER_ADDR или localhost
        if ($host === '0.0.0.0' || empty($host)) {
            $host = $_SERVER['SERVER_ADDR'] ?? 'localhost';
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
        $collectInterval = max(10, min((int)setting_get('collect_interval', '60'), 300));
        $config .= "COLLECT_INTERVAL=" . $collectInterval . "\n";
        $config .= "HEARTBEAT_INTERVAL=15\n";
        $upnpOn = setting_get('upnp_enabled', 'true') === 'true' ? 'true' : 'false';
        $config .= "UPNP_ENABLED=" . $upnpOn . "\n";
        $config .= "UPNP_INTERVAL_CYCLES=" . (int)setting_get('upnp_interval_cycles', '2') . "\n";
        $config .= "UPNP_MX=" . (int)setting_get('upnp_mx', '3') . "\n";
        $config .= "UPNP_TIMEOUT=" . (int)setting_get('upnp_timeout', '8') . "\n";
        $config .= "UPNP_GENA_PORT=" . (int)setting_get('upnp_gena_port', '0') . "\n";
    $config .= "TLS_VERIFY=false\n";
    $config .= "TLS_CERT_PATH=\"\"\n\n";
    $config .= "# Установка зависимостей:\n";
        $config .= "# pip install -r agent/requirements.txt\n\n";
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
        
        // ОПАСНЫЕ КОМАНДЫ ОТКЛЮЧЕНЫ ПО УМОЛЧАНИЮ
        $allowDangerous = getenv('ALLOW_DANGEROUS_COMMANDS') === 'true' || 
                          (isset($data['allow_dangerous']) && $data['allow_dangerous'] === true);
        
        if (in_array($nodeAction, ['reboot', 'shutdown'])) {
            if (!$allowDangerous) {
                http_response_code(403);
                echo json_encode([
                    'error' => 'Dangerous commands (reboot/shutdown) are disabled for safety',
                    'message' => 'Set ALLOW_DANGEROUS_COMMANDS=true environment variable to enable'
                ]);
                return;
            }
        }
        
        if (in_array($nodeAction, ['reboot', 'shutdown', 'sync', 'check-agent-update', 'update-agent'], true)) {
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
    
    // Heartbeat от агента: POST /api/nodes.php?action=heartbeat
    if ($action === 'heartbeat') {
        global $nodeInfo;
        if (!$nodeInfo) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        nodes_ensure_agent_columns($pdo);
        
        $nodeId = $nodeInfo['id'];
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = [];
        }

        $version = substr((string)($data['agent_version'] ?? ''), 0, 32);
        $commit = substr((string)($data['agent_commit'] ?? ''), 0, 64);
        $branch = substr((string)($data['agent_branch'] ?? ''), 0, 64);
        $remote = substr((string)($data['agent_remote_commit'] ?? ''), 0, 64);

        if ($version !== '' || $commit !== '') {
            $updateStmt = $pdo->prepare(
                "UPDATE nodes SET status = 'online', last_seen = NOW(),
                    agent_version = COALESCE(NULLIF(?, ''), agent_version),
                    agent_commit = COALESCE(NULLIF(?, ''), agent_commit),
                    agent_branch = COALESCE(NULLIF(?, ''), agent_branch),
                    agent_remote_commit = COALESCE(NULLIF(?, ''), agent_remote_commit),
                    agent_updated_at = NOW()
                 WHERE id = ?"
            );
            $updateStmt->execute([$version, $commit, $branch, $remote, $nodeId]);
        } else {
            $updateStmt = $pdo->prepare("UPDATE nodes SET status = 'online', last_seen = NOW() WHERE id = ?");
            $updateStmt->execute([$nodeId]);
        }
        
        echo json_encode([
            'status' => 'ok',
            'success' => true,
            'message' => 'Heartbeat received',
            'node_id' => $nodeId,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
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
        $command = $data['command'] ?? '';
        
        // Получаем текущее состояние команды для логирования
        $currentStmt = $pdo->prepare("SELECT last_command, command_status FROM nodes WHERE id = ?");
        $currentStmt->execute([$targetNodeId]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("=== COMMAND STATUS UPDATE ===");
        error_log("Node ID: {$targetNodeId}");
        error_log("Command from request: {$command}");
        error_log("Status from request: {$commandStatus}");
        error_log("Current command in DB: " . ($current['last_command'] ?? 'NULL'));
        error_log("Current status in DB: " . ($current['command_status'] ?? 'NULL'));
        
        // Если команда завершена (completed или failed), очищаем команду чтобы избежать повторного выполнения
        if ($commandStatus === 'completed' || $commandStatus === 'failed') {
            $updateStmt = $pdo->prepare("UPDATE nodes SET command_status = ?, last_command = NULL, command_timestamp = NULL WHERE id = ?");
            $updateStmt->execute([$commandStatus, $targetNodeId]);
            error_log("Command cleared: status={$commandStatus}, last_command=NULL");
        } else {
            // Для pending просто обновляем статус
            $updateStmt = $pdo->prepare("UPDATE nodes SET command_status = ? WHERE id = ?");
            $updateStmt->execute([$commandStatus, $targetNodeId]);
            error_log("Command status updated: status={$commandStatus}");
        }
        
        // Проверяем результат
        $verifyStmt = $pdo->prepare("SELECT last_command, command_status FROM nodes WHERE id = ?");
        $verifyStmt->execute([$targetNodeId]);
        $verified = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        error_log("After update - command: " . ($verified['last_command'] ?? 'NULL') . ", status: " . ($verified['command_status'] ?? 'NULL'));
        
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
    
    $providerName = trim((string)($data['provider_name'] ?? ''));
    $providerName = $providerName !== '' ? $providerName : null;
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
    
    // Проверяем наличие колонок в таблице
    $checkColumns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                                 WHERE TABLE_SCHEMA = DATABASE() 
                                 AND TABLE_NAME = 'nodes'");
    $existingColumns = [];
    while ($row = $checkColumns->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $row['COLUMN_NAME'];
    }
    $hasCountry = in_array('country', $existingColumns);
    $hasProviderName = in_array('provider_name', $existingColumns);
    $hasBillingFields = in_array('billing_amount', $existingColumns);
    
    // Строим запрос динамически в зависимости от наличия колонок
    $columns = ['name', 'host', 'port'];
    $values = [$name, $host, $port];
    
    if ($hasCountry) {
        $columns[] = 'country';
        $values[] = $country;
    }
    
    $columns[] = 'secret_key';
    $columns[] = 'node_token';
    $values[] = $secretKey;
    $values[] = $nodeToken;
    
    if ($hasProviderName) {
        $columns[] = 'provider_name';
        $columns[] = 'provider_url';
        $values[] = $providerName;
        $values[] = $providerUrl;
    }
    
    if ($hasBillingFields) {
        $columns[] = 'billing_amount';
        $columns[] = 'billing_period';
        $columns[] = 'last_payment_date';
        $columns[] = 'next_payment_date';
        $values[] = $billingAmount;
        $values[] = $billingPeriod;
        $values[] = $lastPaymentDate;
        $values[] = $nextPaymentDate;
    }
    
    $columns[] = 'status';
    $values[] = 'offline';
    
    $placeholders = str_repeat('?,', count($values) - 1) . '?';
    $sql = "INSERT INTO nodes (" . implode(', ', $columns) . ") VALUES ($placeholders)";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        
        $id = $pdo->lastInsertId();
        
        http_response_code(201);
        echo json_encode(['id' => $id, 'message' => 'Node created', 'node_token' => $nodeToken]);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Error creating node: " . $e->getMessage());
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
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
    $country = $data['country'] ?? ($currentNode['country'] ?? null);
    $providerName = array_key_exists('provider_name', $data)
        ? (trim((string)$data['provider_name']) !== '' ? trim((string)$data['provider_name']) : null)
        : ($currentNode['provider_name'] ?? null);
    $providerUrl = array_key_exists('provider_url', $data)
        ? ($data['provider_url'] ?: null)
        : ($currentNode['provider_url'] ?? null);
    if ($providerName === null) {
        $providerUrl = null;
    }
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
    
    // Проверяем наличие колонок
    $checkColumns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                                 WHERE TABLE_SCHEMA = DATABASE() 
                                 AND TABLE_NAME = 'nodes'");
    $existingColumns = [];
    while ($row = $checkColumns->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $row['COLUMN_NAME'];
    }
    $hasCountry = in_array('country', $existingColumns);
    $hasProviderName = in_array('provider_name', $existingColumns);
    $hasBillingFields = in_array('billing_amount', $existingColumns);
    
    // Строим UPDATE запрос динамически
    $updates = ['name = ?', 'host = ?', 'port = ?'];
    $params = [$name, $host, $port];
    
    if ($hasCountry) {
        $updates[] = 'country = ?';
        $params[] = $country;
    }
    
    if ($hasProviderName) {
        $updates[] = 'provider_name = ?';
        $updates[] = 'provider_url = ?';
        $params[] = $providerName;
        $params[] = $providerUrl;
    }
    
    if ($hasBillingFields) {
        $updates[] = 'billing_amount = ?';
        $updates[] = 'billing_period = ?';
        $updates[] = 'last_payment_date = ?';
        $updates[] = 'next_payment_date = ?';
        $params[] = $billingAmount;
        $params[] = $billingPeriod;
        $params[] = $lastPaymentDate;
        $params[] = $nextPaymentDate;
    }
    
    $params[] = $id;
    $sql = "UPDATE nodes SET " . implode(', ', $updates) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
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
    
    try {
        // Проверяем существование ноды
        $checkStmt = $pdo->prepare("SELECT id FROM nodes WHERE id = ?");
        $checkStmt->execute([$id]);
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Node not found']);
            return;
        }
        
        // Удаляем ноду
        $stmt = $pdo->prepare("DELETE FROM nodes WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Node not found or already deleted']);
            return;
        }
        
        http_response_code(200);
        echo json_encode(['message' => 'Node deleted', 'success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Error deleting node: " . $e->getMessage());
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
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
    
    // Делаем ping для проверки доступности
    $ping = pingNode($node['host']);
    
    // Обновляем только статус на основе ping, НЕ трогаем last_seen (его обновляет только агент через heartbeat)
    // Если ping успешен, но статус был offline - обновляем статус
    // Если ping неуспешен и last_seen старый - помечаем offline
    $newStatus = $node['status'];
    // Статус по last_seen агента; ICMP ping только как доп. сигнал (не сбрасывает online при свежем heartbeat)
    $heartbeatTimeout = node_heartbeat_timeout_sec();
    
    if ($node['last_seen']) {
        $secondsSinceLastSeen = time() - (int)strtotime($node['last_seen']);
        if ($secondsSinceLastSeen <= $heartbeatTimeout) {
            $newStatus = 'online';
        } elseif ($ping === null) {
            $newStatus = 'offline';
        }
        // ping OK при просроченном last_seen — не трогаем (агент мог просто задержаться)
    } elseif ($ping === null) {
        $newStatus = 'offline';
    }
    
    // Обновляем только статус, НЕ last_seen
    $updateStmt = $pdo->prepare("UPDATE nodes SET status = ? WHERE id = ?");
    $updateStmt->execute([$newStatus, $id]);
    
    // Пересчитываем uptime с обновленным статусом
    $node['status'] = $newStatus;
    $uptime = calculateUptime($node);
    
    echo json_encode(['success' => true, 'node' => ['id' => $id, 'status' => $newStatus, 'ping' => $ping, 'uptime' => $uptime]]);
}

function refreshAllNodes($pdo) {
    $stmt = $pdo->query("SELECT * FROM nodes");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updated = 0;
    $heartbeatTimeout = node_heartbeat_timeout_sec();
    
    foreach ($nodes as $node) {
        // Делаем ping для проверки доступности
        $ping = pingNode($node['host']);
        
        // Статус в первую очередь по last_seen агента, не по ICMP
        $newStatus = $node['status'];
        
        if ($node['last_seen']) {
            $secondsSinceLastSeen = time() - (int)strtotime($node['last_seen']);
            if ($secondsSinceLastSeen <= $heartbeatTimeout) {
                $newStatus = 'online';
            } elseif ($ping === null) {
                $newStatus = 'offline';
            }
        } elseif ($ping === null) {
            $newStatus = 'offline';
        }
        
        // Обновляем только статус, НЕ last_seen (его обновляет только агент)
        if ($newStatus !== $node['status']) {
            $updateStmt = $pdo->prepare("UPDATE nodes SET status = ? WHERE id = ?");
            $updateStmt->execute([$newStatus, $node['id']]);
            $updated++;
        }
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
    
    // Дополнительная проверка для опасных команд
    if (in_array($action, ['reboot', 'shutdown'])) {
        $allowDangerous = getenv('ALLOW_DANGEROUS_COMMANDS') === 'true';
        if (!$allowDangerous) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Dangerous commands are disabled',
                'message' => 'Reboot and shutdown commands are disabled for safety. Set ALLOW_DANGEROUS_COMMANDS=true to enable.'
            ]);
            return;
        }
        error_log("[executeNodeAction] WARNING: Dangerous command '{$action}' executed for node {$nodeId}");
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
    
    $portsStmt = $pdo->prepare("SELECT port, type, status FROM ports WHERE node_id = ?");
    $portsStmt->execute([$nodeId]);
    $ports = $portsStmt->fetchAll(PDO::FETCH_ASSOC);

    $interfaces = [];
    try {
        $ifaceStmt = $pdo->prepare("SELECT name, ip, ipv6, netmask, ipv6_netmask, gateway, gateway6, status, speed, rx_bytes, tx_bytes FROM network_interfaces WHERE node_id = ? ORDER BY name");
        $ifaceStmt->execute([$nodeId]);
        foreach ($ifaceStmt->fetchAll(PDO::FETCH_ASSOC) as $iface) {
            $name = (string)($iface['name'] ?? '');
            if (preg_match('/^(lo(\d+)?$|docker|veth|br-|virbr|cni|flannel|calico|kube)/i', $name)) {
                continue;
            }
            $interfaces[] = [
                'name' => $name,
                'ip' => $iface['ip'] ?? '',
                'ipv6' => $iface['ipv6'] ?? '',
                'netmask' => $iface['netmask'] ?? '',
                'gateway' => $iface['gateway'] ?? '',
                'gateway6' => $iface['gateway6'] ?? '',
                'status' => $iface['status'] ?? 'unknown',
                'speed' => $iface['speed'] ?? 0,
                'rx_bytes' => $iface['rx_bytes'] ?? 0,
                'tx_bytes' => $iface['tx_bytes'] ?? 0,
            ];
        }
    } catch (Exception $e) {
        $interfaces = [];
    }

    $networkData = [
        'interfaces' => $interfaces,
        'ports' => $ports,
        'connections' => []
    ];
    
    echo json_encode(['network' => $networkData]);
    exit;
}
