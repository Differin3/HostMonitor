# GameSync NonSteam (PortProton Edition)

---

## 🇷🇺 Русский

Плагин для Decky Loader, синхронизирующий сохранения игр PortProton с облачным хранилищем:

- через **WebDAV** (Яндекс Диск, Nextcloud, Box, ownCloud и др.)
- через **S3‑совместимые object storage** (Yandex Object Storage, VK Cloud, Cloud.ru, Backblaze B2, Wasabi, AWS S3, DigitalOcean Spaces и др.)
- через **FTP/FTPS** или **SFTP** (собственный сервер/NAS)

### Установка

#### Сборка на Windows:

1. Установите Node.js и pnpm:
```bash
npm i -g pnpm@9
```

2. Установите зависимости:
```bash
pnpm install
```

3. Соберите проект:
```bash
pnpm run build
```

4. Создайте архив (запустите `build.bat` или вручную):
- Создайте папку `dist-plugin`
- Скопируйте: `dist/`, `backend/`, `plugin.json`, `requirements.txt`
- Заархивируйте в `gamesync-nonsteam.zip`

#### Установка на Steam Deck:

1. Скопируйте `gamesync-nonsteam.zip` на Steam Deck
2. Откройте Decky Loader → Developer → Install from ZIP
3. Выберите архив и установите

Python‑зависимости (requests, boto3, paramiko, psutil) плагин устанавливает
сам при первом запуске — отдельно ничего ставить не нужно. Нужен только
доступ в интернет при первом запуске (один раз).

### Настройка

1. Откройте настройки плагина в Decky Loader.
2. В блоке **Тип хранилища** выберите:
   - **WebDAV**, если используете Яндекс Диск / Nextcloud / Box / ownCloud и т.п.
   - **S3 (Object Storage)**, если используете Yandex Object Storage, VK Cloud, Cloud.ru, Backblaze B2, Wasabi, AWS S3, DigitalOcean Spaces и др.
   - **FTP/FTPS**, если используете обычный FTP‑сервер (при необходимости включите TLS и пассивный режим).
   - **SFTP**, если используете сервер с доступом по SSH (пароль или приватный ключ).
3. Для **WebDAV**:
   - выберите провайдера (например, Яндекс Диск или Nextcloud);
   - укажите WebDAV URL, логин/пароль или OAuth‑токен провайдера;
   - нажмите «Тест подключения» и убедитесь, что соединение успешно.
4. Для **S3**:
   - выберите S3‑провайдера (например, Yandex Object Storage, VK Cloud, Backblaze B2);
   - укажите **Bucket**, при необходимости поправьте Endpoint и Region;
   - введите Access Key и Secret Key;
   - нажмите «Тест подключения S3» и убедитесь, что соединение успешно.
5. Для **FTP/FTPS**:
   - укажите хост и порт (по умолчанию 21), логин и пароль;
   - включите «Использовать TLS (FTPS)», если сервер поддерживает шифрование;
   - нажмите «Тест подключения FTP» и убедитесь, что соединение успешно.
6. Для **SFTP**:
   - укажите хост и порт (по умолчанию 22), логин и пароль или путь к приватному ключу;
   - при необходимости укажите passphrase ключа;
   - нажмите «Тест подключения SFTP» и убедитесь, что соединение успешно.
7. Настройте пути сохранений для игр (авто‑поиск + ручные пути при необходимости).

### Использование

- Плагин автоматически сканирует игры PortProton
- Для каждой игры можно настроить пути сохранений
- Нажмите "Синхронизировать" для загрузки сохранений в облако
- Включите автосинхронизацию для автоматической загрузки при выходе из игры

### Как плагин находит игры и сохранения

- **Игры**: `.desktop`‑файлы PortProton (с локализованными именами), префиксы без ярлыков и сопоставление со **Steam‑ярлыками** (appid/StartDir).
- **Сохранения**: база OpenCloudSaves + **Ludusavi** (манифест скачивается и кэшируется автоматически) + эвристический поиск по `drive_c` (Documents, Saved Games, AppData, ProgramData).
- Для каждой игры показываются найденные **кандидаты с оценкой**; ненужные можно исключить из синхронизации.
- **Ручные пути запоминаются** и предлагаются похожим играм.
- Игры с общим префиксом помечаются предупреждением.

