# Установка в XAMPP

## Быстрый старт

1. Скопировать всю папку `master/web` в `C:\xampp\htdocs\monitoring`
2. Скопировать папку `frontend` в `C:\xampp\htdocs\frontend` (на уровень выше monitoring)
3. Открыть браузер: `http://localhost/monitoring`

## Структура после копирования

```
C:\xampp\htdocs\
├── monitoring\          ← скопировать master/web сюда
│   ├── index.php
│   ├── login.php
│   ├── includes\
│   └── .htaccess
└── frontend\            ← скопировать frontend сюда
    ├── css\
    ├── js\
    └── ...
```

## Настройка API

По умолчанию API ожидается на `http://127.0.0.1:8000/api`

Изменить можно в `monitoring/includes/config.php`:

```php
'api_url' => 'http://ваш-api-сервер:8000/api',
```

## Логин по умолчанию

- Логин: `admin`
- Пароль: `admin`

## Проверка работы

1. Открыть `http://localhost/monitoring/login.php`
2. Войти с учетными данными
3. Проверить что дашборд загружается
4. Открыть консоль браузера (F12) и проверить отсутствие ошибок

## Решение проблем

### Стили не загружаются
- Проверить что папка `frontend` скопирована в `htdocs/frontend`
- Проверить права доступа к файлам

### API не работает
- Убедиться что API сервер запущен на порту 8000
- Проверить URL в `includes/config.php`
- Проверить CORS настройки API сервера

### Ошибка 404
- Проверить что mod_rewrite включен в Apache
- Проверить файл `.htaccess` в папке monitoring

