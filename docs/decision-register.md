# Decision register

[Documentation index](README.md)

This register separates the product owner's constraints from proposed technical details. A recommendation in these documents is not automatically an approved product policy. Update this file and the affected specification when a decision is made.

## 1. Established constraints and working direction

| ID | Direction | Status / basis |
| --- | --- | --- |
| A01 | Complete agreed mockup is the release scope; no reduced MVP launch | Explicit owner constraint |
| A02 | Separate independently buildable repositories; no monorepo | Explicit owner constraint |
| A03 | Hosted-only current offering; do not ship/support customer installations in this release | Working direction from the discussion; source licensing is separate |
| A04 | Astro website and Astro/Starlight documentation in separate repositories | Working frontend recommendation |
| A05 | Laravel backend with Inertia + Vue/TypeScript portal in the `bitflag` repository | Working application recommendation; not dependency installation or version approval |
| A06 | Modular monolith; application actions, explicit domain rules, Eloquent persistence, focused read queries | Working design; no generic repository layer required |
| A07 | Immutable published snapshots, versioned drafts, frozen reviews, optimistic concurrency, transactional outbox | Proposed safety architecture to implement and verify |
| A08 | Independent `bitflag-protocol` package/repository for delivery semantics and cross-language fixtures; management OpenAPI owned by `bitflag` | Proposed ownership boundary |
| A09 | Backend uses framework-neutral PHP SDK evaluator directly; JS SDK has a conformant engine; framework adapters do not reimplement evaluation | Proposed reuse boundary |
| A10 | MySQL authoritative configuration store; Redis for selected cache/queue/rate-limit duties | Working baseline inherited from the original blueprint; validate operational fit |

## 2. Open decisions

### D01 — Supported versions and compatibility windows

Choose exact PHP, Laravel, Inertia, Vue, TypeScript, Node, Astro/Starlight, MySQL, and Redis versions; supported browser/runtime and React/Vue/Laravel adapter ranges; package tooling; dependency update and deprecation policy.

**Recommendation:** pick compatible supported releases at implementation time, pin them, and test a declared compatibility matrix. Official documentation links in this design are references, not a mandate to install those exact major versions. Resolve before repository scaffolding and public SDK support claims.

### D02 — Role and approval policy

The role set is resolved (R01) and service accounts are dropped (R03). Still open: custom grants if required by the final mockup, environment protections, contributor restrictions, required approval count, self-approval, expiry, changed-membership behavior, and ownership recovery. Organization roles are resolved (R07).

**Recommendation:** permissions by capability and environment, immutable reviewed content, no silent self-approval exception. Resolve before policy implementation and review UI acceptance. Do not infer all permission semantics from prototype role labels.

### D03 — Normative protocol and determinism

Freeze the hash/bucket algorithm, encoding, numeric range, JSON canonicalization, checksums, operators and type rules, missing/null semantics, dates/semantic versions, schema limits, prerequisite constraints, capability negotiation, projection rules, event/signature formats, and authenticated disaster-recovery generation behavior.

**Recommendation:** publish schemas, algorithm text, and exact known-answer fixtures before independent SDK implementations can diverge. The bucketing inputs and modulo-bias question are resolved (R15). The rest of D03 stays open, and the contract is frozen only when the PHP and JavaScript evaluators both pass the same fixture release. Finalize before shipping assignments, SDKs, or persistent snapshots that customers depend on.

### D04 — Delivery and streaming runtime

Choose polling intervals, SSE/stream hosting strategy, connection limits, reauthentication/revocation rules, reconnection behavior, delivery observability, and maximum offline age defaults/overrides.

**Recommendation:** conditional polling as a reliable recovery path plus streaming invalidations; immutable snapshots remain authoritative. Benchmark deployment/runtime options before promising propagation latency. Resolve before delivery infrastructure and SDK transport acceptance.

### D05 — Emergency disable and rollback permissions

Decide who may emergency-disable, whether approvals can be bypassed, required reason/escalation, dependency behavior, rollback approval, and experiment-related restrictions.

