# Local development

The application runs in Docker through Laravel Sail. PHP, Composer, Node and npm all run inside the containers, so the only host requirements are Docker and Git.

## Services

| Service | Purpose | Default host port |
| --- | --- | --- |
| `laravel.test` | Application (PHP 8.5, Node 22) | 80, Vite on 5173 |
| `horizon` | Queue workers supervised by Horizon | none |
| `outbox` | Outbox worker (`outbox:work`) that fans stored messages out to consumers | none |
| `scheduler` | Scheduled commands (`schedule:work`), including outbox recovery and pruning | none |
| `mysql` | MySQL 8.4 | 3306 |
| `redis` | Redis 7 | 6379 |
| `mailpit` | Captures outgoing mail | 1025 (SMTP), 8025 (inbox) |

Override a host port in `.env` when it is already taken: `APP_PORT`, `VITE_PORT`, `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MAILPIT_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT`.

## First run

```bash
cp .env.example .env
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html composer:2 composer install --ignore-platform-reqs
./vendor/bin/sail up -d --build
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

The demo seed creates Acme and Globex, five Acme people and one Globex person, and the staff user `support@bitflag.test`. Everyone signs in with `DEMO_PASSWORD` (default `password`). The seeder uses fixed identifiers and refuses to run in production.

The portal is served on `APP_HOST` (default `localhost`) and the admin console on `ADMIN_HOST` (default `admin.localhost`). Cookies are host-only, so signing in on one host never signs you in on the other.

## Everyday commands

| Task | Command |
| --- | --- |
| Start and stop | `sail up -d`, `sail down` |
| Run tests | `sail bin pest` (`--parallel`, `--group=arch`) |
| PHP style and static analysis | `sail bin pint --test`, `sail bin phpstan analyse` |
| Regenerate the theme | `sail npm run tokens` (`tokens:check` verifies it) |
| Frontend checks | `sail npm run lint`, `format:check`, `stylelint`, `typecheck`, `test` |
| End-to-end specs | `npm run e2e` on the host (see below) |
| Seed the demo world | `sail artisan db:seed --class=DemoSeeder` (safe to repeat; `migrate:fresh --seed --seeder=DemoSeeder` starts over) |
| Artisan | `sail artisan …` |
| Frontend dev server | `sail npm run dev` |
| Mail inbox | http://localhost:8025 |
| Admin console | http://admin.localhost (staff sign-in; the Horizon dashboard is at /horizon on the same host) |

Tests use the `testing` database on the same MySQL service, created on first start. The application user may also create `testing_*` databases, which parallel test runs use. If the MySQL volume already existed before that grant was added, run `sail down -v` once to recreate it.

## End-to-end tests

The Cypress specs in `cypress/e2e` run in a real browser against the running application and the demo seed. Cypress runs on the host (Node 22 or newer), not in Sail, and needs Chrome.

1. In `.env` set `TESTING_E2E=true`, `TESTING_E2E_TOKEN=e2e-local-token`, `APP_DEBUG=false` and `VITE_INCLUDE_DEV_PAGES=true` (`.env.e2e` has these values), then `sail artisan config:clear`. The last one keeps the component gallery in the build, which production builds leave out.
2. `sail npm run build` so the application serves built assets, and `sail artisan migrate:fresh --seed --seeder=DemoSeeder`.
3. `npm ci` on the host, then `npm run e2e`. When Sail listens on a port other than 80, point Cypress at it:

```bash
CYPRESS_BASE_URL=http://localhost:8080 npm run e2e -- --expose ADMIN_ORIGIN=http://admin.localhost:8080
```

Specs start from known data. `POST /_testing/reset` rebuilds the database from the demo seed, `POST /_testing/login` signs a seeded person in without the form, `POST /_testing/clock` freezes the server clock and `/_testing/fail` raises an error to show the 500 page. These routes load only when `TESTING_E2E` is true in the local or testing environment, and every request needs the `X-Testing-Token` header; anything else gets the ordinary 404. In production they do not exist.
