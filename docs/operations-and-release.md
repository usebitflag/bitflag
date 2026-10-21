# Hosted operations and verification

[Documentation index](README.md)

## 1. Deployment shape

The marketing website and documentation deploy independently from the hosted application. The application topology is:

```text
Hosted app boundary
  HTTP application processes       Portal + management + authorized delivery
  Background workers              Publication delivery, integrations, ingestion, reports
  Scheduler                       Retention, reconciliation, scheduled lifecycle work
  Streaming runtime               Authenticated SDK invalidations
  MySQL                           Authoritative configuration, publication, identities
  Redis                           Cache, queues/rate-limit state as selected
  Private object storage          Exports/backups and immutable artifacts if selected
  Telemetry storage/read models    Behind a storage interface, sized through benchmarks
```

This is a process/responsibility map, not a requirement for one microservice or repository per line. Application and worker processes can deploy the same `bitflag` artifact with different commands. Stream infrastructure may be managed or separately provisioned without splitting business logic prematurely.

Managed database and cache services and reproducible infrastructure are preferred. Hosted-only does not mean manual deployments; internal containers and infrastructure automation do not constitute a supported self-hosted offering.

## 2. Configuration, isolation, and secrets

Use separate production, staging, and development databases, caches, queues, storage, OAuth apps, and provider credentials. A staging integration test must not publish to a production customer environment. Use generated/sanitized fixtures rather than copies of raw production context.

Read secrets from a managed secret mechanism, not committed environment files. Limit database and storage access by service role. Encrypt traffic and stored secrets/backups, rotate access keys, and document recovery of encryption keys. Losing decryption material can make a nominally successful backup useless.

Behind a load balancer, set `TRUSTED_PROXIES` to the balancer's addresses (or `*` when the platform gives none) so the application reads the client address and original scheme from the forwarding headers; without it, secure cookies, generated URLs and per-address rate limits see only the balancer. The session cookie is `Secure` in production unless `SESSION_SECURE_COOKIE=false` is set. The application serves a `robots.txt` that disallows crawling, and expected client errors (404, 403, 409, 422) are answered without being reported, so the error log holds server failures.

Long-running workers and application servers must reset tenant/request context. Authenticate streaming connections and define reauthentication, maximum lifetime, revocation, reconnect, and backpressure behavior. Deployment reloads must not lose committed publication intents.

## 3. Publication and runtime reliability

The database publication pointer and immutable snapshot are authoritative. Redis is an acceleration layer, not the only copy of published configuration. Delivery cache failure should have a bounded authenticated fallback path; validate that this fallback cannot overwhelm MySQL during an outage.

Separate workload priorities so telemetry bursts or a broken external webhook cannot starve publication delivery. Bound queues, retries, concurrent provider calls, stream fanout, and batch sizes. Monitor oldest pending outbox age and failed delivery attempts, not only worker uptime.

Measure distinct intervals: command latency, publication commit-to-delivery availability, SDK observation lag, stream reconnect time, and time since last successful cache validation. Observation metrics depend on reporting clients; offline clients are not silently counted as current.

When a disaster restore reintroduces older database state, SDKs may already hold higher revision numbers. The protocol therefore carries an authenticated generation (epoch) for recovery; silently allowing all lower revisions would break ordinary race protection. Restore drills include cached clients, not only database startup.

## 4. Telemetry and analytics pipeline

Flow: validate and acknowledge ingestion according to its durability contract, persist/enqueue accepted events, deduplicate, enrich with versioned configuration references, aggregate, and expose reporting read models. Do not acknowledge reliable ingestion before the chosen durable boundary. Batch acknowledgements must distinguish accepted, retryable, and permanently invalid events without losing successful members on retry.

Raw-event storage format and retention depend on throughput and privacy policy. Telemetry is written through a storage interface; MySQL is the initial store, and an analytical store or object-storage pipeline replaces it when load tests justify it.

Track event-time versus ingestion-time, late arrivals, clock skew, duplicate rates, sampling, dropped events, SDK version, and data freshness. Counts derived from sampled data must be labeled appropriately. Dashboards explain which clients/events are covered.

