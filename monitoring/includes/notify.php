<?php
declare(strict_types=1);

require_once __DIR__ . '/log_helpers.php';

/**
 * Доставка уведомлений панели.
 *
 * Настройки уведомлений (SMTP, Telegram, webhook) сохранялись в панели,
 * но ни один канал ничего не отправлял — исходящей почты в проекте не было
 * вовсе. Здесь собран минимальный диспетчер:
 *
 *   notify_enqueue()        — положить уведомление в outbox (по строке на канал);
 *   notify_process_outbox() — забрать пачку и попытаться доставить (вызывает воркер);
 *   notify_send_test()      — синхронная проба канала для кнопки «Проверить».
 *
 * SMTP реализован на сокетах (без внешних зависимостей): STARTTLS/SSL,
 * AUTH LOGIN. Это осознанно — панель ставится одной командой и не тянет
 * Composer-пакеты. Telegram и webhook — обычный HTTPS POST.
 *
 * Часть monitoring; подключается по требованию, все обращения к
 * setting_get() защищены function_exists(): файл должен грузиться и в
 * тестах без живой БД.
 */

const NOTIFY_MAX_ATTEMPTS = 5;
const NOTIFY_CHANNELS = ['email', 'telegram', 'webhook'];

if (!function_exists('notify_setting')) {
    function notify_setting(string $key, string $default = ''): string
    {
        if (function_exists('setting_get')) {
            return setting_get($key, $default);
        }
        if (function_exists('settings_defaults')) {
            $defaults = settings_defaults();
            return (string)($defaults[$key] ?? $default);
        }
        return $default;
    }
}

if (!function_exists('notify_ensure_tables')) {
    function notify_ensure_tables(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS notification_outbox (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                channel VARCHAR(20) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                attempts INT NOT NULL DEFAULT 0,
                last_error TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                next_attempt_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                sent_at TIMESTAMP NULL,
                INDEX idx_queue (status, next_attempt_at),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            app_log_warning('[notify_ensure_tables] ' . $e->getMessage());
        }
    }
}

if (!function_exists('notify_email_config')) {
    /**
     * Конфиг SMTP-канала из настроек. null — канал выключен/не настроен.
     *
     * @return array{host:string,port:int,user:string,pass:string,from:string,to:string,tls:string}|null
     */
    function notify_email_config(): ?array
    {
        if (notify_setting('notify_email_enabled', '1') !== '1') {
            return null;
        }
        $host = trim(notify_setting('smtp_host'));
        $to = trim(notify_setting('notify_email'));
        if ($host === '' || $to === '') {
            return null;
        }
        $port = (int)notify_setting('smtp_port', '587');
        $from = trim(notify_setting('smtp_from'));
        if ($from === '') {
            $from = trim(notify_setting('smtp_user'));
        }
        if ($from === '') {
            $from = 'hostmonitor@localhost';
        }
        // Порт 465 — неявный TLS (ssl://), остальные — открытый + STARTTLS.
        $tls = ($port === 465) ? 'ssl' : 'starttls';
        return [
            'host' => $host,
            'port' => $port > 0 ? $port : 587,
            'user' => trim(notify_setting('smtp_user')),
            'pass' => notify_setting('smtp_password'),
            'from' => $from,
            'to' => $to,
            'tls' => $tls,
        ];
    }
}

if (!function_exists('notify_telegram_config')) {
    /** @return array{token:string,chat_id:string}|null */
    function notify_telegram_config(): ?array
    {
        if (notify_setting('notify_telegram_enabled', '0') !== '1') {
            return null;
        }
        $token = trim(notify_setting('telegram_bot_token'));
        $chat = trim(notify_setting('telegram_chat_id'));
        if ($token === '' || $chat === '') {
            return null;
        }
        return ['token' => $token, 'chat_id' => $chat];
    }
}

if (!function_exists('notify_webhook_config')) {
    /** @return array{url:string}|null */
    function notify_webhook_config(): ?array
    {
        if (notify_setting('notify_webhook_enabled', '0') !== '1') {
            return null;
        }
        $url = trim(notify_setting('notify_webhook_url'));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        return ['url' => $url];
    }
}

if (!function_exists('notify_channel_configured')) {
    function notify_channel_configured(string $channel): bool
    {
        switch ($channel) {
            case 'email':
                return notify_email_config() !== null;
            case 'telegram':
                return notify_telegram_config() !== null;
            case 'webhook':
                return notify_webhook_config() !== null;
        }
        return false;
    }
}

