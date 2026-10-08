# Hosted operations, verification, and release

[Documentation index](README.md) · Status: operational design; capacity and service guarantees are not yet selected

## 1. Deployment shape

Deploy the marketing website and documentation independently from the hosted application. The working application topology is:

```text
Hosted app boundary
  HTTP application processes       Portal + management + authorized delivery
  Background workers              Publication delivery, integrations, ingestion, reports
  Scheduler                       Retention, reconciliation, scheduled lifecycle work
  Streaming runtime               Authenticated SDK invalidations; deployment choice open
  MySQL                           Authoritative configuration, publication, identities
  Redis                           Cache, queues/rate-limit state as selected
  Private object storage          Exports/backups and immutable artifacts if selected
  Telemetry storage/read models    Sized and selected through representative benchmarks
```

This is a process/responsibility map, not a requirement for one microservice or repository per line. Application and worker processes can deploy the same `bitflag` artifact with different commands. Stream infrastructure may be managed or separately provisioned without splitting business logic prematurely.

Prefer managed database/cache services and reproducible infrastructure for the hosted product where suitable. No cloud vendor, region, Kubernetes requirement, or numerical availability claim is approved yet (**D04/D08**). Hosted-only does not mean manual, unreproducible deployments; internal containers/infrastructure automation do not constitute a supported self-hosted offering.

## 2. Configuration, isolation, and secrets

Use separate production, staging, and development databases, caches, queues, storage, OAuth apps, and provider credentials. A staging integration test must not publish to a production customer environment. Use generated/sanitized fixtures rather than copies of raw production context.

Read secrets from a managed secret mechanism, not committed environment files. Limit database and storage access by service role. Encrypt traffic and stored secrets/backups, rotate access keys, and document recovery of encryption keys. Losing decryption material can make a nominally successful backup useless.

Long-running workers and application servers must reset tenant/request context. Authenticate streaming connections and define reauthentication, maximum lifetime, revocation, reconnect, and backpressure behavior. Deployment reloads must not lose committed publication intents.

## 3. Publication and runtime reliability

The database publication pointer and immutable snapshot are authoritative. Redis is an acceleration layer, not the only copy of published configuration. Delivery cache failure should have a bounded authenticated fallback path; validate that this fallback cannot overwhelm MySQL during an outage.

Separate workload priorities so telemetry bursts or a broken external webhook cannot starve publication delivery. Bound queues, retries, concurrent provider calls, stream fanout, and batch sizes. Monitor oldest pending outbox age and failed delivery attempts, not only worker uptime.

Measure distinct intervals: command latency, publication commit-to-delivery availability, SDK observation lag, stream reconnect time, and time since last successful cache validation. Observation metrics depend on reporting clients; offline clients are not silently counted as current.

When a disaster restore reintroduces older database state, SDKs may already hold higher revision numbers. The protocol needs an authenticated generation/epoch recovery mechanism or another explicitly verified strategy; silently allowing all lower revisions breaks ordinary race protection. This is part of **D03/D08**, and restore drills must include cached clients, not only database startup.

## 4. Telemetry and analytics pipeline

Proposed flow: validate and acknowledge ingestion according to its durability contract, persist/enqueue accepted events, deduplicate, enrich with versioned configuration references, aggregate, and expose reporting read models. Do not acknowledge reliable ingestion before the chosen durable boundary. Batch acknowledgements must distinguish accepted, retryable, and permanently invalid events without losing successful members on retry.

Raw-event storage format and retention depend on expected throughput and privacy policy. MySQL is the configuration baseline; it is not an unmeasured promise to store unlimited evaluation events. Evaluate an analytical store or object-storage pipeline when representative load tests justify it. Choose and document the launch storage design before collecting customer telemetry (**D08/D09**).

Track event-time versus ingestion-time, late arrivals, clock skew, duplicate rates, sampling, dropped events, SDK version, and data freshness. Counts derived from sampled data must be labeled appropriately. Dashboards explain which clients/events are covered.

Experiment pipelines require stable assignment and plan identifiers, explicit exposures, conversion identity/deduplication, attribution windows, metric definitions, and quality checks. Statistical method, stopping rules, guardrail logic, multiple comparisons, power/minimum evidence, and sample-ratio checks require a reviewed method specification (**D07**). A polished chart is not statistical validation.

Privacy controls apply to pseudonymous IDs too. Define who can inspect subject-level information, how long linkage remains possible, and how deletion propagates through raw events, derived reports, exports, and backups.

## 5. Deployment and compatibility

Each repository builds, tests, versions, and releases independently. Use immutable package versions and deploy artifacts; the backend does not build by reaching into sibling checkouts. Record the supported server/protocol/SDK matrix and run cross-repository conformance against pinned releases.

Use expand-and-contract database migrations: introduce compatible schema, deploy readers/writers, backfill with monitored jobs, verify, then remove old fields after the compatibility window. Never assume rolling back code makes a destructive migration reversible.

Deploy protocol changes with capability negotiation and an explicit compatibility plan. Old SDKs must receive supported data or a documented safe response, not partially interpreted rules. Avoid enabling a rule capability that silently changes results for still-supported clients.

Application rollback restores a compatible artifact; feature configuration rollback creates a new publication revision. These are different operations with separate runbooks.

## 6. Operational controls and runbooks

Before launch, document and rehearse:

