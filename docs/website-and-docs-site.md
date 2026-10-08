# Website and documentation site

[Documentation index](README.md) · Status: proposed page map and content outline (R13); hosted-only messaging (A03, R06)

This document defines the pages of the marketing website (`bitflag-website`, Astro) and the customer documentation site (`bitflag-docs`, Astro/Starlight), what each page says, and the messaging rules both follow. Technical boundaries for both repositories are in [frontend and design](frontend-and-design.md) §1.

## 1. Messaging rules

- BitFlag is a **hosted** product. No self-hosted installer, Docker command, "open source" claim, or "host it yourself" choice appears anywhere (A03, R06). Source licensing is a separate decision (D13).
- No unmeasured numbers. The old Landing claims "<1ms", "5 min from install", "AES256 bank-level", and "100% open source" are removed. A performance or uptime number appears only after it is measured and approved (D08).
- Describe only behavior that exists. An integration is listed only when it meets its release behavior, not because a mockup card exists ([README](README.md) §Complete feature coverage).
- Plain product language that matches the portal: "draft", "publish", "revision", "rollback", "approval".
- Pricing on the site always matches R08 and the live entitlements.

## 2. Website page map

```text
bitflag.example/
├── /                      Home
├── /pricing               Plans and FAQ
├── /product/
│   ├── flags              Flags, remote config, targeting, rollouts
│   ├── publishing         Drafts, review, publish, rollback
│   ├── experiments        Experiments and guardrails
│   └── integrations       Slack, GitHub, Datadog, Jira, Vercel, Sentry, webhooks, MCP
├── /sdks                  JS, PHP, Laravel, Vue, React, CLI, MCP
├── /security              How data, keys, and access are handled
├── /changelog             Product changes
├── /docs  →               Documentation site
├── /signup, /signin   →   Portal
└── /legal/                Terms, Privacy, DPA, Subprocessors, Acceptable use
```

The domain is a placeholder until D13 confirms the name and domain.

### Home

| Section | Content |
| --- | --- |
| Hero | "Deploy code. Release behavior later." Subhead: hosted feature flags with drafts, review, and rollback. Primary: Start free. Secondary: Read the docs. The flag card visual stays |
| How it works band | Three plain facts instead of stats: "4 value types", "First match wins, in a fixed order", "Every change is a new revision". All true by design, no measurement needed |
| Why BitFlag | Same answer every time · Nothing goes live by accident · Works when our servers are unreachable · Does not store your users · One set of permissions · Nothing to run |
| SDKs | Tabs for JavaScript, PHP, Laravel, Vue, React with the real install and evaluate snippet; CLI and MCP mention |
| Publishing | Draft → Validate → Review → Publish → Roll back |
| Pricing teaser | Free and Pro summary with link to /pricing |
| Final call to action | Start free, no card required |

### Pricing

Free and Pro side by side with the R08 table, a "Never gated" note (rollback, key rotation, MFA, publishing), and an FAQ: what counts as a flag, what happens at a limit (nothing live changes), what happens on downgrade, how billing works (Stripe, card, monthly or yearly), and "Need SSO or invoicing? Tell us" capturing interest for the future Enterprise plan without promising it.

### Product pages

Each product page: one problem statement, three to five capabilities with portal screenshots from real or clearly labeled demo data, the relevant plan, and links to the matching docs guide. Experiments copy must not show invented lift or "winner" claims (D07).

### Security page

Hosted infrastructure summary (once D08 is chosen), data handling (context used for evaluation and not stored, aggregate analytics, what experiments retain), keys (shown once, stored hashed, rotation), access (roles, approvals, MFA, audit), support access (no impersonation, audited, R12), and a responsible disclosure contact. No certification claims until obtained.

## 3. Documentation site map

```text
docs/
├── Get started
│   ├── What is BitFlag
│   ├── Quick start: first flag to first evaluation
│   └── Core concepts (organizations, projects, environments, flags, variations, segments, revisions)
├── Flags and targeting
│   ├── Flag types and variations
│   ├── Rules, conditions, and operators
│   ├── Segments
│   ├── Overrides and prerequisites
│   ├── Percentage rollouts and bucketing
│   └── Simulator
├── Publishing
│   ├── Drafts, review, and publish
│   ├── Protected environments and approvals
│   ├── Rollback and revision history
│   └── How changes reach your SDKs
├── Experiments
│   ├── Setting up an experiment
│   ├── Exposures and conversions
│   └── Reading results (after D07)
├── SDKs
│   ├── JavaScript (browser and Node)
│   ├── PHP
│   ├── Laravel, Vue, React
│   ├── Evaluation context
│   ├── Caching, offline behavior, and fallbacks
│   └── Compatibility matrix
├── Tools
│   ├── CLI
│   └── MCP server for AI assistants
├── Integrations
│   ├── Webhooks and event reference
│   └── Slack, GitHub, Datadog, Jira, Vercel, Sentry
├── Administration
│   ├── Organizations, members, and roles
│   ├── Keys and API tokens
│   ├── Audit log
│   └── Plans and billing
├── API reference (generated from contracts/management/openapi.yaml)
├── Security and privacy
├── Troubleshooting
└── Changelog and migration notes
```

Rules: every code sample is run in CI against the released SDKs; API reference and webhook schemas are generated from their owning contracts, never hand-copied ([repository structure](repository-structure.md) §Contract ownership); pages state which plan a feature needs. The blueprint's self-host guides (install, deploy, backups and upgrades) are dropped.

## 4. Landing mockup

The redesigned hosted-only Landing mockup applies §1 and the Home outline in §2. It replaces the current `BitFlag Landing.dc.html` once approved; the old version moves to `archive/`.

## 5. Open points

| Topic | Where |
| --- | --- |
| Product name, domain, and trademark | D13 |
| Pro price shown on /pricing | D10 |
| Security page infrastructure details and any uptime figure | D08 |
| Experiment result language | D07 |