if (!function_exists('notify_channels_status')) {
    /** Публичный статус каналов для UI (без секретов). */
    function notify_channels_status(): array
    {
        $out = [];
        foreach (NOTIFY_CHANNELS as $ch) {
            $out[$ch] = [
                'enabled' => notify_setting('notify_' . $ch . '_enabled', $ch === 'email' ? '1' : '0') === '1',
                'configured' => notify_channel_configured($ch),
            ];
        }
        return $out;
    }
}

if (!function_exists('notify_enqueue')) {
    /**
     * Кладёт уведомление в outbox. Одна строка на канал.
     *
     * @param array<int,string>|null $channels null = все включённые и настроенные
     * @return int число поставленных в очередь доставок (0 — слать некуда)
     */
    function notify_enqueue(PDO $pdo, string $subject, string $body, ?array $channels = null): int
    {
        notify_ensure_tables($pdo);
        $subject = trim($subject) !== '' ? trim($subject) : 'HostMonitor';
        $subject = function_exists('mb_substr') ? mb_substr($subject, 0, 255) : substr($subject, 0, 255);
        $targets = $channels ?? NOTIFY_CHANNELS;
        $stmt = $pdo->prepare(
            'INSERT INTO notification_outbox (channel, subject, body, status, attempts, next_attempt_at)
             VALUES (?, ?, ?, \'queued\', 0, NOW())'
        );
        $count = 0;
        foreach ($targets as $ch) {
            if (!in_array($ch, NOTIFY_CHANNELS, true)) {
                continue;
            }
            if (!notify_channel_configured($ch)) {
                continue;
            }
            $stmt->execute([$ch, $subject, $body]);
            $count++;
        }
        return $count;
    }
}

if (!function_exists('notify_job_failure_message')) {
    /**
     * Текст уведомления о сбое фоновой задачи. Отдельно от постановки в
     * очередь, чтобы проверялось без БД.
     *
     * @return array{subject:string,body:string}
     */
    function notify_job_failure_message(int $jobId, string $kind, string $error): array
    {
        $titles = function_exists('jobs_kinds') ? jobs_kinds() : [];
        $title = $titles[$kind] ?? ($kind !== '' ? $kind : 'задача');
        $subject = 'Сбой фоновой задачи: ' . $title;
        $body = "Задача #{$jobId} («{$title}») завершилась с ошибкой.\n\n"
            . 'Ошибка: ' . ($error !== '' ? $error : 'подробности не сообщены') . "\n"
            . 'Время: ' . date('Y-m-d H:i:s');
        return ['subject' => $subject, 'body' => $body];
    }
}

if (!function_exists('notify_job_failed')) {
    /**
     * Кладёт в outbox уведомление о сбое фоновой задачи. Если ни один канал
     * не настроен, notify_enqueue() тихо вернёт 0 — слать некуда.
     */
    function notify_job_failed(PDO $pdo, int $jobId, string $kind, string $error): int
    {
        $msg = notify_job_failure_message($jobId, $kind, $error);
        return notify_enqueue($pdo, $msg['subject'], $msg['body']);
    }
}

if (!function_exists('notify_http_post')) {
    /**
     * HTTPS POST. Возвращает [statusCode, body]. Предпочитает curl, иначе
     * file_get_contents со stream-контекстом.
     */
    function notify_http_post(string $url, array $payload, int $timeout = 10): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new RuntimeException('HTTP: ' . $err);
            }
            return [$code, (string)$body];
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($body === false) {
            throw new RuntimeException('HTTP: не удалось отправить запрос');
        }
        return [$code, (string)$body];
    }
}

if (!function_exists('notify_smtp_read')) {
    /** Читает SMTP-ответ (может быть многострочным) и возвращает код. */
    function notify_smtp_read($fp): int
    {
        $code = 0;
        while (($line = fgets($fp, 1024)) !== false) {
            if (preg_match('#^(\d{3})([ -])#', $line, $m)) {
                $code = (int)$m[1];
                if ($m[2] === ' ') {
                    break;
                }
            }
        }
        return $code;
    }
}

if (!function_exists('notify_smtp_cmd')) {
    /** Отправляет команду, читает ответ, бросает исключение при коде >= 400. */
    function notify_smtp_cmd($fp, string $cmd, array $okCodes = [250], bool $log = true): int
    {
        if ($log) {
            fwrite($fp, $cmd . "\r\n");
        }
        $code = notify_smtp_read($fp);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException('SMTP: неожиданный ответ ' . $code . ($cmd !== '' && $log ? ' на ' . strtok($cmd, ' ') : ''));
        }
        return $code;
    }
}

