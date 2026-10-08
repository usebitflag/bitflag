# Implementation plan: BitFlag core

[Documentation index](README.md) · Status: draft for review, 6 October 2026

This plan covers building the **core**: the `bitflag` application (Laravel backend, Inertia portal, Filament admin console) with every feature the docs and mockups describe. It also covers the pieces the core cannot run without: the protocol fixtures (`bitflag-protocol`) and the two independent evaluation cores (`bitflag-php` and `bitflag-js`). Everything else in the release (SDK clients, adapters, CLI, MCP, website, docs, streaming and remote evaluation) is planned in M13. The work follows the dependency order in [operations and release](operations-and-release.md) §9. Milestones are engineering steps toward one complete release, not separate launches, and there are no time estimates.

## 1. Stack

| Area | Choice | Status |
| --- | --- | --- |
| Language and framework | Latest stable Laravel on the newest PHP version it supports; exact versions pinned when the repo is created | Chosen |
| Customer portal | Laravel + Inertia + Vue 3 + TypeScript, built with Vite | Chosen |
| Styling | Tailwind, with its theme mapped to the Nocturne design tokens (colors, type, radius, shadows) from `_ds/nocturne-*/styles.css` | Chosen |
| Admin console | Filament, inside the same app on its own host name and login guard; read-only resources with explicit columns, support actions written by hand | Chosen |
| Sign-in and two-factor | Laravel Fortify (headless), with our own Vue screens | Chosen |
| Billing | Laravel Cashier for Stripe | Chosen |
| Database and cache | MySQL (source of truth), Redis (cache, queues, rate limits) | Chosen in docs |
| Queues | Laravel queues on Redis, with Horizon for queue monitoring | Proposed |
| Local development | Laravel Sail (Docker): app, MySQL, Redis, Mailpit for local email | Chosen; Mailpit proposed |
| PHP quality | Pest (unit, feature, architecture tests), PHPStan with Larastan at a strict level, Pint | Chosen |
| Frontend quality | Vitest for components and composables, `vue-tsc` for type checks, ESLint and Prettier | Vitest chosen; others proposed |
| End-to-end tests | Cypress, one spec per mockup flow | Chosen |
| CI | GitHub Actions: lint, static analysis, Pest against real MySQL and Redis, Vitest, Cypress, protocol fixtures | Proposed |
| Not needed yet | Hosting (D08), streaming runtime (D04), analytics store (D08/D09), email provider | Deferred |

Two rules from the docs shape the stack. The evaluator never depends on Laravel ([architecture](architecture.md)). Database tests run on real MySQL, never SQLite only ([operations and release](operations-and-release.md) §7).

## 2. Repositories created for the core

| Repository | Why the core needs it |
| --- | --- |
| `bitflag-protocol` | Snapshot and result schemas, bucketing spec, and the known-answer fixtures that the evaluator and the portal simulator must pass |
| `bitflag-php` | The pure evaluator, used by the backend for simulation and publication checks, and later the PHP SDK |
| `bitflag-js` | The second, independently written evaluation core; the protocol is frozen only when both cores pass the same fixtures |
| `bitflag` | The application: backend, portal, admin console, management API, SDK delivery, workers |

The Laravel/Vue/React adapters, CLI, MCP, website, docs site and the full SDK clients are planned in M13 (§6). They depend on contracts the core produces.

## 3. Milestones

Each milestone lists what it delivers, the mockups it implements, and what must be true before it counts as done. "Done" always includes passing Pest, PHPStan, Vitest and the Cypress specs for that milestone in CI.

### M0. Foundations

- Create the four repositories with CI, branch protection (applied once the organization is on GitHub Team; interim controls in M0 §3.8) and the quality tools above.
- Scaffold `bitflag`: Sail, Fortify, Inertia, Vue/TS, Tailwind with Nocturne tokens, Filament on its own host, Horizon, and the module folders from [repository structure](repository-structure.md).
- Pest architecture tests that enforce the boundaries: modules write only through their own actions, controllers hold no business rules, and nothing in the app imports evaluator internals.
- Portal shell: sidebar with the organization / project switcher and environment picker, top bar with breadcrumbs and search, empty states (Sidebar and Design System mockups).
- Tenant scoping base: `organization_id` on every tenant row, scoped query helpers, and a test that fails if any query skips the scope.
- Audit and outbox tables and the base action pattern (authorize → load → check version → transaction → audit + outbox).

**Done when:** a blank portal page renders in the Nocturne style behind sign-in, CI is green on all four repositories, and the architecture tests fail on a deliberate violation.

### M1. Protocol and evaluator

