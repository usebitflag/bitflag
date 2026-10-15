# Shared conventions

Status: draft, 6 October 2026. The application, its tests and the work plans follow these conventions so the parts fit together. A change that breaks one records the reason in the decision register. A default for an open decision may be built only when it does not change permissions, security, privacy, protocol semantics or release scope; otherwise it is blocked until the [decision register](decision-register.md) records a decision (§4).

## Repositories and stack

- `bitflag-protocol`: JSON Schemas, the written spec, and fixtures. No runtime code beyond fixture tooling.
- `bitflag-php`: the pure PHP evaluator (`src/Evaluation`, `src/Protocol`) and later the SDK client (`src/Client`, `src/Transport`, `src/Cache`, `src/Telemetry`). Namespace `BitFlag\`.
- `bitflag`: the application. It uses the latest stable Laravel on the newest supported PHP, Inertia + Vue 3 + TypeScript + Vite, and Tailwind themed from the Nocturne tokens. The admin console is Filament on its own host and guard. It also uses Fortify, Cashier (Stripe), Horizon, MySQL and Redis, with Sail for local development and Mailpit locally for email.
- Quality tooling: Pest (unit, feature, architecture), PHPStan + Larastan (strict), Pint, Vitest, `vue-tsc`, ESLint + Prettier, Cypress, and GitHub Actions.

## Code layout in `bitflag`

```text
app/Modules/{Module}/
  Actions/        final classes, one public handle() method
  Domain/         rules, state machines, value objects, Exceptions/
  Data/           typed inputs and results (readonly classes)
  Http/Controllers/Web/      Inertia controllers
  Http/Controllers/Api/V1/   management API
  Http/Requests/  shape validation only
  Models/         Eloquent models
  Policies/
  Queries/        named read models for pages and reports
  Events/  Jobs/
app/Shared/       Tenancy, Capabilities, Actors, Audit, Outbox, Ids, Errors,
                  Idempotency, Concurrency, Security, Evaluation, Plans
