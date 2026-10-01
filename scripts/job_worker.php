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
// Как часто проверять, не обновили ли сам воркер.
const JOB_SELF_RELOAD_EVERY = 15;

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
      if ($kind === 'pkg.install') {
          return jobs_handler_node_ops($state, $queue, $jobId, 'pkg');
      }
      if ($kind === 'agent.update' || $kind === 'agent.check') {
          return jobs_handler_node_ops($state, $queue, $jobId, 'agent');
      }
      throw new RuntimeException('Обработчик не найден: ' . $kind);
  }

    /**
     * Наблюдение за обновлениями нод: пакетов (pkg.install) и агентов
     * (agent.update / agent.check).
   *
   * Команду ноде ставит по-прежнему API — сам агент её забирает своим
   * циклом опроса и выполняет. Здесь воркер только следит за состоянием
   * и пишет в background_jobs настоящий прогресс и ошибки.
   *
   * Так прогресс переживает переход на другую страницу, новую вкладку и
   * перезапуск воркера: источник правды — строки nodes/node_updates, а не
   * таймер в браузере.
   */
  function jobs_handler_node_ops(array &$state, PDO $queue, int $jobId, string $mode): string
  {
      $nodes = $state['nodes'] ?? [];
      if (!is_array($nodes) || $nodes === []) {
          throw new InvalidArgumentException('payload.nodes: список нод обязателен');
      }
      $nodes = array_values(array_unique(array_map('intval', $nodes)));
      $nodes = array_values(array_filter($nodes, static fn(int $id): bool => $id > 0));
      if ($nodes === []) {
          throw new InvalidArgumentException('payload.nodes: нет корректных id нод');
      }
      if ($nodes !== ($state['nodes'] ?? null)) {
          $state['nodes'] = $nodes;
      }

      // Шаг короче опроса агента, чтобы не мешать его heartbeat и не
      // блокировать ноду лишними UPDATE в nodes.
      $sleep = $mode === 'pkg' ? 5 : 3;
      $lastTick = (int)($state['last_tick'] ?? 0);
      if ($lastTick === 0 || (time() - $lastTick) >= $sleep) {
          $state['last_tick'] = time();
          jobs_update_payload($queue, $jobId, $state);
      }

      $snapshot = jobs_node_ops_snapshot($queue, $mode, $nodes, $state);
      $total = (int)$snapshot['total'];
      $done = (int)$snapshot['done'];

      jobs_progress(
          $queue,
          $jobId,
          $done,
          $total,
          $snapshot['label']
      );

      if (!empty($snapshot['canceled'])) {
          return 'canceled';
      }

      $result = [
          'done' => $done,
          'total' => $total,
          'failed' => (int)$snapshot['failed'],
      ];
      if (!empty($snapshot['errors'])) {
          $result['errors'] = $snapshot['errors'];
      }

      if ((int)$snapshot['failed'] > 0 && $total > 0 && $done >= $total) {
          // Все ноды дошли до конца, часть пакетов/агентов не обновилась:
          // это провал задачи, а не успех с потерями.
          $state['errors'] = $snapshot['errors'];
          jobs_update_payload($queue, $jobId, $state);
          throw new RuntimeException(implode('; ', array_slice($snapshot['errors'], 0, 3)));
      }

      if ($total > 0 && $done >= $total) {
          return 'done';
      }

      // Нода пропала или зависла намертво: ждать вечно нельзя, иначе задача
      // висит в колокольчике сутками. Порог generous — обновление пакета
      // идёт до 300 с, несколько пакетов подряд — дольше.
      $deadline = (int)($state['deadline'] ?? 0);
      if ($deadline === 0) {
          $state['deadline'] = time() + jobs_node_ops_timeout($mode, $total);
          jobs_update_payload($queue, $jobId, $state);
      }
      if (time() > $deadline) {
          if ($snapshot['stale'] > 0) {
              throw new RuntimeException(sprintf(
                  'Нет ответа от %d нод(ы): %s',
                  (int)$snapshot['stale'],
                  (string)$snapshot['stale_labels']
              ));
          }
          throw new RuntimeException('Превышено время ожидания');
      }

      return 'running';
  }

  /** Текст последней неуспешной установки пакета — для колокольчика. */
  function jobs_node_ops_history_message(PDO $pdo, int $nodeId, string $package): string
  {
      if ($nodeId <= 0 || $package === '') {
          return '';
      }
      try {
          $stmt = $pdo->prepare(
              'SELECT message FROM update_history
                WHERE node_id = ? AND package = ? AND success = 0
                ORDER BY id DESC LIMIT 1'
          );
          $stmt->execute([$nodeId, $package]);
          return trim((string)$stmt->fetchColumn());
      } catch (Throwable $e) {
          return '';
      }
  }

  /** Сколько ждать завершения операции на нодах, с запасом на очередь. */
  function jobs_node_ops_timeout(string $mode, int $total): int
  {
      $perNode = $mode === 'pkg' ? 420 : 900;
      return max(900, $perNode * max(1, $total) + 300);
  }

  /**
   * Считает реальный прогресс по нодам: реальные числа из БД, а не счётчик
   * тиков браузера.
   */
  function jobs_node_ops_snapshot(PDO $pdo, string $mode, array $nodes, array &$state): array
  {
      $in = implode(',', array_map('intval', $nodes));
      $rows = [];
      $errors = [];
      $staleLabels = [];
      $stale = 0;
      $failed = 0;
      $done = 0;
      $total = 0;
      $pendingLabels = [];

          if ($mode === 'pkg') {
            // Пакеты: один элемент прогресса — установленный пакет.
            // Список берём из payload задачи, а не из install_queued: там
            // остаются хвосты от прошлых неудачных попыток, и они завышали
            // знаменатель до бесконечности. Флаг держим только как признак
            // «ещё не отчитался агент».
            $want = $state['packages'] ?? [];
            $total = 0;
            $done = 0;
            foreach ($want as $nodeId => $pkgs) {
                $total += count((array)$pkgs);
            }
            if ($total === 0) {
                throw new RuntimeException('payload.packages: пустой список пакетов');
            }

          $rows = [];
          foreach ($want as $nodeId => $pkgs) {
              $nodeId = (int)$nodeId;
              foreach ((array)$pkgs as $pkg) {
                  $rows[] = [
                      'node_id' => $nodeId,
                      'package' => (string)$pkg,
                      'queued' => 1,
                  ];
              }
          }
        } else {
          // Агент: один элемент прогресса — нода.
          $rows = [];
          try {
              $stmt = $pdo->query(
                  "SELECT n.id AS node_id, '' AS package,
                          COALESCE(n.name, CONCAT('нода ', n.id)) AS node_name,
                          0 AS queued,
                          n.last_command, n.command_status, n.command_timestamp,
                          n.command_result AS last_message
                   FROM nodes n
                   WHERE n.id IN ({$in})"
              );
              $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable $e) {
              throw new RuntimeException('Не удалось прочитать состояние нод: ' . $e->getMessage());
          }
        }

        if ($mode === 'pkg') {
            // Подтягиваем актуальные статусы по нужным пакетам: флаг
            // install_queued показывает «агент ещё не закончил», но не
            // говорит, успех это или провал, — для этого есть история.
            try {
                $statusStmt = $pdo->prepare(
                    'SELECT node_id, package, install_queued FROM node_updates
                      WHERE install_queued = 1'
                );
                $statusStmt->execute();
                $flags = [];
                foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                    $flags[$f['node_id'] . '::' . $f['package']] = (int)$f['install_queued'];
                }
                $nodeStmt = $pdo->query(
                    "SELECT id, name, last_command, command_status, command_timestamp
                       FROM nodes WHERE id IN ({$in})"
                );
                $nodeRows = [];
                foreach ($nodeStmt->fetchAll(PDO::FETCH_ASSOC) as $n) {
                    $nodeRows[(int)$n['id']] = $n;
                }
                foreach ($rows as $i => $r) {
                    $key = $r['node_id'] . '::' . $r['package'];
                    $n = $nodeRows[(int)$r['node_id']] ?? null;
                    $rows[$i]['node_name'] = $n['name'] ?? ('нода ' . $r['node_id']);
                    $rows[$i]['queued'] = $flags[$key] ?? 0;
                    $rows[$i]['last_command'] = $n['last_command'] ?? null;
                    $rows[$i]['command_status'] = $n['command_status'] ?? null;
                    $rows[$i]['command_timestamp'] = $n['command_timestamp'] ?? null;
                }
            } catch (Throwable $e) {
                throw new RuntimeException('Не удалось прочитать статусы пакетов: ' . $e->getMessage());
            }
        }

      // Свежесть ответа: агент мог упасть посреди установки.
      $freshLimit = $mode === 'pkg' ? 60 : 20;
      $cutoff = time() - $freshLimit * 60;

      if ($mode === 'pkg') {
          $doneMap = jobs_node_ops_done_map($pdo, $nodes, $freshLimit);
      }

        if ($mode === 'pkg') {
            // Знаменатель уже посчитан из payload.packages выше и не
            // меняется: агент снимает install_queued по мере установки,
            // и знаменатель из живых строк прыгал бы назад.
            $done = count($doneMap);

            foreach ($rows as $r) {
                $key = $r['node_id'] . '::' . $r['package'];
                if (array_key_exists($key, $doneMap)) {
                    // Агент уже отчитался в истории — этот пакет учтён в done.
                    continue;
                }
                if ((int)$r['queued'] !== 1) {
                    // Флаг снят, но в истории нет записи: агент успел
                    // отчитаться раньше окна свежести. Пакет не ждёт.
                    $done++;
                    continue;
                }
                $label = $r['node_name'] . ': ' . $r['package'];
                $status = strtolower((string)($r['command_status'] ?? ''));
                $ts = $r['command_timestamp'] ?? null;
                $stamp = $ts === null ? 0 : (int)strtotime((string)$ts);
  
                // Порядок важен: явная ошибка агента informative, чем
                // «нет ответа». Проверка failed до stale, иначе упавший
                // пакет со старым timestamp показывался бы как молчание.
                if ($status === 'failed' || $status === 'error') {
                    $failed++;
                    $done++;
                    if (count($errors) < 4) {
                        $msg = jobs_node_ops_history_message($pdo, (int)$r['node_id'], (string)$r['package']);
                        if ($msg === '') {
                            $msg = (string)($r['last_message'] ?? '');
                        }
                        $errors[] = $label . ': ' . jobs_str_cut($msg !== '' ? $msg : 'установка не удалась', 120);
                    }
                    continue;
                }
                if ($stamp === 0 || $stamp < $cutoff) {
                    $stale++;
                    if (count($staleLabels) < 4) {
                        $staleLabels[] = $label . ' (нет ответа)';
                    }
                    continue;
                }
                $pendingLabels[] = $label;
          }

            // Пакеты, по которым агент отчитался неуспехом: считаем их
            // проваленными и поднимаем в ошибку с текстом из истории,
            // иначе пользователь увидит «установлено» вместо провала.
            foreach ($doneMap as $key => $ok) {
                if ($ok) {
                    continue;
                }
                $failed++;
                if (count($errors) < 4) {
                    [$nodeId, $package] = array_pad(explode('::', (string)$key, 2), 2, '');
                    $name = '';
                    foreach ($rows as $r) {
                        if ((string)$r['node_id'] === (string)$nodeId) {
                            $name = (string)$r['node_name'];
                            break;
                        }
                    }
                    $msg = jobs_node_ops_history_message($pdo, (int)$nodeId, (string)$package);
                    $errors[] = ($name !== '' ? $name . ': ' : '') . $package
                        . ': ' . jobs_str_cut($msg !== '' ? $msg : 'не установился', 120);
                }
            }
            if ($failed > 0 && $done >= $total && $errors === []) {
                $errors[] = 'часть пакетов не установилась, подробности в истории обновлений';
            }
      } else {
          // Агент: нода либо занята нашей командой, либо свободна.
          $busyCmd = $mode === 'agent' ? 'update-agent' : 'check-agent-update';
          $total = count($nodes);
          $busy = [];
          $pendingLabels = [];
          foreach ($rows as $r) {
              $label = $r['node_name'];
              $cmd = trim((string)($r['last_command'] ?? ''));
              $status = strtolower((string)($r['command_status'] ?? ''));
              $ts = $r['command_timestamp'] ?? null;
              $stamp = $ts === null ? 0 : (int)strtotime((string)$ts);
  
              if ($status === 'failed' || $status === 'error') {
                  $failed++;
                  $done++;
                  if (count($errors) < 4) {
                      $msg = (string)($r['last_message'] ?? '');
                      $errors[] = $label . ': ' . jobs_str_cut($msg !== '' ? $msg : 'ошибка', 120);
                  }
                  continue;
              }
              if ($stamp === 0 || $stamp < $cutoff) {
                  $stale++;
                  if (count($staleLabels) < 4) {
                      $staleLabels[] = $label . ' (нет ответа)';
                  }
                  continue;
              }
              if ($cmd === $busyCmd && in_array($status, ['pending', 'running', 'installing', 'in_progress'], true)) {
                  $busy[] = $label;
                  continue;
              }
              // Команда выполнена или сменилась — эта нода отработана.
              $done++;
          }
          $pendingLabels = $busy;
      }

      $done = min($done, $total);
      $label = jobs_node_ops_label($mode, $done, $total, $pendingLabels, $failed, $stale);

      return [
          'rows' => $rows,
          'total' => max($total, $done),
          'done' => $done,
          'failed' => $failed,
          'errors' => $errors,
          'stale' => $stale,
          'stale_labels' => implode(', ', $staleLabels),
          'label' => $label,
          'canceled' => false,
      ];
  }

  /**
   * Пакеты, по которым агент уже отчитался в истории установок.
   * Возвращает map "node_id::package" => success.
   */
  function jobs_node_ops_done_map(PDO $pdo, array $nodes, int $freshMinutes): array
  {
      $in = implode(',', array_map('intval', $nodes));
      try {
          $stmt = $pdo->query(
              "SELECT uh.node_id, uh.package, uh.success
                 FROM update_history uh
                 JOIN (SELECT node_id, package, MAX(id) AS mid
                         FROM update_history
                        WHERE node_id IN ({$in})
                          AND `timestamp` > DATE_SUB(NOW(), INTERVAL {$freshMinutes} MINUTE)
                        GROUP BY node_id, package) m
                   ON m.mid = uh.id"
          );
      } catch (Throwable $e) {
          return [];
      }
      $out = [];
      foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
          $out[$h['node_id'] . '::' . $h['package']] = (int)$h['success'];
      }
      return $out;
  }

  /** Человеческая строка прогресса для колокольчика. */
  function jobs_node_ops_label(
      string $mode,
      int $done,
      int $total,
      array $pending,
      int $failed,
      int $stale
  ): string {
      $noun = $mode === 'pkg' ? 'пакет(ов)' : ($mode === 'agent' ? 'агент(ов)' : 'нод(а)');
      $parts = [];
      if ($total > 0) {
          $parts[] = $done . ' из ' . $total . ' ' . $noun;
      }
      if ($pending) {
          $head = array_slice($pending, 0, 2);
          $rest = count($pending) - count($head);
          $parts[] = implode(', ', $head) . ($rest > 0 ? ' и ещё ' . $rest : '');
      }
      if ($failed > 0) {
          $parts[] = 'ошибок: ' . $failed;
      }
      if ($stale > 0) {
          $parts[] = 'без ответа: ' . $stale;
      }
      // Итоговую строку пишем только когда реально всё сошлось: «готово»
      // рядом с «ошибок: 1» читалось бы как успех.
      if ($pending === [] && $failed === 0 && $stale === 0 && $total > 0 && $done >= $total) {
          $parts[] = $mode === 'pkg' ? 'установлено' : 'готово';
      }
      if ($parts === []) {
          return 'Ожидание';
      }
      return jobs_str_cut(implode(' · ', $parts), 255);
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

// Лок живёт рядом с heartbeat, в том же каталоге, который создаёт и
// отдаёт в пользователя установщик. Раньше лок уезжал в scripts/../data,
// то есть в /opt/monitoring/data — каталога там нет, а /opt/monitoring
// принадлежит root, поэтому воркер падал бы с exit(1) ещё на старте.
$lockPath = dirname(__DIR__) . '/monitoring/data/job_worker.lock';
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
  
  // git pull меняет job_worker.php на диске, а процесс уже загрузил старую
  // версию в память и сам её не перечитает. Остаёмся на старом коде, пока
  // панель не перезапустят вручную, — и получаем расхождение версий именно
  // в очереди. Поэтому запоминаем свой mtime и, когда файл изменился,
  // выходим: systemd поднимет нас с новым кодом (Restart=always).
  // Проверка идёт между чанками, посреди копирования выхода не бывает.
  $selfMtime = @filemtime(__FILE__);
  $selfCheckedAt = 0;
  $reloadReason = '';
  
  while ($running) {
      if (function_exists('pcntl_signal_dispatch')) {
          pcntl_signal_dispatch();
      }
      if (!$running) {
          break;
      }
  
      if (time() - $selfCheckedAt >= JOB_SELF_RELOAD_EVERY) {
          $selfCheckedAt = time();
          // stat кэшируется, иначе после первого чтения mtime не менялся бы
          // никогда и перезапуск не срабатывал бы.
          clearstatcache(true, __FILE__);
          $nowMtime = @filemtime(__FILE__);
          // Нечитаемый mtime — не повод перезапускаться: это бывает
          // посреди git checkout, и повторные попытки превратились бы в
          // цикл рестартов. Следующая проверка через 15 с всё увидит.
          if ($nowMtime !== false && $selfMtime !== false && $nowMtime !== $selfMtime) {
              $reloadReason = 'изменился job_worker.php';
              fwrite(STDOUT, '[jobs] ' . $reloadReason . ', перезапускаюсь на новом коде' . "\n");
              break;
          }
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
        // Важно: пишем туда mtime, который воркер загрузил при старте.
        // Если бы heartbeat брал mtime файла в момент записи, старый процесс
        // после git pull отрапортовал бы о новом коде, которого он не выполняет.
        jobs_heartbeat_touch((int)$selfMtime);

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
