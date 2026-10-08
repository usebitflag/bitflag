# M10. Telemetry and analytics

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Expands M10 in the [implementation plan](../implementation-plan.md).

## 1. Goal and scope

Accept SDK telemetry safely and cheaply, turn it into honest reporting (usage, served values, fallbacks, SDK health, delivery health, cleanup evidence), and store the experiment inputs (exposures and conversions) that M11 analyzes. Telemetry is never allowed to slow or starve publication and delivery.

**In scope**

- `POST /sdk/v1/events`: batch ingestion authenticated with SDK keys (`events:write`), size and rate limits, schema validation, per-event acknowledgement (accepted, retryable, rejected), deduplication by event ID, durable journal before acknowledging.
- Processing pipeline: evaluation aggregates (hourly and daily rollups), flag usage (`last_evaluated_at`), SDK observations (revision, generation, version, snapshot age, streaming, errors), exposure and conversion storage with pseudonymous subject hashes, data-quality counters.
- Delivery request recording on the M5 delivery endpoints (fetch counts, `304` ratio, `401`/`403`/`429` error samples) for the environment Delivery tab.
- Read models for Analytics (1a, 1b and states), the environment Delivery tab and delivery badges (Environments, Overview), the Overview stat tiles, the onboarding "Evaluation reported" check, the flag list "Last evaluated" column, and cleanup evidence (stale flags).
- Storage design on MySQL (separate `telemetry` connection and schema) with an explicit seam for a later analytics store (D08), and retention jobs with stated defaults (D09).
- Queue isolation: a separate Horizon supervisor and Redis connection for telemetry, plus ingestion backpressure.
- `bitflag-php` minimal telemetry emitter (evaluation summaries, `sdk_status`, `trackExposure`, `track`) so CI and onboarding exercise real ingestion; telemetry schemas and fixtures in `bitflag-protocol`.
- Subject data deletion for exposures and conversions.

**Out of scope**

- Experiment analysis, results and decisions (M11). M10 stores and serves the raw inputs through `ExperimentEventStore`.
- The streaming runtime and live stream counts from a stream server (D04). Until then "streams" are SDK-reported streaming instances.
- The archive workflow itself (M4 owns flag lifecycle). Analytics links to it.
- Full JavaScript SDK telemetry (`bitflag-js`, M13). The schema is shared, so it plugs in unchanged.
- A dedicated analytics database (D08). Only the seam and the migration path are built.

## 2. Mockups covered

| Mockup | Artboard | Behavior implied |
| --- | --- | --- |
| BitFlag Analytics | 1a Everything on one page | Built (the portal prototype follows it). Environment from the sidebar switcher, range 24 hours / 7 days / 30 days. Tiles: Flag checks (range), Flags checked "6 of 9 active flags", SDKs connected "right now", Fallbacks used with % of checks. "Flag checks per day" (per hour for 24 h) with publish markers and the note "A publish. Checks often jump after one because SDKs refresh." Per-flag table: checks, values served as share bars, fallback %. "Not checked in 30 days" list with evidence and Archive. Connected SDKs table: SDK, version (with Update tag), instances, "On live rev" (All / "11 of 12" / "None, stuck on rev 39") |
| BitFlag Analytics | 1b One flag at a time | Built as the flag drill-down: flag search list, tiles Checks, Unique users, Serving share with rule, Fallbacks; values served over time; breakdown by rule |
| BitFlag Analytics | 1c Health and cleanup first | Not built as a layout. Its three callouts ("3 flags to clean up", "payment-config falls back 0.61% of the time", "1 SDK needs an update") come from the same queries and feed M5's Overview "Needs attention" |
| BitFlag Analytics | 2a Archive a stale flag | Dialog copy "If any code still asks for it, the SDK returns that code's fallback value. You can restore it from the flag's page." Confirm calls M4's archive workflow |
| BitFlag Analytics | 2b Empty: no SDK connected | "No flag checks yet … It usually takes a minute after your first deploy." Create an SDK key, three-step quickstart |
| BitFlag Analytics | 2c Viewer, read-only | Everything readable; Archive disabled with the role banner |
| portal/Analytics | prototype | Fallback definition copy; "Every flag was checked recently." empty stale list; tooltips per bar; Update tag when a version is behind the advisory |
| BitFlag Overview / portal/Overview | 1a header and tiles | "Delivery healthy · 38 SDK connections · snapshot 4s old"; tiles "Live streams 6 reported in last 5 min", "Evaluations · 24h reported by SDKs", "Fallbacks % of evaluations"; "Counts … as of 10:42 (UTC+8). Evaluation data can lag by up to 5 minutes." Needs attention "old-onboarding No evaluations in 30 days". Environments table Delivery column. Onboarding verification "Evaluation reported: Your code checked new-checkout and BitFlag received it." |
| BitFlag Environments | 1a, 1b, 1c, 2f | Delivery column "Healthy · 38 streams"; detail tiles "Live streams 38 SDKs connected now", "Max snapshot age 24 hours" |
| portal/Settings | Environment Delivery tab | Tiles Snapshot (size, flags, rev), Live streams (SSE connections), Fetches · 24 h with "% answered 304 Not Modified", Snapshot age "oldest connected SDK". "Recent fetch errors last 24 h": 401 revoked key with label, "14 requests from 3 IPs"; 403 browser key from a disallowed origin; 429 rate limited by key. "No errors in the last 24 hours." |
| BitFlag Flags | flag list | "Last evaluated" column |
| BitFlag Settings | project General | "Mark as stale after 30 days without checks. Stale flags show up in Analytics as safe to clean up." |