if (!function_exists('notify_email_send')) {
    /**
     * Отправка письма по SMTP. Бросает исключение с причиной при сбое.
     */
    function notify_email_send(array $cfg, string $subject, string $body): void
    {
        $transport = $cfg['tls'] === 'ssl' ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $transport . $cfg['host'] . ':' . $cfg['port'],
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT
        );
        if (!$fp) {
            throw new RuntimeException('SMTP: подключение к ' . $cfg['host'] . ':' . $cfg['port'] . ' не удалось (' . $errstr . ')');
        }
        stream_set_timeout($fp, 15);

        try {
            if (notify_smtp_read($fp) !== 220) {
                throw new RuntimeException('SMTP: сервер не поприветствовал (нет 220)');
            }
            $hostname = 'hostmonitor.local';
            if (isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== '') {
                $hostname = (string)$_SERVER['SERVER_NAME'];
            } elseif (function_exists('gethostname') && gethostname()) {
                $hostname = (string)gethostname();
            }

            // HELO/EHLO + разбор возможностей.
            fwrite($fp, 'EHLO ' . $hostname . "\r\n");
            $capabilities = '';
            while (($line = fgets($fp, 1024)) !== false) {
                $capabilities .= $line;
                if (preg_match('#^\d{3} #', $line)) {
                    break;
                }
            }
            if (stripos($capabilities, 'STARTTLS') !== false && $cfg['tls'] === 'starttls') {
                notify_smtp_cmd($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP: не удалось поднять TLS');
                }
                // После TLS возможности перечитываем.
                fwrite($fp, 'EHLO ' . $hostname . "\r\n");
                $capabilities = '';
                while (($line = fgets($fp, 1024)) !== false) {
                    $capabilities .= $line;
                    if (preg_match('#^\d{3} #', $line)) {
                        break;
                    }
                }
            }

            if ($cfg['user'] !== '') {
                notify_smtp_cmd($fp, 'AUTH LOGIN', [334]);
                notify_smtp_cmd($fp, base64_encode($cfg['user']), [334], false);
                notify_smtp_cmd($fp, base64_encode($cfg['pass']), [235], false);
            }

            notify_smtp_cmd($fp, 'MAIL FROM:<' . $cfg['from'] . '>', [250]);
            notify_smtp_cmd($fp, 'RCPT TO:<' . $cfg['to'] . '>', [250, 251]);
            notify_smtp_cmd($fp, 'DATA', [354]);

            $headers = [
                'From: ' . notify_email_header($cfg['from']),
                'To: ' . notify_email_header($cfg['to']),
                'Subject: ' . notify_email_header($subject),
                'Date: ' . date('r'),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@hostmonitor>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $data = implode("\r\n", $headers) . "\r\n\r\n" . notify_smtp_dot_stuff($body);
            fwrite($fp, $data . "\r\n.\r\n");
            if (notify_smtp_read($fp) !== 250) {
                throw new RuntimeException('SMTP: письмо не принято сервером');
            }
            fwrite($fp, "QUIT\r\n");
        } finally {
            @fclose($fp);
        }
    }
}

if (!function_exists('notify_email_header')) {
    /** Экранирует перевод строки в заголовке (защита от header injection). */
    function notify_email_header(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}

if (!function_exists('notify_smtp_dot_stuff')) {
    /** Точка в начале строки должна удваиваться (RFC 5321). */
    function notify_smtp_dot_stuff(string $body): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $normalized = str_replace("\n", "\r\n", $normalized);
        return preg_replace('/^\./m', '..', $normalized) ?? $normalized;
    }
}

if (!function_exists('notify_telegram_send')) {
    function notify_telegram_send(array $cfg, string $subject, string $body): void
    {
        $url = 'https://api.telegram.org/bot' . $cfg['token'] . '/sendMessage';
        [$code, $resp] = notify_http_post($url, [
            'chat_id' => $cfg['chat_id'],
            'text' => $subject . "\n\n" . $body,
            'disable_web_page_preview' => true,
        ]);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Telegram: HTTP ' . $code . ' ' . substr($resp, 0, 200));
        }
        $decoded = json_decode($resp, true);
        if (is_array($decoded) && array_key_exists('ok', $decoded) && !$decoded['ok']) {
            throw new RuntimeException('Telegram: ' . (string)($decoded['description'] ?? 'ошибка API'));
        }
    }
}

