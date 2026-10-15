#!/bin/sh
# Smoke test of a running production stack of Barber Booking. Read-only: it
# never creates, changes or deletes data and never logs in.
#
#   sh docker/production/smoke-test.sh https://your-domain
#   sh docker/production/smoke-test.sh http://127.0.0.1:8080   # local validation
#
# Needs curl. Exit code 0 when every check passes, 1 otherwise (each failing
# check is printed). A service that is down (web, backend or database) makes
# it fail: nginx answers 502 without PHP-FPM, /api/health 503 without MySQL.
set -u

BASE_URL="${1:?usage: smoke-test.sh <base-url>}"
BASE_URL="${BASE_URL%/}"
FAILURES=0
LAST_OK=0
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# fetch <path> [accept]: headers in $WORK/h, body in $WORK/b, status printed
# (000 when the connection itself fails).
fetch() {
  : > "$WORK/h"; : > "$WORK/b"
  curl -sS --max-time 15 -o "$WORK/b" -D "$WORK/h" -w '%{http_code}' \
    -H "Accept: ${2:-application/json}" "$BASE_URL$1" 2>/dev/null || true
}

fail() {
  echo "FAIL  $1"
  FAILURES=$((FAILURES + 1))
}

# check <description> <expected-status> <path> <accept> [pattern...]
# Every pattern (extended regex, case-insensitive) must match the headers or
# the body.
check() {
  description="$1"; expected="$2"; path="$3"; accept="$4"; shift 4
  status="$(fetch "$path" "$accept")"
  LAST_OK=0
  if [ "$status" != "$expected" ]; then
    fail "$description: HTTP $status at $path (expected $expected)"
    return
  fi
  for pattern in "$@"; do
    if ! cat "$WORK/h" "$WORK/b" | grep -qiE -- "$pattern"; then
      fail "$description: '$pattern' not found at $path"
      return
    fi
  done
  LAST_OK=1
  echo "ok    $description"
}

# absent <description> <pattern>: the response of the previous check (which
# must have passed, so there is something to inspect) must not match.
absent() {
  if [ "$LAST_OK" != 1 ]; then
    fail "$1: no valid response to inspect"
  elif cat "$WORK/h" "$WORK/b" | grep -qiE -- "$2"; then
    fail "$1"
  else
    echo "ok    $1"
  fi
}

HTML='text/html'

# Laravel and the database.
check "Laravel answers /up"                  200 /up "$HTML"
check "API reaches the database"             200 /api/health '' '"status":"ok"'

# SPA: built files, history fallback, cache rules, no dev server.
check "SPA homepage"                         200 / "$HTML" '<div id="root">' 'cache-control: no-cache' 'content-security-policy: default-src .self.'
absent "SPA is the production build (no Vite dev server)" '/@vite/client|/src/main\.jsx|@react-refresh'
asset="$(grep -oE '/assets/[^"]+\.js' "$WORK/b" | head -n 1)"
check "Public route on reload (/agendar)"    200 /agendar "$HTML" '<div id="root">'
check "Admin route on reload (/admin/agenda)" 200 /admin/agenda "$HTML" '<div id="root">'
if [ -n "$asset" ]; then
  check "Hashed asset cached as immutable"   200 "$asset" '*/*' 'cache-control: public, max-age=31536000, immutable'
else
  fail "Hashed asset: no /assets/*.js referenced by index.html"
fi
check "Missing asset is a 404, not the SPA"  404 /assets/does-not-exist.js '*/*'

# Public API.
check "Public business settings"             200 /api/v1/public/business '' '"timezone"'
check "Public services"                      200 /api/v1/public/services '' '"data"'

# Admin stays protected.
check "Admin API requires a session"         401 /api/v1/admin/me '' '"UNAUTHENTICATED"'
check "Admin services require a session"     401 /api/v1/admin/services '' '"UNAUTHENTICATED"'
check "Admin API from a browser tab is 401"  401 /api/v1/admin/me "$HTML" '"UNAUTHENTICATED"'

# Sanctum's CSRF cookie comes from Laravel.
check "CSRF cookie endpoint (Laravel)"       204 /sanctum/csrf-cookie '' 'set-cookie: XSRF-TOKEN=' 'set-cookie: [^=]*session='
case "$BASE_URL" in
  https://*) check "Session cookie is Secure"  204 /sanctum/csrf-cookie '' 'set-cookie: XSRF-TOKEN=[^;]*;.*secure' ;;
esac

# Cancellation pages: an unsigned link gets the generic page.
check "Invalid cancellation link is generic" 403 /cancelar/00000000000000000000000000 "$HTML" 'cache-control: no-store' 'x-robots-tag: noindex'
absent "Invalid cancellation page reveals nothing technical" 'exception|stack trace|vendor/|sqlstate|whoops'

# Errors carry no technical details (APP_DEBUG=false).
check "Unknown API route is a JSON 404"      404 /api/v1/does-not-exist '' '"NOT_FOUND"'
absent "API errors carry no technical details" '"trace"|"exception"|"file"|vendor/|sqlstate'
check "Wrong method on the API is JSON"     405 /api/v1/public/appointments '' '"error"|"message"'
absent "Method errors carry no technical details" '"trace"|"exception"|"file"|vendor/'

if [ "$FAILURES" -gt 0 ]; then
  echo "$FAILURES check(s) failed."
  exit 1
fi
echo "All checks passed."
