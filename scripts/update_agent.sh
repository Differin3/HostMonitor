#!/bin/bash
# Обновление агента HostMonitor: всегда сбрасывает локальные tracked-правки.
# node.conf / .venv / data не трогаем (в .gitignore).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

GIT="${GIT:-/usr/bin/git}"
if [[ ! -x "$GIT" ]]; then
  GIT="$(command -v git || true)"
fi
if [[ -z "${GIT}" || ! -x "$GIT" ]]; then
  echo "git not found" >&2
  exit 127
fi

run_git() {
  "$GIT" -c "safe.directory=${ROOT}" -C "${ROOT}" "$@"
}

echo "[update_agent] root=${ROOT}"
run_git fetch origin --prune
# Сброс любых локальных правок tracked-файлов
run_git reset --hard origin/main
# На всякий случай: если origin/main ещё нет локально
if ! run_git rev-parse --verify origin/main >/dev/null 2>&1; then
  run_git reset --hard FETCH_HEAD
fi

echo "[update_agent] HEAD=$(run_git rev-parse --short HEAD) $(run_git log -1 --pretty=%s)"

if command -v systemctl >/dev/null 2>&1; then
  if systemctl list-unit-files monitoring-agent.service >/dev/null 2>&1 \
     || systemctl cat monitoring-agent.service >/dev/null 2>&1; then
    systemctl restart monitoring-agent
    systemctl --no-pager --full status monitoring-agent || true
  else
    echo "[update_agent] unit monitoring-agent не найден — рестарт вручную"
  fi
else
  echo "[update_agent] systemctl нет — рестарт агента вручную"
fi