## 3. Design

### 3.1 Data model

Telemetry tables live on a separate database connection `telemetry` (schema `bitflag_telemetry`, same MySQL server at first). Tenant columns and `organization_id`-first indexes follow the conventions. Cross-connection joins are forbidden; read models join in PHP. Nullable dimensions use `''` instead of `NULL` so that MySQL unique keys work.

**`ingest_batches`** (the durable journal)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | |
| `organization_id`, `project_id`, `environment_id`, `credential_id` | ulid | From the authenticated key, never from the body |
| `source` | enum(`server`,`browser`) | From the key type |
| `sdk_name`, `sdk_version` | varchar(40) | Validated pattern |
| `instance_hash` | binary(16) | Truncated SHA-256 of the SDK instance ID |
| `received_at` | timestamp(6) | |
| `event_count` | smallint | Accepted events only |
| `payload` | mediumblob | gzip JSON of the validated events, subject keys already replaced by hashes |
| `status` | enum(`pending`,`processing`,`processed`,`failed`) | |
| `attempts`, `processed_at`, `last_error` | | |

Indexes `(status, received_at)`, `(organization_id, environment_id, received_at)`.

**`telemetry_event_receipts`**: `organization_id`, `environment_id`, `event_id` varchar(64), `received_on` date. Primary key `(organization_id, environment_id, event_id)`. Written in the same transaction as the effect of the event, so a retried batch never double counts.

**`evaluation_aggregates`** (hourly)

| Column | Type |
| --- | --- |
| `organization_id`, `project_id`, `environment_id` | ulid |
| `bucket_start` | timestamp (hour) |
| `flag_key` | varchar(100) |
| `flag_id` | ulid or `''` (unknown keys keep `''`) |
| `variation_key` | varchar(100) or `''` |
| `reason` | enum(`OFF`,`PREREQUISITE`,`OVERRIDE`,`RULE`,`DEFAULT`,`FALLBACK`,`ERROR`) |
| `rule_id` | varchar(64) or `''` |
| `error_kind` | varchar(40) or `''` |
| `sdk_name`, `sdk_version` | varchar(40) |
| `source` | enum(`server`,`browser`) |
| `count` | bigint unsigned |
| `estimated` | bool (true when any contributing counter was sampled) |

Unique key over every dimension, starting with `organization_id, environment_id, bucket_start`. Increments use `INSERT … ON DUPLICATE KEY UPDATE count = count + VALUES(count)`.

**`evaluation_aggregates_daily`**: same dimensions with `bucket_date` and without `sdk_version` (kept as `sdk_name` only); built by rollup.

**`flag_usage`**: `organization_id`, `project_id`, `environment_id`, `flag_key`, `flag_id`, `first_evaluated_at`, `last_evaluated_at`. Primary key `(organization_id, environment_id, flag_key)`.

**`sdk_observations`** (5-minute buckets) and **`sdk_observations_hourly`** (M10's own bucketed tables, distinct from M5's per-credential `sdk_credential_observations`)

| Column | Notes |
| --- | --- |
| `organization_id`, `project_id`, `environment_id`, `credential_id` | |
| `bucket_start` | 5-minute or hour |
| `sdk_name`, `sdk_version`, `source` | |
| `revision`, `generation` | Reported by the SDK |
| `instance_count` | From a Redis HyperLogLog of instance hashes per bucket and group |
| `streaming_count` | Instances reporting `streaming: true` |
| `max_snapshot_age_ms` | Oldest snapshot among reporting instances |
| `error_counts` | json, error kind → count |
| `dropped_events` | Reported by SDKs whose buffer overflowed |

Unique `(organization_id, environment_id, bucket_start, credential_id, sdk_name, sdk_version, source, revision, generation)`.

**`delivery_request_rollups`**: `organization_id`, `environment_id`, `credential_id` (or `''`), `bucket_start` (hour), `endpoint` enum(`snapshot`,`stream`,`evaluate`,`events`), `status_class` enum(`200`,`304`,`401`,`403`,`429`,`4xx`,`5xx`), `count`.

**`delivery_error_samples`**: `organization_id`, `environment_id`, `credential_id`, `bucket_start` (hour), `status_code`, `error_code` (`CREDENTIAL_REVOKED`, `ORIGIN_NOT_ALLOWED`, `SCOPE_MISSING`, `RATE_LIMITED`), `origin` varchar(255) or `''`, `request_count`, `ip_count` (from a HyperLogLog of salted IP hashes; raw IPs are never stored), `last_seen_at`. Requests with unknown keys have no tenant and only feed global metrics.

**`exposure_events`**

| Column | Notes |
| --- | --- |
| `organization_id`, `project_id`, `environment_id` | |
| `event_id` | varchar(64); unique `(organization_id, environment_id, event_id)` |
| `experiment_id`, `allocation_id` | From the SDK result (`experimentId`, `allocationId`) |
| `subject_kind` | varchar(40) |
| `subject_hash` | binary(32), HMAC-SHA256 with the environment pepper |
| `flag_key`, `variation_key`, `revision`, `generation` | |
| `event_time`, `received_at` | Both stored (domain model "Time, retention and data classification") |
| `source`, `sdk_name` | |

Index `(organization_id, experiment_id, subject_hash, event_time)`.

