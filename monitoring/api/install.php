<?php
/**
 * install.php — отдача bootstrap-установщика агента (scripts/install_agent.sh).
 *
 * Публичный endpoint: нужен, чтобы команда подключения ноды была
 * самодостаточной — скачивала установщик прямо с панели, ставила агент
 * (git clone, зависимости, venv, systemd-сервис) и подключала его по
 * одноразовому enroll-коду.
 *
 * Отдаётся ровно тот скрипт, что лежит в репозитории панели, поэтому версия
 * установщика всегда совпадает с версией панели. Секретов в скрипте нет, он
 * публичен и в GitHub.
 *
 *   GET /api/install.php
 */
header('Content-Type: text/x-shellscript; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$script = realpath(__DIR__ . '/../../scripts/install_agent.sh');
if ($script === false || !is_file($script)) {
    http_response_code(404);
    echo "#!/bin/sh\n";
    echo "echo 'install_agent.sh не найден на панели' >&2\n";
    exit(1);
}

readfile($script);
