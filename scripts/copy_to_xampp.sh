#!/bin/bash
# Скрипт копирования в XAMPP для Linux (если XAMPP установлен) – из каталога xampp_dev

XAMPP_PATH="/opt/lampp/htdocs"

if [ ! -d "$XAMPP_PATH" ]; then
    echo "Ошибка: XAMPP не найден в $XAMPP_PATH"
    echo "Укажите правильный путь к XAMPP"
    exit 1
fi

echo "Копирование monitoring (PHP) из xampp_dev..."
cp -r "$(dirname "$0")/../xampp_dev/monitoring" "$XAMPP_PATH/monitoring"  # копируем PHP-приложение

echo "Копирование frontend (статические файлы) из xampp_dev..."
cp -r "$(dirname "$0")/../xampp_dev/frontend" "$XAMPP_PATH/frontend"      # копируем фронтенд

echo "Готово! Откройте http://localhost/monitoring"

