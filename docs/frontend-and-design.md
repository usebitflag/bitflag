# Frontend architecture and design handoff

[Documentation index](README.md) · Status: implementation design based on the prototype, not a claim of production readiness

## 1. Separate applications, deliberate boundaries

| Repository | Responsibility | Not responsible for |
| --- | --- | --- |
| `bitflag-website` | Astro marketing pages, pricing presentation, product content, hosted signup links | Portal sessions, flag evaluation, billing authority |
| `bitflag-docs` | Astro/Starlight guides, API/SDK documentation, examples, compatibility notes | Owning protocol definitions independently of `bitflag-protocol` |
| `bitflag` | Laravel + Inertia + Vue/TypeScript portal, management actions, hosted services | Building the other repositories through a root workspace |

Astro pages should primarily render static content. Add hydrated islands only for useful interactive features, such as a pricing control or copyable interactive example. Portal-style global client state is unnecessary for the entire marketing site. [Astro islands documentation](https://docs.astro.build/en/concepts/islands/)

Use consistent design tokens across products, but do not introduce a monorepo to achieve that. Initially maintain an explicit token specification and reviewed copies in each repository. If drift becomes costly, a separately versioned token package can be evaluated later; it is not a prerequisite or an unrequested new repository.

Use hosted messaging consistently. Do not advertise a supported self-hosted installer, unselected license, unverified performance number, or integration behavior that exists only in a mockup. Source availability and a supported self-hosted product are separate choices.

## 2. Portal composition

Inertia connects Laravel route/controller responses to Vue pages. Laravel owns authentication, authorization, validation, and authoritative resource state. Vue owns presentation and interaction, including unsaved local editor state. Inertia does not remove the need for normal server validation or independently versioned SDK APIs. [Inertia documentation](https://inertiajs.com/docs/v3/getting-started)

Organize pages by product area and reusable UI by responsibility:

- Page components arrange layouts and page props; avoid embedding publication rules in them.
- Feature components own targeting editors, diffs, approval panels, experiment setup, and other domain-specific UI.
- UI primitives cover buttons, inputs, tables, dialogs, menus, tabs, toasts, and accessible feedback.
- Composables manage behavior such as environment selection, pagination, dirty-state tracking, and request status. They do not independently decide permissions or reproduce evaluation semantics.
- Typed page props and API schemas distinguish display models from writable commands. Generate types from owned contracts where practical; do not expose entire Eloquent models automatically.

The folder map is in [repository structure](repository-structure.md). Names are proposed, not already scaffolded.

## 3. Four editor states

Always distinguish:

1. **Local edits:** changed in the browser, not yet saved.
2. **Saved draft:** stored server-side with a version, not active in SDKs.
3. **Review candidate:** frozen content under review or approved, identified by hash and base revision.
4. **Published configuration:** immutable active revision, with separately reported delivery observations.

Use explicit labels and timestamps. A toast saying “Saved” must not imply “Live.” The environment enabled badge should reflect published state unless it is visibly labeled as a draft preview.

The prototype has a known example to correct during handoff: the flag environment badge can read the draft `on` value while the rollout summary reads the published configuration. The production view must derive every live indicator from the same published object.

On save conflicts, preserve local edits and show a compare/reload/reapply workflow. Do not silently overwrite another editor's changes. On environment or organization switching and navigation, warn about unsaved changes. Do not casually persist sensitive simulation context or configuration in browser storage to solve dirty-state recovery.

## 4. Page behavior contracts

| Area | Production behavior to implement |
| --- | --- |
| Overview | Organization/project/environment scope visible; activity and counts drawn from real read models; explain reporting lag and empty states |
| Onboarding | Create project/environment, issue appropriate key, install the chosen SDK, publish a flag, verify an actual evaluation; distinguish setup instructions from observed telemetry |
| Flag list | Published status, draft presence, type, owner/tags, environment scope, searchable filters, pagination, lifecycle evidence; never derive live status from unsaved edits |
| Flag detail | Typed variations, off/default values, targeting order, overrides, prerequisites, draft/published comparison, validation, history, and environment-specific protection |
| Segment editor | Explicit all/any groups, include/exclude semantics, preview context, references/dependencies, versioned changes and affected environments |
| Simulator | Choose draft or published revision, enter bounded context, show value/reason/trace, compare outcomes without creating exposure events; retain context only with explicit product behavior |
| Changes | All included changes and contributors, base revision, protected environment warnings, reviewer requirements, stale approvals, publication status, and rollback comparison |
| Environments / keys | Explicit target environment, typed credential purpose, one-time reveal, rotation overlap, revocation, last use, protected-operation policy |
| Experiments | Plan, population, variants, allocation, exposure instrumentation, metrics, guardrails, minimum evidence, quality warnings, results, decision and publication workflow |
| Analytics | Time range, event coverage, sampling/delay, evaluation/fallback distributions, SDK/version health, evidence-based cleanup suggestions |
| Integrations | Provider permissions, destination/resource mapping, connection state, tests, delivery failures, reconnection and removal |
| Audit | Actor/action/target/revision/reason, scope filters, redacted details, pagination and bounded export |
| Organization settings | Membership, invitations, roles, ownership, billing, sessions/security where appropriate, organization lifecycle safeguards ([accounts and organizations](accounts-and-organizations.md) §7, [billing and plans](billing-and-plans.md)) |

Each page needs initial loading, empty, populated, forbidden, validation-error, recoverable-failure, and stale/conflict states where applicable. Background refresh must not overwrite an actively edited form.

## 5. Targeting and publishing interaction details

Keep rule order visible because the first matching rule wins. Distinguish an off variation from caller fallback and from the default on variation. Validate total weights, types, missing references, prerequisite cycles, and expiry before allowing submission, then validate again on the server.

Show impact when changing salts, eligibility, multivariate weights, or precedence can move subjects between variants. Do not promise stable assignment merely because the percentage field changed monotonically.

The proposed publication unit is the environment. From a flag detail page, “Review changes” opens the complete environment change set, not an apparently flag-only confirmation that hides other changes. If selective publication is desired, design dependency closure and change-set selection explicitly before implementing it.

Use production confirmations that name the environment, summarize impact, and collect a reason where policy requires it. Success includes a revision link. Propagation views should report what was actually observed, with timestamps and reporting coverage; do not imply knowledge of every offline SDK instance.

## 6. Experiments and analytics honesty

Do not render prototype confidence percentages, lift, sample sizes, conversion counts, or “winner” labels from invented data in production. Insufficient evidence, invalid attribution, sample-ratio mismatch, and delayed data need designed states, not zeros that look authoritative.

Evaluation count is not exposure count. A flag's low observed usage is not proof it is unused. Cleanup recommendations combine observation window, reporting coverage, repository scan scope, ownership, dependencies, and known instrumentation gaps, then request human review.

An SDK refresh fetches configuration; it does not itself imply a jump in application evaluations. Keep delivery metrics separate from usage charts. Experiment decision actions must explain whether they merely record a conclusion or actually publish a new flag configuration.

## 7. Accessibility and responsive behavior

Design and test the layout at laptop widths, including 1280px, and at narrower widths without relying solely on static artboards. Set deliberate rules for sidebar collapse, sticky environment context, wrapping filter controls, table scrolling, dialog sizing, and stacked comparison panels.

Use semantic inputs and headings, visible keyboard focus, accessible names, keyboard-operable menus/dialogs, focus restoration, and non-color status cues. Targeting reordering needs a keyboard alternative to dragging. Charts require textual summaries or accessible data views. Announce asynchronous results without flooding assistive technology.

Document support expectations for browsers and mobile views before acceptance. Full desktop administration and narrower readable layouts must be explicit expectations, not accidental CSS behavior.

## 8. Prototype-to-production handoff

The mockups remain visual and workflow references; their `sessionStorage` data, role toggles, demo clocks, fake network success, and sample credentials are not implementation architecture.

Keep demo scenario controls and simulation annotations outside the actual product UI in a dedicated demo/test shell. Production authorization must never be driven by a client-side role switch. A demo build can use deterministic fixtures, clearly labeled, with a reset mechanism isolated from real tenants.

Capture a page/state inventory from the current artboards and portal before coding each area. Map every mockup control to its action, permission, validation, success, and failure behavior. Where mockups disagree, record a design decision rather than accidentally using whichever screen was coded first.

Visual acceptance includes desktop and laptop screenshots, keyboard review, empty/error states, long labels, large rule sets, and realistic tenant data. The full mockup scope stays in the launch gate; sequencing pages internally is not a reduced public release.