**`conversion_events`**: `organization_id`, `project_id`, `environment_id`, `event_id` (unique as above), `event_name` varchar(100), `subject_kind`, `subject_hash`, `value` decimal(20,6) null, `event_time`, `received_at`, `source`. Index `(organization_id, environment_id, event_name, subject_hash, event_time)`. Stored only when the event name is used by a metric of a running experiment in that environment (or one stopped whose `final_at`, M11 §3.2, has not passed); otherwise only `conversion_event_names` is updated.

**`conversion_event_names`**: `organization_id`, `project_id`, `environment_id`, `event_name`, `first_seen_at`, `last_seen_at`, `count_total`. No subject data. Feeds metric setup in M11 ("Connect analytics events").

**`telemetry_quality_counters`**: `organization_id`, `environment_id`, `day`, `counter` enum(`rejected_schema`, `rejected_time_window`, `exposure_unknown_experiment`, `exposure_after_stop`, `conversion_without_metric`, `clock_skew_corrected`, `sdk_dropped_events`, `unknown_flag_key`), `count`.

**Main database tables**

- **`environment_telemetry_keys`**: `organization_id`, `environment_id`, `key_version`, `pepper_ciphertext`, `created_at`, `retired_at`. One active pepper per environment; rotating it breaks linkage, so rotation is blocked while an experiment in that environment is running. A retired pepper is kept (encrypted) until no retained row was hashed with it, because deletion must recompute the subject's hash under every version still present.
- **`subject_deletion_requests`**: `organization_id`, `environment_id`, `subject_kind`, `requested_by_user_id`, `requested_at`, `completed_at`, `rows_deleted` json. **`subject_deletion_hashes`**: `deletion_request_id`, `organization_id`, `environment_id`, `key_version`, `subject_hash`; one row per pepper version of the environment that still has data (active and retired), unique `(organization_id, environment_id, key_version, subject_hash)`. Only hashes are stored. These rows are also the **tombstones** checked during processing (see Subject deletion) and are kept for the longest raw-telemetry retention plus 30 days.
- Reads M3's `projects.stale_after_days` (default 30), set in the Settings "New flags" section.

**Retention defaults (D09 open; values are defaults to be confirmed)**

| Data | Default |
| --- | --- |
| `ingest_batches` (contains subject hashes, never raw keys) | 7 days |
| `telemetry_event_receipts` | 8 days (longer than the accepted lateness window) |
| `evaluation_aggregates` hourly | 35 days |
| `evaluation_aggregates_daily`, `flag_usage` | 13 months |
| `sdk_observations` 5-minute / hourly | 7 days / 90 days |
| `delivery_request_rollups` / `delivery_error_samples` | 90 days / 7 days |
| `exposure_events`, `conversion_events` | While the experiment runs, then 90 days after it stops; M11's computed results are kept |
| `conversion_event_names`, `telemetry_quality_counters` | 13 months |

### 3.2 Domain rules and state machines

**Batch format** (proposal for the `bitflag-protocol` telemetry schema, D03/D09):

```json
{
  "schemaVersion": 1,
  "sentAt": "2026-10-06T08:52:11.000Z",
  "sdk": { "name": "bitflag-php", "version": "2.2.0", "instanceId": "a3f1c0de-…", "runtime": "php-8.4" },
  "events": [
    { "id": "01JAB…", "type": "evaluation_summary", "startAt": "…", "endAt": "…", "revision": 42, "generation": 1, "sampleRate": 1,
      "counters": [ { "flagKey": "new-checkout", "variationKey": "on", "reason": "RULE", "ruleId": "rule_02", "errorKind": null, "count": 1834 } ] },
    { "id": "01JAC…", "type": "exposure", "timestamp": "…", "flagKey": "checkout-layout", "variationKey": "b", "revision": 42, "generation": 1,
      "experimentId": "exp_…", "allocationId": "alc_…", "context": { "kind": "user", "key": "u_20931" } },
    { "id": "01JAD…", "type": "conversion", "timestamp": "…", "event": "order_completed", "value": 1250.0, "context": { "kind": "user", "key": "u_20931" } },
    { "id": "01JAE…", "type": "sdk_status", "timestamp": "…", "revision": 42, "generation": 1, "lastValidatedAt": "…",
      "snapshotAgeMs": 4000, "streaming": true, "errorCounts": { "SNAPSHOT_TOO_OLD": 0 }, "droppedEvents": 0 }
  ]
}
```

- Event `id`: 1 to 64 characters `[A-Za-z0-9_-]`, generated by the SDK, stable across retries.
- Exposures and conversions send only `kind` and `key` from the context, never attributes (protocol §9).
- `instanceId` is random per process start (browser: per page load), not derived from any user.

**Limits** (initial values, confirmed by M12 load tests, D08):

| Limit | Server key | Browser key |
| --- | --- | --- |
| Body after decompression (gzip accepted) | 1 MB | 128 KB |
| Events per batch | 1,000 | 100 |
| Counters per summary | 1,000 | 200 |
| Event time window | `received_at − 7 days` to `received_at + 5 min` | same |

**Acknowledgement.**

```json
{ "accepted": 997, "retryable": [ { "id": "01JAF…", "code": "RATE_LIMITED" } ],
  "rejected": [ { "id": "01JAG…", "code": "TIMESTAMP_OUT_OF_RANGE", "message": "timestamp is more than 7 days old" } ],
  "retryAfterMs": 30000, "request_id": "req_…" }
```

