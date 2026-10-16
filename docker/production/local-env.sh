#!/bin/sh
# Writes an environment file to validate the production stack on a developer
# machine or in CI, over plain HTTP, isolated from the development stack.
# Every secret is random and generated here, never committed or printed.
#
#   sh docker/production/local-env.sh <output-file> [port] [project-name]
#   docker compose -f docker-compose.prod.yml --env-file <output-file> ...
#
# <output-file> must be relative to the repository root (for example
# .env.prodlocal, ignored by Git and by the image builds) or an absolute path
# in a form Docker understands: it is also written as ENV_FILE, so the one
# --env-file is both the interpolation source and the containers' env_file.
#
# Differences from a real .env.production, all explicit:
#   ALLOW_INSECURE_HTTP=true, SESSION_SECURE_COOKIE=false, PUBLIC_SCHEME=http
#   and an http:// APP_URL (no certificate on localhost); MAIL_HOST points to
#   an address that does not resolve (.invalid), so no e-mail leaves the
#   machine — a booking still succeeds and its e-mail stays pending.
set -eu

OUT="${1:?usage: local-env.sh <output-file> [port] [project-name]}"
PORT="${2:-8090}"
PROJECT="${3:-barber-booking-prodlocal}"
EXAMPLE="$(dirname "$0")/../../.env.production.example"

[ ! -e "$OUT" ] || { echo "$OUT already exists; not overwriting it." >&2; exit 1; }
# Assigned one by one so that a failure stops the script (set -e) instead of
# silently leaving an empty secret.
random_base64() { head -c "$1" /dev/urandom | base64 | tr -d '\n'; }
random_hex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
APP_KEY_VALUE="base64:$(random_base64 32)"
DB_PASSWORD_VALUE="$(random_hex 24)"
ROOT_PASSWORD_VALUE="$(random_hex 24)"
[ ${#APP_KEY_VALUE} -eq 51 ] && [ ${#DB_PASSWORD_VALUE} -eq 48 ] && [ ${#ROOT_PASSWORD_VALUE} -eq 48 ] \
  || { echo "could not generate the random values." >&2; exit 1; }

umask 077
sed \
  -e "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=$PROJECT#" \
  -e "s#^IMAGE_TAG=.*#IMAGE_TAG=local#" \
  -e "s#^WEB_PORT=.*#WEB_PORT=$PORT#" \
  -e "s#^PUBLIC_SCHEME=.*#PUBLIC_SCHEME=http#" \
  -e "s#^APP_KEY=.*#APP_KEY=$APP_KEY_VALUE#" \
  -e "s#^APP_URL=.*#APP_URL=http://127.0.0.1:$PORT#" \
  -e "s#^ALLOW_INSECURE_HTTP=.*#ALLOW_INSECURE_HTTP=true#" \
  -e "s#^DB_PASSWORD=.*#DB_PASSWORD=$DB_PASSWORD_VALUE#" \
  -e "s#^MYSQL_ROOT_PASSWORD=.*#MYSQL_ROOT_PASSWORD=$ROOT_PASSWORD_VALUE#" \
  -e "s#^SESSION_SECURE_COOKIE=.*#SESSION_SECURE_COOKIE=false#" \
  -e "s#^SANCTUM_STATEFUL_DOMAINS=.*#SANCTUM_STATEFUL_DOMAINS=127.0.0.1:$PORT,localhost:$PORT#" \
  -e "s#^MAIL_HOST=.*#MAIL_HOST=smtp.invalid#" \
  -e "s#^MAIL_USERNAME=.*#MAIL_USERNAME=null#" \
  -e "s#^MAIL_PASSWORD=.*#MAIL_PASSWORD=null#" \
  -e "s#^MAIL_FROM_ADDRESS=.*#MAIL_FROM_ADDRESS=no-reply@example.invalid#" \
  "$EXAMPLE" > "$OUT"
printf '\n# Read by docker-compose.prod.yml (env_file of backend and scheduler).\nENV_FILE=%s\n' "$OUT" >> "$OUT"

if grep -qE '^[A-Z0-9_]+=.*<[^>]+>' "$OUT"; then
  echo "placeholders left in $OUT: $(grep -E '^[A-Z0-9_]+=.*<[^>]+>' "$OUT" | cut -d= -f1 | tr '\n' ' ')" >&2
  exit 1
fi
echo "Wrote $OUT (project $PROJECT, http://127.0.0.1:$PORT)."
