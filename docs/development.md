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
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

Before the first request, set the credential pepper in `.env`. Recovery codes, invitation tokens and trusted-browser tokens are stored as HMAC-SHA256 hashes under a pepper that stays outside the database. Set `BITFLAG_CREDENTIAL_PEPPER_1` to 32 random bytes, base64-encoded (`openssl rand -base64 32`), and leave `BITFLAG_CREDENTIAL_PEPPER_CURRENT=1`. To rotate, add a second pepper, make it current with `BITFLAG_CREDENTIAL_PEPPER_CURRENT`, and name the old one in `BITFLAG_CREDENTIAL_PEPPER_PREVIOUS` until stored secrets have expired. Hashing fails with a message naming the setting when the pepper is missing or malformed.

Then migrate and seed the demo world:

```bash
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed --class=DemoSeeder
```

Open http://localhost (or the `APP_PORT` you chose) and sign in at `/signin`.

### The demo world

The demo seed creates three organizations (Acme, Globex and Maya Studio), five Acme people, one Globex person and the staff user `support@bitflag.test`. The seeder uses fixed identifiers, can be run again, and refuses to run in production. Everyone signs in with `DEMO_PASSWORD` (default `password`).

| Person | Sign-in | Notes |
| --- | --- | --- |
| `maya@acme.dev` | password, then a code | Org Owner and project Owner in Acme; also in Maya Studio. Asked for a code at sign-in. |
| `jon@acme.dev` | password, then a code | Member, Publisher on Storefront, Developer on Checkout API. Asked for a code at sign-in. |
| `priya@acme.dev` | password | Developer on Storefront |
| `ana@acme.dev` | password | Viewer on Storefront (read-only screens) |
| `leo@acme.dev` | password | Member of Acme without a project |
| `gina@globex.dev` | password | Org Owner of Globex, for isolation checks |
| `support@bitflag.test` | password, on `admin.localhost` | Staff |

Maya and Jon's authenticator secret is the fixed value `JBSWY3DPEHPK3PXP` (add it to any authenticator app), and their recovery codes are listed in `database/seeders/Demo/DemoSecrets.php`. One invitation to Acme for `dev@northwind.studio` is waiting at `/invitations/` followed by the token in the same file.

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
| End-to-end specs | `scripts/dev/e2e-stack.sh up`, then `npm run e2e` on the host against it (see below) |
| Seed the demo world | `sail artisan db:seed --class=DemoSeeder` (safe to repeat; `migrate:fresh --seed --seeder=DemoSeeder` starts over) |
| Artisan | `sail artisan …` |
| Frontend dev server | `sail npm run dev` |
| Mail inbox | http://localhost:8025 |
| Restart the workers after changing PHP | `sail restart outbox horizon scheduler` (see below) |
| Outbox backlog, failures and retry | `sail artisan outbox:status`, `outbox:retry --all` |
| Admin console | http://admin.localhost (staff sign-in; the Horizon dashboard is at /horizon on the same host) |

Tests use the `testing` database on the same MySQL service, created on first start. The application user may also create `testing_*` databases, which parallel test runs use. If the MySQL volume already existed before that grant was added, run `sail down -v` once to recreate it.

## Signing in with Google and GitHub

The sign-up and sign-in pages show a Google and a GitHub button for each provider that has credentials, and hide the whole row when neither does. To try it locally, register an OAuth app at each provider and put its id and secret in `.env`:

| Setting | Where to get it |
| --- | --- |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET` | GitHub, Settings, Developer settings, OAuth Apps, New OAuth App |
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | Google Cloud console, APIs and services, Credentials, OAuth client ID (Web application) |

Register `APP_URL` followed by `/auth/github/callback` and `/auth/google/callback` as the redirect URLs (for the default setup, `http://localhost:8080/auth/github/callback` and `http://localhost:8080/auth/google/callback`), then `sail artisan config:clear`. A provider is offered only when both its id and secret are set. Use a different OAuth app for each environment; never reuse production credentials locally.

How it behaves:

