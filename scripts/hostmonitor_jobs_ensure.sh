#!/bin/bash
# Гарантирует, что воркер фоновых задач (hostmonitor-jobs) установлен и запущен.
#
# Вызывается панелью через sudo (NOPASSWD) как root — поэтому скрипт не
# принимает аргументов и не читает ничего из репозитория. Репозиторий
# принадлежит сервисному пользователю, под которым работает PHP; если бы этот
# скрипт брал код или юнит оттуда, компрометация веба дала бы root. Всё, что
# используется ниже, лежит в root-каталогах, поэтому подменять нечего.
#
# Эталонная копия юнита кладётся установщиком
# (scripts/install_jobs_worker.sh) в /usr/local/share/hostmonitor.
set -euo pipefail

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

UNIT_NAME="hostmonitor-jobs"
UNIT_DST="/etc/systemd/system/${UNIT_NAME}.service"
PRISTINE="${PRISTINE_UNIT:-/usr/local/share/hostmonitor/${UNIT_NAME}.service}"

# Обращения к systemd идут через таймаут: в контейнерах и на части окружений
# systemctl не возвращает управление и молча висит. Здесь это должно падать с
# диагностикой, а не подвешивать запрос панели.
SYSTEMCTL_TIMEOUT="${SYSTEMCTL_TIMEOUT:-15}"
sc() { timeout "${SYSTEMCTL_TIMEOUT}" systemctl "$@"; }

if [ "$(id -u)" -ne 0 ]; then
    echo "[jobs-ensure] Нужен root (скрипт запускается через sudo из панели)." >&2
    exit 1
fi

# 1) Юнит. Если его нет — ставим root-овую эталонную копию, оставленную
#    установщиком. Из репозитория юнит не берём (см. шапку).
if [ ! -f "${UNIT_DST}" ]; then
    if [ ! -f "${PRISTINE}" ]; then
        echo "[jobs-ensure] Нет юнита ${UNIT_DST} и эталона ${PRISTINE}. Выполните один раз на сервере: sudo bash /opt/monitoring/scripts/install_jobs_worker.sh" >&2
        exit 2
    fi
    install -m 644 "${PRISTINE}" "${UNIT_DST}"
fi

# 2) Перечитать конфиг systemd и включить автозапуск. enable может не пройти,
#    если юнит уже включён/замаскирован — это не повод падать.
if ! sc daemon-reload; then
    echo "[jobs-ensure] systemd не отвечает (daemon-reload)." >&2
    exit 3
fi
sc enable "${UNIT_NAME}" >/dev/null 2>&1 || true

# 3) Перезапустить, а не enable --now: юнит может уже работать, а новый
#    job_worker.php должен подхватить обновлённый код. Воркер держит состояние
#    в базе, поэтому после рестарта текущая задача продолжится с курсора.
if ! sc restart "${UNIT_NAME}"; then
    echo "[jobs-ensure] Не удалось перезапустить ${UNIT_NAME}:" >&2
    sc --no-pager --lines=20 status "${UNIT_NAME}" | sed 's/^/[jobs-ensure] /' >&2 || true
    exit 4
fi

sleep 1
if sc is-active --quiet "${UNIT_NAME}"; then
    echo "[jobs-ensure] ${UNIT_NAME}: запущен"
    exit 0
fi

echo "[jobs-ensure] ${UNIT_NAME} не активен после перезапуска:" >&2
sc --no-pager --lines=20 status "${UNIT_NAME}" | sed 's/^/[jobs-ensure] /' >&2 || true
exit 5
