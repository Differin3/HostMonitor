<?php
// Аутентификация: агент (Ed25519-подпись или legacy Bearer) или админ (сессия+CSRF).
// Секреты нод (node_token, secret_key) больше не раздаются: админ видит их в
// конфиге при создании/ротации, агент — никогда и только свою ноду.
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/commands.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = getDbConnection();

// require_api_auth вернёт ['user' => ?int, 'node' => ['id','name']|null,
// 'scopes' => ?array, 'auth' => 'session'|'ed25519'|'legacy'].
$auth = require_api_auth($pdo);
$isAdmin = ($auth['auth'] === 'session');
$agentNode = $auth['node'] ?? null; // ['id','name'] для агента, null для админа

// Миграция колонок — не на каждый POST агента (маркер в helpers), как и раньше.
if (!($method === 'POST' && !$isAdmin)) {
    nodes_ensure_agent_columns($pdo);
}

// Проверка, что запрос пришёл от админской сессии (не от агента).
function nodes_require_admin() {
    global $isAdmin;
    if (!$isAdmin) {
        json_error('Forbidden: admin session required', 403);
    }
}

// Секреты никогда не уходят в ответ списков/ноды.
function nodes_strip_secrets(array &$node) {
    unset($node['node_token'], $node['secret_key']);
}

// Отпечаток Ed25519-ключа для UI (не секрет).
function nodes_fingerprint_in(array &$node) {
    if (!empty($node['public_key'])) {
        $node['fingerprint'] = agent_key_fingerprint((string)$node['public_key']);
    }
}