- Failed publication delivery: inspect committed revision/outbox, replay safely, validate newest snapshot availability.
- Credential compromise: revoke/rotate, inspect access, invalidate appropriate delivery paths, communicate offline-cache limitations.
- Queue/cache outage: capacity protection, priority recovery, duplicate-safe replay, no cross-tenant cache contamination.
- Provider outage: integration degradation without blocking local evaluation/publication, bounded retry, reconnect and reconciliation.
- Bad application deploy or migration: compatibility checks, artifact rollback, data repair with audit and approval.
- Data restore: encrypted backups, point-in-time recovery where selected, keys, outbox reconciliation, epoch/revision handling, external side effects, measured recovery time and data loss.
- Telemetry loss or experiment integrity incident: visible degraded-data warning, stop invalid conclusions, rebuild only where retained evidence allows it.
- Organization deletion/export: authorization, retention, revocation, linked provider cleanup, backup limitations, completion evidence.

Define on-call ownership, escalation, support access, audit access, and incident communication. RPO, RTO, service objectives, alert thresholds, quotas, and support coverage are **D08** decisions. Do not copy illustrative numbers into customer-facing promises.

## 7. Verification strategy

| Layer | Required checks |
| --- | --- |
| Pure domain/evaluator | Typed values, strict operators, context normalization, override expiry with fixed clock, dependency graphs, stable bucket fixtures, all precedence outcomes |
| Cross-language | PHP and JavaScript run identical normative fixtures; include Unicode, numeric boundaries, allocation changes, malformed snapshots and unsupported capabilities |
| Database/actions | Real MySQL concurrency tests, transaction rollback, unique constraints, publication races, expected versions, approval invalidation, idempotent retries |
| Authorization | Cross-tenant reads/writes/search/export, environment grants, stale memberships, reviewer eligibility, key type/scope, scheduled-action identity |
| Outbox/workers | Crash before/after commit and external send, duplicate/out-of-order delivery, retry exhaustion, queue isolation, context cleanup |
| SDK transport | ETag authorization, transient outage, revoked credentials, cache age across restart, refresh races, stream reconnect/polling, bounded telemetry, SSR isolation |
| Web/UI | Main workflows plus loading/empty/error/conflict states, keyboard access, responsive layouts, realistic long/large inputs, dirty navigation, secrets absent from bundles |
| APIs/CLI/MCP | Schema conformance, permission parity, machine-readable failures, retries, explicit production scope, untrusted tool-returned data |
| Integrations/billing | Provider sandbox/live-safe acceptance as appropriate, signature failures, OAuth rejection, revoked grants, duplicate/out-of-order callbacks, replay and rate limits |
| Experiments | Reviewed statistical implementation with synthetic controlled datasets, attribution edge cases, no-exposure conversions, repeated events, imbalance, missing/late data and stopping-rule behavior |
| Operations/security | Restore rehearsal, protocol-compatible deploy/rollback, egress/SSRF tests, secret rotation, dependency scanning, capacity/soak tests and quota enforcement |

SQLite-only tests are insufficient evidence for MySQL locking and transactional behavior. Test doubles are useful for unit tests but do not replace real Redis/queue/provider-boundary verification. Test harnesses should exercise controllable time, failures, retries, and deterministic fixtures.

Select workload targets before load testing: organizations, flags per environment, snapshot size, rule complexity, concurrent streams, refresh frequency, remote evaluation RPS, event ingestion rate, and reporting latency. Report measured conditions and bottlenecks, not an unsupported “millions of requests” headline.

## 8. Complete-product release gates

Release requires the complete agreed mockup scope, not simply completion of the earliest milestone:

- Website, documentation, hosted onboarding, authentication/security, organizations, projects, environments, roles, keys, and billing work end-to-end.
- All supported flag types, variations, segments, overrides, prerequisites, rollouts, simulation, approvals, publication, propagation visibility, audit, rollback, and lifecycle flows are verified.
- Experiments include real instrumentation, attribution, reviewed statistical reporting, quality warnings, guardrails, and safe decision/publication flows.
- Analytics, SDK health, cleanup evidence, retention, exports where represented, and error/degraded states use real data.
- Webhooks and Slack, GitHub, Datadog, Jira, Vercel, and Sentry integrations meet the agreed behavior and failure handling.
- PHP/JavaScript SDKs, Laravel/Vue/React adapters, CLI, MCP, and the protocol artifacts are released with tested compatibility and examples.
- Accessibility/responsive acceptance, tenant/security review, realistic load tests, restore drill, monitoring, operational ownership, and support documentation pass.
- Contract-affecting open decisions are resolved; no simulated integration, made-up experiment result, demo role control, or placeholder security gate remains in production.

## 9. Internal implementation sequence

Build in dependency order while keeping the same full launch scope:

1. Resolve foundational protocol/identity decisions; establish separate repositories, CI, design tokens, auth, tenant boundaries, and evaluator fixtures.
2. Deliver the connected configuration workflow: modeling, draft editor, targeting, simulation, review, transactional publish, snapshots, SDK consumption, and audit.
3. Complete lifecycle/operations and client breadth: rollback, delivery observation, adapters, CLI/MCP, integrations, billing, key and member administration.
4. Complete the measurement system and experiment workflows with real exposures/conversions, validated methods, reporting and cleanup evidence. Start the statistical specification and data design early; this is not a late UI-only task.
5. Run full workflow, security, compatibility, accessibility, load, and recovery acceptance across every feature area before public release.

These are engineering milestones, not separate MVP releases. There is no schedule estimate until staffing, workload targets, and decision dependencies are known.