Experiment pipelines require stable assignment and plan identifiers, explicit exposures, conversion identity/deduplication, attribution windows, metric definitions, and quality checks. Statistical method, stopping rules, guardrail logic, multiple comparisons, power and minimum evidence, and sample-ratio checks follow a reviewed method specification. A polished chart is not statistical validation.

Privacy controls apply to pseudonymous IDs too. Define who can inspect subject-level information, how long linkage remains possible, and how deletion propagates through raw events, derived reports, exports, and backups.

## 5. Deployment and compatibility

Each repository builds, tests, versions, and releases independently. Use immutable package versions and deploy artifacts; the backend does not build by reaching into sibling checkouts. Record the supported server/protocol/SDK matrix and run cross-repository conformance against pinned releases.

Use expand-and-contract database migrations: introduce compatible schema, deploy readers/writers, backfill with monitored jobs, verify, then remove old fields after the compatibility window. Never assume rolling back code makes a destructive migration reversible.

Deploy protocol changes with capability negotiation and an explicit compatibility plan. Old SDKs must receive supported data or a documented safe response, not partially interpreted rules. Avoid enabling a rule capability that silently changes results for still-supported clients.

Application rollback restores a compatible artifact; feature configuration rollback creates a new publication revision. These are different operations with separate runbooks.

## 6. Operational controls and runbooks

Runbooks are documented and rehearsed for:

- Failed publication delivery: inspect committed revision/outbox, replay safely, validate newest snapshot availability. `php artisan outbox:status` shows the backlog, receipts by state and the age of the oldest overdue work; `--max-lag=SECONDS` and `--fail-on-exhausted` make it a monitoring check (a stopped worker fails nothing, so work that sits is the signal), and `--json` is for tooling.
- Outbox housekeeping: `outbox:prune` runs daily and deletes a message and its receipts once it was dispatched and every receipt succeeded more than `OUTBOX_RETENTION_DAYS` (default 14) ago. A message with a pending, processing, failed or exhausted receipt is never deleted. `--dry-run` reports the count; `--days` overrides the period.
- Credential compromise: revoke/rotate, inspect access, invalidate appropriate delivery paths, communicate offline-cache limitations.
- Queue/cache outage: capacity protection, priority recovery, duplicate-safe replay, no cross-tenant cache contamination.
- Provider outage: integration degradation without blocking local evaluation/publication, bounded retry, reconnect and reconciliation.
- Bad application deploy or migration: compatibility checks, artifact rollback, data repair with audit and approval.
- Data restore: encrypted backups, point-in-time recovery where selected, keys, outbox reconciliation, epoch/revision handling, external side effects, measured recovery time and data loss.
- Telemetry loss or experiment integrity incident: visible degraded-data warning, stop invalid conclusions, rebuild only where retained evidence allows it.
- Organization deletion/export: authorization, retention, revocation, linked provider cleanup, backup limitations, completion evidence.

On-call ownership, escalation, support access, audit access and incident communication are defined alongside the runbooks. Recovery and service objectives are published only after they are measured.

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

Load tests start from stated workload targets: organizations, flags per environment, snapshot size, rule complexity, concurrent streams, refresh frequency, remote evaluation RPS, event ingestion rate, and reporting latency. Results report measured conditions and bottlenecks, not an unsupported headline number.

## 8. Acceptance checks

Before a build is released, these areas are verified end to end:

- Authentication, organizations, projects, environments, roles, keys and billing.
- Flag types, variations, segments, overrides, prerequisites, rollouts, simulation, approvals, publication, rollback and lifecycle flows.
- Experiment instrumentation, attribution, reviewed statistical reporting, quality warnings and guardrails.
- Analytics, SDK health, cleanup evidence and error or degraded states backed by real data.
- Webhooks and each provider integration, including failure handling.
- SDK, adapter, CLI and MCP compatibility against the pinned protocol release.
- Accessibility and responsive behavior, tenant isolation and security review, load tests, a restore drill, monitoring and support documentation.

Production contains no simulated integration, invented experiment result, demo role control or placeholder security check.
