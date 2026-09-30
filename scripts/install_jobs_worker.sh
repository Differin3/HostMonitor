#!/usr/bin/env bash
# Установка и запуск systemd-юнита воркера фоновых задач.
#
# Отдельный скрипт, потому что install_panel.sh запускается только при
# первой установке, а обновление панели делает git pull и юнит не
# трогает. Скрипт идемпотентен: его можно запускать повторно после
# обновлений, он ничего не ломает и не дублирует настройку.
#
#   sudo bash scripts/install_jobs_worker.sh
#
# Аргументы (необязательно): путь к каталогу панели и пользователь
# сервиса. По умолчанию берутся из каталога скрипта и из владельца
# каталога мониторинга.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INSTALL_DIR="${1:-$(dirname "${SCRIPT_DIR}")}"
SERVICE_USER="${2:-$(stat -c '%U' "${INSTALL_DIR}/monitoring" 2>/dev/null || echo root)}"
UNIT_NAME="hostmonitor-jobs"
UNIT_SRC="${SCRIPT_DIR}/../systemd/${UNIT_NAME}.service"
UNIT_DST="/etc/systemd/system/${UNIT_NAME}.service"

if [ "$(id -u)" -ne 0 ]; then
    echo "[jobs_worker] Нужен root: sudo bash $0" >&2
    exit 1
fi

if [ ! -f "${UNIT_SRC}" ]; then
    echo "[jobs_worker] Не найден юнит ${UNIT_SRC}" >&2
    exit 1
fi

PHP_BIN="$(command -v php || echo /usr/bin/php)"
if [ ! -x "${PHP_BIN}" ]; then
    echo "[jobs_worker] Не найден интерпретатор PHP (${PHP_BIN})" >&2
    exit 1
fi

if [ ! -f "${INSTALL_DIR}/scripts/job_worker.php" ]; then
    echo "[jobs_worker] В ${INSTALL_DIR} нет scripts/job_worker.php — сначала выполните git pull" >&2
    exit 1
fi

# Обращения к systemd идут через таймаут: в контейнерах и на части
# окружений systemctl не возвращает управление и молча висит, из-за чего
# установщик панели тоже stuck. Здесь это должно падать с диагностикой.
SYSTEMCTL_TIMEOUT="${SYSTEMCTL_TIMEOUT:-15}"
sc() { timeout "${SYSTEMCTL_TIMEOUT}" systemctl "$@"; }

# Проверяем systemd до правки файлов, иначе на машине без systemd
# останется юнит, который всё равно не запустится.
if ! sc daemon-reload >/dev/null 2>&1; then
    echo "[jobs_worker] systemd не отвечает (daemon-reload не прошёл за ${SYSTEMCTL_TIMEOUT}s)." >&2
    echo "[jobs_worker] Похоже, система загружена без systemd — юнит вручную не поставить." >&2
    exit 1
fi

echo "[jobs_worker] Установка ${UNIT_NAME}: каталог=${INSTALL_DIR}, пользователь=${SERVICE_USER}"

install -m 644 "${UNIT_SRC}" "${UNIT_DST}"
sed -i "s|WorkingDirectory=.*|WorkingDirectory=${INSTALL_DIR}|g" "${UNIT_DST}"
sed -i "s|ExecStart=.*|ExecStart=${PHP_BIN} ${INSTALL_DIR}/scripts/job_worker.php|g" "${UNIT_DST}"
if grep -q "^User=" "${UNIT_DST}"; then
    sed -i "s|^User=.*|User=${SERVICE_USER}|g" "${UNIT_DST}"
else
    sed -i "/^\[Service\]/a User=${SERVICE_USER}" "${UNIT_DST}"
fi

# Каталог данных должен остаться доступен воркеру: там lock-файл и
# heartbeat, по которому панель определяет, что воркер жив. Права на
# остальные файлы не трогаем — это данные панели.
if id "${SERVICE_USER}" &>/dev/null; then
    mkdir -p "${INSTALL_DIR}/monitoring/data"
    chown "${SERVICE_USER}" "${INSTALL_DIR}/monitoring/data" 2>/dev/null || true
else
    echo "[jobs_worker] ВНИМАНИЕ: пользователя ${SERVICE_USER} нет, юнит упадёт при старте" >&2
fi

# Обращения к systemd идут через таймаут: в контейнерах и на части
# окружений systemctl не возвращает управление и молча висит, из-за чего
# установщик панели тоже stuck. Здесь это должно падать с диагностикой.
if ! sc enable "${UNIT_NAME}" >/dev/null 2>&1; then
    echo "[jobs_worker] Не удалось включить автозапуск ${UNIT_NAME}" >&2
    exit 1
fi
# restart, а не enable --now: юнит уже может работать, а новый
# job_worker.php должен подхватить обновлённый код.
if ! sc restart "${UNIT_NAME}"; then
    echo "[jobs_worker] Не удалось перезапустить ${UNIT_NAME}" >&2
    sc --no-pager --lines=20 status "${UNIT_NAME}" | sed 's/^/[jobs_worker] /' >&2 || true
    exit 1
fi

sleep 1
if sc is-active --quiet "${UNIT_NAME}"; then
    echo "[jobs_worker] ${UNIT_NAME}: запущен"
    sc --no-pager --lines=0 status "${UNIT_NAME}" | sed 's/^/[jobs_worker] /' || true
    exit 0
fi

echo "[jobs_worker] ВНИМАНИЕ: ${UNIT_NAME} не стартовал" >&2
sc --no-pager --lines=20 status "${UNIT_NAME}" | sed 's/^/[jobs_worker] /' >&2 || true
journalctl -u "${UNIT_NAME}" --no-pager --lines=20 | sed 's/^/[jobs_worker] /' >&2 || true
exit 1
