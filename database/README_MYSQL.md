# Развертывание базы данных MySQL

## Установка

1. Убедитесь что MySQL установлен и запущен
2. Создайте базу данных и импортируйте схему:

```bash
mysql -u root -p < schema_mysql.sql
```

Или через MySQL Workbench:
- Откройте `schema_mysql.sql`
- Выполните скрипт

## Настройка подключения

В файле `master/web/includes/database.php` настройте параметры подключения:

```php
$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'monitoring';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
```

Или установите переменные окружения:
- `DB_HOST` - хост MySQL (по умолчанию: localhost)
- `DB_PORT` - порт MySQL (по умолчанию: 3306)
- `DB_NAME` - имя базы данных (по умолчанию: monitoring)
- `DB_USER` - пользователь MySQL (по умолчанию: root)
- `DB_PASSWORD` - пароль MySQL (по умолчанию: пустой)

## Тестовые данные

После импорта схемы в базе будут:
- Пользователь: `admin` / пароль: `admin`
- 3 провайдера (firstbyte.ru, cloud4box.com, my.aeza.net)
- 3 ноды с биллингом
- Тестовые платежи, метрики, процессы и контейнеры

## Проверка

После импорта проверьте подключение:
```php
<?php
require_once 'includes/database.php';
$pdo = getDbConnection();
echo "Подключение успешно!";
?>
```

