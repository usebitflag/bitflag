# BitFlag evaluation and SDK protocol

[Documentation index](README.md)

## Contract status

This document defines the intended semantics and a proposed version-one wire design. `bitflag-protocol` will own the normative schemas, algorithm specification and conformance fixtures. Example payloads are illustrative until those artifacts are released. Numbers such as revision 42 are demo values, not performance or compatibility promises.

The result depends on the complete snapshot, normalized input context, caller fallback and an explicit evaluation time where expiry/date behavior is relevant. Transport authorization happens before the evaluator; the pure evaluator performs no network or database access.

## Context and typed reads

Use one context shape in server evaluation, SDKs, flag simulation and segment preview:

```json
{
  "kind": "user",
  "key": "u_20931",
  "attributes": {
    "country": "PH",
    "plan": "pro",
    "appVersion": "4.2.0"
  }
}
```

Subject keys are strings, not numbers coerced independently by PHP and JavaScript. Context keys, attribute count, nesting, strings and arrays have bounded sizes. Stable subject kind/key are mandatory for sticky rollout or experiment assignment. Callers without identity can use a persisted anonymous ID; SDKs must not invent a new random cohort identity on each read.

Typed read APIs require a fallback matching the requested type. Invalid caller fallback is a programming error with an explicit SDK error, not a covert conversion. A missing flag or unusable configuration returns a valid caller fallback with a machine-readable reason/error. Financially precise decimals or integers outside the agreed safe numeric range should be represented by a deliberate string/structured contract rather than accidental JavaScript rounding; the numeric representation is finalized in D03.

## Evaluation precedence

| Order | Condition | Result |
| --- | --- | --- |
| 1 | No usable authorized snapshot, or unsupported required capability | Typed caller fallback with diagnostic error |
| 2 | Flag missing from the selected snapshot or incompatible with requested type | Typed caller fallback with specific error |
| 3 | Environment configuration disabled | Off variation |
| 4 | Valid prerequisite not satisfied | Off variation with prerequisite reason |
| 5 | Applicable, unexpired exact subject override | Override variation |
| 6 | First matching enabled ordered rule | Fixed variation or stable weighted assignment |
| 7 | No rule matched | Default on variation |

An invalid prerequisite graph or invalid variation reference is a configuration error, not an ordinary unmet prerequisite. Validation should prevent these snapshots from being published; runtimes must still handle malformed input safely.

Experiment assignment must compile into an explicit outcome at a defined point in this same evaluation order. Proposed design: an experiment outcome attached to the selected rule, with a separate salt and exposure metadata. Off state, prerequisites and exact overrides retain their documented precedence. The finalized experiment configuration must not introduce an undocumented second evaluator.

Predicate evaluation is strict: do not let language coercion make `"20"` equal to `20`. Proposed missing/null rule: absent values fail comparisons including negative comparisons; `not exists` explicitly tests absence. Null is an explicit JSON value, distinct from absence. Empty groups, array semantics, date parsing, semantic versions, case sensitivity and depth limits must have fixtures before the contract is frozen.

## Result shape

```json
{
  "flagKey": "new-checkout",
  "value": true,
  "variationKey": "enabled",
  "reason": "RULE",
  "ruleId": "rule_ph_premium",
  "revision": 42,
  "evaluatedAt": "2026-10-05T00:00:00Z",
  "errorCode": null
}
```

`reason` identifies OFF, OVERRIDE, RULE, DEFAULT, PREREQUISITE, FALLBACK or ERROR. `errorCode` gives details such as FLAG_NOT_FOUND, TYPE_MISMATCH, MISSING_SUBJECT, UNSUPPORTED_CAPABILITY, INVALID_CONFIG, STALE_CACHE or AUTHORIZATION_REJECTED. Final naming is part of the protocol release.

An authenticated remote-evaluation endpoint returns per-flag results for valid evaluation requests. Authentication/authorization failures use HTTP 401/403, not successful fallback responses. SDKs may convert transport failures into the caller's fallback locally, while exposing the failure state.

Debug traces include rule decisions, predicate failures, prerequisite path and bucket bounds only where the viewer is authorized. Do not return private predicates through a public browser endpoint. Traces are opt-in and not attached to every telemetry event.

## Stable rollout algorithm

Use 100,000 integer buckets numbered 0 through 99,999. A 20% allocation occupies 20,000 buckets. These are buckets, not 100,000 basis points.

**Algorithm `bucket-sha256-v1` (decided, R15):**

1. Build the ordered tuple: the domain tag `bitflag-bucket-v1`, the allocation's persistent salt, subject kind, and the bucketing value (the context `key`, or the rule's `bucketBy` attribute, which must be a non-empty string).
2. Encode each tuple element as its UTF-8 bytes prefixed by a four-byte unsigned big-endian byte length. This avoids delimiter ambiguity. Do not apply implicit case folding or Unicode normalization.
3. Hash the concatenated bytes with SHA-256.
4. Interpret the first eight digest bytes as an unsigned big-endian 64-bit integer and take modulo 100,000. The remaining modulo bias is below 10⁻¹⁴ and is accepted. JavaScript implementations use `BigInt` or equivalent exact 64-bit arithmetic.
5. Select the half-open cumulative bucket interval containing that bucket. Allocation units must total at most 100,000; an explicit remainder variation receives unallocated buckets.

Project, environment and flag identity are deliberately not hash inputs: the salt carries allocation identity. Salts are random and unique per allocation, and publication rejects two allocations in one snapshot that share a salt unless they were explicitly linked to keep a cohort. Copying a rule, flag or environment generates new salts by default.

