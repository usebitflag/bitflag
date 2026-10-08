# Publishing and security

[Documentation index](README.md) · Status: proposed implementation design

## 1. The safety boundary

Editing a flag does not change what applications receive. Applications evaluate immutable, published environment snapshots. A successful database publication means that a new revision is authoritative; it does **not** mean that every connected or offline SDK has already observed it.

The portal must show these as different facts: draft saved, change approved, revision published, and delivery observed. Never label a database commit as globally propagated.

## 2. Change sets and approvals

The proposed initial publication unit is an entire environment draft. A preview and approval must cover every included flag, segment version, override, prerequisite, and experiment allocation change. The UI must not imply that a single flag is being published if other changes are included.

| State | Meaning | Allowed next steps |
| --- | --- | --- |
| Draft | Editable, versioned configuration | Save, simulate, submit for review |
| Pending review | Frozen candidate with base revision and content hash | Approve, reject, withdraw; edits create a replacement candidate |
| Approved | Required approvals apply to exactly this candidate | Publish if versions, permissions, and policy still match |
| Invalidated | Candidate or approval assumptions no longer hold | Rebase, revalidate, resubmit |
| Published | Candidate committed as a new immutable revision | Inspect, compare, request rollback |
| Rejected / withdrawn | Review ended without publication | Create a replacement change set |

Approval policy belongs to the environment and is checked at submission and publication. Changes to contributor content, the base revision, reviewer eligibility, or relevant policy require revalidation. Store the submitted diff, authors, reviewers, reasons, timestamps, policy version, and candidate hash.

Owners and Publishers may approve reviews (decision register R01). Self-approval, separation of duties, approval expiry, and minimum reviewers remain **D02** decisions. Multiple contributors make “author cannot approve” more complicated than comparing the last editor with the reviewer; use the candidate's contributor set.

## 3. Publication transaction

1. Authenticate the actor and resolve organization, project, and environment through scoped identifiers.
2. Authorize publication in that environment, including current approval policy and any required reason.
3. Validate the submitted expected draft version, expected published revision, and frozen candidate hash. Build and validate the proposed snapshot from immutable inputs; mutable inputs must be frozen or rechecked before commit.
4. Start a database transaction and lock the environment publication record. Reserve or verify the operation's scoped idempotency key under a unique constraint.
5. Recheck the versions, authorization-sensitive state, approval eligibility, and candidate identity inside the transaction. Concurrent permission and policy changes must follow a documented locking/versioning convention; a preliminary policy check alone is insufficient.
6. Allocate the next environment revision. Insert the immutable snapshot and its metadata, update the published pointer, record the change-set transition, and insert the audit event and outbox records atomically.
7. Commit. Return the revision, publication ID, and current delivery status. A retry of the same authorized operation returns the recorded result without creating another revision.
8. Workers process outbox effects: update delivery caches, notify streams, dispatch integration events, and update read models. Retries must be safe.

No network calls to Slack, a billing provider, or a CDN belong inside the transaction. Database uniqueness and locking are the final concurrency protection; a Redis lock alone is not sufficient.

If the base or draft changes, return a conflict with enough metadata for the portal to show a rebase workflow. Do not silently publish a newer draft than the user reviewed.

Outbox processing is at-least-once. Consumers deduplicate by event/operation identity and avoid replacing a newer cached revision with an older event. Retry with backoff, surface exhausted attempts, and support controlled replay. The outbox pattern closes the database-write/message-send gap but does not make external effects exactly-once. [AWS transactional outbox guidance](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html)

## 4. Rollback and emergency disable

**Rollback creates a new revision using an earlier configuration.** It never moves the revision counter backward or deletes history. Show the selected historical revision, resulting diff, affected experiments, and any references that no longer satisfy current policy. A historical snapshot is not exempt from today's authorization and validation requirements.

Pending drafts must not be silently discarded. Preserve them and mark their base as stale, then require an explicit rebase or discard decision. Experiment analysis must retain which allocation/configuration revisions were active over time; restoring configuration cannot undo exposures already recorded.

