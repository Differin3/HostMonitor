<?php
declare(strict_types=1);

//
// Конфигурация подключения к БД и настройки панели.
//
// Файл остаётся единственной точкой входа: модули лежат рядом в
// includes/db/ и подключаются отсюда. Вызывающие страницы продолжают
// делать require_once '.../db_config.php' и видят весь прежний API.
//
// Порядок подключения не влияет на доступность функций (они
// разрешаются в момент вызова), но идёт от инфраструктуры к потребителям.

require_once __DIR__ . '/db/polyfill.php';
require_once __DIR__ . '/db/config.php';
require_once __DIR__ . '/db/ssl.php';
require_once __DIR__ . '/db/connection.php';
require_once __DIR__ . '/db/schema.php';
require_once __DIR__ . '/db/status.php';
require_once __DIR__ . '/db/ha.php';
require_once __DIR__ . '/db/replication.php';
require_once __DIR__ . '/db/settings.php';

// API: 64 функций в 9 модулях.
