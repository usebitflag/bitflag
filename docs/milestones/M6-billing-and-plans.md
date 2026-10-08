# M6. Billing and plans

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Sources: `billing-and-plans.md` (all), decision register R07, R08, R09, D10, `domain-model.md` §11 and §14 (merge draft), Organization mockup 1a–3f, Auth mockup 5d, Pricing mockup (catalog consistency only).

## 1. Goal and scope

Turn the R08 plans into enforced, Stripe-driven entitlements for an organization, without billing ever touching what applications are served.

**In scope**

- Cashier on the `Organization` model (the billable is the organization, never a user).
- Plan and limit catalog as versioned config (`config/plans.php`, started by M3 with the Free values), with placeholders for the Pro price and Pro limits (D10).
- Stripe Checkout for upgrade, Stripe Customer Portal for payment method, invoices, plan interval changes and cancellation.
- Verified, deduplicated, order-tolerant Stripe webhook intake (`billing_provider_events`) processed through the outbox. Entitlements change only here.
- Scheduled reconciliation against Stripe.
- Usage counted from BitFlag records (never SDK traffic), 90% warnings, at-limit blocking.
- One guard, `EnforcePlanLimit`, that every create action in M3, M4 and M9 calls inside its transaction; one `Entitlements` read contract for paid features and the audit window; one `PlanRestrictionGate` for over-limit read-only projects and environments. M3, M4, M5, M9 and M11 call these names (§3.3).
- `403 PLAN_LIMIT` and `403 FEATURE_NOT_IN_PLAN` for portal, API, CLI and MCP.
- Failed payment with a 14-day grace period, cancellation at period end, downgrade review, read-only projects and environments over the Free limit. Pausing integrations and extra webhooks is done by M9 (`ApplyPlanToIntegrations`) when M6 announces `internal.entitlements.changed`.
- Tax via Stripe Tax, invoices via Stripe (BitFlag is responsible for tax and invoices, R09).
- The Pro path of the onboarding plan step (Auth 5d). M2 owns the wizard and the page; M6 provides the catalog prop and the `billing.checkout` route and turns on `config('bitflag.billing.enabled')`.
- Billing actions that M7 support staff call (credit, grace extension, on-demand reconcile).
- A test proving billing state never changes a published snapshot or a delivery response.

**Out of scope**

- The public pricing page (website repository, `bitflag-website`). M6 only exports the catalog so the website stays consistent.
- Billing emails and in-app notifications: M6 pushes domain outbox messages; M8 maps them to its email catalog and sends them (message-style rule in M8 §3.2).
- The SDK abuse ceiling (rate limiting with `429`) belongs to delivery in M5. It never reads billing state.
- Enterprise, SSO, invoicing by contract (R08).
- Trials (D10 undecided; default: no trial, see §6).
- Physical audit retention (D09). M6 only hides entries outside the plan window.

## 2. Mockups covered

| Mockup | Artboard | Behavior implied |
| --- | --- | --- |
| Organization | 1a General | `Billing email` field: "Invoices and payment notices go here as well as to owners." M6 owns the column and its action; M2 owns the General page layout |
| Organization | 1b Members | Shows a "Billing Admin" org role. Not built (R07). Billing access is the capability `org.billing.manage`; see §3.7 for where Billing Admin would plug in |
| Organization | 2a Free plan, near a limit | Current plan card, "Compare plans", "Upgrade to Pro". Warning banner at 90% ("9 of 10 flags. Archived flags don't count"). Usage list: Projects 1/1, Environments in Storefront 3/3, Flags 9/10, Webhooks 1/1, Seats (count, unlimited), Segments (count, unlimited). "Not on Free" list with explanations. Footnotes: counted from records, warning at 90% |
| Organization | 2b Choose a plan | Monthly/Yearly toggle, Free (current) and Pro cards, "Continue to checkout", "Prices and Pro limits are placeholders" |
| Organization | 2c After Stripe Checkout | "Confirming your payment" with four status rows (Plan, Checkout, Subscription, Limits). Limits stay Free until a verified event. "You can leave this page; we'll email you either way" |
| Organization | 2d Pro plan | Plan, interval, renewal date, "Manage in Stripe", "Change plan". Usage with Pro maxima. Payment block: card brand and last four, billing email, tax ID, next invoice date. Invoices table with PDF links |
| Organization | 2e Downgrade review | Table of everything over Free limits with the consequence of each, effective at period end. "Keep Pro" and "Cancel Pro at period end" (opens Stripe to confirm) |
| Organization | 3a Creating a flag at the limit (owner) | Dialog "Free includes 10 flags", meter 10/10, "Review archivable flags", "Upgrade to Pro" |
| Organization | 3b Same, as a member | Same dialog without upgrade; lists who can upgrade (Owners, Billing admin) and "Copy request link" |
| Organization | 3c Paid feature on Free | Experiments page visible, "New experiment" disabled, explanation card, "Upgrade to Pro", "See what's in Pro". Note: API, CLI and MCP return `403 FEATURE_NOT_IN_PLAN` |
| Organization | 3d Paid setting on Free | "Require a second reviewer" toggle disabled inline with "Required approvals are on Pro" |
| Organization | 3e Payment failed, grace period | Project-level banner: "Pro stays active until Oct 20 while Stripe retries", "Update payment" |
| Organization | 3f Over the project limit after downgrade | Banner on the read-only project: still allowed are rollback, emergency disable, rotate or revoke keys. "Upgrade" |
| Auth | 5d Plan | Onboarding step after naming the organization: Free (continue) or Pro (opens Checkout). "Pro trial: not decided yet" copy must not ship |
| Pricing | Compare table, FAQ | Catalog source of truth must produce the same numbers. Not built here |

Project switcher shows "Acme · Free" (3a to 3f): a plan badge from shared props.

## 3. Design

### 3.1 Data model

All tables are in the Billing module unless noted. Tenant-owned tables carry `organization_id` first in their composite indexes.

**`organizations` (created by M0 and extended by M2; M6 adds columns through its own migration)**

| Column | Type | Notes |
| --- | --- | --- |
| `stripe_id` | varchar(255) null, unique | Cashier customer ID |
| `pm_type` | varchar(32) null | Cashier; card brand for display |
| `pm_last_four` | char(4) null | Cashier |
| `trial_ends_at` | timestamp(6) null | Cashier column; unused while trials are undecided |
| `billing_email` | varchar(320) null | Org 1a. Synced to the Stripe customer email. Null means "first Org Owner's email" |
| `billing_tax_id_display` | varchar(64) null | Display copy of the customer's tax ID from Stripe (2d). Never edited in BitFlag |

