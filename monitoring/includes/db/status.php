<?php
declare(strict_types=1);

// Каталог monitoring/includes. Модули лежат уровнем ниже, поэтому их
// собственный __DIR__ на уровень глубже; пути конфигурации и данных
// должны считаться от каталога, где лежит db_config.php.
if (!defined('DB_INCLUDES_DIR')) {
    define('DB_INCLUDES_DIR', dirname(__DIR__));
}

/**
 * Диагностика подключения и страница настройки.
 *
 * Разбор причины ошибки в терминах пользователя, флаг редактируемости полей и вывод страницы мастера настройки.
 *
 * Часть monitoring/includes/db_config.php; подключается оттуда.
 * Напрямую этот файл не включают.
 */

function db_is_configured(): bool
{
    $cfg = db_config_load();
    if (!empty($cfg['from_file'])) {
        return true;
    }
    if (!empty($cfg['from_env'])) {
        $name = trim((string)($cfg['name'] ?? ''));
        $user = trim((string)($cfg['user'] ?? ''));
        return $name !== '' && $user !== '';
    }
    return false;
}

function db_needs_setup(): bool
{
    if (!db_is_configured()) {
        return true;
    }
    try {
        $pdo = getDbConnection();
        $pdo->query('SELECT 1');
        try {
            return !db_has_users($pdo);
        } catch (Throwable $e) {
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }
}

function db_connection_status(): array
{
    $cfg = db_config_load();
    $status = [
        'configured' => db_is_configured(),
        'replica_enabled' => db_replica_enabled($cfg),
        'primary' => null,
        'replica' => null,
        'active_role' => null,
        'last_error' => null,
    ];
    if (!$status['configured']) {
        return $status;
    }
    $primaryEp = db_endpoint($cfg, 'primary');
    $GLOBALS['_db_ep_primary_host'] = $primaryEp['host'];
    $GLOBALS['_db_ep_primary_name'] = $primaryEp['name'];
    try {
        $status['primary'] = db_ping_endpoint($primaryEp, 3);
    } catch (Throwable $e) {
        $status['primary'] = ['ok' => false, 'ms' => 0, 'error' => $e->getMessage()];
    }
    $status['primary']['host'] = $primaryEp['host'];
    $status['primary']['port'] = $primaryEp['port'];
    $status['primary']['name'] = $primaryEp['name'];
    $status['primary']['user'] = $primaryEp['user'];

    if ($status['replica_enabled']) {
        $replicaEp = db_endpoint($cfg, 'replica');
        $GLOBALS['_db_ep_replica_host'] = $replicaEp['host'];
        $GLOBALS['_db_ep_replica_name'] = $replicaEp['name'];
        try {
            $status['replica'] = db_ping_endpoint($replicaEp, 3);
        } catch (Throwable $e) {
            $status['replica'] = ['ok' => false, 'ms' => 0, 'error' => $e->getMessage()];
        }
        $status['replica']['host'] = $replicaEp['host'];
        $status['replica']['port'] = $replicaEp['port'];
        $status['replica']['name'] = $replicaEp['name'];
        $status['replica']['user'] = $replicaEp['user'];
    }
    try {
        $status['active_role'] = db_active_role();
    } catch (Throwable $e) {
    }
    $anyOk = ($status['primary']['ok'] ?? false) || ($status['replica']['ok'] ?? false);
    if (!$anyOk) {
        $errors = [];
        if (!empty($status['primary']['error'])) $errors[] = 'Основная: ' . $status['primary']['error'];
        if (!empty($status['replica']['error'])) $errors[] = 'Резервная: ' . $status['replica']['error'];
        $status['last_error'] = implode('; ', $errors) ?: 'Не удалось подключиться к базам данных';
    }
    return $status;
}

function db_connection_editable(array $status): bool
{
    if (empty($status['configured'])) {
        return true;
    }
    $primaryOk = !empty($status['primary']['ok']);
    if (empty($status['replica_enabled'])) {
        return $primaryOk;
    }
    return $primaryOk || !empty($status['replica']['ok']);
}

function db_render_error_page(array $status): void
{
    $title = 'Ошибка подключения к базе данных';
    $brandName = 'HostMonitor';
    $primary = $status['primary'];
    $replica = $status['replica'];
    $replicaEnabled = !empty($status['replica_enabled']);
    $lastError = $status['last_error'] ?? 'Неизвестная ошибка';
    $bothDown = !db_connection_editable($status);
    header('HTTP/1.1 503 Service Unavailable');
    header('Retry-After: 60');
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> · <?= htmlspecialchars($brandName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Inter', system-ui, sans-serif; background: #0b1220; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { background: #111827; border: 1px solid rgba(239,68,68,0.25); border-radius: 14px; max-width: 640px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.35); overflow: hidden; }
        .card-head { background: linear-gradient(135deg, rgba(239,68,68,0.12), rgba(234,179,8,0.08)); padding: 28px 28px 12px; border-bottom: 1px solid rgba(148,163,184,0.08); display: flex; align-items: flex-start; gap: 16px; }
        .icon { flex: 0 0 auto; width: 48px; height: 48px; border-radius: 12px; background: rgba(239,68,68,0.15); display: flex; align-items: center; justify-content: center; color: #f87171; }
        .icon svg { width: 26px; height: 26px; stroke-width: 2; stroke: currentColor; fill: none; }
        .card-title { margin: 0 0 6px; font-size: 20px; font-weight: 700; color: #fecaca; }
        .card-sub { margin: 0; font-size: 14px; color: #cbd5e1; line-height: 1.5; }
        .card-body { padding: 24px 28px; }
        .section-title { font-size: 12px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: #94a3b8; margin: 0 0 12px; }
        .endpoints { display: grid; gap: 12px; margin-bottom: 22px; }
        .endpoint { background: rgba(15,23,42,0.6); border: 1px solid rgba(148,163,184,0.08); border-radius: 10px; padding: 14px 16px; }
        .ep-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .ep-name { display: flex; align-items: center; gap: 10px; font-weight: 600; color: #f1f5f9; }
        .badge { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge.ok { background: rgba(16,185,129,0.15); color: #34d399; }
        .badge.err { background: rgba(239,68,68,0.15); color: #f87171; }
        .dot { width: 8px; height: 8px; border-radius: 50%; }
        .badge.ok .dot { background: #34d399; box-shadow: 0 0 0 4px rgba(16,185,129,0.12); }
        .badge.err .dot { background: #f87171; box-shadow: 0 0 0 4px rgba(239,68,68,0.12); }
        .ep-meta { font-size: 13px; color: #94a3b8; display: flex; flex-wrap: wrap; gap: 6px 14px; }
        .ep-meta span strong { color: #e2e8f0; font-weight: 500; }
        .ep-error { margin-top: 10px; font-size: 13px; color: #fca5a5; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.15); border-radius: 8px; padding: 10px 12px; line-height: 1.5; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; word-break: break-word; }
        .hint-box { background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.18); border-radius: 10px; padding: 14px 16px; color: #bfdbfe; font-size: 13.5px; line-height: 1.6; }
        .hint-box strong { color: #dbeafe; }
        .actions { margin-top: 22px; display: flex; flex-wrap: wrap; gap: 10px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-weight: 600; font-size: 14px; text-decoration: none; border: none; cursor: pointer; transition: 150ms; }
        .btn-primary { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,0.25); }
        .btn-primary:hover { filter: brightness(1.07); }
        .btn-secondary { background: rgba(148,163,184,0.08); color: #e2e8f0; border: 1px solid rgba(148,163,184,0.15); }
        .btn-secondary:hover { background: rgba(148,163,184,0.14); }
        .foot { margin-top: 18px; text-align: center; font-size: 12px; color: #64748b; }
        .retry-tip { display: inline-block; margin-left: 8px; color: #64748b; font-size: 12px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-head">
            <div class="icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div>
                <h1 class="card-title"><?= htmlspecialchars($title) ?></h1>
                <p class="card-sub">Панель временно не может работать с базой данных. Уже настроенная конфигурация сохранена — пожалуйста, проверьте сервер MySQL/MariaDB.</p>
            </div>
        </div>
        <div class="card-body">
            <div class="section-title">Состояние подключений</div>
            <div class="endpoints">
                <div class="endpoint">
                    <div class="ep-head">
                        <div class="ep-name">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#60a5fa"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
                            Основная база
                        </div>
                        <?php if (($primary['ok'] ?? false) === true): ?>
                            <span class="badge ok"><span class="dot"></span>ОК (<?= (int)($primary['ms'] ?? 0) ?> мс)</span>
                        <?php else: ?>
                            <span class="badge err"><span class="dot"></span>Недоступна</span>
                        <?php endif; ?>
                    </div>
                    <div class="ep-meta">
                        <span>Хост: <strong><?= htmlspecialchars((string)($primary['host'] ?? $GLOBALS['_db_ep_primary_host'] ?? '—')) ?></strong></span>
                        <span>База: <strong><?= htmlspecialchars((string)($primary['name'] ?? $GLOBALS['_db_ep_primary_name'] ?? '—')) ?></strong></span>
                    </div>
                    <?php if (!empty($primary['error'])): ?>
                        <div class="ep-error"><?= htmlspecialchars((string)$primary['error']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($replicaEnabled): ?>
                <div class="endpoint">
                    <div class="ep-head">
                        <div class="ep-name">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#34d399"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                            Резервная база
                        </div>
                        <?php if (($replica['ok'] ?? false) === true): ?>
                            <span class="badge ok"><span class="dot"></span>ОК (<?= (int)($replica['ms'] ?? 0) ?> мс)</span>
                        <?php else: ?>
                            <span class="badge err"><span class="dot"></span>Недоступна</span>
                        <?php endif; ?>
                    </div>
                    <div class="ep-meta">
                        <span>Хост: <strong><?= htmlspecialchars((string)($replica['host'] ?? $GLOBALS['_db_ep_replica_host'] ?? '—')) ?></strong></span>
                        <span>База: <strong><?= htmlspecialchars((string)($replica['name'] ?? $GLOBALS['_db_ep_replica_name'] ?? '—')) ?></strong></span>
                    </div>
                    <?php if (!empty($replica['error'])): ?>
                        <div class="ep-error"><?= htmlspecialchars((string)$replica['error']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="hint-box">
                <strong>Что можно сделать:</strong><br>
                1. Проверьте запущен ли сервер MySQL/MariaDB (systemctl status mariadb / mysqld)<br>
                2. Если база временно перегружена — подождите 1–2 минуты и повторите попытку.<br>
                <?php if ($bothDown): ?>
                    <br>Обе базы недоступны — изменить настройки через панель нельзя. Исправьте сервер БД или отредактируйте <code>monitoring/data/db.local.php</code> по SSH.
                <?php elseif (!$replicaEnabled): ?>
                    <br>💡 Совет: включите резервную базу в «Настройки → База данных» — панель сможет работать при падении основной.
                <?php else: ?>
                    <br>Хотя бы одна база отвечает — параметры можно изменить в «Настройки → База данных».
                <?php endif; ?>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-primary" onclick="location.reload()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Повторить подключение
                </button>
                <?php if (!$bothDown): ?>
                <a href="settings.php#database" class="btn btn-secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
                    Настройки базы данных
                </a>
                <?php endif; ?>
                <?php if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION)): ?>
                    <a href="logout.php" class="btn btn-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Выйти
                    </a>
                <?php endif; ?>
            </div>
            <div class="foot">HostMonitor · страница будет автоматически обновлена через 60 секунд<span class="retry-tip">(или нажмите кнопку выше)</span></div>
        </div>
    </div>
    <script>
        setTimeout(function(){ location.reload(); }, 60000);
    </script>
</body>
</html>
    <?php
}
