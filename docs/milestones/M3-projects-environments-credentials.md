# M3. Projects, environments and credentials

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Expands M3 in the [implementation plan](../implementation-plan.md). Builds on M0 (tenancy spine tables `projects` and `environments`, `CapabilityResolver` with the `EnvironmentProtection` and `TokenScopeMap` interfaces, `Actor`, URL scheme) and M2 (the `environment_policies` migration specified here, memberships, the first `CreateProject`). Plan limits use M6's contracts in `app/Shared/Plans`.

## 1. Goal and scope

A project Owner can configure the project and its new-flag defaults, shape its environments (template, custom, order, color, protection policy, browser origins, maximum snapshot age), and issue every credential the product uses. Credentials are shown once, stored only as peppered hashes, scoped, rotatable with an overlap window, revocable with immediate effect, and report when they were last used. The management API authenticates tokens through one middleware that later milestones reuse.

**In scope**

- Project settings General tab, new-flag defaults, project key lock.
- Project lifecycle (D12 proposal): archive and unarchive, schedule deletion (7-day grace), restore, purge. Organization deletion scheduling uses the same machinery.
- Project export job framework (sections registered by later milestones).
- Environments: template, custom creation, ordering, label colors, key immutable after first publication, versioned protection policy, browser origins, default maximum snapshot age, archive, delete.
- Calls to M6's `EnforcePlanLimit`, `Entitlements` and `PlanRestrictionGate` (`app/Shared/Plans`) from every create, restore and settings action here (null bindings until M6).
- Credentials: server keys `bf_srv_`, browser keys `bf_pub_`, management keys `bf_mgt_` (R03), personal access tokens `bf_pat_`. One-time reveal, hashing scheme, display prefix and last 4, scopes, expiry, rotation with overlap, revocation, last-used tracking without a database write per request.
- Management API token authentication middleware, error envelope for token failures, rate limits, and a minimal set of endpoints (`/api/v1/me`, project, environments).
- The credential contract M5 needs for SDK delivery authentication (§3.4).

**Out of scope**

| Item | Where |
| --- | --- |
| SDK delivery endpoints, ETag, snapshot caches, CORS on SDK routes, live revision, Delivery tab metrics and "Test the snapshot endpoint" | M5 (Delivery tab stats also M10) |
| Flags count in project tables, flag defaults enforcement at flag creation | M4 (reads the defaults stored here) |
| Plan entitlements from Stripe, over-limit read-only projects | M6 |
| Emails (project or organization scheduled for deletion, key expiring) | M8; this milestone pushes the outbox messages |
| Webhook delivery of `key.revoked` | M9 |
| Project transfer between organizations (Settings 2h "Transfer…") | Blocked on D12; see §8 |
| CLI browser login (`bitflag login`) | D11 |
| Credential management through the API (create, rotate, revoke keys by token) | D14; portal only in M3 |

## 2. Mockups covered

| Mockup | Artboard | Behavior implied |
| --- | --- | --- |
| BitFlag Settings | 1a, 1b, 1c General | Name, project key ("used by SDKs and the CLI", "fixed once SDKs use it"), description ("shown on the project switcher"), time zone, key format (kebab-case, snake_case, any), stale after (14, 30, 60, 90 days without checks), Require a description, Require an owner, Client-visible by default. Save, Reset, "Unsaved changes". Layout 1a (tabs with stacked cards) is built |
| | 2d, 2e, 2f, 2g API tokens | Personal tokens table (name, `bf_pat_••••8e21`, access, created, last used, expires, Revoke); create dialog (name, access, expires, "A token can never do more than your role allows. Protected environments still need a review."); shown once with CLI snippet and acknowledgement; empty state |
| | 2h, 2i, 2j Danger zone | Export JSON (no keys or tokens), Transfer, Delete (7 days, typed key, restore banner "Storefront will be deleted on Oct 7, 2026. Until then SDKs keep working") |
| BitFlag Environments | 1a Table list | Order (up/down), environment, protection, live revision, last publish, SDK keys ("3 active"), delivery; "Environments with published revisions or keys can't be deleted. Archive them instead" |
| | 1b, 1c | Card grid and split layouts: not built; 1c's detail panel is the detail page's Overview tab |
| | 2a Keys tab | Columns key (`bf_srv_prod_••••41c8`), type, scopes, created (date and creator), last used, expires, state (Active, Rotating, Revoked); row menu Rotate…, Copy key prefix, Revoke…; type help for server, browser, management |
| | 2b New environment | Display name, key ("Available"), label color, position ("After staging"), protected toggle; "Every flag starts disabled here. The key can be changed until the first publish." |
| | 2c, 2d Create key | Step 1 type (Server SDK, Browser SDK, CLI CI or API), step 2 details, step 3 copy key with environment and env variable name, acknowledgement |
| | 2e Empty environment | "No keys yet"; never-published copy "SDKs using these keys get an empty snapshot and return your fallback values" |
| | 2f Viewer read-only | Everything visible, nothing editable |
| portal/Settings | Environments list and detail, all modals | Detail tabs Overview, Keys, Delivery, History, Settings; settings form (name, key lock note, color, three protection toggles, allowed origins chips with validation, default maximum snapshot age None/5 min/1 hour/24 hours); rotate dialog (overlap 1 hour, 24 hours, 7 days); revoke dialog with cache text that depends on max age and "I understand this can't be undone"; archive and delete dialogs with typed key; delete blockers list; leave-without-saving dialog; remove-member copy "Their personal API tokens are revoked" |
| BitFlag Organization | 1a General | Projects table (environments, flags, members) |
| | 3d Paid setting on Free | "Require a second reviewer" disabled on Free with "Required approvals are on Pro" |
| BitFlag Overview, portal/Overview | Connect an SDK | Placeholder key `bf_srv_dev_DEMO-not-a-real-key`; real keys come from this milestone (M5 builds the checklist) |

## 3. Design

### 3.1 Data model

**`projects`** (Projects). M0 creates `id`, `organization_id`, `name`, `key`, `description`, `status`, timestamps; M3 adds the other columns (an "add columns" migration).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | `prj_` |
| `organization_id` | ulid | |
| `name` | varchar(80) | |
| `key` | varchar(40) (M0) | Shown as "Project key"; `^[a-z][a-z0-9-]{1,39}$`, unique `(organization_id, key)` (M0) |
| `key_locked_at` | timestamp(6) null | Set on first credential issue or first publication |
| `description` | varchar(280) null (M0) | |
| `time_zone` | varchar(64) | IANA, default from the creator's timezone |
| `flag_key_format` | enum(`kebab`,`snake`,`any`) | default `kebab` |
| `stale_after_days` | smallint | 14, 30 (default), 60, 90 |
| `require_flag_description` | bool | default true |
| `require_flag_owner` | bool | default true |
| `client_visible_by_default` | bool | default false |
| `status` | enum(`active`,`archived`,`deletion_scheduled`,`deleted`) | |
| `status_before_deletion` | enum(`active`,`archived`) null | Restored on cancel |
| `archived_at`, `archived_by` | | |
| `deletion_scheduled_at`, `deletion_due_at`, `deletion_scheduled_by` | | |
| `deleted_at` | timestamp(6) null | Purge time (status timestamp, not a soft delete) |
| `version` | int | Settings form concurrency |
| `created_by`, `created_at`, `updated_at` | | |

Index `(organization_id, status)`.

