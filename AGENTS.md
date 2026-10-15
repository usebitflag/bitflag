# AGENTS.md

This file provides guidance for coding agents working in the BitFlag core repository.

## Project Overview

BitFlag is a hosted feature flag management service. This repository is the core application: a
Laravel backend, an Inertia + Vue 3 + TypeScript portal, the management API, SDK delivery endpoints,
workers, and the Filament admin console (own host and guard). It is a modular monolith.

Related repositories: `bitflag-protocol` (schemas and fixtures), `bitflag-php` and `bitflag-js`
(the two independent evaluators). There is no monorepo and no path dependency between them.

The technical documentation is in `docs/`. Start with `docs/README.md`, then `docs/conventions.md`.
Do not invent behavior the docs leave open: ask.

The application is not scaffolded yet.

## Project Structure

```
bitflag/
├── app/
│   ├── Modules/{Module}/     # Identity, Organizations, Projects, Environments, Flags, Targeting,
│   │   ├── Actions/          # Publishing, Credentials, Experiments, Analytics, Integrations,
│   │   ├── Domain/           # Audit, Billing, Notifications, Support
│   │   ├── Data/
│   │   ├── Http/{Controllers/Web,Controllers/Api/V1,Requests}/
│   │   ├── Models/  Policies/  Queries/  Events/  Jobs/
│   ├── Shared/               # Tenancy, Capabilities, Actors, Audit, Outbox, Ids, Errors,
│   │                         # Idempotency, Concurrency, Security, Evaluation, Plans
│   └── Admin/                # Filament panel
├── resources/js/             # pages/, features/, components/ui/, layouts/, composables/, types/
├── routes/                   # web.php (+ web/{module}.php), api.php, sdk.php, admin.php, testing.php
├── tests/                    # Unit/, Feature/{Module}/, Arch/
├── cypress/e2e/              # {area}-{flow}.cy.ts
├── design/nocturne/          # vendored design tokens
└── docs/                     # technical documentation
```

## Core Rules

- Every write goes through an Action: a `final` class with one public `handle(Actor, Data)` method.
  Actions never call `auth()` or `request()`.
- Every tenant table has `organization_id` and a model using `BelongsToOrganization`. Queries without a
  tenant context fail closed. `Tenancy::crossTenant()` is the only bypass.
- Policies and actions check capabilities, never role names.
- Audit and outbox rows are written in the same transaction as the change.
- Secrets (keys, tokens, passwords) are never stored, logged, audited or serialized in plaintext.
- The evaluator is `bitflag-php`; the application never imports its internals.
- Every change goes through a pull request. See `.claude/rules/git-workflow.md`.

Detailed rules live in `.claude/rules/`: `architecture.md`, `api-conventions.md`, `code-style.md`,
`frontend.md`, `testing.md` and `git-workflow.md`.

## Commands

Available once the application is scaffolded:

- `./vendor/bin/sail up -d` starts the app, MySQL, Redis, Mailpit, Horizon and the outbox worker.
- `sail artisan migrate:fresh --seed --seeder=DemoSeeder` resets the demo world.
- `sail composer test` / `vendor/bin/pest` (add `--group=arch` for architecture tests).
- `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`.
- `npm run tokens:check`, `vue-tsc --noEmit`, `vitest run`, `vite build`.
- `npx cypress run` against Sail.
