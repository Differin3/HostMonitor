#!/bin/bash
# Разовая починка: git «dubious ownership» для HostMonitor на ноде.
# Запускать от root на каждой ноде, где update-agent падает с safe.directory.
set -euo pipefail

ROOT="${1:-}"
if [[ -z "$ROOT" ]]; then
  if [[ -d /opt/monitoring/.git ]]; then
    ROOT=/opt/monitoring
  elif [[ -d /mnt/NAS/HostMonitor/.git ]]; then
    ROOT=/mnt/NAS/HostMonitor
  else
    echo "usage: $0 /path/to/HostMonitor" >&2
    exit 1
  fi
fi

echo "[fix_git_safe] root=${ROOT}"
git -c "safe.directory=${ROOT}" -c safe.directory=\* -C "${ROOT}" config --local --add safe.directory "${ROOT}" || true
git config --global --add safe.directory "${ROOT}" || true
git config --system --add safe.directory "${ROOT}" || true

# Обновить код и перезапустить агент
git -c "safe.directory=${ROOT}" -c safe.directory=\* -C "${ROOT}" fetch origin --prune
git -c "safe.directory=${ROOT}" -c safe.directory=\* -C "${ROOT}" reset --hard origin/main
echo "[fix_git_safe] HEAD=$(git -c safe.directory=\* -C "${ROOT}" rev-parse --short HEAD)"

if systemctl cat monitoring-agent.service >/dev/null 2>&1; then
  # Подтянуть Environment safe.directory=* из unit, если файл в репо уже новый
  if [[ -f "${ROOT}/systemd/monitoring-agent.service" ]]; then
    # Не копируем весь unit (пути могут отличаться) — только GIT_* env через drop-in
    mkdir -p /etc/systemd/system/monitoring-agent.service.d
    cat >/etc/systemd/system/monitoring-agent.service.d/git-safe.conf <<'EOF'
[Service]
Environment=GIT_CONFIG_COUNT=1
Environment=GIT_CONFIG_KEY_0=safe.directory
Environment=GIT_CONFIG_VALUE_0=*
EOF
    systemctl daemon-reload
  fi
  systemctl restart monitoring-agent
  systemctl --no-pager --full status monitoring-agent || true
fi
