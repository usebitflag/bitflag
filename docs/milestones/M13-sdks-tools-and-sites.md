# M13. SDKs, tools, delivery extensions and sites

Status: **plan stub**, 7 October 2026. Follows [00-conventions.md](00-conventions.md). This file gives every release repository outside the core an owner, a scope, its dependencies and its done criteria, so that M12's go/no-go gate 7 and 8 have something to verify. Each part below gets a full plan in the milestone template before its work starts. Release scope is unchanged (A01): everything here ships before launch.

## 1. Goal and scope

M0 to M11 build the hosted application plus the protocol and both evaluation cores (M1). M13 builds everything customers install or read: the full SDK clients, framework adapters, CLI, MCP server, website and documentation, plus the two delivery features the core deliberately left out (streaming and remote evaluation).

**Ordering.** M13 is not a single block at the end.

| Part | Can start after | Must finish before |
| --- | --- | --- |
| A. Delivery extensions (`bitflag`) | M5, D04, D06 | B and C stream and remote-evaluation acceptance; M12 load tests |
| B. `bitflag-php` full SDK | M5 (minimal client), M10 (telemetry) | D (Laravel) |
| C. `bitflag-js` clients | M1 (JS core), M5 | E (Vue, React) |
| D. `bitflag-laravel` | B | M12 |
| E. `bitflag-vue`, `bitflag-react` | C, D06 | M12 |
| F. `bitflag-cli` | Management OpenAPI frozen (D14), M5 | M12 |
| G. `bitflag-mcp` | F's API client, M9 MCP policy, D11 | M12 |
| H. `bitflag-website` | Landing and Pricing mockups approved, D10 price, D13 name | M12 |
| I. `bitflag-docs` | Each feature's milestone; runs alongside from M5 onward | M12 |

The first real portal → publish → SDK loop runs in M5 with the minimal PHP client. C's first slice (a Node client fetching and evaluating with the M1 core) should land right after M5, so both languages run against a real server early. This is internal validation, not a release.

**Management OpenAPI.** Every milestone that adds a management API endpoint adds it to `contracts/management/openapi.yaml` in `bitflag` in the same pull request; a CI check fails when a route in `routes/api.php` is missing from the spec. F and G generate their clients from a tagged release of that file.

## 2. Parts

### A. Delivery extensions in `bitflag` (owner: backend)

- **Streaming invalidations** `GET /sdk/v1/stream` (server-sent events unless D04 picks otherwise): authenticated by the same credential resolver as snapshots (M3), carries only `(generation, revision, projection)` notices, never configuration. The SDK refetches the snapshot. Connection limits per credential and per environment, heartbeat, maximum connection lifetime with forced reauthentication, immediate close on revocation (via `internal.credential.state_changed`), `Last-Event-ID` resume with full-fetch fallback, jittered reconnect guidance. Hosting choice (in-process, separate stream runtime or managed service) is D04/D08 and is load-tested in M12.
- **Remote evaluation** `POST /sdk/v1/evaluate` and `/evaluate-batch`: server keys only unless D06 allows a browser variant; bounded context and batch size; uses the M1 PHP core on the published snapshot; never returns traces to browser credentials; `401`/`403` are never turned into fallback responses.
- **Delivery observations** extend M10's SDK health with stream connection counts, replacing the "reported in the last 5 minutes" wording only where streams make it accurate.
- Done: stream and polling clients converge on the same revision under publication races; revocation closes open streams within the M3 bound; losing the stream runtime degrades to polling without errors; load targets from M12 met.

### B. `bitflag-php` full SDK (owner: SDK)

- Builds on M5's client and M10's telemetry: PSR-18 transport, PSR-16 cache with documented adapters (APCu, file, Redis), shared-cache behavior for request-per-process PHP, `maxOfflineAge` and the `lastValidatedAt` rules from protocol §7 (a restored persistent cache keeps its original validation time), credential rejection handling, optional stream client for long-running workers, generation and revision ordering, exposure and conversion APIs, shutdown flush. Includes the SDK-side Sentry hook that attaches allowlisted flag values to error events (M9).
- Done: every protocol cache and fallback fixture passes; a persistent cache restored after restart is not treated as fresh; revoked credential stops serving per policy; documented examples run in CI against a staging server.