if (!function_exists('notify_webhook_send')) {
    function notify_webhook_send(array $cfg, string $subject, string $body): void
    {
        [$code, $resp] = notify_http_post($cfg['url'], [
            'source' => 'HostMonitor',
            'subject' => $subject,
            'body' => $body,
            // text — для совместимости с Slack/Discord-style хуками.
            'text' => $subject . "\n" . $body,
            'timestamp' => gmdate('c'),
        ]);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException('Webhook: HTTP ' . $code . ' ' . substr($resp, 0, 200));
        }
    }
}

if (!function_exists('notify_dispatch')) {
    /** Синхронная доставка по одному каналу. Бросает при сбое. */
    function notify_dispatch(string $channel, string $subject, string $body): void
    {
        switch ($channel) {
            case 'email':
                $cfg = notify_email_config();
                if ($cfg === null) {
                    throw new RuntimeException('SMTP не настроен');
                }
                notify_email_send($cfg, $subject, $body);
                return;
            case 'telegram':
                $cfg = notify_telegram_config();
                if ($cfg === null) {
                    throw new RuntimeException('Telegram не настроен');
                }
                notify_telegram_send($cfg, $subject, $body);
                return;
            case 'webhook':
                $cfg = notify_webhook_config();
                if ($cfg === null) {
                    throw new RuntimeException('Webhook не настроен');
                }
                notify_webhook_send($cfg, $subject, $body);
                return;
        }
        throw new RuntimeException('Неизвестный канал: ' . $channel);
    }
}

if (!function_exists('notify_process_outbox')) {
    /**
     * Забирает пачку из outbox и пытается доставить.
     *
     * @return array{sent:int,failed:int,retry:int}
     */
    function notify_process_outbox(PDO $pdo, int $limit = 20): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        try {
            notify_ensure_tables($pdo);
            $stmt = $pdo->prepare("SELECT * FROM notification_outbox
                WHERE status = 'queued' AND next_attempt_at <= NOW()
                ORDER BY id ASC LIMIT " . max(1, min(100, $limit)));
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            app_log_warning('[notify_process_outbox] выборка: ' . $e->getMessage());
            return $result;
        }

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $channel = (string)$row['channel'];
            try {
                notify_dispatch($channel, (string)$row['subject'], (string)$row['body']);
                $pdo->prepare("UPDATE notification_outbox SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ?")
                    ->execute([$id]);
                $result['sent']++;
            } catch (Throwable $e) {
                $attempts = (int)$row['attempts'] + 1;
                $msg = function_exists('mb_substr') ? mb_substr($e->getMessage(), 0, 500) : substr($e->getMessage(), 0, 500);
                if ($attempts >= NOTIFY_MAX_ATTEMPTS) {
                    $pdo->prepare("UPDATE notification_outbox SET status = 'failed', attempts = ?, last_error = ? WHERE id = ?")
                        ->execute([$attempts, $msg, $id]);
                    $result['failed']++;
                } else {
                    // Экспоненциальная пауза: 30s, 60s, 120s, 240s.
                    $delay = 30 * (2 ** ($attempts - 1));
                    $pdo->prepare("UPDATE notification_outbox SET status = 'queued', attempts = ?, last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
                        ->execute([$attempts, $msg, $delay, $id]);
                    $result['retry']++;
                }
                app_log_warning('[notify] ' . $channel . ' #' . $id . ': ' . $e->getMessage());
            }
        }
        return $result;
    }
}

if (!function_exists('notify_prune_outbox')) {
    /** Чистка истории доставок старше N дней. */
    function notify_prune_outbox(PDO $pdo, int $days = 30): int
    {
        try {
            notify_ensure_tables($pdo);
            $stmt = $pdo->prepare("DELETE FROM notification_outbox WHERE status <> 'queued' AND created_at < (NOW() - INTERVAL ? DAY)");
            $stmt->execute([max(1, $days)]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            app_log_warning('[notify_prune_outbox] ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('notify_send_test')) {
    /**
     * Синхронная проба каналов для кнопки в настройках.
     *
     * @param array<int,string>|null $channels
     * @return array<string,array{ok:bool,error:?string}>
     */
    function notify_send_test(PDO $pdo, ?array $channels = null): array
    {
        $targets = $channels ?? NOTIFY_CHANNELS;
        $subject = 'HostMonitor: проверка уведомлений';
        $body = "Это тестовое уведомление от панели HostMonitor.\nВремя: " . date('Y-m-d H:i:s');
        $out = [];
        foreach ($targets as $ch) {
            if (!in_array($ch, NOTIFY_CHANNELS, true)) {
                continue;
            }
            try {
                notify_dispatch($ch, $subject, $body);
                $out[$ch] = ['ok' => true, 'error' => null];
            } catch (Throwable $e) {
                $out[$ch] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        return $out;
    }
}