**`subscriptions`** (Cashier table, custom model `Billing\Models\Subscription extends Laravel\Cashier\Subscription` with `HasUlids`)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `organization_id` | ulid FK | Cashier derives the foreign key from the billable model, so this replaces `user_id` |
| `type` | varchar(32) | Always `default` |
| `stripe_id` | varchar(255) unique | |
| `stripe_status` | varchar(32) | `incomplete`, `trialing`, `active`, `past_due`, `unpaid`, `canceled`, `incomplete_expired`, `paused` |
| `stripe_price` | varchar(255) null | |
| `quantity` | int null | Always 1 (no seats) |
| `plan_key` | varchar(32) | Resolved from the price via the catalog (`pro`) |
| `billing_interval` | enum(`month`,`year`) | |
| `current_period_end` | timestamp(6) null | Renewal date on 2d, effective date on 2e |
| `cancel_at_period_end` | bool | Set from Stripe |
| `ends_at` | timestamp(6) null | Cashier |
| `first_payment_failed_at` | timestamp(6) null | Start of the current dunning cycle |
| `grace_ends_at` | timestamp(6) null | `first_payment_failed_at + billing.grace_days`, or a support extension |
| `stripe_synced_at` | timestamp(6) | Last time the row was rebuilt from a Stripe read |
| `created_at`, `updated_at` | timestamp(6) | |

Index: `(organization_id, type)`, unique `stripe_id`.

**`subscription_items`**: Cashier table with ULID `id`, `subscription_id`, `organization_id` (added for tenant scans), `stripe_id` unique, `stripe_product`, `stripe_price`, `quantity`. Index `(organization_id, subscription_id)`.

**`entitlements`**: one row per organization; the only thing enforcement reads.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `organization_id` | ulid, unique | Row-locked by `EnforcePlanLimit` |
| `plan_key` | varchar(32) | `free` or `pro` |
| `billing_state` | enum | See §3.2 |
| `max_projects` | int null | Null means unlimited |
| `max_environments_per_project` | int null | |
| `max_flags_per_project` | int null | |
| `max_webhooks` | int null | |
| `audit_history_days` | int | 7 or 90 |
| `features` | json | List of `Feature` values |
| `grace_ends_at` | timestamp(6) null | Copied for banners |
| `period_ends_at` | timestamp(6) null | |
| `catalog_version` | int | Catalog version used to compute the row |
| `version` | int | Optimistic version; bumps on every recompute |
| `computed_at` | timestamp(6) | |

**`entitlement_changes`** (append-only history, added beyond §14 so M7 can show "entitlement history" without parsing audit): `id`, `organization_id`, `from_plan_key`, `to_plan_key`, `from_billing_state`, `to_billing_state`, `cause` enum(`provider_event`,`grace_expired`,`reconciliation`,`support`,`catalog_change`,`organization_created`), `billing_provider_event_id` null, `staff_user_id` null, `created_at`. Index `(organization_id, created_at)`.

**`billing_provider_events`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `provider` | varchar(16) | `stripe` |
| `provider_event_id` | varchar(255) | Unique with `provider`: deduplication |
| `type` | varchar(64) | Stripe event type |
| `livemode` | bool | Must match the configured mode |
| `organization_id` | ulid null | Resolved from `customer` → `organizations.stripe_id` |
| `provider_object_id` | varchar(255) | Subscription, invoice or session ID |
| `provider_created_at` | timestamp(6) | Stripe `created` |
| `payload` | json | Stripe event body (Stripe never sends card numbers). Retention D09, proposed 400 days |
| `status` | enum(`received`,`processed`,`ignored`,`failed`) | |
| `attempts` | int | |
| `error_code` | varchar(64) null | |
| `received_at`, `processed_at` | timestamp(6) | |

Indexes: unique `(provider, provider_event_id)`, `(organization_id, received_at)`, `(status, received_at)`.

**`billing_checkout_sessions`**: `id`, `organization_id`, `stripe_session_id` unique, `plan_key`, `billing_interval`, `status` enum(`open`,`completed`,`expired`), `created_by_user_id`, `created_at`, `completed_at`. Drives 2c without trusting the browser.

**`billing_invoices`** (read model from `invoice.*` events, so pages never call Stripe while rendering): `id`, `organization_id`, `stripe_invoice_id` unique, `number`, `period_start`, `period_end`, `currency`, `amount_due`, `amount_paid`, `tax_amount`, `status` enum(`draft`,`open`,`paid`,`uncollectible`,`void`), `attempt_count`, `next_payment_attempt_at` null, `hosted_invoice_url`, `invoice_pdf_url`, `created_at`, `updated_at`. Index `(organization_id, created_at)`.

**`usage_meters`** (display read model, never used for enforcement): `id`, `organization_id`, `project_id` null, `meter` enum(`projects`,`environments`,`flags`,`webhooks`,`seats`,`segments`), `value` int, `counted_at`, generated `project_scope` char(26) = `COALESCE(project_id, '')`. Unique `(organization_id, project_scope, meter)`: a unique index on the nullable `project_id` would allow duplicate organization-level rows, because MySQL treats NULLs as distinct. Recounted (never incremented) by `RecountUsage`.

**`usage_warnings`**: `id`, `organization_id`, `project_id` null, `limit_key`, `threshold` enum(`warning_90`,`reached_100`), `first_seen_at`, `notified_at` null, `cleared_at` null, generated `project_scope` char(26) = `COALESCE(project_id, '')`, generated `open_key` tinyint null = `IF(cleared_at IS NULL, 1, NULL)`. Unique `(organization_id, project_scope, limit_key, threshold, open_key)`: at most one open warning per scope, limit and threshold; cleared rows (`open_key` null) are history and do not collide. Re-armed when usage drops below 80% so a flag archived and re-created does not spam.