| HTTP | Meaning | SDK behavior |
| --- | --- | --- |
| `200` | Batch journaled; per-event lists say what to resend (`retryable`) or drop (`rejected`). Everything else, including duplicates, counts as accepted | Drop accepted and rejected; resend retryable after `retryAfterMs` |
| `400 MALFORMED_BATCH` | Not JSON or top-level schema invalid | Drop the batch, report locally |
| `401` / `403` | Missing, invalid or revoked key; key lacks `events:write`; browser origin not allowed | Stop sending with this key (protocol §6) |
| `413 BATCH_TOO_LARGE` | Over the size limit | Split and resend |
| `429 RATE_LIMITED` | Whole batch over the limit, `Retry-After` set | Back off with jitter |
| `503 INGEST_UNAVAILABLE` | Journal unavailable or backpressure | Back off with jitter, keep bounded buffer |

Per-event `rejected` codes: `INVALID_EVENT` (schema, with field path), `UNSUPPORTED_EVENT_TYPE`, `TIMESTAMP_OUT_OF_RANGE`, `EVENT_TOO_LARGE`, `UNKNOWN_SCHEMA_VERSION`. Per-event `retryable` codes: `RATE_LIMITED` (the token bucket ran out partway through the batch: the first events are accepted, the rest retryable).

**Durability boundary.** An event is acknowledged as accepted only after the `ingest_batches` row commits. Request-time work is limited to: authenticate (cached credential lookup), origin check for browser keys, decompress, validate, hash subject keys with the environment pepper, apply the rate limit, insert one journal row. No other database writes happen in the request.

**Processing** (`ProcessIngestBatch`, idempotent):

1. Claim the batch (`pending` → `processing` with `SELECT … FOR UPDATE SKIP LOCKED`).
2. For each event, in one transaction per batch on the `telemetry` connection: insert the receipt (`INSERT IGNORE`; a duplicate skips the event), then apply the effect:
   - `evaluation_summary`: resolve `flagKey` to `flag_id` through `FlagKeyResolver` (cached per project; unknown keys kept as strings, capped at 1,000 distinct unknown keys per environment per day, extra ones only counted in `unknown_flag_key`); increment the hourly bucket of `endAt`; if `sampleRate < 1`, scale counts and set `estimated`.
   - `exposure`: look up the experiment through `ExperimentRegistry` (interface owned by M10, implemented by M11; the default null implementation treats every experiment as unknown). Store if the experiment belongs to this environment and `event_time` is between its start and stop; otherwise count `exposure_unknown_experiment` or `exposure_after_stop`.
   - `conversion`: store if `ExperimentRegistry::metricEventNames(environment)` contains the name; always upsert `conversion_event_names`.
   - `sdk_status`: `PFADD` the instance hash into the Redis HyperLogLog for its 5-minute group; keep max snapshot age, streaming and error counts in a Redis hash; `FlushSdkObservations` writes the bucket every minute.
3. Update `flag_usage.last_evaluated_at` for flags with non-zero counts.
4. Mark processed. A failure leaves the batch `pending` with `attempts + 1`; after 5 attempts it becomes `failed` and raises an alert (M12).

**Clock skew.** A client timestamp more than 5 minutes ahead is rejected; one within the window but ahead of `received_at` is clamped to `received_at` and counted as `clock_skew_corrected`. Event time and ingestion time stay separate; late events land in their event-time bucket and never move an analysis window (M11 uses a watermark).

**Subject hashing.** `subject_hash = HMAC-SHA256(pepper(environment, key_version), kind + "\x00" + key)`, computed at request time, so raw subject keys are never written anywhere. Rows store the `key_version` that produced their hash.

**Subject deletion contract.** `DeleteSubjectTelemetry` takes `kind` and `key` once, computes the hash under every retained pepper version, stores those hashes as tombstones, then discards the raw key. Deletion must hold against every path that could bring the data back:

| Path | Rule |
| --- | --- |
| Stored exposures and conversions | Deleted in chunks for every tombstoned `(key_version, subject_hash)` |
| Pending or replayed journal batches (`ingest_batches`, sweeper retries, manual replay) | `ProcessIngestBatch` drops any event whose hash matches a tombstone and whose `event_time ≤ requested_at`, and counts it as `deleted_subject_dropped`. Events with a later `event_time` are new activity and are processed normally |
| Derived experiment data | M11 recomputes the current and, if not yet final, the final result for every experiment in the environment that had exposures for the subject. Earlier `experiment_result_snapshots` hold only per-variant aggregates, never subject-level data, and stay as history |
| Evaluation aggregates and SDK observations | Contain no subject data; unaffected |
| Database restore | Tombstones created after the restore point are recovered from the recovery journal (M12) and re-applied before telemetry processing resumes |

A deletion is `completed` only when the stored rows are gone; the tombstones keep working after that.

**Delivery health** (`EnvironmentDeliveryHealth`, thresholds in `config/analytics.php`, tuned under D08):

| State | Condition (first match) | Label |
| --- | --- | --- |
| `never_published` | No revision (M5 pointer) | "Never published" |
| `degraded` | Publication outbox message for this environment pending longer than 60 s, or `5xx` above 1% of delivery requests in the last 15 min | "Delivery degraded" |
| `no_sdks` | No observations and no delivery requests in the last 15 min | "No SDKs reporting" |
| `behind` | A reporting group has been on an older `(generation, revision)` for more than 10 min after the latest publish | "1 SDK group behind" |
| `healthy` | Otherwise | "Healthy · N streams" |

