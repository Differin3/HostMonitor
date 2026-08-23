#!/bin/bash
# Починка обновления панели из UI (git fetch).
set -euo pipefail

ROOT="${1:-/opt/monitoring}"
WRAPPER="${ROOT}/scripts/panel_git.sh"
CFG="${ROOT}/monitoring/data/panel.local.php"
SERVICE_USER="monitoring"

echo "[fix_panel_git] root=${ROOT}"

if [[ ! -d "${ROOT}/.git" ]]; then
  echo "ERROR: ${ROOT} is not a git repo"
  exit 1
fi

if ! id "${SERVICE_USER}" &>/dev/null; then
  sudo useradd -r -s /usr/sbin/nologin -d "${ROOT}" "${SERVICE_USER}" || true
fi

sudo chown -R "${SERVICE_USER}:${SERVICE_USER}" "${ROOT}"
sudo chmod 755 "${WRAPPER}" 2>/dev/null || true

# safe.directory для monitoring и root
sudo -u "${SERVICE_USER}" git config --global --add safe.directory "${ROOT}" 2>/dev/null || true
sudo git config --global --add safe.directory "${ROOT}" 2>/dev/null || true

# Кто реально запускает PHP (python web / php-fpm)
PHP_USERS=()
for u in monitoring www-data nginx root; do
  id "$u" &>/dev/null && PHP_USERS+=("$u")
done

SUDOERS="/etc/sudoers.d/hostmonitor-panel-git"
{
  echo "# HostMonitor panel update"
  for u in "${PHP_USERS[@]}"; do
    if [[ "$u" != "${SERVICE_USER}" ]]; then
      echo "${u} ALL=(${SERVICE_USER}) NOPASSWD: ${WRAPPER}"
    fi
  done
} | sudo tee "${SUDOERS}" >/dev/null
sudo chmod 440 "${SUDOERS}"
sudo visudo -cf "${SUDOERS}"

mkdir -p "$(dirname "${CFG}")"
if [[ ! -f "${CFG}" ]]; then
  sudo tee "${CFG}" >/dev/null <<EOF
<?php
return [
    'repo_root' => '${ROOT}',
    'git_wrapper' => '${WRAPPER}',
    'git_sudo_user' => '${SERVICE_USER}',
];
EOF
else
  echo "[fix_panel_git] config exists: ${CFG}"
fi
sudo chown "${SERVICE_USER}:${SERVICE_USER}" "${CFG}"
sudo chmod 600 "${CFG}"

echo "[fix_panel_git] test fetch as ${SERVICE_USER}..."
sudo -u "${SERVICE_USER}" -H git -C "${ROOT}" fetch origin --prune
echo "[fix_panel_git] test wrapper (как PHP)..."
sudo -u "${SERVICE_USER}" -H "${WRAPPER}" "${ROOT}" fetch origin --prune
echo "[fix_panel_git] OK"

# перезапуск веб-сервиса, если есть
if systemctl list-unit-files | grep -qE '^(monitoring-web|hostmonitor-web)\.service'; then
  UNIT=$(systemctl list-unit-files | awk '/^(monitoring-web|hostmonitor-web)\.service/{print $1; exit}')
  sudo systemctl restart "${UNIT}"
  echo "[fix_panel_git] ${UNIT} restarted"
fi

echo "Готово. В UI нажмите проверку обновления ещё раз."
