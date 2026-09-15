#!/bin/bash
# Скрипт для правильной установки плагина GameSync NonSteam

PLUGIN_DIR="$HOME/homebrew/plugins"
PLUGIN_NAME="gamesync-nonsteam"
ARCHIVE="$HOME/Downloads/gamesync-nonsteam.zip"

echo "=== Установка плагина GameSync NonSteam ==="
echo ""

# Проверка архива
if [ ! -f "$ARCHIVE" ]; then
    echo "✗ Архив не найден: $ARCHIVE"
    exit 1
fi

echo "✓ Архив найден: $ARCHIVE"
echo ""

# Удаление старой установки
if [ -d "$PLUGIN_DIR/$PLUGIN_NAME" ]; then
    echo "Удаление старой установки..."
    sudo rm -rf "$PLUGIN_DIR/$PLUGIN_NAME"
fi

# Удаление файлов из корня (если есть)
echo "Очистка корня plugins/ от файлов плагина..."
sudo rm -f "$PLUGIN_DIR/index.js" "$PLUGIN_DIR/main.py" "$PLUGIN_DIR/plugin.json" 2>/dev/null
sudo rm -rf "$PLUGIN_DIR/backend" 2>/dev/null

# Распаковка
echo "Распаковка архива..."
cd "$PLUGIN_DIR"
sudo unzip -o "$ARCHIVE" > /dev/null 2>&1

# Проверяем структуру архива
# Новая структура: файлы в подпапке gamesync-nonsteam/
if [ -d "$PLUGIN_DIR/gamesync-nonsteam" ]; then
    echo "✓ Архив содержит подпапку gamesync-nonsteam/"
    if [ "$PLUGIN_DIR/gamesync-nonsteam" != "$PLUGIN_DIR/$PLUGIN_NAME" ]; then
        echo "Переименование подпапки..."
        sudo mv "$PLUGIN_DIR/gamesync-nonsteam" "$PLUGIN_DIR/$PLUGIN_NAME"
    fi
# Старая структура: файлы в корне
elif [ -f "$PLUGIN_DIR/index.js" ] && [ ! -d "$PLUGIN_DIR/$PLUGIN_NAME" ]; then
    echo "Файлы распакованы в корень (старая структура), перемещение в подпапку..."
    sudo mkdir -p "$PLUGIN_DIR/$PLUGIN_NAME"
    
    # Перемещаем основные файлы
    sudo mv index.js main.py plugin.json "$PLUGIN_DIR/$PLUGIN_NAME/" 2>/dev/null
    sudo mv package.json requirements.txt "$PLUGIN_DIR/$PLUGIN_NAME/" 2>/dev/null
    sudo mv backend "$PLUGIN_DIR/$PLUGIN_NAME/" 2>/dev/null
    
    # Перемещаем дополнительные файлы
    for file in check_decky_version.sh check_plugin.sh clean_decky_cache.sh FIX_INSTALL.sh fix_location.sh install_decky.sh install_deps.sh install_plugin.sh setup_plugin.sh test_decky.sh LICENSE; do
        if [ -f "$PLUGIN_DIR/$file" ]; then
            sudo mv "$PLUGIN_DIR/$file" "$PLUGIN_DIR/$PLUGIN_NAME/" 2>/dev/null
        fi
    done
fi

# Проверка установки
if [ ! -d "$PLUGIN_DIR/$PLUGIN_NAME" ]; then
    echo "✗ Ошибка: плагин не установлен"
    exit 1
fi

# Устанавливаем права
echo "Установка прав доступа..."
sudo chown -R root:root "$PLUGIN_DIR/$PLUGIN_NAME"
sudo chmod +x "$PLUGIN_DIR/$PLUGIN_NAME/main.py" 2>/dev/null
sudo chmod +x "$PLUGIN_DIR/$PLUGIN_NAME/index.js" 2>/dev/null

# Проверка структуры
echo ""
echo "Проверка установки:"
if [ -f "$PLUGIN_DIR/$PLUGIN_NAME/index.js" ]; then
    echo "  ✓ index.js"
else
    echo "  ✗ index.js не найден"
fi

if [ -f "$PLUGIN_DIR/$PLUGIN_NAME/main.py" ]; then
    echo "  ✓ main.py"
else
    echo "  ✗ main.py не найден"
fi

if [ -f "$PLUGIN_DIR/$PLUGIN_NAME/plugin.json" ]; then
    echo "  ✓ plugin.json"
    echo "  Содержимое:"
    cat "$PLUGIN_DIR/$PLUGIN_NAME/plugin.json"
else
    echo "  ✗ plugin.json не найден"
fi

if [ -d "$PLUGIN_DIR/$PLUGIN_NAME/backend" ]; then
    echo "  ✓ backend/"
else
    echo "  ✗ backend/ не найден"
fi

echo ""
echo "Перезапуск Decky Loader..."
sudo pkill -f PluginLoader

echo ""
echo "=== Установка завершена ==="
echo "Плагин установлен в: $PLUGIN_DIR/$PLUGIN_NAME/"