"Streams" means instances reporting `streaming: true` in the last 5 minutes until the D04 stream runtime reports connection counts directly; the label stays "reported by SDKs". Snapshot age is the maximum `snapshotAgeMs` reported in the latest bucket ("oldest connected SDK").

**"On live rev"** per SDK group: instances on the current `(generation, revision)` versus all instances in the latest bucket; "None, stuck on rev 39" when the group reports only older revisions. "Update" appears when the version is below `config('analytics.sdk_advisories')` (minimum recommended version per SDK, maintained with SDK releases).

**Cleanup evidence** (`StaleFlagCandidates` for one environment):

- Flag lifecycle `active` or `deprecated`, lifecycle intent `temporary` (permanent flags are never suggested), published in this environment at least `stale_after_days` ago.
- No evaluations in this environment for `stale_after_days`, and the environment has reporting coverage (SDK observations on at least `stale_after_days − 1` days of the window). Without coverage the flag is listed under "Not enough data to judge" instead.
- Evidence shown: last checked date or "never", serving state across environments ("off everywhere", "serves true to everyone", "rules in 2 environments"), code references from M9 (`CodeReferenceSummary`: "0 code references in acme/storefront@main, scanned 2 h ago" or "No repository connected"), owner.
- The card always says "Probably safe to clean up. Remove the code first, then archive the flag." and never presents absence of checks as proof (frontend doc §6).

**Stat definitions** used everywhere (one query class each, so Overview, Analytics and Environments agree):

| Stat | Definition |
| --- | --- |
| Flag checks | Sum of `count` in range for the environment |
| Flags checked | Distinct flags with checks in range / flags in `active` or `deprecated` lifecycle |
| SDKs connected right now | Sum of `instance_count` over the latest complete 5-minute bucket |
| Fallbacks used | Checks with reason `FALLBACK` or `ERROR`, and their share of all checks |
| Unique users (1b) | Not collected by default (D09); tile shows "Not collected" with a link to the explanation |
| By rule (1b) | Checks per `rule_id` and variation (not users) until D09 allows unique counts |
| Data freshness | Time of the newest processed batch; "Evaluation data can lag by up to 5 minutes" is a target verified in M12 |

### 3.3 Actions

`app/Modules/Analytics/Actions`:

| Action | Input | Capability | Transaction contents | Audit / outbox |
| --- | --- | --- | --- | --- |
| `AcceptEventBatch` | M3's `ResolvedSdkCredential`, raw body, headers | Key scope `events:write`; browser origin | Validate, hash, rate limit, insert journal row (`telemetry`) | None (volume). Metrics only |
| `ProcessIngestBatch` | batch ID | System | §3.2 processing | None; `internal.environment.first_evaluation_observed` the first time an environment sees any evaluation, `internal.sdk_key.first_event` per credential |
| `BuildUsageAggregate` | day | System | Daily rollup from hourly | None |
| `FlushSdkObservations` | bucket | System | Write observation rows from Redis | None |
| `RecordDeliveryRequest` | environment, credential, endpoint, status, origin, IP | System (middleware after response) | Redis counters only; flushed by `FlushDeliveryRollups` | None |
| `DetectStaleFlags` | project | System (daily) | Compute candidates, remember notified flags in `flag_usage` | `internal.flag.unused_detected` per flag owner, at most once per flag per 30 days (M8 in-app) |
| `DeleteSubjectTelemetry` | environment, kind, key | New capability `telemetry.subject.delete` (Owner); also `subjects:delete` management scope | Insert request, delete rows in batches (job), complete | `subject_data.deleted` (hash prefix and counts only) / `internal.experiment.data_changed` for M11 |
| `RotateTelemetryPepper` | environment | `project.manage`; blocked while an experiment runs there | New key version, retire old | `telemetry.pepper_rotated` |
| `PruneTelemetry` | now | System (daily) | Delete beyond retention in chunks of 10,000 | Records a `retention_runs` row (M12) |

Capability added: `telemetry.subject.delete` (Owner only).

### 3.4 Routes

**SDK (`routes/sdk.php`)**

```text
POST /sdk/v1/events      Authorization: Bearer bf_srv_… | bf_pub_…
                         Content-Encoding: gzip (optional), Content-Type: application/json
```

