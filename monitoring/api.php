<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
session_write_close();

$config = require __DIR__ . '/includes/config.php';
$apiBase = rtrim($config['api_url'] ?? '', '/');
$endpoint = trim($_GET['endpoint'] ?? '', '/');

if ($apiBase === '' || $endpoint === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Endpoint required']);
    exit;
}

$ALLOWED_ENDPOINTS = [
    'nodes.php', 'metrics.php', 'logs.php', 'summary.php', 'processes.php',
    'settings.php', 'health.php', 'ports.php', 'smart.php', 'containers.php',
    'databases.php', 'db_ha.php', 'providers.php', 'payments.php',
    'panel_update.php', 'updates.php', 'nodes_export.php', 'upnp.php',
];
$epBase = basename($endpoint);
if (!in_array($epBase, $ALLOWED_ENDPOINTS, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Endpoint not allowed']);
    exit;
}

$query = $_GET;
unset($query['endpoint']);
$queryString = http_build_query($query);
$url = $apiBase . '/' . $endpoint . ($queryString ? '?' . $queryString : '');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_TIMEOUT => (int)($config['timeout'] ?? 15),
    CURLOPT_FOLLOWLOCATION => false,
]);

$hasBody = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
if ($hasBody) {
    $body = file_get_contents('php://input');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

$forwardHeaders = [];
$incomingHeaders = function_exists('getallheaders') ? getallheaders() : [];
foreach ($incomingHeaders as $name => $value) {
    $normalized = strtolower($name);
    if (in_array($normalized, ['content-type', 'accept'], true)) {
        $forwardHeaders[] = $name . ': ' . $value;
    }
}
if (!empty($forwardHeaders)) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
}

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 502;
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => $error ?: 'API proxy error']);
    exit;
}

http_response_code($httpCode);
echo $response;