app/Admin/        Filament panel (resources, pages, actions)
resources/js/pages/{area}/…   resources/js/features/{area}/…
resources/js/components/ui/   resources/js/composables/   resources/js/types/
routes/web.php  routes/api.php  routes/sdk.php  routes/admin.php
tests/Unit  tests/Feature/{Module}  tests/Arch  cypress/e2e/{milestone}-{flow}.cy.ts
```

Modules: Identity, Organizations, Projects, Environments, Flags, Targeting, Publishing, Credentials, Experiments, Analytics, Integrations, Audit, Billing, Notifications, Support (admin console back end).

## Identifiers and tables

- Primary keys are ULIDs (`HasUlids`). Public IDs add a type prefix in API and UI: `org_`, `usr_`, `prj_`, `env_`, `flg_`, `var_`, `seg_`, `chg_`, `rev_`, `key_`, `exp_`, `whk_`, `evt_`.
- Prefixes added by milestones: `aud_` (audit entry) and `req_` (request ID) in M0; `rule_` (rule), `ovr_` (override), `sgv_` (segment version), `vvl_` (variation value), `tcx_` (test context) in M4; `cnd_` (review candidate) and `apv_` (approval) in M5; `dlv_` (webhook delivery), `att_` (delivery attempt) and `int_` (provider connection) in M9; `alc_` (experiment allocation) and `met_` (metric definition) in M11. Secret prefixes (`bf_srv_`, `bf_pub_`, `bf_mgt_`, `bf_pat_`, `whsec_`) are not IDs.
- Table names follow the persistence groups in [domain-model.md](domain-model.md) where it names a table. Each milestone's §3.1 is authoritative for the tables it creates and lists any name that differs from the domain model.
- Every tenant-owned table has `organization_id`, plus `project_id` / `environment_id` where they apply. Each has a composite index that starts with `organization_id`.
- Timestamps are UTC, stored as `timestamp(6)`.
- Soft deletes are not used for domain records. Lifecycle is an explicit `status` column.

## Actions

Every write goes through an action:

```php
final class SaveFlagDraft
{
    public function handle(Actor $actor, SaveFlagDraftData $data): SaveFlagDraftResult
    {
        // 1. authorize: $actor->can(Capability::FlagsDraftWrite, $environment)
        // 2. load tenant-scoped state; compare $data->expectedVersion
        // 3. domain rules
        // 4. DB::transaction(): write, Audit::record(...), Outbox::push(...)
        // 5. return typed result
    }
}
```

- `Actor` is a user, a management token (with its creator), the system, or support staff. Actions never read `auth()` directly.
- Optimistic concurrency uses an integer `version` column (or `draft_version`). A mismatch throws `VersionConflict`, which maps to HTTP 409 with code `VERSION_CONFLICT`.
- Audit event names are `{object}.{verb}` in past tense, for example `flag.created`, `revision.published`, `key.revoked`.
- Outbox messages use the webhook event names from R02 where one exists (`revision.published`, `review.requested`, `review.decided`, `flag.changed`, `key.revoked`, `experiment.started`, `experiment.stopped`). Internal-only messages are prefixed `internal.`.
- Email message style: account and security emails produced by M2 and M3 use `internal.email.requested{template, …}`. Notification, billing and support emails come from domain messages (R02 names and other `internal.*` messages) that M8 maps to its email catalog. New producers follow the same split (M8 §3.2).

## Capabilities

Policies check capabilities, not role names. The canonical list is below; milestones may add to it, and must list what they add.

| Capability | Owner | Publisher | Developer | Viewer |
| --- | --- | --- | --- | --- |
| `project.read` | ✓ | ✓ | ✓ | ✓ |
| `project.manage` (settings, delete) | ✓ | | | |
| `members.manage` | ✓ | | | |
| `flags.draft.write` | ✓ | ✓ | ✓ | |
| `segments.write` | ✓ | ✓ | ✓ | |
| `environment.publish` (unprotected) | ✓ | ✓ | ✓ | |
| `environment.publish.protected` | ✓ | ✓ | | |
| `changeset.approve` | ✓ | ✓ | | |
| `environment.manage` | ✓ | | | |
| `credentials.manage` | ✓ | | | |
| `experiments.manage` | ✓ | ✓ | | |
| `integrations.manage` | ✓ | | | |
| `audit.read` | ✓ | ✓ | ✓ | ✓ |
| `sensitive.read` (override keys, include lists) | ✓ | ✓ | ✓ | |
| `changeset.submit` (create, submit, resubmit; added by M5) | ✓ | ✓ | ✓ | |
| `audit.export` (added by M5; every role that reads audit, R17; Pro feature) | ✓ | ✓ | ✓ | ✓ |
| `telemetry.subject.delete` (added by M10) | ✓ | | | |

Org-level capabilities are `org.manage`, `org.members.manage` and `org.billing.manage`, held by the Org Owner (R07). M2 adds `org.read` (Org Owner and Member) and `projects.create` (Org Owner). Staff console capabilities are separate and listed only in M7.

## Evaluation limits

Prerequisite chains: M4 rejects drafts deeper than 5 (authoring limit), and the M1 evaluator treats depth above 10 as `INVALID_CONFIG` (safety ceiling for snapshots that bypassed validation). Both values are intended.

## Errors

The API error envelope is `{ "error": { "code": "VERSION_CONFLICT", "message": "…", "request_id": "req_…", "details": {} } }`. Every response carries a request ID, and the portal shows it on error screens. Codes are upper snake case.

## Frontend

- Inertia pages receive server props plus a `can` map of capabilities for the current scope. The server always rechecks.
- Draft editing state lives in composables (`useFlagDraft`, …), never in page components.
- The Tailwind theme is generated from Nocturne CSS variables (`--color-*`, `--font-*`, `--radius-*`, `--shadow-*`). Components use theme classes, never raw hex values.
- Phosphor icons are installed as an npm dependency (`@phosphor-icons/vue`), not from a CDN.

## Demo data

Seeders recreate the mockup world:
- Organization **Acme**, with projects **Storefront** and **Checkout API**.
- Environments `development`, `staging` and `production`.
- Users Maya Santos (Org Owner, Owner), Jon Reyes (Publisher), Priya Nair (Developer), Ana Cruz (Viewer) and Leo Ramos.
- Flags `new-checkout`, `checkout-layout` and `payment-config`.

Cypress specs run against this seed.
