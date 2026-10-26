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

Recovery codes, invitation tokens and trusted-browser tokens are stored as HMAC-SHA256 hashes under a pepper that stays outside the database. Set `BITFLAG_CREDENTIAL_PEPPER_1` in `.env` to 32 random bytes, base64-encoded (`openssl rand -base64 32`). To rotate, add a second pepper, make it current with `BITFLAG_CREDENTIAL_PEPPER_CURRENT`, and name the old one in `BITFLAG_CREDENTIAL_PEPPER_PREVIOUS` until stored secrets have expired. Hashing fails with a message naming the setting when the pepper is missing or malformed.

The demo seed creates Acme, Globex and Maya Studio, five Acme people and one Globex person, and the staff user `support@bitflag.test`. Maya and Jon have two-factor on (the fixed secret and recovery codes are in `database/seeders/Demo/DemoSecrets.php`), and one invitation to Acme is waiting. Everyone signs in with `DEMO_PASSWORD` (default `password`). The seeder uses fixed identifiers and refuses to run in production.

The portal is served on `APP_HOST` (default `localhost`) and the admin console on `ADMIN_HOST` (default `admin.localhost`). Cookies are host-only, so signing in on one host never signs you in on the other.

## Everyday commands

| Task | Command |
| --- | --- |
| Start and stop | `sail up -d`, `sail down` |
| Everything CI runs before pushing | `sail bash scripts/check.sh` (`backend` or `frontend` for one half; skips end-to-end, the production build checks and the security audit job) |
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

## Email and background workers

Nothing sends mail during a web request. A request that needs an email writes an outbox message in the same transaction as the change. The `outbox` service reads those messages and hands each one to its consumers, and the consumer that sends account email builds the message (including links) and delivers it over SMTP. Locally SMTP points at Mailpit, so no mail ever reaches a real inbox: open http://localhost:8025 to read it.

```
request ──► outbox_messages (same transaction) ──► outbox worker ──► consumer ──► Mailpit
                                                      │
                                              outbox_receipts: pending → succeeded | failed
```

- The `outbox`, `horizon` and `scheduler` services are long-running PHP processes. They load the application code once, when they start. After you change PHP, switch branches or pull, restart them, or they keep running the old code: `sail restart outbox horizon scheduler`. Symptoms of stale workers are failed receipts with errors such as `Route [...] not defined` or `No consumer named ... is registered`, and mail that never shows up in Mailpit.
- `sail artisan outbox:status` shows the backlog, failed receipts and the age of the oldest overdue work. `sail artisan outbox:recover` releases expired leases and signals receipts that are due.
- A receipt marked `failed` has used its attempts. After fixing the cause, set the receipt back to `pending` with a due time of now and run `outbox:recover`. Do this only in local development; in production, failures are investigated, not reset by hand.
- Mail that does not arrive: check `outbox:status`, then the `last_error` column of the failed row in `outbox_receipts`, then whether the workers are running the current code.
- The end-to-end specs clear the Mailpit inbox before they read mail, so an inbox you were using can empty while they run. Run them against a separate database and stack when you need the dev data (see below).

## Checks before a push, and CI

Pull requests do not run GitHub Actions: the organization is on the Free plan and its minutes are limited. The checks run on your machine instead, in the `pre-push` hook, and CI runs after a merge to `main` (and by hand from the Actions tab, with "Run workflow").

- Turn the hook on once per clone: `git config core.hooksPath .githooks`.
- On every push of a branch it runs `scripts/check.sh` in Sail (what the `backend-lint`, `backend-test`, `arch-canary` and `frontend` jobs run: Composer audit, Pint, PHPStan, Pest, architecture canaries, theme and type checks, ESLint, Prettier, Stylelint, Vitest with coverage) and a gitleaks scan of the repository. It stops at the first failure. Sail must be running (`sail up -d`).
- A push that only changes `docs/`, `.github/` or Markdown files skips `check.sh` and still runs the secret scan.
- `SKIP_CHECKS=1 git push` skips both. Use it rarely. The end-to-end specs, the production build checks and the weekly security audit are not part of the hook; they run in CI after the merge.
- The hook also blocks pushes to `main`.

## The evaluator package