**Emergency disable is a separate proposed operation, not a shortcut that publishes all drafts.** Construct a minimal change from the current published revision, turn off the chosen flag, check dependency impact, and commit a new revision. Require explicit authorization, reason, audit event, and visible confirmation of the affected environment. Whether this can bypass normal approvals, who can do it, and whether rollback uses the same exception are **D05** decisions.

Neither operation can promise immediate effect in offline SDKs. The interface should distinguish publication time from last observed delivery and document the cache/revocation policy.

## 5. Tenant isolation and authorization

- Organization membership does not automatically grant every action in every environment. Policies consider actor, organization, project, environment, and operation.
- Scope model lookups before returning records, counts, search results, exports, or validation details. Random-looking IDs are not authorization.
- Commands, jobs, CLI/MCP requests, and API endpoints use the same application actions and policy rules. Inertia controllers do not become the only authorization boundary.
- Queue payloads carry explicit tenant and actor/service identity; workers clear tenant context between jobs. Scheduled actions recheck permissions at execution under an explicitly defined service identity.
- Review the last-owner removal and ownership-transfer cases. Organization transfer must not silently grant access to old credentials or integrations.
- Audit administrative access and support impersonation if offered; impersonation is not assumed to be a release feature.

Laravel policies are the framework mechanism for resource authorization; the policy contents and environment rules remain BitFlag's responsibility. [Laravel authorization documentation](https://laravel.com/docs/13.x/authorization)

## 6. Credentials and sessions

Management tokens, server SDK keys, browser identifiers, webhook signing secrets, and OAuth grants are different credential classes. Give each a distinct prefix/type, scope, lifecycle, and allowed endpoint family.

Store high-entropy bearer token verifiers using a suitable one-way hash; reveal newly created secrets once. Store only a safe display prefix, creator, last-use metadata, expiry, and revocation state alongside the verifier. OAuth refresh tokens and outgoing webhook signing secrets must be recoverable to function, so encrypt them at rest with managed key rotation rather than pretending they can all be hashed.

Rotation supports an explicit overlap window and shows which key is being retired. Never put server keys in Vue bundles, browser storage, URLs, logs, analytics, or screenshots. Browser credentials must have no management privileges and cannot be treated as confidential.

Portal authentication uses secure, HttpOnly session cookies and CSRF protection. Include signup, verification, reset, invitation acceptance, MFA/recovery, session inspection, and revocation flows. Avoid account-enumerating error messages. Scope cookies to the app host where possible; the marketing website does not need the portal session.

## 7. Delivery and application security

Authenticate and authorize before responding with a cached private snapshot or `304 Not Modified`. Cache keys include environment, audience, and projection identity; never allow an edge cache to mix tenants or public/private payloads.

Validate snapshot inputs, expression depth, prerequisite cycles, JSON payload size, override cardinality, and rule complexity before publication. Rate-limit management, evaluation, streaming, and ingestion independently. Flag checks are not a substitute for application authorization: client-side flags cannot protect sensitive server operations.

Treat webhook destinations and integration URLs as SSRF-sensitive. Restrict schemes and ports, deny internal/metadata destinations, validate resolved addresses at connection time, recheck redirects, and apply network-level egress restrictions. Do not rely on a single URL string check.

Apply output encoding and content security controls to flag descriptions, audit metadata, integration responses, and user-supplied JSON. Avoid arbitrary executable rules. Use dependency and secret scanning across all repositories.

## 8. Audit, privacy, and deletion

Audit events record who changed what, where, why, and from which revision to which revision, with correlation IDs. Keep audit metadata separate from raw evaluation context and secret values. The application should treat audit entries as append-only; privileged database modification is a separate threat requiring restricted access and, if selected, stronger tamper-evidence controls.

Define retention for snapshots, audit logs, telemetry, exports, backups, deleted organizations, and integration payloads before release (**D09/D12**). Deletion must account for cached snapshots and backup expiry; do not claim that revoking a key erases copies already delivered to customer processes.

Security verification belongs in the release gates, including cross-tenant tests, publication races, stale approval rejection, revoked token retries, secret redaction, browser bundle inspection, and webhook egress attacks.