- The provider replaces the password only. Two-factor and an organization's "require two-factor" still apply.
- The provider's email counts only when the provider says it is verified. A verified email with no account creates one (email confirmed, no password); an email that already has an account links nothing and tells the person to sign in with their password.
- A person who signed up this way has no password until they set one. Account settings shows a notice with a button that emails the usual reset link, and the settings that ask for a password stay off until then.
- The specs never call the providers. `.env.e2e` and `scripts/dev/e2e-stack.sh` set fake credentials so the buttons show, and the Pest tests fake the HTTP calls.

## Email and background workers

Nothing sends mail during a web request. A request that needs an email writes an outbox message in the same transaction as the change. The `outbox` service reads those messages and hands each one to its consumers, and the consumer that sends account email builds the message (including links) and delivers it over SMTP. Locally SMTP points at Mailpit, so no mail ever reaches a real inbox: open http://localhost:8025 to read it.

```
request ──► outbox_messages (same transaction) ──► outbox worker ──► consumer ──► Mailpit
                                                      │
                                              outbox_receipts: pending → succeeded | failed
```

- The `outbox`, `horizon` and `scheduler` services are long-running PHP processes. They load the application code once, when they start. After you change PHP, switch branches or pull, restart them, or they keep running the old code: `sail restart outbox horizon scheduler`. Symptoms of stale workers are failed receipts with errors such as `Route [...] not defined` or `No consumer named ... is registered`, and mail that never shows up in Mailpit.
- `sail artisan outbox:status` shows the backlog, failed receipts and the age of the oldest overdue work. `sail artisan outbox:recover` releases expired leases and signals receipts that are due.
- A receipt marked `failed` or `exhausted` has used its attempts. After fixing the cause, run `sail artisan outbox:retry --id=<receipt>`, `--consumer=<name>` or `--all`. It puts those receipts back to pending from the first attempt and signals them; it never touches receipts that succeeded or are running, and in production it needs `--force`. Do not edit receipts by hand.
- Mail that does not arrive: check `outbox:status`, then the `last_error` column of the failed row in `outbox_receipts`, then whether the workers are running the current code.
- The end-to-end specs clear the Mailpit inbox before they read mail, so an inbox you were using can empty while they run. Run them against the separate stack described below, never against your development stack.

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

The Cypress specs in `cypress/e2e` run in a real browser against a running application and the demo seed. Cypress runs on the host (Node 22 or newer), not in Sail, and needs Chrome.

**The specs reset the database.** Each one starts with `POST /_testing/reset`, which runs `migrate:fresh` and re-seeds, clears the cache and empties the Mailpit inbox. Never point them at the development stack: they would erase your data. Use the separate stack:

```bash
scripts/dev/e2e-stack.sh up        # a second app container on http://localhost:8081, its own database bitflag_e2e
npm ci                             # once, on the host
CYPRESS_BASE_URL=http://localhost:8081 npm run e2e -- --expose ADMIN_ORIGIN=http://admin.localhost:8081
scripts/dev/e2e-stack.sh down      # removes the container and the database, and rebuilds the normal assets
```

`up` needs the Sail services running. It builds the assets with the component gallery (`VITE_INCLUDE_DEV_PAGES=true`, which production builds leave out), creates and seeds the database, turns the testing routes on, and starts a mail worker. Both stacks share `public/build`, so the development app shows the gallery build until `down` rebuilds it. `E2E_PORT` changes the port. Use `npm run e2e:open` instead of `npm run e2e` to watch a spec run. CI does not use the script: it copies `.env.e2e` over `.env` in a clean checkout.

Specs start from known data. `POST /_testing/reset` rebuilds the database from the demo seed, `POST /_testing/login` signs a seeded person in without the form, `POST /_testing/clock` freezes the server clock and `GET /_testing/clock` reads it, `GET`/`DELETE /_testing/mailpit` read and clear the captured mail, and `/_testing/fail` raises an error to show the 500 page. These routes load only when `TESTING_E2E` is true in the local or testing environment, and every request needs the `X-Testing-Token` header; anything else gets the ordinary 404. In production they do not exist.

Tips: wait for a request with `cy.intercept(...).as(...)` and `cy.wait`, not a fixed delay, before you assert on its result; read the confirmation or reset link from the mail with `cy.latestEmail`; and use `cy.freezeServerClock` to move time, for example past the session limit.
