#!/bin/bash
# Скрипт для перезапуска всех сервисов мониторинга

echo "Остановка сервисов..."
sudo systemctl stop monitoring-agent
sudo systemctl stop monitoring-web

echo "Ожидание завершения процессов..."
sleep 2

echo "Запуск сервисов..."
sudo systemctl start monitoring-web
sudo systemctl start monitoring-agent

echo "Ожидание запуска..."
sleep 3

echo "Статус сервисов:"
echo "=================="
sudo systemctl status monitoring-web --no-pager -l
echo ""
sudo systemctl status monitoring-agent --no-pager -l

echo ""
echo "Последние логи monitoring-web:"
echo "=================="
sudo journalctl -u monitoring-web -n 20 --no-pager

echo ""
echo "Последние логи monitoring-agent:"
echo "=================="
sudo journalctl -u monitoring-agent -n 20 --no-pager

