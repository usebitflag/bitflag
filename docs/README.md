# BitFlag technical documentation

**Updated:** 6 October 2026  
**Status:** Design baseline for implementation; the application is not implemented by these documents.  
**Audience:** Product owner, backend and frontend engineers, SDK authors, and operators.

BitFlag is planned as a hosted feature flag management SaaS. This documentation translates the current mockups and architecture discussion into implementation boundaries, workflows, contracts, and verification requirements. The complete agreed mockup is the release scope. Internal development milestones do not authorize a smaller product release.

## Reading guide

| Document | Covers |
| --- | --- |
| [Architecture](architecture.md) | System boundaries, modules, design patterns, and dependency rules |
| [Repository structure](repository-structure.md) | Separate repositories, Laravel and Vue folders, SDK ownership, and compatibility |
| [Domain model](domain-model.md) | Entities, tenant isolation, lifecycle, targeting, and experiment data |
| [Evaluation and SDK protocol](evaluation-and-sdk-protocol.md) | Evaluation semantics, snapshots, bucketing, caching, and SDK behavior |
| [Publishing and security](publishing-and-security.md) | Review, atomic publication, rollback, outbox delivery, permissions, and credentials |
| [API and integrations](api-and-integrations.md) | Management and delivery interfaces, CLI, MCP, webhooks, and connected apps |
| [Frontend and design](frontend-and-design.md) | Astro, Inertia/Vue, editor state, page contracts, and prototype handoff |
| [Accounts and organizations](accounts-and-organizations.md) | Hosted signup, sign-in and MFA, recovery, sessions, organization roles, invitations |
| [Billing and plans](billing-and-plans.md) | Free and Pro plans, limits and enforcement, Stripe, downgrade and failed payment |
| [Email and notifications](email-and-notifications.md) | Every email, in-app notifications, preferences, delivery |
| [Support and admin](support-and-admin.md) | Internal admin console, staff access, support actions, staff audit |
| [Website and documentation site](website-and-docs-site.md) | Page maps, content outline, and messaging rules |
| [Operations and release](operations-and-release.md) | Hosted infrastructure, analytics, deployment, tests, and complete-product release gates |
| [Decision register](decision-register.md) | Current direction, proposed defaults, unresolved choices, and affected implementation work |

## Current direction

- Hosted-only offering for the current release. Customer-operated installations and their support lifecycle are outside this deployment offering.
- Separate repositories. There is no monorepo and no requirement for sibling checkouts to build an application.
- Astro marketing website and Astro/Starlight customer documentation, independently deployed.
- Laravel backend and an Inertia/Vue/TypeScript portal in the same application repository.
- A modular monolith organized by business capability, with application actions, explicit domain rules, immutable snapshots, and a transactional outbox.
- MySQL and Redis are the working infrastructure baseline inherited from the blueprint, pending final operational confirmation.
- Framework-neutral PHP and JavaScript SDKs, Laravel/Vue/React adapters, CLI, and MCP remain in scope.
- Release requires all agreed feature areas and their real behavior, including experiments, analytics, integrations, and hosted administration.

Framework choices are the working design being documented, not a claim that dependencies have been installed or benchmarked. Exact versions, limits, and unresolved product policies are tracked in the decision register. Proposed wire formats and table names require contract review before being frozen.

## Complete feature coverage

| Feature area | Required implementation outcome | Main design reference |
| --- | --- | --- |
| Public website | Product, pricing, SDK examples, documentation links, and hosted signup entry | Website and documentation site |
| Authentication | Signup, verification, login, password recovery, MFA/recovery flow, invitation acceptance, sessions | Accounts and organizations |
| Organizations and projects | Membership, organization and project roles, ownership, project API tokens, switching, project lifecycle | Domain model; accounts and organizations |
| Overview and onboarding | Accurate scoped summaries, activity, first flag through verified SDK evaluation | Frontend and design |
| Environments and keys | Custom environments, protection, delivery status, issuance, rotation, revocation | Publishing and security |
| Flags and remote config | Boolean, String, Number, JSON, variations, metadata, lifecycle, typed fallback behavior | Evaluation and SDK protocol |
| Targeting | Ordered rules, all/any groups, predicates, segments, overrides, prerequisites, weighted rollout | Domain model |
| Simulation | Consistent context format, draft/published comparison, decision trace, no implicit context retention | Frontend and design |
| Changes | Exact diff, independent review policy, conflict handling, publication, propagation, rollback | Publishing and security |
| Experiments | Setup, exposure/conversion collection, attribution, guardrails, validated statistical reporting, decision publication | Domain model; operations and release |
| Analytics and cleanup | Reporting coverage, distributions, fallbacks, SDK health, evidence-based lifecycle guidance | Operations and release |
| Audit | Attributed, redacted, queryable history and bounded export | Publishing and security |
| Developer ecosystem | PHP/JS SDKs, framework adapters, CLI, MCP, examples, compatibility documentation | Repository structure; API and integrations |
| Integrations | Signed webhooks plus Slack, GitHub, Datadog, Jira, Vercel, and Sentry behaviors represented in the mockups | API and integrations |
| SaaS operations | Billing, entitlements, notifications, tenant isolation, monitoring, backup and restore, customer support workflows | Billing and plans; email and notifications; support and admin; operations and release |

An integration card, chart, or SDK sample is not complete merely because it renders. Its required external behavior, error handling, permissions, and documentation must also be verified. Open decisions are work to resolve within the full scope; they are not implicit feature deferrals.

## Source precedence and existing mockups

The current discussion supersedes the older blueprint's self-host-first model. This documentation records that updated direction without rewriting the source mockups. The Landing mockup has been redesigned for the hosted product (R13; the self-hosted version is in `archive/`). The Auth mockup still shows a self-hosted sample instance and first-run setup wizard; that content is superseded by [accounts and organizations](accounts-and-organizations.md) and needs redesigning (R06). Email layouts are in `BitFlag Emails.dc.html`.

1. Explicit product decisions made by the owner take precedence.
2. This documentation describes the working technical design; locally labeled proposals remain proposals.
3. The current mockups define the feature surface and visual reference. Simulated values and controls are not production promises.
4. The [29 September blueprint](../Bitflag%20UI%20mockups/uploads/BitFlag-Product-and-Architecture-Blueprint.md) remains historical context where consistent with the updated direction.

The [portal prototype](../Bitflag%20UI%20mockups/BitFlag%20Portal%20Prototype.dc.html) and [design system](../Bitflag%20UI%20mockups/BitFlag%20Design%20System.dc.html) are preserved. Design-only role switching, fake SDK propagation, placeholder statistics, and demo credentials must not enter production behavior.

No repositories, cloud resources, API credentials, schemas, or application scaffolds are created by this documentation change.
