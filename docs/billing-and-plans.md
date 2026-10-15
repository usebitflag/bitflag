# Billing and plans

BitFlag is sold as plans with limits. This document defines the plans, what each includes, how limits are counted and enforced, how Stripe drives entitlements, and what happens on upgrade, downgrade, and failed payment. The overriding rule from [architecture](architecture.md): **billing may restrict management actions; it never changes a published flag or stops applications from evaluating.**

## 1. Plans

| | Free | Pro |
| --- | --- | --- |
| Price | $0 | Monthly or yearly, by card |
| Projects | 1 | 10 |
| Environments | 3 | 10 per project |
| Seats | Unlimited | Unlimited |
| Flags | 10 | Unlimited |
| Segments | Unlimited | Unlimited |
| Audit history | 7 days | 90 days, with export |
| Webhooks | 1 | Unlimited |
| Integrations | Slack | Slack, GitHub, Datadog, Jira, Vercel, Sentry |
| Required approvals on protected environments | — | ✓ |
| Experiments | — | ✓ |
| Code references | — | ✓ |
| CLI, MCP, API tokens | ✓ | ✓ |
| Support | Documentation and community | Email |

**Never gated on any plan:** drafts, publishing, rollback, emergency disable, key rotation and revocation, MFA, the simulator, all flag types and targeting.

Enterprise (contract, invoice, SSO/SCIM, longer audit, SLA) is added once company SSO exists.

## 2. Counting

Limits are counted from BitFlag's own records, never from SDK traffic.

| Limit | What counts |
| --- | --- |
| Projects | Active and archived projects (archived projects still serve, so they count); projects scheduled for deletion do not count |
| Environments | Environments not archived, per project |
| Flags | Flags in `draft`, `active`, or `deprecated` lifecycle across the project; `archived` flags do not count |
| Webhooks | Enabled webhook endpoints in the organization |
| Audit history | Entries older than the window are hidden from the portal and API on that plan; physical retention follows the retention policy |

Counts are checked inside the action that would exceed them, under the same transaction as the creation, so two concurrent creations cannot both pass at the limit.

## 3. Enforcement

| Situation | Behavior |
| --- | --- |
| Creating something over a limit | Blocked with a clear message naming the limit and an upgrade link for Org Owners ("Free includes 10 flags. Archive a flag or upgrade to Pro.") Others see who can upgrade |
| Using a paid feature on Free | The control is visible but disabled with an explanation, never hidden in a way that looks like a bug. API and CLI return `403` with a `PLAN_LIMIT` or `FEATURE_NOT_IN_PLAN` error code |
| SDK traffic | Served on every plan. A generous request ceiling exists only to stop abuse and is enforced by rate limiting with `429` and retry guidance; it is never billed. The exact ceiling is set from load tests |
| Evaluation | Never affected by plan, payment state, or limits |

## 4. Upgrade and downgrade

**Upgrade (Free → Pro):** Stripe Checkout; entitlements apply as soon as the subscription is confirmed by a verified Stripe event (never by the browser returning from Checkout). Proration follows Stripe's defaults.

**Downgrade (Pro → Free), or Pro ending:** nothing that is live changes. Handling of anything over Free limits:

| Over the Free limit | Behavior |
| --- | --- |
| Extra projects or environments | Keep serving. They become read-only for configuration edits until the organization is back under the limit or upgrades. Rollback, emergency disable, and key rotation/revocation stay available |
| More than 10 flags | Existing flags keep serving and can still be edited; creating new flags is blocked until under 10 |
| Required approvals | Existing approval policies keep applying so production does not silently lose protection. Org Owners may remove them; they cannot add new ones |
| Running experiments | Keep running and collecting data; they can be stopped and decided. New experiments cannot start |
| Integrations other than Slack, extra webhooks | Deliveries pause; configuration is kept and resumes on upgrade. The portal shows them as paused by plan |
| Audit beyond 7 days | Hidden, not deleted, subject to retention |
| Code references | Scans stop; the last results stay visible with their scan date |

The oldest project and its environments stay editable; the others are read-only until the organization is under the limit.

## 5. Stripe integration

| Concern | Design |
| --- | --- |
| Checkout and self-service | Stripe Checkout for upgrade; Stripe Customer Portal for payment method, invoices, and cancellation, opened from Organization settings → Billing |
| Source of truth | Stripe owns subscription and payment state; BitFlag stores a reconciled copy and derives entitlements from it |
| Webhooks | Verify signatures, deduplicate by event ID, tolerate out-of-order delivery by re-reading the subscription from Stripe, process through the outbox-backed job queue ([publishing and security](publishing-and-security.md) §3) |
| Reconciliation | Scheduled job compares local subscription state with Stripe and repairs drift |
| Tax and invoices | BitFlag is responsible. Stripe Tax calculates and collects tax, and Stripe invoices cover Pro. Tax registrations per jurisdiction are a business task |
| Who can pay | Org Owners only |

No card data touches BitFlag servers.

## 6. Failed payment and cancellation

1. Payment fails → Stripe retries on its schedule; Org Owners get an email and an in-app banner ([email and notifications](email-and-notifications.md)).
2. During a grace period (14 days) Pro stays fully active.
3. After grace, the organization moves to Free limits using the downgrade rules in §4. Nothing live is switched off.
4. Cancellation takes effect at the end of the paid period, then follows the same downgrade rules.

## 7. Trials

There is no trial; the Free plan serves that purpose.

## 8. Billing data in the domain

Subscription (Stripe IDs, plan, status, period end), entitlements (derived limits and feature flags for the organization), and a deduplicated provider event log. See [domain model](domain-model.md), "Persistence groups" (Billing). Billing and plan changes are customer-visible audit events.

## 9. Verification

- Concurrent creation at a limit allows exactly one success.
- Downgrade, failed payment, and cancellation never change a published snapshot or delivery response.
- Forged, duplicated, and reordered Stripe events do not change entitlements incorrectly.
- Every gated control shows the reason in the portal and returns the documented error code in the API, CLI, and MCP.
