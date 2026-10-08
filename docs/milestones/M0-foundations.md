# M0. Foundations

Status: draft for review, 6 October 2026. Expands M0 in [implementation-plan.md](../implementation-plan.md) and follows [00-conventions.md](00-conventions.md).

## 1. Goal and scope

M0 sets up the repositories, the application skeleton and the shared building blocks that every later milestone uses. After M0, a signed-in demo user sees an empty portal page in the Nocturne style, inside the real shell (sidebar, environment picker, top bar), and CI is green in all four repositories.

**In scope**

- Create `bitflag-protocol`, `bitflag-php`, `bitflag-js` and `bitflag`, each with CI, branch protection and quality tooling. The protocol and both evaluator repositories get only a skeleton here; M1 fills them.
- Scaffold `bitflag`: Sail (app, MySQL, Redis, Mailpit, plus Horizon and outbox worker processes), Fortify installed with sign-in and sign-out only, Inertia + Vue 3 + TypeScript + Vite, Tailwind themed from Nocturne, Phosphor icons, Filament as an empty panel on its own host and guard, and Horizon.
- Module skeleton for all 15 modules with service providers, and the split route files.
- `app/Shared` foundations: tenancy scoping with an enforcement test, capabilities and the role map, `Actor`, the audit recorder and `audit_events`, the outbox (`outbox_messages`, `outbox_receipts`, dispatcher worker), ULID and public-ID helpers, the error envelope and request IDs, `idempotency_records`, and an optimistic version helper.
- "Tenancy spine" tables so scoping, the switchers and the seeders have real rows: `organizations`, `projects`, `environments`, `organization_memberships`, `project_memberships` (minimal columns; see 3.1).
- Pest architecture tests for module and evaluator boundaries, a PHPStan rule for writes outside actions, and a CI canary that proves they fail on a deliberate violation.
- Portal shell layout, organization settings layout, error pages, and the UI component baseline from the Design System mockup, with a dev-only component gallery. The portal ships both the dark and the light theme, with a theme switch in the user menu (3.5).
- Demo seeders for the mockup world, and a Cypress harness that runs against Sail in CI.

**Out of scope (owner milestone)**

- Sign-up, verification, two-factor, reset, lockout, sessions page, invitations, onboarding, org settings pages, membership write actions (M2).
- Project and environment write actions, protection policies, browser origins, credentials (M3).
- Any flag, segment, publishing, billing, notification or integration feature (M4 to M11). The nav shows only pages that exist.
- Staff MFA, staff roles, console resources and support actions (M7). M0 only proves the panel boots on its own host and rejects portal sessions.
- Audit log UI and export (M5). M0 only writes audit rows.

## 2. Mockups covered

| Mockup | Artboard or section | What M0 takes from it |
| --- | --- | --- |
| `BitFlag Sidebar.dc.html` | Component props `active`, `env`, `role`, `showEnv`, `badges`, `rev` | Sidebar order: Overview, Flags, Segments, Experiments, Changes, Analytics, Audit, Integrations, Settings. Active item: 7% text tint plus a 2px inset accent edge. Count pills on items. Environment icons: `development` code, `staging` flask, `production` shield-check, anything else stack. User block shows initials, name and project role |
| `portal/Overview.dc.html` | Shell markup | Grid `240px minmax(0,1fr)`, sticky full-height sidebar, top bar with breadcrumbs, environment picker as a `listbox` button with `rev N`, menu of environments with revisions and a "Manage environments" link |
| `BitFlag Overview.dc.html` | 1a populated, 1b new project | Breadcrumb "Acme / Storefront / Overview", search trigger "Search flags, segments, audit ⌘K", "Organization settings" link under the nav, nav counts (Flags 48, Segments 9, Experiments 2, Changes 3), picker label `development · no revision` before first publish |
| `BitFlag Organization.dc.html` | 1a General (layout only) | Org settings variant of the shell: "A Organization Acme", "Back to Storefront", sections General, Members, Billing & plans, Security, Projects |
| `BitFlag Design System.dc.html` | 02 Themes, 03 Color, 04 Type, 05 Spacing, 06 Icons, 07 Components, 09 Portal | Tokens and light-theme overrides, status chips, type scale, radius usage, icon sizes (16 in controls, 20 in navigation), buttons, fields, choice controls, cards, table, type-to-confirm dialog, empty state, toast, banners, dropdown menu, tabs with counts, page header, breadcrumbs, environment labels, avatars |
| `BitFlag Environments.dc.html` 2f, `BitFlag Settings.dc.html` 2c | Read-only states | Neutral outline banner "You're a Viewer. Everything here is read-only." |
| `BitFlag Portal Prototype.dc.html` | Demo strip | The demo controls (publish toggle, reset) stay out of the product. M0 keeps test controls behind testing-only routes instead (frontend-and-design §8) |

Rules taken from the mockups:

- Primary buttons are outlined, never filled. Destructive actions use neutral buttons and confirm in a dialog that names the object; type-to-confirm keeps the button disabled until the typed key matches.
- Color never carries meaning alone: status chips pair a tint with a label, and runtime states add a dot. Environment label color is identification only; risk is shown by a "Protected" tag (M3).
- Toasts sit bottom right, disappear after 4 seconds and name the object and environment.
- Dropdown menus are layered above tables, never clipped. Destructive items sit below a divider.
- The environment menu shows a search field when a project has more than 5 environments.
- Inter is the only sans-serif face and covers headings and body text everywhere. IBM Plex Mono is used for code, keys, IDs, and monospace numbers such as revisions and counts; other numbers use tabular figures in Inter.

## 3. Design

### 3.1 Data model

All primary keys are ULIDs (`HasUlids`, `char(26)`). Timestamps are `timestamp(6)` UTC. Tenant tables follow the conventions: `organization_id` not null, and a composite index starting with it.

**Tenancy spine.** M0 creates these with identity columns only. The schema belongs to the named module; M2 and M3 add columns and all write actions through their own migrations. No write action for them exists in M0; only seeders write them.

| Table (owner module) | Columns | Indexes and constraints |
| --- | --- | --- |
| `organizations` (Organizations) | `id`, `name` varchar(120), `slug` varchar(40), `status` enum(`active`,`suspended`,`deletion_scheduled`) default `active`, `created_at`, `updated_at` | unique(`slug`). The tenant root; not itself tenant-scoped |
| `projects` (Projects) | `id`, `organization_id`, `name` varchar(80), `key` varchar(40), `description` varchar(280) null, `status` enum(`active`,`archived`,`deletion_scheduled`,`deleted`), timestamps | unique(`organization_id`,`key`); unique(`id`,`organization_id`) as composite FK target; index(`organization_id`,`status`) |
| `environments` (Environments) | `id`, `organization_id`, `project_id`, `name` varchar(40), `key` varchar(40), `color` enum(`slate`,`lilac`,`violet`,`indigo`,`ink`), `position` smallint unsigned, `status` enum(`active`,`archived`), timestamps | unique(`organization_id`,`project_id`,`key`); unique(`id`,`project_id`,`organization_id`); FK (`project_id`,`organization_id`) → `projects`(`id`,`organization_id`); index(`organization_id`,`project_id`,`position`) |
| `organization_memberships` (Organizations) | `id`, `organization_id`, `user_id`, `role` enum(`owner`,`member`) (Org Owner, Member), timestamps | unique(`organization_id`,`user_id`); index(`user_id`) |
| `project_memberships` (Organizations) | `id`, `organization_id`, `project_id`, `user_id`, `role` enum(`owner`,`publisher`,`developer`,`viewer`), timestamps | unique(`organization_id`,`project_id`,`user_id`); FK (`project_id`,`organization_id`) → `projects`; index(`user_id`,`organization_id`) |

**Global (non-tenant) tables**

