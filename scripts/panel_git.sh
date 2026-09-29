#!/bin/bash
# Git от имени владельца репозитория (вызывается PHP через sudo).
set -euo pipefail

ROOT="${1:?usage: panel_git.sh <repo-root> <git-args...>}"
shift

export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"
export GIT_TERMINAL_PROMPT=0

# HOME владельца репо — нужны SSH-ключи / credential helper, не /tmp
if [[ -z "${HOME:-}" || "${HOME}" == "/tmp" || ! -d "${HOME}" ]]; then
  if command -v getent >/dev/null 2>&1; then
    HOME="$(getent passwd "$(id -un)" | cut -d: -f6 || true)"
  fi
  HOME="${HOME:-/var/lib/monitoring}"
fi
export HOME

GIT="${GIT:-/usr/bin/git}"
if [[ ! -x "$GIT" ]]; then
    GIT="$(command -v git || true)"
fi
if [[ -z "$GIT" || ! -x "$GIT" ]]; then
    echo "git not found (PATH=${PATH})" >&2
    exit 127
fi

exec "$GIT" -c "safe.directory=${ROOT}" -C "${ROOT}" "$@"