- `bitflag-protocol`: JSON Schemas for snapshot and result, the `bucket-sha256-v1` spec, and fixtures for every reason, operator, missing/null/wrong-type case, Unicode, bucket edges, prerequisites and expired overrides ([evaluation and SDK protocol](evaluation-and-sdk-protocol.md) §10).
- `bitflag-php` `src/Evaluation` and `src/Protocol`: snapshot parsing and validation, evaluation order, operators, bucketing, typed results. No network, no framework.
- `bitflag-js` `src/evaluation` and `src/protocol`: the same contract implemented independently in TypeScript, plus a cross-language job that runs every fixture through both cores.
- A fixture runner in CI that pins one fixture release by checksum.

**Needs decisions:** D03 (missing-attribute rules, bucketing inputs, checksum format). Until then, build against the proposals in the protocol doc and publish as `0.x` prereleases only.

**Done when:** every fixture passes in both cores with no cross-language difference, and neither evaluator has a runtime dependency outside its language's standard library.

### M2. Accounts, organizations and access

- Sign-up, email verification, sign-in, two-factor with recovery codes, password reset, lockout, sessions list and revocation (Fortify + our screens).
- Hosted onboarding: organization → plan → first project → invite team → "ready" checklist.
- Organizations, org roles, invitations with expiry, project memberships with the four project roles (R01), capability-based policies checked per environment.
- Organization settings: General, Members, Security.

**Mockups:** Auth 5a–5g and the sign-in, recovery and invitation states; portal Auth; Organization 1a–1c; Settings → Members.

**Mockup conflicts already settled by the register:** the Billing Admin role is not built (R07), there is no GitHub/Google sign-up (R11), and the self-hosted Auth screens are replaced (R06). The hosted Auth and Organization settings screens still need a design pass before M2 acceptance.

**Done when:** Cypress covers sign-up to first project, sign-in with two-factor, password reset, and accepting and expiring an invitation; tenant isolation tests pass for every new table.

### M3. Projects, environments and credentials

- Projects with lifecycle states, environments from a template, ordering, protection policy (versioned), browser origins.
- SDK keys (`bf_srv_`, `bf_pub_`), project management tokens (`bf_mgt_`), personal access tokens (`bf_pat_`): one-time reveal, hashed storage, scopes, rotation with overlap, revocation, last used.

**Mockups:** Environments; Settings (project, environments, keys, tokens).

**Needs decisions:** D14 for final prefixes and scope names (use the documented proposals meanwhile).

**Done when:** keys are never stored or logged in plaintext (test asserts it), rotation overlap works, and revoked keys are rejected.

### M4. Flags, targeting and simulation

- Flags with typed variations, lifecycle, client visibility, project defaults.
- Environment drafts with `draft_version` optimistic concurrency, rules and condition groups, overrides with expiry, prerequisites with cycle checks, segments with immutable versions and include/exclude lists.
- The draft editor's four states (local, saved draft, review candidate, published) as in [frontend and design](frontend-and-design.md) §3.
- Simulator: compiles the draft or published configuration into a snapshot and evaluates it with `bitflag-php`, showing value, reason and trace.

**Mockups:** Flags (list, detail, editor, simulator); Segments.

**Done when:** two people editing the same draft get a conflict instead of a silent overwrite, and the simulator agrees with the protocol fixtures.

### M5. Publishing and delivery

- Change sets, frozen review candidates with content hash, approvals (Owners and Publishers, R01), stale-approval invalidation, protected-environment rules.
- Publication transaction: compile server and public projections, validate, store immutable snapshots, move the revision pointer, write audit and outbox in the same transaction.
- Rollback and emergency disable as new revisions.
- SDK delivery endpoint: authenticated conditional `GET` with ETag and `304`, per-projection caching in Redis with MySQL as the fallback, generation/revision ordering.
- A minimal `bitflag-php` client (fetch, cache, evaluate) so onboarding can verify a real evaluation.
- Audit log with filters, and the project Overview.

**Mockups:** Changes, Audit, Overview, portal Overview onboarding checklist.

**Needs decisions:** D02 (self-approval, approval count, expiry), D05 (who can emergency-disable or roll back), D06 (public projection rules).

**Done when:** the first end-to-end Cypress spec passes. It creates a flag, publishes to development, fetches the snapshot with the PHP client, and sees the expected value. Concurrency tests on real MySQL also cover publication races.

### M6. Billing and plans

- Cashier subscription, Stripe Checkout and Customer Portal; entitlements switch only on verified Stripe webhooks.
- Plan limits from R08, counted from records (never SDK traffic), warnings at 90%, creation blocked at the limit, paid features shown with the reason.
- Failed payment with a 14-day grace period, downgrade review, and read-only projects over the limit. Published flags never change.

**Mockups:** Organization 2a–3f.

**Needs decisions:** D10 (Pro price, final limits, trials, tax details).

**Done when:** Stripe test-mode webhooks drive every state in the mockups, and a test proves billing state never alters a published snapshot.

### M7. Admin console

