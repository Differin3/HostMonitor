# API контракты фронта
## Общие
- База: `/api`.
- Заголовок `Authorization: Bearer <token>`.
- Ответы `{ "data": ..., "meta": {...} }`, ошибки `{ "error": { "code": "...", "message": "...", "details": {...} } }`.

## Дашборд
- `GET /api/summary`: KPI (nodes_online, cpu_avg, alerts_active, containers_total).
- `GET /api/metrics?range=1h&group=5m`: массив точек `[ { ts, cpu, ram, disk, net_in, net_out } ]`.
- `GET /api/alerts/active`: последние 10 алертов.

## Ноды
- `GET /api/nodes?status=&search=`: список, пагинация `page,size`.
- `POST /api/nodes`: {name, host, ssh_port, tags[] } -> {id, token}.
- `GET /api/nodes/{id}`: детали, health, последняя метрика.
- `PUT /api/nodes/{id}`: обновление.
- `DELETE /api/nodes/{id}`.
- `POST /api/nodes/{id}/actions`: {action:"reboot|shutdown|sync"}.

## Процессы
- `GET /api/nodes/{id}/processes?search=&sort=cpu`: таблица.
- `POST /api/nodes/{id}/processes/{pid}/actions`: {action:"kill|restart"}.
- WebSocket `/ws/processes?node_id=` для live обновлений.

## Контейнеры
- `GET /api/nodes/{id}/containers`.
- `POST /api/nodes/{id}/containers`: запуск из образа {image, name, env, ports}.
- `POST /api/nodes/{id}/containers/{cid}/actions`: {action:"start|stop|restart|logs"}; при `logs` возвращает stream URL.

## Порты и сеть
- `GET /api/nodes/{id}/ports`: список открытых портов + процессы.
- `POST /api/nodes/{id}/ports`: управление firewall {port, proto, action:"allow|deny"}.
- `GET /api/nodes/{id}/network`: интерфейсы, скорости, ошибки.

## Логи
- `GET /api/logs?node=&level=&search=&from=&to=`: стрим, поддержка SSE.
- `POST /api/logs/export`: генерация отчёта (ссылка на файл).

## Настройки/пользователи
- `GET /api/users`, `POST /api/users`, `PUT /api/users/{id}`, `DELETE ...`.
- `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`.

## Уведомления
- `GET /api/alerts/rules`, `POST /api/alerts/rules`.
- `POST /api/alerts/test`: проверка канала (email/sms/webhook).

## Специфичные фронту
- Endpoint для загрузки файлов `POST /api/files/upload`.
- WebSocket `/ws/dashboard` для метрик и алертов.

