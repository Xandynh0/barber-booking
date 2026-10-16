#!/bin/sh
# Entrypoint of the production backend image (backend, scheduler and one-off
# commands such as migrations). The environment comes from the container
# (docker-compose.prod.yml env_file), never from a file inside the image, so
# the configuration cache is built here, at start-up, not at build time.
# Values are never printed: only the names of the variables that are wrong.
set -eu

fail() {
  echo "barber-booking: $1" >&2
  exit 1
}

# Unfilled placeholders copied from .env.production.example (<...>).
placeholders="$(env | grep -E '^[A-Z0-9_]+=.*<[^>]+>' | cut -d= -f1 | tr '\n' ' ' || true)"
[ -z "$placeholders" ] || fail "fill in the example placeholders before starting: $placeholders"

[ -n "${APP_KEY:-}" ] || fail "APP_KEY is not set (see docs/desenvolvimento.md, \"Produção com Docker\")."
[ "${APP_ENV:-}" = "production" ] || fail "APP_ENV must be production in this image."
[ "${APP_DEBUG:-}" = "false" ] || fail "APP_DEBUG must be false in this image (true shows stack traces and configuration)."

# HTTPS is the default. Plain HTTP (local validation of the production stack
# without a certificate) must be asked for explicitly with
# ALLOW_INSECURE_HTTP=true; it is never inferred from the other values.
insecure="false"
case "${APP_URL:-}" in http://*) insecure="true" ;; esac
[ "${SESSION_SECURE_COOKIE:-}" = "true" ] || insecure="true"
if [ "$insecure" = "true" ]; then
  [ "${ALLOW_INSECURE_HTTP:-false}" = "true" ] \
    || fail "APP_URL is not https or SESSION_SECURE_COOKIE is not true. Use HTTPS, or set ALLOW_INSECURE_HTTP=true for a local validation only."
  echo "barber-booking: WARNING: running without HTTPS (ALLOW_INSECURE_HTTP=true). Local validation only." >&2
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan optimize --no-interaction >/dev/null

exec "$@"
