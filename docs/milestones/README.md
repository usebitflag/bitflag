# BitFlag milestone plans

Status: draft, 6 October 2026. This is the index to the milestone plans for the BitFlag core. Every plan follows [00-conventions.md](00-conventions.md) and expands one milestone of [implementation-plan.md](../implementation-plan.md). The critical path is M0 → M1 → M2 → M3 → M4 → M5. After M5, M6 to M9 and M13 can run in parallel. M10 comes before M11, and M12 closes the release after everything else, including M13.

## Milestones

| Milestone | File | Scope | Depends on | Blocking decisions (default assumed) |
| --- | --- | --- | --- | --- |
| M0 Foundations | [M0-foundations.md](M0-foundations.md) | Repos (including the `bitflag-js` skeleton), CI, tenancy spine tables, actors, audit, outbox with durable receipt recovery, portal shell and URL scheme, UI baseline | None | D01 (latest Laravel, MySQL 8.4, Node 22); D14 (conventions error envelope, 24 h idempotency); D08 (Sail only) |
| M1 Protocol and evaluator | [M1-protocol-and-evaluator.md](M1-protocol-and-evaluator.md) | `bitflag-protocol` schemas and fixtures, pure PHP evaluator, independent TypeScript evaluator, cross-language conformance, canonical JSON | M0 (CI) | D03 (bucketing decided in R15; the rest released as `0.x-alpha` until both evaluators pass); D06 (public projection drops overrides and lists); D13 (private repos) |
| M2 Accounts and access | [M2-accounts-and-access.md](M2-accounts-and-access.md) | Sign-up, sign-in, two-factor, sessions, organizations, members, invitations, onboarding | M0 | D02 (last-Owner rules); D08 (null GeoIP, Mailpit); D10 (no trial); D09 (security log 90 days) |
| M3 Projects, environments, credentials | [M3-projects-environments-credentials.md](M3-projects-environments-credentials.md) | Project settings, environments and protection policy, all key and token types, management API auth | M0, M2 | D14 (key prefixes, scopes, codes); D12 (7-day grace, no environment unarchive, no transfer); D02 (0 or 1 approvals, no self-approval); D04 (no max age) |
| M4 Flags, targeting, simulation | [M4-flags-targeting-simulation.md](M4-flags-targeting-simulation.md) | Flags, variations, environment drafts, rules, segments, overrides, prerequisites, simulator, snapshot compiler | M1, M3 | D03 (operator semantics, limits, bucketing); D09 (simulator stores nothing); D12 (staged archive with `archiving`) |
| M5 Publishing and delivery | [M5-publishing-and-delivery.md](M5-publishing-and-delivery.md) | Change sets, review, publication, rollback, emergency disable, audit page, SDK snapshot endpoint, PHP client | M1, M3, M4 | D02 (168 h approval expiry, no self-approval); D05 (direct rollback, no-review emergency disable); D04 (polling only); D06 |
| M6 Billing and plans | [M6-billing-and-plans.md](M6-billing-and-plans.md) | Stripe billing, entitlements, usage and limits, plan contracts, downgrade and read-only restrictions | M2, M3, M5 | D10 (no price, no trial, 14-day grace, automatic downgrade selection); D09 (hide, never delete, old audit); D12 |
| M7 Admin console | [M7-admin-console.md](M7-admin-console.md) | Filament staff console, lookups, two-person support actions, suspension | M2, M5, M6 | D08 (separate admin host); D09 (request error log 30 days); D12 (staff cannot delete); D10 (credit caps) |
| M8 Email and notifications | [M8-email-and-notifications.md](M8-email-and-notifications.md) | Email catalog and streams, in-app inbox, preferences, digest | M2, M5 | D09 (90-day logs); D02 (no review mail to contributors); D12 (no hard-coded deletion days) |
| M9 Webhooks and integrations | [M9-webhooks-and-integrations.md](M9-webhooks-and-integrations.md) | Signed webhooks, Slack, GitHub, Datadog, Jira, Vercel, Sentry, MCP policy | M3, M5, M6 | D11 (per-provider app setup, MCP via `mcp` audience tokens); D03 (webhook envelope); D08 (egress proxy); D10 (keep oldest webhook) |
| M10 Telemetry and analytics | [M10-telemetry-and-analytics.md](M10-telemetry-and-analytics.md) | Events ingestion, evaluation analytics, SDK health, delivery rollups, stale flags | M5 | D08 (MySQL telemetry schema behind a storage seam); D09 (hashed subjects, no unique users, retention table); D03 (event schema) |
| M11 Experiments | [M11-experiments.md](M11-experiments.md) | Experiment lifecycle, assignment rules, metrics, statistics, results | M4, M5, M10 | D07 (`normal-approx-v1`, fixed horizon, verdicts off in production until review); D09 (raw events 90 days after stop) |
| M13 SDKs, tools, delivery extensions and sites | [M13-sdks-tools-and-sites.md](M13-sdks-tools-and-sites.md) (plan stub) | Streaming, remote evaluation, full PHP and JS SDKs, Laravel/Vue/React adapters, CLI, MCP, website, docs | M1, M5, M10 per part | D04, D06, D11 (blocks MCP), D13, D14, D01 |
| M12 Release acceptance | [M12-release-acceptance.md](M12-release-acceptance.md) | Load, security, accessibility, restore drill with recovery journal, release gate | All, including M13 | D08 (targets, hosting, RPO and RTO); D07 (statistical sign-off); D03 (frozen protocol, `source: recovery`); D11 (provider apps) |

