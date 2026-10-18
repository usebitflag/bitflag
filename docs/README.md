# BitFlag technical documentation

BitFlag is a hosted feature flag management service. People define flags and targeting rules in a web portal, review and publish changes per environment, and applications read the published configuration through SDKs. This documentation describes how the system works: its boundaries, data model, evaluation semantics, publishing flow, APIs and operations.

## Reading guide

| Document | Covers |
| --- | --- |
| [Architecture](architecture.md) | System boundaries, modules, design patterns, dependency rules |
| [Conventions](conventions.md) | Stack, code layout, identifiers, actions, capabilities, errors |
| [Repository structure](repository-structure.md) | Repositories, application and module layout, SDK boundaries, contract ownership |
| [Domain model](domain-model.md) | Entities, tenant isolation, flag lifecycle, targeting, drafts, experiments |
| [Evaluation and SDK protocol](evaluation-and-sdk-protocol.md) | Evaluation order, bucketing, snapshots, caching, SDK behavior |
| [Publishing and security](publishing-and-security.md) | Review, atomic publication, rollback, tenant isolation, credentials |
| [API and integrations](api-and-integrations.md) | Management and delivery APIs, webhooks, integrations, CLI, MCP |
| [Frontend and design](frontend-and-design.md) | Portal composition, editor states, page behavior, accessibility |
| [Accounts and organizations](accounts-and-organizations.md) | Sign-up, sign-in and MFA, sessions, roles, invitations, organization settings |
| [Billing and plans](billing-and-plans.md) | Plans, limits, enforcement, Stripe, downgrade and failed payment |
| [Email and notifications](email-and-notifications.md) | Emails, in-app notifications, preferences, delivery |
| [Support and admin](support-and-admin.md) | Internal admin console, staff access, support actions, staff audit |
| [Operations](operations-and-release.md) | Deployment shape, reliability, telemetry pipeline, verification, runbooks |

## How the system fits together

- BitFlag is offered as a hosted service only.
- The application is a Laravel backend with an Inertia, Vue 3 and TypeScript portal in the same repository, organized as a modular monolith.
- State is held in MySQL. Redis provides caching, queues and rate limiting.
- Editing a flag changes a draft. Applications evaluate immutable, published environment snapshots, so nothing goes live by accident.
- Evaluation is a pure core with two independent implementations: PHP and JavaScript. Both pass the same conformance fixtures, which live in the protocol repository.
- Framework-neutral PHP and JavaScript SDKs, Laravel, Vue and React adapters, a CLI and an MCP server are built on the same contracts.

## Feature areas

| Area | What it does | Reference |
| --- | --- | --- |
| Authentication | Sign-up, verification, sign-in, password recovery, MFA, invitation acceptance, sessions | Accounts and organizations |
| Organizations and projects | Memberships, organization and project roles, project lifecycle, switching | Domain model; accounts and organizations |
| Environments and keys | Custom environments, protection, SDK keys, management tokens, rotation, revocation | Publishing and security |
| Flags and remote config | Boolean, String, Number and JSON flags; variations; metadata; lifecycle; typed fallbacks | Evaluation and SDK protocol |
| Targeting | Ordered rules, all/any groups, predicates, segments, overrides, prerequisites, weighted rollouts | Domain model |
| Simulation | One context format, draft or published comparison, decision trace, no retained context | Frontend and design |
| Changes | Exact diff, independent review, conflict handling, publication, propagation, rollback | Publishing and security |
| Experiments | Setup, exposure and conversion collection, attribution, guardrails, statistical reporting | Domain model; operations |
| Analytics and cleanup | Reporting coverage, distributions, fallbacks, SDK health, stale-flag guidance | Operations |
| Audit | Attributed, redacted, queryable history with bounded export | Publishing and security |
| Integrations | Signed webhooks; Slack, GitHub, Datadog, Jira, Vercel and Sentry | API and integrations |
| Billing and operations | Plans, entitlements, notifications, tenant isolation, monitoring, backup and restore, support tooling | Billing and plans; email and notifications; support and admin; operations |