**`environments`** (Environments). M0 creates `id`, `organization_id`, `project_id`, `name`, `key`, `color`, `position`, `status`, timestamps; M3 adds the other columns (an "add columns" migration).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | `env_` |
| `organization_id`, `project_id` | ulid | |
| `name` | varchar(40) | |
| `key` | varchar(40) | `^[a-z][a-z0-9-]{0,39}$`, unique `(organization_id, project_id, key)` |
| `color` | enum(`slate`,`lilac`,`violet`,`indigo`,`ink`) (M0) | Label only, never implies risk |
| `position` | smallint | Rewritten as 1..n by reorder |
| `status` | enum(`active`,`archived`) | |
| `first_published_at` | timestamp(6) null | Set by M5 through `RecordFirstPublication`; locks `key` |
| `current_policy_id` | ulid | FK `environment_policies.id` |
| `allowed_origins` | json | List of normalized origins, max 20; `[]` blocks all browser requests |
| `default_max_snapshot_age_seconds` | int null | null (no limit), 300, 3600, 86400 |
| `archived_at`, `archived_by` | | |
| `version` | int | Settings form concurrency |
| `created_by`, `created_at`, `updated_at` | | |

Index `(organization_id, project_id, status, position)`. The published revision pointer lives in M5's `publication_pointers`.

**`environment_policies`** (Environments; immutable rows; migration lands in M2)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | |
| `organization_id`, `project_id`, `environment_id` | ulid | |
| `version` | int | unique `(environment_id, version)`; starts at 1 |
| `is_protected` | bool | Protection badge; when true only Owners and Publishers can publish (R01) |
| `require_reason` | bool | true whenever `is_protected` ("requires a change reason on every publish"); separate column so D02 can decouple it |
| `required_approvals` | tinyint | 0 or 1 at launch (D02 may allow more); Pro feature |
| `allow_self_approval` | bool | false (D02 default, "The editor can't approve their own change") |
| `approval_ttl_hours` | int null | null means "use M5's `DefaultApprovalPolicy` default" (168 hours, D02) |
| `created_by`, `created_at` | | |

M5 records `policy_version` on every change set and rechecks it at publication.

**`credentials`** (Credentials; one table for every credential type, per the domain model's persistence groups)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | `key_` public prefix for every type |
| `organization_id`, `project_id` | ulid | |
| `environment_id` | ulid null | Required for `server`, `browser`, `management`; null for `personal` |
| `user_id` | ulid null | Owner of a `personal` token |
| `type` | enum(`server`,`browser`,`management`,`personal`) | |
| `label` | varchar(60) | |
| `display_prefix` | varchar(24) | For example `bf_srv_prod_` |
| `last4` | char(4) | |
| `secret_hash` | binary(32) | unique; `SecretHasher` output |
| `pepper_id` | tinyint | Which pepper produced `secret_hash` |
| `scopes` | json | Sorted list from the catalog in §3.2 |
| `ttl_days` | smallint null | Chosen expiry option, reused on rotation |
| `expires_at` | timestamp(6) null | |
| `status` | enum(`active`,`rotating`,`expired`,`revoked`) | |
| `rotated_from_id`, `replaced_by_id` | ulid null | Rotation pair |
| `overlap_ends_at` | timestamp(6) null | Set on the old key when rotating |
| `revoked_at`, `revoked_by` | | `revoked_by` null for system |
| `revoke_reason` | enum(`manual`,`rotation_completed`,`environment_archived`,`project_deleted`,`member_removed`,`account_deleted`) null | |
| `last_used_at` | timestamp(6) null | Flushed from Redis (§3.2) |
| `last_used_client` | varchar(60) null | From `X-BitFlag-Client`, for example `bitflag-cli/1.8.2` |
| `audience` | enum(`api`,`mcp`) | default `api`; `mcp` tokens are read by M9's `EnforceMcpPolicy` |
| `created_by` | ulid | User who issued it (kept after they leave, R03) |
| `created_at`, `updated_at` | timestamp(6) | |

Indexes: unique `secret_hash`; `(organization_id, project_id, environment_id, status)`; `(organization_id, user_id, type, status)`; `(status, expires_at)`; `(status, overlap_ends_at)`.

**`project_exports`**: `id`, `organization_id`, `project_id`, `requested_by`, `status` enum(`queued`,`running`,`ready`,`failed`,`expired`), `file_path` (private disk), `bytes`, `sha256`, `error_code`, `expires_at` (+24 hours), `created_at`, `updated_at`.

**`organizations`** gains `deletion_scheduled_at`, `deletion_due_at`, `deletion_scheduled_by`, `deleted_at`, and the `deleted` status value (M0 created `status`).

### 3.2 Domain rules and state machines

**Project settings.** Name 1 to 80 characters. The project key (`key`) is editable until `key_locked_at`, then shown read-only with "The key is fixed once SDKs use it". The key locks when the first credential of any type is issued in the project, or at the first publication (M5 calls `LockProjectKey`). The new-flag defaults are stored here and enforced by M4's `CreateFlag`. Time zone affects scheduled rollouts and audit display only; storage stays UTC.

**Project lifecycle (D12 proposal).**

```text
active ⇄ archived
active | archived ──schedule deletion──▶ deletion_scheduled ──restore──▶ status_before_deletion
deletion_scheduled ──deletion_due_at passes──▶ deleted (purge)
```

| State | Draft edits and publishing | New credentials | Rotate / revoke | SDK keys serve | Counts toward plan |
| --- | --- | --- | --- | --- | --- |
| `active` | Yes | Yes | Yes | Yes | Yes |
| `archived` | No (read-only) | No | Yes | Yes, last published snapshot | Yes (R17: it still serves) |
| `deletion_scheduled` | No (read-only) | No | Yes | Yes, until purge | No |
| `deleted` | No | No | No | No (all revoked, `401`) | No |

- Schedule deletion: `project.manage`, typed project key, grace 7 days (`config('bitflag.lifecycle.project_deletion_grace_days')`, from Settings 2i; D12 placeholder).
- Restore (`RestoreProject`, Settings 2j "Restore project"): allowed until `deletion_due_at`; calls `EnforcePlanLimit` (`LimitKey::Projects`), so it is blocked with `PLAN_LIMIT` if it would exceed the limit.
- Unarchive: no limit check, because an archived project already counts (R17).
- Purge: revoke every credential (`project_deleted`), drop credential caches, set `deleted`, rename `key` to `deleted-{ulid}` to free it, push `internal.project.deleted`. Each module registers a `ProjectPurger` that removes its tenant data after the D09 retention window; the `projects` row stays as a tombstone for audit references.
- Rotation and revocation stay available in every non-deleted state (R08: never gated).
- Routing: M0's `ResolvePortalScope` rule "reject archived with 404" changes here. Archived and deletion-scheduled projects resolve and render read-only with `ProjectStatusBanner`; deleted projects stay `404`. An archived environment never resolves as the `{environment}` URL segment, but opens read-only as `{target}` in Settings → Environments.

**Organization deletion** (General page, Org Owner): typed slug, blocked while a paid subscription is active (M6 `BillingStatus::hasActivePaidSubscription()`, returns false until M6), same 7-day grace and restore. Scheduling also schedules every project; purge runs project purges, then marks the organization `deleted` and removes memberships. Management actions inside a scheduled organization are blocked except restore; SDK keys keep working until purge.

**Environment template.** `CreateProject` accepts the editable list from onboarding 5e or the New project dialog:

| Key | Name | Color | Protected | Required approvals | Max snapshot age |
| --- | --- | --- | --- | --- | --- |
| `development` | Development | slate | no | 0 | none |
| `staging` | Staging | lilac | no | 0 | none |
| `production` | Production | violet | yes | 1 if `Entitlements` allows `Feature::RequiredApprovals`, else 0 | none |

Any environment named with key `production` or `prod` gets the protected defaults; others are open. The list must hold 1 to the plan's environment limit (Free 3, Pro 10).

**Environment keys.** Format `^[a-z][a-z0-9-]{0,39}$`, unique in the project, auto-filled from the display name until the person edits it ("Available", "Already used in this project", "Start with a letter. Use a–z, 0–9 and -"). Editable until `first_published_at`, then locked ("locked after first publish"). Changing the key before first publication does not change existing key prefixes (the tag in a key is display-only).

**Ordering.** `ReorderEnvironments` takes the full ordered list of active environment IDs; a list that does not match the current set returns `409 VERSION_CONFLICT`. Archived environments sort after active ones. Order is used everywhere environments are listed.

**Protection policy.** Saving the Settings tab with any change to `is_protected` or `required_approvals` inserts a new `environment_policies` row with `version + 1` and moves `current_policy_id`. Rules:

- `required_approvals` can only be above 0 while `is_protected` is on; turning `is_protected` off clears it in the new version.
- There is no separate "Only publishers can publish" toggle (R01, R14): protection always means only Owners and Publishers publish. The mockup's toggle is removed and its copy folded into the protection description.
- Raising `required_approvals` above 0 calls `Entitlements::assertAllows(org, Feature::RequiredApprovals)`; otherwise `403 FEATURE_NOT_IN_PLAN` and the toggle shows "Required approvals are on Pro. Upgrade to turn this on." (Organization 3d). Lowering is always allowed (billing doc §4).
- `PolicyEnvironmentProtection` implements M0's `EnvironmentProtection` interface (replacing M2's interim binding): publishing requires `environment.publish.protected` when the current policy has `is_protected`, otherwise `environment.publish`. A change reason is required when `is_protected`. M5 enforces approvals.
- Policy changes are audited with before and after values (`environment.policy_changed`).