Do not use language-specific string hashes or the prototype's demonstration hash as production behavior. Freeze the chosen algorithm and publish exact known-answer vectors for both languages. Changing it later requires an explicit algorithm version and migration plan.

Increasing a single treatment's threshold from 20% to 50% preserves its prior members only while identity inputs, salt, eligibility and preceding rule behavior remain unchanged. Multivariate weight changes, rule reordering, audience edits and salt changes may reassign subjects. The UI must describe those consequences.

Do not include snapshot revision in the hash input; ordinary publication must not reshuffle users. Experiments use their own stable allocation identity/salt, with plan-version rules governing changes.

## Snapshot representation

A snapshot is a complete immutable environment configuration containing:

- Protocol and algorithm versions, required capabilities, tenant/project/environment identity, revision and publication time.
- Flag keys/types, immutable variation values, enabled/off/default settings, compiled rules and persistent salts.
- Frozen segment definitions, overrides with expiry, prerequisites and experiment allocation metadata where applicable.
- A checksum of the canonical content and a defined audience projection identifier.

Canonical serialization, checksum input boundaries and schema limits are D03 requirements. Avoid self-referential checksum fields: compute over the defined canonical payload excluding the checksum/envelope metadata itself.

Generate server and browser representations from the same authorized published revision. A browser projection contains only deliberately public configuration. Public flags with private prerequisite/segment dependencies must be rejected at publication or served through a trusted server path; dropping private dependencies while retaining altered public behavior is not acceptable.

Different projections may share an environment revision but need different checksums and ETags. Authenticate and authorize before returning a cached representation, including a 304 response. Private configuration cannot enter a shared public CDN cache. Any edge cache must use the correct tenant/environment/audience key and enforce current authorization.

## SDK initialization and caching

Local evaluation is synchronous after initialization; fetching, refreshing and remote evaluation are asynchronous. PHP request-based applications use an appropriate shared cache to avoid a network fetch on every request. Long-running Node/PHP runtimes can refresh in the background.

Track these separately:

| Field | Meaning |
| --- | --- |
| `publishedAt` | When configuration content was published |
| `lastValidatedAt` | When an authenticated successful fetch or 304 confirmed the usable current representation |
| `lastRefreshAttemptAt` | Most recent attempt, successful or not |
| `maxOfflineAge` | Maximum time since successful validation before fallback is required |

Old unchanged content is not automatically stale. A successful authorized 304 refreshes `lastValidatedAt`; a failed request does not. A restored persistent cache must retain its original validation timestamp and must not appear fresh merely because the process restarted.

On transient network/server failure, retain a validated cached snapshot within policy. Once it exceeds the permitted offline age, use caller fallbacks and expose the stale condition. Explicit credential rejection (401/403) stops serving from that credential's cache according to the documented revocation policy; do not treat rejection as an ordinary transient outage. An offline client cannot learn about revocation until communication resumes, so the bounded offline policy remains essential.

Cache keys include endpoint, project/environment, credential identity and audience; persist only a non-secret credential fingerprint in cache metadata. Do not persist raw context by default. Context changes invalidate context-dependent memoization rather than the whole configuration snapshot.

Conditional polling and streaming invalidations are complementary. Streaming carries revision notices, never secrets or raw context; the SDK refetches authorized configuration. Reconnect includes jitter/backoff and falls back to polling/full fetch when event history is unavailable. Never regress to an older revision due to racing refresh responses. A rollback is a higher revision with older content.

Disaster recovery is a separate case: restoring server data can leave a client's cached revision ahead of the restored server. Define an authenticated generation/epoch transition or equivalent verified recovery mechanism under D03/D08 before freezing the protocol. An ordinary older response must never trigger this exception. See the [restore requirements](operations-and-release.md).

## Browser and server SDK boundaries

Server credentials authorize private snapshot retrieval and remote evaluation within their scope. Browser identifiers are public and authorize only public configuration/event capabilities. CORS is a browser control, not a confidentiality boundary. Browser-provided attributes are untrusted and must not enforce server authorization or paid entitlements.

Default private evaluation path: the customer's trusted backend evaluates and sends safe result values to the browser. The full browser SDK remains in release scope; whether it defaults to public local snapshots or an evaluated-value endpoint is D06. If result bootstrapping is used, include safe revision/context binding without private rules or credentials.

Vue/React adapters share one SDK instance per appropriate application/request scope. SSR uses per-request identity and safe hydration values. Prevent cross-user cache leaks and duplicate exposure events from repeated renders. The Laravel adapter binds the PHP SDK and chooses explicit context attributes; it does not reimplement evaluation.

## Telemetry and conformance

Evaluation does not implicitly become experiment exposure. Record exposure only when the application uses the result, through explicit SDK calls or documented render helpers. Batch telemetry with bounded memory, payloads, retries and shutdown flushing. Telemetry failure must not block local flag reads.

Canonical fixtures cover every predicate/type, missing/null inputs, Unicode, identity encoding, bucket boundaries, 0/100% allocations, multivariate reallocation, precedence, expiry with a fixed clock, invalid graphs, unsupported capabilities and cache/fallback cases. Server simulation and both SDK engines must agree on value, variation, reason and applicable rule/revision.

An OpenFeature provider is an optional integration proposal, not a replacement for these contracts or an automatically added release requirement. [OpenFeature providers](https://openfeature.dev/docs/reference/concepts/provider/)
