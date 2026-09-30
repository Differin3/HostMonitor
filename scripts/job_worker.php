<?php
declare(strict_types=1);

/**
 * Фоновые задачи панели: воркер.
 *
 * Запускается systemd-юнитом hostmonitor-jobs.service от пользователя
 * панели. Забирает задачу из очереди, выполняет её по шагам, после
 * каждого шага пишет прогресс и курсор в базу. Благодаря этому
 * копирование базы продолжается, даже если вкладку закрыли, панель
 * перезапустили или воркер убили и подняли заново.
 *
 * Запуск: php scripts/job_worker.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "job_worker.php запускается только из CLI\n");
    exit(1);
}

require_once __DIR__ . '/../monitoring/includes/database.php';
require_once __DIR__ . '/../monitoring/includes/jobs.php';
require_once __DIR__ . '/../monitoring/includes/db/ha.php';
require_once __DIR__ . '/../monitoring/includes/db/replication.php';

// Порция строк за шаг. Совпадает с тем, что раньше делал браузер:
// достаточно мало, чтобы не залипнуть на медленном резерве и при этом
// не превращать копирование в тысячи HTTP-запросов.
const JOB_CHUNK_LIMIT = 200;
// Сколько секунд воркер спит, когда очередь пуста.
const JOB_IDLE_SLEEP = 2;
// Задание без обновления updated_at дольше этого считается убитым.
const JOB_STALE_SEC = 300;
// Раз в сутки чистим историю.
const JOB_PRUNE_EVERY = 86400;

$running = true;

// systemd шлёт SIGTERM при restart/stop. Не обрываем запись посреди
// чанка: помечаем задачу обратно в queued и выходим, её подхватит
// следующий запуск.
if (function_exists('pcntl_signal')) {
    $stop = static function () use (&$running): void {
        $running = false;
    };
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/**
 * Выполняет один шаг задачи. Возвращает 'running' | 'done' | 'canceled'.
 *
 * Шаг, а не задача целиком: между шагами воркер проверяет отмену и
 * обновляет прогресс, поэтому панель видит живое состояние, а Cancel
 * срабатывает за секунды, а не минуты.
 */
function jobs_handler(string $kind, array &$state, PDO $queue, int $jobId): string
{
    if ($kind === 'db.sync') {
        return jobs_handler_db_sync($state, $queue, $jobId);
    }
    throw new RuntimeException('Обработчик не найден: ' . $kind);
}

/**
 * Копирование таблиц между основной и резервной базой.
 *
 * Порядок и семантика перенесены из прежнего клиентского цикла в
 * db_sync_runner.js: список таблиц, схема таблицы, порции данных,
 * в конце — восстановление FOREIGN_KEY_CHECKS на приёмнике.
 */
