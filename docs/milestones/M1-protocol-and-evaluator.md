# M1. Protocol and evaluator

Status: draft for review, 6 October 2026. Expands M1 in [implementation-plan.md](../implementation-plan.md), follows [00-conventions.md](00-conventions.md), and treats [evaluation and SDK protocol](../evaluation-and-sdk-protocol.md) plus the decision register as the source of truth. Every place where that draft leaves a choice to D03, this plan states the default it builds on (§6).

## 1. Goal and scope

M1 produces the contract every evaluator must satisfy and the first evaluator that satisfies it.

**In scope**

- `bitflag-protocol`: the written spec, JSON Schemas (context, snapshot, result with trace, telemetry batch and acknowledgement, fixture files, bucket vectors), the fixture format and directory layout, fixtures for every category in protocol §10, fixture tooling, and an immutable, checksummed release process.
- `bitflag-php`: `src/Protocol` (strict JSON decoding, snapshot parsing and validation, canonical JSON, checksums) and `src/Evaluation` (context, evaluation order, operators, segments, `bucket-sha256-v1`, rollouts, prerequisites, overrides with an injected clock, typed results, reasons, error kinds, traces). Pure PHP with no runtime dependencies.
- A conformance runner in `bitflag-php` that runs one pinned fixture release, verified by checksum, in CI.
- `bitflag-js` evaluation core (`src/evaluation`, `src/protocol`): a second, independently written evaluator in TypeScript that runs the same pinned fixture release (§3.10). The contract is frozen only when both evaluators pass it. Two implementations are the real test that the written spec is complete; the Node tools in `bitflag-protocol` are reference utilities for generating vectors and do not count as the second implementation.
- How `bitflag` consumes `bitflag-php`: pinned prerelease through a Composer VCS repository, a local path link that can never be committed, and the app's own run of the same fixtures.

**Out of scope (owner milestone)**

- Snapshot compiler from drafts and the simulator (M4), publication validation and snapshot finalization (M5).
- SDK client, transport, cache, ETag handling, telemetry sending (minimal client in M5; full SDK in M13).
- Stream message and webhook envelope schemas (M5 and M9 add them to `bitflag-protocol`).
- `bitflag-js` transport, cache, browser and Node clients, telemetry (M13). Only its evaluation core is built here.
- Any database table or portal page.

## 2. Mockups covered

M1 has no screens, but the mockups fix behavior the protocol must produce.

