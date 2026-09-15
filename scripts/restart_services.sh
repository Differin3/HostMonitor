#!/bin/bash
# Скрипт для перезапуска всех сервисов мониторинга

echo "=== Остановка сервисов ==="

# Останавливаем агент
echo "Остановка monitoring-agent..."
sudo systemctl stop monitoring-agent

# Останавливаем веб-сервер
echo "Остановка monitoring-web..."
sudo systemctl stop monitoring-web

# Ждем немного
sleep 2

echo ""
echo "=== Запуск сервисов ==="

# Запускаем веб-сервер
echo "Запуск monitoring-web..."
sudo systemctl start monitoring-web

# Запускаем агент
echo "Запуск monitoring-agent..."
sudo systemctl start monitoring-agent

# Ждем немного
sleep 2

echo ""
echo "=== Статус сервисов ==="

# Проверяем статус
echo "Статус monitoring-web:"
sudo systemctl status monitoring-web --no-pager -l

echo ""
echo "Статус monitoring-agent:"
sudo systemctl status monitoring-agent --no-pager -l

echo ""
echo "=== Логи (последние 20 строк) ==="
echo "Логи monitoring-web:"
sudo journalctl -u monitoring-web -n 20 --no-pager

echo ""
echo "Логи monitoring-agent:"
sudo journalctl -u monitoring-agent -n 20 --no-pager

echo ""
echo "=== Готово ==="

