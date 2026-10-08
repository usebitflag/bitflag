# M7. Admin console

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Sources: `support-and-admin.md` (all), decision register R12, `accounts-and-organizations.md` §3 to §4 and §8, `domain-model.md` §11 (merge draft), `operations-and-release.md` §6.

## 1. Goal and scope

Give BitFlag staff an internal console to answer "why isn't my flag updating?", "why was I charged?" and "I lost my MFA device" without database access, without impersonation (R12), and without seeing more customer data than the question needs.

**In scope**

- A Filament panel inside `bitflag`, on its own host name, with its own `staff` guard, session cookie and middleware stack.
- Staff identities in a `staff_users` table, separate from customer `users`. MFA required on every sign-in, security keys (WebAuthn) preferred, TOTP as a fallback.
- Network restriction (allowlisted networks) for every console request.
- Two staff roles: **Support** and **Billing support** (Billing support adds billing lookups and billing actions).
- Read-only lookups from support-and-admin.md §3 as Filament resources and pages with explicit columns and no edit forms.
- One "Find" page: search by organization name or slug, user email, project key, public ID, or request ID.
- The support actions from §4, written by hand: resend verification or invitation, clear lockout, two-person MFA reset with a 24-hour delay, replay failed deliveries, billing credit and grace extension, two-person organization suspension.
- An append-only staff audit log of every view, search and action, plus customer audit entries with actor "BitFlag support" and channel `support`.
- The customer-facing pieces those actions need: MFA reset cancel page, suspended-organization state, "BitFlag support" rendering in the audit log.
- The verification tests in §7 of the doc.

**Out of scope**

- Impersonation or "view as customer" of any kind (R12).
- Editing flags, segments, environments, members, roles or keys; publishing or rollback; deleting organizations (support-and-admin §4, D12).
- Infrastructure operations: on-call uses dashboards and runbooks (operations-and-release §6), not this console.
- Staff management UI: staff accounts are created and disabled by artisan commands (§3.3), audited.
- The emails themselves: M7 pushes outbox messages; M8 renders and sends them.
- Company identity provider sign-in (proposed later, see §8).

## 2. Mockups covered

There are no admin console mockups. The console uses Filament's default look with a BitFlag staff header (environment name in a colored strip so production is unmistakable). Customer-facing changes reuse existing portal patterns:

| Where | Behavior |
| --- | --- |
| Audit mockup (M5) | Channel filter gains `support`; actor shows "BitFlag support" with a support icon; staff identity is never shown to customers |
| Auth mockup 3c "Account deactivated" (pattern) | Reused for the suspended-organization page: "This organization is suspended. Published flags keep serving. Contact support." |
| Emails mockup 1h layout (M8) | Reused for "Your two-factor sign-in will be reset" and "was reset" emails |
| Portal error screens (M0) | Already show the request ID that staff search |

## 3. Design

### 3.1 Data model

Staff tables are not tenant-owned and have no `organization_id` unless they reference a tenant. They live in the Support module.

**`staff_users`** (created by M0 with `id`, `name`, `email`, `password`, `status`, `remember_token`; M7 adds the other columns and the `invited` status)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `name` | varchar(120) | |
| `email` | varchar(320) unique | Must be on the company domain (`admin.staff_email_domain`) |
| `password` | varchar(255) null | Argon2id; null when only security keys are used |
| `role` | enum(`support`,`billing_support`) | |
| `status` | enum(`invited`,`active`,`disabled`) | |
| `totp_secret` | text null | Encrypted; fallback factor |
| `totp_confirmed_at` | timestamp(6) null | |
| `enrollment_token_hash` | char(64) null | One-time enrollment link from the create command, 24-hour expiry |
| `enrollment_expires_at` | timestamp(6) null | |
| `last_sign_in_at` | timestamp(6) null | |
| `failed_sign_in_count` | int | Lockout after 5 failures for 15 minutes |
| `locked_until` | timestamp(6) null | |
| `disabled_at` | timestamp(6) null | |
| `created_at`, `updated_at` | timestamp(6) | |

**`staff_webauthn_credentials`**: `id`, `staff_user_id`, `credential_id` varbinary(1024) unique, `public_key` blob, `sign_count` bigint, `aaguid` char(36), `transports` json, `label` varchar(60), `created_at`, `last_used_at`. Index `(staff_user_id)`.

**`staff_audit_events`** (append-only)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `staff_user_id` | ulid null | Null for system and artisan-originated entries |
| `operator` | varchar(120) null | Shell user for artisan commands |
| `action` | varchar(64) | `{object}.{verb}`, see §3.2 |
| `organization_id` | ulid null | |
| `target_type` | varchar(32) null | `organization`, `user`, `project`, `environment`, `support_action`, `request`, `staff_user` |
| `target_id` | varchar(64) null | Public ID or request ID |
| `ticket_reference` | varchar(64) null | |
| `query` | varchar(255) null | Search text for `console.searched` |
| `request_id` | varchar(32) | |
| `ip` | varbinary(16) | |
| `user_agent` | varchar(255) | |
| `details` | json | Redacted, metadata only |
| `occurred_at` | timestamp(6) | |