// Управление входом: админ — по ?id (число или имя), агент — только своя нода.
function nodes_resolve_target($pdo, $idParam) {
    global $isAdmin, $agentNode;
    if ($agentNode) {
        return (int)$agentNode['id'];
    }
    if (!$idParam || !$isAdmin) {
        return null;
    }
    if (ctype_digit((string)$idParam)) {
        return (int)$idParam;
    }
    $stmt = $pdo->prepare('SELECT id FROM nodes WHERE name = ?');
    $stmt->execute([(string)$idParam]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : null;
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
    error_log('nodes.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
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

        // Агент видит только свою ноду; админ может указать ?id (число/имя).
        $targetId = nodes_resolve_target($pdo, $_GET['id'] ?? null);

        if ($targetId) {
            // pending → running. Reclaim:
            // - offline (last_seen > 3м)
            // - check-agent-* / check-updates зависли > 90с
            // - update-agent > 10м
            // Не трогаем last_seen/status здесь: presence только от heartbeat/metrics.
            // Иначе get-command «оживляет» offline-ноду без реального heartbeat.
            $claim = $pdo->prepare(
                "UPDATE nodes
                 SET command_status = 'running',
                     command_timestamp = IF(command_status = 'running', NOW(), command_timestamp)
                 WHERE id = ?
                   AND last_command IS NOT NULL
                   AND last_command <> ''
                   AND (
                       command_status = 'pending'
                       OR (
                           command_status = 'running'
                           AND (
                               last_seen IS NULL
                               OR last_seen < DATE_SUB(NOW(), INTERVAL 3 MINUTE)
                               OR (
                                   command_timestamp IS NOT NULL
                                   AND command_timestamp < DATE_SUB(NOW(), INTERVAL 90 SECOND)
                                   AND last_command IN (
                                       'check-agent-update', 'check-agent-updates', 'check-updates'
                                   )
                               )
                               OR (
                                   command_timestamp IS NOT NULL
                                   AND command_timestamp < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                                   AND last_command IN ('update-agent', 'upgrade-agent')
                               )
                           )
                       )
                   )"
            );
            $claim->execute([$targetId]);

            if ($claim->rowCount() > 0) {
                $stmt = $pdo->prepare("SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?");
                $stmt->execute([$targetId]);
                $node = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($node && $node['last_command']) {
                    // В ответе всегда pending: старые агенты принимают только pending,
                    // в БД уже running (чтобы не выдавать команду повторно).
                    $result = [
                        'status' => 'ok',
                        'command' => $node['last_command'],
                        'command_status' => 'pending',
                        'command_timestamp' => $node['command_timestamp'],
                    ];
                }
            }
        }

        echo json_encode($result);
        return;
    }
    
    if ($action === 'generate-key') {
        // Только админ: префилл legacy-токена в форме создания ноды.
        nodes_require_admin();
        echo json_encode(['secret_key' => generateSecretKey()]);
        return;
    }

    if ($action === 'generate-config') {
        // Только админ. Секретов в responses больше не отдаём: приватный
        // ключ существует лишь на момент создания/ротации (см. create).
        nodes_require_admin();
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

        $config = buildAgentConfig($node);
        echo json_encode(['config' => $config]);
        return;
    }

    if ($action === 'rotate-key') {
        // Ed25519-ротация: новый seed отдаётся один раз, старый невосстановим.
        nodes_require_admin();
        $nodeId = $_GET['id'] ?? null;
        if (!$nodeId) {
            http_response_code(400);
            echo json_encode(['error' => 'Node ID required']);
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
        $stmt->execute([$nodeId]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$node) {
            http_response_code(404);
            echo json_encode(['error' => 'Node not found']);
            return;
        }

        $kp = agent_generate_keypair();
        if ($kp === null) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to generate Ed25519 keypair']);
            return;
        }

        // Проверка, что ключ парсится нашим же разбором — защита от будущего
        // рассинхрона формата хранимого публичного ключа.
        if (agent_raw_public_key($kp['public_pem']) === null) {
            http_response_code(500);
            echo json_encode(['error' => 'Generated keypair failed validation']);
            return;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE nodes SET public_key = ?, key_rotated_at = ?, node_token = NULL WHERE id = ?");
            $stmt->execute([$kp['public_pem'], date('Y-m-d H:i:s'), $nodeId]);
            $stmt2 = $pdo->prepare(
                'INSERT INTO key_rotation_log (node_id, actor_type, actor_id, reason, old_fingerprint, new_fingerprint)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt2->execute([$nodeId, 'admin', $auth['user'] ?? null, 'admin-rotate', null, $kp['fingerprint']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Rotation failed']);
            return;
        }

        $node['public_key'] = $kp['public_pem'];
        $config = buildAgentConfig($node, $kp['seed_b64']);
        echo json_encode([
            'message'     => 'Key rotated',
            'node_id'     => (int)$nodeId,
            'fingerprint' => $kp['fingerprint'],
            'scopes'      => agent_parse_scopes($node['scopes'] ?? null),
            'config'      => $config,
        ]);
        return;
    }
    
    if ($id) {
        // Агент — только свою ноду и без секретов.
        global $agentNode;
        $targetId = nodes_resolve_target($pdo, $id);
        if ($targetId === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Node not found']);
            return;
        }

        $stmt = $pdo->prepare("SELECT * FROM nodes WHERE id = ?");
        $stmt->execute([$targetId]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$node) {
            http_response_code(404);
            echo json_encode(['error' => 'Node not found']);
            return;
        }

        $node['status'] = node_presence_from_last_seen(
            isset($node['last_seen']) ? (string)$node['last_seen'] : null
        );
        nodes_fingerprint_in($node);
        nodes_strip_secrets($node);
        $id = $targetId;
        
        // Вычисляем uptime и ping динамически
        $node['uptime'] = calculateUptime($node);
        $node['ping'] = pingNode($node['host']);
        
        // Получаем последние метрики из таблицы metrics
        $metricsStmt = $pdo->prepare("SELECT cpu_percent, memory_percent, disk_percent, network_in, network_out,
                                            memory_used, memory_total, disk_used, disk_total, swap_percent, load_avg, cpu_count,
                                            network_in_total, network_out_total
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
            $node['network_in_total'] = (float)($metrics['network_in_total'] ?? 0);
            $node['network_out_total'] = (float)($metrics['network_out_total'] ?? 0);
            $node['metrics_timestamp'] = $metrics['timestamp'] ?? null;
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
            $node['network_in_total'] = 0;
            $node['network_out_total'] = 0;
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

        // Админ видит всё, агент — только свою ноду.
        global $agentNode;
        if ($agentNode) {
            $filtered = [];
            foreach ($nodes as $n) {
                if ((int)$n['id'] === (int)$agentNode['id']) {
                    $filtered[] = $n;
                }
            }
            $nodes = $filtered;
        }

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
                       m.cpu_count,
                       m.network_in_total,
                       m.network_out_total
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
        
        // Presence по last_seen агента; синхронизируем БД (иначе дашборд/топология видят залипший online)
        $heartbeat_timeout = node_heartbeat_timeout_sec();
        nodes_refresh_presence_status($pdo, $heartbeat_timeout);
        foreach ($nodes as &$node) {
            $node['status'] = node_presence_from_last_seen(
                isset($node['last_seen']) ? (string)$node['last_seen'] : null,
                $heartbeat_timeout
            );
            
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
                $node['network_in_total'] = (float)($m['network_in_total'] ?? 0);
                $node['network_out_total'] = (float)($m['network_out_total'] ?? 0);
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
                $node['network_in_total'] = 0.0;
                $node['network_out_total'] = 0.0;
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

        foreach ($nodes as &$node) {
            nodes_strip_secrets($node);
            nodes_fingerprint_in($node);
        }
        unset($node);
        
        echo json_encode(['nodes' => $nodes]);
    }
}

function calculateUptime($node) {
    // Реальный uptime ОС: now − boot_time (от агента).
    // Раньше ошибочно считали от created_at/first_seen (= возраст записи в панели).
    if (empty($node['last_seen']) || ($node['status'] ?? '') !== 'online') {
        return 0;
    }

    $bootRaw = $node['boot_time'] ?? null;
    if ($bootRaw === null || $bootRaw === '' || $bootRaw === 0 || $bootRaw === '0') {
        return 0;
    }

    if (is_numeric($bootRaw)) {
        $bootTs = (int)$bootRaw;
    } else {
        $bootTs = (int)strtotime((string)$bootRaw);
    }
    if ($bootTs <= 0) {
        return 0;
    }

    return max(0, time() - $bootTs);
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

function buildAgentConfig($node, $oneTimeSeed = null) {
    if (getenv('MASTER_URL')) {
        $masterUrl = getenv('MASTER_URL');
        $parsedUrl = parse_url($masterUrl);
        $masterHost = $parsedUrl['host'] ?? 'localhost';
        $masterPort = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'https' ? '443' : '80');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        if ($realIp) {
            $realIp = trim(explode(',', $realIp)[0]);
        }
        $httpHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? null;
        if ($httpHost && $httpHost !== '0.0.0.0' && $httpHost !== 'localhost' && strpos($httpHost, '0.0.0.0:') !== 0) {
            $host = $httpHost;
        } else {
            $externalIp = @shell_exec("hostname -I 2>/dev/null | awk '{print \$1}' || curl -s ifconfig.me 2>/dev/null || curl -s ifconfig.co 2>/dev/null || echo ''");
            $externalIp = trim($externalIp);
            if ($externalIp && filter_var($externalIp, FILTER_VALIDATE_IP)) {
                $host = $externalIp;
            } else {
                $host = $_SERVER['SERVER_ADDR'] ?? 'localhost';
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
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host, 2);
        } else {
            $port = $_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? '443' : '80');
        }
        if ($host === '0.0.0.0' || empty($host)) {
            $host = $_SERVER['SERVER_ADDR'] ?? 'localhost';
        }
        $masterHost = $host;
        $masterPort = $port;
        $masterUrl = $scheme . '://' . $host;
        if (($scheme === 'http' && $port != '80') || ($scheme === 'https' && $port != '443')) {
            $masterUrl .= ':' . $port;
        }
    }

    $config = "# Конфигурация агента HostMonitor\n";
    $config .= "# Сгенерировано автоматически\n";
    $config .= "# Дата: " . date('Y-m-d H:i:s') . "\n\n";
    $config .= "MASTER_URL=\"" . $masterUrl . "\"\n";
    $config .= "MASTER_HOST=\"" . $masterHost . "\"\n";
    $config .= "MASTER_PORT=\"" . $masterPort . "\"\n";
    $config .= "NODE_ID=\"" . (int)($node['id'] ?? 0) . "\"\n";
    $config .= "NODE_NAME=\"" . ($node['name'] ?? '') . "\"\n";
    $config .= "NODE_HOST=\"" . ($node['host'] ?? '') . "\"\n";
    $config .= "NODE_PORT=\"" . ($node['port'] ?? '2222') . "\"\n";
    if (!empty($node['public_key'])) {
        $config .= "NODE_PUBLIC_KEY_B64=\"" . base64_encode(hex2bin(str_repeat('00', 0))) . "\"\n"; // placeholder
    }
    if ($oneTimeSeed !== null && $oneTimeSeed !== '') {
        $config .= "NODE_SECRET_B64=\"" . $oneTimeSeed . "\"\n"; // одноразовый seed для первого старта
    }
    $collectInterval = max(10, min((int)setting_get('collect_interval', '60'), 300));
    $config .= "COLLECT_INTERVAL=" . $collectInterval . "\n";
    $config .= "HEARTBEAT_INTERVAL=15\n";
    $upnpOn = setting_get('upnp_enabled', 'true') === 'true' ? 'true' : 'false';
    $config .= "UPNP_ENABLED=" . $upnpOn . "\n";
    $config .= "UPNP_INTERVAL_CYCLES=" . (int)setting_get('upnp_interval_cycles', '2') . "\n";
    $config .= "UPNP_MX=" . (int)setting_get('upnp_mx', '3') . "\n";
    $config .= "UPNP_TIMEOUT=" . (int)setting_get('upnp_timeout', '8') . "\n";
    $config .= "UPNP_GENA_PORT=" . (int)setting_get('upnp_gena_port', '0') . "\n";
    $config .= "SNMP_ENABLED=" . (setting_get('snmp_enabled', 'true') === 'true' ? 'true' : 'false') . "\n";
    $config .= "SNMP_COMMUNITY=\"" . str_replace('"', '', (string)setting_get('snmp_community', 'public')) . "\"\n";
    $config .= "SNMP_TIMEOUT=" . (string)setting_get('snmp_timeout', '0.8') . "\n";
    $snmpTargets = trim((string)setting_get('snmp_targets', ''));
    if ($snmpTargets !== '') {
        $config .= "SNMP_TARGETS=\"" . str_replace('"', '', $snmpTargets) . "\"\n";
    }
    $config .= "LLDP_PASSIVE=" . (setting_get('lldp_passive', 'true') === 'true' ? 'true' : 'false') . "\n";
    $lldpIface = trim((string)setting_get('lldp_listen_interface', ''));
    if ($lldpIface !== '') {
        $config .= "LLDP_LISTEN_INTERFACE=\"" . str_replace('"', '', $lldpIface) . "\"\n";
    }
    $config .= "LLDP_ACTIVE_POLL_KNOWN=" . (setting_get('lldp_active_poll_known', 'true') === 'true' ? 'true' : 'false') . "\n";
    $config .= "TLS_VERIFY=false\n";
    $config .= "TLS_CERT_PATH=\"\"\n\n";
    $config .= "# Установка зависимостей:\n";
    $config .= "# pip install -r agent/requirements.txt\n";
    $config .= "# pip install scapy          # LLDP passive (root)\n\n";
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

        // Не вызываем nodes_ensure_agent_columns на heartbeat — маркер/миграцию делает UI/старт
        
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
                "UPDATE nodes SET status = 'online', last_seen = ?,
                    agent_version = COALESCE(NULLIF(?, ''), agent_version),
                    agent_commit = COALESCE(NULLIF(?, ''), agent_commit),
                    agent_branch = COALESCE(NULLIF(?, ''), agent_branch),
                    agent_remote_commit = COALESCE(NULLIF(?, ''), agent_remote_commit),
                    agent_updated_at = NOW()
                 WHERE id = ?"
            );
            $updateStmt->execute([date('Y-m-d H:i:s'), $version, $commit, $branch, $remote, $nodeId]);
        } else {
            $updateStmt = $pdo->prepare("UPDATE nodes SET status = 'online', last_seen = ? WHERE id = ?");
            $updateStmt->execute([date('Y-m-d H:i:s'), $nodeId]);
        }

        if (array_key_exists('boot_time', $data)) {
            nodes_store_boot_time($pdo, (int)$nodeId, $data['boot_time']);
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
        $command = trim((string)($data['command'] ?? ''));
        // Причина от агента: без неё UI показывал голое «ошибка», а
        // объяснение (например «update-agent заблокирован») оставалось
        // только в журнале ноды.
        $result = trim((string)($data['result'] ?? ($data['error'] ?? '')));
        if (mb_strlen($result) > 4000) {
            $result = mb_substr($result, 0, 4000);
        }
        
        $currentStmt = $pdo->prepare("SELECT last_command, command_status FROM nodes WHERE id = ?");
        $currentStmt->execute([$targetNodeId]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
        $currentCmd = trim((string)($current['last_command'] ?? ''));
        
        error_log("=== COMMAND STATUS UPDATE ===");
        error_log("Node ID: {$targetNodeId}");
        error_log("Command from request: {$command}");
        error_log("Status from request: {$commandStatus}");
        error_log("Current command in DB: " . ($currentCmd !== '' ? $currentCmd : 'NULL'));
        error_log("Current status in DB: " . ($current['command_status'] ?? 'NULL'));

        // Не затираем чужую/новую команду: очищаем слот только если совпадает
        $sameCommand = ($command === '' || $currentCmd === '' || $command === $currentCmd);
        
        if ($commandStatus === 'failed' && $sameCommand) {
            // Оставляем last_command — UI показывает «ошибка» + command_result
            // Presence не трогаем: статус ноды только от heartbeat/metrics
            if ($result !== '') {
                $updateStmt = $pdo->prepare(
                    "UPDATE nodes SET command_status = 'failed', command_result = ? WHERE id = ?"
                );
                $updateStmt->execute([$result, $targetNodeId]);
            } else {
                $updateStmt = $pdo->prepare(
                    "UPDATE nodes SET command_status = 'failed' WHERE id = ?"
                );
                $updateStmt->execute([$targetNodeId]);
            }
            error_log("Command marked failed (kept last_command={$currentCmd})");
        } elseif ($commandStatus === 'completed' && $sameCommand) {
            $updateStmt = $pdo->prepare(
                "UPDATE nodes SET command_status = ?, last_command = NULL, command_timestamp = NULL, command_result = NULL WHERE id = ?"
            );
            $updateStmt->execute([$commandStatus, $targetNodeId]);
            error_log("Command cleared: status={$commandStatus}, last_command=NULL");
        } elseif ($commandStatus === 'completed' || $commandStatus === 'failed') {
            // Старый отчёт после постановки новой команды — игнор (без touch last_seen)
            error_log("Ignored stale command-status for '{$command}' (current='{$currentCmd}')");
            echo json_encode([
                'status' => 'ok',
                'success' => true,
                'message' => 'Stale command status ignored',
                'command_status' => $current['command_status'] ?? null,
                'ignored' => true,
            ]);
            return;
        } else {
            $updateStmt = $pdo->prepare(
                "UPDATE nodes SET command_status = ? WHERE id = ?"
            );
            $updateStmt->execute([$commandStatus, $targetNodeId]);
            error_log("Command status updated: status={$commandStatus}");
        }
        
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
        echo json_encode(['error' => 'Internal server error']);
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
        echo json_encode(['error' => 'Internal server error']);
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
    
    // Статус только по last_seen агента (ICMP ping — справочно, не «оживляет» ноду)
    $newStatus = node_presence_from_last_seen(
        isset($node['last_seen']) ? (string)$node['last_seen'] : null
    );
    
    // Обновляем только статус, НЕ last_seen
    $updateStmt = $pdo->prepare("UPDATE nodes SET status = ? WHERE id = ?");
    $updateStmt->execute([$newStatus, $id]);
    
    // Пересчитываем uptime с обновленным статусом
    $node['status'] = $newStatus;
    $uptime = calculateUptime($node);
    
    echo json_encode(['success' => true, 'node' => ['id' => $id, 'status' => $newStatus, 'ping' => $ping, 'uptime' => $uptime]]);
}

function refreshAllNodes($pdo) {
    $updated = nodes_refresh_presence_status($pdo);
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
    // Не затираем свежий update-agent проверкой/другой короткой командой
    $cur = $pdo->prepare('SELECT last_command, command_status, command_timestamp FROM nodes WHERE id = ?');
    $cur->execute([$nodeId]);
    $curRow = $cur->fetch(PDO::FETCH_ASSOC) ?: [];
    $pending = trim((string)($curRow['last_command'] ?? ''));
    $pStatus = strtolower(trim((string)($curRow['command_status'] ?? '')));
    $pBusy = in_array($pStatus, ['pending', 'running', 'installing', 'in_progress'], true);
    $pAge = 999999;
    if (!empty($curRow['command_timestamp'])) {
        $ts = strtotime((string)$curRow['command_timestamp']);
        if ($ts) {
            $pAge = max(0, time() - $ts);
        }
    }
    if (
        $pBusy
        && in_array($pending, ['update-agent', 'upgrade-agent'], true)
        && $pAge < 600
        && !in_array($action, ['update-agent', 'upgrade-agent'], true)
    ) {
        echo json_encode([
            'success' => false,
            'error' => "Нода уже обновляет агент (команда «{$pending}»). Дождитесь завершения.",
            'node_id' => $nodeId,
            'action' => $action,
        ]);
        return;
    }

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
