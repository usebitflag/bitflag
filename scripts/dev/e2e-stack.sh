#!/usr/bin/env bash
# An end-to-end stack that never touches the development database.
#
#   scripts/dev/e2e-stack.sh up     # database bitflag_e2e, seeded; the app on http://localhost:8081
#   scripts/dev/e2e-stack.sh down   # removes the container and the database, and rebuilds the normal assets
#
# The specs reset the database before they start (migrate:fresh), clear the cache and empty the Mailpit inbox, so they
# must not run against the development stack. This starts a second application container on port 8081 against its own
# database, with the testing routes on, a mail worker, and the same Mailpit. Run the specs against it:
#
#   CYPRESS_BASE_URL=http://localhost:8081 npm run e2e -- --expose ADMIN_ORIGIN=http://admin.localhost:8081
#
# Both stacks share public/build, so `up` builds the assets with the component gallery and `down` builds them without.
# The Sail services (mysql, redis, mailpit) must be running: `sail up -d`.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

# Sail sets these before it calls Docker Compose; the compose file reads them.
export WWWUSER=${WWWUSER:-$(id -u)}
export WWWGROUP=${WWWGROUP:-$(id -g)}

name=bitflag-e2e
database=bitflag_e2e
port=${E2E_PORT:-8081}
pepper="ZTJlLXBlcHBlci1mb3ItdGVzdHMtb25seS0zMmJ5dGU="

env_value() {
  grep -E "^$1=" .env | head -1 | cut -d= -f2- | tr -d '"'
}

root_password=$(env_value DB_PASSWORD)
mysql_root() {
  docker compose exec -T mysql mysql -uroot -p"$root_password" -e "$1" 2>&1 | grep -v "Using a password" || true
}

case "${1:-}" in
  up)
    docker rm -f "$name" >/dev/null 2>&1 || true
    mysql_root "CREATE DATABASE IF NOT EXISTS $database; GRANT ALL ON $database.* TO '$(env_value DB_USERNAME)'@'%';"
    docker compose exec -T -e VITE_INCLUDE_DEV_PAGES=true laravel.test npm run build >/dev/null

    # PHP's built-in server runs one request at a time per worker; browsers hold idle connections open, so give it many.
    docker compose run -d --no-deps --name "$name" -p "$port:$port" \
      -e APP_ENV=testing -e APP_DEBUG=false -e "APP_URL=http://localhost:$port" -e "DB_DATABASE=$database" \
      -e QUEUE_CONNECTION=sync -e CACHE_STORE=file -e BCRYPT_ROUNDS=4 \
      -e TESTING_E2E=true -e TESTING_E2E_TOKEN=e2e-local-token \
      -e BITFLAG_CREDENTIAL_PEPPER_CURRENT=1 -e "BITFLAG_CREDENTIAL_PEPPER_1=$pepper" \
      -e PHP_CLI_SERVER_WORKERS=24 \
      --entrypoint sh laravel.test -c "php artisan migrate:fresh --seed --seeder=DemoSeeder --force && (php artisan outbox:work &) && exec php -S 0.0.0.0:$port -t public" >/dev/null

    for _ in $(seq 1 60); do
      if curl -s -o /dev/null "http://localhost:$port/signin"; then
        echo "Ready on http://localhost:$port (database $database)."
        exit 0
      fi
      sleep 1
    done
    echo "The stack did not answer in time. See: docker logs $name" >&2
    exit 1
    ;;
  down)
    docker rm -f "$name" >/dev/null 2>&1 || true
    mysql_root "DROP DATABASE IF EXISTS $database;"
    docker compose exec -T laravel.test npm run build >/dev/null
    echo "Removed $name and the $database database."
    ;;
  *)
    echo "Usage: scripts/dev/e2e-stack.sh up|down" >&2
    exit 1
    ;;
esac
