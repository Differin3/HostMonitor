<?php
declare(strict_types=1); // строгие типы

header('Content-Type: application/json; charset=utf-8'); // JSON заголовок

require_once __DIR__ . '/../includes/database.php'; // БД

try {
    $pdo = getDbConnection(); // пробуем подключиться к БД
    $stmt = $pdo->query('SELECT 1'); // простой запрос
    $stmt->fetch();
    echo json_encode(['status' => 'ok']); // всё хорошо
} catch (Throwable $e) {
    http_response_code(500); // ошибка
    echo json_encode(['status' => 'error', 'message' => 'db_unavailable']); // код ошибки
}


