@echo off
echo Building GameSync NonSteam plugin...

REM Проверка Node.js
where node >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Node.js not found! Please install Node.js from https://nodejs.org/
    pause
    exit /b 1
)

REM Установка/обновление зависимостей
echo Installing/updating dependencies...
call npm.cmd install
if %errorlevel% neq 0 (
    echo ERROR: Failed to install dependencies
    pause
    exit /b 1
)

REM Сборка проекта
echo Building project...
call npm.cmd run build
if %errorlevel% neq 0 (
    echo ERROR: Build failed
    pause
    exit /b 1
)

REM Создание папки для упаковки
if exist dist-plugin rmdir /s /q dist-plugin
mkdir dist-plugin
mkdir dist-plugin\gamesync-nonsteam

REM Копирование файлов в подпапку плагина
echo Copying files...
mkdir dist-plugin\gamesync-nonsteam\dist
copy dist\index.js dist-plugin\gamesync-nonsteam\dist\index.js
xcopy /E /I /Y backend dist-plugin\gamesync-nonsteam\backend
if exist dist\assets xcopy /E /I /Y dist\assets dist-plugin\gamesync-nonsteam\assets
copy main.py dist-plugin\gamesync-nonsteam\
copy plugin.json dist-plugin\gamesync-nonsteam\
copy package.json dist-plugin\gamesync-nonsteam\
copy requirements.txt dist-plugin\gamesync-nonsteam\
copy install_plugin.sh dist-plugin\gamesync-nonsteam\
copy LICENSE dist-plugin\gamesync-nonsteam\

REM Создание архива с подпапкой плагина
echo Creating archive...
powershell -NoProfile -Command "$distPluginPath = (Resolve-Path 'dist-plugin').Path; $zipPath = Join-Path (Get-Location).Path 'gamesync-nonsteam.zip'; if (Test-Path $zipPath) { Remove-Item $zipPath -Force }; Add-Type -AssemblyName System.IO.Compression.FileSystem; $zip = [System.IO.Compression.ZipFile]::Open($zipPath, 1); try { Get-ChildItem -Path $distPluginPath -Recurse -File | ForEach-Object { $relativePath = $_.FullName.Substring($distPluginPath.Length + 1).Replace('\', '/'); [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $relativePath) | Out-Null } } finally { $zip.Dispose() }"

echo Build complete! Archive: gamesync-nonsteam.zip
