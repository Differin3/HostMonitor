# XAMPP dev copy

- `frontend/` — клон папки `frontend` для статического контента
- `monitoring/` — клон `master/web` с PHP API

Развёртывание (панель прямо из корня `htdocs`):

1. Скопируй файлы из `xampp_dev/monitoring` непосредственно в `C:\xampp\htdocs` (без подпапки)
2. Скопируй `xampp_dev/frontend` в `C:\xampp\htdocs\frontend`
3. Импортируй `schema_mysql.sql` в MySQL и запусти Apache+MySQL в XAMPP
4. Открой `http://localhost/` — данные и стили будут подгружаться по относительным путям