Indexes: `(staff_user_id, occurred_at)`, `(organization_id, occurred_at)`, `(action, occurred_at)`. The model throws on update and delete; a migration adds MySQL triggers that reject `UPDATE` and `DELETE` on this table. Retention D09.

**`support_action_requests`** (two-person and delayed actions)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `type` | enum(`mfa_reset`,`organization_suspend`) | |
| `organization_id` | ulid null | Suspend target |
| `target_user_id` | ulid null | MFA reset target |
| `status` | enum(`pending_approval`,`scheduled`,`executed`,`rejected`,`cancelled`,`expired`) | |
| `requested_by_staff_id` | ulid | |
| `approved_by_staff_id` | ulid null | `CHECK (approved_by_staff_id IS NULL OR approved_by_staff_id <> requested_by_staff_id)` |
| `ticket_reference` | varchar(64) | Required |
| `reason_code` | enum(`identity_verified_lost_device`,`abuse`,`non_payment_past_policy`) | |
| `reason` | text | Documented reason (required for suspension) |
| `verification_method` | enum(`ownership_challenge`,`org_owner_confirmation`,`document_check`) null | MFA reset only; no identity documents are stored |
| `approved_at` | timestamp(6) null | |
| `executes_at` | timestamp(6) null | `approved_at + 24h` for `mfa_reset`; `approved_at` for suspension |
| `executed_at`, `rejected_at`, `cancelled_at` | timestamp(6) null | |
| `cancelled_by_type` | enum(`staff`,`customer`) null | |
| `cancelled_by_id` | ulid null | |
| `cancel_token_hash` | char(64) null | For the customer cancel link |
| `version` | int | |
| `created_at`, `updated_at` | timestamp(6) | |

Indexes: `(status, executes_at)`, `(target_user_id, status)`, `(organization_id, status)`. A partial uniqueness rule (enforced in the action under a row lock on the target) allows only one open request per type and target.

**`request_error_log`** (makes request IDs searchable; written for portal and management API responses with status 400 or above, and for 5xx everywhere)

| Column | Type | Notes |
| --- | --- | --- |
| `request_id` | varchar(32) PK | `req_…` |
| `organization_id` | ulid null | |
| `user_id` | ulid null | |
| `credential_id` | ulid null | Token or key ID, never the secret |
| `surface` | enum(`portal`,`api`,`sdk`) | |
| `route_name` | varchar(128) | Route name, not the full URL |
| `method` | varchar(8) | |
| `status` | smallint | |
| `error_code` | varchar(64) null | |
| `occurred_at` | timestamp(6) | |

Index `(organization_id, occurred_at)`. Retention proposed 30 days (D09); pruned daily. SDK `401` and `429` are not logged per request (volume); delivery health reads counters instead (§3.3).

**`organizations`** (M0 already has `status` enum(`active`,`suspended`,`deletion_scheduled`); M7 adds through an Organizations migration): `suspended_at` timestamp(6) null, `suspension_reason_code` varchar(32) null, `suspended_by_request_id` ulid null, `status_before_suspension` varchar(32) null (so unsuspending a `deletion_scheduled` organization returns it to that state).

**Read models for the console** (`app/Modules/Support/ReadModels/`): read-only Eloquent models over other modules' tables, each selecting an explicit column list through a global scope and using a `ReadOnlyModel` trait that throws on `save`, `update`, `delete`. Filament tables require an Eloquent builder; these models are the named read models for the console and the only way `app/Admin` touches customer data. Queries run inside M0's `Tenancy::crossTenant('admin console lookup', …)` and always filter by the organization or user the staff member opened.

