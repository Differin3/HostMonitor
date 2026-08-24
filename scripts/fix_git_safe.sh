#!/bin/bash
# Разовая починка git + обновление агента до origin/main.
# Работает на Debian (/opt/monitoring) и TrueNAS (/mnt/NAS/HostMonitor).
# zsh: кавычки обязательны вокруг safe.directory=*
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

SUDO=""
if [[ "$(id -u)" -ne 0 ]]; then
  SUDO="sudo"
fi

echo "[fix_git_safe] root=${ROOT}"

run_git() {
  $SUDO git -c 'safe.directory=*' -c "safe.directory=${ROOT}" -C "${ROOT}" "$@"
}

export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0=*
export GIT_TERMINAL_PROMPT=0

run_git config --local --add safe.directory '*' || true
run_git config --local --add safe.directory "${ROOT}" || true

run_git fetch origin --prune
run_git reset --hard origin/main
echo "[fix_git_safe] HEAD=$(run_git rev-parse --short HEAD) $(run_git log -1 --pretty=%s)"

# systemd GIT env
if command -v systemctl >/dev/null 2>&1; then
  $SUDO mkdir -p /etc/systemd/system/monitoring-agent.service.d
  $SUDO tee /etc/systemd/system/monitoring-agent.service.d/git-safe.conf >/dev/null <<'EOF'
[Service]
Environment=GIT_CONFIG_COUNT=1
Environment=GIT_CONFIG_KEY_0=safe.directory
Environment=GIT_CONFIG_VALUE_0=*
Environment=GIT_TERMINAL_PROMPT=0
EOF
  $SUDO systemctl daemon-reload
  $SUDO systemctl restart monitoring-agent
  $SUDO systemctl --no-pager --full status monitoring-agent || true
fi
