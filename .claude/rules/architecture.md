# Architecture

Source of truth: `docs/architecture.md` and `docs/conventions.md`.

## Modules

- Modules: Identity, Organizations, Projects, Environments, Flags, Targeting, Publishing, Credentials,
  Experiments, Analytics, Integrations, Audit, Billing, Notifications, Support.
- A module may use another module's `Actions`, `Data`, `Queries`, `Events` and `Domain\Exceptions` only.
  Its `Models`, `Domain`, `Http`, `Policies` and `Jobs` are private to the module (and `App\Admin` for models).
- A module never writes another module's records directly. It calls that module's action.
- `App\Shared` never imports `App\Modules` or `App\Admin`.
- Read-only reporting across modules goes through named `Queries` classes, with the tenant scope kept.

## Actions

1. Authorize: `$actor->can(Capability::…, $scope)`.
2. Load tenant-scoped state and compare `expectedVersion`.
3. Apply domain rules.
4. `DB::transaction()`: write, `Audit::record(…)`, `Outbox::push(…)`.
5. Return a typed result.

- Model writes (`save`, `update`, `delete`, `create`, `insert`, `upsert`, `increment`, …) are allowed only
  in `App\Modules\*\Actions`, `App\Shared\{Audit,Outbox,Idempotency,Concurrency}`, `Database\` and tests.
- Use `Psr\Clock\ClockInterface` for time; never `now()` in actions.

## Controllers and domain

- Controllers validate request shape, call an Action or a Query, and format the result. No `DB` facade,
  no models, no `Domain` classes (exceptions excepted). Never return Eloquent models to Inertia.
- `Domain` classes use no `Illuminate\Http`, facades, Inertia, controllers or global helpers.

## Tenancy

- Add every new table to `config/tenancy.php` (`tenant`, `scoped` or `global`). An unclassified table fails a test.
- Create tenant tables with the `tenantOrganization()`, `tenantProject()`, `tenantEnvironment()` Blueprint
  macros so composite foreign keys and the leading `organization_id` index come by default.
- Queue jobs implement `TenantAware` and use the `RestoreTenantContext` middleware.
- `Tenancy::crossTenant($reason, …)` is allowed only in `App\Shared\{Tenancy,Outbox,Idempotency}`, `App\Admin` and
  `Queries` classes that resolve portal scope or capabilities (an architecture test enforces it).

## Audit and outbox

- Audit event names are `{object}.{verb}` in past tense (`flag.created`) and are registered in `AuditCatalog`.
- Outbox payloads carry IDs and versions only: never secrets, rule contents or subject data. Use the webhook event
  names where one exists; other messages are prefixed `internal.`.
- Consumers are idempotent. Receipts in MySQL are the work list; Redis jobs are only signals.
- `outbox:prune` deletes only messages whose receipts all succeeded before the retention period; never delete or
  edit outstanding work by hand. `outbox:status` shows the backlog and the age of the oldest overdue work.
- Never call an external service inside a database transaction.

## Boundaries that must hold

- Billing state never changes a published flag value or snapshot.
- An integration failure never reverses a committed publication.
- The portal host and the admin host never serve each other's routes; sessions do not cross hosts.
- Only `App\Shared\Evaluation`, `App\Modules\Flags` and `App\Modules\Publishing` may use `BitFlag\`, and never
  its `Internal`, `Client` or `Transport` namespaces.