function jobs_handler_db_sync(array &$state, PDO $queue, int $jobId): string
{
    $direction = (string)($state['direction'] ?? 'to_replica');
    if ($direction !== 'to_replica' && $direction !== 'to_primary') {
        throw new InvalidArgumentException('direction: to_replica или to_primary');
    }

    // db_sync_endpoints() проверяет, что резерв включён и отличается от
    // основной, а db_try_connect() ниже — что обе базы действительно
    // отвечают. Этого достаточно; db_ha_require_editable() из API тут не
    // годится: он вызывает json_error() и выходит, а не бросает исключение.
    if (empty($state['prepared'])) {
        $eps = db_sync_endpoints($direction);
        $src = db_try_connect($eps['src'], 10);
        $tables = db_list_base_tables($src);
        if ($tables === []) {
            throw new RuntimeException('В источнике нет таблиц для копирования');
        }
        $state['tables'] = $tables;
        $state['index'] = 0;
        $state['offset'] = 0;
        $state['cursor'] = null;
        $state['schema_done'] = false;
        $state['table_rows'] = 0;
        $state['total_rows'] = 0;
        $state['src_label'] = $eps['source_label'];
        $state['dst_label'] = $eps['target_label'];
        $state['src_name'] = (string)($eps['src']['name'] ?? '');
        $state['dst_name'] = (string)($eps['dst']['name'] ?? '');
        $state['prepared'] = true;
        jobs_update_payload($queue, $jobId, $state);
        jobs_progress($queue, $jobId, 0, count($tables), 'Подготовка завершена');
    }

    $eps = db_sync_endpoints($direction);
    $src = db_try_connect($eps['src'], 10);
    $dst = db_try_connect($eps['dst'], 10);

    $tables = $state['tables'];
    $total = count($tables);
    $index = (int)($state['index'] ?? 0);

    try {
        while ($index < $total) {
            if (jobs_cancel_requested($queue, $jobId)) {
                db_sync_restore_checks($dst);
                return 'canceled';
            }

            $table = (string)$tables[$index];

            if (empty($state['schema_done'])) {
                $dst->exec('SET FOREIGN_KEY_CHECKS=0');
                $dst->exec('SET UNIQUE_CHECKS=0');
                db_copy_table_schema($src, $dst, $table);
                $state['schema_done'] = true;
                $state['offset'] = 0;
                $state['cursor'] = null;
                $state['table_rows'] = 0;
                jobs_update_payload($queue, $jobId, $state);
                jobs_progress($queue, $jobId, $index, $total, 'Схема «' . $table . '» создана');
            }

            $chunk = db_copy_table_chunk(
                $src,
                $dst,
                $table,
                (int)($state['offset'] ?? 0),
                isset($state['cursor']) && $state['cursor'] !== '' ? (string)$state['cursor'] : null,
                JOB_CHUNK_LIMIT,
                5.0
            );

            $state['table_rows'] = (int)($state['table_rows'] ?? 0) + (int)$chunk['rows'];
            $state['total_rows'] = (int)($state['total_rows'] ?? 0) + (int)$chunk['rows'];
            $state['offset'] = (int)$chunk['next_offset'];
            $state['cursor'] = $chunk['next_cursor'];
            $index = (int)($state['index'] ?? 0);
            $done = !empty($chunk['table_done']) || (int)$chunk['rows'] === 0;

            $rows = number_format((int)$state['table_rows'], 0, ',', ' ');
            jobs_progress(
                $queue,
                $jobId,
                $done ? $index + 1 : $index,
                $total,
                'Таблица ' . ($index + 1) . ' из ' . $total . ' · ' . $table . ' · ' . $rows . ' строк'
            );
            jobs_update_payload($queue, $jobId, $state);

            if (!$done) {
                // Ещё данные у этой таблицы — отдаём управление, чтобы
                // отмена и прогресс были видны.
                return 'running';
            }

            $index++;
            $state['index'] = $index;
            $state['schema_done'] = false;
            $state['offset'] = 0;
            $state['cursor'] = null;
            $state['table_rows'] = 0;
            jobs_update_payload($queue, $jobId, $state);

            if ($index >= $total) {
                db_sync_restore_checks($dst);
                // Соединение самой панели могло зависнуть на блокировках
                // во время копирования — переоткрываем, как это делал API.
                getDbConnection(true);
                return 'done';
            }
        }
    } catch (Throwable $e) {
        try {
            db_sync_restore_checks($dst);
        } catch (Throwable $ignored) {
            // На приёмнике могло не быть соединения — молча продолжаем.
        }
        throw $e;
    }

    return 'running';
}

$lockPath = dirname(__DIR__) . '/data/job_worker.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0750, true);
}
$lock = @fopen($lockPath, 'c');
if (!$lock) {
    fwrite(STDERR, "Не удалось открыть lock-файл {$lockPath}\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Воркер уже запущен\n");
    exit(0);
}

fwrite(STDOUT, "[jobs] воркер запущен, pid " . getmypid() . "\n");

$pdo = null;
$lastPrune = time();
$reconnectAt = 0;
$currentJobId = 0;

