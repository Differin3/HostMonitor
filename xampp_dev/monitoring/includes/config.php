<?php
// Конфигурация для XAMPP
// Можно переопределить через переменную окружения или изменить здесь
$api_url = getenv('MONITORING_API_URL') ?: 'http://127.0.0.1:8000/api';

return [
    'api_url' => $api_url,
    'timeout' => 15,
    // base_path используется только для внутренних ссылок
    // frontend всегда доступен как /frontend от корня сайта
    'base_path' => '', // Автоопределение из SCRIPT_NAME
];