**`plan_restrictions`**: projects and environments that are read-only because the organization is over its plan. (Webhook endpoints and provider bindings paused by plan are M9's own `paused(plan)` state, not rows here.)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `organization_id` | ulid | |
| `project_id` | ulid | |
| `subject_type` | enum(`project`,`environment`) | |
| `subject_id` | ulid | |
| `restriction` | enum(`read_only`) | |
| `reason` | enum(`over_project_limit`,`over_environment_limit`) | |
| `selected_by` | enum(`automatic`,`org_owner`) | Default `automatic` until D10 decides on owner choice |
| `created_at`, `lifted_at` | timestamp(6) | Active rows have `lifted_at` null |

Generated `active_subject_id` = `IF(lifted_at IS NULL, subject_id, NULL)`. Indexes: `(organization_id, lifted_at)`, unique `(subject_type, active_subject_id)`: at most one active restriction per subject. A unique key on `lifted_at` itself would not stop duplicate active rows, because their `lifted_at` values are all NULL.

**Plan catalog (config, not a table): `config/plans.php`** (M3 creates it with the Free values; M6 adds Pro, prices and features). Billing behavior settings (`grace_days`, `warning_ratio`, `max_support_credit`, Stripe price env names) live in `config/billing.php`.

```php
// config/plans.php
return [
    'catalog_version' => 1,
    'plans' => [
        'free' => [
            'name' => 'Free',
            'prices' => [],
            'limits' => ['projects' => 1, 'environments_per_project' => 3, 'flags_per_project' => 10, 'webhooks' => 1],
            'audit_history_days' => 7,
            'features' => ['integration.slack'],
        ],
        'pro' => [
            'name' => 'Pro',
            'display_price' => null, // "$—" until D10
            'prices' => ['month' => env('STRIPE_PRICE_PRO_MONTHLY'), 'year' => env('STRIPE_PRICE_PRO_YEARLY')],
            'limits' => ['projects' => 10, 'environments_per_project' => 10, 'flags_per_project' => null, 'webhooks' => null], // placeholders
            'audit_history_days' => 90,
            'features' => ['required_approvals', 'experiments', 'code_references', 'audit_export',
                'integration.slack', 'integration.github', 'integration.datadog', 'integration.jira',
                'integration.vercel', 'integration.sentry', 'email_support'],
        ],
    ],
];
```

Read through `Billing\Domain\PlanCatalog` (typed `Plan`, `PlanLimits`, enums `Feature`, `LimitKey`). `php artisan billing:export-catalog` writes `plans.json` for `bitflag-website`, so the Pricing page and the app share one source. A unit test asserts the catalog matches the R08 table.

### 3.2 Domain rules and state machines

**Counting (billing-and-plans §2).** Each limit has one named query in `Billing\Queries`, reading other modules' tables read-only:

| `LimitKey` | Scope | Counts |
| --- | --- | --- |
| `projects` | organization | `projects.status in (active, archived)` (archived projects still serve, R17; excludes `deletion_scheduled`) |
| `environments_per_project` | project | `environments.status = active` in the project |
| `flags_per_project` | project | `flags.status in (draft, active, deprecated, archiving)` in the project |
| `webhooks` | organization | `webhook_endpoints.status = active` |

Seats and segments are counted for display only. The flag limit is per project, as billing-and-plans §2 says; on Free the only editable project is the one that counts, so this matches 3a. See §8 for the 2e wording.

**Thresholds.** `warning` when `used / max >= 0.9` and `used < max`; `at_limit` when `used >= max`. With a max of 1 or 3 there is no warning state, only at-limit (2a shows "1 / 1" and "3 / 3" as full meters).

**Billing state (on `entitlements.billing_state`)**

| State | Derived from | Plan applied |
| --- | --- | --- |
| `free` | No subscription, or subscription `canceled` / `incomplete_expired` | Free |
| `pro_active` | `active` or `trialing`, not canceling | Pro |
| `pro_canceling` | `active` with `cancel_at_period_end` | Pro until `current_period_end` |
| `pro_grace` | `past_due` or `unpaid`, and `now < grace_ends_at` | Pro |
| `free_after_grace` | `past_due` or `unpaid`, and `now >= grace_ends_at` | Free |

`incomplete` (Checkout needing extra authentication) stays `free`. Transitions are computed, never set directly: `EntitlementResolver::resolve(?SubscriptionState, PlanCatalog, CarbonImmutable $now): EntitlementSet` is a pure function covered by a table-driven unit test.

```text
free ──checkout confirmed──▶ pro_active ──cancel in portal──▶ pro_canceling ──period end──▶ free
                              │    ▲                              │
                    payment   │    │ payment recovered            └─revert in portal─▶ pro_active
                    failed    ▼    │
                            pro_grace ──grace_ends_at passes──▶ free_after_grace ──Stripe cancels──▶ free
                                                                  └──payment recovered──▶ pro_active
```

Stripe dunning must be configured to retry for at least `grace_days` and then cancel the subscription. `free_after_grace` exists because Stripe may still be retrying after BitFlag's grace ends.

**Grace.** On the first `invoice.payment_failed` of a cycle, set `first_payment_failed_at` and `grace_ends_at = +14 days`. Later failures in the same cycle do not move it. `invoice.paid` clears both. M7's `ExtendGracePeriod` may move `grace_ends_at` forward by 1 to 14 days, at most once per cycle.

**Downgrade effects (billing-and-plans §4)** are applied by `ReconcilePlanRestrictions` whenever entitlements or usage change:

| Over the Free limit | Effect | Mechanism |
| --- | --- | --- |
| Projects | The oldest active project (by `created_at`) stays editable; the others get `read_only` | `plan_restrictions` rows, `selected_by = automatic` |
| Environments | Per project, the 3 oldest active environments (ties broken by position) stay editable; the rest get `read_only` | `plan_restrictions` |
| Flags over 10 | No restriction rows. Existing flags stay editable; `EnforcePlanLimit` blocks creation | Guard only |
| Required approvals | Existing policies keep applying. `Entitlements::assertAllows` blocks raising required approvals; lowering to 0 is allowed | M3 `UpdateEnvironmentSettings` calls it only when raising |
| Running experiments | Keep running, can be stopped and decided; new starts blocked | M11 `StartExperiment` calls `Entitlements::assertAllows` |
| Integrations other than Slack | Paused, configuration kept | M9 `ApplyPlanToIntegrations` on `internal.entitlements.changed` sets `paused(plan)` |
| Webhooks over 1 | Oldest active endpoint keeps running; others paused | Same M9 consumer |
| Audit over 7 days | Hidden in portal and API, not deleted | M5 audit queries apply `Entitlements::auditHistoryDays()` |
| Code references | Scans stop; last results stay with their scan date | M9 scheduler checks `Entitlements::allows` |

Restrictions are lifted automatically when the organization is back under the limit (scheduling a project's deletion or purging it, archiving an environment; archiving a project does not lower the count, R17) or upgrades; M9 restores its paused-by-plan items on the same `internal.entitlements.changed` message. M9 drops events raised while an endpoint is paused; nothing is backfilled on resume.

**Read-only project or environment.** `PlanRestrictionGate::assertConfigWritable()` blocks configuration edits: flag create and edit, drafts, segments, environment settings, change-set submission and normal publication. It never blocks rollback, emergency disable, SDK key rotation and revocation, member management, reads, project archive or deletion, or removing required approvals. Error: `403 PLAN_LIMIT` with `details.restriction = "read_only"`.

**Never gated** (billing-and-plans §1, R08): drafts, publishing (in a writable project), rollback, emergency disable, key rotation and revocation, MFA, simulator, flag types, targeting, analytics, CLI, MCP, API tokens. Enforced by architecture tests in §5.

**Billing never changes published configuration.** The Billing module has no dependency on Publishing actions, snapshot tables or delivery code, and delivery code has no dependency on Billing. Billing state is never an input to snapshot compilation.

### 3.3 Actions

Shared contracts live in `app/Shared/Plans/` so that M2, M3, M4, M5, M9 and M11 depend on them before M6 lands. Earlier milestones bind permissive null implementations (M5's `StaticEntitlements` implements `Entitlements`); M6 swaps the bindings.

**One contract per concern.** M6 defines the canonical names below. Earlier drafts used other names; this table records the renames, now applied in those plans (no behavior change):

| Name in sibling draft | Canonical (M6) |
| --- | --- |
| M3 `PlanGate::assertCanCreateProject`, `assertCanCreateEnvironment` | `EnforcePlanLimit::handle(org, LimitKey::Projects / EnvironmentsPerProject, project)` |
| M4 `PlanLimits::reserve(org, flags)` | `EnforcePlanLimit::handle(org, LimitKey::FlagsPerProject, project)` |
| M9 `PlanGuard::assertWithinLimit(Limit::Webhooks)` | `EnforcePlanLimit::handle(org, LimitKey::Webhooks)` |
| M3 `PlanGate::allows`, `limits`; M5 `Shared\Entitlements\Entitlements` (`auditHistoryDays`, `allows(Feature::AuditExport)`) | `App\Shared\Plans\Entitlements` |
| M3 `BillingStatus::hasActivePaidSubscription()` | Same name, `App\Shared\Plans\BillingStatus`, implemented by M6 |

```php
namespace App\Shared\Plans;

interface EnforcePlanLimit
{
    /**
     * The single plan-limit guard. Call as the first statement inside the creating
     * action's DB::transaction(). Locks the organization's entitlements row
     * (SELECT ... FOR UPDATE), then counts with a locking read (FOR SHARE) so the
     * count sees rows committed by a concurrent creator even if the caller already
     * opened a consistent-read snapshot.
     *
     * @throws PlanLimitReached  maps to 403 PLAN_LIMIT
     */
    public function handle(OrganizationId $organization, LimitKey $limit, ?ProjectId $project = null, int $adding = 1): void;
}

interface Entitlements
{
    public function allows(OrganizationId $organization, Feature $feature): bool;
    /** @throws FeatureNotInPlan  maps to 403 FEATURE_NOT_IN_PLAN */
    public function assertAllows(OrganizationId $organization, Feature $feature): void;
    public function auditHistoryDays(OrganizationId $organization): int;
    public function limits(OrganizationId $organization): PlanLimits; // for UI meters
}

interface PlanRestrictionGate
{
    /** @throws ProjectReadOnlyByPlan  maps to 403 PLAN_LIMIT, details.restriction = read_only */
    public function assertConfigWritable(ProjectId $project, ?EnvironmentId $environment = null): void;
}

interface BillingStatus
{
    public function hasActivePaidSubscription(OrganizationId $organization): bool;
}
```

Before M6, the M3 implementation of `EnforcePlanLimit` locks the `organizations` row instead of `entitlements` (no entitlements table yet); after M6 every caller locks the same `entitlements` row, so lock order is uniform.

`PlanLimitReached` carries `limit`, `scope`, `used`, `max`, `plan_key`, and, for the error envelope, `upgrade_url` (only when the actor holds `org.billing.manage`) and `billing_contacts` (display names of holders of `org.billing.manage`, for 3b). Message format: "Free includes 10 flags. Archive a flag or upgrade to Pro."

**Callers in other milestones** (each listed action must call the guard; enforced by an architecture test):

| Action (milestone) | Guard call |
| --- | --- |
| `CreateProject`, `RestoreProject` (M3; `UnarchiveProject` needs no check because archived projects count, R17); `AcceptProjectTransfer` only if D12 adds transfer | `EnforcePlanLimit` `projects` |
| `CreateEnvironment` (M3) | `EnforcePlanLimit` `environments_per_project` + `PlanRestrictionGate` |
| `CreateFlag`, `CloneFlag`, `RestoreFlag` (M4) | `EnforcePlanLimit` `flags_per_project` + `PlanRestrictionGate` |
| `SaveFlagDraft`, `UpdateFlagMetadata`, `SaveSegmentDraft`, `CreateSegment`, `UpdateEnvironmentSettings`, `UpdateProjectSettings` (M3, M4) | `PlanRestrictionGate` |
| `CreateChangeSet`, `SubmitChangeSetForReview`, `PublishChangeSet`, `PublishEnvironment` (M5) | `PlanRestrictionGate` |
| `UpdateEnvironmentSettings` (M3) when raising required approvals | `Entitlements::assertAllows` `required_approvals` |
| `CreateWebhookEndpoint`, `ResumeWebhookEndpoint` (M9) | `EnforcePlanLimit` `webhooks` |
| `StartProviderConnection`, `ConnectTokenProvider` (M9) for non-Slack providers | `Entitlements::assertAllows` `integration.{provider}` |
| `RequestCodeReferenceScan` (M9) | `Entitlements::assertAllows` `code_references` |
| `StartExperiment` (M11) | `Entitlements::assertAllows` `experiments` |
| `ExportAuditLog` (M5) | `Entitlements::assertAllows` `audit_export`; audit queries use `Entitlements::auditHistoryDays` |

M3 has no environment unarchive (D12), so there is no `RestoreEnvironment` call. Environments over the limit stay read-only through `PlanRestrictionGate` until the organization is under the limit or upgrades.

**Billing module actions**

| Action | Input | Capability | Transaction contents | Audit / outbox |
| --- | --- | --- | --- | --- |
| `InitializeEntitlements` | organization ID | system (called by M2 `CreateOrganization`) | Insert Free `entitlements` row, `entitlement_changes` (`organization_created`) | none |
| `StartCheckout` | `StartCheckoutData(organizationId, interval)` | `org.billing.manage` | Outside transaction: create Stripe customer if missing, create Checkout session (`client_reference_id` = org ULID, `metadata.organization_id`, `automatic_tax`, `tax_id_collection`, `billing_address_collection=required`, fixed success and cancel URLs). Then insert `billing_checkout_sessions`. Rejects with `409 ALREADY_SUBSCRIBED` when a non-ended subscription exists | `checkout.started` |
| `OpenCustomerPortal` | `organizationId`, `flow` enum(`manage`,`payment_method_update`,`subscription_update`,`subscription_cancel`) | `org.billing.manage` | No DB write; returns a portal URL with `flow_data` and a fixed return URL | none |
| `UpdateBillingEmail` | `organizationId`, `email` | `org.billing.manage` | Update `organizations.billing_email` (through an Organizations action), outbox `internal.billing.customer_sync` | `billing_email.changed` |
| `RecordProviderEvent` | verified Stripe event | webhook (system) | Insert `billing_provider_events` (unique on event ID; duplicate returns early), outbox `internal.billing.event_received` | none |
| `ApplyBillingEvent` | `billing_provider_events.id` | system | Before the transaction: re-read the subscription, customer or invoice from Stripe. In one transaction: upsert `subscriptions`, `subscription_items`, `billing_invoices`, customer display columns, grace fields; `RecomputeEntitlements`; mark event processed | See below |
| `RecomputeEntitlements` | organization ID, cause | system | Lock `entitlements`, resolve, update with `version + 1`, insert `entitlement_changes` when plan or state changed, call `ReconcilePlanRestrictions` | `plan.upgraded`, `plan.downgraded`, `subscription.cancellation_scheduled`, `subscription.cancellation_reverted`, `payment.failed`, `payment.recovered` (actor system, channel `system`); outbox `internal.billing.plan_changed`, `internal.billing.payment_failed`, `internal.billing.payment_recovered`, `internal.billing.subscription_ending`, and `internal.entitlements.changed` on every version change |
| `ReconcilePlanRestrictions` | organization ID | system | Compute the desired read-only set for projects and environments, insert and lift rows | `plan_restriction.applied`, `plan_restriction.lifted` |
| `RecountUsage` | organization ID | system | Recount all meters into `usage_meters`; open or clear `usage_warnings` | none |
| `RecordPlanLimitHit` | `PlanLimitReached` details, actor | system, own small transaction after the failed one rolled back | Upsert `usage_warnings` (`reached_100`), at most one outbox per org, limit and 24 hours | outbox `internal.plan.limit_reached` (M8 in-app to Org Owners and the blocked person) |
| `ExpireBillingGrace` | none (scheduled) | system | For subscriptions with `grace_ends_at <= now`, `RecomputeEntitlements(cause: grace_expired)` | as `RecomputeEntitlements` |
| `ReconcileStripeSubscriptions` | optional org ID | system or M7 Billing support | Re-read each customer's subscriptions from Stripe, repair drift, recompute | `entitlement_changes` with `cause = reconciliation`; metric `billing.drift_repaired` |
| `ApplyCustomerCredit` (called by M7) | `organizationId`, amount (bounded by `billing.max_support_credit`), currency, ticket reference | `Actor::support` with Billing support | Stripe customer balance transaction (idempotency key = support action ID) outside the transaction; then record | `billing_credit.applied` (channel `support`) |
| `ExtendGracePeriod` (called by M7) | `organizationId`, days (1 to 14), ticket reference | `Actor::support` with Billing support | Lock subscription, move `grace_ends_at`, recompute | `billing_grace.extended` (channel `support`) |

Network calls to Stripe never run inside a database transaction.

**Stripe events handled by `ApplyBillingEvent`**

| Event | Handling |
| --- | --- |
| `checkout.session.completed` | Mark `billing_checkout_sessions` completed; re-read the subscription it created. Entitlements change only through the re-read subscription state |
| `checkout.session.expired` | Mark session expired (2c shows "Checkout didn't complete") |
| `customer.subscription.created`, `.updated`, `.deleted`, `.paused`, `.resumed` | Re-read the subscription from Stripe and rebuild the local row; ignore event payload ordering |
| `invoice.payment_failed` | Upsert invoice; start grace if not started this cycle |
| `invoice.paid` | Upsert invoice; clear grace |
| `invoice.finalized`, `invoice.updated`, `invoice.voided`, `invoice.marked_uncollectible` | Upsert `billing_invoices` |
| `customer.updated`, `customer.tax_id.created`, `.updated`, `.deleted`, `payment_method.attached`, `customer.source.updated` | Re-read the customer; update `pm_type`, `pm_last_four`, `billing_tax_id_display` |
| Anything else | Stored with `status = ignored` |

### 3.4 Routes

Portal (`routes/web/billing.php`, under M0's organization settings prefix `/{organization}/settings`). All require org membership; billing pages require `org.billing.manage`.

| Method and path | Controller | Inertia page / response | Props |
| --- | --- | --- | --- |
| GET `/{organization}/settings/billing` | `Billing\Http\Controllers\Web\BillingOverviewController` | `organization/billing/Overview` (2a and 2d) | `plan`, `billingState`, `period`, `usage[]` (meter, used, max, scope, state), `warnings[]`, `featuresNotInPlan[]`, `payment` (brand, last four, billing email, tax ID, next invoice), `invoices[]`, `can` |
| GET `.../billing/plans?interval=year` | `BillingPlansController@show` | `organization/billing/Plans` (2b) | `catalog`, `currentPlan`, `interval` |
| POST `.../billing/checkout` | `BillingCheckoutController@store` | `Inertia::location(stripeUrl)` | |
| GET `.../billing/checkout/return?session={id}` | `BillingCheckoutController@return` | `organization/billing/CheckoutReturn` (2c) | `session` (status from our table, Stripe status read for display only), `entitlementPlan` |
| GET `.../billing/status` | `BillingStatusController` | JSON `{plan, billingState, entitlementsVersion}` for polling | |
| POST `.../billing/portal` | `BillingPortalController` | `Inertia::location(portalUrl)`; body `flow` | |
| GET `.../billing/downgrade` | `BillingDowngradeController` | `organization/billing/DowngradeReview` (2e) | `effectiveAt`, `impact[]` (key, current, freeMax, consequence, subjects) |
| PATCH `.../billing/email` | `BillingEmailController` | redirect back | |
| POST `/webhooks/stripe` | `Billing\Http\Controllers\Webhooks\StripeWebhookController` | `200` / `400` | CSRF-exempt, no session, body limit 256 KB, rate limited |

Cashier's own routes are disabled (`Cashier::ignoreRoutes()`).

Management API (`routes/api.php`):

| Method and path | Auth | Response |
| --- | --- | --- |
| GET `/api/v1/organizations/{organization}/plan` | `bf_pat_` of an org member | `{ plan, billing_state, features[], limits{}, audit_history_days, usage{} }`. `usage` is included only for holders of `org.billing.manage`; members get plan, features and limits |
| GET `/api/v1/plans` | none (public) | Catalog without Stripe price IDs, for CLI help text and the website |

Error envelope examples:

```json
{ "error": { "code": "PLAN_LIMIT", "message": "Free includes 10 flags. Archive a flag or upgrade to Pro.",
  "request_id": "req_…", "details": { "limit": "flags_per_project", "scope": "project", "project_id": "prj_…",
  "used": 10, "max": 10, "plan": "free", "upgrade_url": "https://…/acme/settings/billing/plans" } } }
{ "error": { "code": "FEATURE_NOT_IN_PLAN", "message": "Experiments are on Pro.",
  "request_id": "req_…", "details": { "feature": "experiments", "plan": "free", "required_plan": "pro" } } }
```

No SDK endpoints change. SDK routes never load billing middleware.

### 3.5 Frontend

**Shared Inertia prop** `billing` (added to M0's `HandleInertiaRequests`, cached per entitlements version): `{ plan, billingState, graceEndsAt, periodEndsAt, features[], projectRestriction: null | { restriction, reason }, environmentRestrictions: { [envId]: reason } }`.

**Pages** (`resources/js/pages/`): `organization/billing/Overview.vue`, `Plans.vue`, `CheckoutReturn.vue`, `DowngradeReview.vue`. M2's onboarding 5d page reuses `PlanPicker`.

**Feature components** (`resources/js/features/billing/`)

| Component | Used by | Behavior |
| --- | --- | --- |
| `PlanBadge.vue` | Project switcher (M0 shell) | "Acme · Free" |
| `CurrentPlanCard.vue` | Overview | Free or Pro, interval, renewal, "Manage in Stripe", "Change plan", "Compare plans", "Upgrade to Pro" |
| `UsagePanel.vue`, `UsageMeter.vue` | Overview, `PlanLimitDialog` | Meter states `ok`, `warning`, `at_limit`, `unlimited` |
| `LimitWarningBanner.vue` | Overview | 2a copy; "Review flags" link |
| `NotInPlanList.vue` | Overview (Free) | 2a "Not on Free" list |
| `PlanPicker.vue` | Plans, onboarding Plan | Monthly/Yearly toggle, cards from catalog, "$—" while `display_price` is null |
| `CheckoutStatus.vue` | CheckoutReturn | Four status rows; polls `/billing/status` every 3 s for 2 minutes, then shows "Still waiting. We'll email you." |
| `PaymentDetails.vue`, `InvoicesTable.vue` | Overview (Pro) | PDF opens Stripe-hosted URL |
| `DowngradeImpactTable.vue` | DowngradeReview | Rows from `impact[]` |
| `PlanLimitDialog.vue` | Any create flow | Owner variant (3a): meter, "Review archivable flags", "Upgrade to Pro". Member variant (3b): contacts list, "Close", "Copy request link" (copies the billing plans URL with `?request={limit}`) |
| `PaidFeatureCard.vue` | M11 Experiments empty state, M9 code references | 3c: visible, disabled primary action, explanation, "Upgrade to Pro", "See what's in Pro" |
| `PaidSettingNotice.vue` | M3 protection settings | 3d inline notice under a disabled toggle |
| `BillingBanner.vue` | Project layout | 3e payment failed (Org Owners get "Update payment"; others get "An organization owner has been notified"), 3f read-only project, `pro_canceling` notice |

**Composables** (`resources/js/composables/`): `usePlan()` (reads the shared prop), `usePlanGate(feature)` returning `{ allowed, reason, canUpgrade, upgradeUrl, contacts }`, `usePlanLimitError()` (maps a `PLAN_LIMIT` or `FEATURE_NOT_IN_PLAN` response to `PlanLimitDialog`), `useCheckoutStatus()`.

"Review archivable flags" links to the M4 flag list filtered to `deprecated` flags (assumption: M4's list supports a lifecycle filter).

### 3.6 Jobs, events, outbox consumers

| Item | Trigger | Queue | Does |
| --- | --- | --- | --- |
| `ProcessBillingProviderEvent` | outbox `internal.billing.event_received` | `billing` | `ApplyBillingEvent`; retries with backoff; after 10 attempts marks `failed` and alerts |
| `SyncStripeCustomer` | outbox `internal.billing.customer_sync` | `billing` | Pushes billing email to the Stripe customer |
| `RecountUsageConsumer` | outbox `internal.project.created`, `internal.project.archived`, `internal.project.deleted`, `internal.environment.created`, `internal.environment.archived`, `flag.changed`, `internal.flag.archived`, and `internal.usage.changed` (added by M3, M4, M9 only where no message exists yet: project restore, flag restore, webhook create, pause, resume, delete) | `default` | `RecountUsage` then `ReconcilePlanRestrictions` |
| `ExpireBillingGraceJob` | scheduler, every 15 minutes | `billing` | `ExpireBillingGrace` |
| `ReconcileStripeSubscriptionsJob` | scheduler, daily 03:00 UTC | `billing` | `ReconcileStripeSubscriptions` for every organization with a `stripe_id` |
| `ExpireCheckoutSessions` | scheduler, hourly | `billing` | Marks sessions older than 24 hours `expired` |
| Listener `RecordPlanLimitHitListener` | `PlanLimitReached` reported by the exception handler | sync | `RecordPlanLimitHit` |

Outbox messages consumed elsewhere: `internal.billing.plan_changed`, `internal.billing.payment_failed`, `internal.billing.payment_recovered`, `internal.billing.subscription_ending` (M8 emails 3a, 3b and banners), `internal.plan.limit_reached` (M8 in-app), `internal.entitlements.changed` (pushed by `RecomputeEntitlements` on every version change; M9 `ApplyPlanToIntegrations` pauses or restores, M2 and M5 caches drop the organization's entitlements).

### 3.7 Security and tenant isolation

- **Who can pay:** capability `org.billing.manage`, held by Org Owner only (R07). Billing contacts in 3b are computed as "holders of `org.billing.manage`". If a Billing Admin org role is added later, it gets `org.billing.manage` (and the billing emails) and no project capabilities; no other code changes. No new capabilities are added by M6.
- **Webhook authenticity:** signature verified with `Stripe\Webhook::constructEvent` against `STRIPE_WEBHOOK_SECRET` with a 300-second tolerance; invalid signature returns `400` and stores nothing. `livemode` must match the configured mode. Event IDs are deduplicated by a unique index.
- **Organization resolution:** only through `customer` → `organizations.stripe_id`. If `metadata.organization_id` disagrees, the event is marked `failed` and alerts; it never applies to either organization.
- **No browser trust:** the Checkout return URL only displays; entitlements wait for the webhook-driven re-read.
- **No card data:** Checkout and Customer Portal are Stripe-hosted. BitFlag stores brand and last four only.
- **Secrets:** Stripe keys and webhook secret only in environment config; never in props or logs. `billing_provider_events.payload` is excluded from logs and from M7 views (M7 shows type, status and times).
- **Tenant isolation:** all Billing queries take an explicit organization ID; the guard locks only that organization's row; usage queries scope by `organization_id` first. Isolation tests cover every new table.
- **Lock order:** `entitlements` row first, then any other row the creating action locks. Documented in the guard's docblock and checked in the concurrency test.

## 4. Implementation steps

Each step is one pull request.

1. **Catalog and contracts.** Pro section of `config/plans.php`, `config/billing.php`, `Billing\Domain\{PlanCatalog, Plan}`, `app/Shared/Plans/{EnforcePlanLimit, Entitlements, PlanRestrictionGate, BillingStatus, PlanLimits, Feature, LimitKey}` and exceptions (replacing the null bindings earlier milestones call, §3.3), error mapping in `app/Shared/Errors` for `PLAN_LIMIT` and `FEATURE_NOT_IN_PLAN`, `billing:export-catalog` command, catalog unit test.
2. **Schema and Cashier wiring.** Migrations for the organization columns and every table in §3.1; `Billing\Models\{Subscription, SubscriptionItem, Entitlement, EntitlementChange, BillingProviderEvent, BillingInvoice, BillingCheckoutSession, UsageMeter, UsageWarning, PlanRestriction}`; `Organization` uses `Billable`; `Cashier::useCustomerModel`, `useSubscriptionModel`, `useSubscriptionItemModel`, `ignoreRoutes`, `calculateTaxes`; tenant-isolation tests for new tables.
3. **Entitlements.** `EntitlementResolver` with the state table test, `InitializeEntitlements` (hooked into M2 `CreateOrganization`), `RecomputeEntitlements`, backfill command `billing:init-entitlements` for organizations created before M6, `billing:recompute-entitlements` for catalog changes.
4. **Limit guard.** `Billing\Services\EntitlementsEnforcePlanLimit`, the four count queries, `RecordPlanLimitHit`, container binding, guard calls added to the M3, M4, M9 actions listed in §3.3, architecture test that those actions use the guard, real-MySQL concurrency test.
5. **Entitlements and audit window.** `Billing\Services\StoredEntitlements` (replaces M5 `StaticEntitlements`), `Billing\Services\StripeBillingStatus`, calls in `UpdateEnvironmentSettings`, `ExportAuditLog`, `StartProviderConnection`, `ConnectTokenProvider`, `RequestCodeReferenceScan`, `StartExperiment` (stub until M11); `Entitlements::auditHistoryDays()` applied in M5 audit queries and API.
6. **Stripe gateway and webhook intake.** `Billing\Gateways\StripeGateway` interface, `CashierStripeGateway`, `FakeStripeGateway` (tests and Cypress); `StripeWebhookController`, `RecordProviderEvent`, `ProcessBillingProviderEvent`, `ApplyBillingEvent`; signed fixture events in `tests/Fixtures/stripe/`.
7. **Checkout.** `StartCheckout`, `BillingPlansController`, `BillingCheckoutController`, `BillingStatusController`, pages `Plans.vue` and `CheckoutReturn.vue`, `PlanPicker`, `CheckoutStatus`, `billing.checkout` accepting `return_to=onboarding` for M2's 5d step, `config('bitflag.billing.enabled')` on.
8. **Customer Portal and Pro overview.** `OpenCustomerPortal`, `UpdateBillingEmail`, `SyncStripeCustomer`, `billing_invoices` upserts, `Overview.vue` with `CurrentPlanCard`, `PaymentDetails`, `InvoicesTable`.
9. **Usage and warnings.** `RecountUsage`, `RecountUsageConsumer`, `internal.usage.changed` pushes where no message exists, `UsagePanel`, `LimitWarningBanner`, `NotInPlanList`, `PlanLimitDialog`, `usePlanLimitError`, `PlanBadge`.
10. **Payment failure and grace.** Grace fields handling, `ExpireBillingGrace` and its job, `BillingBanner` (3e), outbox messages for M8.
11. **Downgrade.** `ReconcilePlanRestrictions`, `PlanRestrictionGate` implementation and its calls (§3.3), `BillingDowngradeController`, `DowngradeReview.vue`, `DowngradeImpactTable`, `BillingBanner` (3f, canceling), `internal.entitlements.changed` for M9.
12. **Paid feature UI.** `PaidFeatureCard`, `PaidSettingNotice`, `usePlanGate`; wire 3d into protection settings and 3c into the Experiments placeholder.
13. **Reconciliation and support hooks.** `ReconcileStripeSubscriptions` and its job, `ApplyCustomerCredit`, `ExtendGracePeriod` (for M7), `ExpireCheckoutSessions`.
14. **Invariance and end-to-end.** Snapshot invariance test suite, Cypress specs, demo billing states, nightly Stripe test-mode workflow.

## 5. Tests

**Unit (`tests/Unit/Billing/`)**

- `PlanCatalogTest`: matches R08 (Free 1/3/10/1/7 days; Pro placeholders 10/10/unlimited/unlimited/90 days); every `Feature` has a plan; export JSON shape.
- `EntitlementResolverTest`: dataset over every Stripe status × `cancel_at_period_end` × grace before and after, expected plan and state.
- `UsageThresholdTest`: warning at 9/10, none at 2/3, at-limit at 1/1 and 3/3, unlimited never warns.
- `RestrictionSelectionTest`: oldest project stays editable; environment tie-break by position; oldest webhook stays active; Slack never paused.

**Feature (`tests/Feature/Billing/`, real MySQL and Redis)**

- `EnforcePlanLimitTest`: each `LimitKey` blocks at max with `PLAN_LIMIT` details; archived flags and deletion-scheduled projects do not count, archived projects do (R17); restoring an archived flag at the limit is blocked.
- `ConcurrentLimitTest`: at 9/10 flags, two processes (Laravel `Concurrency::run` with the process driver, separate connections) call `CreateFlag`; exactly one succeeds. Same for projects at 0/1 and webhooks at 0/1. Includes a variant where the caller ran a consistent read before the guard, proving the locking count sees the competitor's row.
- `EntitlementsFeatureTest`: each feature returns `403 FEATURE_NOT_IN_PLAN` on Free through the portal controller and the API (same action), with matching `details`.
- `StripeWebhookIntakeTest`: forged signature → `400`, nothing stored; wrong `livemode` → ignored; duplicate event ID → `200`, processed once; unknown customer → `ignored`; metadata mismatch → `failed`, no entitlement change.
- `OutOfOrderEventsTest`: `subscription.deleted` processed before `subscription.updated(active)`; because both re-read Stripe (fake returns `canceled`), the result is `free`. Reverse order gives the same result.
- `CheckoutFlowTest`: `StartCheckout` parameters (tax, client reference, URLs); browser return alone leaves `free`; signed `checkout.session.completed` + subscription re-read → `pro_active`, `plan.upgraded` audit, outbox `internal.billing.plan_changed`.
- `GracePeriodTest`: first failure sets grace +14 days; second failure does not move it; `invoice.paid` clears; time travel past grace → `free_after_grace`, restrictions applied; support extension moves the date once.
- `CancellationTest`: `cancel_at_period_end` → `pro_canceling`, outbox `internal.billing.subscription_ending`; period end → `free`.
- `DowngradeRestrictionsTest`: Pro org with 2 projects, 5 environments, 20 flags, 3 webhooks, GitHub connected, required approvals on production, a running experiment → after downgrade: second project read-only, extra environments read-only, `internal.entitlements.changed` pushed (M9's consumer, faked here, pauses extra webhooks and GitHub), flags editable but creation blocked, approvals kept and removable but not raisable, experiment stoppable, new experiment blocked, audit window 7 days.
- `ReadOnlyProjectTest`: on a read-only project, draft save, flag create, segment write, change-set submit and publish return `403 PLAN_LIMIT` (`restriction = read_only`); rollback, emergency disable (when M5 provides it), key rotation and revocation, member changes, project archive succeed.
- `RestrictionLiftTest`: scheduling deletion of the extra project lifts its sibling's restriction after `RecountUsageJob`; upgrading lifts all.
- `SnapshotInvarianceTest`: for each transition (upgrade, payment failed, grace expiry, cancellation, downgrade with restrictions, catalog change), record snapshot rows (checksum, bytes) and the SDK delivery response body and ETag for every environment before and after; assert identical, and assert `GET /sdk/v1/snapshot` stays `200` / `304`.
- `ReconciliationTest`: local row says `active`, fake Stripe says `canceled` → repaired, `entitlement_changes` with `cause = reconciliation`.
- `SupportBillingActionsTest`: `ApplyCustomerCredit` uses the support action ID as Stripe idempotency key; customer audit entries use channel `support`.
- `BillingTenantIsolationTest`: billing pages, status endpoint and API return `404` across organizations; webhook for org A never touches org B.
- `BillingAuthorizationTest`: Member gets `403` on billing pages and checkout; API `plan` omits `usage` for Members.

**Architecture (`tests/Arch/BillingArchTest.php`)**

- Actions listed in §3.3 use `EnforcePlanLimit`, `Entitlements::assertAllows` or `PlanRestrictionGate` as specified.
- `RollbackEnvironment`, `EmergencyDisableFlag`, `RotateCredential`, `RevokeCredential`, MFA actions and the simulator do not use `Entitlements`, `EnforcePlanLimit` or `PlanRestrictionGate`.
- `App\Modules\Billing` does not use `App\Modules\Publishing\Actions`, snapshot models, or delivery code; SDK controllers and `routes/sdk.php` middleware do not use `App\Modules\Billing` or `App\Shared\Plans`.
- No Billing class calls Stripe inside `DB::transaction` (enforced by routing all Stripe calls through `StripeGateway`, which throws when `DB::transactionLevel() > 0`).

**Vitest (`resources/js/features/billing/__tests__/`)**: `UsageMeter` states; `PlanLimitDialog` owner and member variants; `PlanPicker` interval toggle and "$—"; `CheckoutStatus` polling stops on `pro`; `usePlanGate` with and without `org.billing.manage`; `usePlanLimitError` maps both error codes.

**Cypress (`cypress/e2e/`)**, using `FakeStripeGateway` and `cy.task('stripe:send', fixture)` to post signed events:

- `m6-billing-free-limits.cy.ts`: 2a banner at 9 flags; creating the 11th shows 3a for Maya and 3b for Priya.
- `m6-billing-upgrade.cy.ts`: 2b → checkout (fake redirect) → 2c waiting → send event → Pro on 2d.
- `m6-billing-paid-features.cy.ts`: 3c experiments card, 3d disabled toggle; API call returns `FEATURE_NOT_IN_PLAN`.
- `m6-billing-payment-failed.cy.ts`: send `invoice.payment_failed` → 3e banner with date; time travel past grace → Free.
- `m6-billing-downgrade.cy.ts`: 2e table contents; cancellation event → 3f banner; rollback still allowed in the read-only project.
- `m6-onboarding-plan.cy.ts`: M2's 5d step with billing enabled: Free continues the wizard; Pro goes to checkout and returns to the wizard.

**Fixtures and seed**: `tests/Fixtures/stripe/*.json` (one per handled event type), `BillingDemoSeeder` and a local-only command `billing:demo-state {organization} {free-near-limit|pro|pro-grace|pro-canceling|downgraded}` used by Cypress. **Nightly CI**: a GitHub Actions workflow with Stripe test-mode keys runs `stripe trigger` for checkout, payment failure and cancellation against a staging-like stack and asserts entitlement states (proves "Stripe test-mode webhooks drive every state").

## 6. Decisions

| Item | Status | Default assumed until decided |
| --- | --- | --- |
| R07 Organization roles | Resolved | Only Org Owners hold `org.billing.manage`. Billing Admin plugs in by granting that capability |
| R08 Plans and limits | Resolved | Catalog in §3.1; Pro limits are placeholders |
| R09 Stripe, BitFlag owns tax and invoices | Resolved | Stripe Tax with `automatic_tax`, tax ID collection in Checkout, Stripe invoices |
| D10 Pro price | Open | `display_price = null`, shown as "$—"; Stripe test prices from env |
| D10 Final Pro limits | Open | 10 projects, 10 environments per project; changing them is a config change plus `billing:recompute-entitlements` |
| D10 Trials | Open | No trial (Free is the trial). Cashier `trial_ends_at` unused. Remove "Pro trial: not decided yet" from 5d |
| D10 Grace and dunning | Open (proposal) | 14 days from first failure; Stripe retries for at least 14 days, then cancels |
| D10 Downgrade project choice | Open | Automatic: oldest project and oldest environments stay editable; `selected_by` column ready for an owner choice screen |
| D10 Tax registrations | Open (business task) | Stripe Tax enabled per registered jurisdiction; no engineering blocker |
| D09 Audit retention | Open | Entries outside the plan window are hidden, never deleted by M6 |
| D12 Organization deletion | Open | M3 blocks organization deletion while a non-ended subscription exists, using `BillingStatus::hasActivePaidSubscription()` |
| D14 Error codes | Open | `PLAN_LIMIT`, `FEATURE_NOT_IN_PLAN`, `ALREADY_SUBSCRIBED`; read-only uses `PLAN_LIMIT` with `details.restriction` |
| D05 Emergency disable | Open | Never gated by billing, whatever D05 decides about who may use it |

## 7. Done criteria

- Pest, PHPStan, Vitest and the six Cypress specs pass in CI; the nightly Stripe test-mode workflow passes.
- Every state in Organization 2a to 3f and Auth 5d is reachable from Stripe test-mode events or the fake gateway, and renders as drawn.
- `SnapshotInvarianceTest` passes for every billing transition.
- `ConcurrentLimitTest` proves exactly one success at each limit on real MySQL.
- Forged, duplicated and reordered Stripe events never change entitlements incorrectly.
- Every gated control shows its reason in the portal and returns the documented code from the API (CLI and MCP inherit it through the API).
- Architecture tests prove the never-gated actions do not depend on billing gates and delivery does not depend on Billing.
- `plans.json` export matches the Pricing mockup numbers.

## 8. Risks and open questions

- **Cashier with ULIDs and a non-user billable.** Custom `Subscription` and `SubscriptionItem` models should work, but Cashier internals may assume integer keys in places. Fallback: integer keys on these two Cashier tables only, documented as an exception to the conventions.
- **Billing Admin (Organization 1b, 1c, 2a, 3b) versus R07.** Org 2a says "Only organization owners and billing admins can change the plan"; the Pricing FAQ says "Only organization owners". Built to R07.
- **Billing email (Org 1a)** is not in billing-and-plans.md or the domain model. Added as `organizations.billing_email`; M8 sends billing emails to Org Owners plus this address. Confirm.
- **Flag limit scope.** billing-and-plans §2 counts flags per project; Org 2e says "20 flags (Free has 10)" and Org 2d shows an organization total. Built per project; only matters on Pro display and after downgrade.
- **Audit history copy.** The Audit mockup says "Entries are kept for 1 year on this plan" and "Exporting needs the Owner role"; R08 says 7 or 90 days and export only on Pro. Resolved (R17): export needs `audit.export`, which every role with `audit.read` holds, plus the Pro `audit_export` feature. The mockup's "Exporting needs the Owner role" copy is removed.
- **Read-only error code.** Uses `PLAN_LIMIT` with `details.restriction = "read_only"` rather than a new code. Confirm or add `PLAN_READ_ONLY` under D14.
- **Unverified accounts and Checkout.** 5b lets unverified people continue setup and 5d offers Checkout. Default: allowed (Stripe collects the payer's email). Confirm.
- **Pricing "Yearly · 2 months free"** is a commercial commitment not in R08 or D10; the catalog leaves yearly price unset.
- **Grace reminder.** No reminder email before grace ends is documented; only the first failure email (3a) and the plan-changed email (3b).
- **Live Stripe calls on render** are avoided by `billing_invoices`; Checkout return does one Stripe read for display, which can fail and must degrade to "waiting".