### Структура проекта

- `backend/` - Python бэкенд
- `src/` - React фронтенд
- `plugin.json` - конфигурация плагина
- `requirements.txt` - Python зависимости

---

## 🇬🇧 English

Plugin for Decky Loader that synchronizes PortProton game saves with cloud storage:

- via **WebDAV** (Yandex Disk, Nextcloud, Box, ownCloud, etc.)
- via **S3‑compatible object storage** (Yandex Object Storage, VK Cloud, Cloud.ru, Backblaze B2, Wasabi, AWS S3, DigitalOcean Spaces, etc.)
- via **FTP/FTPS** or **SFTP** (your own server/NAS)

### Installation

#### Building on Windows:

1. Install Node.js and pnpm:
```bash
npm i -g pnpm@9
```

2. Install dependencies:
```bash
pnpm install
```

3. Build the project:
```bash
pnpm run build
```

4. Create archive (run `build.bat` or manually):
- Create `dist-plugin` folder
- Copy: `dist/`, `backend/`, `plugin.json`, `requirements.txt`
- Archive into `gamesync-nonsteam.zip`

#### Installation on Steam Deck:

1. Copy `gamesync-nonsteam.zip` to Steam Deck
2. Open Decky Loader → Developer → Install from ZIP
3. Select archive and install

The plugin installs its Python dependencies (requests, boto3, paramiko, psutil)
automatically on first launch — no manual steps required. Only internet access
is needed the first time.

### Configuration

1. Open plugin settings in Decky Loader.
2. In the **Storage type** block choose:
   - **WebDAV** if you use Yandex Disk / Nextcloud / Box / ownCloud, etc.
   - **S3 (Object Storage)** if you use Yandex Object Storage, VK Cloud, Cloud.ru, Backblaze B2, Wasabi, AWS S3, DigitalOcean Spaces, etc.
   - **FTP/FTPS** for a regular FTP server (enable TLS and passive mode if needed).
   - **SFTP** for an SSH-accessible server (password or private key).
3. For **WebDAV**:
   - choose a provider (e.g. Yandex Disk or Nextcloud);
   - enter WebDAV URL, username/password or provider OAuth token;
   - click “Test connection” and ensure it succeeds.
4. For **S3**:
   - choose an S3 provider (e.g. Yandex Object Storage, VK Cloud, Backblaze B2);
   - enter **Bucket**, adjust Endpoint and Region if needed;
   - enter Access Key and Secret Key;
   - click “Test S3 connection” and ensure it succeeds.
5. For **FTP/FTPS**:
   - enter host and port (default 21), username and password;
   - enable “Use TLS (FTPS)” if the server supports encryption;
   - click “Test FTP connection” and ensure it succeeds.
6. For **SFTP**:
   - enter host and port (default 22), username and password or a private key path;
   - provide the key passphrase if needed;
   - click “Test SFTP connection” and ensure it succeeds.
7. Configure save paths for games (auto‑detected or custom paths).

### Usage

- Plugin automatically scans PortProton games
- Save paths can be configured for each game
- Click "Synchronize" to upload saves to cloud
- Enable auto-sync for automatic upload when exiting game

### How the plugin finds games and saves

- **Games**: PortProton `.desktop` files (with localized names), prefixes without shortcuts, and matching against **Steam shortcuts** (appid/StartDir).
- **Saves**: OpenCloudSaves database + **Ludusavi** (manifest downloaded and cached automatically) + heuristic search inside `drive_c` (Documents, Saved Games, AppData, ProgramData).
- Each game shows detected **candidates with a score**; unwanted ones can be excluded from sync.
- **Manual paths are remembered** and suggested for similar games.
- Games sharing a prefix are flagged with a warning.

### Project Structure

- `backend/` - Python backend
- `src/` - React frontend
- `plugin.json` - plugin configuration
- `requirements.txt` - Python dependencies