Middleware order: request ID, `AuthenticateSdkKey` (from M5, wrapping M3's `SdkKeyAuthenticator`; scope `events:write`), `EnforceBrowserOrigin` for `bf_pub_` (CORS preflight answered for allowed origins only), `TelemetryRateLimit` (Redis token bucket per credential and per environment, on the `telemetry` Redis connection), body size guard before decompression, `AcceptEventBatch`. `RecordDeliveryRequest` is attached as terminable middleware to every `routes/sdk.php` route, including M5's snapshot and stream routes.

This handler supersedes M5's minimal `POST /sdk/v1/events` behind the same route. It accepts the same `evaluation_summary` shape (plus the other event types), keeps writing M5's per-credential `sdk_credential_observations` markers (first evaluation, evaluated flag keys) that onboarding reads, and records its own bucketed `sdk_observations`, which are a separate table.

**Portal (`routes/web.php`, under M0's prefix `/{organization}/{project}/{environment}`, written here as `…`)**

| Method and path | Page | Props (summary) |
| --- | --- | --- |
| `GET …/analytics?range=24h\|7d\|30d` | `analytics/Index` | `environment`, `range`, `asOf`, `coverage` (reporting instances, estimated flag, quality counters), `stats[]`, `series[]` (bucket, count, `publishedRevision` marker), `flags[]` (key, checks, `values[]` share, fallback rate), `stale[]` and `insufficientData[]`, `sdks[]` (name, version, instances, onLive, behindRevision, updateAdvised), `empty` (no data ever), `can.archiveFlags` |
| `GET …/analytics/flags/{flag}?range=` | `analytics/FlagShow` | Flag summary tiles, `seriesByVariation[]`, `byRule[]`, `fallbacksBySdk[]` |
| `GET …/settings/environments/{target}/delivery` | Props for M3's environment detail Delivery tab | Snapshot size and flags (M5), streams, fetches 24 h and 304 share, max snapshot age, `errors[]` (code, what, request and IP counts, last seen), curl snippet without any key |

Shared props added to existing pages: Overview tiles and header (`DeliveryHealthSummary`), Environments list Delivery column, flag list `lastEvaluatedAt` per flag for the selected environment, onboarding `evaluationObserved` boolean.

**Management API (`/api/v1`, scope `analytics:read` proposed for D14)**

```text
GET  /projects/{project}/environments/{environment}/analytics/summary?from=&to=
GET  /projects/{project}/environments/{environment}/analytics/flags?from=&to=&granularity=hour|day&cursor=
GET  /projects/{project}/environments/{environment}/analytics/flags/{flag}?from=&to=
GET  /projects/{project}/environments/{environment}/analytics/sdks
GET  /projects/{project}/environments/{environment}/analytics/stale-flags
GET  /projects/{project}/environments/{environment}/delivery-health
POST /projects/{project}/environments/{environment}/subject-deletions      { "kind": "user", "key": "…" }
```

Ranges are bounded (max 400 days, daily granularity beyond 35 days); responses carry `asOf` and `estimated`.

### 3.5 Frontend

| Path | Contents |
| --- | --- |
| `resources/js/pages/analytics/Index.vue` | 1a layout, range tabs, environment from the global switcher, empty state 2b, read-only banner 2c |
| `resources/js/pages/analytics/FlagShow.vue` | 1b drill-down |
| `features/analytics/StatTiles.vue` | Four tiles; each links to its definition tooltip |
| `features/analytics/ChecksChart.vue` | SVG bar chart with publish markers, keyboard-focusable bars, hover/focus label, and a visually hidden data table (frontend doc §7) |
| `features/analytics/FlagValuesTable.vue` | Share bars with text percentages, fallback rate highlight above 0.5% |
| `features/analytics/StaleFlagsCard.vue` | Evidence lines, Archive opens M4's `ArchiveDialog` with 2a copy |
| `features/analytics/SdkHealthTable.vue` | "On live rev" text, Update tag linking to the SDK upgrade guide |
| `features/analytics/CoverageNote.vue` | "As of 10:42 (UTC+8). Evaluation data can lag by up to 5 minutes. Based on N reporting SDK instances." plus "Estimated" when sampled |
| `features/environments/DeliveryTab.vue` | Delivery tab content (inside M3's page) |
| `features/environments/DeliveryHealthBadge.vue` | Badge used by Overview, Environments list and detail |
| `components/ui/charts/BarSeries.vue`, `ShareBar.vue` | Theme-token colors only, no chart library |
| `composables/useAnalyticsRange.ts` | Range and environment in the URL query, remembered per project in local storage |

### 3.6 Jobs, events, outbox consumers

| Job | Queue | Schedule / trigger |
| --- | --- | --- |
| `ProcessIngestBatch` | `telemetry` | Dispatched after the journal insert commits; a sweeper re-dispatches `pending` batches older than 1 minute (same pattern as M0's outbox durability contract) |
| `FlushSdkObservations`, `FlushDeliveryRollups` | `telemetry` | Every minute |
| `RollUpObservationsHourly`, `BuildUsageAggregate` | `telemetry` | Hourly / daily |
| `DetectStaleFlags` | `telemetry` | Daily per project |
| `DeleteSubjectTelemetryChunk` | `telemetry` | Per deletion request |
| `PruneTelemetry` | `telemetry` | Daily |
| Consumer of `key.revoked` / `revision.published` | `default` | Invalidate the cached credential lookup; mark observation groups for the "behind" check |

**Queue isolation.**

- Horizon supervisors: `publication` (queue `publication`, own Redis connection `queue-core`, never shared), `default`, `integrations` (M9), `telemetry` (queue `telemetry`, Redis connection `queue-telemetry` on a separate Redis database, ideally a separate instance under D08, `maxProcesses` capped), `reports` (M11).
- The ingestion endpoint writes only to the `telemetry` MySQL connection and the `telemetry` Redis connection. Its only core-database read is the credential lookup, cached in Redis for 60 seconds and invalidated on revocation.
- Backpressure: when the oldest `pending` batch is older than 10 minutes or pending batches exceed a configured count, `AcceptEventBatch` returns `503 INGEST_UNAVAILABLE` with `Retry-After`. SDKs keep a bounded buffer and report `droppedEvents`, which shows up as reduced coverage rather than silent loss.
- The ingestion route group can be served by its own PHP-FPM pool or process group (deployment detail, D08) without code changes.

**Storage seam.** Read models and processors depend on interfaces in `app/Modules/Analytics/Domain/Storage`: `IngestJournal`, `EvaluationAggregateStore`, `ObservationStore`, `ExperimentEventStore` (consumed by M11), `DeliveryRollupStore`. Each has a MySQL implementation in `Infrastructure/MySql`. Moving to an analytics store later means adding implementations, dual-writing from `ProcessIngestBatch` for one retention period, comparing query results in a shadow job, then switching the binding. No page or M11 code changes.

### 3.7 Security and tenant isolation

- The authenticated credential fixes organization, project and environment; nothing in the body can change them. Event payloads never carry tenant IDs.
- Browser events are untrusted: separate, smaller limits; marked `source = browser`; M11 can show the share of browser-sourced exposures. Origins must match the environment's allowed origins.
- Raw subject keys never persist (hashed in the request). Peppers are encrypted, per environment, and never leave the server.
- Contexts other than `kind` and `key` are rejected for exposures and conversions; any `attributes` field is a schema error.
- Error samples store no raw IPs or full user agents; IP counts come from a daily-salted HyperLogLog.
- Read queries include `organization_id` and `environment_id` in every `WHERE`; M0's scope test is extended to the `telemetry` connection. Caches are keyed `tenant:{org}:env:{env}:…`.
- Support console (M7) sees aggregate delivery health, SDK versions and error rates, never exposure or conversion rows.
- Analytics and Pricing copy must not claim "we don't store users" while exposures are kept (see §8).

## 4. Implementation steps

1. **Protocol schemas.** In `bitflag-protocol`: `schemas/telemetry-batch.v1.json`, `schemas/telemetry-ack.v1.json`, valid and invalid fixtures (every event type, limits, time window, duplicates in batch). Pinned into `bitflag` like the snapshot fixtures.
2. **Telemetry connection and seam.** `config/database.php` `telemetry` connection, migrations under `database/migrations/telemetry/`, storage interfaces and MySQL implementations, arch tests forbidding cross-connection joins and direct table access outside `Infrastructure/MySql`.
3. **Ingestion endpoint.** `AcceptEventBatch`, `Data/EventBatch`, `Domain/Ingest/BatchValidator`, `SubjectHasher`, `environment_telemetry_keys`, `TelemetryRateLimit`, `EnforceBrowserOrigin`, ack DTO; feature tests for every status code.
4. **Processing.** `ProcessIngestBatch`, receipts, aggregates, `flag_usage`, `FlagKeyResolver` query in Flags, `ExperimentRegistry` interface with `NullExperimentRegistry`, exposure and conversion storage, quality counters, sweeper.
5. **Queue isolation.** Horizon config for all supervisors, Redis connections, backpressure check, `tests/Feature/Analytics/QueueIsolationTest.php`.
6. **SDK observations and delivery recording.** Redis HyperLogLog groups, flush jobs, `RecordDeliveryRequest` middleware on `routes/sdk.php`, error samples.
7. **`bitflag-php` telemetry.** `src/Telemetry/EvaluationSummarizer`, `EventBuffer` (bounded, drops oldest and counts), `BatchSender` (gzip, retry with backoff and jitter, honors ack lists), `trackExposure`, `track`, `sdk_status` timer for long-running processes and flush on `close()`. Fixture tests against the protocol schemas.
8. **Read models.** `Queries/AnalyticsSummary`, `EvaluationSeries`, `FlagValueDistribution`, `SdkHealth`, `EnvironmentDeliveryHealth`, `StaleFlagCandidates`, `FlagLastEvaluated`, `FirstEvaluationObserved`.
9. **Analytics pages.** `analytics/Index`, `analytics/FlagShow`, feature components, charts with accessible tables; Cypress `m10-analytics.cy.ts`, `m10-analytics-empty.cy.ts`.
10. **Health surfaces.** Delivery tab, `DeliveryHealthBadge` on Overview and Environments, Overview tiles, flag list column, onboarding "Evaluation reported" check (replacing any placeholder M5 used); Cypress `m10-delivery-health.cy.ts`, `m10-onboarding-verify.cy.ts`.
11. **Cleanup evidence.** `DetectStaleFlags`, outbox message for M8, Stale card wired to M4 archive dialog; Needs attention items for M5's Overview.
12. **Management API.** Analytics endpoints, subject deletion, OpenAPI entries.
13. **Retention.** `PruneTelemetry`, rollups, `retention_runs` evidence rows, deletion job.
14. **Demo seed.** `AnalyticsDemoSeeder` generates synthetic batches for Storefront and pushes them through `ProcessIngestBatch` (never writes aggregate rows directly), including PHP / Laravel 1.9.3 stuck on rev 39, `payment-config` fallbacks, and the three stale flags. Seeder refuses to run when `app.env` is `production`.

## 5. Tests

**Pest unit**: `BatchValidatorTest` (every schema rule, limits, time window, clamping), `SubjectHasherTest` (known vectors, kind separation, Unicode keys hashed as given), `AckBuilderTest`, `DeliveryHealthTest` (each state), `StaleFlagRulesTest` (permanent flags excluded, coverage required, publish age).

**Pest feature (real MySQL and Redis, `tests/Feature/Analytics`)**

- Auth: missing key 401, revoked key 401, management token 401, server key without `events:write` 403, browser key from a disallowed origin 403, rotating key accepted during overlap.
- Idempotency: the same batch posted twice counts once; the same event in two batches counts once; a crash after journal insert and before processing is recovered by the sweeper; a crash mid-processing does not double count (transaction plus receipts).
- Subject deletion (`SubjectDeletionReplayTest`): ingest exposures and conversions for a subject, leave one batch `pending`, delete the subject, then run the sweeper, replay the journal and rebuild M11 results; no row for that subject reappears under any pepper version, and an event with a later `event_time` is still accepted. Repeat with a pepper rotated between ingestion and deletion.
- Partial acknowledgement: mixed valid, invalid and rate-limited events return the right lists; resending only retryable events completes the counts.
- Durability: when the `telemetry` connection is down the endpoint returns 503 and nothing is acknowledged.
- Isolation: a key from org A can never write rows for org B; every read query with another tenant's IDs returns nothing; the scope test runs against both connections.
- Raw subject keys: after ingesting exposures and conversions, a scan of every telemetry table and the journal finds no raw key string.
- Conversions without a metric are not stored as rows; their names are counted.
- Queue isolation: with 50,000 queued telemetry jobs, a publication job on `publication` is picked up by its own worker immediately (Horizon test harness); backpressure returns 503 above the threshold.
- Retention: prune removes exactly the rows beyond each window.

**Pest arch**: `Analytics` jobs declare `telemetry` (or `default` for consumers); only `Infrastructure/MySql` touches telemetry tables; ingestion code never imports core Eloquent models except through the credential context.

**Vitest**: `ChecksChart` (markers, keyboard focus, hidden table), `FlagValuesTable`, `SdkHealthTable` texts, `CoverageNote` estimated label, `useAnalyticsRange`.

**Cypress**: `m10-analytics.cy.ts` (seeded data, ranges, drill-down, stale list, Archive dialog), `m10-analytics-empty.cy.ts` (2b), `m10-analytics-readonly.cy.ts` (Ana Cruz, 2c), `m10-delivery-health.cy.ts` (Delivery tab errors, badges), `m10-onboarding-verify.cy.ts` (create key, run the `bitflag-php` example in CI, see "Evaluation reported").

**Fixtures**: protocol telemetry fixtures; `tests/Fixtures/telemetry/*.json` batches for edge cases (late events, clock skew, unknown flags, oversized).

## 6. Decisions

| Item | Default assumed until decided |
| --- | --- |
| **D08** Telemetry storage and scale | MySQL on a separate `telemetry` connection and schema, hourly and daily rollups, storage interfaces as the seam; separate Redis connection for the telemetry queue and rate limits; numbers from M12 load tests |
| **D09** Retention | Table in §3.1 |
| **D09** Subject linkage | Exposures and conversions keep an environment-peppered HMAC of `kind` and `key`; no raw keys; conversions stored only when a running experiment's metric needs them; deletion by subject supported |
| **D09** Unique users | Not collected. Option for later: SDK-side HyperLogLog sketches of hashed keys in evaluation summaries |
| **D09** Sampling | Off by default; `sampleRate` supported and labeled "Estimated" |
| **D03** Telemetry schema | §3.2 format published in `bitflag-protocol` |
| **D04** Stream counts | SDK-reported streaming instances until the stream runtime reports connections |
| **D14** Scopes | `analytics:read`, `subjects:delete` |
| **D12** Archive from Analytics | Uses M4's staged archive workflow; the 2a dialog starts it rather than archiving in one step |

## 7. Done criteria

- A `bitflag-php` client in CI evaluates flags, sends telemetry, and the Analytics page, Overview tiles, Delivery tab and onboarding check show the resulting numbers within 5 minutes, with no seeded aggregate rows.
- Duplicate and retried batches never change counts; acknowledgements distinguish accepted, retryable and rejected events exactly as the protocol fixtures expect.
- Telemetry floods (load test in M12, smoke version in CI) do not delay publication jobs.
- No raw subject key, IP or context attribute is stored anywhere (automated scan).
- Deleted subjects never reappear through pending batches, replay, rebuilds or retired pepper versions (`SubjectDeletionReplayTest`).
- Stale suggestions appear only with coverage and show their evidence and caveats.
- Every stat has one query shared by all pages, and Overview, Environments and Analytics agree on the seed.
- Cypress M10 specs pass.

## 8. Risks and open questions

- **D08/D09 build-ahead.** The implementation plan says M10 cannot build ahead of D08/D09; this plan builds on stated defaults behind the storage seam. Shei should confirm that is acceptable, or decide D08/D09 first. **Resolved (R17, 8 October 2026): the default above is the decision.**
- **Privacy copy.** The Pricing FAQ says "User context is used for an evaluation and then dropped. Analytics are aggregate counts." That is true for evaluations, but experiments keep pseudonymous subject hashes for exposures and conversions. The domain model warns against promising otherwise. The copy needs a change.
- **"Unique users" and per-rule "users" (1b)** need subject-level counting, which the aggregate model does not provide. Default: shown as "Not collected" and per-rule checks instead.
- **Chart note** "Checks often jump after one because SDKs refresh" contradicts frontend doc §6 ("An SDK refresh … does not itself imply a jump in application evaluations"). Suggested copy: "A publish."
- **One-click Archive (2a)** conflicts with the staged, dependency-checked archive workflow (domain model "Lifecycle and archive", D12). Default: the dialog starts the workflow.
- **Curl path (mockup copy).** The Delivery tab mockup's curl uses `/api/v1/sdk/snapshot`; we build `/sdk/v1/snapshot` (M5), and the rendered snippet uses that path. The mockup copy needs a fix.
- **MySQL hot rows.** Popular flags concentrate increments on few rows. Batch-level transactions keep contention bounded; if load tests show lock waits, pre-aggregate per worker in Redis for 10 seconds before writing.
- **Browser instance counts** grow with page loads; HyperLogLog bounds memory but "instances" for browsers means page sessions, which needs a tooltip.
- **Ingestion lateness window** of 7 days is a default; experiments with long attribution windows may need longer.