while ($running) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    if (!$running) {
        break;
    }

    try {
        if ($pdo === null || time() >= $reconnectAt) {
            $pdo = getDbConnection();
            if (!$pdo) {
                throw new RuntimeException('Нет соединения с базой данных панели');
            }
            $reconnectAt = time() + 60;
        }

        jobs_ensure_tables($pdo);
        jobs_heartbeat_touch();

        $recovered = jobs_recover_stale($pdo, JOB_STALE_SEC);
        if ($recovered > 0) {
            fwrite(STDOUT, '[jobs] вернул в очередь зависших задач: ' . $recovered . "\n");
        }

        if (time() - $lastPrune >= JOB_PRUNE_EVERY) {
            jobs_prune($pdo);
            $lastPrune = time();
        }

        // Пока задача не закончилась, продолжаем именно её: забирать из
        // очереди заново нельзя, иначе на второй итерации claim вернул бы
        // ту же запись и цикл крутился бы на ней вместо остальной очереди.
        if ($currentJobId === 0) {
            $claimed = jobs_claim($pdo);
            if ($claimed === null) {
                sleep(JOB_IDLE_SLEEP);
                continue;
            }
            $currentJobId = (int)$claimed['id'];
            fwrite(STDOUT, '[jobs] взял задачу #' . $currentJobId . "\n");
        }

        $row = jobs_get($pdo, $currentJobId);
        if ($row === null) {
            $currentJobId = 0;
            continue;
        }
        $jobId = $currentJobId;
        $kind = (string)$row['kind'];
        if ($row['status'] !== 'running') {
            $currentJobId = 0;
            continue;
        }
        $state = jobs_decode(isset($row['payload']) ? (string)$row['payload'] : null);

        try {
            $outcome = jobs_handler($kind, $state, $pdo, $jobId);
            if ($outcome === 'done') {
                jobs_finish($pdo, $jobId, 'done', [
                    'tables' => count($state['tables'] ?? []),
                    'rows' => (int)($state['total_rows'] ?? 0),
                    'direction' => (string)($state['direction'] ?? 'to_replica'),
                ]);
                fwrite(STDOUT, '[jobs] задача #' . $jobId . " выполнена\n");
                $currentJobId = 0;
            } elseif ($outcome === 'canceled') {
                jobs_finish($pdo, $jobId, 'canceled', [], 'Отменено пользователем');
                fwrite(STDOUT, '[jobs] задача #' . $jobId . " отменена\n");
                $currentJobId = 0;
            }
        } catch (Throwable $e) {
            error_log('[jobs] задача #' . $jobId . ' (' . $kind . '): ' . $e->getMessage());
            jobs_finish($pdo, $jobId, 'failed', [], $e->getMessage());
            fwrite(STDOUT, '[jobs] задача #' . $jobId . ' упала: ' . $e->getMessage() . "\n");
            // После обрыва соединения следующий шаг должен взять новое.
            $pdo = null;
            $currentJobId = 0;
        }
    } catch (Throwable $e) {
        error_log('[jobs] воркер: ' . $e->getMessage());
        fwrite(STDERR, '[jobs] ' . $e->getMessage() . "\n");
        $pdo = null;
        sleep(5);
    }
}

// systemctl restart после апдейта не должен оставлять задачу «в работе»
// на 5 минут до срабатывания stale-recovery: возвращаем её в очередь,
// новый процесс подхватит с последнего записанного курсора.
if ($currentJobId > 0 && $pdo !== null) {
    try {
        $stmt = $pdo->prepare("UPDATE background_jobs SET status = 'queued', updated_at = NOW() WHERE id = ? AND status = 'running'");
        $stmt->execute([$currentJobId]);
        if ($stmt->rowCount() > 0) {
            fwrite(STDOUT, '[jobs] задача #' . $currentJobId . " возвращена в очередь\n");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, '[jobs] не удалось вернуть задачу в очередь: ' . $e->getMessage() . "\n");
    }
}

fwrite(STDOUT, "[jobs] воркер остановлен\n");
flock($lock, LOCK_UN);
fclose($lock);
exit(0);