| Read model | Table | Columns exposed |
| --- | --- | --- |
| `ConsoleOrganization` | organizations | id, name, slug, created_at, require_mfa, session_idle_timeout_minutes, lifecycle status, deletion_scheduled_at, suspended_at |
| `ConsoleUser` | users | id, name, email, email_verified_at, status, last_active_at, created_at, timezone; lockout state comes from Identity's Redis lock through `Identity\Queries\SignInLockStatus` |
| `ConsoleMembership` | organization_memberships, project_memberships | user, organization, project, role, created_at |
| `ConsoleProject` | projects | id, organization_id, name, key, status, created_at |
| `ConsoleEnvironment` | environments + publication_pointers + revisions | key, name, protected, current revision number, published_at |
| `ConsoleCredential` | credentials | id, type, label, environment, status, created_at, last_used_at, revoked_at (no prefix, last characters or verifier) |
| `ConsoleWebhookEndpoint` | webhook_endpoints | id, URL host and path (query string dropped), status, subscribed event types, environment filter |
| `ConsoleWebhookDelivery` | webhook_deliveries | event ID, attempt, status, response code, error class, attempted_at, next_attempt_at |
| `ConsoleProviderConnection` | provider_connections | provider, status, status_reason, connected_at |
| `ConsoleOutboxReceipt` | outbox_receipts joined to outbox_messages (M0) | message type, consumer, status (`pending`, `failed`, `exhausted`, `succeeded`), attempts, next attempt, created and dispatched times (no payload) |
| `ConsoleSecurityEvent` | security_events (M2) | type, created_at (no IP address, no user agent) |
| `ConsoleEmailMessage` | email_messages (M8) | type, stream, status, queued_at, sent_at, delivered_at, bounced_at (no body, no payload) |
| `ConsoleSubscription`, `ConsoleInvoice`, `ConsoleEntitlementChange`, `ConsoleBillingEvent` | M6 billing tables | Status fields, amounts, dates, Stripe IDs; billing event `type`, `status`, times (no payload) |

### 3.2 Domain rules and state machines

**Staff roles and staff capabilities** (`Support\Domain\StaffCapability`, checked by staff policies; not part of the customer capability table)

| Staff capability | Support | Billing support |
| --- | --- | --- |
| `console.lookup` (all lookups except billing) | ✓ | ✓ |
| `console.audit.read` (staff audit log) | ✓ | ✓ |
| `console.support_actions` (resend, clear lockout, replay, request MFA reset, request suspension) | ✓ | ✓ |
| `console.two_person.approve` | ✓ | ✓ |
| `console.billing.read` | | ✓ |
| `console.billing.actions` (credit, grace extension, reconcile now) | | ✓ |

**Least data.** Never rendered: flag rules and conditions, variation values, segment conditions and include/exclude lists, override subject keys, context attributes, telemetry subject data, webhook payloads and response bodies, provider tokens, key prefixes or secrets, password hashes, MFA secrets, recovery codes, session IDs, IP addresses of customers, billing event payloads. Names, keys, counts, states, timestamps and error codes are allowed.

**Two-person request state machine**

```text
pending_approval ──approve (different staff, security-key step-up)──▶ scheduled ──executes_at reached──▶ executed
       │                                                                 │
       ├──reject (any other staff)──▶ rejected                           ├──cancel (staff or customer link)──▶ cancelled
       ├──cancel (requester)──▶ cancelled                                └──target changed (user deleted, MFA re-enrolled)──▶ cancelled
       └──72 h without approval──▶ expired
```

- The approver must differ from the requester (checked in the action and by the database `CHECK`).
- Approving requires a WebAuthn assertion within the last 5 minutes (step-up), not TOTP.
- Both staff must still be `active` when the action executes; otherwise it moves to `cancelled` with reason `staff_disabled`.
- `mfa_reset` executes 24 hours after approval. `organization_suspend` executes on approval.
- At execution the action re-checks that the request is still `scheduled` under a row lock (idempotent).

**MFA reset procedure (support-and-admin §4, accounts §4)**

1. Staff A verifies identity outside the console and records the ticket, reason and verification method; creates the request.
2. Staff B reviews and approves with a security key.
3. On approval: emails go to the account address (with a single-use cancel link) and to the Org Owners of every organization the person belongs to; customer audit entries are written in each of those organizations.
4. The person or any staff member can cancel during the 24 hours. A successful MFA sign-in by the person during the delay also cancels it (they evidently still have a factor).
5. After 24 hours: Identity removes MFA factors and recovery codes, revokes all sessions, and requires enrollment at next sign-in. Email "two-factor sign-in was reset" to the account and the same Org Owners.

**Suspension.** Sets `organizations.status = suspended` (the value M0 already defines) and blocks management writes for the organization through `App\Shared\Tenancy\OrganizationStatusGate` (added by M7, used by the management middleware on portal and API write routes). Allowed while suspended: reads, sign-in and account security, billing pages and Stripe flows (so a non-payment can be resolved), SDK key revocation. SDK delivery and evaluation are untouched. Error `403 ORGANIZATION_SUSPENDED`. Unsuspension is a single-staff action with a ticket reference (proposed; the doc lists only suspension).

**Staff audit action names** (`{object}.{verb}`): `console.searched`, `organization.viewed`, `user.viewed`, `project.viewed`, `delivery_health.viewed`, `integrations.viewed`, `billing.viewed`, `email_log.viewed`, `request.looked_up`, `staff_audit.viewed`, `support_action.requested`, `support_action.approved`, `support_action.rejected`, `support_action.cancelled`, `support_action.executed`, `verification_email.resent`, `invitation.resent`, `lockout.cleared`, `deliveries.replayed`, `billing_credit.applied`, `billing_grace.extended`, `billing.reconciled`, `organization.unsuspended`, `staff.signed_in`, `staff.sign_in_failed`, `staff.security_key_added`, `staff.created`, `staff.disabled`, `staff.role_changed`.

