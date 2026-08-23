#!/usr/bin/env bash
# Post-deploy verification for HostMonitor panel + SSE.
# Run on the server:
#   sudo bash scripts/verify_post_deploy.sh [/opt/monitoring] [http://127.0.0.1:5443]
# Optional authenticated SSE:
#   HM_USER=admin HM_PASS=secret sudo -E bash scripts/verify_post_deploy.sh
set -uo pipefail

ROOT="${1:-/opt/monitoring}"
BASE_URL="${2:-http://127.0.0.1:5443}"
COOKIE_JAR="${TMPDIR:-/tmp}/hm_verify_cookies.txt"
PASS=0
FAIL=0
WARN=0

ok()   { echo "[OK]  $*"; PASS=$((PASS + 1)); }
bad()  { echo "[FAIL] $*"; FAIL=$((FAIL + 1)); }
warn() { echo "[WARN] $*"; WARN=$((WARN + 1)); }

echo "=== HostMonitor post-deploy verify ==="
echo "root=${ROOT}"
echo "base=${BASE_URL}"
echo

if [[ ! -d "${ROOT}" ]]; then
  bad "root directory not found: ${ROOT}"
  echo "=== Summary: ${PASS} ok, ${WARN} warn, ${FAIL} fail ==="
  exit 1
fi
cd "${ROOT}"

echo "--- 1. BOM / PHP lint ---"
check_bom() {
  local f="$1"
  if [[ ! -f "$f" ]]; then
    bad "missing $f"
    return
  fi
  local head
  head=$(xxd -l 3 -p "$f" 2>/dev/null || od -An -tx1 -N3 "$f" | tr -d ' \n')
  if [[ "$head" == "efbbbf" ]]; then
    bad "BOM present: $f"
    return
  fi
  if head -c 5 "$f" | grep -q '<?php'; then
    ok "no BOM: $f"
  else
    warn "unexpected head for $f (hex=${head})"
  fi
}

check_bom "monitoring/includes/db_config.php"
check_bom "monitoring/sse.php"
check_bom "monitoring/includes/dashboard_snapshot.php"

if command -v php >/dev/null 2>&1; then
  for f in monitoring/includes/db_config.php monitoring/sse.php monitoring/includes/dashboard_snapshot.php; do
    if php -l "$f" >/dev/null 2>&1; then
      ok "php -l $f"
    else
      bad "php -l $f"
      php -l "$f" || true
    fi
  done
else
  warn "php CLI not found — skip php -l"
fi

if grep -qE '_stream_php_stdout|_is_sse_script' scripts/python_web_server.py 2>/dev/null; then
  ok "python_web_server.py has SSE streaming helpers"
else
  bad "python_web_server.py missing SSE streaming helpers (_stream_php_stdout / _is_sse_script)"
fi

echo
echo "--- 2. HTTP panel ---"
HTTP_CODE=$(curl -sI -o /tmp/hm_verify_headers.txt -w "%{http_code}" --connect-timeout 3 "${BASE_URL}/" || true)
HTTP_CODE="${HTTP_CODE:-000}"
head -10 /tmp/hm_verify_headers.txt 2>/dev/null || true
case "$HTTP_CODE" in
  200|301|302) ok "GET / → HTTP ${HTTP_CODE}" ;;
  500) bad "GET / → HTTP 500 (check journalctl -u monitoring-web)" ;;
  000) warn "GET / unreachable at ${BASE_URL} (run this script on the panel host)" ;;
  *) bad "GET / → HTTP ${HTTP_CODE}" ;;
esac

echo
echo "--- 3. SSE endpoint ---"
SSE_CODE=$(curl -sI -o /tmp/hm_verify_sse_headers.txt -w "%{http_code}" --connect-timeout 3 "${BASE_URL}/sse.php" || true)
SSE_CODE="${SSE_CODE:-000}"
head -8 /tmp/hm_verify_sse_headers.txt 2>/dev/null || true
case "$SSE_CODE" in
  401) ok "sse.php without cookie → 401 (auth required)" ;;
  200) warn "sse.php without cookie → 200 (expected 401 if auth enforced)" ;;
  000) warn "sse.php unreachable at ${BASE_URL} (run this script on the panel host)" ;;
  *) bad "sse.php without cookie → HTTP ${SSE_CODE}" ;;
esac

if [[ -n "${HM_USER:-}" && -n "${HM_PASS:-}" ]]; then
  rm -f "${COOKIE_JAR}"
  curl -s -c "${COOKIE_JAR}" -b "${COOKIE_JAR}" \
    -X POST "${BASE_URL}/login.php" \
    -d "username=${HM_USER}&password=${HM_PASS}" \
    -o /dev/null || true
  echo "Streaming sse.php for 12s with session cookie..."
  STREAM_OUT=/tmp/hm_verify_sse_stream.txt
  curl -N --max-time 12 -s -b "${COOKIE_JAR}" \
    "${BASE_URL}/sse.php?range=1h" -o "${STREAM_OUT}" || true
  if grep -q "event: ready" "${STREAM_OUT}" && grep -q "event: overview" "${STREAM_OUT}"; then
    ok "SSE stream has event: ready and event: overview"
  else
    bad "SSE stream missing ready/overview (see ${STREAM_OUT})"
    head -40 "${STREAM_OUT}" || true
  fi
  if grep -q ": ping" "${STREAM_OUT}"; then
    ok "SSE heartbeat (: ping) present"
  else
    warn "SSE heartbeat (: ping) not seen in 12s window"
  fi
else
  warn "Set HM_USER and HM_PASS to verify authenticated SSE stream"
fi

echo
echo "--- 4. Frontend / nginx wiring ---"
DASH_JS="frontend/js/dashboard.js"
if [[ -f "$DASH_JS" ]] && grep -q "EventSource" "$DASH_JS" && grep -q "sse.php" "$DASH_JS"; then
  ok "dashboard.js uses EventSource + sse.php"
else
  bad "dashboard.js missing EventSource/sse.php"
fi
if [[ -f "$DASH_JS" ]] && grep -q "startPollingFallback" "$DASH_JS"; then
  ok "dashboard.js has polling fallback"
else
  warn "polling fallback not found by name"
fi
if [[ -f nginx/monitoring.conf ]] && grep -q "sse.php" nginx/monitoring.conf; then
  ok "nginx/monitoring.conf mentions sse.php"
else
  warn "nginx SSE location missing (ok if using monitoring-web only)"
fi

echo
echo "--- 5. Panel git ---"
if [[ ! -f scripts/panel_git.sh ]]; then
  warn "scripts/panel_git.sh missing"
elif [[ ! -x scripts/panel_git.sh ]]; then
  warn "scripts/panel_git.sh exists but not executable"
elif ! id monitoring >/dev/null 2>&1; then
  warn "user 'monitoring' not found — skip panel_git fetch (ok on non-panel hosts)"
else
  if sudo -u monitoring "${ROOT}/scripts/panel_git.sh" fetch >/tmp/hm_verify_git.txt 2>&1; then
    ok "panel_git.sh fetch as monitoring"
  else
    bad "panel_git.sh fetch failed"
    cat /tmp/hm_verify_git.txt || true
  fi
fi

echo
echo "=== Summary: ${PASS} ok, ${WARN} warn, ${FAIL} fail ==="
echo "Browser (manual): ${BASE_URL}/ → DevTools → Network → sse.php (type=eventsource, overview ~3s)"
echo "UI git (manual): Settings → Updates → Check for updates"

if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
exit 0