- Filament panel on a staff host with its own guard and MFA; Support and Billing support roles.
- Lookups and the support actions from [support and admin](support-and-admin.md) §3–4, including the two-person, 24-hour MFA reset.
- Staff audit log, and customer audit entries with channel `support`.

**Done when:** console routes reject customer sessions and vice versa, no console response contains rule contents, keys or subject data (tested), and two-person actions can't be completed by one staff member.

### M8. Email and notifications

- Every email in the Emails mockup, sent through the outbox, plus in-app notifications and per-project preferences.

**Mockups:** Emails 1a–3c.

**Needs decisions:** email provider (Mailpit locally until then).

### M9. Webhooks and integrations

- Webhooks with the R02 event catalog, signing, SSRF checks, retries and delivery history.
- Slack, GitHub (code references), Datadog, Jira, Vercel, Sentry connections with their failure states.

**Mockups:** Integrations.

**Needs decisions:** D11 (provider authentication and automation).

### M10. Telemetry and analytics

- Event ingestion endpoint with batching and deduplication, evaluation aggregates, SDK observations, cleanup evidence.

**Mockups:** Analytics; delivery health on Overview and Environments.

**Needs decisions:** D08 (analytics store and scale) and D09 (retention and subject linkage).

### M11. Experiments

- Experiment plans and versions, experiment rules placed through publishing, exposures and conversions, guardrails, quality warnings, stop-and-decide flow.

**Mockups:** Experiments.

**Needs decisions:** D07 (statistical method and stopping rule). The statistical spec and data design should start alongside M5, not after M10.

### M13. SDKs, tools, delivery extensions and sites

- Streaming invalidations and remote evaluation in `bitflag`; full PHP and JavaScript SDK clients; Laravel, Vue and React adapters; CLI; MCP server; website; documentation site.

**Needs decisions:** D04, D06, D11, D13, D14, D01. See [M13](milestones/M13-sdks-tools-and-sites.md); it is a plan stub, and each part gets a full plan before its work starts.

### M12. Release acceptance

- Accessibility and responsive checks, security and tenant-isolation review, load tests against agreed targets, a backup restore drill, monitoring and on-call ownership ([operations and release](operations-and-release.md) §8).

## 4. Order and parallel work

M0 → M1 → M2 → M3 → M4 → M5 is the critical path, because each step builds on the previous one. After M5, these can run in parallel: M6, M7, M8, M9 and M13. M10 must come before M11. M12 closes the release after everything else, including M13. Starting the D07 statistical spec early keeps M11 off the critical path; name the statistical reviewer before M10 starts.

## 5. Decisions that block milestones

| Decision | Blocks | Can build ahead on the proposal? |
| --- | --- | --- |
| D03 Protocol | M1, M4, M5 | Yes, as `0.x` prereleases |
| D14 API details | M3 | Yes |
| D02 Approvals, D05 Emergency permissions | M5 | Yes, behind policy classes |
| D06 Browser exposure | M5 (public projection) | Partly; server projection first |
| D10 Billing | M6 | Yes with placeholder price and limits |
| D11 Integrations | M9 | Webhooks yes; providers no |
| D08, D09 Data and hosting | M10, M12 | M10 yes, behind the storage seam (R17); retention confirmed before real customer data; M12 no |
| D07 Experiments | M11 | Pipeline yes with verdicts off in production until statistical sign-off (R17) |
| D04 Streaming, D06 Browser exposure, D11 CLI/MCP | M13 | Partly; G (MCP) is blocked by D11 |
| D01 Supported versions, D13 Naming and licensing | Release | Yes |

## 6. Beyond the core (M13)

The `bitflag-js` browser and Node clients, the full PHP SDK transport and cache, `bitflag-laravel`, `bitflag-vue`, `bitflag-react`, `bitflag-cli`, `bitflag-mcp`, `bitflag-website` (Landing and Pricing mockups), `bitflag-docs`, and streaming and remote evaluation in `bitflag`. All are in the release scope and have owners and done criteria in [M13](milestones/M13-sdks-tools-and-sites.md). A Node client running against a real server should land right after M5, so both languages are exercised early; this is internal validation, not an early release.

## 7. Testing approach

| Layer | Tool | Covers |
| --- | --- | --- |
| Evaluators | Pest in `bitflag-php`, Vitest in `bitflag-js`, the same protocol fixtures, and the cross-language job | Every evaluation rule, bucketing, malformed input; identical results in both languages |
| Domain and actions | Pest feature tests on real MySQL and Redis | Authorization, tenant isolation, optimistic concurrency, publication races, outbox |
| Boundaries | Pest architecture tests | Module and evaluator dependency rules |
| Portal components | Vitest | Editors, draft state, validation display |
| Workflows | Cypress | One spec per mockup flow, run against Sail in CI |