**Customer audit entries** use `Actor::support($staffUser)`: stored with actor type `support` and the staff ID internally, rendered to customers as "BitFlag support", channel `support`. User-level actions (resend verification, clear lockout, MFA reset) are recorded in every organization the person belongs to, plus the person's own security events (M2).

### 3.3 Actions

All actions live in `app/Modules/Support/Actions/`, take `Actor::support($staffUser)` (or `Actor::system('support')` for scheduled execution), write staff audit inside the transaction, and call the owning module's action for any customer change (never writing other modules' tables).

| Action | Input | Staff capability | Calls / transaction | Customer audit / outbox |
| --- | --- | --- | --- | --- |
| `SearchConsole` | query string | `console.lookup` | `Support\Queries\ConsoleSearch` (resolvers below); staff audit `console.searched` | none |
| `RecordStaffView` | target | any | Staff audit `*.viewed` (called from Filament page `mount`) | none |
| `ResendVerificationEmail` | user ID | `console.support_actions` | Identity `ResendVerificationEmail` (M2); rate limit 3 per user per hour | `verification_email.resent`; outbox from Identity |
| `ResendInvitation` | invitation ID | `console.support_actions` | Organizations `ResendInvitation` (M2), which re-mints the token | `invitation.resent` |
| `ClearSignInLockout` | user ID, ticket | `console.support_actions` | Identity `ClearSignInLock` (added to Identity by M7: deletes the Redis lock and failure counter for the email hash) | `lockout.cleared` |
| `RequestMfaReset` | user ID, ticket, verification method, reason | `console.support_actions` | Insert `support_action_requests` (`pending_approval`) under a lock on the user's open requests | none yet (internal only) |
| `ApproveSupportAction` | request ID, expected version, ticket | `console.two_person.approve`, step-up | Lock row, check requester ≠ approver, status, version; set `scheduled`, `executes_at`; mint cancel token (MFA reset) | MFA reset: `mfa_reset.scheduled` in each org; outbox `internal.support.mfa_reset_scheduled` (M8 emails). Suspend: executes immediately via `ExecuteSupportAction` |
| `RejectSupportAction` | request ID, reason | `console.two_person.approve` | Status `rejected` | none |
| `CancelSupportAction` | request ID, by staff or by customer token | staff: `console.support_actions`; customer: valid token | Status `cancelled` | `mfa_reset.cancelled`; outbox `internal.support.mfa_reset_cancelled` |
| `ExecuteSupportAction` | request ID | system | Lock row, re-check; MFA reset → Identity `ResetMfaFactors` (added to Identity by M7: delete factors and recovery codes, revoke sessions, flag `mfa_enrollment_required`); suspend → Organizations `SuspendOrganization` | `mfa.reset` or `organization.suspended`; outbox `internal.support.mfa_reset_completed` or `internal.organization.suspended` |
| `UnsuspendOrganization` | organization ID, ticket, reason | `console.support_actions` | Organizations `UnsuspendOrganization` | `organization.unsuspended` |
| `ReplayFailedDeliveries` | organization ID, scope (`outbox` or `webhook_endpoint` + ID), receipt or delivery IDs | `console.support_actions` | M0's replay of `exhausted` outbox receipts and M9 `RetryWebhookDelivery`, with the same replay rules as customers; idempotent by receipt and delivery ID | `deliveries.replayed` |
| `ApplyBillingCredit` | organization ID, amount, currency, ticket, reason | `console.billing.actions` | M6 `ApplyCustomerCredit` | `billing_credit.applied` |
| `ExtendBillingGrace` | organization ID, days, ticket | `console.billing.actions` | M6 `ExtendGracePeriod` | `billing_grace.extended` |
| `ReconcileBillingNow` | organization ID | `console.billing.actions` | M6 `ReconcileStripeSubscriptions` for one organization | none (staff audit only) |
| `RequestOrganizationSuspension` | organization ID, reason code, reason, ticket | `console.support_actions` | Insert `support_action_requests` (`organization_suspend`) | none yet |

Ticket references are validated against `admin.ticket_pattern` (default `^[A-Z]{2,10}-\d{1,8}$`) until a helpdesk is chosen.

**Search resolvers** (`ConsoleSearch`, in order, each scoped and limited to 20 results):

| Input | Resolver |
| --- | --- |
| `org_…`, `usr_…`, `prj_…`, `env_…`, `flg_…`, `var_…`, `seg_…`, `chg_…`, `rev_…`, `key_…`, `exp_…`, `whk_…`, `evt_…` | Decode public ID, look up the owning row, return the organization and project it belongs to (flag, segment, change set and revision IDs resolve to their project page; contents are never shown) |
| `req_…` | `request_error_log` by ID, then `audit_events.request_id` |
| Contains `@` | User by exact email, then prefix match on email |
| Otherwise | Organization slug exact, organization name prefix, project key exact |