| Table | Columns | Notes |
| --- | --- | --- |
| `users` (Identity) | Laravel default plus `timezone` varchar(64) default `UTC`, `status` enum(`active`,`deactivated`), `last_organization_id`, `last_project_id`, `last_environment_id` (nullable, `nullOnDelete`) | `email` unique. The `last_*` columns remember the last scope (accounts doc §3). Fortify's `two_factor_*` columns are not added: two-factor lives in M2's `mfa_factors` |
| `sessions` | Laravel database session table, `user_id` char(26) | Database driver so M2 can list and revoke sessions |
| `staff_users` (Support) | `id`, `name`, `email`, `password`, `status` enum(`active`,`disabled`), `remember_token`, timestamps | Admin console identities only; never joined to `users`. M7 adds MFA and staff roles |
| `cache`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens` | Laravel defaults | Listed in `config/tenancy.php` as global |

**Shared infrastructure tables (tenant-scoped)**

`audit_events` (written by `App\Shared\Audit`, read side owned by the Audit module in M5):

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | Public ID `aud_…` (matches "Entry aud_…" in the Design System drawer) |
| `organization_id`, `project_id`, `environment_id` | char(26), last two null | Scope of the change |
| `actor_type` | enum(`user`,`management_token`,`system`,`support`) | |
| `actor_id` | char(26) null | User, token or staff user ID; null for `system` |
| `actor_display` | varchar(120) | Stable display name kept after account deletion |
| `via_token_id` | char(26) null | Personal access token used by a user actor |
| `token_creator_id` | char(26) null | Creator of a management token (R03) |
| `channel` | enum(`dashboard`,`api`,`cli`,`mcp`,`system`,`support`) | |
| `action` | varchar(64) | `{object}.{verb}`, past tense |
| `target_type`, `target_id`, `target_display` | varchar(32), char(26) null, varchar(160) null | |
| `from_revision`, `to_revision` | int unsigned null | |
| `reason` | text null | |
| `request_id`, `correlation_id` | varchar(40) null | |
| `details` | json null | Redacted before insert |
| `occurred_at` | timestamp(6) | |

Indexes: (`organization_id`,`occurred_at`,`id`), (`organization_id`,`project_id`,`occurred_at`), (`organization_id`,`target_type`,`target_id`), (`organization_id`,`action`,`occurred_at`), (`organization_id`,`actor_type`,`actor_id`).

`outbox_messages`:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | char(26) | Event identity; becomes the webhook `evt_` ID in M9 |
| `scope` | enum(`organization`,`account`,`system`) | `organization` for tenant events; `account` for events about a person before or outside any organization (sign-up, verification, password reset); `system` for platform maintenance |
| `organization_id` | char(26) null | Required when `scope = organization` (CHECK constraint), null otherwise |
| `user_id` | char(26) null | Required when `scope = account` (CHECK constraint) |
| `project_id`, `environment_id` | char(26) null | |
| `type` | varchar(64) | R02 names or `internal.*` |
| `aggregate_type`, `aggregate_id` | varchar(32), char(26) | |
| `payload` | json | IDs and versions only; never secrets, rule contents or subject data |
| `request_id` | varchar(40) null | |
| `occurred_at`, `available_at` | timestamp(6) | |
| `dispatched_at` | timestamp(6) null | Set when receipts are created |
| `attempts` | smallint unsigned | Dispatch attempts |
| `claimed_by`, `claimed_until` | varchar(64) null, timestamp(6) null | Worker lease |
| `last_error` | varchar(500) null | |

Indexes: (`organization_id`,`occurred_at`), (`scope`,`user_id`,`occurred_at`), (`dispatched_at`,`available_at`).

`outbox_receipts` (addition to the domain model table map; one row per message and consumer so each consumer retries and replays independently):

| Column | Type |
| --- | --- |
| `id`, `outbox_message_id` | char(26) |
| `scope`, `organization_id`, `user_id` | copied from the message, same CHECK constraints |
| `consumer` | varchar(100), the consumer class alias |
| `status` | enum(`pending`,`processing`,`succeeded`,`failed`,`exhausted`) |
| `attempts` | smallint unsigned |
| `next_attempt_at`, `processed_at` | timestamp(6) null |
| `lease_owner`, `lease_until` | varchar(64) null, timestamp(6) null; set while `processing` |
| `last_signalled_at` | timestamp(6) null; when a queue job was last pushed for this receipt |
| `last_error` | varchar(500) null |

Indexes: unique(`outbox_message_id`,`consumer`), (`status`,`next_attempt_at`), (`status`,`lease_until`), (`organization_id`,`status`,`next_attempt_at`).

The receipt row, not the queue, is the authoritative record of outstanding work (see 3.6).

`idempotency_records`:

| Column | Type | Notes |
| --- | --- | --- |
| `id`, `organization_id` | char(26) | |
| `actor_key` | varchar(40) | Public ID of the user or token, for example `usr_…` |
| `operation` | varchar(64) | Action name, for example `publish_environment` |
| `idempotency_key` | varchar(255) | Client supplied |
| `request_fingerprint` | char(64) | SHA-256 of the canonical request |
| `status` | enum(`in_progress`,`completed`) | |
| `response_status` | smallint null | |
| `response_body` | json null | Never contains one-time secrets |
| `result_type`, `result_id` | varchar(32) null, char(26) null | |
| `created_at`, `completed_at`, `expires_at` | timestamp(6), last two null | |

Indexes: unique(`organization_id`,`actor_key`,`operation`,`idempotency_key`), (`expires_at`).

**Migration helpers.** Blueprint macros in `App\Shared\Tenancy\Schema\TenantColumns`: `$table->tenantOrganization()`, `$table->tenantProject()`, `$table->tenantEnvironment()`. Each adds the columns, the composite FKs (`(project_id, organization_id)` → `projects`, `(environment_id, project_id, organization_id)` → `environments`) and a leading `organization_id` index, so later milestones get composite ownership constraints by default.

### 3.2 Domain rules

**Identifiers (`App\Shared\Ids`)**

- `IdPrefix` enum: the convention prefixes plus two M0 additions, `aud_` (audit events) and `req_` (request IDs).
- Public ID = prefix + the lowercase ULID, for example `prj_01j9x3…`. It is derived, never stored. `PublicId::encode(IdPrefix, string $ulid)` and `PublicId::decode(IdPrefix $expected, string $publicId)`; decode rejects a wrong prefix or a malformed ULID with `NOT_FOUND` (never a 500).
- `HasPublicId` model trait: `public_id` accessor, `scopeWherePublicId()`, and `resolveRouteBinding()` that decodes and then queries through the tenant scope, so a valid ID from another organization is a 404.
- Slugs and keys are for URLs and display; authorization never relies on them.

**Tenancy (`App\Shared\Tenancy`)**

- `TenantContext` (scoped binding, reset per request and per job): `organizationId()`, `set(string)`, `run(string $orgId, Closure)`, `clear()`.
- `BelongsToOrganization` trait: adds `OrganizationScope`, which appends `table.organization_id = ?` from the context. With no context it throws `TenantContextMissing` (fail closed). It fills `organization_id` on create, and throws `CrossTenantWrite` if a model's `organization_id` differs from the context on save.
- `Tenancy::crossTenant(string $reason, Closure)` is the only bypass. It is allowed for the outbox dispatcher, scheduled maintenance, the admin console (M7) and the membership lookup that resolves the scope, and it logs the reason at debug level.
- Queue jobs implement `TenantAware` (public `organizationId`). Job middleware `RestoreTenantContext` sets the context before `handle()` and clears it after, including on failure.
- Every table is classified in `config/tenancy.php` as `tenant` (has `organization_id`), `scoped` (has a `scope` column and `organization_id` required by CHECK constraint when `scope = organization`; today only `outbox_messages` and `outbox_receipts`), or `global`. An unclassified table fails the tenant-table test. Reads of `scoped` tables outside the dispatcher must filter by `organization_id` or by `scope` and `user_id`.

**Capabilities (`App\Shared\Capabilities`)**

- `Capability` backed string enum with every value in the conventions table, plus `org.manage`, `org.members.manage`, `org.billing.manage`. M0 adds no new capabilities.
- `ProjectRole` enum (`owner`, `publisher`, `developer`, `viewer`) and `OrgRole` enum (`owner` shown as "Org Owner", `member`).
- `RoleCapabilityMap::for(ProjectRole): list<Capability>` encodes the conventions table exactly; `OrgRoleCapabilityMap` gives Org Owner the three org capabilities. A Billing Admin role (Organization mockup 1b) would map to `org.billing.manage` only; it is not built (R07).
- `CapabilityResolver::can(Actor, Capability, Scope): bool`. `Scope` is `OrganizationScope | ProjectScope | EnvironmentScope`. M0 resolves user actors through the spine memberships. Environment-sensitive capabilities go through an `EnvironmentProtection` interface; its M0 binding treats every environment as unprotected, and M3 replaces it. Token actors go through a `TokenScopeMap` interface that M3 implements; in M0 it grants nothing.
- An Org Owner is not automatically a project member (accounts doc §6), so org capabilities never imply project capabilities.

**Actor (`App\Shared\Actors`)**

- `Actor` is a readonly value object built with named constructors: `user(User, Channel)`, `personalToken(User, tokenId, Channel)`, `managementToken(tokenId, creatorUserId, projectId, Channel)`, `system(string $process)`, `support(StaffUser, ?string $ticket)`.
- Methods: `can(Capability, Scope)`, `type()`, `id()`, `displayName()`, `channel()`, `auditAttributes()`.
- `ResolveActor` middleware builds the actor from the session (channel `dashboard`) and binds it to the request. Controllers receive it as a method parameter (contextual binding); actions receive it as an argument. Actions never call `auth()` or `request()`.

**Actions and concurrency**

- Pattern exactly as in the conventions. M0 adds `App\Shared\Concurrency\VersionedUpdate::apply(Builder $query, int $expectedVersion, array $changes)`, which runs `UPDATE … SET …, version = version + 1 WHERE id = ? AND organization_id = ? AND version = ?` and throws `VersionConflict` when no row changed, with `details.expectedVersion` and `details.currentVersion` read inside the same transaction.
- Clock: actions take `Psr\Clock\ClockInterface` (bound to a Carbon-backed `SystemClock`), so tests and Cypress can freeze time.

**Audit (`App\Shared\Audit`)**

- `AuditRecorder::record(Actor, string $action, AuditTarget $target, array $details = [], ?string $reason = null, ?RevisionChange $revisions = null)`. It must run inside the caller's transaction: it throws `AuditOutsideTransaction` when `DB::transactionLevel() === 0`.
- Action names are validated against `{object}.{verb}` (`/^[a-z_]+\.[a-z_]+$/`), and module providers register their names in `AuditCatalog`, so a typo fails tests.
- `Redactor` runs on `details` before insert. It drops keys matching `password|secret|token|plaintext|key_value|context|attributes|authorization` at any depth, and truncates strings over 500 characters.
- `AuditEvent` model is append-only: its `updating` and `deleting` events throw. Retention is D09; nothing is pruned in M0.

**Outbox (`App\Shared\Outbox`)**

- `Outbox::push(OutboxMessage $message)`, inside the transaction (same guard as audit). Messages carry IDs and versions only.
- Consumers implement `OutboxConsumer` (`handles(): list<string>`, `consume(OutboxMessage): void`) and are registered in module service providers. Consumers must be idempotent; the receipt row is their deduplication key.
- Delivery is at least once. Dispatch never calls external services inside a database transaction.

**Idempotency (`App\Shared\Idempotency`)**

- `Idempotency::run(IdempotencyScope $scope, string $key, string $fingerprint, Closure $operation)`. Inside the action's transaction it inserts an `in_progress` row (the unique index is the reservation), runs the operation, and stores the result in the same commit.
- Same key and fingerprint after completion: returns the stored result. Different fingerprint: `IDEMPOTENCY_KEY_REUSED` (409). Concurrent duplicate while in progress: `IDEMPOTENCY_IN_PROGRESS` (409, `Retry-After: 1`).
- The stored body never includes one-time secrets; a replay of a secret-issuing operation returns metadata with `details.secret_not_replayable = true` (D14 default).
- Records expire after 24 hours (D14 default); `idempotency:prune` runs hourly.

**Errors and request IDs (`App\Shared\Errors`)**

- `AssignRequestId` middleware (first in every group): generates `req_` + ULID, ignores any inbound value, sets the `X-Request-Id` response header, adds it to the log context and shares it with Inertia.
- `ApplicationException` base class with `errorCode()`, `httpStatus()`, `details()`, `publicMessage()`. The exception handler maps:

| Exception | HTTP | Code |
| --- | --- | --- |
| `AuthenticationException` | 401 | `UNAUTHENTICATED` |
| `AuthorizationDenied`, `AuthorizationException` | 403 | `FORBIDDEN` |
| `ModelNotFoundException`, `ResourceNotFound`, bad public ID | 404 | `NOT_FOUND` |
| `TokenMismatchException` | 419 | `SESSION_EXPIRED` |
| `ValidationException` | 422 | `VALIDATION_FAILED`, `details.fields[] = {path, code, message}` |
| `VersionConflict` | 409 | `VERSION_CONFLICT` |
| `IdempotencyKeyReused`, `IdempotencyInProgress` | 409 | `IDEMPOTENCY_KEY_REUSED`, `IDEMPOTENCY_IN_PROGRESS` |
| `ThrottleRequestsException` | 429 | `RATE_LIMITED` with `Retry-After` |
| `TenantContextMissing`, `CrossTenantWrite`, anything else | 500 | `INTERNAL_ERROR`, no details |

- API and SDK routes always return the envelope `{ "error": { "code", "message", "request_id", "details" } }`. Inertia requests get `errors/Error` for 403, 404, 419, 429, 500 and 503 with the request ID. Validation errors on Inertia forms use the normal Inertia error bag. Stack traces, SQL and other tenants' data never appear outside `APP_DEBUG=true` locally.

### 3.3 Actions

M0 has no product actions. It ships the shared services above and these:

| Action | Input | Capability | Transaction contents | Audit / outbox |
| --- | --- | --- | --- | --- |
| `Identity\Actions\RememberLastScope` | `Actor`, `RememberLastScopeData{organizationId, projectId, environmentId}` | Membership of the project (checked through `CapabilityResolver` with `project.read`) | Update `users.last_*` | None (preference, not audited) |
| `Identity\Actions\Fortify\AuthenticateUser` | Fortify credentials | None | None | None (sign-in is a security log, accounts doc §8) |
| `tests/Support/Actions/RenameWidget` (test-only) | `Actor`, `RenameWidgetData{widgetId, expectedVersion, name}` | `project.manage` | `VersionedUpdate` on `test_widgets`, `Audit::record('widget.renamed')`, `Outbox::push('internal.widget_renamed')`, `Idempotency::run` | Reference implementation of the full pattern; its tests prove rollback when audit or outbox insert fails |

Fortify's published classes move from `app/Actions/Fortify` to `app/Modules/Identity/Actions/Fortify` so the "writes only in actions" rule holds. M2 adds registration, reset and two-factor actions there.

### 3.4 Routes

Hosts come from `config/bitflag.php`: `hosts.app` (`APP_HOST`, local `localhost`) and `hosts.admin` (`ADMIN_HOST`, local `admin.localhost`). Portal routes are bound to the app host; the Filament panel to the admin host. Neither host serves the other's routes.

`routes/web.php` declares the groups and requires `routes/web/{module}.php`, so parallel milestones don't edit one file. `routes/api.php` (prefix `/api/v1`, middleware `api`, error envelope) and `routes/sdk.php` (prefix `/sdk/v1`, no session or CSRF) are registered empty. `routes/admin.php` holds non-Filament staff routes (empty). `routes/testing.php` loads only when `config('testing.e2e')` is true and the environment is `local` or `testing`.

**Portal URL scheme.** Organization slugs are global ("bitflag.dev/acme" in the Organization mockup), so portal URLs start with the slug: `/{organization:slug}/{project:key}/{environment:key}/{page}`. M2 owns slug rules and the reserved list (slugs start with a letter, so `_dev` and `_testing` can't collide).

| Method and path | Name | Page / response | Notes |
| --- | --- | --- | --- |
| `GET /signin`, `POST /signin`, `POST /signout` | Fortify (re-registered with `Fortify::ignoreRoutes()` on the paths M2 and the website use) | `auth/Login` | Minimal screen on the UI baseline; M2 replaces it with the Auth mockup screens |
| `GET /` | `home` | Redirect | To the remembered scope, else the first project the user can read, else `account/NoAccess` |
| `GET /{organization}` | `organization.home` | Redirect | To the last or first readable project in that organization |
| `GET /{organization}/{project}` | `project.home` | Redirect | To `{environment}/overview` for the remembered or first environment by `position` |
| `GET /{organization}/{project}/{environment}/overview` | `project.overview` | `overview/Show` | Blank page in M0 (page header only); M5 fills it |
| `GET /{organization}/settings` | `organization.settings` | Redirect | Placeholder target for the "Organization settings" link; M2 adds the pages and the layout is ready |
| `POST /scope/remember` | `scope.remember` | 204 | Calls `RememberLastScope` after navigation |
| `GET /_dev/ui` | `dev.ui` | `dev/UiGallery` | Local and testing only; every baseline component in every state |
| `POST /_testing/reset`, `/_testing/login`, `/_testing/clock`, `/_testing/fail` | testing | JSON | Cypress harness (3.7) |
| `GET /up` | Laravel | 200 | Health check used by CI `wait-on` |
| Admin host `/` and `/login` | Filament | Filament dashboard and login | Empty panel |

`ResolvePortalScope` middleware (on every `/{organization}/…` route):

1. Resolve the organization by slug, then the project by key inside it, then the environment by key inside the project.
2. Verify the user has an `organization_memberships` row and a `project_memberships` row (inside `Tenancy::crossTenant('scope resolution')`). If not, return 404, so existence is never disclosed.
3. Set `TenantContext`, bind `PortalScope` (organization, project, environment, project role, org role).
4. Reject an archived environment or project with 404 until M3 defines archived views.

**Inertia shared props** (`HandleInertiaRequests`):

```ts
type SharedProps = {
  auth: { user: { id: string; name: string; email: string; initials: string } | null }
  scope: {
    organization: { id: string; slug: string; name: string } | null
    project: { id: string; key: string; name: string } | null
    environment: { id: string; key: string; name: string; color: string; revision: number | null } | null
    projectRole: 'owner' | 'publisher' | 'developer' | 'viewer' | null
    orgRole: 'owner' | 'member' | null
  }
  environments: Array<{ id: string; key: string; name: string; color: string; revision: number | null }>
  switcher?: { organizations: Array<{ id; slug; name; projects: Array<{ id; key; name }> }> } // lazy, partial reload on open
  navigation: Array<{ key: string; label: string; icon: string; href: string; count: number | null; active: boolean }>
  can: Partial<Record<CapabilityName, boolean>>
  flash: { toast: { message: string; tone: 'neutral' | 'accent' } | null }
  requestId: string
}
```

`revision` is `null` until M5 adds the publication pointer; the picker then shows "no revision". `navigation` is built server side from `PortalNavigation`, a registry that module providers add to with a fixed order key. M0 registers Overview only. Counts are computed by each module's registered count query, deferred (`Inertia::defer`) so they never block the page.

### 3.5 Frontend

**Stack.** Inertia (Vue adapter), Vue 3 `<script setup lang="ts">`, Vite, Tailwind v4, `@phosphor-icons/vue`, Laravel Wayfinder for typed route helpers (Ziggy if Wayfinder is not stable at scaffold time), Reka UI as headless primitives for dialog, menu, popover, tabs, switch, tooltip and toast (proposal; see §6), `@fontsource-variable/inter` and `@fontsource/ibm-plex-mono` served from the app instead of Google Fonts. No Laravel starter kit: its component library and auth pages would fight the Nocturne components and the M2 screens. No SSR for the portal.

**Theme generation.** Nocturne's variables already use Tailwind v4 theme namespaces, so the generator maps them almost one to one.

- Source: `design/nocturne/styles.css` (vendored copy of `_ds/nocturne-*/styles.css` with its SHA-256 in `design/nocturne/SOURCE`), plus `design/nocturne/light.json` (the light overrides from the Design System mockup, 02 Themes).
- `npm run tokens` (`scripts/tokens/generate-theme.mjs`) writes `resources/css/theme.generated.css`; `npm run tokens:check` fails CI if the output differs.

| Nocturne variable | Generated theme variable | Example utility |
| --- | --- | --- |
| `--color-bg`, `--color-surface`, `--color-text`, `--color-accent`, `--color-accent-2`, `--color-divider` | same names | `bg-bg`, `bg-surface`, `text-text`, `border-accent`, `border-divider` |
| `--color-neutral-100` … `--color-neutral-900` | same | `bg-neutral-800`, `text-neutral-500` |
| `--color-accent-100` … `--color-accent-900` | same | `bg-accent-900`, `text-accent-300` |
| `--color-accent-2-100` … `-900` | same (kept so both sets resolve; the readme says treat them as one role) | rarely used |
| `--color-section`, `--color-section-glow`, `--color-section-ghost` | same | Not used in the portal (deck and landing only) |
| `--font-heading`, `--font-body` | same (both Inter, from Nocturne), plus `--font-mono: "IBM Plex Mono", ui-monospace, monospace` for code, keys, IDs, revisions and counts | `font-heading`, `font-body`, `font-mono` |
| `--font-heading-weight` (500) | `--font-weight-heading` | `font-heading` weight via base layer |
| `--space-1` … `--space-8` (2.8px steps) | `--spacing: 2.8px` | `p-1` 2.8, `p-2` 5.6, `p-3` 8.4, `p-4` 11.2, `p-6` 16.8, `p-8` 22.4 (exact match to Nocturne) |
| `--radius-sm`, `--radius-md`, `--radius-lg` | same | `rounded-sm` chips and table inputs, `rounded-md` controls and cards, `rounded-lg` panels and dialogs |
| `--shadow-sm`, `--shadow-md`, `--shadow-lg` | same | `shadow-sm` cards and inputs, `shadow-md` menus, `shadow-lg` dialogs |

- Derived tokens (generated, documented in the file): `--color-text-muted` (text 55%), `--color-text-secondary` (text 65%), `--color-hover` (text 7%, active nav), `--color-row-rule` (text 8%), `--color-selection` (accent 30%).
- Type scale (from Design System 04 and portal pages): `--text-2xs` 11px, `--text-xs` 12px, `--text-sm` 13px, `--text-md` 13.5px, `--text-base` 15px, `--text-lg` 16px, `--text-xl` 20px, `--text-2xl` 25px, `--text-page` 28px, `--text-3xl` 32px, `--text-4xl` 42px, `--text-display` 76px. Headings stay at weight 500.
- Tailwind's default palette is cleared (`--color-*: initial`) so raw colors like `red-500` don't exist. An ESLint rule (`no-restricted-syntax` on hex literals in `.vue` and `.ts`) and a Stylelint rule block raw hex outside the generated file.
- Both themes ship (R18). Dark values are the base; light values are emitted under `[data-theme="light"]`. `useTheme()` offers `dark`, `light` and `system`; the default is `dark` (Nocturne is dark-first), and `system` follows `prefers-color-scheme`. The choice is stored in `localStorage` (`bitflag-theme`). A small nonce'd inline script in `app.blade.php` sets `data-theme` before first paint, so there is no flash of the wrong theme. Saving the choice on the account (a `users` column) belongs to M2's account settings; until then it is per browser.
- The light overrides are checked in `tokens:check`: text on background, muted text on background, and accent on background must meet WCAG AA (4.5:1 for text, 3:1 for the accent edge and focus ring) in both themes, or the generator fails.
- The portal mockups draw no theme switch, so its placement (user menu, three-option segmented control) is a proposal and needs a design pass.
- Base layer: `:focus-visible` 2px accent outline with 2px offset, `::selection` tint, disabled at 45% opacity, tabular numbers via `.tnum`.

**Layouts and shell** (`resources/js/layouts`, `resources/js/features/shell`)

| Component | Behavior |
| --- | --- |
| `layouts/PortalLayout.vue` | Grid 240px sidebar plus content, sticky sidebar, top bar, `<main>` with page slot, toast viewport, role banner slot. Below 1024px the sidebar becomes an off-canvas drawer opened from a top bar button |
| `layouts/OrgSettingsLayout.vue` | Same frame; sidebar shows "Organization {name}", "Back to {last project}" and the five org sections (links registered by M2 and M6) |
| `layouts/AuthLayout.vue` | Centered card on `bg-bg` for the sign-in screen |
| `features/shell/AppSidebar.vue` | Brand mark, `OrgProjectSwitcher`, `EnvironmentPicker`, `SidebarNav`, "Organization settings" link (only with `org.manage`), `UserBlock` |
| `features/shell/BrandMark.vue` | The pole and six-bit mark (last bit outlined), 20px in the sidebar |
| `features/shell/OrgProjectSwitcher.vue` | Card button with org initial tile, org name (11px muted) over project name, `caret-up-down`. Popover lists organizations and their projects from the lazy `switcher` prop. Selecting navigates to `project.home` |
| `features/shell/EnvironmentPicker.vue` | `listbox` button: environment icon, key in mono, `rev N` or "no revision", `caret-down`. Menu: options with revisions, search field above 5 environments, divider, "Manage environments" (shown only when M3 registers the route). Switching keeps the current page and swaps the `{environment}` segment, after the navigation guard |
| `features/shell/SidebarNav.vue` | Items from `navigation`; active style from the Sidebar mockup; count pills with `aria-label` "48 flags" |
| `features/shell/UserBlock.vue` | Initials avatar, name, project role label; menu with a theme switch (Dark, Light, System) and "Sign out" (M2 adds account links) |
| `features/shell/TopBar.vue` | `Breadcrumbs` (organization, project, page, object), `CommandPaletteTrigger` "Search flags, segments, audit ⌘K" |
| `features/shell/CommandPalette.vue` | Dialog with a provider registry. M0 ships only the "Go to" provider (nav items, switch environment). M4 and M5 add search providers |
| `features/shell/RoleBanner.vue` | Neutral outline banner "You're a Viewer. Everything here is read-only." when `projectRole === 'viewer'` |
| `pages/overview/Show.vue` | `PageHeader` "Overview · {environment}" only |
| `pages/errors/Error.vue` | Status title, one sentence, request ID with copy button, link home |
| `pages/auth/Login.vue`, `pages/account/NoAccess.vue` | Minimal screens on the baseline components |

**UI baseline** (`resources/js/components/ui`). Each component has typed props, `data-test` hooks and a gallery entry.

| Component | Variants and states (Design System §07 and §09) |
| --- | --- |
| `Button.vue` | `primary` (accent outline), `secondary`, `ghost`, `destructive` (neutral, used with confirm), sizes `sm`/`md`, `loading`, `disabled`, icon slot, `block` |
| `IconButton.vue` | Required `label` for `aria-label`, tooltip |
| `Input.vue`, `Textarea.vue`, `Select.vue` | Invalid state, mono option for keys, prefix and suffix slots |
| `Field.vue` | Label, help text, error text tied with `aria-describedby` |
| `SegmentedControl.vue`, `RadioGroup.vue`, `Checkbox.vue`, `Switch.vue` | Switch and checkbox with help text ("Client-visible by default · Leaving this off is safer.") |
| `Tag.vue` | `accent`, `neutral`, `outline`, optional `dot`; mono option |
| `StatusTag.vue` | Maps status names to variants: accent for working (`Active`, `Healthy`, `Live`, `Enabled`), outline for needs a look or in progress (`Draft`, `Rotating`, `Failing`, `Unpublished changes`), neutral for ended (`Revoked`, `Archived`, `Disabled`, `Deprecated`). Runtime states add the dot |
| `EnvLabel.vue` | Environment key in mono with its label color as identification only |
| `KeyTag.vue` | Copyable key under detail page titles |
| `Card.vue`, `StatCard.vue` | Kicker, title, body, meta; stat card label, mono value, meta, optional link |
| `DataTable.vue` | Typed columns, header and row rules, sortable header slot, row action slot, `loading` skeleton rows, `empty` slot, horizontal scroll container below 1024px |
| `Dialog.vue`, `ConfirmDialog.vue` | Focus trap, Esc and backdrop close, focus restore; confirm dialog names the object and supports `confirmText` type-to-confirm |
| `DropdownMenu.vue` | Portal-rendered (never clipped), keyboard navigation, destructive group below a divider |
| `Tabs.vue` | Counts in tabs ("Keys 4"), URL-synced option |
| `Toast` (`ToastViewport.vue` + `useToast`) | Bottom right, 4 seconds, `aria-live="polite"`, pause on hover, action button ("Undo") |
| `Banner.vue`, `InlineNotice.vue` | Banner `neutral` (role limits) and `attention` (accent tint); inline notice for conflicts and warnings |
| `EmptyState.vue` | Title, one sentence, primary and secondary actions ("No keys yet … Create key · Read the guide") |
| `Skeleton.vue`, `Spinner.vue` | Loading states |
| `Avatar.vue`, `Kbd.vue`, `Breadcrumbs.vue`, `PageHeader.vue` | Page header: title, one sentence, actions slot, optional `KeyTag` and tags |

Deferred to the milestone that first needs them: drawer (M5 audit), chip input and stepper with choice cards (M3), key reveal (M3), member row (M2), filter chips and rule card (M4), percentage distribution, split bar and evaluation result (M4), diff viewer, change item and activity timeline (M5), code block (M5 onboarding), bar chart (M10), progress and range (M11), integration card (M9).

**Composables** (`resources/js/composables`): `useScope()` (typed shared scope), `useCan()` (`can('flags.draft.write')`), `useToast()`, `useTheme()`, `useNavigationGuard()` (registry that dirty editors join in M4; prompts before environment, project or org switches and Inertia visits, and on `beforeunload`), `useRequestStatus()` (pending, error with request ID), `useCommandPalette()`.

**Types** (`resources/js/types`): `shared.ts` (above), `capabilities.ts` generated from the PHP enum by `php artisan bitflag:types` (CI checks it is current), `ids.ts` (branded ID types per prefix).

### 3.6 Jobs, events, outbox consumers

- **Outbox worker (fan-out).** `php artisan outbox:work --max-time=3600` runs as its own Sail service and production process. Loop: inside `Tenancy::crossTenant('outbox dispatcher')`, claim up to 100 rows with `SELECT … WHERE dispatched_at IS NULL AND available_at <= now() ORDER BY available_at LIMIT 100 FOR UPDATE SKIP LOCKED`, set `claimed_by` and `claimed_until`, then in one transaction create one `pending` `outbox_receipts` row per registered consumer (`INSERT IGNORE` on the unique key, so a repeated fan-out is harmless) and set `dispatched_at`. After that commit, push a `DeliverOutboxMessage(receiptId)` job per receipt onto the consumer's queue and set `last_signalled_at`. Idle sleep is 250 ms. Expired claims are reclaimable.
- **Durability contract (all milestones).** Receipts are the work list; Redis jobs are only signals that make work start quickly. Losing a job, a worker or the entire Redis queue never loses work, because recovery reads receipts from MySQL. Every consumer in M3 to M11 relies on this contract; milestone-specific sweepers (M9 delivery fan-out, M10 ingest journal) follow the same pattern for their own tables instead of inventing new rules.
- **`DeliverOutboxMessage`** (`TenantAware` for `organization` scope; account and system scope run without a tenant context): claims its receipt with a conditional update (`status IN ('pending','failed') AND next_attempt_at <= now()` → `processing`, `lease_owner`, `lease_until = now() + consumer timeout + 30 s`). If the update affects no row, another worker owns it or it is done, and the job exits. It then calls the consumer and marks the receipt `succeeded`, or `failed` with backoff (10s, 30s, 2m, 10m, 30m, then 1h up to 12 attempts), then `exhausted`. Exhausted receipts are visible to the console (M7) and replayable.
- **Recovery.** `outbox:recover` runs every minute: (1) receipts `processing` with `lease_until` in the past go back to `failed` with an attempt counted; (2) receipts `pending` or `failed` that are due and whose `last_signalled_at` is older than 2 minutes (or null) get a new job pushed. Because the claim is conditional, a duplicate signal never runs a consumer twice concurrently, and consumers stay idempotent for the rare double run after a lease expiry.
- **Horizon** (`config/horizon.php`): supervisors and queues `publication` (highest, isolated), `default`, `notifications`, `integrations`, `telemetry` (lowest), so telemetry or a broken webhook can't starve publication delivery (architecture doc). Later milestones add queues with their own supervisors: `exports` (M3), `billing` (M6), `mail-*` (M8), `integrations-long` (M9), `reports` (M11). Horizon's dashboard is reachable only on the admin host behind the staff guard.
- **Scheduler** (`routes/console.php`): `idempotency:prune` hourly; `outbox:reclaim` every minute (clears expired message claims); `outbox:recover` every minute (receipt recovery above); `horizon:snapshot` every five minutes.
- **Consumers in M0:** none in production code. A test consumer proves dispatch, retries and deduplication.

### 3.7 Security and tenant isolation

- **Hosts and sessions.** Portal and console are separate hosts with host-only cookies (`SESSION_DOMAIN` null). The admin panel middleware sets a distinct cookie name (`bitflag_staff_session`) before `StartSession`. Guards: `web` (provider `users`) and `staff` (provider `staff_users`). A portal session on the admin host and a staff session on the app host both get the login screen.
- **Headers.** `SecurityHeaders` middleware: CSP with a Vite nonce (`Vite::useCspNonce()`), `frame-ancestors 'none'`, `X-Content-Type-Options`, `Referrer-Policy: strict-origin-when-cross-origin`, HSTS outside local.
- **Props hygiene.** Shared props contain IDs, names and booleans only. Inertia pages never receive whole Eloquent models (an arch test forbids returning models from controllers; see §5).
- **Testing routes.** `routes/testing.php` loads only when `APP_ENV` is `local` or `testing` and `TESTING_E2E=true`; it also requires the `X-Testing-Token` header to match `TESTING_E2E_TOKEN`. A feature test boots the app with `APP_ENV=production` and asserts every `_testing` and `_dev` route is absent.
- **Tenant isolation.** Fail-closed global scope, composite FKs, route binding through the scope, job middleware that resets context, and the query guard in tests (§5). `TenantContext` is a scoped binding, so long-running workers start each job clean.
- **Logs.** Request ID and actor ID in context; never request bodies, passwords, tokens or context attributes. Telescope is not installed.
- **Bundle check.** CI greps the built assets for `bf_srv_`, `bf_mgt_`, `bf_pat_`, `APP_KEY`, `STRIPE_` and fails on a match.

### 3.8 Repositories and CI

| Repository | M0 contents | CI workflows |
| --- | --- | --- |
| `bitflag-protocol` | `README.md`, `CHANGELOG.md`, `spec/`, `schemas/v1/`, `fixtures/`, `tools/` (Node 22, Ajv 2020), `package.json`, `.github/` | `ci.yml`: install, `npm run validate` (schemas compile; fixtures valid; manifest current), markdownlint, link check. `release.yml` stub (filled in M1) |
| `bitflag-php` | `composer.json` (`require: { "php": "…" }` only), `src/Evaluation`, `src/Protocol` with a placeholder class, Pest, PHPStan (level max), Pint | `ci.yml`: matrix of supported PHP versions × `--prefer-lowest`/highest; `composer validate --strict`, Pint, PHPStan, Pest, arch test that `BitFlag\` uses only PHP built-ins |
| `bitflag-js` | `package.json` (no runtime dependencies), `tsconfig.json` (strict), `src/evaluation`, `src/protocol` with a placeholder module, Vitest, ESLint | `ci.yml`: matrix of supported Node versions; `tsc --noEmit`, ESLint, Vitest; an arch check that `src/evaluation` imports nothing outside the package |
| `bitflag` | Everything in this plan | `ci.yml`, `security.yml`, `e2e` inside `ci.yml` (below) |

All four: private until D13, `main` protected (pull request required, required status checks, linear history, no force push, stale review dismissal), `CODEOWNERS` naming Shei, pull request template with a "contracts touched" checklist, Dependabot for Composer, npm and GitHub Actions (weekly, grouped), Gitleaks on every pull request.

`bitflag` `ci.yml` jobs:

| Job | Steps |
| --- | --- |
| `backend-lint` | `composer validate --strict`, `composer audit`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (Larastan, level max, custom rules), `scripts/ci/no-path-repositories.php` |
| `backend-test` | Services `mysql:8.4` and `redis:7`; `php artisan migrate`; `vendor/bin/pest --parallel` (Unit, Feature, Arch) with the tenant query guard on |
| `arch-canary` | `scripts/ci/arch-canary.sh`: copies each file from `tests/Arch/Canaries/*.php.txt` into `app/` in turn and asserts that `pest --group=arch` or PHPStan fails; the job fails if any canary passes |
| `frontend` | `npm ci`, `npm run tokens:check`, `php artisan bitflag:types --check`, ESLint, Prettier check, Stylelint, `vue-tsc --noEmit`, `vitest run --coverage`, `vite build`, `scripts/ci/scan-bundle-secrets.mjs` |
| `e2e` | Needs the jobs above. `cp .env.e2e .env`; `./vendor/bin/sail up -d --wait`; `sail artisan migrate:fresh --seed --seeder=DemoSeeder`; `sail npm run build`; `cypress-io/github-action` with `wait-on: http://localhost/up`, Chrome; uploads screenshots and videos on failure |

## 4. Implementation steps

Each step is one pull request. Main files are listed.

1. **Create repositories.** Four private repos, branch protection, `CODEOWNERS`, PR template, Dependabot, Gitleaks workflow, `.editorconfig`, README with the purpose from repository-structure.md.
2. **`bitflag-protocol` skeleton.** `package.json`, `tools/validate.mjs`, `tools/manifest.mjs`, empty `schemas/v1/` with `common.schema.json`, `spec/00-overview.md`, `.github/workflows/ci.yml`.
3. **`bitflag-php` and `bitflag-js` skeletons.** `bitflag-php`: `composer.json` (`BitFlag\` PSR-4, require only `php`), `phpstan.neon`, `pint.json`, `tests/Pest.php`, `tests/Arch/StdlibOnlyTest.php`, `ci.yml`. `bitflag-js`: `package.json`, `tsconfig.json`, `vitest.config.ts`, ESLint config, `ci.yml`.
4. **Scaffold `bitflag`.** `laravel new` (no starter kit), pin PHP, Laravel, Node and MySQL versions (D01), `config/bitflag.php` (hosts), `.env.example`, `.env.e2e`, Sail with `mysql`, `redis`, `mailpit`, plus `horizon` and `outbox` services in `docker-compose.yml`, `bootstrap/app.php` route registration (web, api, sdk, admin, testing).
5. **Quality tooling.** Pest, Larastan, Pint, ESLint + Prettier + Stylelint, `vue-tsc`, Vitest config, `ci.yml` jobs `backend-lint`, `backend-test`, `frontend`.
6. **Inertia, Vue, TypeScript, Vite.** `resources/views/app.blade.php`, `resources/js/app.ts`, `HandleInertiaRequests`, Wayfinder, `tsconfig.json`, `resources/js/types/shared.ts`.
7. **Tokens and theme.** `design/nocturne/styles.css`, `design/nocturne/SOURCE`, `design/nocturne/light.json`, `scripts/tokens/generate-theme.mjs`, `resources/css/app.css`, `resources/css/theme.generated.css`, fonts, Phosphor, hex lint rules, `resources/js/composables/useTheme.ts` and the pre-paint theme script in `resources/views/app.blade.php`.
8. **Module skeleton.** `app/Modules/{Identity,Organizations,Projects,Environments,Flags,Targeting,Publishing,Credentials,Experiments,Analytics,Integrations,Audit,Billing,Notifications,Support}/{Module}ServiceProvider.php` registered in `bootstrap/providers.php`; `routes/web/*.php` include pattern; `PortalNavigation` and `AuditCatalog` registries.
9. **Architecture rules.** `tests/Arch/*.php` (§5), `tools/phpstan/Rules/NoModelWritesOutsideActionsRule.php`, `tests/Arch/Canaries/*.php.txt`, `scripts/ci/arch-canary.sh`, `arch-canary` job.
10. **IDs, errors, request IDs.** `app/Shared/Ids/{IdPrefix,PublicId,HasPublicId}.php`, `app/Shared/Errors/{ApplicationException,ErrorEnvelope,ExceptionRenderer}.php`, `app/Http/Middleware/{AssignRequestId,SecurityHeaders}.php`, `pages/errors/Error.vue`.
11. **Tenancy spine and scoping.** Spine migrations, `TenantColumns` macros, `app/Shared/Tenancy/{TenantContext,BelongsToOrganization,OrganizationScope,Tenancy,TenantAware,RestoreTenantContext}.php`, `config/tenancy.php`, spine models in their modules, `tests/Support/TenantQueryGuard.php`, `tests/Feature/Tenancy/*`.
12. **Capabilities and Actor.** `app/Shared/Capabilities/{Capability,ProjectRole,OrgRole,RoleCapabilityMap,OrgRoleCapabilityMap,CapabilityResolver,MembershipCapabilityResolver,EnvironmentProtection,TokenScopeMap}.php`, `app/Shared/Actors/{Actor,ActorType,Channel,ResolveActor}.php`, `php artisan bitflag:types`.
13. **Audit recorder.** `audit_events` migration, `app/Shared/Audit/{AuditRecorder,AuditEvent,AuditTarget,RevisionChange,Redactor,AuditCatalog}.php`.
14. **Outbox.** Migrations for `outbox_messages` and `outbox_receipts`, `app/Shared/Outbox/{Outbox,OutboxMessage,OutboxConsumer,ConsumerRegistry,Dispatcher,DeliverOutboxMessage}.php`, `OutboxWorkCommand`, `OutboxReclaimCommand`, `OutboxRecoverCommand`, Horizon config and supervisors.
15. **Idempotency and concurrency.** `idempotency_records` migration, `app/Shared/Idempotency/*`, `app/Shared/Concurrency/{VersionedUpdate,VersionConflict}.php`, `SystemClock`, test-only `RenameWidget` action and `test_widgets` migration under `tests/Support`.
16. **Fortify and guards.** Fortify install with only login and logout enabled, classes moved to `Identity\Actions\Fortify`, `pages/auth/Login.vue`, `layouts/AuthLayout.vue`, `staff` guard and `staff_users` table, `StaffUser` model.
17. **Filament shell.** `app/Admin/AdminPanelProvider.php` (`->domain(config('bitflag.hosts.admin'))`, `->authGuard('staff')`, staff cookie middleware, accent color, "BitFlag Support" brand, empty dashboard), Horizon gate for staff.
18. **UI baseline.** `resources/js/components/ui/*`, `composables/useToast.ts`, `pages/dev/UiGallery.vue`, Vitest specs.
19. **Portal shell.** `ResolvePortalScope`, `RememberLastScope`, scope and redirect controllers, `layouts/PortalLayout.vue`, `layouts/OrgSettingsLayout.vue`, `features/shell/*`, `useNavigationGuard`, `useCommandPalette`, `pages/overview/Show.vue`, `pages/account/NoAccess.vue`.
20. **Demo seeders.** `database/seeders/DemoSeeder.php` composed of `Demo\OrganizationsSeeder`, `Demo\ProjectsSeeder`, `Demo\EnvironmentsSeeder`, `Demo\UsersSeeder`, `Demo\StaffSeeder`. Later milestones add `Demo\FlagsSeeder` and others to the same composition.
21. **Cypress harness and M0 specs.** `cypress.config.ts`, `cypress/support/{commands,selectors}.ts`, `routes/testing.php`, `app/Http/Controllers/Testing/*`, `cypress/e2e/m0-*.cy.ts`, `e2e` job.

**Demo seed (M0 part).** Refuses to run when `APP_ENV=production`. Fixed ULIDs so specs can use stable URLs.

| Record | Values |
| --- | --- |
| Organization | Acme, slug `acme` |
| Projects | Storefront (`storefront`, "Web checkout, catalog and account pages."), Checkout API (`checkout-api`) |
| Environments (each project) | `development` (position 1), `staging` (2), `production` (3) |
| Users (password from `DEMO_PASSWORD`, default `password`, local only) | Maya Santos `maya@acme.dev` (Org Owner; Owner of both projects), Jon Reyes `jon@acme.dev` (Member; Storefront Publisher, Checkout API Developer), Priya Nair `priya@acme.dev` (Member; Storefront Developer), Ana Cruz `ana@acme.dev` (Member; Storefront Viewer), Leo Ramos `leo@acme.dev` (Member; no project access, standing in for the mockup's Billing Admin) |
| A second organization | Globex (`globex`) with one project and one user, used by isolation specs |
| Staff | `support@bitflag.test` (local and testing only) |

Flags `new-checkout`, `checkout-layout` and `payment-config` are seeded by M4. Two-factor enrollment for demo users is added by M2.

## 5. Tests

**Pest architecture (`tests/Arch`, group `arch`)**

| File | Rule |
| --- | --- |
| `ModuleBoundariesTest.php` | For each module M (dataset): `App\Modules\M\Models`, `Domain` (except `Domain\Exceptions`), `Http`, `Policies` and `Jobs` are used only inside `App\Modules\M`, `App\Admin` (models only) and `Database`. Other modules may use M's `Actions`, `Data`, `Queries`, `Events` and `Domain\Exceptions` |
| `ControllersTest.php` | Controllers don't use `Illuminate\Support\Facades\DB`, any `Models` namespace, or `Domain` (except exceptions); they read through `Queries` and write through `Actions` |
| `ActionsTest.php` | Classes in `Actions` are `final`, have `handle()`, and don't use `auth`, `request`, `session`, `Auth`, `Request` |
| `DomainPurityTest.php` | `Domain` uses no `Illuminate\Http`, facades, `Inertia`, controllers or global helpers |
| `SharedTest.php` | `App\Shared` never uses `App\Modules` or `App\Admin` |
| `EvaluatorBoundaryTest.php` | Nothing in `App` uses `BitFlag\Evaluation\Internal`, `BitFlag\Protocol\Internal`, `BitFlag\Client`, `BitFlag\Transport`; only `App\Shared\Evaluation` (M4's `EvaluatorGateway`), `App\Modules\Flags` and `App\Modules\Publishing` use `BitFlag\` at all |
| `HygieneTest.php` | No `dd`, `dump`, `ray`, `var_dump`; `env()` only in `config/` |

PHPStan rule `NoModelWritesOutsideActionsRule`: calls to `save`, `update`, `delete`, `create`, `insert`, `upsert`, `increment`, `decrement`, `forceDelete`, `push` on Eloquent models or builders, and `DB::table()` writes, are allowed only in `App\Modules\*\Actions`, `App\Shared\{Audit,Outbox,Idempotency,Concurrency}`, `Database\` and `Tests\`.

Canaries (`tests/Arch/Canaries`): controller using `DB`; Billing using a Flags model; action calling `auth()`; app class using `BitFlag\Evaluation\Internal\…`; domain class using `Request`; a model write in a controller (PHPStan); a tenant model without `BelongsToOrganization` (tenant table test).

**Pest feature (`tests/Feature`)**

- `Tenancy/TenantTablesTest.php`: reads `information_schema`; every table is classified in `config/tenancy.php`; every tenant table has `organization_id NOT NULL`, an index whose first column is `organization_id`, and a model using `BelongsToOrganization`.
- `Tenancy/TenantQueryGuardTest.php`: the guard (enabled for every feature test in `tests/Pest.php`) fails a query on a tenant table without an `organization_id` predicate and passes inside `Tenancy::crossTenant()`.
- `Tenancy/ScopeTest.php`: missing context throws; cross-tenant save throws; route binding with another org's public ID is 404; `RestoreTenantContext` clears context after a failing job.
- `Tenancy/IsolationTest.php` with helper `assertTenantIsolated(ModelClass)` used for every spine model (M2 onward reuse it for every new tenant table).
- `Shared/PublicIdTest.php`, `Shared/ErrorEnvelopeTest.php` (each mapped exception, `request_id` matches header, no trace in production mode), `Shared/RequestIdTest.php` (inbound header ignored).
- `Shared/AuditRecorderTest.php` (outside-transaction guard, redaction, append-only, catalog validation), `Shared/OutboxTest.php` (push requires transaction; rollback leaves no message; dispatcher with `SKIP LOCKED` across two workers dispatches once; consumer failure backs off; exhausted state; idempotent redelivery), `Shared/OutboxRecoveryTest.php` (kill the worker after the receipt commit and before the push; after the push and before the consumer runs; inside the consumer; flush every Redis queue; in each case `outbox:recover` completes every receipt exactly once per consumer, and two concurrent signals for one receipt run the consumer once), `Shared/OutboxScopeTest.php` (account-scoped message without an organization is accepted; an organization-scoped message without one is rejected by the CHECK constraint).
- `Shared/IdempotencyTest.php` (same key replay, different payload, concurrent duplicate on real MySQL, expiry), `Shared/VersionedUpdateTest.php` (conflict details), `Shared/ActionPatternTest.php` (the `RenameWidget` action: audit and outbox written in the same commit; forced audit failure rolls back the write).
- `Capabilities/RoleCapabilityMapTest.php` (the conventions table, cell by cell), `Capabilities/ResolverTest.php` (org owner without project membership has no project capability; viewer read-only).
- `Shell/ScopeResolutionTest.php` (redirect chain, remembered scope, 404 for non-members and for Leo on Storefront), `Shell/SharedPropsTest.php` (no model serialization, `can` map per role).
- `Admin/HostSeparationTest.php` (portal session rejected on admin host, staff session rejected on app host, distinct cookies).
- `Testing/RoutesAbsentInProductionTest.php`.

**Vitest (`resources/js/**/*.spec.ts`)**: every baseline component (rendering, variants, `aria` attributes, keyboard: Esc closes dialog and menu, focus returns to trigger, type-to-confirm enables the button only on exact match), `useToast` (4-second timer with fake timers, pause on hover), `useNavigationGuard` (blocks a visit when dirty), `EnvironmentPicker` (search appears above 5 items, "no revision" label, keeps the page when switching), `SidebarNav` (active style, count labels), `StatusTag` mapping, `useTheme` (default dark, stored choice, `system` follows the media query, sets `data-theme`).

**Cypress (`cypress/e2e`)**

| Spec | Covers |
| --- | --- |
| `m0-shell.cy.ts` | Maya signs in through `/signin`, lands on `/acme/storefront/production/overview`; sidebar shows Overview active; environment picker switches to `staging` and the URL and label change; switcher lists Storefront and Checkout API and navigates; the remembered scope is used after sign-out and sign-in; Ana sees the Viewer banner; Leo gets 404 on `/acme/storefront/production/overview` and `NoAccess` on `/`; Globex user can't reach Acme |
| `m0-errors.cy.ts` | `/_testing/fail` shows the 500 page whose request ID equals the `X-Request-Id` header; unknown project key shows 404; expired CSRF shows the 419 page |
| `m0-ui-gallery.cy.ts` | `/_dev/ui` renders in dark and in light; dialog keyboard flow; dropdown menu not clipped inside a scrolling table; toast disappears after 4 seconds under a frozen clock |
| `m0-theme.cy.ts` | Maya switches to Light from the user menu and `data-theme="light"` is set; it survives a reload with no flash (attribute present before first paint); System follows the emulated color scheme; the shell has no horizontal overflow in either theme |
| `m0-admin-host.cy.ts` | `admin.localhost` shows the Filament login; Maya's portal session does not open it; the seeded staff user reaches the empty dashboard; the staff session does not open the portal |

## 6. Decisions

| Item | Default assumed in M0 |
| --- | --- |
| D01 supported versions | Latest stable Laravel on the newest PHP it supports, MySQL 8.4 LTS, Redis 7, Node 22 LTS, pinned in `composer.json`, `.nvmrc`, Sail and CI at scaffold time and recorded in an ADR (`docs/adr/0001-versions.md`) |
| D13 naming and licensing | Repos are private; no license file; package name `bitflag/bitflag-php` is a placeholder |
| D14 API details | Error envelope and codes from the conventions; idempotency records kept 24 hours; one-time secrets never replayed |
| D09 retention | Audit and outbox rows are kept; no pruning beyond idempotency records |
| D08 hosting | None; Sail only. Production process types (web, Horizon, outbox worker, scheduler) are defined so D08 can map them |
| D02 approvals | Not needed in M0; capability map follows the conventions table |
| R07 org roles | Org Owner and Member only. Billing Admin (Organization mockup 1b, Leo Ramos) is not built; it would plug in as an `OrgRole` mapped to `org.billing.manage` |
| R11 sign-in | Email and password only; no GitHub or Google buttons on the M0 login screen even though the Auth mockup shows them |
| R12 support | Admin console on its own host and guard; no impersonation path exists in the shell |
| R18 front-end choices (9 October 2026) | Portal ships dark and light themes; Inter for headings and body, IBM Plex Mono for code, keys, IDs and monospace numbers; Reka UI, Wayfinder and self-hosted fonts accepted; nav shows only built pages; no semantic red or green |

Portal URLs led by the organization slug are decided (R17). The front-end choices above are decided (R18). Still for Shei to confirm: the `outbox_receipts` table and the `aud_` and `req_` prefixes.

## 7. Done criteria

- CI is green on `main` in all four repositories, with branch protection requiring the checks.
- `./vendor/bin/sail up` starts app, MySQL, Redis, Mailpit, Horizon and the outbox worker; `sail artisan migrate:fresh --seed --seeder=DemoSeeder` succeeds.
- A seeded user signs in and sees the blank Overview page inside the Nocturne shell, with working environment and project switching, and the Viewer banner for Ana.
- The `arch-canary` job proves each architecture rule, the PHPStan write rule and the tenant-table test fail on a deliberate violation.
- The tenant query guard is on for every feature test and passes; every table is classified.
- Audit, outbox and idempotency behave as specified on real MySQL, including rollback and `SKIP LOCKED` tests, and the outbox recovers every receipt after worker crashes and a full Redis queue loss.
- Every API-style error carries the envelope and a request ID that matches the response header; the portal error page shows it.
- The admin host serves only Filament with the `staff` guard, and sessions do not cross hosts.
- `theme.generated.css` is generated from the vendored Nocturne file, and the check fails if either changes without regeneration. Both themes pass the contrast checks.
- The portal renders correctly in dark and light, the user can switch between them, and the chosen theme applies before first paint.
- All five `m0-*` Cypress specs pass in CI against Sail.

## 8. Risks and open questions

1. **Light theme: resolved (R18).** Both themes ship. Open detail: no mockup draws the theme switch, so its placement in the user menu needs a design pass, and the light values in the Design System mockup must pass the contrast checks (if they don't, the light overrides change, not the check). Per-account storage of the choice moves to M2.
2. **Fonts: resolved (R18).** Inter for headings and body, IBM Plex Mono for code, keys, IDs and monospace numbers. The Design System's "system monospace" wording and `design/nocturne/styles.css` (which loads Inter from Google Fonts) are superseded for the portal by the self-hosted fonts; the vendored file is not edited, and the generator drops its `@import`.
3. **No semantic success or danger colors: resolved (R18).** Failure states stay outline tags, dots and copy; no red or green.
4. **Headless UI dependency: resolved (R18).** Reka UI, Wayfinder and self-hosted fonts are accepted.
5. **Docs disagree on test folders and modules.** repository-structure.md shows `tests/Architecture` and `tests/Browser` and a 13-module list; the conventions use `tests/Arch`, `cypress/e2e` and 15 modules (adding Notifications and Support). This plan follows the conventions.
6. **Error envelope casing.** api-and-integrations.md shows `requestId` and `DRAFT_VERSION_CONFLICT`; the conventions use `request_id` and `VERSION_CONFLICT`. This plan follows the conventions; the API doc example should be updated.
7. **Nav entries for unbuilt pages: resolved (R18).** Items appear only once their module registers a route, so the M0 shell shows Overview alone.
8. **Sail in CI** makes the e2e job slow. If it becomes a bottleneck, a services-based runner using the same images is the fallback, but Sail stays the documented path.
