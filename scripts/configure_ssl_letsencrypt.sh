#!/bin/bash
# Быстрая настройка Let's Encrypt для nginx на Debian/Ubuntu
set -euo pipefail

if [ $# -lt 2 ]; then
    echo "Использование: $0 example.com admin@example.com [www.example.com ...]"
    exit 1
fi

DOMAIN="$1"
EMAIL="$2"
shift 2
EXTRA_DOMAINS=("$@")

DOMAINS=(-d "${DOMAIN}")
for d in "${EXTRA_DOMAINS[@]}"; do
    DOMAINS+=(-d "$d")
done

echo "Проверка DNS для ${DOMAIN}"
if ! getent hosts "${DOMAIN}" >/dev/null; then
    echo "Внимание: домен ${DOMAIN} пока не резолвится на этот сервер."
fi

echo "Проверка, слушает ли nginx порт 80"
if ! sudo ss -tln | grep -q ":80 "; then
    echo "Предупреждение: порт 80 не слушается (nginx не запущен?). Certbot может завершиться с ошибкой."
fi

echo "Установка certbot и плагина nginx"
sudo apt update
sudo apt install -y certbot python3-certbot-nginx

echo "Получение сертификата для доменов: ${DOMAIN} ${EXTRA_DOMAINS[*]}"
sudo certbot --nginx "${DOMAINS[@]}" --non-interactive --agree-tos -m "${EMAIL}" --redirect

echo "Включение автоматического продления сертификатов (certbot.timer)"
sudo systemctl enable --now certbot.timer

echo "Готово. Проверка: https://${DOMAIN}"

