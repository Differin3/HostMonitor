#!/bin/bash
# Git от имени владельца репозитория (вызывается PHP через sudo).
set -euo pipefail

ROOT="${1:?usage: panel_git.sh <repo-root> <git-args...>}"
shift

GIT="${GIT:-/usr/bin/git}"
if [[ ! -x "$GIT" ]]; then
    GIT="$(command -v git || true)"
fi
if [[ -z "$GIT" || ! -x "$GIT" ]]; then
    echo "git not found" >&2
    exit 127
fi

export GIT_TERMINAL_PROMPT=0
export HOME="${HOME:-/tmp}"

exec "$GIT" -c "safe.directory=${ROOT}" -C "${ROOT}" "$@"