### C. `bitflag-js` clients (owner: SDK)

- `src/node` and `src/browser` on top of the M1 core: snapshot polling and streaming, conditional requests, persistent cache rules identical to B, telemetry batching with bounded memory, `bootstrap` from server-evaluated values for the D06 default path, browser projection only for browser keys. Includes the Sentry hook (as in B).
- Bundle rules: browser entry points cannot import Node modules or anything that handles server keys (bundle inspection test, M12 `tools/scan-bundle`).
- Done: same fixtures as B, plus SSR isolation tests (no cross-request context or cache leaks) and a browser test matrix agreed under D01.

### D. `bitflag-laravel` (owner: SDK)

- Service provider, config publishing, context resolution from the authenticated user with an explicit attribute allowlist, Blade and middleware helpers, Octane-safe scoping, queue-safe context. Uses B; reimplements nothing.
- Done: example application in CI against staging; Octane and queue worker tests show no context bleed.

### E. `bitflag-vue` and `bitflag-react` (owner: SDK)

- One client instance per application or per SSR request, provider and composable or hook APIs, subscription to updates, render helpers that record exposures once per render tree (not per re-render), hydration from server values.
- Done: SSR frameworks listed under D01 tested; duplicate-exposure tests; no network client created per component.

### F. `bitflag-cli` (owner: tools)

- Scope from api-and-integrations §6: inspect, edit drafts, simulate, review, publish, roll back, key administration; human and `--json` output; stable exit codes; explicit organization, project and environment; production writes print target and diff; `--yes` skips only local confirmation. Authentication (`bitflag login` versus token only) is D11.
- Done: every command maps to an OpenAPI operation; permission parity tests against the same seed as Cypress; no secret ever accepted as a command-line argument where an environment variable or credential store is available.

### G. `bitflag-mcp` (owner: tools)

- Whether this is a hosted endpoint (Integrations 2d shows `https://…/mcp` with OAuth) or an installable package, or both, is **D11 and blocks G**. Either way: tools split by capability (read, simulate, draft write, approve, publish, roll back), enforced server-side by M9's `EnforceMcpPolicy`, explicit production targets, expected versions and idempotency keys on mutations, tool output treated as untrusted data.
- Done: policy matrix tests through the real management API; prompt-injection fixtures in flag descriptions do not trigger extra tool calls in the reference client tests.

### H. `bitflag-website` (owner: product)

- Astro pages from website-and-docs-site §2 and the Landing and Pricing mockups; pricing generated from M6's `plans.json` export so the site cannot disagree with entitlements; hosted-only messaging; no unmeasured numbers.
- Done: content review against R06, R13 and the M12 load report; links to signup and docs verified in the deploy pipeline.

### I. `bitflag-docs` (owner: product with each feature owner)

- Starlight site from website-and-docs-site §3. API reference generated from the tagged OpenAPI, webhook and protocol schemas from `bitflag-protocol`. Every code sample is extracted and run in CI against released SDKs and staging.
- Done: every portal feature links to a page; every error code in the conventions has a troubleshooting entry; compatibility matrix published.

## 3. Cross-repository requirements

- Compatibility matrix (server release, protocol versions, algorithm versions, SDK and adapter versions, supported runtimes) maintained in `bitflag-docs` and checked by a scheduled cross-repository job that installs pinned releases and runs the conformance and example suites.
- Package names, registries, signing and provenance: D13, resolved before the first public publish.
- Each repository has its own CHANGELOG, SemVer and deprecation policy (D01).

## 4. Decisions

| Item | Blocks |
| --- | --- |
| D04 streaming runtime, poll defaults, max offline age | A, B, C |
| D06 browser exposure model and bootstrap | A (browser evaluation), C, E |
| D11 CLI login and MCP hosting and authentication | F (login only), G |
| D13 names, registries, licenses | B to I publication |
| D14 management API freeze | F, G |
| D01 supported runtimes and frameworks | B to E |

## 5. Risks

- This is a large share of the release and has no full plan yet; writing A and C's full plans should start no later than M5.
- Streaming capacity is the least-measured part of the system; A's hosting choice must be load-tested before Overview or Changes copy promises propagation times.
- MCP hosting is a product decision, not an implementation detail; leaving D11 open blocks G entirely.