The application evaluates flags with `bitflag-php`, installed from its private GitHub repository at one exact release (`composer.json` requires it by version, and `composer.lock` pins the commit). Composer needs a GitHub token with read access to that repository, supplied in `COMPOSER_AUTH` or `auth.json`.

The package is tested against a pinned release of the `bitflag-protocol` fixtures, named in `protocol.lock.json`. `sail bin pest` runs those fixtures through the installed package (`tests/Conformance`), so a package upgrade that changes behavior fails here. Fetch the release once, and again whenever the lock changes:

```bash
GH_TOKEN=… scripts/fetch-protocol-fixtures.sh   # downloads, verifies the SHA-256 and extracts to build/protocol/<version>
GH_TOKEN=… scripts/pin-protocol.sh <version>    # pins a published release in protocol.lock.json
```

The tests fail, they do not skip, when the fixtures are missing. `BITFLAG_PROTOCOL_DIR` points them at another extracted release.

The lock should name the protocol release that the installed package pins in its own `protocol.lock.json`; nothing checks this automatically, so compare them when upgrading.

### Changing `bitflag-php` together with the application

```bash
scripts/dev/link-bitflag-php.sh     # symlinks ../bitflag-php as a path repository and mounts it into the containers
sail down && sail up -d             # picks up the mount
scripts/dev/unlink-bitflag-php.sh   # back to the pinned release
```

Linking changes `composer.json` and `composer.lock`. Do not commit them while linked: CI runs `scripts/ci/no-path-repositories.php`, which fails on a path repository.

### Upgrading the package

Upgrading is a deliberate pull request. Dependabot ignores `bitflag/bitflag-php` for that reason.

1. A new `bitflag-php` version exists once its tag exists (`v0.1.0-alpha.2`). Check the `protocol.lock.json` of that tag to see which protocol release it was tested against.
2. Change the version in `composer.json` to the exact new version and refresh the lock: `composer update bitflag/bitflag-php`. Commit `composer.json` and `composer.lock` together.
3. If the package pins a new protocol release, run `GH_TOKEN=… scripts/pin-protocol.sh <protocol version>` and then `scripts/fetch-protocol-fixtures.sh`.
4. Run `sail bin pest` (`tests/Conformance` runs the pinned fixtures through the installed package) and fix what fails.
5. Open the pull request and link the package's changelog or release notes.

### Access in CI

CI needs one Actions secret, `PROTOCOL_READ_TOKEN`: a fine-grained personal access token with read-only Contents access to both `usebitflag/bitflag-php` (Composer installs it from GitHub) and `usebitflag/bitflag-protocol` (the fixture download). Tokens expire. A Composer `404` for a `bitflag-php` archive, or `release not found` in the fetch step, means the token is missing, expired or lacks one of the two repositories; replace the secret.

## End-to-end tests

The Cypress specs in `cypress/e2e` run in a real browser against the running application and the demo seed. Cypress runs on the host (Node 22 or newer), not in Sail, and needs Chrome.

1. In `.env` set `TESTING_E2E=true`, `TESTING_E2E_TOKEN=e2e-local-token`, `APP_DEBUG=false` and `VITE_INCLUDE_DEV_PAGES=true` (`.env.e2e` has these values, including the credential pepper), then `sail artisan config:clear`. The last one keeps the component gallery in the build, which production builds leave out.
2. `sail npm run build` so the application serves built assets, and `sail artisan migrate:fresh --seed --seeder=DemoSeeder`.
3. `npm ci` on the host, then `npm run e2e`. When Sail listens on a port other than 80, point Cypress at it:

```bash
CYPRESS_BASE_URL=http://localhost:8080 npm run e2e -- --expose ADMIN_ORIGIN=http://admin.localhost:8080
```

Specs start from known data. `POST /_testing/reset` rebuilds the database from the demo seed, `POST /_testing/login` signs a seeded person in without the form, `POST /_testing/clock` freezes the server clock and `GET /_testing/clock` reads it, `GET`/`DELETE /_testing/mailpit` read and clear the captured mail, and `/_testing/fail` raises an error to show the 500 page. These routes load only when `TESTING_E2E` is true in the local or testing environment, and every request needs the `X-Testing-Token` header; anything else gets the ordinary 404. In production they do not exist.
