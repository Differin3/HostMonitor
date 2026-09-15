# База данных панели (MySQL / MariaDB)

Панель **сама создаёт базу и таблицы**. Вручную `CREATE DATABASE` нужен только если у пользователя MySQL нет права CREATE DATABASE.

## Как это работает

1. `install.sh` / `scripts/install_panel.sh`
   - поднимает MariaDB
   - создаёт базу `monitoring` (или `$DB_NAME`)
   - создаёт пользователя `monitoring` со случайным паролем
   - применяет `schema_mysql.sql` (таблицы)
   - пишет `monitoring/data/db.local.php`

2. В браузере открывается `setup.php`
   - если таблиц ещё нет — применяет схему
   - создаёт **администратора панели** (это не пользователь MySQL)

Агент базу не создаёт и к MySQL не подключается.

## Ручной импорт (если ставили MySQL сами)

```bash
sudo mysql --protocol=socket -u root -e "CREATE DATABASE IF NOT EXISTS monitoring CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql monitoring < schema_mysql.sql
```

Либо откройте панель: мастер первого запуска создаст базу, если у указанного пользователя MySQL есть `CREATE DATABASE`, иначе подключится к уже существующей.

Администратор панели задаётся только в мастере, не схемой.