**Recommendation:** distinct audited operation built from published state so unrelated drafts cannot hitchhike. Configuration rollback is always a new revision. Resolve before production action controls and release runbooks. Rollback is direct, without review (R17 #1); who may perform it and emergency disable remain open here.

### D06 — Browser SDK exposure model

Choose public local snapshots versus trusted remote/result delivery defaults, public context fields, browser key capabilities, bootstrap/hydration behavior, authenticated targeting, and private prerequisite handling.

**Recommendation:** private targeting stays on trusted servers; browser-visible configuration must be deliberately public. Browser attributes cannot grant secure entitlements. Resolve before public projection contracts and Vue/React integration acceptance.

### D07 — Experiment methodology

Choose the statistical method and validated implementation, fixed-horizon/sequential stopping approach (experiments have no pause, R04), allocation changes, analysis unit, exposure definition, attribution windows, metric/guardrail definitions, multiple comparisons, sample-ratio checks, power/minimum evidence, and results terminology.

**Recommendation:** write the method specification and test with controlled datasets before implementing “winner” or significance UI. Obtain statistical review where expertise is needed. Resolve early in experiment/data design; unresolved methodology is not permission to omit experiments from launch.

### D08 — Hosting, scale, observability, and recovery

Choose provider/regions, data residency, traffic/workload budgets, telemetry storage, queue durability, stream capacity, service objectives, monitoring/alerting, backup encryption, RPO/RTO, recovery generation strategy, deployment orchestration, support/on-call ownership, and infrastructure budget.

**Recommendation:** managed services where appropriate, reproducible deploys, representative load tests, and an actual restore drill. Define numerical targets with business context before claiming them publicly. Resolve provisioning choices before infrastructure work and operational acceptance before launch.

### D09 — Privacy, data retention, and telemetry

Define approved context attributes, identity/linkage policy, consent requirements where applicable, event sampling, raw/aggregate retention, snapshot/audit retention, access/export rules, deletion handling, and diagnostic redaction.

**Recommendation:** minimize context collection and use explicit exposure events. Pseudonymous IDs remain sensitive and experiment attribution may require retained linkage. Resolve before collecting customer data; obtain appropriate legal/privacy review rather than treating this architecture as compliance advice.

### D10 — Billing and commercial limits

Plans, limits and provider are resolved (R08, R09); archived projects count toward the project limit (R17 #3). Still open: Pro price and final limits, trials, tax calculation and registration, payment failure, grace, downgrade enforcement, cancellation, and entitlement reconciliation.

**Recommendation:** clear centrally enforced limits with safe runtime behavior; do not unexpectedly flip published flags because a payment state changed. Resolve before checkout, entitlements, pricing claims, and billing acceptance.

### D11 — Provider integrations and automation authentication

Confirm the exact Slack/GitHub/Datadog/Jira/Vercel/Sentry behaviors, app registrations, scopes, provider-plan requirements, review/write actions, and inbound events. Choose CLI login/service credentials and MCP transport/authentication/client confirmation support.

**Recommendation:** least-privilege provider grants, explicit organization mapping, real delivery/error states, and identical server policies across portal/automation. Verify provider capabilities against current official APIs during implementation. Resolve each connector's contract before its UI promises are frozen; all agreed connectors stay in release scope.

### D12 — Resource lifecycle and deletion

Define archive vs delete semantics, removal across environments, restoration, historical rollback references, orphaned experiments/segments, organization transfer, delayed destruction, export windows, key/integration cleanup, and backup expiry.

**Recommendation:** staged, visible lifecycle changes; archive does not silently alter every published environment. Historical artifacts stay coherent under the retention policy. Resolve before archive/delete actions and operational retention jobs.

### D13 — Licensing, naming, and publication

Choose source visibility and license independently for service, SDKs, protocol, CLI/MCP, and docs; verify package names, domain names, trademarks, registries, release signing/provenance, and contribution/support policy.

**Recommendation:** do not reuse the old blueprint's open-source/self-host claims as settled decisions. Repository names in this design are proposed identifiers, not registered resources. Resolve before public repositories/package publication/marketing claims.

### D14 — Management API contract details

Freeze routes, request/response DTOs, error codes, pagination, concurrency conventions, idempotency coverage/retention, one-time secret responses, rate-limit headers, export jobs, and API compatibility/deprecation policy.

**Recommendation:** generate and test clients against the owned OpenAPI contract; use expected versions for mutations and scoped idempotency for retryable effects. Resolve before CLI/MCP and external automation contracts are released.

## 3. Resolved decisions

Decision owner for each entry below: Shei (product owner), recorded 6 October 2026.

### R01 — Project roles

**Decision:** four project roles, as in the mockups. Owner: everything, including members and deleting the project. Publisher: edit and publish anywhere, and approve reviews. Developer: edit drafts and publish to unprotected environments. Viewer: read only. There is no separate reviewer or admin role.

**Alternatives:** blueprint owner/admin/developer/viewer; D02's five roles including reviewer.

**Rationale:** matches the designed Settings screens; approval is a Publisher capability.

**Affects:** domain model, publishing and security, D02. Policies are still checked as capabilities per environment.

### R02 — Webhook event types

**Decision:** `revision.published`, `review.requested`, `review.decided`, `flag.changed`, `key.revoked` (from the Integrations mockup), plus `experiment.started` and `experiment.stopped`.

**Alternatives:** blueprint names (`flag.published`, `environment.published`, `credential.revoked`, …); mockup five only.

**Rationale:** keeps the designed UI, adds experiment events the release scope needs.

**Affects:** API and integrations §4, `bitflag-protocol` webhook schemas.

### R03 — Management tokens belong to the project

**Decision:** management tokens (`bf_mgt_`) are project-owned and scoped to one environment. A member creates them on that environment's Keys tab, as drawn in the Environments mockups; project Settings → API tokens lists them read-only under "Project management keys". The creator is recorded, and the token keeps working if that member leaves. There are no separate service accounts. Personal access tokens (`bf_pat_`) act with their user's own permissions.

**Amended 7 October 2026:** the original text placed creation in Settings → API tokens with project-wide scope. The mockups create environment-scoped keys, which is also least privilege (a CI key for development cannot touch production), so the mockups win.

**Alternatives:** service accounts owning tokens (would need new screens); both; project-wide tokens created under API tokens (the original R03 text).

**Rationale:** matches the designed screens, keeps one automation credential model, and limits each key to one environment.

**Affects:** domain model, architecture (Organizations and Credentials modules), README feature table, D02.

### R04 — No experiment pause

**Decision:** experiments go draft → running → stopped with a recorded decision. There is no pause or resume.

**Alternatives:** pause and resume, as in the blueprint.

**Rationale:** matches the mockups and avoids gaps in exposure data mid-test.

**Affects:** domain model, D07.

### R05 — Context field is `kind`

**Decision:** the evaluation context names the subject category with `kind` (for example `"kind": "user"`), not `type`.

**Alternatives:** `type`.

**Rationale:** `type` already means a flag's value type; the mockups and blueprint use `kind`; common in other flag tools.

**Affects:** evaluation and SDK protocol, SDK APIs, `bitflag-protocol` schemas.

### R06 — Self-hosted content in mockups is superseded

**Decision:** the Landing and Auth mockups' self-hosting content (Docker install, self-hosted sample instance, first-run setup wizard) is superseded by the hosted-only direction (A03). Landing was redesigned on 6 October 2026 (R13); Auth still needs redesigning with hosted signup.

**Alternatives:** update both mockups immediately.

**Rationale:** hosted signup and onboarding are not designed yet; redesign them together.

**Affects:** README source precedence, Landing and Auth mockups.

### R07 — Organization roles

**Decision:** two organization roles above project roles. Org Owner: billing, organization members, organization security and deleting the organization. Member: sees only the projects they are added to, with that project's role (R01).

**Alternatives:** Owner/Admin/Member; no organization roles.

**Rationale:** keeps billing and organization lifecycle with a few people while project work stays in project roles.

**Affects:** domain model, accounts and organizations, D02.

### R08 — Plans and limits

**Decision:** pricing is plans with limits, not per seat or usage. At launch there are two plans, Free and Pro; Enterprise is added once company SSO exists.

| | Free | Pro (limits are placeholders) |
| --- | --- | --- |
| Projects | 1 | 10 |
| Environments | 3 | 10 per project |
| Seats | Unlimited | Unlimited |
| Flags | 10 (archived flags don't count) | Unlimited |
| Audit history | 7 days | 90 days, with export |
| Webhooks | 1 | Unlimited |
| Integrations | Slack | All six |
| Required approvals, experiments, code references | — | Included |
| Segments, CLI, MCP, API tokens | Included | Included |

Never gated: rollback, key rotation and revocation, MFA. SDK requests have a generous ceiling on every plan that only exists to stop abuse; it is never billed. Reaching a limit only blocks creating new things; published flags keep serving.

**Alternatives:** per seat; usage-based (MAU or SDK requests); Pro plus Enterprise at launch.

**Rationale:** limits are counted from BitFlag's own records and enforced at creation time, so billing can never affect evaluation; usage pricing would require collecting subject keys. Competitor review on 6 October 2026 (LaunchDarkly, Flagsmith, ConfigCat, Unleash, GrowthBook) showed core flagging free and governance paid.

**Affects:** billing and plans, D10.

### R09 — Payment provider

**Decision:** Stripe. BitFlag is responsible for tax and invoices.

**Alternatives:** Paddle as merchant of record; decide later.

**Affects:** billing and plans, D10.

### R10 — Email and notifications

**Decision:** email for account messages (verification, invitations, password reset, security alerts) and review requests; in-app notifications for everything else; per-user preferences.

**Alternatives:** email for everything; account emails only.

**Affects:** email and notifications.

### R11 — Sign-in at launch

**Decision:** email and password with MFA. No social sign-in or company SSO at launch; SSO arrives with Enterprise.

**Alternatives:** adding Google/GitHub sign-in; adding SAML SSO.

**Affects:** accounts and organizations, publishing and security.

### R12 — Support tooling

**Decision:** an internal admin console for looking up organizations, plans and delivery health. No impersonation.

**Alternatives:** console with customer-approved impersonation; no tooling.

**Affects:** support and admin.

### R13 — Website and documentation site

**Decision:** write a hosted-only page map and content outline for the website and documentation site, and redesign the Landing mockup now.

**Alternatives:** outline only.

**Affects:** website and documentation site, Landing mockup, R06.

### R14 — Protection means publishers only

**Decision (7 October 2026, Shei):** R01 applies without exception. A protected environment can be published only by Owners and Publishers. There is no separate "Only publishers can publish" toggle; the mockup control is removed and its copy is folded into the protection description.

**Alternatives:** keep the mockup's toggle so a protected environment can still allow Developers to publish (an exception to R01).

**Affects:** M3 (`environment_policies` has no `publishers_only`), M5 protected-environment rules, Environments and Settings mockup copy.

### R15 — Bucketing inputs

**Decision (7 October 2026, Shei):** algorithm `bucket-sha256-v1`. The bucket input is `field("bitflag-bucket-v1") ‖ field(salt) ‖ field(kind) ‖ field(bucketByValue)`, each field a 4-byte big-endian byte length followed by the UTF-8 bytes. Take the first 8 digest bytes of SHA-256 as an unsigned big-endian integer, `mod 100000`. The remaining modulo bias (below 10⁻¹⁴) is accepted. Every allocation's salt is generated randomly and is unique; copying or duplicating a rule, flag or environment generates new salts unless the person explicitly chooses to keep the cohort.

**Alternatives:** the earlier protocol proposal hashing algorithm ID, project ID, environment ID, flag key, allocation ID, salt, kind and key with 4 digest bytes.

**Rationale:** fewer inputs to implement identically in PHP and JavaScript; cohorts survive project transfer and key renames; bias is negligible. Salt uniqueness becomes the property that keeps unrelated rollouts independent, so it is validated at publication.

**Affects:** evaluation and SDK protocol, M1, M4 (salt generation on copy), D03. JavaScript must use `BigInt` or equivalent 64-bit arithmetic.

### R16 — Experiment verdict wording

**Decision (7 October 2026, Shei):** an experiment that reaches its planned sample without a clear result is labelled "No clear evidence of a difference", never "No meaningful difference". The `leading` verdict does not suggest stopping now; early stopping stays available through Stop and choose with its existing warning. The statistical reviewer may refine wording under D07 but not toward claims the method cannot support.

**Alternatives:** keep the mockup copy.

**Rationale:** failing to detect a difference does not show there is none, and a fixed-horizon method loses its guarantees if the interface invites interim decisions.

**Affects:** M11 stopping policy and verdict enum, Experiments mockup copy, website experiment copy.

### R17 — Milestone plan questions

**Decision (8 October 2026, Shei):** the sixteen blocked questions from the milestone index (kept outside this repository) are decided as below. Rows marked † differ from the default the plans had assumed.

| # | Question | Decision | Affects |
| --- | --- | --- | --- |
| 1 | Rollback flow | Direct rollback: a new revision from the old content, without review; pending drafts are kept and marked stale. Who may roll back stays in D05 | M5, Changes and Flags mockup copy |
| 2 | Protection on Free | Protected means a change reason and publishers only; required approvals stay Pro-only (R08). Mockup copy promising "a second reviewer" on Free is fixed | M3, M5, M6, Environments copy |
| 3 † | Archived projects and the project limit | Archived projects keep serving and **count** toward the limit. Projects scheduled for deletion do not count (they stop at purge; restore checks the limit) | M3, M6, billing and plans |
| 4 | Rule storage | One validated JSON document per flag draft, with a `draft_references` index | M4, M5 |
| 5 | Telemetry before D08/D09 | Build now behind the storage seam; retention defaults must be confirmed under D09 before real customer data is collected | M10, implementation plan |
| 6 | Experiments before D07 | Build the pipeline with the pluggable default method; verdicts stay off in production until a named statistical reviewer signs off | M11, M12, implementation plan |
| 7 | Portal URLs | Organization slug first: `/{organization}/{project}/{environment}/…` | M0 and all portal pages |
| 8 | Personal token scope | One project per token | M3, M13 (CLI) |
| 9 | Developer tokens | Developers can create tokens that publish; the live role check still blocks protected environments | M3 |
| 10 † | Audit export | Every role with `audit.read` may export, on Pro only; "Exporting needs the Owner role" copy is removed | Conventions, M5, M6, Audit copy |
| 11 † | Review request email | The free-text reason is not in the email; it is shown in the portal behind permissions | M8, email and notifications |
| 12 | Saved test contexts | Added as explicit, named `test_contexts`, visible only with `sensitive.read` | Domain model, M4, M5 |
| 13 | Simulator override probing by Viewers | Accepted | M4 |
| 14 | Lifting a suspension | One staff member, audited with reason and ticket | M7 |
| 15 | "Continue setup before verifying" | Accepted with rate limits | M2 |
| 16 † | Breached-password check | Bundled local list only; no HIBP call | M2 |

**Rationale:** defaults kept where they matched the mockups and limited risk; changed where the default left a plan-limit loophole (3), was narrower than the team wanted (10), put user-written content into email (11), or added a third-party dependency to sign-up (16).

### R18 — Portal front-end choices

**Decision (9 October 2026, Shei):** the portal ships both a dark and a light theme, with a user-selectable switch. Inter is used for headings and body text everywhere (as in the Nocturne design system); IBM Plex Mono is used for code, keys, IDs and monospace numbers such as revisions and counts. Reka UI (headless components), Laravel Wayfinder (typed routes) and self-hosted fonts are accepted. The navigation shows only pages that exist. Nocturne's single-accent palette stays: no red or green semantic colors.

**Alternatives:** dark only until a theme preference is designed; system monospace; all nine nav items from M0 with placeholder pages; a semantic danger color.

**Rationale:** the Design System already defines both themes; the font roles keep prose consistent and reserve monospace for values people copy or compare; the dependencies avoid hand-building accessible primitives; placeholder pages would have to be removed later.

**Affects:** M0 (§3.5, §5, §6), the Design System mockup (add a theme switch), and every later milestone's UI, which must work in both themes. The choice is stored per browser until M2 adds an account setting.

### R19 — Branch protection deferred

**Decision (9 October 2026, Shei):** the `usebitflag` organization stays on the GitHub Free plan for now. Private repositories on that plan cannot use branch protection or rulesets, so protection is deferred. Every change still goes through a pull request, enforced by a `main-guard` workflow (detective), a `pre-push` hook (client side), squash-only merges and a `CLAUDE.md` rule. The repositories stay private (D13).

**Alternatives:** upgrade to GitHub Team now; make the repositories public (conflicts with D13).

**Rationale:** M0 is a solo scaffold, so the risk is low; the upgrade is a small cost that must happen before M1 or before anyone else gets write access.

**Affects:** M0 §3.8, §7 and §8; all four repositories.

## 4. Recording a decision

For each resolved item, record: date, decision owner, chosen behavior, alternatives considered, rationale, affected documents/repositories, migration/compatibility implications, and acceptance tests. Move the item to a resolved section without deleting its history.

Use small architecture decision records for consequential changes. Suggested template:

```text
Title / ID:
Status: Proposed | Accepted | Superseded
Decision owner and date:
Context and constraints:
Decision:
Alternatives and tradeoffs:
Affected contracts and migrations:
Verification / acceptance:
Supersedes / links:
```

Milestone plans may build on a stated default only when the default does not change permissions, security, privacy, protocol semantics, or release scope. A default that does any of those (marked "(high impact)" in the milestone index, kept outside this repository) is **blocked** until it is decided and recorded here; documenting a conflict is not resolving it. When a decision is made, update this register, the affected design documents, the milestone plans, and the mockup copy list in the same change.

Not every open decision blocks all engineering work. Build independent foundations while resolving the relevant dependencies, but do not invent permanent protocol, security, statistical, or commercial behavior silently. Completion of the complete product remains the release condition.
