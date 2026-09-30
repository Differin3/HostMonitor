<?php
declare(strict_types=1);

/**
 * Очередь долгих операций.
 *
 * Браузер только ставит задачу и опрашивает состояние — работает
 * scripts/job_worker.php. Поэтому копирование базы не прерывается при
 * переходе на другую страницу, закрытии вкладки или падении панели:
 * курсор лежит в payload задачи и переживает перезапуск воркера.
 *
 * Часть monitoring/includes. Подключается из api/jobs.php и job_worker.php.
 */

if (!function_exists('jobs_ensure_tables')) {
    /**
     * Создаёт таблицы очереди, если их ещё нет.
     *
     * Отдельный файл schema не годится: обновление панели через git не
     * трогает живой database, а ленивое создание здесь делает очередь
     * рабочей сразу после апдейта, без ручного SQL.
     */
    function jobs_ensure_tables(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS background_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                kind VARCHAR(64) NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                payload MEDIUMTEXT,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
                progress_done BIGINT NOT NULL DEFAULT 0,
                progress_total BIGINT NOT NULL DEFAULT 0,
                progress_label VARCHAR(255) NOT NULL DEFAULT '',
                result MEDIUMTEXT,
                error MEDIUMTEXT,
                created_by INT NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                started_at TIMESTAMP NULL DEFAULT NULL,
                finished_at TIMESTAMP NULL DEFAULT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_jobs_status (status, id),
                INDEX idx_jobs_kind (kind, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS background_job_seen (
                job_id BIGINT UNSIGNED NOT NULL,
                user_id INT NOT NULL,
                seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (job_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('jobs_str_cut')) {
    /**
     * Обрезка строки до длины колонки без зависимости от mbstring.
     *
     * Воркер запускается отдельным процессом, и если в системном PHP-CLI
     * нет mbstring, падать нельзя: колонка в строгом режиме отвергает
     * невалидный UTF-8. Режем символами через PCRE с /u — он есть всегда.
     * Если исходная строка сама битая, режем по байтам и отбрасываем
     * неполную последнюю последовательность.
     */
    function jobs_str_cut(string $value, int $max): string
    {
        $max = max(0, $max);
        if (function_exists('mb_substr')) {
            return (string)mb_substr($value, 0, $max);
        }
        if (preg_match('/^(.{0,' . $max . '})/us', $value, $m) === 1) {
            return $m[1];
        }
        $cut = (string)substr($value, 0, $max);
        $cut = (string)preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut);
        $cut = (string)preg_replace('/[\x80-\xBF]+$/', '', $cut);
        return $cut;
    }
}

if (!function_exists('jobs_encode')) {
    function jobs_encode(array $data): string
    {
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('jobs_decode')) {
    function jobs_decode(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        // Состояние — это всегда карта (index/offset/cursor/...). JSON-список
        // в это поле попадать не должен: у него нет ключей, и любое чтение
        // state['что-то'] молча дало бы null посреди копирования.
        $isList = $data === [] || array_keys($data) === range(0, count($data) - 1);
        return $isList ? [] : $data;
    }
}

if (!function_exists('jobs_row_to_array')) {
    /**
     * Приводит строку таблицы к виду, который отдаём в браузер.
     *
     * payload скрыт намеренно: там лежит курсор копирования, а браузеру он
     * не нужен — достаточно прогресса и статуса.
     */
    function jobs_row_to_array(array $row): array
    {
        $result = jobs_decode(isset($row['result']) ? (string)$row['result'] : null);
        $error = isset($row['error']) ? trim((string)$row['error']) : '';
        $total = (int)($row['progress_total'] ?? 0);
        $done = (int)($row['progress_done'] ?? 0);
        $pct = $total > 0 ? (int)round(min(100, max(0, $done / $total * 100))) : 0;
        if (($row['status'] ?? '') === 'done') {
            $pct = 100;
        }
        return [
            'id' => (int)$row['id'],
            'kind' => (string)$row['kind'],
            'title' => (string)($row['title'] ?? ''),
            'status' => (string)$row['status'],
            'progress_done' => $done,
            'progress_total' => $total,
            'progress_pct' => $pct,
            'progress_label' => (string)($row['progress_label'] ?? ''),
            'result' => $result,
            'error' => $error,
            'cancel_requested' => (int)($row['cancel_requested'] ?? 0) === 1,
            'created_at' => (string)($row['created_at'] ?? ''),
            'started_at' => (string)($row['started_at'] ?? ''),
            'finished_at' => (string)($row['finished_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }
}

if (!function_exists('jobs_kinds')) {
    /**
     * Реестр видов задач: ключ — что воркер умеет выполнять, значение —
     * как это называется в интерфейсе.
     *
     * Новый вид подключается одной строкой здесь плюс веткой в
     * jobs_handler() в job_worker.php.
     */
    function jobs_kinds(): array
    {
        return [
            'db.sync' => 'Копирование базы',
        ];
    }
}

if (!function_exists('jobs_kind_allowed')) {
    function jobs_kind_allowed(string $kind): bool
    {
        return array_key_exists($kind, jobs_kinds());
    }
}

if (!function_exists('jobs_enqueue')) {
    /**
     * Ставит задачу в очередь. Возвращает id.
     *
     * Задачи одного вида не дублируются: пока предыдущая в работе, новая
     * не встанет в очередь — иначе два параллельных копирования в одну
     * таблицу гарантированно conflict'ятся по первичному ключу.
     */
    function jobs_enqueue(PDO $pdo, string $kind, array $payload, string $title = '', ?int $userId = null): int
    {
        jobs_ensure_tables($pdo);
        $kind = trim($kind);
        if ($kind === '') {
            throw new InvalidArgumentException('Не указан вид задачи');
        }
        $active = $pdo->prepare(
            "SELECT id FROM background_jobs WHERE kind = ? AND status IN ('queued','running') ORDER BY id LIMIT 1"
        );
        $active->execute([$kind]);
        $running = $active->fetchColumn();
        if ($running !== false) {
            throw new RuntimeException('Такая операция уже выполняется (задача #' . (int)$running . ')');
        }
        $stmt = $pdo->prepare(
            "INSERT INTO background_jobs (kind, title, payload, status, created_by)
             VALUES (?, ?, ?, 'queued', ?)"
        );
        $stmt->execute([$kind, $title !== '' ? $title : $kind, jobs_encode($payload), $userId]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('jobs_claim')) {
    /**
     * Забирает очередную задачу себе. null — очередь пуста.
     *
     * Захват делает условный UPDATE по id, а не SELECT ... FOR UPDATE
     * SKIP LOCKED: SKIP LOCKED нет в MySQL 5.7, куда панель тоже ходит.
     * Важно, что возвращается именно свеже взятая задача: повторный
     * вызов на уже выполняющейся должен дать null, иначе воркер сошёл бы
     * с ума и крутил бы одну и ту же задачу вместо очереди.
     */
    function jobs_claim(PDO $pdo): ?array
    {
        $pick = $pdo->query("SELECT id FROM background_jobs WHERE status = 'queued' ORDER BY id LIMIT 1");
        $id = $pick ? $pick->fetchColumn() : false;
        if ($id === false) {
            return null;
        }
        $upd = $pdo->prepare(
            "UPDATE background_jobs SET status = 'running', started_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'queued'"
        );
        $upd->execute([(int)$id]);
        if ($upd->rowCount() === 0) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT * FROM background_jobs WHERE id = ?');
        $stmt->execute([(int)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('jobs_get')) {
    /**
     * Перечитывает задачу. Воркер берёт её перед каждым шагом: после
     * перезапуска процесса это единственный способ узнать позицию, на
     * которой остановились.
     */
    function jobs_get(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM background_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('jobs_update_payload')) {
    /**
     * Сохраняет курсор задачи между шагами воркера.
     *
     * Именно это делает копирование переживаемым: после падения воркера или
     * панели новый запуск продолжит с последней записанной позиции, а не
     * сначала.
     */
    function jobs_update_payload(PDO $pdo, int $id, array $payload): void
    {
        $stmt = $pdo->prepare('UPDATE background_jobs SET payload = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([jobs_encode($payload), $id]);
    }
}

if (!function_exists('jobs_progress')) {
    function jobs_progress(PDO $pdo, int $id, int $done, int $total, string $label = ''): void
    {
        $stmt = $pdo->prepare(
            'UPDATE background_jobs SET progress_done = ?, progress_total = ?, progress_label = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([max(0, $done), max(0, $total), jobs_str_cut($label, 255), $id]);
    }
}

if (!function_exists('jobs_finish')) {
    function jobs_finish(PDO $pdo, int $id, string $status, array $result = [], string $error = ''): void
    {
        $status = in_array($status, ['done', 'failed', 'canceled'], true) ? $status : 'failed';
        // Счётчики прогресса приводим к итогу задачи, а не оставляем
        // «1 из 5» на выполненном копировании всех пяти таблиц: на них
        // смотрят и статус в колокольчике, и журнал.
        $done = 0;
        $total = 0;
        if ($status === 'done') {
            $total = isset($result['tables']) ? (int)$result['tables'] : 1;
            if ($total < 1) {
                $total = 1;
            }
            $done = $total;
        } else {
            $stmt = $pdo->prepare('SELECT progress_done, progress_total FROM background_jobs WHERE id = ?');
            $stmt->execute([$id]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $done = (int)$row['progress_done'];
                $total = (int)$row['progress_total'];
            }
        }
        $stmt = $pdo->prepare(
            'UPDATE background_jobs SET status = ?, progress_done = ?, progress_total = ?,
                progress_label = ?, result = ?, error = ?,
                finished_at = NOW(), updated_at = NOW() WHERE id = ?'
        );
        $label = $status === 'done' ? 'Готово' : ($status === 'canceled' ? 'Отменено' : 'Ошибка');
        $stmt->execute([
            $status,
            $done,
            $total,
            $label,
            jobs_encode($result),
            $error !== '' ? $error : null,
            $id,
        ]);
    }
}

if (!function_exists('jobs_cancel_requested')) {
    function jobs_cancel_requested(PDO $pdo, int $id): bool
    {
        $stmt = $pdo->prepare('SELECT cancel_requested FROM background_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        return (int)$value === 1;
    }
}

if (!function_exists('jobs_request_cancel')) {
    /**
     * Просит задачу остановиться. Флаг, а не kill: воркер проверяет его
     * между чанками, чтобы не оборвать запись на середине.
     */
    function jobs_request_cancel(PDO $pdo, int $id): bool
    {
        jobs_ensure_tables($pdo);
        $stmt = $pdo->prepare(
            "UPDATE background_jobs SET cancel_requested = 1, updated_at = NOW()
             WHERE id = ? AND status IN ('queued','running')"
        );
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('jobs_recover_stale')) {
    /**
     * Возвращает в очередь задачи, на которых воркер умер.
     *
     * Воркер пишет updated_at после каждого чанка, поэтому «давно не
     * обновлялась» означает именно падение, а не долгую таблицу.
     */
    function jobs_recover_stale(PDO $pdo, int $staleSeconds = 300): int
    {
        $stmt = $pdo->prepare(
            "UPDATE background_jobs SET status = 'queued', updated_at = NOW()
             WHERE status = 'running' AND updated_at < (NOW() - INTERVAL ? SECOND)"
        );
        $stmt->execute([max(30, $staleSeconds)]);
        return $stmt->rowCount();
    }
}

if (!function_exists('jobs_list')) {
    function jobs_list(PDO $pdo, int $limit = 20, ?string $kind = null): array
    {
        jobs_ensure_tables($pdo);
        $limit = max(1, min(200, $limit));
        if ($kind !== null && $kind !== '') {
            $stmt = $pdo->prepare("SELECT * FROM background_jobs WHERE kind = ? ORDER BY id DESC LIMIT {$limit}");
            $stmt->execute([$kind]);
        } else {
            $stmt = $pdo->query("SELECT * FROM background_jobs ORDER BY id DESC LIMIT {$limit}");
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = jobs_row_to_array($row);
        }
        return $out;
    }
}

if (!function_exists('jobs_unseen_ids')) {
    /**
     * Завершённые задачи, о которых этот пользователь ещё не знает.
     *
     * Отметка о прочтении своя на пользователя: один админ не должен
     * снимать счётчик у другого.
     */
    function jobs_unseen_ids(PDO $pdo, ?int $userId, int $limit = 20): array
    {
        jobs_ensure_tables($pdo);
        if ($userId === null) {
            return [];
        }
        $stmt = $pdo->prepare(
            "SELECT j.id FROM background_jobs j
             LEFT JOIN background_job_seen s ON s.job_id = j.id AND s.user_id = ?
             WHERE j.status IN ('done','failed','canceled') AND s.job_id IS NULL
             ORDER BY j.id DESC LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}

if (!function_exists('jobs_mark_seen')) {
    function jobs_mark_seen(PDO $pdo, ?int $userId, array $ids): int
    {
        jobs_ensure_tables($pdo);
        if ($userId === null || $ids === []) {
            return 0;
        }
        $stmt = $pdo->prepare('INSERT IGNORE INTO background_job_seen (job_id, user_id) VALUES (?, ?)');
        $n = 0;
        foreach ($ids as $id) {
            $stmt->execute([(int)$id, $userId]);
            $n += $stmt->rowCount();
        }
        return $n;
    }
}

if (!function_exists('jobs_active')) {
    /**
     * Задачи в работе — их нельзя запустить повторно и их видно в списке.
     */
    function jobs_active(PDO $pdo): array
    {
        jobs_ensure_tables($pdo);
        $stmt = $pdo->query(
            "SELECT * FROM background_jobs WHERE status IN ('queued','running') ORDER BY id ASC"
        );
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = jobs_row_to_array($row);
        }
        return $out;
    }
}

if (!function_exists('jobs_prune')) {
    /**
     * Чистит историю. Вызывает воркер, раз в сутки.
     *
     * LIMIT/OFFSET в prepared-запросе не принимает ни MySQL, ни MariaDB,
     * поэтому отбираем лишние id обычным SELECT и удаляем по списку.
     * Значения — целые числа из самой таблицы, подстановка безопасна.
     */
    function jobs_prune(PDO $pdo, int $keepDays = 30, int $keep = 300): int
    {
        jobs_ensure_tables($pdo);
        $days = max(1, $keepDays);
        $keep = max(0, $keep);
        $stmt = $pdo->query(
            "SELECT id FROM background_jobs
             WHERE status IN ('done','failed','canceled') AND finished_at < (NOW() - INTERVAL {$days} DAY)
             ORDER BY id DESC LIMIT {$keep}, 18446744073709551615"
        );
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', $ids);
        $seen = $pdo->exec("DELETE FROM background_job_seen WHERE job_id IN ({$in})");
        $jobs = $pdo->exec("DELETE FROM background_jobs WHERE id IN ({$in})");
        return (int)$seen + (int)$jobs;
    }
}

if (!function_exists('jobs_heartbeat_path')) {
    function jobs_heartbeat_path(): string
    {
        return dirname(__DIR__) . '/data/worker.heartbeat';
    }
}

if (!function_exists('jobs_heartbeat_touch')) {
    /**
     * Путь к самому воркеру: по нему панель понимает, какой версии кода
     * работает запущенный процесс.
     */
    function jobs_worker_script_path(): string
    {
        return dirname(__DIR__, 2) . '/scripts/job_worker.php';
    }
}

if (!function_exists('jobs_heartbeat_touch')) {
    /**
     * Отметка «воркер жив» и версия кода, на которой он реально работает.
     *
     * $codeMtime обязан приходить из самого воркера и быть тем, что он
     * загрузил при старте. Если брать mtime файла здесь, то после git pull
     * старый процесс продолжит писать в heartbeat mtime нового файла, и
     * панель решит, что перезапуск уже произошёл, — хотя в очереди всё ещё
     * крутится старый код.
     */
    function jobs_heartbeat_touch(int $codeMtime = 0): void
    {
        $path = jobs_heartbeat_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $payload = ['ts' => time()];
        if ($codeMtime <= 0) {
            $codeMtime = (int)@filemtime(jobs_worker_script_path());
        }
        if ($codeMtime > 0) {
            $payload['code'] = $codeMtime;
        }
        @file_put_contents($path, (string)json_encode($payload));
    }
}

if (!function_exists('jobs_worker_alive')) {
    /**
     * Жив ли воркер. Панель показывает это в настройках долгих операций,
     * иначе застрявшая в очереди задача выглядит как «ничего не происходит».
     *
     * Файл heartbeat писался по-разному: сначала просто timestamp, потом
     * JSON с меткой кода. Оба формата читаются, иначе воркер, запущенный
     * до обновления, выглядел бы мёртвым и панель ругалась бы впустую.
     */
    function jobs_worker_alive(int $staleSeconds = 120): bool
    {
        $raw = jobs_worker_heartbeat_raw();
        $ts = 0;
        if ($raw > 0) {
            $ts = $raw;
        } else {
            $decoded = json_decode((string)@file_get_contents(jobs_heartbeat_path()), true);
            $ts = isset($decoded['ts']) ? (int)$decoded['ts'] : 0;
        }
        if ($ts <= 0) {
            return false;
        }
        return (time() - $ts) <= max(30, $staleSeconds);
    }
}

if (!function_exists('jobs_worker_heartbeat_raw')) {
    /**
     * Старый формат heartbeat — просто число секунд. Ноль у нового формата.
     */
    function jobs_worker_heartbeat_raw(): int
    {
        $path = jobs_heartbeat_path();
        if (!is_file($path)) {
            return 0;
        }
        $raw = trim((string)@file_get_contents($path));
        return ctype_digit($raw) ? (int)$raw : 0;
    }
}

if (!function_exists('jobs_worker_status')) {
    /**
     * Состояние воркера для панели: жив ли и на какой версии кода работает.
     *
     * up_to_date = null означает «неизвестно»: воркер не запущен либо
     * метки кода ещё нет (процесс стартовал до этой правки). Панель в этом
     * случае не должна утверждать, что всё в порядке.
     */
    function jobs_worker_status(int $staleSeconds = 120): array
    {
        $alive = jobs_worker_alive($staleSeconds);
        $code = null;
        $lastSeen = 0;

        $decoded = json_decode((string)@file_get_contents(jobs_heartbeat_path()), true);
        if (is_array($decoded)) {
            $lastSeen = isset($decoded['ts']) ? (int)$decoded['ts'] : 0;
            $code = isset($decoded['code']) ? (int)$decoded['code'] : null;
        }
        if ($lastSeen === 0) {
            $lastSeen = jobs_worker_heartbeat_raw();
        }

        $expected = @filemtime(jobs_worker_script_path());
        $expected = $expected === false ? null : (int)$expected;

        $upToDate = null;
        if ($alive && $code !== null && $expected !== null) {
            $upToDate = $code === $expected;
        }

        return [
            'alive' => $alive,
            'code_mtime' => $code,
            'expected_mtime' => $expected,
            'up_to_date' => $upToDate,
            'last_seen' => $lastSeen,
        ];
    }
}