**Staff accounts** (artisan, audited with the shell operator): `support:staff:create {email} {name} --role=support|billing_support` (prints a one-time enrollment URL), `support:staff:disable {email}`, `support:staff:role {email} {role}`, `support:staff:reset-factors {email}` (requires a second operator confirmation code from another active staff member's console session, mirroring two-person rules).

**Sign-in flow:** email and password (or security key first) → WebAuthn assertion (or TOTP if `admin.mfa.allow_totp` and no key is enrolled) → session. Enrollment requires at least one security key; TOTP can only be added after a key exists. Idle timeout 30 minutes, absolute 8 hours.

**Delivery health data sources** (read-only, scoped to one organization): outbox backlog and oldest pending age (`outbox_messages` where `dispatched_at is null`, plus `outbox_receipts` in `failed` or `exhausted`); recent publications and their delivery status (M5 publication delivery read model); SDK key last used (`credentials.last_used_at`); observed SDK versions and revisions (M10 `sdk_observations`; the panel shows "Not recorded yet" until M10); recent `401` and `429` rates per environment (M10 `delivery_request_rollups`, read through a `Support\Queries\DeliveryCounters` adapter that shows "Not recorded yet" until M10).

### 3.4 Routes

**Console host** (`config('bitflag.hosts.admin')` from M0, for example `console.bitflag.internal`; never the portal host). M0 already boots an empty Filament panel there with the `staff` guard and the `bitflag_staff_session` cookie; M7 fills it.

Filament panel `App\Admin\AdminPanelProvider`: `->id('admin')->domain(config('bitflag.hosts.admin'))->path('')->authGuard('staff')->login(StaffLogin::class)->globalSearch(false)` (Filament's automatic global search is disabled so no model attributes are indexed implicitly). Middleware stack: `RestrictToStaffNetwork` (CIDR allowlist; fails closed when empty in production), `UseStaffSessionCookie` (cookie `bitflag_staff_session`, host-only, `Secure`, `HttpOnly`, `SameSite=Strict`), `RejectBearerTokens` (any `Authorization` header → `401`), `EnsureStaffMfa`, `SecurityHeaders` (`frame-ancestors 'none'`, `X-Robots-Tag: noindex`).

| Filament class (`app/Admin/`) | Path | Content | Gate |
| --- | --- | --- | --- |
| `Pages\Find` | `/` | Search box and grouped results | `console.lookup` |
| `Resources\OrganizationResource` (`ListOrganizations`, `ViewOrganization`) | `/organizations`, `/organizations/{id}` | Infolist: plan, billing state, limits vs usage (M6 `BillingOverviewQuery`), Org Owners, member count, created, MFA requirement, session policy, deletion state, suspension. Relation tables: projects (key, status, environment, flag and segment counts), support actions taken | `console.lookup` |
| `Resources\UserResource` (`ListUsers`, `ViewUser`) | `/users`, `/users/{id}` | Organizations and roles, project roles, verification state, MFA enrolled yes or no and factor types, last sign-in, lockout state, recent security events (type, time, country) | `console.lookup` |
| `Resources\ProjectResource` (`ViewProject`) | `/projects/{id}` | Flag, segment, environment counts; per environment: key, current revision, published at, protection on or off | `console.lookup` |
| `Pages\DeliveryHealth` | `/organizations/{id}/delivery` | §3.3 data sources, filterable by project and environment | `console.lookup` |
| `Pages\WebhooksAndIntegrations` | `/organizations/{id}/integrations` | Endpoint and connection states, last 50 delivery attempts with status code and error class | `console.lookup` |
| `Pages\Billing` | `/organizations/{id}/billing` | Stripe customer ID (link to Stripe dashboard), subscription status, interval, period end, grace end, recent invoices, payment attempts (from billing event types and statuses), entitlement history | `console.billing.read` |
| `Pages\EmailLog` | `/users/{id}/emails` | Last 100 emails to the user: type, stream, time, delivery status | `console.lookup` |
| `Pages\RequestLookup` | `/requests/{requestId}` | Request error log row and linked audit entries (action, target, time) | `console.lookup` |
| `Resources\SupportActionRequestResource` (`ListSupportActionRequests`, `ViewSupportActionRequest`) | `/support-actions` | Queue of pending and scheduled requests; approve, reject, cancel actions | `console.support_actions` |
| `Resources\StaffAuditEventResource` (`ListStaffAuditEvents`) | `/staff-audit` | Filters: staff, action, organization, date | `console.audit.read` |
| `Widgets\PendingApprovals`, `Widgets\MyRecentActions` | dashboard | Counts and links | any |

Support actions appear as hand-written Filament `Action` classes on the relevant view pages (`App\Admin\Actions\ResendVerificationAction`, `ClearLockoutAction`, `RequestMfaResetAction`, `ApproveSupportActionAction`, `RejectSupportActionAction`, `CancelSupportActionAction`, `ReplayDeliveriesAction`, `ApplyBillingCreditAction`, `ExtendGraceAction`, `ReconcileBillingAction`, `RequestSuspensionAction`, `UnsuspendAction`). Each declares its own small modal schema (ticket, reason, amount), calls exactly one Support action, and is hidden when the staff capability is missing.

`routes/admin.php` (same host and middleware, outside Filament): `POST /webauthn/options/login`, `POST /webauthn/login`, `POST /webauthn/options/register`, `POST /webauthn/register`, `GET /enroll/{token}`, `POST /step-up` (fresh assertion for approvals), `POST /logout`.

**Portal additions** (`routes/web.php`, portal host only):

| Method and path | Page / response |
| --- | --- |
| GET `/account/security/mfa-reset/{request}/cancel?token=` | `account/MfaResetCancel` (signed, single-use, no sign-in required): explains the scheduled reset and offers "Cancel the reset" |
| POST same | `CancelSupportAction` with customer token → `account/MfaResetCancelled` |
| Any org-scoped page while suspended | Banner from shared prop `organization.suspended`; write attempts return `403 ORGANIZATION_SUSPENDED` and render `organization/Suspended` for full-page navigations to write-only pages |

No management API or SDK endpoints are added.

### 3.5 Frontend

The console is Filament (Livewire and Blade); no Vue. Panel theme: Filament default with a colored environment strip and the text "BitFlag staff console". Every table declares explicit `TextColumn`s; no resource defines `form()`; `canCreate`, `canEdit`, `canDelete`, `canDeleteAny` return false on every resource; bulk actions are disabled.

Portal (Inertia, `resources/js/`):

- `pages/account/MfaResetCancel.vue`, `pages/account/MfaResetCancelled.vue`.
- `pages/organization/Suspended.vue`, `features/organizations/SuspendedBanner.vue`.
- `features/audit/AuditActor.vue` (M5 component) gains the `support` actor rendering; `features/audit/ChannelFilter.vue` gains `support`.

### 3.6 Jobs, events, outbox consumers

| Item | Trigger | Does |
| --- | --- | --- |
| `ExecuteDueSupportActions` | scheduler, every minute | For each `scheduled` request with `executes_at <= now`, dispatch `ExecuteSupportAction` (unique job per request ID) |
| `ExpireSupportActionRequests` | scheduler, hourly | `pending_approval` older than 72 hours → `expired` |
| `CancelMfaResetOnSignIn` | Identity outbox `internal.identity.mfa_sign_in_succeeded` (M7 adds this message to M2's `CompleteTwoFactorChallenge`) | Cancels an open MFA reset for that user |
| `PruneRequestErrorLog` | scheduler, daily | Deletes rows older than 30 days |
| Middleware `RecordErrorRequest` (terminable, in `app/Shared/Errors`) | Every portal and API response with status ≥ 400, and SDK 5xx | Inserts into `request_error_log` after the response is sent |

Outbox messages produced for M8: `internal.support.mfa_reset_scheduled` (account email with cancel link, Org Owner notice), `internal.support.mfa_reset_cancelled`, `internal.support.mfa_reset_completed`, `internal.organization.suspended`, `internal.organization.unsuspended` (Org Owner emails; M8 adds the templates). These are domain messages that M8 maps to its email catalog, per the message-style rule in M8 §3.2.

### 3.7 Security and tenant isolation

- **Separate identity:** `staff_users` and guard `staff` (session driver, provider `staff_users`). Customer guard `web` uses `users`. No foreign key or shared login between them.
- **Host separation:** Filament and `routes/admin.php` are bound to the admin host; portal routes are bound to the app host (both from M0's `config/bitflag.php`); requests on the wrong host get `404`. Cookies are host-only with different names, so a customer cookie never reaches the console and a staff cookie never reaches the portal.
- **Tokens:** console middleware rejects any bearer token; API routes (`auth:api-token`) never accept a staff session.
- **Network:** `RestrictToStaffNetwork` allowlist; production refuses to boot the panel with an empty allowlist.
- **MFA:** required for every staff sign-in; approvals require a security key step-up.
- **No writes through Filament:** architecture tests forbid Filament `CreateRecord`, `EditRecord`, `form()` and implicit columns; read models throw on save.
- **Least data:** read models select explicit columns; a crawl test scans every console response for sentinel values (§5).
- **Rate limits:** console search 60 per minute per staff user; resend actions 3 per target per hour.
- **Tenant scoping:** lookups always start from a resolved organization or user; per-organization pages verify the child ID belongs to that organization (`ConsoleProject::forOrganization($org)`).
- **Customer visibility:** every customer-affecting action writes a customer audit entry in the same transaction as its staff audit entry.

## 4. Implementation steps

1. **Panel hardening.** Building on M0's panel shell: `config/admin.php` (allowlist, ticket pattern, MFA options), `RestrictToStaffNetwork`, `RejectBearerTokens`, `SecurityHeaders`, `globalSearch(false)`; tests for host, cookie and token separation.
2. **Staff identity.** New `staff_users` columns, `staff_webauthn_credentials`, `StaffUser` model, `staff` guard, artisan staff commands, enrollment page, `StaffLogin`, WebAuthn ceremonies (candidate package `laragear/webauthn`), TOTP fallback, lockout, `EnsureStaffMfa`, step-up endpoint.
3. **Staff audit.** `staff_audit_events` with triggers, `StaffAudit` service, `RecordStaffView` trait for Filament pages, `StaffAuditEventResource`.
4. **Read models and capabilities.** `Support\ReadModels\*`, `ReadOnlyModel` trait, `StaffCapability` and staff policies; architecture tests for `app/Admin` dependencies and read-only resources.
5. **Request error log.** `request_error_log`, `RecordErrorRequest` middleware, prune job, `RequestLookup` page.
6. **Lookups.** `Find` page and `ConsoleSearch`; `OrganizationResource`, `UserResource`, `ProjectResource`, `EmailLog` (empty until M8 tables exist).
7. **Delivery and integrations lookups.** `DeliveryHealth`, `DeliveryCounters` adapter, `WebhooksAndIntegrations` (shows "Not available" until M9 tables exist).
8. **Billing lookups and actions.** `Billing` page, `ApplyBillingCredit`, `ExtendBillingGrace`, `ReconcileBillingNow` and their Filament actions (needs M6).
9. **Simple support actions.** `ResendVerificationEmail`, `ResendInvitation`, `ClearSignInLockout`, `ReplayFailedDeliveries`; customer audit `support` channel rendering in the portal audit UI.
10. **Two-person framework and MFA reset.** `support_action_requests`, request, approve, reject, cancel, execute actions, `SupportActionRequestResource`, scheduled jobs, customer cancel pages, outbox messages, Identity `ResetMfaFactors` call.
11. **Suspension.** `organizations` columns, Organizations `SuspendOrganization` and `UnsuspendOrganization`, `OrganizationStatusGate` and middleware on portal and API write routes, `SuspendedBanner`, `organization/Suspended`, suspension request and unsuspend actions.
12. **Least-data crawl and end-to-end.** Sentinel crawl test, Cypress specs with a virtual authenticator, staff demo seed.

## 5. Tests

**Unit (`tests/Unit/Support/`)**: `ConsoleSearchParserTest` (prefix detection, email detection, request IDs); `SupportActionStateMachineTest` (every allowed and forbidden transition); `TicketReferenceTest`; `StaffCapabilityTest` (role matrix).

**Feature (`tests/Feature/Support/`)**

- `ConsoleAccessTest`: customer session cookie on the console host → staff login, never a page; customer `bf_pat_` bearer on console routes → `401`; staff session on portal routes → redirect to customer sign-in; console paths on the portal host → `404`; portal paths on the console host → `404`; request from a non-allowlisted IP → `403`.
- `StaffSignInTest`: password alone never creates a session; WebAuthn assertion completes sign-in; TOTP only when allowed and no key; lockout after 5 failures; disabled staff cannot sign in; idle and absolute timeouts.
- `NoSensitiveDataInConsoleTest`: seed sentinel strings into rule conditions, variation values, segment include lists, override subject keys, context attributes in telemetry rows, webhook payloads and response bodies, provider tokens, SDK key secrets and display prefixes, TOTP secrets, recovery codes, billing event payloads and email bodies; as a Billing support user, crawl every console GET route and every Livewire table render for the seeded organization; assert no sentinel appears in any response.
- `TwoPersonActionTest`: the requester cannot approve (action error and database `CHECK`); a different staff member can; concurrent approvals produce one `scheduled`; approval without a fresh security key assertion fails; MFA reset executes only after 24 hours (time travel), never earlier; cancellation by customer link and by staff; a successful MFA sign-in cancels it; a disabled approver cancels it at execution.
- `MfaResetEffectsTest`: after execution the user has no factors or recovery codes, all sessions are revoked, enrollment is required at next sign-in; emails queued through the outbox for the account and every Org Owner.
- `SuspensionTest`: suspension needs two staff; once suspended, portal and API writes return `403 ORGANIZATION_SUSPENDED`; reads, billing flows and key revocation work; SDK snapshot fetch still returns `200` with an unchanged body and ETag.
- `StaffAuditTest`: every console page view, search and action writes one staff audit entry with staff ID, target and ticket where required; staff audit rows cannot be updated or deleted (model and trigger).
- `CustomerAuditFromSupportTest`: each customer-affecting action writes customer audit entries with actor type `support`, channel `support`, displayed as "BitFlag support", in each relevant organization; the customer API never exposes the staff ID.
- `ReplayDeliveriesTest`: replay obeys customer replay rules and is idempotent.
- `BillingSupportGateTest`: Support role gets `403` on the billing page and billing actions; Billing support succeeds.
- `RequestErrorLogTest`: a `409` portal response creates a row with its request ID; search by that ID finds the organization.
- `ConsoleTenantScopingTest`: a project ID from organization B under organization A's pages → `404`.

**Architecture (`tests/Arch/AdminArchTest.php`)**

- `App\Admin` uses only `App\Modules\Support`, Filament, and Laravel; never other modules' models or actions directly.
- No class in `App\Admin` extends `CreateRecord` or `EditRecord`; every `Resource` defines no `form()` fields and returns false from `canCreate`, `canEdit`, `canDelete`.
- Every class in `Support\ReadModels` uses `ReadOnlyModel`.
- Every Filament `Action` class in `App\Admin\Actions` calls exactly one `Support\Actions` class.

**Cypress (`cypress/e2e/`)**, against the console host with a Chrome virtual authenticator added through the DevTools protocol (`Cypress.automation('remote:debugger:protocol', { command: 'WebAuthn.addVirtualAuthenticator', … })`):

- `m7-console-sign-in.cy.ts`: enrollment link, security key registration, sign-in, step-up prompt.
- `m7-console-lookup.cy.ts`: search "acme", a `prj_` ID and a `req_` ID; open organization, user, project, delivery health; assert no rule text is visible.
- `m7-console-mfa-reset.cy.ts`: Sam requests, Sam cannot approve, Ola approves, Mailpit shows the account email with a cancel link, the customer cancels in the portal.
- `m7-support-audit-visible.cy.ts`: after a lockout clear, Maya sees a "BitFlag support" entry with channel `support` in the Audit page.

**Seed**: `StaffDemoSeeder` (local and CI only) creates Sam Ortiz (Support), Ola Diaz (Support) and Bea Lim (Billing support) with virtual-authenticator-compatible test credentials.

## 6. Decisions

| Item | Status | Default assumed |
| --- | --- | --- |
| R12 Console, no impersonation | Resolved | As designed |
| R07, R08 | Resolved | Plan and limits shown from M6 |
| Staff MFA factor | Proposed here | WebAuthn required at enrollment; TOTP fallback configurable; approvals need a security key |
| Network or identity provider restriction (support-and-admin §2) | Open, with D08 hosting | IP allowlist now; company IdP (OIDC) sign-in as a later replacement for passwords |
| D08 Console host name | Open | `bitflag.hosts.admin` config (M0); separate DNS name and TLS certificate |
| D09 Retention of staff audit and request error log | Open | Staff audit kept indefinitely until D09; request error log 30 days |
| D12 Deletion | Open | Staff cannot delete organizations or data |
| D10 Credit and grace limits | Open | Credit capped by `billing.max_support_credit`; grace extension 1 to 14 days once per cycle |
| Helpdesk and ticket format | Not decided | Pattern `^[A-Z]{2,10}-\d{1,8}$` |
| Unsuspension | Not in the doc | Single staff with ticket |

## 7. Done criteria

- Pest, PHPStan and the four Cypress specs pass in CI.
- Console routes reject customer sessions and tokens; portal routes reject staff sessions (`ConsoleAccessTest`).
- `NoSensitiveDataInConsoleTest` passes for every console route and table.
- Two-person actions cannot be completed by one staff identity, and MFA resets never execute before 24 hours.
- Every console view, search and action produces a staff audit entry; every customer-affecting action produces a customer audit entry with channel `support`.
- Suspension never changes SDK delivery.
- Every lookup in support-and-admin §3 has a page, and every action in §4 has a hand-written action with its guardrail.

## 8. Risks and open questions

- **Filament internals and least data.** Livewire payloads can carry model attributes to the browser. Mitigated by read models that select explicit columns and by crawling Livewire responses in the sentinel test.
- **Request ID search** needs a store that support-and-admin.md does not name. `request_error_log` is new; confirm the table and its retention.
- **User-level support actions in organization audit logs.** The doc says "customer audit: yes" but some actions concern a person, not an organization. Recorded in every organization the person belongs to; confirm.
- **Staff account management** has no UI by design; confirm that artisan commands under change control are acceptable.
- **Org Owner notice for MFA resets when the person is the only Org Owner**: they get the account email only. Consider requiring a different verification method in that case.
- **"Restricted to the company network or identity provider"** depends on hosting (D08). Until then, the allowlist must include staff VPN egress addresses.
- **Suspension blocking writes** relies on every write route being under the management middleware; a test enumerates all non-GET portal and API routes and asserts the middleware is present, so a new route cannot skip it.
