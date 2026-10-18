# Frontend architecture and design

[Documentation index](README.md)

## 1. Separate applications, deliberate boundaries

| Repository | Responsibility | Not responsible for |
| --- | --- | --- |
| `bitflag-website` | Astro marketing pages, pricing presentation, product content, hosted signup links | Portal sessions, flag evaluation, billing authority |
| `bitflag-docs` | Astro/Starlight guides, API/SDK documentation, examples, compatibility notes | Owning protocol definitions independently of `bitflag-protocol` |
| `bitflag` | Laravel + Inertia + Vue/TypeScript portal, management actions, hosted services | Building the other repositories through a root workspace |

Astro pages should primarily render static content. Add hydrated islands only for useful interactive features, such as a pricing control or copyable interactive example. Portal-style global client state is unnecessary for the entire marketing site. [Astro islands documentation](https://docs.astro.build/en/concepts/islands/)

Design tokens are consistent across products without a monorepo: each repository keeps a reviewed copy of the token specification. The portal generates its Tailwind theme from the Nocturne tokens.



## 2. Portal composition

Inertia connects Laravel route/controller responses to Vue pages. Laravel owns authentication, authorization, validation, and authoritative resource state. Vue owns presentation and interaction, including unsaved local editor state. Inertia does not remove the need for normal server validation or independently versioned SDK APIs. [Inertia documentation](https://inertiajs.com/docs/v3/getting-started)

Organize pages by product area and reusable UI by responsibility:

- Page components arrange layouts and page props; avoid embedding publication rules in them.
- Feature components own targeting editors, diffs, approval panels, experiment setup, and other domain-specific UI.
- UI primitives cover buttons, inputs, tables, dialogs, menus, tabs, toasts, and accessible feedback.
- Composables manage behavior such as environment selection, pagination, dirty-state tracking, and request status. They do not independently decide permissions or reproduce evaluation semantics.
- Typed page props and API schemas distinguish display models from writable commands. Generate types from owned contracts where practical; do not expose entire Eloquent models automatically.

The folder map is in [repository structure](repository-structure.md).

## 3. Four editor states

Always distinguish:

1. **Local edits:** changed in the browser, not yet saved.
2. **Saved draft:** stored server-side with a version, not active in SDKs.
3. **Review candidate:** frozen content under review or approved, identified by hash and base revision.
4. **Published configuration:** immutable active revision, with separately reported delivery observations.

Use explicit labels and timestamps. A toast saying “Saved” must not imply “Live.” The environment enabled badge should reflect published state unless it is visibly labeled as a draft preview.

Every live indicator derives from the same published object, so an environment badge and a rollout summary can never disagree.

On save conflicts, preserve local edits and show a compare/reload/reapply workflow. Do not silently overwrite another editor's changes. On environment or organization switching and navigation, warn about unsaved changes. Do not casually persist sensitive simulation context or configuration in browser storage to solve dirty-state recovery.

## 4. Page behavior contracts

| Area | Behavior |
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

The publication unit is the environment. From a flag detail page, “Review changes” opens the complete environment change set, never an apparently flag-only confirmation that hides other changes.

Use production confirmations that name the environment, summarize impact, and collect a reason where policy requires it. Success includes a revision link. Propagation views should report what was actually observed, with timestamps and reporting coverage; do not imply knowledge of every offline SDK instance.

## 6. Experiments and analytics honesty

Confidence percentages, lift, sample sizes, conversion counts and “winner” labels come only from collected data. Insufficient evidence, invalid attribution, sample-ratio mismatch and delayed data have their own states, not zeros that look authoritative.

Evaluation count is not exposure count. A flag's low observed usage is not proof it is unused. Cleanup recommendations combine observation window, reporting coverage, repository scan scope, ownership, dependencies, and known instrumentation gaps, then request human review.

An SDK refresh fetches configuration; it does not itself imply a jump in application evaluations. Keep delivery metrics separate from usage charts. Experiment decision actions must explain whether they merely record a conclusion or actually publish a new flag configuration.

## 7. Accessibility and responsive behavior

The layout is designed and tested at laptop widths, including 1280px, and at narrower widths. Rules cover sidebar collapse, sticky environment context, wrapping filter controls, table scrolling, dialog sizing and stacked comparison panels.

Use semantic inputs and headings, visible keyboard focus, accessible names, keyboard-operable menus/dialogs, focus restoration, and non-color status cues. Targeting reordering needs a keyboard alternative to dragging. Charts require textual summaries or accessible data views. Announce asynchronous results without flooding assistive technology.

Full desktop administration and narrower readable layouts are explicit expectations, not accidental CSS behavior.

## 8. Themes and typography

The portal ships a dark and a light theme. Dark is the default; a switch in the user menu offers Dark, Light and System, and the choice is applied before first paint so the page never flashes the wrong theme. Inter is used for headings and body text. IBM Plex Mono is used for code, keys, IDs and monospace numbers such as revisions and counts; other numbers use tabular figures in Inter. Fonts are served from the application. Both themes meet WCAG AA contrast for text.

## 9. Demo and test controls

Demo scenario controls, role switching and simulated clocks never appear in the product UI. Authorization is never driven by a client-side role switch. Test-only routes (reset, login, clock, forced failure) exist only in local and CI environments behind a token, and a production-boot test asserts they are absent. A deterministic demo seed provides fixtures for tests and local development.