## Cross-milestone contracts

| Contract | Owner | Consumers |
| --- | --- | --- |
| Shared tenancy spine (`users`, `organizations`, `projects`, `environments`, memberships); later milestones add columns only | M0 | M2, M3 |
| Portal URL scheme `/{organization}/{project}/{environment}/…`, `ResolvePortalScope`, `scope` shared prop | M0 | M2 to M11 portal pages |
| Sidebar org/project switcher and environment picker (`rev N`) | M0 | M2 (switcher data), M5 (revisions, Changes count), M12 (mobile rule) |
| Outbox, `outbox_receipts`, `OutboxConsumer` and `ConsumerRegistry`; message `scope` (`organization`, `account`, `system`); durability contract (receipts are the work list, jobs are signals, `outbox:recover`) | M0 | M2 (account-scoped mail), M3, M5, M6, M8, M9, M10, M11 |
| Credential cache invalidation: fill with `SET NX`, change by overwrite, `internal.credential.state_changed` repair; revocation bound 60 s | M3 | M5, M13 (stream close on revocation) |
| Recovery journal (security and privacy changes outside the restorable database) and `ops:replay-recovery-journal` | M12 | M2, M3, M7, M10 (producers) |
| Bulk evaluation contract (`evaluateAll` returns `BulkResult`, no caller fallback) | M1 | M4 simulator, M13 bootstrap |
| Error envelope (`VERSION_CONFLICT`, `request_id`) | Conventions, M0 | All |
| Prerequisite depth: authoring limit 5 / evaluator ceiling 10 | M4 / M1 | M5 (validation) |
| Two-factor in `mfa_factors` (no Fortify `two_factor_*` columns) | M2 | M7 (`ResetMfaFactors`) |
| `EnforcePlanLimit::handle(org, LimitKey, ?project)`, `Entitlements` (`assertAllows`, 403 `FEATURE_NOT_IN_PLAN`), `PlanRestrictionGate`, `BillingStatus` in `app/Shared/Plans` | M6 (null bindings before) | M2, M3, M4, M5, M9, M11 |
| `internal.entitlements.changed` | M6 | M9 (`ApplyPlanToIntegrations`), M8 |
| Project flag defaults as `projects` columns, `UpdateProjectSettings` | M3 | M4 (enforces), M10 (`stale_after_days`) |
| Protection policy: `environment_policies`, `UpdateEnvironmentSettings`, `EnvironmentPolicyUpdated`, `PolicyEnvironmentProtection` | M3 | M5 |
| `DefaultApprovalPolicy` (null `approval_ttl_hours` means 168 h) | M5 | M3, M8 |
| `RecordFirstPublication`, `LockProjectKey` | M3 | M5 |
| `IssueCredential`, `RotateCredential`, `RevokeCredential`, `credentials.audience` (`api`, `mcp`), `internal.key.rotated` | M3 | M5, M9 (Vercel, `EnforceMcpPolicy`) |
| `SdkKeyAuthenticator` returning `ResolvedSdkCredential` | M3 | M5 (`AuthenticateSdkKey`), M10 |
| `SnapshotCompiler` (in `Publishing/Domain/Compiler`) | M4 | M5 |
| `environment_draft_states`, `EnvironmentDraftChanges`, `EnvironmentDraftChanged` | M4 | M5 |
| `RecordPublishedBaseline` (flag IDs, inlined segment version IDs) | M4 | M5 |
| Experiment rule actions (`PlaceExperimentRule`, `ReplaceExperimentRule`, `RemoveExperimentRule`, `ReleaseExperimentRule`), managed-rule lock, compiler output `experimentId`, `allocationId`, `remainderVariation` | M4 | M11 |
| `ExperimentDependencies` (blocks archive, variation removal, type change) | M11 (null binding in M4) | M4 |
| `ExperimentsAffectedByRevisionChange` rollback hook | M5 | M11 |
| `GET /sdk/v1/snapshot` | M5 | PHP client, M3 Delivery tab, M10 |
| `POST /sdk/v1/events` (same `evaluation_summary` shape; writes `sdk_credential_observations`) | M10 (supersedes M5's minimal handler) | SDKs, M5 onboarding |
| `publication_pointers.generation`, `bitflag:delivery:bump-generation` (new value `max(current + 1, unix_seconds(now))`) | M5 | M12 (adds `--all`, `--reason`, audit) |
| Email message style: `internal.email.requested{template}` for account and security mail; domain messages mapped by M8 for everything else | M8 | M2, M3, M6, M7, M9, M11 |
| `delivery_request_rollups` | M10 | M7 (delivery health) |

## Questions for Shei

Each line states the conflict, then the default the plans assume. Items marked (high impact) change data models, flows, permissions, security, privacy or scope (on 7 October 2026, items touching permissions, security or privacy were also marked): their defaults are **blocked**, not built, until the item is decided and recorded in the [decision register](../decision-register.md). Other defaults may be built and changed later. Documenting a conflict is not resolving it.

Resolved on 7 October 2026: management keys (R03 amended), protection versus "Only publishers can publish" (R14), bucketing inputs (R15), experiment verdict wording (R16).

Resolved on 8 October 2026: all sixteen high-impact questions (R17). Each is marked "Resolved (R17)" below with its original wording; the decision itself is in the register. Four differ from the plans' defaults: archived projects count toward the limit, every audit reader can export on Pro, review-request emails omit the reason, and the breached-password check uses the local list only. No question in this list is blocked any more; the remaining items carry ordinary defaults that may be built and changed later.

### Roles and permissions

- Billing Admin appears in Organization 1b, 2a and 3b. Settled by R07 (Org Owner and Member only): not built; the mockup copy needs fixing.
- ~~Management keys location~~ Resolved (R03 amended): per environment on the Keys tab as drawn, listed read-only under API tokens.
- Resolved (R17): Personal tokens are project-bound in the mockup, but the docs describe them as acting with the user's own permissions. Default in plan: one project per token.
- Resolved (R17): The token form offers Developers no publish level, though R01 lets Developers publish unprotected environments. Default in plan: Developers can create publish tokens; the role check still blocks protected environments.
- ~~Protection toggles~~ Resolved (R14): protection always means publishers only; the toggle is removed.
- The Overview mockup shows Priya (Developer) starting an experiment, but `experiments.manage` is Owner and Publisher only. Default in plan: Owners and Publishers only.
- Reviewer "Jon Reyes · Admin" and "only admins can change settings" name a role R01 removed. Default in plan: R01 roles; copy says "owners".
- Webhooks 2g shows Jon as a Developer, but the seed makes him a Publisher. Default in plan: seed unchanged; Cypress uses Priya.
- Resolved (R17): Audit says "Exporting needs the Owner role", which is not in the conventions. Default in plan: new `audit.export` capability for Owners, plus the Pro feature.
- New token scopes `webhooks:write` (M9) and `experiments:write` (M11) would grant capabilities the plans keep away from tokens. Default in plan: not issued until D14 decides; read scopes ship.

### Publishing and review

- Resolved (R17): Rollback: Changes 2e publishes a new revision directly, while portal/Changes loads the old revision into drafts for review. Default in plan: direct rollback (D05); drafts are kept and must be reviewed again.
- Resolved (R17): Required approvals are Pro-only (R08), yet every demo production environment needs one and Environments 2b promises "a second reviewer". Default in plan: on Free, protection means reason and publishers only, no approvals.
- The flag page reads as a per-flag publish ("Publish rev 43"), but the docs publish whole environments. Default in plan: every entry point shows the whole environment change set.
- BitFlag 2b combines "Approve & publish"; the portal separates approve and publish. Default in plan: both supported.
- Emergency disable appears only on Pricing and in downgrade copy; no screen is drawn. Default in plan: menu item and dialog on the flag's environment card.
- portal/Audit lists "Read flags list" by a token. Default in plan: only writes are audited.
- Three onboarding checklists disagree (BitFlag Overview 1b, portal/Overview, accounts doc). Default in plan: portal/Overview's four steps, with key creation inside Connect.
- "38 streams are connected" assumes streaming (D04). Default in plan: polling, with "reported in the last 5 minutes" wording.
- The portal prototype applies overrides immediately. Default in plan: overrides are draft content and go live on publish.
- The portal prototype defines segments per environment; the domain model has project-wide versions. Default in plan: project versions pinned per environment.

### Plans, limits and billing copy

- Resolved (R17): Archived projects: the plan doesn't count them toward the Free project limit and keeps them serving, so a Free org can run several projects by archiving. Default in plan: archived projects don't count; the alternative is to count them or stop serving them.
- Flag limit scope: billing doc counts per project, Org 2d and 2e show an organization total. Default in plan: per project.
- Audit window copy says "kept for 1 year", but R08 says Free 7 days and Pro 90 days. Default in plan: R08.
- The over-limit read-only state reuses `PLAN_LIMIT` with `details.restriction`. Default in plan: no new `PLAN_READ_ONLY` code.
- Onboarding lets unverified people reach Stripe Checkout. Default in plan: allowed.
- "Yearly · 2 months free" is a commercial promise not in R08 or D10. Default in plan: yearly price unset.
- Onboarding 5d says "Pro trial: not decided yet". Default in plan: no trial; Free is the trial.
- No reminder email before the payment grace period ends. Default in plan: none beyond the first-failure and plan-changed emails.
- Billing email (Org 1a) is in no doc. Default in plan: `organizations.billing_email`, which also receives billing mail.
- Deletion grace: Settings says 7 days, Emails 3c shows about 30. Default in plan: 7 days, Owners only.
- Which projects stay editable after a downgrade is undecided. Default in plan: automatic (oldest project and environments).

### Email

- 3a and 3b are sent from `billing@`, which is not one of the doc's two streams. Default in plan: a third, isolated stream.
- The invitation footer points to notification settings the invitee doesn't have. Default in plan: invitation-specific footer.
- Project deletion mail is "can't be turned off" yet sent from `notifications@`, and the recipients differ from the doc. Default in plan: mandatory, to Org Owners and project Owners.
- Preferences: the doc says per organization, mockup footers say per project. Default in plan: organization defaults with per-project overrides.
- Resolved (R17): Review request emails include the requester's free-text reason, which may contain targeting values. Default in plan: shown as drawn.
- Several emails and the inbox and preferences screens have no mockup. Default in plan: built on the drawn layouts; design pass needed.
- Webhooks 2a says "we email the owner" after auto-pause, but R10 makes it in-app. Default in plan: in-app only.

### Telemetry and privacy

- Resolved (R17): The implementation plan says M10 cannot start before D08 and D09. Default in plan: build on stated defaults behind a storage seam.
- The Pricing FAQ says context is "dropped", but experiments keep pseudonymous subject hashes. Default in plan: copy must change.
- "Unique users" and per-rule user counts need subject-level data. Default in plan: shown as "Not collected".
- Segment size estimates need retained contexts, which conflicts with "used and discarded". Default in plan: no Size column.
- Resolved (R17): Saved test contexts are implied by Changes 2a but not in the domain model. Default in plan: explicit, named `test_contexts`, visible only with `sensitive.read`.
- The analytics chart note contradicts frontend doc §6. Default in plan: copy changed to "A publish."
- Ingestion accepts events up to 7 days late. Default in plan: 7 days, which may be short for long experiment attribution.
- Resolved (R17): A Viewer can learn from the simulator whether a typed key has an override. Default in plan: accepted.

### Experiments

- Resolved (R17): The implementation plan says M11 cannot start before D07. Default in plan: build the pipeline with a pluggable default method and keep verdicts off in production until statistical sign-off.
- `starting` status ("waiting for review and publish") is not in the domain model. Default in plan: add it.
- Stop wording differs between canvas ("keep the split, stop collecting") and prototype ("remove the rule"). Default in plan: canvas.
- "Chance to beat control" (Bayesian) and "95% range" (interval) describe different methods. Default in plan: both, reconciled under a flat prior.
- Plan input: canvas uses days and total users, prototype uses users per version. Default in plan: users per version.
- "Experiments run in production" vs an environment picker in 2a. Default in plan: any environment, production preselected.
- Percentile guardrails (p75, p95) aren't supported by the default aggregations. Default in plan: not offered until D07.
- The Pause button still appears in several canvases, but R04 removed it. Default in plan: no pause.

### Mockup copy fixes

- Environments 2b and the demo: on Free, protection promises a reason and publishers only, never "a second reviewer" (R17 #2).
- Audit: remove "Exporting needs the Owner role" (R17 #10).
- Emails 2b: remove the reason from the review-request email (R17 #11).
- Organization 2d/2e and plan copy: archived projects count toward the project limit (R17 #3).

- Environments and Settings: remove the "Only publishers can publish" toggle; the protection description says only Owners and Publishers can publish (R14).
- Experiments: "No meaningful difference" becomes "No clear evidence of a difference", and the leading banner drops "or stop now and ship b" (R16).
- Revoke key dialog: "fail right away" becomes "fail with 401 within a minute, usually right away"; "until they restart" becomes "until they next reach BitFlag, including after a restart if they use a persistent cache" (M3).
- "Unallocated traffic gets the rule's fallback" should say "remainder variation".
- Flags 1c environment badges read the draft value instead of the live one; fixed in the build.
- Flags 2b shows 20% / 50%, which doesn't sum to 100%.
- Boolean variation keys differ (`disabled`/`enabled`, Off/On, `off`/`on`); the build uses `off`/`on`.
- The Delivery tab curl uses `/api/v1/sdk/snapshot`; the built path is `/sdk/v1/snapshot`.
- ~~api-and-integrations.md error example~~ Fixed: it now uses `request_id` and `VERSION_CONFLICT`.
- Auth 3b and 3c copy is written for self-hosting; the hosted meaning is "support deactivated the account".
- The segment key rule differs from the flag key rule; the build uses the flag key rule.
- Portal operators switch to version comparison when a value has a dot; the build follows the protocol's separate operators.
- Archive copy describes one step; the build uses the staged archive workflow.
- Prerequisites and the flag metadata form have no mockup; both need a design pass.
- "Slack: approve reviews" promises in-Slack approval before D11 allows it.

### New proposals needing a yes or no

- Resolved (R17): Portal URLs start with a global organization slug (`/acme/storefront/production/flags`). Default in plan: yes.
- Resolved (R17): Flag rules, condition groups and prerequisites are stored as one validated JSON document per draft with a `draft_references` index, not as relational rows. Default in plan: yes.
- Resolved (R18): New front-end dependencies Reka UI and Wayfinder, plus self-hosted fonts. Default in plan: yes. R18 also decides that the portal ships dark and light themes, with Inter for text and IBM Plex Mono for code, keys, IDs and monospace numbers.
- New tables `outbox_receipts`, `request_error_log`, `entitlement_changes` and `test_contexts`. Default in plan: yes.
- `archiving` flag status for the staged archive. Default in plan: yes.
- Provider connections per organization with project bindings. Default in plan: yes.
- Webhook delete removes the endpoint and its deliveries, keeping the audit entry. Default in plan: yes.
- `source: recovery` on `revision.published` after a restore (not in R02). Default in plan: yes, under D03.
- WCAG 2.2 AA as the accessibility gate. Default in plan: yes, added to D01.
- Resolved (R17): Unsuspending an organization needs only one staff member. Default in plan: yes.
- Resolved (R17): "Continue setup before verifying" lets someone tell whether an account exists. Default in plan: accept, with rate limits.
- Session timeouts: platform 7 days idle and 30 absolute, with org options of 1, 12 or 24 hours. Default in plan: yes.
- Resolved (R17): Password check against HIBP at sign-up, failing open. Default in plan: yes.
- Allowed email domains (Organization 1c only). Default in plan: built as drawn.
- Staff accounts managed by artisan commands, with no UI. Default in plan: yes.
- Webhook destinations limited to ports 443 and 8443. Default in plan: yes.
