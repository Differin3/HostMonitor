# API контракты фронта
## Общие
- База: `/api/*.php` (PHP-скрипты в `monitoring/api`).
- Аутентификация UI: cookie-сессия PHP (`$_SESSION['user_id']`), агенты: `Authorization: Bearer <node_token>`.
- Базовый формат: успешный ответ `{...}`, ошибки `{ "error": "Сообщение" }` (без вложенных `code/details`).

## Дашборд и метрики
- `GET /api/summary.php` → `{ summary: {...} }` — агрегированные KPI (онлайн-ноды, алерты, контейнеры и т.п.).
- `GET /api/metrics.php?node_id=&range=&limit=` → `{ data: [ { ts, cpu, ram, memory, disk, network_in, network_out } ] }`.
- `POST /api/metrics.php` (агент) → JSON `{metrics:{...}, node_id?}` c токеном ноды, создаёт запись в `metrics`.

## Ноды
- `GET /api/nodes.php` → `{ nodes: [...] }` — список с провайдерами, uptime, ping.
- `GET /api/nodes.php?id={id}` → `{ node: {...} }` — детали ноды.
- `GET /api/nodes.php?id={id}&action=network` → `{ network: {...} }` — сеть + порты по ноде.
- `GET /api/nodes.php?action=get-command` (агент) → `{status, command, command_status, command_timestamp}`.
- `POST /api/nodes.php` → создание ноды `{ name, host, port, ... }` → `{ id, node_token }`.
- `PUT /api/nodes.php?id={id}` → обновление ноды.
- `DELETE /api/nodes.php?id={id}` → удаление.
- `POST /api/nodes.php?id={id}&action=reboot|shutdown|sync` → постановка команды ноде.
- `POST /api/nodes.php?id={name|id}&action=command-status` (агент) → `{ status: "ok", command_status }`.

## Процессы
- `GET /api/processes.php?node_id=` → `{ processes: [...] }` — список процессов по ноде.
- `POST /api/processes.php` (агент) → `{ message: "Processes updated" }`, тело `{ processes:[...], node_id? }` или массив процессов.
- `POST /api/processes.php?node_id={id}&pid={pid}&action=kill|restart` → постановка команды, `{ success, message }`.

## Контейнеры
- `GET /api/containers.php?node_id=` → `{ containers: [...] }`.
- `GET /api/containers.php?node_id=&container_id=&logs=1&limit=` → `{ logs: [...] }`.
- `GET /api/containers.php?node_id=&network=1` → `{ networks:[...], connections:[...], ports:[...] }` — тестовые сетевые данные.
- `POST /api/containers.php` (агент) → `{ message, count }`, тело: массив контейнеров или `{containers:[...]}`.
- `POST /api/containers.php?node_id={id}&container_id={cid}&action=start|stop|restart|logs` → постановка docker-команды или URL логов.

## Порты и сеть
- `GET /api/ports.php?node_id=` → `{ ports: [...] }` — порты с полями `id,node_id,port,type,process_name,pid,status,node_name`.
- `POST /api/ports.php` (агент) → `{ message, count }`, тело: массив портов или `{ports:[...]}`.
- `POST /api/ports.php?node_id={id}&action=allow|deny` → управление firewall, тело `{port, proto, action}`.

## Логи
- `GET /api/logs.php?node_id=&level=&limit=&type=processes&pid=` → `{ logs: [...] }`.
- `POST /api/logs.php` (агент) → `{ message, count }`, тело: массив логов, `{logs:[...]}` или один лог.