| Mockup | Where | Behavior implied |
| --- | --- | --- |
| `portal/Flags.dc.html` | Rule editor, `OPS` list | Flag rule operators shown: is, is not, is one of, ends with, contains (`eq`, `neq`, `in`, `ends_with`, `contains`) |
| `portal/Segments.dc.html` | Segment editor, `OPS` list | Segment operators: is one of, is not one of, ends with, contains, at least, at most (`in`, `not_in`, `ends_with`, `contains`, `gte`, `lte`) |
| `BitFlag Flags.dc.html` 1c, 2a | Rules tab | "Rules are checked top to bottom. The first match decides the value. Overrides are checked before any rule." "No rule matched · Serve default". Condition example `email ends with "@acme.dev"`, groups "All of" |
| `portal/Flags.dc.html` | Rollout card | "Users already getting true keep it. Raising the rollout only adds users." "Going below the published 20% moves about N of matching users from true back to false." Rollout "by user.key" (`bucketBy`) |
| `portal/Flags.dc.html` | Overrides | "Checked before rules, in {env} only. Max 500." Expiry "In 7 days, In 30 days, Never". Import format "kind, key, variation, reason" (overrides are keyed by kind and key) |
| `BitFlag Flags.dc.html` 1b | Create flag | Types Boolean, String, Number, JSON |
| `BitFlag Design System.dc.html` 08 | Evaluation result | Value, Reason `RULE` with rule name and number, Bucket "14,203 < 20,000" (bucket and slot bound), Revision "draft vs published rev 42" (the trace must carry slot bounds and the result the revision) |
| `BitFlag Design System.dc.html` 08 | Percentage distribution | "Weights total at most 100%", unallocated traffic gets a separate outcome (the protocol's remainder variation) |
| `BitFlag Design System.dc.html` 07 | Archive dialog | "SDKs will return your fallback value … This is different from disabling, which serves the off variation" (caller fallback vs off variation) |
| `portal/Flags.dc.html` script | Prototype `bucket()` | FNV-1a over flag key plus subject key; demonstration only, never production behavior (protocol §5) |

Operator labels the editor will use (M4), mapped to protocol operators:

| Mockup label | Operator |
| --- | --- |
| is / is not | `eq` / `neq` |
| is one of / is not one of | `in` / `not_in` |
| starts with / ends with / contains | `starts_with` / `ends_with` / `contains` |
| at least / at most | `gte` / `lte` (plus `gt`, `lt`) |
| segment is / is not | `in_segment` / `not_in_segment` |
| (no label yet) | `exists`, `not_exists`, `semver_*`, `before`, `after` |

## 3. Design

### 3.1 Data model (wire formats; no database tables)

All schemas are JSON Schema 2020-12 under `schemas/v1/`, with shared definitions in `common.schema.json` (`flagKey`, `variationKey`, `segmentKey`, `contextKind`, `subjectKey`, `rfc3339`, `sha256Checksum`, `weight`). Field names are camelCase on the wire.

**Context** (`context.schema.json`)

| Field | Rule |
| --- | --- |
| `kind` | Required. `^[a-z][a-z0-9_-]{0,63}$` (R05) |
| `key` | Optional. String, 1 to 256 UTF-8 bytes. A number is invalid, never coerced |
| `attributes` | Optional object, at most 100 entries. Names are 1 to 128 bytes, case-sensitive, and may not be `kind` or `key`. Values: string (at most 1,024 bytes), finite number, boolean, `null`, or an array of at most 100 items of one scalar type (no `null` items, no nested arrays or objects) |

**Snapshot** (`snapshot.schema.json`)

| Field | Type and rule |
| --- | --- |
| `protocolVersion` | Integer, `1` |
| `algorithmVersion` | `"bucket-sha256-v1"` |
| `environment` | `{ "id": "env_…", "key": "production" }` |
| `revision` | Integer ≥ 0. `0` is the synthetic snapshot of a never-published environment (M5) and must have `flags: {}` |
| `generation` | Integer ≥ 1 |
| `projection` | `"server"` or `"public"`. For `public`, every flag has `overrides: []`, and segments carry no `include` or `exclude` lists (enforced by `if/then` in the schema) |
| `publishedAt` | RFC 3339 UTC |
| `requiredCapabilities` | Unique strings; must equal the union of every flag's `requires` |
| `flags` | Object keyed by flag key (`^[a-z][a-z0-9_-]{0,99}$`) |
| `segments` | Object keyed by segment key |
| `checksum` | `sha256:` plus 64 lowercase hex characters (§3.2.9) |

Flag object:

| Field | Rule |
| --- | --- |
| `type` | `boolean`, `string`, `number`, `json` |
| `enabled` | Boolean |
| `variations` | Object of variation key (`^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`) to value. Values match `type`: `json` values may be any JSON value at most 32 KB canonical |
| `offVariation`, `defaultVariation` | Variation keys |
| `requires` | Optional array of capability names this flag needs |
| `prerequisites` | Array (≤ 20) of `{ "flagKey", "variations": [variation keys] }` |
| `overrides` | Array (≤ 500, the mockup's "Max 500") of `{ "kind", "key", "variation", "expiresAt": rfc3339 or null }`; `(kind, key)` unique |
| `rules` | Array (≤ 200) of rule objects |

Rule object: `id` (1 to 64 characters, stable across edits), `enabled` (optional, default `true`), `match` (condition group), `serve`. Rule names are not in the snapshot; the simulator maps `id` to the name from the draft (M4).

Condition group: an object with exactly one key, `all` or `any`, whose value is an array (≤ 50 items) of conditions and groups. Nesting depth ≤ 5.

Condition: `{ "attribute", "op", "values", "ignoreCase" }`. `attribute` is a context attribute name, or `kind` or `key`. `values` is required except for `exists` and `not_exists`, and holds 1 to 1,000 items. Segment conditions are `{ "op": "in_segment", "values": ["beta-testers"] }` with no `attribute`.

Serve: `{ "variation": "on" }` or `{ "rollout": { "bucketBy", "salt", "slots": [{ "variation", "weight" }], "remainderVariation", "allocationId", "experimentId" } }`. `bucketBy` is `key` or an attribute name. `salt` is 1 to 128 bytes. Weights are integers 0 to 100,000 and sum to at most 100,000. `remainderVariation` is required when the sum is below 100,000. `allocationId` and `experimentId` are optional and present together (experiment rules, M11).

Segment object: `{ "version": integer ≥ 1, "match": group, "include": [{kind, key}], "exclude": [{kind, key}] }` (lists optional, server projection only).

**Result** (`result.schema.json`)

| Field | Type | Notes |
| --- | --- | --- |
| `flagKey` | string | |
| `value` | any | Served value, or the caller fallback for `FALLBACK` and `ERROR` |
| `variationKey` | string or null | null when the fallback was returned |
| `reason` | `OFF`, `PREREQUISITE`, `OVERRIDE`, `RULE`, `DEFAULT`, `FALLBACK`, `ERROR` | |
| `ruleId` | string or null | Set for `RULE` |
| `prerequisiteKey` | string or null | The failing prerequisite for `PREREQUISITE` (addition to the protocol example) |
| `revision`, `generation` | integer or null | null only when no snapshot was usable |
| `bucket` | integer or null | Only when a rollout decided and the caller asked for details |
| `experimentId`, `allocationId` | string or null | Experiment rules only (`allocationId` is named in protocol §3 but missing from its example) |
| `errorKind` | enum or null | §3.2.8 |
| `evaluatedAt` | RFC 3339 | The evaluation time used |
| `trace` | object, optional | Trusted callers only, on request (§3.2.10) |

**Telemetry batch** (`telemetry-batch.schema.json`) and **acknowledgement** (`telemetry-ack.schema.json`), consumed by M10:

```json
{
  "batchId": "01J9…",
  "sentAt": "2026-10-06T08:52:11Z",
  "sdk": { "name": "bitflag-php", "version": "0.1.0", "protocolVersion": 1 },
  "events": [
    { "id": "01J9…A", "type": "evaluation_summary", "windowStart": "…", "windowEnd": "…",
      "flagKey": "new-checkout", "variationKey": "on", "reason": "RULE", "errorKind": null,
      "revision": 42, "generation": 1, "count": 118 },
    { "id": "01J9…B", "type": "exposure", "occurredAt": "…", "flagKey": "checkout-layout",
      "variationKey": "b", "revision": 42, "experimentId": "exp_…", "allocationId": "alc_…",
      "subject": { "kind": "user", "key": "u_20931" } },
    { "id": "01J9…C", "type": "conversion", "occurredAt": "…", "event": "checkout_completed",
      "subject": { "kind": "user", "key": "u_20931" }, "value": 42.5 },
    { "id": "01J9…D", "type": "sdk_status", "occurredAt": "…", "revision": 42, "generation": 1,
      "snapshotAgeMs": 4000, "errorCounts": { "SNAPSHOT_TOO_OLD": 0 } }
  ]
}
```

At most 500 events and 256 KB per batch; event IDs are 1 to 64 characters and unique per SDK instance. The acknowledgement is `{ "accepted": [ids], "retryable": [{ "id", "code" }], "rejected": [{ "id", "code" }] }`. Evaluation summaries never carry subject data (protocol §9).

**Fixture file** (`fixture.schema.json`). One file per scenario:

```json
{
  "id": "operators/neq/missing-attribute",
  "description": "A negated operator does not match when the attribute is missing.",
  "spec": ["04-operators.md#missing-null-and-wrong-type"],
  "tags": ["operator:neq", "missing"],
  "snapshotRef": "snapshots/country-rules.json",
  "cases": [
    {
      "name": "no country attribute",
      "flagKey": "ph-promo",
      "type": "boolean",
      "fallback": false,
      "context": { "kind": "user", "key": "u_1", "attributes": { "plan": "pro" } },
      "evaluatedAt": "2026-10-06T08:00:00Z",
      "options": { "details": true, "trace": true },
      "expect": { "value": false, "variationKey": "off", "reason": "DEFAULT", "ruleId": null,
                  "errorKind": null, "revision": 42, "generation": 1, "bucket": null },
      "expectTrace": [{ "step": "rule", "ruleId": "not-ph", "conditions": [{ "op": "neq", "outcome": "missing" }] }]
    }
  ]
}
```

- `snapshot` (inline) or `snapshotRef` (relative to `fixtures/`) at file level; a case may override it with its own `snapshotRef`, which is how before-and-after fixtures (rollout changes, reallocation) compare two snapshots. Snapshot files under `fixtures/snapshots/` are shared by several fixtures.
- `expect` lists the fields every evaluator must match exactly: `value`, `variationKey`, `reason`, `ruleId`, `prerequisiteKey`, `errorKind`, `revision`, `generation`, `bucket`, `experimentId`, `allocationId`, `evaluatedAt`. Omitted fields are not compared. Numbers compare numerically (`20` equals `20.0`).
- `expectTrace` is a partial match: each listed step and field must appear in order; extra trace content is allowed, so languages may add detail.
- Snapshot-level fixtures use `"kind": "snapshot"` with `snapshotRaw` (a string, so invalid JSON can be expressed) or `snapshot`, and `expect: { "accept": false, "errorKind": "MALFORMED_SNAPSHOT", "path": "/flags/a/rules/0/serve" }` or `{ "accept": true }`.
- Programming-error fixtures use `expect: { "throws": "FALLBACK_TYPE_ERROR" }`.

Bucket vectors (`fixtures/bucketing/bucket-sha256-v1.json`): `[{ "salt", "kind", "value", "inputHex", "digestPrefixHex", "bucket" }]`. Canonical vectors (`fixtures/canonical/*.json`): `{ "input", "canonical", "checksum" }`.

### 3.2 Domain rules

#### 3.2.1 Evaluation order

```text
evaluate(snapshot, flagKey, requestedType, fallback, context, at, options):
  assert typeOf(fallback) == requestedType            else throw FallbackTypeError
  ctx = normalize(context)                            invalid -> ERROR / INVALID_CONTEXT
  flag = snapshot.flags[flagKey]                      absent  -> FALLBACK / FLAG_NOT_FOUND
  flag.type == requestedType                          else    -> FALLBACK / TYPE_MISMATCH
  flag.requires ⊆ supported                           else    -> ERROR / UNSUPPORTED_CAPABILITY
  return evaluateFlag(flag, ctx, at, visiting = [flagKey])

evaluateFlag(flag, ctx, at, visiting):
  if !flag.enabled                                    -> OFF (offVariation)
  for p in flag.prerequisites (in order):
     if p.flagKey in visiting or |visiting| > 10      -> ERROR / INVALID_CONFIG
     pf = snapshot.flags[p.flagKey]                   absent -> ERROR / INVALID_CONFIG
     r = evaluateFlag(pf, ctx, at, visiting + p.flagKey)   (memoized per call)
     if r.reason == ERROR                             -> ERROR (propagate errorKind)
     if r.variationKey not in p.variations            -> PREREQUISITE (offVariation, prerequisiteKey = p.flagKey)
  for o in flag.overrides:
     if o.kind == ctx.kind and o.key == ctx.key and (o.expiresAt == null or at < o.expiresAt)
                                                      -> OVERRIDE (o.variation)
  for r in flag.rules where r.enabled:
     if matches(r.match, ctx):
        fixed variation                               -> RULE (ruleId)
        rollout: value = bucketByValue(r, ctx)        missing -> ERROR / MISSING_SUBJECT_KEY
                 b = bucket(salt, ctx.kind, value); serve slot or remainder -> RULE (ruleId, bucket)
  -> DEFAULT (defaultVariation)
```

- `FALLBACK` and `ERROR` results return the caller fallback with `variationKey: null`. All other reasons return a variation from the snapshot.
- A variation key that does not exist in `variations` (for `offVariation`, `defaultVariation`, an override, a slot or a remainder) is `ERROR / INVALID_CONFIG`. Invalid configuration never returns a truthy value by accident.
- A prerequisite that ends in `ERROR` makes the parent `ERROR` with the same `errorKind`, so a broken graph is never read as an ordinary unmet prerequisite (protocol §3).
- Overrides come after prerequisites, as in protocol §3: an unmet prerequisite serves the off variation even for a subject with an override.
- `evaluateAll(context)` has its own contract, because there is no caller fallback to validate: it evaluates every flag in the snapshot in that flag's declared type and returns `AllFlagsResult { results: map<flagKey, BulkResult>, revision, generation }`. A `BulkResult` has the same fields as `EvaluationResult` except that `value` and `variationKey` are `null` whenever `reason` is `ERROR` (there is no fallback to substitute), and `FALLBACK` cannot occur because only flags present in the snapshot are listed. Callers must not feed bulk values into typed reads; typed reads always go through `evaluate*` with a typed fallback. Bulk evaluation is for bootstrapping and debugging, and is covered by its own fixture category `bulk/`.

#### 3.2.2 Operators and missing values

| Operator | Attribute types | `values` | Match rule |
| --- | --- | --- | --- |
| `eq`, `neq` | string, number, boolean | exactly 1, same type family | Strict type; numbers compare numerically |
| `in`, `not_in` | string, number | 1 to 1,000, one type | Membership |
| `starts_with`, `ends_with`, `contains` | string | 1 to 1,000 strings | Any listed value matches. Byte-wise on UTF-8. `ignoreCase: true` folds ASCII A to Z only |
| `gt`, `gte`, `lt`, `lte` | number | exactly 1 number | Finite numbers |
| `exists`, `not_exists` | any | none | Presence of the attribute name; `null` counts as present |
| `semver_eq`, `semver_neq`, `semver_gt`, `semver_gte`, `semver_lt`, `semver_lte` | string | exactly 1 valid SemVer | SemVer 2.0.0 precedence (§11 of that spec); build metadata ignored |
| `before`, `after` | string | exactly 1 RFC 3339 instant | Strictly earlier or later instant |
| `in_segment`, `not_in_segment` | none | 1 to 50 segment keys | Member of any listed segment |

Missing, null and wrong type (protocol §4, D03 default as proposed):

1. A positive operator on a missing attribute, a `null`, or a value of the wrong type is false.
2. A negated operator (`neq`, `not_in`, `semver_neq`) is the negation of its positive form only when the attribute is present, non-null and of the right type; otherwise it is also false.
3. Arrays: a positive operator matches when any element matches; a negated operator matches when no element matches. An empty array is present and of the right type, so positive operators are false and negated operators are true.
4. An invalid SemVer or date in the context is the wrong type (false for both forms). An invalid one in the snapshot is a schema error.
5. `not_in_segment` is the plain negation of `in_segment`, because segment membership is always computable.

Values:

- **Numbers.** IEEE 754 binary64. Integers outside ±(2^53 − 1) are invalid in snapshots and treated as the wrong type in contexts. `-0` equals `0`. NaN and infinities can't appear in JSON and are rejected if constructed in PHP.
- **Strings** are compared byte for byte after validating UTF-8, without normalization or case folding (protocol §5 Unicode rule applies to matching too).
- **Dates.** `YYYY-MM-DDTHH:MM:SS[.fraction](Z|±HH:MM)`, uppercase `T` and `Z`, seconds 00 to 59, fraction 1 to 9 digits truncated to milliseconds. Converted to epoch milliseconds with integer arithmetic (days-from-civil), so PHP and JavaScript agree without platform date parsers.
- **SemVer.** `MAJOR.MINOR.PATCH[-pre][+build]`, no leading zeros, no `v` prefix, each numeric part ≤ 2^53 − 1. `4.2` and `v4.2.0` are invalid.

Groups: `all` is true when every member is true (an empty `all` is true, used for "everyone" rules); `any` is true when at least one member is true (an empty `any` is false). Evaluation short-circuits; the trace records skipped members as `not_evaluated`.

Segments: excluded `(kind, key)` → not a member; else included `(kind, key)` → member; else the segment's `match` group decides. A context without `key` never matches include or exclude lists. A reference to a segment absent from the snapshot is `ERROR / INVALID_CONFIG`.

#### 3.2.3 Bucketing (`bucket-sha256-v1`)

```text
field(s)  = u32be(byteLength(utf8(s))) ‖ utf8(s)
input     = field("bitflag-bucket-v1") ‖ field(salt) ‖ field(kind) ‖ field(bucketByValue)
digest    = SHA-256(input)
n         = unsigned 64-bit big-endian integer from digest[0..7]
bucket    = n mod 100000
```

- `bucketByValue` is the context `key` when `bucketBy` is `key`; otherwise the named attribute, which must be a non-empty string. Absent, empty, or not a string gives `ERROR / MISSING_SUBJECT_KEY` with the caller fallback. No random cohort is ever assigned.
- `kind` is always the context kind, also when bucketing by an attribute.
- Not in the input: revision, flag key, project, environment, names or slugs (protocol §5).
- PHP has no unsigned 64-bit integer, so the evaluator computes `hi = u32be(digest[0..3])`, `lo = u32be(digest[4..7])`, `bucket = ((hi mod 100000) × 67296 + lo) mod 100000`, where 67,296 = 2^32 mod 100,000. Every intermediate fits in a signed 64-bit integer. JavaScript will use `BigInt`.
- Modulo bias: 2^64 mod 100,000 = 51,616, so buckets 0 to 51,615 each have one extra preimage out of about 1.8 × 10^14. Accepted and documented (protocol §5).

Worked vector (computed from this definition; the released vector file is generated by tooling and cross-checked by both languages):

| Field | Value |
| --- | --- |
| salt, kind, value | `s_9f2c`, `user`, `u_20931` |
| input hex | `00000011 626974666c61672d6275636b65742d7631 00000006 735f39663263 00000004 75736572 00000007 755f3230393331` |
| digest[0..7] | `9f9eda90f9168dca` (n = 11501870814539976138) |
| bucket | 76,138 |
| Same salt and kind, `José` NFC vs NFD | 60,341 vs 99,140 (they must differ) |

Slots: slot *i* covers `[sum of earlier weights, sum including its own)`. A bucket at or beyond the total weight gets `remainderVariation`. Weight-0 slots cover nothing. The trace records `{ variation, start, end }` of the chosen slot, which is what the Design System shows as "14,203 < 20,000".

#### 3.2.4 Overrides and the clock

- Override match is exact on `(kind, key)`; the context must have a `key`.
- An override is active when `expiresAt` is null or `at < expiresAt`. At exactly `expiresAt` it is expired.
- `at` comes from `EvaluationOptions::at()` when given (fixtures and the simulator), else from the injected `BitFlag\Evaluation\Clock`. The result's `evaluatedAt` is always that same instant, so a fixed clock gives reproducible results (domain model "Rules and segments": overrides use a supplied evaluation time).

#### 3.2.5 Prerequisites

- Evaluator ceiling: depth 10 counted from the evaluated flag; at most 20 prerequisites per flag. Publication may enforce a lower limit (the M4 draft validates depth 5); the evaluator ceiling only protects against snapshots that bypassed validation.
- Cycles (including self-reference), missing references and depth overflow are `ERROR / INVALID_CONFIG` at evaluation, and `SnapshotValidator` reports them so publication (M5) rejects them first.
- Each prerequisite flag is evaluated once per top-level call (memoized by key), and the trace records the prerequisite path.

#### 3.2.6 Capabilities and versions

- Capabilities known in M1: `operators.v1` (equality, membership, string, numeric, existence), `semver.v1`, `dates.v1`, `segments.v1`, `prerequisites.v1`, `rollouts.v1`, `experiments.v1` (`experimentId` and `allocationId` on rollouts).
- A flag whose `requires` names an unknown capability returns `ERROR / UNSUPPORTED_CAPABILITY`; other flags in the same snapshot still evaluate.
- `protocolVersion` other than 1 and `algorithmVersion` other than `bucket-sha256-v1` reject the whole snapshot (`MALFORMED_SNAPSHOT` and `UNSUPPORTED_CAPABILITY` respectively), because they affect every flag.

#### 3.2.7 Parsing and validation layers

| Layer | Class | Failure |
| --- | --- | --- |
| JSON decoding | `Protocol\Json\JsonDecoder` | Invalid JSON, depth over 64, integer beyond ±(2^53 − 1), size over 5 MB (the parser's hard ceiling; M5 sets the lower publication limit): snapshot rejected, `MALFORMED_SNAPSHOT` |
| Structure (everything the schema says) | `Protocol\SnapshotParser` | Snapshot rejected, `MALFORMED_SNAPSHOT` with a JSON Pointer `path` and a `code` |
| Checksum | `Protocol\Checksum::verify()` | Snapshot rejected, `MALFORMED_SNAPSHOT` with code `CHECKSUM_MISMATCH` |
| References and graphs | `Protocol\SnapshotValidator` | Returns `ValidationIssue[]`. Not a rejection: the SDK keeps the snapshot and the affected flags return `INVALID_CONFIG`; publication (M5) treats any issue as blocking |

A rejected snapshot never replaces an accepted one (protocol §2); that rule is enforced by the client (M5), and the parser simply throws.

#### 3.2.8 Reasons and error kinds

| Error kind | Reason | When |
| --- | --- | --- |
| `FLAG_NOT_FOUND` | `FALLBACK` | Flag absent from the snapshot or projection |
| `TYPE_MISMATCH` | `FALLBACK` | Flag type differs from the requested type |
| `MISSING_SUBJECT_KEY` | `ERROR` | Rollout needs a key or `bucketBy` attribute that is absent or not a non-empty string |
| `INVALID_CONFIG` | `ERROR` | Dangling variation, segment or prerequisite reference, cycle, depth overflow |
| `INVALID_CONTEXT` | `ERROR` | Context fails §3.1 (addition to the protocol's proposed list) |
| `UNSUPPORTED_CAPABILITY` | `ERROR` | §3.2.6 |
| `MALFORMED_SNAPSHOT` | `ERROR` | Used by clients that hold no valid snapshot (M5) |
| `SNAPSHOT_TOO_OLD`, `CLIENT_NOT_READY`, `AUTHORIZATION_REJECTED` | `ERROR` | Defined in the enum and schema now; produced by the client in M5 |

A fallback of the wrong type is a programming error: `FallbackTypeError` (an `InvalidArgumentException`) is thrown and never converted (protocol §3).

#### 3.2.9 Canonical JSON and checksum

- Canonical form is RFC 8785 (JSON Canonicalization Scheme): object keys sorted by UTF-16 code units, no insignificant whitespace, minimal string escaping, numbers in ECMAScript `Number.prototype.toString` form (`1e+21`, `0.000001`, `1e-7`, `-0` written as `0`).
- `checksum = "sha256:" + lowercase hex(SHA-256(canonical(snapshot without the "checksum" member)))`. Everything else in the snapshot, including `revision`, `generation` and `publishedAt`, is covered. HTTP headers and ETags are outside it.
- PHP needs its own ECMAScript number formatter (PHP's `json_encode` writes `1.0e+25`); canonical vectors cover integers, fractions, exponent boundaries (`1e21`, `1e-7`), `-0` and non-ASCII keys.
- Producers must not emit duplicate object keys. PHP and JavaScript both keep the last duplicate, so they agree; consumers are not required to detect duplicates.

#### 3.2.10 Results, details and traces

- `EvaluationOptions::withDetails()` adds `bucket`; `withTrace()` adds the trace. Both are off by default. The SDK client (M5) forbids them on browser and public-projection paths.
- Trace shape: `steps[]` of `{ step: "capability" | "off" | "prerequisite" | "override" | "rule" | "default", outcome, ruleId?, prerequisitePath?, conditions?: [{ path, op, attribute?, segment?, outcome: "match" | "no_match" | "missing" | "null" | "wrong_type" | "invalid_value" | "not_evaluated" }], bucket?, slot?: { variation, start, end }, overrideExpiresAt? }`.
- Traces name attributes and outcomes, never the context values themselves, so a trace can be logged without leaking subject data. The simulator already has the context it sent.

#### 3.2.11 Limits

All limits in this plan (context sizes, rule and condition counts, depths, value list lengths, 500 overrides, 5 MB snapshot) are proposed defaults recorded in `spec/02-snapshot.md` and enforced by schema and parser. Changing one is a protocol change with fixtures.

### 3.3 Library API (no Laravel actions in M1)

`bitflag-php`, namespace `BitFlag\`. Classes in `Internal` namespaces are `@internal` and the app's arch test forbids using them (M0 §5).

| Class | Responsibility |
| --- | --- |
| `Protocol\ProtocolVersion` | `CURRENT = 1`, `SUPPORTED = [1]` |
| `Protocol\Json\JsonDecoder` | Strict decode with `JSON_BIGINT_AS_STRING`, depth and size limits, error mapping |
| `Protocol\Json\CanonicalJson` | `encode(mixed): string` per RFC 8785 |
| `Protocol\Checksum` | `compute(array $snapshot): string`, `verify(array $snapshot): void` |
| `Protocol\SnapshotParser` | `parse(string $json): Snapshot`, `fromArray(array): Snapshot`; throws `MalformedSnapshot(path, code)` or `UnsupportedSnapshot` |
| `Protocol\SnapshotValidator` | `validate(Snapshot): list<ValidationIssue>` (references, cycles, depth, capability union) |
| `Protocol\Model\*` | Readonly value objects: `Snapshot`, `EnvironmentRef`, `Projection`, `Flag`, `FlagType`, `Variation`, `Prerequisite`, `Override`, `Rule`, `ConditionGroup`, `GroupKind`, `Condition`, `Operator`, `FixedServe`, `RolloutServe`, `Slot`, `Segment`, `SubjectRef`, `Capability` |
| `Evaluation\Evaluator` | `new Evaluator(Snapshot, Clock = new SystemClock())`; `evaluate(string $flagKey, Context, FlagType, mixed $fallback, ?EvaluationOptions): EvaluationResult`; typed shortcuts `evaluateBoolean`, `evaluateString`, `evaluateNumber`, `evaluateJson`; `evaluateAll(Context, ?EvaluationOptions): array<string, EvaluationResult>`; `SUPPORTED_CAPABILITIES` |
| `Evaluation\Context` | `Context::fromArray(array)` and `new Context(kind, ?key, attributes)`; `validate()` returns issues; holds normalized values |
| `Evaluation\EvaluationOptions` | `at(DateTimeImmutable)`, `withDetails()`, `withTrace()` (immutable withers) |
| `Evaluation\EvaluationResult` | Readonly result per §3.1; `toArray()` matches `result.schema.json` |
| `Evaluation\Reason`, `Evaluation\ErrorKind` | Backed string enums |
| `Evaluation\Clock`, `SystemClock`, `FixedClock` | Own interface (no `psr/clock` dependency; the SDK client can adapt PSR-20 later) |
| `Evaluation\Bucketing\BucketSha256V1` | `bucket(string $salt, string $kind, string $value): int`; public so tools and the simulator can show "bucket N" |
| `Evaluation\Trace\*` | `EvaluationTrace`, `TraceStep`, `ConditionTrace`, `ConditionOutcome` |
| `Evaluation\Exception\FallbackTypeError` | Thrown for a wrong-type fallback |
| `Evaluation\Internal\*` | `FlagEvaluator`, `PrerequisiteResolver`, `ConditionMatcher`, `SegmentMatcher`, `SlotSelector`, `Operators\{Equality,Membership,StringMatch,Numeric,Semver,Date,Existence}`, `Values\{SemVer,Instant,TypedValue}` |
| `Testing\Conformance\FixtureRunner` | Loads a fixture release directory, runs every case, returns structured pass and fail records; used by `bitflag-php` CI and by `bitflag` |

Invariants enforced in code:

- `Evaluator` never throws for data problems; it returns a result. It throws only `FallbackTypeError`.
- Evaluation does no I/O: no file, network, environment or global state access (arch test), and no `random_*` calls.
- Recursion is bounded by the prerequisite depth and group depth limits, not by PHP's stack.

### 3.4 Routes

None. The delivery endpoints that serve snapshots (`/sdk/v1/snapshot` and others) are M5.

### 3.5 Frontend

None in M1. M4's simulator (`features/evaluation`) renders `EvaluationResult::toArray()` and the trace. TypeScript types for result and trace will be generated from `result.schema.json` in M4 (proposed: `json-schema-to-typescript`), so the portal never hand-copies the contract.

### 3.6 CI and release

**`bitflag-protocol`**

- `ci.yml` (every pull request): `npm ci`; `npm run validate`: compile every schema with Ajv (2020-12, strict mode); validate every fixture file against `fixture.schema.json`; validate every snapshot that a fixture expects to be accepted against `snapshot.schema.json`, and confirm every `accept: false` structural fixture actually fails the schema; unique fixture IDs; every fixture listed in `manifest.json` with its SHA-256; `tools/bucket.mjs` recomputes `bucket-sha256-v1.json` and `tools/canonical.mjs` recomputes canonical vectors and checksums (byte equality required). Then markdownlint and a link check.
- Fixture `checksum` fields: snapshots in fixtures carry a correct checksum unless the fixture tests a mismatch; `tools/checksum.mjs --write` updates them, and CI fails if any is stale.
- `release.yml` (on tag `vX.Y.Z[-pre]`): runs CI, builds `bitflag-protocol-X.Y.Z.tar.gz` with `spec/`, `schemas/`, `fixtures/`, `manifest.json` and `VERSION`, writes `SHA256SUMS`, and creates a GitHub release. It refuses to run if a release with that tag exists, so releases are immutable.
- Versioning: SemVer for the package. Until D03 closes, releases are `0.Y.Z-alpha.N`. Any change to an expected result bumps the minor version and is labelled "result-affecting" in `CHANGELOG.md`. `protocolVersion` stays `1` and `algorithmVersion` stays `bucket-sha256-v1` during 0.x; after the first stable release, protocol §10 applies (a result-changing change needs a new algorithm or capability version).

**`bitflag-php`**

- `protocol.lock.json` at the repo root: `{ "version": "0.1.0-alpha.3", "url": "https://github.com/…/releases/download/v0.1.0-alpha.3/bitflag-protocol-0.1.0-alpha.3.tar.gz", "sha256": "…" }`.
- `scripts/fetch-protocol-fixtures.sh` downloads the archive, checks `sha256sum -c`, and extracts to `build/protocol/{version}/` (gitignored). A mismatch fails the job. CI authenticates to the private release with a read-only token secret until D13 makes it public.
- `ci.yml`: matrix of supported PHP versions × lowest and highest dependencies; `composer validate --strict`; check that `require` contains only `php`; Pint; PHPStan level max with strict rules; Pest unit, arch and conformance suites; Infection mutation testing on `src/Evaluation` and `src/Protocol` with a minimum MSI of 85% (proposed).
- `composer.json` records the fixture release in `extra.bitflag.protocol` so consumers can check it.
- Releases: tags `v0.1.0-alpha.N`, created only from green `main`.

### 3.7 Security and isolation

- The evaluator is pure: an arch test asserts `BitFlag\Evaluation` and `BitFlag\Protocol` use only `BitFlag\` and PHP built-ins, and that they don't call `file_get_contents`, `fopen`, `curl_*`, `getenv`, `$_SERVER` or `random_*`.
- Every input is bounded (snapshot size, JSON depth, group depth, list sizes, prerequisite depth), so a hostile or broken snapshot can't exhaust memory or the stack.
- No regular expressions or scripts are evaluated from snapshots; the only regexes are fixed patterns in the parser, written without nested quantifiers.
- Traces never contain context values; `bucket` and traces are opt-in.
- Fixtures contain only synthetic identifiers (`u_1`, `acme.dev` addresses). A CI check rejects email domains other than `acme.dev` and `example.com` in fixtures.

### 3.8 Repository layouts

```text
bitflag-protocol/
  spec/00-overview.md 01-context.md 02-snapshot.md 03-evaluation.md 04-operators.md
       05-bucketing.md 06-results-and-errors.md 07-canonical-json-and-checksum.md
       08-capabilities-and-versioning.md 09-telemetry.md 10-conformance.md
  schemas/v1/common context snapshot result telemetry-batch telemetry-ack fixture bucket-vectors (.schema.json)
  fixtures/manifest.json
  fixtures/snapshots/*.json
  fixtures/evaluation/{category}/{name}.json
  fixtures/snapshot/{name}.json
  fixtures/bucketing/bucket-sha256-v1.json
  fixtures/canonical/{name}.json
  tools/validate.mjs manifest.mjs bucket.mjs canonical.mjs checksum.mjs
  CHANGELOG.md  package.json  .github/workflows/ci.yml release.yml

bitflag-php/
  src/Protocol/…  src/Evaluation/…  src/Testing/Conformance/…
  tests/Unit/{Protocol,Evaluation}/…  tests/Conformance/FixturesTest.php  tests/Arch/…
  protocol.lock.json  scripts/fetch-protocol-fixtures.sh  composer.json
```

**Fixture categories.** Each row lists example fixture IDs; every operator gets match, no match, missing, `null` and wrong-type cases, and the negated form of each.

| Category | Example fixtures |
| --- | --- |
| `precedence` | `off-serves-off-variation`, `override-beats-rules`, `first-matching-rule-wins`, `disabled-rule-skipped`, `default-when-no-rule-matches` |
| `fallback` | `flag-not-found`, `type-mismatch-string-requested`, `archived-flag-absent`, `wrong-type-fallback-throws` |
| `context` | `missing-kind`, `numeric-key-invalid`, `attribute-named-key-invalid`, `too-many-attributes`, `mixed-type-array-invalid` |
| `operators/{op}` | `eq/number-int-vs-float`, `eq/string-never-equals-number`, `neq/missing-attribute`, `in/array-any-element`, `not_in/empty-array`, `ends_with/email-domain`, `contains/ignore-case-ascii-only`, `gte/string-numeral-no-match`, `exists/null-counts-as-present` |
| `semver` | `prerelease-precedence`, `build-metadata-ignored`, `two-part-version-invalid`, `v-prefix-invalid` |
| `dates` | `offset-normalized-to-utc`, `fraction-truncated-to-ms`, `date-only-invalid`, `before-is-strict` |
| `strings-unicode` | `nfc-vs-nfd-differ`, `turkish-dotless-i-not-folded`, `emoji-key` |
| `numbers` | `negative-zero`, `max-safe-integer`, `beyond-safe-integer-wrong-type` |
| `groups` | `empty-all-matches`, `empty-any-fails`, `nested-any-in-all`, `depth-limit` |
| `segments` | `exclude-beats-include`, `include-beats-conditions`, `not-in-segment`, `missing-segment-invalid-config`, `no-key-skips-lists` |
| `rollouts` | `bucket-zero`, `bucket-99999`, `slot-lower-edge-inclusive`, `slot-upper-edge-exclusive`, `zero-percent`, `hundred-percent`, `remainder-variation`, `raise-only-adds-subjects`, `multivariate-reallocation`, `bucket-by-attribute`, `missing-key-error`, `bucket-by-number-attribute-error` |
| `overrides` | `exact-kind-and-key`, `other-kind-ignored`, `expires-at-boundary-is-expired`, `expired-falls-through-to-rules` |
| `prerequisites` | `met`, `unmet-serves-off`, `chain-of-three`, `self-cycle-invalid-config`, `two-flag-cycle`, `missing-reference`, `depth-eleven-invalid`, `prerequisite-error-propagates`, `override-does-not-beat-unmet-prerequisite` |
| `experiments` | `experiment-ids-on-result`, `audience-before-allocation` |
| `capabilities` | `unknown-flag-capability`, `other-flags-still-evaluate`, `unknown-algorithm-rejects-snapshot` |
| `snapshot` (snapshot-level) | `invalid-json`, `wrong-protocol-version`, `checksum-mismatch`, `weights-over-100000`, `missing-remainder`, `public-with-overrides`, `integer-beyond-safe-range`, `oversized` |
| `bucketing` vectors | At least 200 vectors: ASCII, long values, multibyte, NFC and NFD pairs, values that land on 0, 51,615, 51,616 and 99,999 (found by search in `tools/bucket.mjs`) |
| `canonical` vectors | Key ordering with non-ASCII keys, numbers, escapes, nested arrays |

Two more example fixtures, to fix the format:

```json
{
  "id": "rollouts/raise-only-adds-subjects",
  "description": "The same subject against the published 20% and the draft 50%: bucket 76,138 is 'off' in both; further cases (not shown) check that every subject 'on' at 20% stays 'on' at 50%.",
  "snapshotRef": "snapshots/new-checkout-20.json",
  "cases": [
    { "name": "u_20931 at 20%", "flagKey": "new-checkout", "type": "boolean", "fallback": false,
      "context": { "kind": "user", "key": "u_20931", "attributes": { "country": "PH", "plan": "pro" } },
      "evaluatedAt": "2026-10-06T08:00:00Z", "options": { "details": true },
      "expect": { "variationKey": "off", "reason": "RULE", "ruleId": "rule_02", "bucket": 76138 } },
    { "name": "u_20931 at 50%", "snapshotRef": "snapshots/new-checkout-50.json", "flagKey": "new-checkout",
      "type": "boolean", "fallback": false,
      "context": { "kind": "user", "key": "u_20931", "attributes": { "country": "PH", "plan": "pro" } },
      "evaluatedAt": "2026-10-06T08:00:00Z", "options": { "details": true },
      "expect": { "variationKey": "off", "reason": "RULE", "ruleId": "rule_02", "bucket": 76138 } }
  ]
}
```

```json
{
  "id": "prerequisites/two-flag-cycle",
  "description": "A cycle that reached an evaluator is an error, never an unmet prerequisite.",
  "snapshot": { "…": "flags a → b → a, checksum valid" },
  "cases": [
    { "name": "evaluate a", "flagKey": "a", "type": "boolean", "fallback": true,
      "context": { "kind": "user", "key": "u_1" }, "evaluatedAt": "2026-10-06T08:00:00Z",
      "expect": { "value": true, "variationKey": null, "reason": "ERROR", "errorKind": "INVALID_CONFIG" } }
  ]
}
```

(The fallback `true` in the cycle case is deliberate: the result must be the caller's value, not a served variation.)

### 3.9 How `bitflag` consumes `bitflag-php`

- **Pinned version.** `bitflag/composer.json` declares a `vcs` repository for `bitflag-php` and requires an exact prerelease, for example `"bitflag/bitflag-php": "0.1.0-alpha.3"`. `minimum-stability` stays `stable`; the exact version string allows the prerelease for this package only. `composer.lock` pins the commit. CI authenticates with a read-only token in `COMPOSER_AUTH`.
- **Local development across repos.** `scripts/dev/link-bitflag-php.sh` adds a `path` repository pointing at `../bitflag-php` (symlinked, `versions` set to the pinned version) and a gitignored `docker-compose.override.yml` that mounts it into Sail. `scripts/dev/unlink-bitflag-php.sh` reverts both. The M0 check `scripts/ci/no-path-repositories.php` fails CI if `composer.json` or `composer.lock` contains a `path` repository, so a link can't be merged.
- **Same fixtures in the app.** `bitflag/protocol.lock.json` pins the same fixture release. `tests/Conformance/ProtocolFixturesTest.php` runs `BitFlag\Testing\Conformance\FixtureRunner` against it, and a second test asserts that `protocol.lock.json` matches `extra.bitflag.protocol` of the installed `bitflag-php`. M4 extends this suite to run fixtures through the simulator path (draft compile, then evaluate).
- **Boundary.** Only `App\Modules\Flags` (simulator, M4) and `App\Modules\Publishing` (compiler in M4, validation and finalization in M5) use `BitFlag\`, and never `Internal` classes (M0 arch test). The backend calls the evaluator directly, never its own delivery API (repository-structure.md).
- **Upgrades.** A Dependabot rule is disabled for `bitflag/bitflag-php`; upgrades are deliberate pull requests that bump `composer.json`, `protocol.lock.json` and the changelog link together.

### 3.10 `bitflag-js` evaluation core

- Repository `bitflag-js` (skeleton from M0), package `@bitflag/js` (name subject to D13), TypeScript strict, ESM with types, no runtime dependencies. `src/protocol` (strict JSON decoding, snapshot parsing and validation, canonical JSON, checksum) and `src/evaluation` (context, evaluation order, operators, segments, `bucket-sha256-v1`, rollouts, prerequisites, overrides with an injected clock, typed results, traces, `evaluateAll` with the bulk contract in §3.2.1). The public API mirrors `bitflag-php` in JavaScript naming: `new Evaluator(snapshot, { clock })`, `evaluate(flagKey, context, type, fallback, options)`, typed shortcuts, `evaluateAll`.
- Independence: the core must not import from `bitflag-protocol/tools`, and it is written from `spec/` rather than ported line by line from PHP, so a spec gap shows up as a fixture disagreement instead of a shared bug.
- JavaScript specifics the fixtures must cover: 64-bit bucket arithmetic with `BigInt`; SHA-256 through a small pure implementation or `crypto.subtle` (async) with the synchronous evaluator using a vendored pure implementation, decided in the first PR and recorded; UTF-8 byte lengths (not UTF-16 code units) for every size limit and length prefix; numbers beyond the safe integer range rejected exactly as in PHP.
- CI: same `protocol.lock.json` and checksum-verified fixture fetch as `bitflag-php`; matrix of supported Node versions (D01) plus one headless browser run of the conformance suite (Vitest browser mode), because browser builds are where engine differences appear; `tsc --noEmit`, ESLint, Vitest, Stryker mutation testing on `src/evaluation` (same 85% proposal as Infection).
- Cross-language check: a CI job in `bitflag-protocol` installs the pinned `bitflag-php` and `bitflag-js` prereleases, runs every fixture through both, and fails on any difference in value, variation, reason, error kind, rule ID or bucket. This job gates every protocol release after `alpha.1`.

## 4. Implementation steps

`bitflag-protocol`:

1. **Spec and schema base.** `spec/00` to `spec/03`, `schemas/v1/common`, `context`, `snapshot`, `result` schemas; Ajv strict compile in CI.
2. **Fixture format and tooling.** `fixture.schema.json`, `tools/validate.mjs`, `tools/manifest.mjs`, `tools/checksum.mjs`, first `precedence` and `fallback` fixtures with `snapshots/basic-boolean.json`.
3. **Bucketing.** `spec/05-bucketing.md`, `bucket-vectors.schema.json`, `tools/bucket.mjs`, `fixtures/bucketing/bucket-sha256-v1.json`.
4. **Canonical JSON and checksum.** `spec/07`, `tools/canonical.mjs`, `fixtures/canonical/*`, checksums written into every fixture snapshot.
5. **Operators.** `spec/04-operators.md`, `fixtures/evaluation/operators/**`, `numbers`, `strings-unicode`, `groups`, `context`.
6. **SemVer and dates.** `fixtures/evaluation/semver/*`, `dates/*`.
7. **Segments, rollouts, overrides.** Corresponding fixture folders and snapshots.
8. **Prerequisites, capabilities, experiments, snapshot-level fixtures.** `spec/06`, `spec/08`, fixture folders.
9. **Telemetry.** `spec/09-telemetry.md`, `telemetry-batch.schema.json`, `telemetry-ack.schema.json`, example batches validated in CI.
10. **Conformance and release.** `spec/10-conformance.md`, `release.yml`, `CHANGELOG.md`; tag `v0.1.0-alpha.1`.

`bitflag-php`:

11. **Protocol model and decoding.** `Protocol\Json\JsonDecoder`, `Protocol\Model\*`, `SnapshotParser` (structure only), `MalformedSnapshot`, unit tests.
12. **Canonical JSON and checksum.** `CanonicalJson` with the ECMAScript number formatter, `Checksum`.
13. **Context and results.** `Context`, `EvaluationOptions`, `EvaluationResult`, `Reason`, `ErrorKind`, `Clock`, `FallbackTypeError`.
14. **Evaluator core.** `Evaluator`, `Internal\FlagEvaluator`: typed fallback, not found, type mismatch, off, overrides with the clock, fixed-variation rules, default.
15. **Conditions.** `ConditionMatcher`, equality, membership, string, numeric and existence operators, groups, missing and wrong-type handling.
16. **SemVer and dates.** `Values\SemVer`, `Values\Instant`, their operators.
17. **Segments.** `SegmentMatcher`, include and exclude precedence.
18. **Bucketing and rollouts.** `BucketSha256V1`, `SlotSelector`, remainder, `MISSING_SUBJECT_KEY`, experiment IDs.
19. **Prerequisites and validator.** `PrerequisiteResolver` (memo, depth, cycles), `SnapshotValidator`.
20. **Traces and details.** `Trace\*`, `withDetails`, `withTrace`, `evaluateAll`.
21. **Conformance runner in CI.** `Testing\Conformance\FixtureRunner`, `tests/Conformance/FixturesTest.php`, `protocol.lock.json`, fetch script, CI job, Infection; tag `v0.1.0-alpha.1` once every fixture passes.

`bitflag`:

22. **Consume the evaluator.** VCS repository and exact version, `COMPOSER_AUTH` secret, link and unlink scripts, `protocol.lock.json`, `tests/Conformance/ProtocolFixturesTest.php`, lock-match test, CI step.

`bitflag-js`:

23. **Protocol model, decoding, canonical JSON and checksum.** `src/protocol/*`, strict decoder, ECMAScript number formatting (native here, so the canonical vectors double as a check on PHP's formatter).
24. **Evaluator core, conditions, SemVer and dates, segments.** Mirrors steps 13 to 17.
25. **Bucketing, rollouts, prerequisites, validator, traces, `evaluateAll`.** Mirrors steps 18 to 20, with `BigInt` bucket arithmetic.
26. **Conformance in CI and the cross-language job.** Node matrix, browser run, Stryker; the `bitflag-protocol` cross-language job; tag `v0.1.0-alpha.1` of `bitflag-js` once every fixture passes.

Fixture authoring and evaluator work interleave: each `bitflag-protocol` step is released as the next `alpha.N` and pinned by the matching `bitflag-php` step, so neither side builds against a floating branch.

## 5. Tests

**`bitflag-protocol`** (Node tooling, run by `npm test`): schema compilation, fixture validation, manifest freshness, bucket and canonical recomputation, checksum freshness, synthetic-data check, and a test that every `ErrorKind` and `Reason` value appears in at least one fixture (coverage of protocol §10).

**`bitflag-php` Pest**

| Suite | Contents |
| --- | --- |
| `tests/Unit/Protocol` | `JsonDecoderTest` (depth, bigints, size), `SnapshotParserTest` (one test per structural rule with the expected JSON Pointer path), `CanonicalJsonTest` (RFC 8785 appendix examples plus number edge cases), `ChecksumTest`, `SnapshotValidatorTest` (each issue type) |
| `tests/Unit/Evaluation` | `EvaluatorPrecedenceTest`, `TypedFallbackTest`, `ContextValidationTest`, one test file per operator family, `SemVerTest` (SemVer 2.0 precedence examples), `InstantTest` (offsets, truncation, leap years, invalid forms), `SegmentMatcherTest`, `BucketSha256V1Test` (worked vector; the 32-bit split agrees with every entry in the vector file, including digests whose top bit is set), `SlotSelectorTest`, `PrerequisiteResolverTest`, `OverrideExpiryTest` with `FixedClock`, `TraceTest` (no context values in serialized trace) |
| Property tests (seeded, 10,000 subjects) | Raising the first slot weight never moves a subject out of it; any weight change moves only subjects between the old and new boundary; buckets are uniform within a chi-square bound over 100,000 keys; evaluation is deterministic across repeated runs and fresh evaluators |
| `tests/Arch` | `BitFlag\Evaluation` and `BitFlag\Protocol` use only `BitFlag\` and PHP built-ins; no I/O or randomness functions; `Internal` classes are `final` and `@internal`; public value objects are `readonly` |
| `tests/Conformance/FixturesTest.php` | Dataset of every case in the pinned release; failure output shows fixture ID, case name and a field diff |

**`bitflag`**: `tests/Conformance/ProtocolFixturesTest.php`, `tests/Conformance/ProtocolLockTest.php`, and the M0 `EvaluatorBoundaryTest` now exercised against real `BitFlag\` classes. No Cypress spec in M1 (no UI); M4's `m4-simulator.cy.ts` reuses fixture cases.

## 6. Decisions

**D03 defaults assumed** (each is written into the spec as "proposed" and covered by fixtures; changing one means editing fixtures and bumping the protocol prerelease):

1. Missing, `null` and wrong-type values make positive and negated operators false; arrays match on any element (positive) or no element (negated); empty arrays count as present (§3.2.2).
2. Strings are case-sensitive and unnormalized; `ignoreCase` folds ASCII A to Z only, so PHP and JavaScript fold identically.
3. One context kind per evaluation; no multi-kind contexts.
4. **Decided (R15):** bucket inputs are salt, kind and the `bucketBy` value only, each length-prefixed with a 4-byte big-endian byte length; first 8 digest bytes; `mod 100000`; modulo bias accepted. The main protocol document now states the same algorithm. Salt uniqueness per allocation is validated by the snapshot validator (`DUPLICATE_SALT`, unless allocations are explicitly linked) and by M4's compiler.
5. Numbers are binary64; integers limited to ±(2^53 − 1); `-0` equals `0`. Exact decimals and large integers must be sent as strings (no numeric operators apply to them).
6. Canonical serialization is RFC 8785; the checksum covers the whole snapshot except `checksum`.
7. Capability negotiation is per flag (`requires`), with the snapshot-level list as their union; unknown protocol or algorithm versions reject the whole snapshot.
8. Dates are RFC 3339 instants with offset, compared at millisecond precision after truncation.
9. SemVer 2.0.0 strict, no `v` prefix, build metadata ignored.
10. Empty `all` is true; empty `any` is false.
11. Prerequisite depth 10, at most 20 per flag; cycles, missing references and overflow are `INVALID_CONFIG`; a prerequisite error propagates.
12. Overrides expire at `expiresAt` (exclusive end).
13. Segment precedence: exclude, then include, then conditions.
14. A rollout whose bucketing value is absent or not a non-empty string returns `MISSING_SUBJECT_KEY`.
15. `remainderVariation` is required when weights total less than 100,000.
16. Additions to the protocol's proposed lists: error kind `INVALID_CONTEXT`; result fields `prerequisiteKey` and `allocationId`; context attributes may not be named `kind` or `key`.
17. Duplicate JSON keys are forbidden for producers and not detected by consumers.
18. Limits in §3.1 and §3.2.11, including 500 overrides per flag and environment from the Flags mockup.

**Other decisions**

| Item | Default assumed |
| --- | --- |
| D01 supported versions | `bitflag-php` supports the PHP versions still in active or security support at its first stable release (assumed 8.3 and newer); the app uses the newest |
| D06 browser exposure | The schema already forbids overrides and include and exclude lists in the `public` projection; the rest of D06 (private attributes, bootstrap) is M5 |
| D09 telemetry | Exposure and conversion events carry subject kind and key as in protocol §9; retention and linkage stay open |
| D13 naming and licensing | Package `bitflag/bitflag-php`, repos and releases private, fixture downloads authenticated |
| R05 | Context field `kind` |

## 7. Done criteria

- `bitflag-protocol` has a tagged prerelease whose archive and `SHA256SUMS` are attached to an immutable GitHub release, with schemas, the written spec (`spec/00` to `spec/10`), and fixtures in every category of §3.8, including every reason and error kind.
- `bitflag-php` passes every fixture in that pinned release in CI on every supported PHP version, verified by archive checksum.
- `composer.json` of `bitflag-php` requires only `php`; the arch test proves `BitFlag\Evaluation` and `BitFlag\Protocol` use nothing outside the package and PHP built-ins.
- The worked bucket vector, the NFC and NFD pair, and the 0 and 99,999 boundary vectors match between the Node tooling and the PHP implementation.
- Property tests for rollout monotonicity and determinism pass.
- `bitflag-js` passes every fixture in the same pinned release on every supported Node version and in a headless browser, and the cross-language job in `bitflag-protocol` reports no difference between the two evaluators. D03 cannot be closed and no protocol release can leave `0.x` before this holds.
- `bitflag` installs the pinned `bitflag-php` prerelease from the VCS repository, runs the same fixture release in its own CI, and rejects any committed `path` repository.

## 8. Risks and open questions

1. **D03 is still open.** Every default in §6 is a proposal. Releases stay `0.x-alpha` and nothing customer-facing depends on them until Shei closes D03.
2. **"Fallback" means two things in the mockups.** The Design System percentage distribution says unallocated traffic "gets the rule's fallback", while the protocol reserves "fallback" for the caller's value and calls this the remainder variation. The editor copy (M4) should say "remainder" (or similar) to keep the three outcomes distinct.
3. **Operator coverage in the editors.** The Flags editor offers only is, is not, is one of, ends with and contains; the Segments editor adds is not one of, at least and at most. Neither offers starts with, exists, SemVer or date operators, though the protocol defines them. M4 needs a decision on which operators the editors expose; the evaluator supports all of them regardless.
4. **Overrides after prerequisites.** Protocol §3 puts prerequisites before overrides, so a QA override can't bypass an unmet prerequisite. The mockup copy "Overrides are checked before any rule" is consistent, but Shei should confirm that this precedence over prerequisites is intended.
5. **Per-flag capabilities.** The protocol leaves "reject the snapshot vs fall back per flag" open. This plan picks per flag, which needs the compiler (M4) to compute each flag's `requires`.
6. **ECMAScript number formatting in PHP** is the most error-prone part of the canonical form; the canonical vectors must be extensive before checksums are trusted.
7. **Private repositories** mean CI tokens for Composer and release downloads until D13; a leaked token would expose unreleased contracts, so tokens are read-only and per repository.
8. **Experiment fields** (`experimentId`, `allocationId`) are in the schema now, but their semantics (exposure attribution) belong to D07; fixtures only check that they are copied onto the result.
