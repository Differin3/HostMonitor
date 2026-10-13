# Система логирования HostMonitor

Документ описывает целевую модель логирования, диагноз текущего состояния и
план поэтапного перехода. Статусы отмечаются в тексте: `[P0]` — сделано,
`[ ]` — запланировано.

## 1. Контур и текущая диагностика

В системе четыре независимых контура логирования, и до P0 они были
рассогласованы.

1. **Агент (Python)** — `agent/`, пишет через `runtime._log()` в stdout →
   `journald` (`monitoring-agent.service`, `StandardOutput=journal`).
   - Было: `_log()` = `print()` без `flush`, без уровней; TLS-предупреждение
     печаталось ~17 раз за цикл; каждый цикл и каждый heartbeat писали
     INFO-спам.
   - `[P0]` добавлены уровни и `flush=True`, verbose-логи переведены в `debug`,
     TLS-предупреждение печатается один раз.
2. **Веб-сервер панели (Python)** — `scripts/python_web_server.py`,
   `monitoring-web.service`. Запускает `php-cgi` и обслуживает `monitoring/`.
   - Было: `stderr` php-cgi выводился **только при ненулевом коде возврата**;
     при успешном запросе все PHP-ошибки и `error_log()` **терялись**.
   - `[P0]` `stderr` теперь всегда пишется в journal (одна строка, обрезка до
     4000 символов) — и при успехе, и при падении.
3. **PHP панели** — `monitoring/`. ~107 вызовов `error_log()` идут в `stderr`
   php-cgi (php.ini: `error_log` пуст, `log_errors=On`, `display_errors=Off`),
   то есть попадают в контур (2).
   - Было: отладочные баннеры `=== UPDATE REPORT ===` и self-check запросы
     «проверяем, что сохранилось» засоряли журнал.
   - `[P0]` отладочные маркеры удалены, оставшиеся логи озаглавлены тегом
     (`[nodes]`, `[updates]`, `[processes]`).
4. **Фоновые задачи (Python/PHP)** — `hostmonitor-jobs.service`,
   `scripts/cleanup_logs.php` и т.п.

Поток данных пользовательских логов (не системных): агент собирает логи
процессов/контейнеров → панель раскладывает их в таблицы `logs`,
`process_logs`, `container_logs`, `ssh_auth_logs`, `auth_logs`; retention
чистит по возрасту (`monitoring/includes/retention.php`,
`scripts/cleanup_logs.php`).

## 2. Целевая модель: 4 канала

| Канал          | Источник                         | Потребитель        | Хранение         |
|----------------|----------------------------------|--------------------|------------------|
| `app`          | агент, веб-сервер, PHP, jobs     | journald / stdout  | journald         |
| `access`       | HTTP-доступ (web server)         | журнал / файл      | ротация          |
| `audit`        | действия администратора (PHP)    | таблица БД         | БД + retention   |
| `node_telemetry` | логи процессов/контейнеров агента | таблицы БД      | БД + retention   |

Системные логи (app/access) не пишутся в БД. Пользовательские (audit /
node_telemetry) живут в БД и имеют UI-фильтры.

## 3. Формат и уровни

Единый формат строки журнала:

```
[origin] <ISO-8601> <LEVEL> <message>
```

- `origin` — `agent`, `web`, `php`, `jobs`.
- `LEVEL` — `DEBUG` < `INFO` < `WARNING` < `ERROR`.

| Уровень   | Что писать                                                     |
|-----------|----------------------------------------------------------------|
| `DEBUG`   | детали цикла, ответы API, heartbeat-успех, дампы конфигурации |
| `INFO`    | старт/остановка, смена состояния, найденная команда           |
| `WARNING` | разовая деградация (UPnP/LLDP недоступны, TLS off, таймаут)   |
| `ERROR`   | провал отправки, фатальная ошибка PHP, исключение             |

Правила:

- Ничего не теряем: если `error_log()`/`sys.stderr` пишет — это попадает в
  journal. Потери допустимы только при явной обрезке с пометкой `…[обрезано]`.
- Уровень повторяющихся сообщений понижается, а не удаляется: их всегда можно
  включить через `LOG_LEVEL=debug`.
- Одно событие — одна строка. Баннеры и self-check вырезаются.

## 4. Конфигурация

Агент читает `LOG_LEVEL` из окружения (его поднимает `load_node_conf()` из
`node.conf`):

```
LOG_LEVEL=debug   # debug | info | warning | error, по умолчанию info
```

Пример `node.conf`:

```
MASTER_URL=https://hostmonitor-access-02.duckdns.org
NODE_NAME=msk-gate-01
LOG_LEVEL=info
```

## 5. Что сделано в P0

- `[P0]` `agent/runtime.py`: уровни `_LOG_LEVELS`, фильтрация по `LOG_LEVEL`,
  `flush=True`, однократное TLS-предупреждение (`_tls_warning_logged`).
- `[P0]` `agent/main.py`: цикловые логи → `debug`, провалы → `error`,
  деградация → `warning`; стартовые `print()` заменены на `_log()`.
- `[P0]` `agent/transport.py`: «Sending metrics», «Metrics sent», heartbeat,
  command-check и command-status переведены в `debug`; ошибки отправки — в
  `error`, таймауты/сбои — в `warning`.
- `[P0]` `agent/logs.py`, `agent/topology.py`, `agent/network.py`,
  `agent/updater.py`: периодический INFO-шум (`collect_logs`/`send_logs`
  totals, счётчики UPnP/LLDP, пассивные LLDP-соседи, отчёт об апдейтах) →
  `debug`; сбои → `error`/`warning`.
- `[P0]` `scripts/python_web_server.py`: `_log_php_stderr()` — stderr php-cgi
  всегда пишется в journal; фатальные ошибки — одна строка `[php ERROR]`.
- `[P0]` `monitoring/api/nodes.php`, `updates.php`, `processes.php`: удалены
  отладочные баннеры и self-check-запросы, оставшиеся `error_log()` получили
  тег.

Эффект на МСК (msk-gate-01): журнал агента при `LOG_LEVEL=info` содержит
только старт/состояние и одну строку TLS-warning; PHP-`Notice` панели
(например `smart.php`) больше не теряются.

## 6. Дорожная карта

- `[P0]` Не терять логи, убрать шум и self-check, ввести уровни/flush.
- `[P1]` Ввести общий формат `[origin] <ISO> <LEVEL>` для PHP и веб-сервера
  (обёртка над `error_log`, `request_id` на запрос), причёсать
  `agent/lldp_discovery.py`.
- `[P2]` Убрать дубль auth-логов (`monitoring/auth_logs.php` и вкладка `auth`
  в `logs.php` читают одну таблицу `auth_logs`), свести UI-фильтры.
- `[P3]` Разделить каналы `access`/`app` при росте нагрузки (вынести access
  log в отдельный файл с ротацией), при необходимости — централизация в
  journald-remote / Loki.

## 7. Границы

- Формат пользовательских логов (то, что показывает вкладка логов) — контракт
  API агент↔панель, в P0 не меняется.
- Retention и схема таблиц в P0 не затрагиваются.
- Полные тексты логов процессов/апдейтов на уровне `debug` допустимы, но
  всегда обрезаются до разумного лимита при передаче.