**Browser origins.** Each entry is normalized (lowercase scheme and host, default port removed, trailing slash removed) and must match `scheme://host[:port]` with no path, query or wildcard. `https` is required except for `localhost` and `127.0.0.1`. Duplicates rejected ("Already in the list"). Max 20. Empty list means every browser-key request is refused. Origins are a browser control, not a confidentiality boundary (protocol §8); M5 enforces them on browser-key requests with `403 ORIGIN_NOT_ALLOWED`.

**Default maximum snapshot age.** None, 5 minutes, 1 hour, 24 hours. Delivered to SDKs by M5 as the environment default; SDKs may set a stricter value. The revoke dialog uses it to explain cached behavior.

**Environment archive and delete.**

| Action | Allowed when | Effect |
| --- | --- | --- |
| Archive | Active, not the project's last active environment | Revokes every active key (`environment_archived`, SDKs get `401`), blocks new publishes, hides it from pickers and flag pages, keeps history readable, stops counting toward the limit. Typed key confirmation |
| Delete | Never published and no credential rows of any state | Removes the environment, its policy rows and (via M4's purger) its empty drafts. Typed key confirmation |

Unarchive is not offered at launch (D12). The delete blockers list shows "Published revisions (latest rev N)", "N active SDK keys", "Credential history for this environment".

**Plan limits** use M6's contracts (`app/Shared/Plans`), never a local count:

| Action | Call |
| --- | --- |
| `CreateProject`, `RestoreProject`, `UnarchiveProject` | `EnforcePlanLimit::handle(org, LimitKey::Projects)` |
| `CreateEnvironment` | `EnforcePlanLimit::handle(org, LimitKey::EnvironmentsPerProject, project)` and `PlanRestrictionGate::assertConfigWritable(project)` |
| `UpdateEnvironmentSettings`, `UpdateProjectSettings` | `PlanRestrictionGate::assertConfigWritable(project, environment)` |
| `UpdateEnvironmentSettings` raising approvals, template production | `Entitlements::assertAllows` with `Feature::RequiredApprovals` |
| `RotateCredential`, `RevokeCredential` | None: never gated (R08), even in read-only projects |

The guard is the first statement inside the action's transaction and locks the organization's entitlements row, so concurrent creations at a limit produce one success (M6 §3.3). Until M6, the bindings are permissive null implementations; M3 tests bind a Free-limits test double to prove each call happens inside the transaction. Errors: `403 PLAN_LIMIT`, `403 FEATURE_NOT_IN_PLAN`.

**Credential types.**

| Type | Prefix | Bound to | Created in | Who can create | Scope catalog (default in bold) | Expiry options (default) |
| --- | --- | --- | --- | --- | --- | --- |
| Server key | `bf_srv_{tag}_` | One environment | Environment → Keys, "Server SDK" | `credentials.manage` | **`snapshot:read`**, **`evaluate`**, `events:write` | Never (default), 30 days, 90 days, 1 year |
| Browser key | `bf_pub_{tag}_` | One environment, public projection | Environment → Keys, "Browser SDK" | `credentials.manage` | **`snapshot:read`**, **`events:write`** | Same |
| Management key | `bf_mgt_{tag}_` | Project, restricted to one environment; owned by the project (R03) | Environment → Keys, "CLI, CI or API"; listed in Settings → API tokens | `credentials.manage` | **`flags:read`**, `flags:write`, `publish` | Same |
| Personal access token | `bf_pat_` | User, bound to one project | Settings → API tokens | Any project member with a verified email, after password confirmation | Access level: Read only (`flags:read`), Read and edit drafts (`flags:read flags:write`, default), Read, edit and publish (adds `publish`) | 30 days, 90 days (default), 1 year, Never |

`{tag}` is a display-only environment tag: `dev`, `stg`, `prod` for the template keys, otherwise the first 4 alphanumeric characters of the environment key. Authorization never reads the tag. Prefixes and scope names are provisional under D14. At least one scope is required.

**Scopes to capabilities** (`ScopeTokenScopeMap` implements M0's `TokenScopeMap`; token actors only; a personal token also intersects with the user's current project role, so it can never do more than the role allows):

| Scope | Capabilities |
| --- | --- |
| `flags:read` | `project.read`, `audit.read`, `sensitive.read` |
| `flags:write` | `flags.draft.write`, `segments.write` |
| `publish` | `changeset.submit`, `environment.publish`, `environment.publish.protected` (M5 allows a token to publish in a protected environment only an already-approved change set); `changeset.approve` for personal tokens only, never for management keys |
| (never through tokens) | `project.manage`, `members.manage`, `environment.manage`, `credentials.manage`, `integrations.manage`, `experiments.manage` |

Later milestones add scopes under D14: `webhooks:read`, `webhooks:write`, `integrations:read` (M9); `analytics:read`, `subjects:delete` (M10); `experiments:read`, `experiments:write` (M11). M5 audit reads use `flags:read`. `webhooks:write` and `experiments:write` would grant capabilities listed above as never through tokens, so they are not issued until D14 decides (§8 item 14).

A management key reads project-level data (flags, segments) and acts on environment-level data only in its own environment. Project-level writes (creating a flag, editing variations or segments) are allowed with `flags:write` because every environment needs them (§8).

**Token format and generation** (`Credentials\Domain\TokenGenerator`):

```text
bf_srv_prod_<30 random base62><6 base62 checksum>      environment credentials
bf_pat_<30 random base62><6 base62 checksum>           personal tokens
```

- Random part: 30 characters from `random_bytes()` with rejection sampling (no modulo bias), about 178 bits.
- Checksum: CRC32 of everything before it, base62, zero-padded to 6. It lets the server and SDKs reject malformed or mistyped keys without a lookup, and makes secret scanning reliable.
- Regex: `^bf_(srv|pub|mgt)_[a-z0-9]{1,4}_[A-Za-z0-9]{36}$` and `^bf_pat_[A-Za-z0-9]{36}$`.
- Display: `display_prefix` + `••••` + `last4` (for example `bf_srv_prod_••••41c8`). "Copy key prefix" copies `bf_srv_prod_…41c8`, never the secret.

**Hashing scheme** (`App\Shared\Security\SecretHasher`, first used in M2):

- `secret_hash = HMAC-SHA256(key = pepper[pepper_id], message = full presented token)`, stored as `binary(32)` with a unique index.
- Peppers are 32 random bytes from the secret manager (`BITFLAG_CREDENTIAL_PEPPER_{n}`), never in the database. `config('bitflag.credentials.current_pepper_id')` picks the one for new hashes.
- Lookup: validate format and checksum, compute the HMAC under the current pepper and query by `secret_hash`; on miss, try the previous pepper if one is configured. Confirm with `hash_equals` on the fetched row.
- Why not bcrypt or argon2: tokens carry about 178 bits of entropy, so guessing is infeasible without a slow hash, and a slow per-request hash would make every SDK call expensive and prevent an indexed lookup. The pepper means a database dump alone cannot even test guesses.
- Pepper rotation: add a new pepper ID, issue new credentials with it, retire the old pepper when no active credential uses it (`credentials:pepper-usage` command reports counts). Losing every pepper invalidates every credential; the ops runbook covers backup of the secret.

**One-time reveal.** `IssueCredential` returns `IssuedCredential { credential, secret: PlaintextSecret }`. `PlaintextSecret` masks itself in `__toString`, `jsonSerialize`, `__debugInfo` and `__serialize`; only `reveal()` returns the value, and an arch test limits callers of `reveal()` to the credential controllers. Portal creation is an axios `POST` that returns JSON with the secret once; it is never put in the session, flash data, Inertia props or logs. The reveal panel cannot be dismissed by Escape or backdrop click until "I've stored this key somewhere safe" is ticked. A retried create with the same `Idempotency-Key` returns the metadata with `"secret": null, "secret_unavailable": true` (D14: no plaintext kept for replay).

**Credential state machine.**

```text
active ──rotate──▶ rotating ──overlap_ends_at passes──▶ revoked (rotation_completed)
active ──revoke──▶ revoked (manual)        rotating ──revoke──▶ revoked (manual)
active ──expires_at passes──▶ expired
active | rotating ──environment archived / project purged / member removed──▶ revoked
```

Authentication always checks `status`, `expires_at` and `overlap_ends_at` directly, so jobs only tidy listings and emit events.

**Rotation.** Only `active` credentials rotate (menu item disabled otherwise). Overlap options: 1 hour, 24 hours (default), 7 days. In one transaction: create the replacement (same type, environment, label, scopes, `ttl_days`; new `expires_at` from `ttl_days`; `rotated_from_id`), set the old one to `rotating` with `overlap_ends_at`, label shown with "(old)", `replaced_by_id`. Both authenticate until the overlap ends. Audit `key.rotated`. The replacement is revealed once with "Deploy it before the old key stops working ({time})".

**Revocation.** In one transaction set `revoked`, write audit `key.revoked` (or `token.revoked` for personal tokens), outbox `key.revoked` (R02) for environment credentials, and outbox `internal.credential.state_changed` for every credential type; after commit, overwrite the cache entry with the revoked summary (see Credential cache). The guarantee is: **rejected on the next request in the normal path, and in every failure case within the cache TTL (60 seconds)**. The dialog states "Requests with this key fail with 401 within a minute, usually right away", the last-used time, and the cached-snapshot consequence from the environment's maximum snapshot age ("SDKs that already downloaded a snapshot keep serving it for up to 24 hours, then return fallback values", or, when there is no limit, "SDKs that already downloaded a snapshot keep serving it until they next reach BitFlag, including after a restart if they use a persistent cache"). Revocation never promises to retract bytes already delivered.

**Membership changes.** On M2's `MemberRemovedFromProject` the user's personal tokens for that project are revoked (`member_removed`); on `MemberRemovedFromOrganization`, all their personal tokens in the org; on account deletion, all of them (`account_deleted`). Management keys keep working when their creator leaves (R03); the Keys tab shows "created by a former member" so Owners can decide whether to rotate. Role changes need no token action because personal-token capabilities are intersected with the live role on every request.

**Last-used tracking without a write per request** (`Credentials\Support\CredentialUsageRecorder`):

1. On every successful authentication: `SET bf:cred:seen:{id} 1 NX EX 60`. Only when that succeeds (at most once a minute per credential): `ZADD bf:cred:last_used GT {unix_ms} {id}` and `HSET bf:cred:client {id} {client}`.
2. `FlushCredentialLastUsed` runs every minute: atomically renames the sorted set and hash (Lua), then bulk-updates `credentials.last_used_at = GREATEST(COALESCE(last_used_at, 0), ?)` and `last_used_client` in chunks of 500.
3. Accuracy is about one minute; the UI shows relative times. If Redis is down, usage is not recorded (authentication still works from MySQL).

**Credential cache.** `CredentialResolver` caches a non-secret summary (`id`, `type`, organization, project, environment, scopes, `status`, `expires_at`, `overlap_ends_at`, `user_id`) at `bf:cred:{hex(secret_hash)}` for 60 seconds, and unknown hashes at `bf:cred:neg:{hex}` for 30 seconds. Expiry and overlap are re-evaluated from the cached timestamps on every request, so a cached entry never extends a credential's life. If Redis fails, the resolver queries MySQL.

Invalidation must survive a request that read MySQL before the change committed and writes the cache after it:

1. The resolver fills a missing entry only with `SET … NX EX 60`. It never overwrites an existing entry.
2. Every state change (revoke, rotate, expiry or scope change, purge) writes the new summary after commit with a plain `SET … EX 120`, overwriting whatever is there. It never deletes. A stale `NX` fill that arrives after this write fails because the key exists; one that arrived before it is overwritten.
3. The `internal.credential.state_changed` consumer (queue `publication`) repeats step 2 from MySQL. A crash between commit and the inline write is therefore repaired by the outbox, and the worst case is bounded by the 60-second TTL of any stale entry.

Tests (`CredentialRevocationRaceTest`): a resolver paused between its MySQL read and its cache fill while revocation commits and writes; a crash after commit and before the inline write; Redis unavailable during revocation and restored afterwards. Each asserts the next request after the bound is rejected.

### 3.3 Actions

| Action | Module | Input | Capability | Transaction, audit, outbox |
| --- | --- | --- | --- | --- |
| `CreateProject` | Projects | `CreateProjectData(name, key, description?, environments[])` | `projects.create`; `EnforcePlanLimit` | Project, environments, policy v1 each, creator Owner membership; audit `project.created`, `environment.created`; outbox `internal.project.created`, `internal.environment.created` (M4 creates disabled drafts) |
| `UpdateProjectSettings` | Projects | fields above, `expectedVersion` | `project.manage` | audit `project.updated` (changed fields) |
| `LockProjectKey` | Projects | project | System (called by `IssueCredential`, M5) | `key_locked_at` |
| `ArchiveProject` / `UnarchiveProject` | Projects | project | `project.manage`; `EnforcePlanLimit` on unarchive | audit `project.archived` / `project.unarchived`; outbox `internal.project.archived` |
| `ScheduleProjectDeletion` | Projects | project, typed key | `project.manage` | status, due date; audit `project.deletion_scheduled`; outbox `internal.email.requested{template: project_deletion_scheduled}` (Org Owners and project Owners, M8) |
| `RestoreProject` | Projects | project | `project.manage`; `EnforcePlanLimit` | audit `project.deletion_cancelled` |
| `PurgeProject` | Projects | project | System (job) | Revoke credentials via `RevokeCredential`, status `deleted`; audit `project.deleted` (actor system); outbox `internal.project.deleted` |
| `ExportProject` | Projects | project | `project.manage` | `project_exports` row; audit `project.exported`; dispatches `BuildProjectExport` |
| `ScheduleOrganizationDeletion` / `CancelOrganizationDeletion` / `PurgeOrganization` | Organizations | org, typed slug | `org.manage` / system | audit `organization.deletion_scheduled`, `organization.deletion_cancelled`, `organization.deleted`; outbox `internal.email.requested{template: organization_deletion_scheduled}` on schedule (Org Owners, M8) |
| `CreateEnvironment` | Environments | `CreateEnvironmentData(name, key, color, afterEnvironmentId?, protected)` | `environment.manage`; `EnforcePlanLimit`, `PlanRestrictionGate` | env, policy v1, positions shifted; audit `environment.created`; outbox `internal.environment.created` |
| `UpdateEnvironmentSettings` | Environments | name, key, color, origins, max age, policy fields, `expectedVersion` | `environment.manage`; `RequiredApprovals` gate when raising | env update, new policy version if policy fields changed; audit `environment.updated`, `environment.policy_changed`; domain event `EnvironmentPolicyUpdated` after commit when a new policy version is written (M5 listener); outbox `internal.environment.delivery_settings_changed` when origins or max age change |
| `ReorderEnvironments` | Environments | ordered IDs | `environment.manage` | audit `environment.reordered` |
| `RecordFirstPublication` | Environments | environment | System (M5 publication transaction) | `first_published_at` |
| `ArchiveEnvironment` | Environments | environment, typed key | `environment.manage` | status, revoke keys; audit `environment.archived`; outbox `internal.environment.archived`, `key.revoked` per key |
| `DeleteEnvironment` | Environments | environment, typed key | `environment.manage`; blockers empty | delete; audit `environment.deleted` |
| `IssueCredential` | Credentials | `IssueCredentialData(environmentId, type, label, scopes[], ttlDays?)` | `credentials.manage`; verified email; project and environment `active` | credential; `LockProjectKey`; audit `key.created` (type, label, scopes, prefix, last4) |
| `IssuePersonalAccessToken` | Credentials | `IssuePersonalAccessTokenData(projectId, label, accessLevel, ttlDays?)` | Project member; verified; password confirmed; access level within role; max 20 active per user per project | credential; `LockProjectKey`; audit `token.created` |
| `RotateCredential` | Credentials | credential, overlap | `credentials.manage` (environment credentials) | replacement + old to `rotating`; audit `key.rotated`; outbox `internal.key.rotated` (M9 Vercel sync) |
| `RevokeCredential` | Credentials | credential, reason | `credentials.manage`, or the token's own user for personal tokens, or system | audit `key.revoked` / `token.revoked`; outbox `key.revoked` (environment credentials) |
| `RevokeTokensForMember` | Credentials | user, scope | System (listener) | Calls `RevokeCredential` with `member_removed` |
| `CompleteRotation`, `ExpireCredential` | Credentials | credential | System (jobs) | audit `key.revoked` (reason `rotation_completed`) / `key.expired`; outbox `key.revoked` for completed rotations |

The actor for every system transition is `Actor::system()` with channel `system`.

### 3.4 Routes

**Portal** (Inertia pages under `resources/js/pages/project/settings/`; M0 scheme, so project pages carry the current `{environment}` segment and the environment picker keeps working; the environment being configured is `{target}`)

| Method and path | Page / response | Capability |
| --- | --- | --- |
| `GET /{organization}/{project}/{environment}/settings` | redirect to general | `project.read` |
| `GET /{organization}/{project}/{environment}/settings/general`, `PUT` | `General` | view `project.read`, save `project.manage` |
| `GET /{organization}/{project}/{environment}/settings/environments` | `environments/Index` | `project.read` |
| `POST /{organization}/{project}/{environment}/settings/environments` | redirect to the new env's Keys tab | `environment.manage` |
| `PUT /{organization}/{project}/{environment}/settings/environments/order` | redirect | `environment.manage` |
| `GET /{organization}/{project}/{environment}/settings/environments/{target}/{tab?}` | `environments/Show` with tab `overview`, `keys`, `delivery`, `history`, `settings` | `project.read` |
| `PUT .../{target}/settings` | redirect | `environment.manage` |
| `POST .../{target}/archive`, `DELETE .../{target}` | redirect to list | `environment.manage` |
| `POST .../{target}/keys` | JSON `{credential, secret}` | `credentials.manage` |
| `POST .../{target}/keys/{credential}/rotate` | JSON `{old, replacement, secret}` | `credentials.manage` |
| `POST .../{target}/keys/{credential}/revoke` | redirect | `credentials.manage` |
| `GET /{organization}/{project}/{environment}/settings/tokens` | `Tokens` | `project.read` |
| `POST /{organization}/{project}/{environment}/settings/tokens` | JSON `{credential, secret}` | member, `password.confirm` |
| `POST /{organization}/{project}/{environment}/settings/tokens/{credential}/revoke` | redirect | token owner or `credentials.manage` |
| `GET /{organization}/{project}/{environment}/settings/danger` | `Danger` | `project.read` |
| `POST .../danger/export`, `GET .../danger/exports/{export}/download` | redirect / signed download | `project.manage` |
| `POST .../danger/archive`, `.../unarchive`, `.../delete`, `.../restore` | redirect | `project.manage` |
| `POST /{organization}/settings/projects` | redirect to the new project | `projects.create` (New project dialog in Organization → Projects) |
| `POST /{organization}/settings/general/delete`, `.../restore` | redirect | `org.manage` |
| `GET /account/tokens` | `account/Tokens` | Signed-in; lists own personal tokens across projects with revoke |

Page props are display models, never Eloquent models: credentials expose `id`, `type`, `label`, `masked`, `scopes`, `createdAt`, `createdBy {name, formerMember}`, `lastUsedAt`, `lastUsedClient`, `expiresAt`, `state`, `overlapEndsAt`.

**Management API** (`routes/api.php`, prefix `/api/v1`, middleware `auth.token`, `throttle:management`)

| Method and path | Requires | Response |
| --- | --- | --- |
| `GET /api/v1/me` | any token | `{type, id, label, organization, project, environment?, scopes, expires_at, user?}` |
| `DELETE /api/v1/me/token` | any token | `204`; revokes the presenting token (CLI logout) |
| `GET /api/v1/projects/{project}` | `flags:read` | Project with settings and new-flag defaults |
| `GET /api/v1/projects/{project}/environments` | `flags:read` | Ordered environments with key, name, color, protection summary, policy version, status |
| `GET /api/v1/projects/{project}/environments/{environment}` | `flags:read` | One environment |

`{project}` accepts `prj_…` or the project key; `{environment}` accepts `env_…` or the environment key. Both resolve inside the token's own project and organization; anything else is `404`.

**Token authentication middleware** (`Credentials\Http\Middleware\AuthenticateManagementToken`):

1. Read `Authorization: Bearer`. Missing → `401 UNAUTHENTICATED`.
2. Format and checksum check. SDK key prefixes (`bf_srv_`, `bf_pub_`) → `401 WRONG_CREDENTIAL_TYPE`. Anything else malformed → `401 INVALID_CREDENTIAL`.
3. `CredentialResolver` lookup. Unknown → `401 INVALID_CREDENTIAL`. Matches a revoked, expired or rotated-out row → `401 CREDENTIAL_REVOKED` (the caller already holds that secret, so this discloses nothing and lets the CLI explain). `WWW-Authenticate: Bearer error="invalid_token"`.
4. Project `deleted` → `401 INVALID_CREDENTIAL`; organization suspended (M7) → `403 ORGANIZATION_SUSPENDED` for writes.
5. Personal token: the user must be `active`, a current project member, and pass the org `require_mfa` rule (a member who has not enrolled cannot use tokens in that org: `403 MFA_REQUIRED`).
6. Build M0's `Actor::managementToken(tokenId, creatorUserId, projectId, channel)` or `Actor::personalToken(user, tokenId, channel)`; channel from `X-BitFlag-Client` (`cli`, `mcp`) else `api`.
7. Record usage (§3.2); bind the scope for route-model resolution; capability checks then run through the same policies as the portal.

Rate limits: 600 requests per minute per credential, 3,000 per minute per organization, `429 RATE_LIMITED` with `Retry-After`. Errors use the conventions envelope with `request_id`.

**Contract for M5 (SDK delivery authentication).** M3 provides, and M5 consumes without re-implementing:

| Piece | Contract |
| --- | --- |
| `SdkKeyAuthenticator::authenticate(string $bearer): ResolvedSdkCredential` (the name M5 uses; wraps `CredentialResolver`) | Accepts only `server` and `browser`; returns ID, organization, project, environment, projection (`server` or `public`), scopes; throws `InvalidCredential` (`401 INVALID_CREDENTIAL`), `RevokedCredential` (`401 CREDENTIAL_REVOKED`) or `WrongCredentialType` |
| Scope check | `snapshot:read` for snapshot and stream, `evaluate` for remote evaluation (server only), `events:write` for telemetry; else `403 SCOPE_MISSING` |
| `EnvironmentDeliverySettings` query | `allowed_origins`, `default_max_snapshot_age_seconds`, `status`; cached, invalidated by `internal.environment.delivery_settings_changed` |
| `BrowserOriginPolicy::check(Environment, ?string $origin)` | Browser keys only; missing or unlisted origin → `403 ORIGIN_NOT_ALLOWED` |
| `CredentialUsageRecorder::touch(id, client)` | Called after successful auth, before cache or `304` responses |
| Invalidation signals | Outbox `key.revoked`, `internal.environment.archived`, `internal.project.deleted` so delivery caches drop entries |
| Rules | Authenticate before answering from cache or with `304`; the credential fixes environment and projection; request parameters cannot change them; never log the `Authorization` header |

### 3.5 Frontend

| Area | Files |
| --- | --- |
| Settings shell | `layouts/ProjectSettingsLayout.vue` with tabs General, Environments, Members (M2), API tokens, Danger zone (layout 1a); role banner for non-Owners ("You're a Developer. You can see environments and key metadata, but only owners can change settings or create, rotate and revoke keys." / "You're a Viewer. Everything here is read-only.") |
| General | `pages/project/settings/General.vue`; `features/settings/NewFlagDefaults.vue`; `features/settings/ProjectKeyField.vue` (copy button, lock note) |
| Environments | `pages/project/settings/environments/Index.vue` (table, up/down buttons with keyboard support, delete/archive explainer), `Show.vue`; tabs `features/environments/{OverviewTab,KeysTab,DeliveryTab,HistoryTab,SettingsTab}.vue`; `NewEnvironmentDialog.vue`, `ColorPicker.vue`, `ProtectionToggles.vue` (Pro gate message), `OriginListField.vue` (chips, Enter to add, inline errors), `ArchiveEnvironmentDialog.vue`, `DeleteEnvironmentDialog.vue` (blockers list) |
| Keys | `features/credentials/CreateKeyWizard.vue` (Type, Details, Copy key stepper; browser-key note listing allowed origins or "none set yet"), `SecretRevealPanel.vue` (shared; env variable name `BITFLAG_SERVER_KEY`, `BITFLAG_CLIENT_KEY`, `BITFLAG_API_KEY`), `KeyRowMenu.vue`, `RotateKeyDialog.vue`, `RevokeKeyDialog.vue` (acknowledgement checkbox), `ScopeChips.vue` |
| Tokens | `pages/project/settings/Tokens.vue` with two sections: "Personal tokens" (Settings 2d) and "Project management keys" (read-only list of `bf_mgt_` keys across environments, linking to each environment's Keys tab); `CreateTokenDialog.vue`, `RevokeTokenDialog.vue`; `pages/account/Tokens.vue` |
| Danger zone | `pages/project/settings/Danger.vue`, `DeleteProjectDialog.vue`, `TypedConfirmField.vue`, `components/ui/ProjectStatusBanner.vue` (deletion scheduled with Restore; archived) in the app layout |
| Composables | `useDirtyForm` (Save, Reset, "Unsaved changes", leave guard through Inertia `before` event and `beforeunload`, "Leave without saving?" dialog), `useSecretReveal` (holds the secret in a local ref, clears it on close, never in a store or browser storage), `useKeySlug`, `useOriginList` |

Overview tab shows "Never published" and "Nothing published yet. SDKs using this environment's keys get an empty snapshot and return your fallback values." until M5 supplies the revision panel; Delivery tab renders the same empty state until M5 and M10 fill it.

### 3.6 Jobs, events and outbox consumers

| Item | Schedule / trigger | Behavior |
| --- | --- | --- |
| `FlushCredentialLastUsed` | Every minute | §3.2 |
| `CompleteCredentialRotations` | Every minute | `rotating` past `overlap_ends_at` → `CompleteRotation` |
| `ExpireCredentials` | Every 5 minutes | `active` past `expires_at` → `ExpireCredential` |
| `NotifyExpiringCredentials` | Daily | Outbox `internal.credential.expiring` 7 days before expiry (M8 in-app to the creator and project Owners) |
| `PurgeDueProjects`, `PurgeDueOrganizations` | Hourly | Run `PurgeProject` / `PurgeOrganization` for due rows, one per job, with tenant IDs in the payload |
| `BuildProjectExport` | Queued (`exports` queue) | Iterates registered `ProjectExportSection`s (M3 registers `project` and `environments` with policy history; M4 adds flags and segments; M5 adds revisions); writes JSON to the private disk; never includes credentials |
| `ExpireProjectExports` | Hourly | Deletes files past `expires_at` |
| Listeners on `MemberRemovedFromProject`, `MemberRemovedFromOrganization`, `AccountDeleted` | M2 events | `RevokeTokensForMember` |
| Outbox produced | | `key.revoked` (R02), `internal.project.created`, `internal.project.archived`, `internal.project.deleted`, `internal.environment.created`, `internal.environment.archived`, `internal.environment.delivery_settings_changed`, `internal.credential.expiring`, `internal.key.rotated`, `internal.email.requested` (deletion scheduled) |

### 3.7 Security and tenant isolation

- Plaintext secrets exist only in the issuing request's memory and its one response. They never reach the database, Redis, sessions, flash data, Inertia props, audit details, outbox payloads, queue payloads, exception reports or logs.
- A Monolog processor masks anything matching the token regex in every log record; the exception handler strips the `Authorization` header and `secret` fields; request logging excludes `Authorization`.
- Audit details for credentials contain type, label, scopes, `display_prefix` and `last4` only.
- Response caching headers: secret-bearing responses send `Cache-Control: no-store`.
- Unknown credentials return `INVALID_CREDENTIAL`; only a presented secret that matches a revoked row returns `CREDENTIAL_REVOKED`. Both take the same lookup path.
- Credential routes resolve `{credential}` inside the environment and project in the URL; a key ID from another environment is `404`.
- Exports go to a private disk with signed, expiring download URLs and are scoped by organization and project.
- Every new table carries `organization_id`; tenant isolation tests cover `projects`, `environments`, `environment_policies`, `credentials`, `project_exports`, every portal route above (generated from the route list) and every API route with a token from another organization (`404`).

## 4. Implementation steps

Each step is one pull request.

1. **Project settings.** `UpdateProjectSettings`, `LockProjectKey`, `ProjectSettingsLayout.vue`, `General.vue`, `useDirtyForm`. Files: `app/Modules/Projects/{Actions,Data,Http/Controllers/Web}/*`.
2. **Plan contract calls.** Wire `EnforcePlanLimit`, `Entitlements` and `PlanRestrictionGate` (M6 contracts in `app/Shared/Plans`, null bindings) into `CreateProject`, `RestoreProject`, `UnarchiveProject`, `CreateEnvironment` and the settings actions; Free-limits test double in `tests/Support/FreePlanTestGates.php`; New project dialog in Organization → Projects.
3. **Environments core.** `CreateEnvironment`, `ReorderEnvironments`, `RecordFirstPublication`, `environments/Index.vue`, `Show.vue` shell with Overview, History (`EnvironmentHistoryQuery` over `audit_events`) and Delivery placeholder, `NewEnvironmentDialog.vue`, `ColorPicker.vue`.
4. **Environment settings and policy versions.** `UpdateEnvironmentSettings`, `PolicyEnvironmentProtection` (M0 interface), `Environments\Domain\{OriginRules, EnvironmentKeyRules, PolicyChange}`, `SettingsTab.vue`, `ProtectionToggles.vue`, `OriginListField.vue`.
5. **Environment archive and delete.** `ArchiveEnvironment`, `DeleteEnvironment`, dialogs, blockers query.
6. **Credential core.** `credentials` migration, `TokenGenerator`, `SecretHasher` peppers config, `PlaintextSecret`, `CredentialResolver` with cache, `CredentialUsageRecorder`, `FlushCredentialLastUsed`, log redaction processor, arch test on `reveal()`.
7. **Environment keys UI.** `IssueCredential`, `RotateCredential`, `RevokeCredential`, `CompleteCredentialRotations`, `ExpireCredentials`, `KeysTab.vue`, `CreateKeyWizard.vue`, `SecretRevealPanel.vue`, `RotateKeyDialog.vue`, `RevokeKeyDialog.vue`.
8. **Personal tokens.** `IssuePersonalAccessToken`, `RevokeTokensForMember` listeners, `Tokens.vue`, `CreateTokenDialog.vue`, `account/Tokens.vue`, `NotifyExpiringCredentials`.
9. **Management API authentication.** `AuthenticateManagementToken`, `ScopeTokenScopeMap` (M0 interface), rate limiters, error envelope for auth failures, `/api/v1/me`, project and environment endpoints, OpenAPI entries in `contracts/management/openapi.yaml`.
10. **Project lifecycle.** `ArchiveProject`, `UnarchiveProject`, `ScheduleProjectDeletion`, `RestoreProject`, `PurgeProject`, `ProjectPurger` registry, `PurgeDueProjects`, `Danger.vue`, `ProjectStatusBanner.vue`; organization deletion actions and General page section.
11. **Project export.** `ExportProject`, `project_exports`, `ProjectExportSection` registry, `BuildProjectExport`, `ExpireProjectExports`, download route.
12. **Hardening and end-to-end.** Plaintext leak test, tenant isolation suites, seed keys and tokens for the demo world (Checkout API server keys, Web storefront browser key, Release pipeline management key, a revoked Legacy mobile app key, Maya's two personal tokens), Cypress specs.

## 5. Tests

**Pest unit**

- `TokenGeneratorTest`: format regex per type, alphabet, length, checksum validation and rejection of a one-character change, distribution check that rejection sampling has no visible bias over 1,000,000 characters.
- `SecretHasherTest`: same input and pepper gives same hash; different pepper IDs differ; previous-pepper fallback.
- `ScopeCapabilitiesTest`: scope map; personal token intersection for each role (Viewer with `publish` scope gets no publish capability); management key never gets `changeset.approve`.
- `OriginRulesTest` (path, query, wildcard, `http` outside localhost, trailing slash, uppercase host, default port), `EnvironmentKeyRulesTest`, `ProjectLifecycleTest` (every transition and refusal), `PolicyChangeTest` (protected off clears sub-options).

**Pest feature** (real MySQL and Redis)

- **Plaintext never persisted or logged** (`CredentialPlaintextLeakTest`): issue each type through HTTP, use it against `/api/v1/me` and the M5 resolver, rotate, revoke, force an exception during a request carrying it; then search every text and blob column of every table (from `information_schema`), every Redis key and value, `storage/logs/*`, outbox and queue payloads, and session payloads for the full secret and its random part. Must find nothing.
- **Revoked keys are rejected immediately:** warm the cache with a successful call, revoke, next call is `401 CREDENTIAL_REVOKED`; the same for archive-environment and project purge.
- **Revocation races and crashes** (`CredentialRevocationRaceTest`, §3.2): stale cache fill after commit, crash before the inline cache write (repaired by the outbox consumer), Redis down during revocation; rejected within the 60-second bound in every case.
- **Rotation overlap:** old and new both work; with the clock at overlap end minus 1 second the old works, at the end it fails; the job marks it revoked and emits `key.revoked` once.
- Expired credentials rejected at `expires_at` even before the job runs.
- **No write per request:** 100 authenticated calls produce zero `UPDATE credentials` statements (`DB::listen`), and one flush updates `last_used_at`.
- Wrong credential type on the management API; SDK key prefix rejected; unknown and revoked bodies identical.
- Personal tokens: capability intersection with live role (demote Publisher to Viewer, token loses write); removal from project revokes; management key keeps working after its creator is removed and shows `formerMember`.
- Plan contract calls, with the Free-limits test double: two concurrent `CreateEnvironment` calls at 2 of 3 produce one success; restore and unarchive blocked at the limit; raising approvals gets `FEATURE_NOT_IN_PLAN`, lowering is allowed; rotation and revocation succeed in a project the restriction gate marks read-only.
- Policy versions: each protection change inserts a row; rows never update (model guard throws on update); non-policy edits do not create versions.
- Environment key locked after `RecordFirstPublication`; project key locked after first credential.
- Archive revokes keys and blocks new keys; delete refused with blockers; last active environment cannot be archived.
- Lifecycle: schedule, restore, purge with clock travel; SDK resolver accepts keys while scheduled and rejects after purge; read-only states reject edits with `PROJECT_ARCHIVED` / `PROJECT_DELETION_SCHEDULED` while rotation and revocation still succeed.
- Export excludes every credential field and contains registered sections only.
- **Tenant isolation:** generated route tests for portal and API; a token from organization A gets `404` on B's project by ID and by key.

**Pest arch**

- Only `Credentials\Http\Controllers\*` and `Credentials\Actions\*` call `PlaintextSecret::reveal()`.
- No module except Credentials writes `credentials`; Environments writes `environment_policies` only through its actions; `EnvironmentPolicy` model has no update path.

**Vitest**

- `SecretRevealPanel.spec.ts` (Done disabled until acknowledged; Escape does not close; secret cleared on unmount), `CreateKeyWizard.spec.ts` (default scopes per type; at least one scope; browser note), `OriginListField.spec.ts`, `useDirtyForm.spec.ts` (leave guard), `RevokeKeyDialog.spec.ts` (cache text for none and 24 hours), `ProtectionToggles.spec.ts` (sub-toggles disabled when not protected; Pro message).

**Cypress**

| Spec | Flow |
| --- | --- |
| `m3-project-settings.cy.ts` | Edit General, Reset, leave guard; project key (`key`) locks after first key; Jon (Developer) and Ana (Viewer) read-only |
| `m3-environments.cy.ts` | Create `qa` after staging, reorder, color; key editable then locked (test route marks first publication); protection toggles; origins validation; max age; archive revokes keys; delete a never-published environment (Free limit and Pro gate screens are covered by M6's Cypress specs) |
| `m3-sdk-keys.cy.ts` | Create server, browser and management keys; reveal once (reload shows only `••••last4`); copy prefix; management key calls `/api/v1/me`; rotate with 1-hour overlap, clock travel, old key `401`; revoke, immediate `401` |
| `m3-personal-tokens.cy.ts` | Create a token (password confirmation), use it, revoke; Priya's token stops working after she is removed from the project |
| `m3-project-deletion.cy.ts` | Typed confirmation, banner, restore; schedule again, clock travel 7 days, purge, keys `401` |

## 6. Decisions

| Item | Default assumed until decided |
| --- | --- |
| R03 | Management keys are project-owned, keep working after the creator leaves, have no service account. Created from an environment's Keys tab (as drawn) and listed in Settings → API tokens |
| R08 | Free limits 1 project and 3 environments; Pro 10 and 10 (placeholders); key rotation and revocation never gated, including in read-only projects |
| R02 | `key.revoked` emitted for server, browser and management keys (including rotation completion), not for personal tokens |
| D14 | Prefixes `bf_srv_`, `bf_pub_`, `bf_mgt_`, `bf_pat_` with display tag; scopes as in §3.2; secret responses not replayable; error codes `UNAUTHENTICATED`, `INVALID_CREDENTIAL`, `CREDENTIAL_REVOKED`, `WRONG_CREDENTIAL_TYPE`, `SCOPE_MISSING`, `ORIGIN_NOT_ALLOWED`, `PLAN_LIMIT`, `FEATURE_NOT_IN_PLAN`, `PROJECT_ARCHIVED`, `PROJECT_DELETION_SCHEDULED`, `MFA_REQUIRED`, `RATE_LIMITED`; no key management through the API yet |
| D12 | Project and organization deletion grace 7 days, restore until due, tombstone row, module purgers after retention; archive keeps serving; no environment unarchive; transfer not built |
| D02 | `required_approvals` 0 or 1, `allow_self_approval` false, `approval_ttl_hours` null (M5's 168-hour default); stored on the versioned policy so D02 can change values without a schema change |
| D04 | No default maximum snapshot age on any template environment |
| D06 | Browser keys limited to `snapshot:read` and `events:write`, origins enforced by M5 |
| D08 | Exports on the local private disk until object storage is chosen |
| D09 | Revoked credential metadata and tombstones kept for audit; retention period set by D09 |

## 7. Done criteria

- `CredentialPlaintextLeakTest` passes: no credential secret is stored or logged in plaintext anywhere.
- Rotation overlap works to the second, and revoked, expired, rotated-out and purged credentials are rejected on the next request, including with a warm cache; under races and crashes they are rejected within the 60-second bound.
- Last-used times update without a database write per authenticated request.
- Every create, restore and settings action calls the M6 plan contracts inside its transaction (verified with the Free test double).
- Every protection change produces a new immutable policy version.
- Cypress specs in §5 pass; tenant isolation tests pass for every new table and route; PHPStan, Pint, Vitest and `vue-tsc` are clean.
- M5 can authenticate SDK requests using only the contract in §3.4.

## 8. Risks and open questions

1. **Where management keys live.** Resolved (R03 amended, 7 October 2026): management keys are project-owned, scoped to one environment, created on that environment's Keys tab as drawn, and listed read-only in a "Project management keys" section under API tokens.
2. **Personal tokens are project-bound in the mockup** (Settings 2d to 2f sit under one project), while the docs describe them as acting with the user's own permissions. Default: one project per token; `GET /account/tokens` lists all. A CLI user with several projects needs several tokens until D11 decides `bitflag login`. **Resolved (R17, 8 October 2026): the default above is the decision.**
3. **Developer personal tokens.** portal/Settings offers Developers only "Read only" and "Read & edit drafts", although R01 lets Developers publish to unprotected environments. Default: offer "Read, edit & publish" to Developers too; the role intersection still blocks protected environments. **Resolved (R17, 8 October 2026): the default above is the decision.**
4. **Protection toggles vs R01.** Resolved (R14, 7 October 2026): R01 applies. Protection always means only Owners and Publishers publish; the mockup's separate "Only publishers can publish" toggle is removed and there is no `publishers_only` column.
5. **Second reviewer on Free.** Environments 2b says a protected environment needs "a reason and a second reviewer", Auth 5e says production only "needs a reason", and Organization 3d makes required approvals Pro-only. Default: on Free the protected toggle sets reason and publishers-only, not approvals. **Resolved (R17, 8 October 2026): the default above is the decision.**
6. **Deletion grace differs.** Settings 2i and 2j say 7 days; Emails 3c shows about 30 days (scheduled 6 Oct, deletes 5 Nov) and has Jon Reyes, a Publisher, scheduling it, though only Owners can. Default: 7 days, Owners only.
7. **Project archive has no mockup.** It is in the domain model lifecycle and needed for plan counting. Built as an "Archive project" row in Danger zone and a row action in Organization → Projects. Resolved (R17): archived projects keep serving and **count** toward the project limit, so archiving cannot be used to run extra live projects. Projects scheduled for deletion do not count, because they stop serving at purge within the grace period and restoring one checks the limit.
8. **Project transfer** is in Settings 2h and portal/Settings (with accept by the other organization's owner), but D12 holds transfer policy. Default: the row renders disabled with "Not available yet". A01 (full mockup scope) means this needs a decision before release.
9. **Environment delete blockers.** The mockup lists audit history as a blocker, which almost every environment has. Default: delete only when never published and no credential rows exist.
10. **Project key lock trigger.** Settings 1c says "fixed once SDKs use it" without defining it. Default: first credential issued or first publication.
11. **SDK endpoint path (mockup copy).** We build `/sdk/v1/snapshot` (M5). The portal Delivery tab's curl shows `/api/v1/sdk/snapshot` and needs a copy fix; the built Delivery tab uses the real path.
12. **Environment tag inside keys** (`bf_srv_prod_`) is drawn in the mockups but not in the domain model. Adopted as display-only; renaming an environment key before first publication leaves older keys with the old tag.
13. **Role copy.** The Environments Developer banner says "only admins can change settings"; there is no admin role. Copy changed to "only owners".
14. **Later token scopes.** `webhooks:write` (M9) and `experiments:write` (M11) would let a token reach `integrations.manage` or `experiments.manage`, which this plan keeps away from tokens. Default: not issued until D14 decides; read scopes ship.
