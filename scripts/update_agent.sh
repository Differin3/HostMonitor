#!/bin/bash
# Обновление агента HostMonitor
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

GIT="${GIT:-/usr/bin/git}"
[ ! -x "$GIT" ] && GIT="$(command -v git || true)"
[ -z "${GIT:-}" ] || [ ! -x "$GIT" ] && { echo "git not found" >&2; exit 127; }

run_git() { "$GIT" -c "safe.directory=*" -c "safe.directory=${ROOT}" -C "${ROOT}" "$@"; }

echo "[update_agent] root=${ROOT}"
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0="${ROOT}"
export GIT_TERMINAL_PROMPT=0

BRANCH=$(run_git symbolic-ref --short HEAD 2>/dev/null || echo "main")
echo "[update_agent] branch=${BRANCH}"
run_git config --local --add safe.directory "${ROOT}" 2>/dev/null || true

if command -v systemctl >/dev/null 2>&1 && [[ -w /etc/systemd/system || -w /etc/systemd/system/monitoring-agent.service.d ]]; then
  mkdir -p /etc/systemd/system/monitoring-agent.service.d 2>/dev/null || true
  if [[ -d /etc/systemd/system/monitoring-agent.service.d ]]; then
    cat >/etc/systemd/system/monitoring-agent.service.d/git-safe.conf 2>/dev/null <<EOF || true
[Service]
Environment=GIT_CONFIG_COUNT=1
Environment=GIT_CONFIG_KEY_0=safe.directory
Environment=GIT_CONFIG_VALUE_0=${ROOT}
Environment=GIT_TERMINAL_PROMPT=0
EOF
    systemctl daemon-reload 2>/dev/null || true
  fi
fi

run_git fetch origin --prune
run_git reset --hard "origin/${BRANCH}"
run_git rev-parse --verify "origin/${BRANCH}" >/dev/null 2>&1 || run_git reset --hard FETCH_HEAD
echo "[update_agent] HEAD=$(run_git rev-parse --short HEAD) $(run_git log -1 --pretty=%s)"

VENV_DIR="${ROOT}/.venv"
REQ="${ROOT}/agent/requirements.txt"
[ ! -f "${REQ}" ] && REQ="${ROOT}/requirements.txt"
if [[ -d "${VENV_DIR}" && -f "${VENV_DIR}/bin/pip" && -f "${REQ}" ]]; then
  echo "[update_agent] Обновляю pip-зависимости..."
  "${VENV_DIR}/bin/pip" install --upgrade pip 2>/dev/null || true
  "${VENV_DIR}/bin/pip" install -r "${REQ}" || true
  "${VENV_DIR}/bin/pip" install "scapy>=2.5.0" 2>/dev/null || true
fi

# Рестарт
if [ "$(id -u)" -eq 0 ]; then
  if command -v systemctl >/dev/null 2>&1 && systemctl cat monitoring-agent.service >/dev/null 2>&1; then
    systemctl restart monitoring-agent.service || systemctl restart monitoring-agent || true
    systemctl --no-pager status monitoring-agent --lines=6 || true
  elif [ -f /usr/local/etc/rc.d/monitoring-agent ]; then
    /usr/local/etc/rc.d/monitoring-agent restart || true
  elif command -v service >/dev/null 2>&1; then
    service monitoring-agent restart || true
  fi
else
  if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
    (sudo -n systemctl restart monitoring-agent.service 2>/dev/null || sudo systemctl restart monitoring-agent 2>/dev/null) || true
  fi
  if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet monitoring-agent 2>/dev/null; then
    true
  elif command -v service >/dev/null 2>&1; then
    service monitoring-agent restart 2>/dev/null || true
  elif [ -f /usr/local/etc/rc.d/monitoring-agent ]; then
    sudo -n /usr/local/etc/rc.d/monitoring-agent restart 2>/dev/null || true
  fi
fi
