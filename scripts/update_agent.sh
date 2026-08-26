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
  "$GIT" -c "safe.directory=*" -c "safe.directory=${ROOT}" -C "${ROOT}" "$@"
}

echo "[update_agent] root=${ROOT}"

export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0="${ROOT}"
export GIT_TERMINAL_PROMPT=0

# ─── Определяем текущую ветку ────────────────────────────────────────────────
BRANCH=$(run_git symbolic-ref --short HEAD 2>/dev/null || echo "main")
echo "[update_agent] branch=${BRANCH}"

run_git config --local --add safe.directory "${ROOT}" 2>/dev/null || true

# ─── Systemd drop-in (git-safe.conf с specific path) ─────────────────────────
if command -v systemctl >/dev/null 2>&1 && [[ -w /etc/systemd/system || -w /etc/systemd/system/monitoring-agent.service.d ]]; then
  mkdir -p /etc/systemd/system/monitoring-agent.service.d 2>/dev/null || true
  if [[ -d /etc/systemd/system/monitoring-agent.service.d ]]; then
    cat >/etc/systemd/system/monitoring-agent.service.d/git-safe.conf 2>/dev/null <<EOF || true
[Service]
Environment=GIT_CONFIG_COUNT=2
Environment=GIT_CONFIG_KEY_0=safe.directory
Environment=GIT_CONFIG_VALUE_0=${ROOT}
Environment=GIT_CONFIG_KEY_1=GIT_TERMINAL_PROMPT
Environment=GIT_CONFIG_VALUE_1=0
EOF
    systemctl daemon-reload 2>/dev/null || true
  fi
fi

# ─── Pull ─────────────────────────────────────────────────────────────────────
run_git fetch origin --prune
# Сброс любых локальных правок tracked-файлов
run_git reset --hard "origin/${BRANCH}"
# На всякий случай: если origin/<branch> ещё нет локально
if ! run_git rev-parse --verify "origin/${BRANCH}" >/dev/null 2>&1; then
  run_git reset --hard FETCH_HEAD
fi

echo "[update_agent] HEAD=$(run_git rev-parse --short HEAD) $(run_git log -1 --pretty=%s)"

# ─── Переустановка pip-зависимостей после git reset ──────────────────────────
VENV_DIR="${ROOT}/.venv"
REQ="${ROOT}/agent/requirements.txt"
if [[ ! -f "${REQ}" ]]; then
  REQ="${ROOT}/requirements.txt"
fi

if [[ -d "${VENV_DIR}" && -f "${VENV_DIR}/bin/pip" && -f "${REQ}" ]]; then
  echo "[update_agent] Обновляю pip-зависимости..."
  "${VENV_DIR}/bin/pip" install --upgrade pip 2>/dev/null || true
  "${VENV_DIR}/bin/pip" install -r "${REQ}" || echo "[update_agent] pip install завершился с ошибкой — продолжаем"
  # scapy — опционально
  "${VENV_DIR}/bin/pip" install "scapy>=2.5.0" 2>/dev/null || echo "[update_agent] scapy не установлен"
fi

# ─── Рестарт сервиса ─────────────────────────────────────────────────────────
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
