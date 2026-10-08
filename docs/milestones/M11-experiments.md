# M11. Experiments

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Expands M11 in the [implementation plan](../implementation-plan.md).

## 1. Goal and scope

Let Owners and Publishers on Pro run an experiment on a multi-variation flag: plan it, start it through normal review and publication, collect real exposures and conversions, see results with quality warnings, and stop it with a recorded decision that becomes an ordinary reviewed change. The statistics sit behind a pluggable `StatisticalMethod` so that the D07 decision changes one class, not the product.

**In scope**

- Experiments, immutable plan versions, variants, metrics (primary, secondary, guardrail), allocation history, interruptions, decisions (domain model "Experiments").
- A project metric catalog built on conversion event names that M10 observes.
- Start flow: validation, plan freeze, an experiment rule placed in the environment draft through M4 actions, then M5's review and publication; the experiment becomes `running` only when a published revision contains the rule.
- Assignment via the experiment salt (protocol §5), with `experimentId` and `allocationId` on results.
- Analysis pipeline over M10's exposure and conversion store: units, first exposure, attribution windows, crossover exclusion, late data watermark, final results.
- Results: per-variant users, conversions, rates or means, change versus control with an interval, chance to beat control, guardrails, sample ratio mismatch and other data-quality warnings, verdicts behind a stopping policy.
- Stop and decide: ship a variation, keep control, or stop without changing serving; a production draft through M5 when serving changes.
- `experiment.started` and `experiment.stopped` outbox messages (webhooks in M9), notifications through M8, Pro-only gate through M6.
- A reference-validated statistics package and a sign-off switch that keeps verdict UI off in production until D07 is approved.

**Out of scope**

- Pause and resume (R04). The mockups' Pause button is not built.
- Multiple concurrent experiments on one flag and environment (needs an approved priority design, domain model "Experiments").
- Sequential testing, CUPED, Bayesian priors other than the flat default, percentile metrics (for example "Page load p75"): these are D07 options the interface can take later.
- Client-side SDK helpers for exposures in Vue/React adapters (M13); `bitflag-php` `trackExposure` and `track` come from M10.

## 2. Mockups covered

| Mockup | Artboard | Behavior implied |
| --- | --- | --- |
| BitFlag Experiments | 1a List: table | Columns experiment (name + flag key), status ("Running · day 9 of 14", "Finished Sep 20", "Draft"), primary metric, leading ("b +4.1%", "Too early", or a dash for drafts), verdict tag ("Likely winner", "Collecting data", "Shipped v3", "Not started"); filters Status and Environment; New experiment |
| BitFlag Experiments | 1b List: cards | Same data as cards. The portal prototype uses tabbed cards (All, Running, Drafts, Finished with counts): this is the list built |
| BitFlag Experiments | 1c Results: table first | Built as the detail page. Header: flag key, environment, split ("50/50"), "started Sep 21 · day 9 of 14", actions Stop & choose (Pause omitted, R04). Verdict banner "b is likely better: +4.1% checkout conversion. 94% chance b beats control. Wait 5 more days for the planned sample, or stop now and ship b." Primary metric table: users, conversions, rate, "Change vs control · 95% range". Secondary metrics table with change |
| BitFlag Experiments | 1d Results: chart first | "Chance b beats control by day" chart with 50% line; tiles Chance to win, Lift with range, Sample "82.6k of 90k planned". Built as a secondary panel on the detail page |
| BitFlag Experiments | 2a New experiment | Flag (with variation count), environment, split per variation, primary metric, "Run for 21 days · ~120k users", "Users stay in the same variation for the whole experiment. Only users who reach this flag are counted." |
| BitFlag Experiments | 2b Stop and choose | "Choose what everyone gets from now on. This creates a draft in production that goes through review like any other change." Options Ship b, Keep control, Stop without changing ("Keep the 50/50 split, stop collecting results"), optional note, "Stop and create draft" |
| BitFlag Experiments | 2c Too early to call | "Not enough data yet. 8,400 of about 95,000 users so far. Early numbers swing a lot, so we hide the leader until the result settles. Estimated: Oct 12." Progress, users and split per variation, no leader |
| BitFlag Experiments | 2d Empty | "No experiments yet …" and "Connect analytics events" (links to the metric catalog) |
| BitFlag Experiments | 2e Viewer, read-only | Results readable, actions disabled with the role banner |
| portal/Experiments | prototype | Create dialog: name, flag ("Its versions are what gets compared"), who's in it (Everyone or a segment), goal, "Stop when each version has 5,000 / 10,000 / 25,000 users. Set this up front so you don't stop early on a lucky streak." Start dialog: "{audience} will be split … Each user keeps the same version for the whole test. This adds an experiment rule to {flag} as a production draft. It needs a review and a publish before users see it." CTA "Create draft and go to Changes". Detail: goal definition ("order_completed within 7 days of first exposure"), interval bar explanation, Guardrails ("metrics that must not get worse"), Setup card, Progress ("Users per version, counting the smallest group. About N more days at the current pace."), Timeline ("Started · 50/50", "Went live in production rev 40", "Stopped at 20,000 users per version", "Shipped v3 to everyone in rev 39"). Verdict kinds: not started, ahead keep running, too early, wins, control wins, no meaningful difference, finished. Stop dialog suggests an option, warns "Only N% of the planned users so far. Results this early are often not repeatable." Toast "Publish production from Changes to apply it." |
| BitFlag Organization | Free plan | "Experiments are on Pro … Your flags and rollouts keep working on Free. Upgrade to Pro"; downgrade "1 running experiment: Keeps collecting data. You can stop and decide it. New ones can't start." |
| BitFlag Overview | 1a | Activity "Priya Nair started experiment checkout-layout"; tile "Experiments 2 running" |
| BitFlag Changes | rollback list | Revision "checkout-layout experiment": rollback past it must show the affected experiment |

## 3. Design

### 3.1 Data model

Main connection unless marked *(telemetry)*. ULIDs, `timestamp(6)` UTC, tenant columns first in every index.

**`experiments`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | Public `exp_` |
| `organization_id`, `project_id`, `environment_id`, `flag_id` | ulid | Composite FKs keep them in one project |
| `key` | varchar(100) | Slug of the name, unique in project, immutable after start |
| `name` | varchar(120) | |
| `hypothesis` | text null | |
| `owner_user_id` | ulid | Defaults to creator |
| `status` | enum(`draft`,`starting`,`running`,`stopped`) | `starting` is an addition to the domain model (see §8) |
| `current_version_id` | ulid | Draft plan until start, then the frozen one |
| `salt` | varchar(40) | `s_` + 16 random bytes hex, generated at creation, never edited |
| `allocation_id` | varchar(40) | Public `alc_` (new prefix), generated at start |
| `rule_id` | varchar(64) null | Stable rule ID placed in the draft |
| `start_requested_at`, `started_at`, `stopped_at` | timestamp null | |
| `started_revision_id` | ulid null | First revision that served the rule |
| `stopped_by_user_id` | ulid null | |
| `sample_reached_notified_at` | timestamp null | |
| `version` | int | Optimistic concurrency |
| `running_lock` | varchar(60) generated null | `CONCAT(flag_id, ':', environment_id)` when status is `starting` or `running`, else NULL; unique index enforces one active experiment per flag and environment |

Indexes `(organization_id, project_id, status)`, `(organization_id, environment_id, status)`.

**`experiment_versions`**: `id`, `organization_id`, `experiment_id`, `number`, `state` enum(`draft`,`frozen`), `audience_type` enum(`everyone`,`segment`), `audience_segment_id` null, `assignment_kind` varchar(40) (context kind, default `user`), `exposure_trigger` enum(`explicit`) (only `trackExposure` or adapter render counts), `event_time_policy` json (`{"lateness_hours": 48}`), `exclusion_rules` json (`{"crossover": "exclude"}`), `missing_data_policy` enum(`unconverted_is_zero`), `statistical_method` varchar(40) (`normal-approx-v1`), `stopping_policy` varchar(40) (`fixed-horizon-v1`), `decision_criteria` json (`{"interval": 0.95, "min_evidence_fraction": 0.5, "srm_p": 0.001, "multiple_comparisons": "bonferroni"}`), `planned_sample_per_variant` int, `planned_end_at` null, `frozen_at` null. Unique `(experiment_id, number)`. A frozen version is never updated (arch test plus a model guard).

**`experiment_variants`**: `organization_id`, `experiment_version_id`, `variation_id`, `variation_key`, `is_control` bool, `position`, `weight` int (units of 100,000). Exactly one control; sum of weights at most 100,000; default equal split with the remainder (from integer division) added to the control.

**`metric_definitions`** (project catalog)

| Column | Notes |
| --- | --- |
| `id` | Public `met_` (new prefix) |
| `organization_id`, `project_id` | |
| `key`, `name`, `description` | Key unique in project |
| `event_name` | Conversion event from `track()` |
| `aggregation` | enum(`conversion_rate`, `mean_value_per_unit`, `count_per_unit`) |
| `value_unit` | varchar(16) null (`PHP`, `ms`, none) for display |
| `direction` | enum(`increase`,`decrease`): which way is better |
| `default_attribution_hours` | int, default 168 (7 days) |
| `status` | enum(`active`,`archived`) |
| `version` | int |

**`experiment_metrics`**: `organization_id`, `experiment_version_id`, `metric_definition_id`, `role` enum(`primary`,`secondary`,`guardrail`), and a frozen copy of `event_name`, `aggregation`, `direction`, `attribution_hours`. Exactly one primary; at most 5 secondary and 5 guardrails.

**`experiment_allocations`** (allocation history): `organization_id`, `experiment_id`, `experiment_version_id`, `allocation_id`, `from_revision`, `to_revision` null, `from_at`, `to_at` null, `weights` json, `state` enum(`serving`,`flag_off`,`rule_absent`). A new row opens whenever a published revision changes what the rule serves for this experiment.

**`experiment_interruptions`**: `organization_id`, `experiment_id`, `kind` enum(`emergency_disable`,`flag_disabled`,`rule_removed`,`rollback`), `revision`, `started_at`, `ended_at` null.

**`experiment_decisions`**: `id`, `organization_id`, `experiment_id` (unique), `decision` enum(`ship_variation`,`keep_control`,`no_serving_change`), `variation_key` null, `note` varchar(1000) null, `decided_by_user_id`, `decided_at`, `results_snapshot_id`, `publication_state` enum(`not_applicable`,`draft_created`,`published`,`superseded`), `draft_version` int null, `published_revision` int null.

**`experiment_units`** *(telemetry)*: `organization_id`, `experiment_id`, `subject_hash` binary(32), `variation_key`, `first_exposed_at`, `exposure_count`, `crossover` bool, `source_mask` tinyint (server, browser). Primary key `(organization_id, experiment_id, subject_hash)`.

**`experiment_unit_metrics`** *(telemetry)*: `organization_id`, `experiment_id`, `experiment_metric_id`, `subject_hash`, `converted` bool, `value_sum` decimal(24,6), `event_count`, `first_conversion_at`. Variance is computed across units at query time, so no per-unit squares are stored. Primary key `(organization_id, experiment_id, experiment_metric_id, subject_hash)`.

**`experiment_build_cursors`** *(telemetry)*: `organization_id`, `experiment_id`, `exposures_cursor`, `conversions_cursor` (last processed `received_at` + `event_id`).

**`experiment_result_snapshots`**: `id`, `organization_id`, `experiment_id`, `experiment_version_id`, `computed_at`, `watermark` (data included up to this event time), `method`, `stopping_policy`, `is_final` bool, `verdict` enum(`not_started`,`too_early`,`leading`,`wins`,`control_wins`,`inconclusive`,`guardrail_failed`,`invalid`), `recommended_decision` json null, `results` json (per metric, per variant: units, converters, sum, mean, variance, absolute and relative change, interval, probability better, sufficient flag), `warnings` json, `progress` json (smallest group units, planned, fraction, units per day, estimated completion date). Index `(organization_id, experiment_id, computed_at)`. Kept for the life of the experiment plus the audit retention window (history drives the 1d chart).

### 3.2 Domain rules and state machines

**Status machine** (`Domain/ExperimentStateMachine`):

```text
draft ──StartExperiment──▶ starting ──revision with rule published──▶ running ──StopExperiment──▶ stopped
  ▲                          │
  └──CancelExperimentStart───┘ (also automatic if the rule disappears from the draft before publication)
```

- No pause (R04). `stopped` is terminal; a re-run is a new experiment (copy action).
- Running experiments allow edits to name, hypothesis and owner only. Audience, variants, weights, metrics, attribution and planned sample are frozen in the version (default for D07: forbidden while running rather than versioned mid-flight).
- The flag cannot be archived, have its variations deleted, or change type while an experiment is `starting` or `running` (M4 dependency checks call `ExperimentDependencies`).

**Eligibility to create and start**

- Pro plan (`Entitlements::assertAllows(org, Feature::Experiments)`), capability `experiments.manage` in the target environment.
- Flag lifecycle `active`, at least two variations, every variant is a current variation of the flag.
- Audience segment (if any) exists in the project. The snapshot pins the segment version per environment as usual; the allocation history records the revision, so analysis knows the exact segment version.
- One primary metric, planned sample per variant ≥ 100.
- Starting additionally requires no other `starting` or `running` experiment for the flag and environment (unique `running_lock`, error `EXPERIMENT_ALREADY_ACTIVE`) and that the environment draft accepts the rule (M4 validation, `VERSION_CONFLICT` on stale `expectedDraftVersion`).

**Assignment.** The experiment rule placed by M4's `PlaceExperimentRule` is an ordinary rule (protocol §3):

```json
{
  "id": "rule_exp_01JAB…",
  "experimentId": "exp_01JAB…",
  "match": { "all": [ { "op": "in_segment", "values": ["ph-premium"] } ] },
  "serve": { "rollout": {
    "bucketBy": "key", "salt": "s_9f2c…",
    "experimentId": "exp_01JAB…", "allocationId": "alc_01JAB…",
    "slots": [ { "variation": "control", "weight": 50000 }, { "variation": "b", "weight": 50000 } ],
    "remainderVariation": "control" } }
}
```

- Placed at the top of the rule list by default (overrides still win, protocol §3); the start dialog states which rules would otherwise precede it. Everyone audience means an empty `all` group.
- Bucketing uses `bucket-sha256-v1` with the experiment salt and the assignment kind; subjects without a key get the caller fallback with `MISSING_SUBJECT_KEY` and are never assigned randomly.
- Only subjects in a slot get `experimentId` and `allocationId` on the result; remainder subjects get the remainder variation without experiment IDs (proposal for D03/D07).
- The rule carries `experimentId`, so M4's editor shows it as "Managed by experiment checkout-layout" and refuses direct edits (M4 §3.3, "Experiment rule actions (used by M11)").

**Becoming running.** The consumer of `revision.published` (`ActivateStartingExperiments`) loads the published server snapshot for the environment; if it contains the experiment's `allocationId`, the experiment becomes `running` with `started_at = publishedAt`, `started_revision_id`, the first `experiment_allocations` row, timeline entry "Went live in production rev 40", and outbox `experiment.started`. If a `starting` experiment's rule is removed from the draft, it returns to `draft`.

**Interruptions and later revisions.** For every later `revision.published` in the environment (including rollbacks and emergency disables, which are revisions with `source`):

| Snapshot condition | Effect |
| --- | --- |
| Rule present, flag enabled, weights unchanged | Nothing |
| Flag disabled (`source = emergency_disable` or a normal publish) | Close allocation row, open `flag_off`, interruption `emergency_disable` or `flag_disabled` |
| Rule absent (rollback or publish) | Close allocation, open `rule_absent`, interruption `rollback` or `rule_removed`; banner "This experiment is not being served. Stop and decide, or publish the rule again." |
| Back to serving | Close the interruption and open a new `serving` row |

Exposures are only possible while serving, so the analysis never mixes populations; the interruption appears as a data-quality warning. M5's rollback dialog calls `ExperimentsAffectedByRevisionChange` to list experiments that a rollback would interrupt.

**Analysis definitions** (`Domain/Analysis`):

- **Unit**: a distinct `subject_hash` of the assignment kind with at least one exposure for this experiment in a `serving` interval. Exposures of another kind are ignored and counted.
- **Variant of a unit**: the variation of its first exposure. A unit later exposed to another variation is a **crossover**; it is excluded and counted (warning above 1% of units).
- **Conversion attribution**: a conversion event for the metric's event name with `first_exposed_at ≤ event_time ≤ first_exposed_at + attribution_hours`. Conversions before exposure or for subjects without exposure are ignored (counted for the quality panel).
- **Metric values per unit**: `conversion_rate` → 1 if converted else 0; `mean_value_per_unit` → sum of `value` in window (0 if none, `unconverted_is_zero`); `count_per_unit` → number of events in window.
- **Watermark**: results include events with `event_time ≤ now − 15 minutes`; late events within the 48-hour lateness policy update later computations. **Finalization:** `final_at = stopped_at + max(attribution_hours over the version's primary, secondary and guardrail metrics) + lateness_hours`. With the defaults (168 h attribution, 48 h lateness) that is 9 days after stop. A unit first exposed just before the stop keeps its full attribution window, and conversions that arrive within the lateness policy still count. Only then are results computed with `is_final = true`; until then the stopped page says "Final results on {final_at date}" and shows provisional results labelled as such. M10 keeps storing conversions for the experiment's event names until `final_at`.
- **Window**: from `started_at` to `stopped_at`; exposures after stop are not used (M10 discards them).

**`StatisticalMethod` interface** (`Domain/Statistics`):

```php
interface StatisticalMethod
{
    public function key(): string; // 'normal-approx-v1'

    public function compare(
        MetricSummary $control,
        MetricSummary $treatment,
        ComparisonOptions $options, // interval level, direction, number of treatments
    ): Comparison;
}

final readonly class MetricSummary
{
    public function __construct(
        public int $units, public float $sum, public float $sumOfSquares, public int $converters, public Aggregation $aggregation,
    ) {}
}

final readonly class Comparison
{
    public function __construct(
        public float $controlMean, public float $treatmentMean,
        public float $absoluteChange, public float $relativeChange,
        public Interval $relativeInterval, public float $probabilityBetter, public bool $sufficientData,
    ) {}
}

interface StoppingPolicy
{
    public function key(): string; // 'fixed-horizon-v1'
    public function verdict(ExperimentAnalysis $analysis): Verdict;
}
```

Methods are registered by key in `config/experiments.php`; the experiment version pins the key, so a later method never silently reinterprets a running experiment.

**Proposed default method `normal-approx-v1` (D07 undecided; must be validated before release)**

- Per variant mean and variance from `units`, `sum` and `sumOfSquares` (Bernoulli variance `p(1 − p)` for conversion rate; sample variance with `n − 1` otherwise).
- Difference `d = mean_t − mean_c`, `se = sqrt(var_t/n_t + var_c/n_c)`.
- Relative change `mean_t/mean_c − 1`, interval by the delta method; level 95%, widened by Bonferroni when there is more than one treatment (`z = Φ⁻¹(1 − 0.05/(2k))`).
- "Chance to beat control" `= Φ(d/se)` (flipped for `decrease` metrics). Under a flat prior this equals the approximate posterior probability, which reconciles the canvas copy ("94% chance b beats control") with the prototype's normal interval ("95% range"). This interpretation is exactly what D07 must confirm.
- `sufficientData` requires at least 100 units and, for conversion rates, at least 10 converters and 10 non-converters per variant; otherwise the variant reports "Not enough data".

**Proposed default stopping policy `fixed-horizon-v1`**

| Condition (smallest variant's units vs planned) | Verdict | UI |
| --- | --- | --- |
| Not started | `not_started` | "Not started. Nobody sees a different version yet." |
| SRM detected | `invalid` | "Results may be invalid: the split is off." No recommendation |
| Below 50% of plan, or insufficient data | `too_early` | 2c: leader hidden, progress and estimated date only |
| 50% to 100%, best treatment's interval entirely better | `leading` | "b is ahead so far … Wait N more days for the planned sample; results before then are often not repeatable" (R16: no "stop now" suggestion; Stop and choose stays available with its early-stop warning) |
| 50% to 100%, otherwise | `too_early` | "No clear leader yet" |
| 100% reached, best interval entirely better, guardrails held | `wins` | "b wins"; suggested option Ship b |
| 100% reached, a guardrail failed for the best treatment | `guardrail_failed` | Winner not suggested |
| 100% reached, every treatment interval entirely worse | `control_wins` | Suggest Keep control |
| 100% reached, otherwise | `inconclusive` | "No clear evidence of a difference" (R16); suggest Keep control as the default choice, without claiming the variants perform the same |

**Data-quality warnings** (computed with every snapshot, shown above results):

| Warning | Rule |
| --- | --- |
| Sample ratio mismatch | Chi-square goodness of fit of units per variant against slot weights, p < 0.001 |
| Guardrail regression | A guardrail's interval is entirely on the worse side |
| Crossover units | Above 1% of units |
| Interruption | Any interruption in the window, with dates |
| Delayed data | Watermark more than 1 hour behind, or M10 backpressure active |
| Reduced coverage | SDKs reported `droppedEvents` in the environment during the window (M10 quality counters) |
| No conversions | A variant has units but zero conversions for the primary metric after 24 hours |
| Stopped early | Stopped below the planned sample ("Results this early are often not repeatable") |
| Browser-sourced data | Share of exposures from browser keys (informational; browser events are untrusted) |

**Stop and decide.** `StopExperiment` records the decision, sets `stopped`, closes the analysis window, and then:

| Decision | Draft change through M4 | Copy |
| --- | --- | --- |
| `ship_variation` (b) | `ReplaceExperimentRule`: same audience condition, fixed variation b | "Everyone in {audience} gets b" |
| `keep_control` | `ReplaceExperimentRule`: same audience, fixed control | "Everyone gets control" |
| `no_serving_change` | None. `ReleaseExperimentRule` marks the rule as an ordinary rollout in the next draft only if the user later edits it; the live split keeps serving, results stop | "Keep the split, stop collecting results" |

The decision's `publication_state` becomes `published` when a revision containing the replacement is published ("Shipped v3 to everyone in rev 39"), or `superseded` if someone edits the rule differently first. Stopping never publishes by itself.

**Plan downgrade.** On Free, creating and starting return `FEATURE_NOT_IN_PLAN`; running experiments keep collecting, and stopping and deciding stay available (billing doc §4).

### 3.3 Actions

`app/Modules/Experiments/Actions`:

| Action | Input | Capability and gates | Transaction contents | Audit / outbox |
| --- | --- | --- | --- | --- |
| `CreateExperiment` | project, environment, flag, name, hypothesis, audience, variants (default from flag), metrics, planned sample | `experiments.manage`, Pro | Experiment, draft version, variants, metrics, salt | `experiment.created` |
| `UpdateExperimentDraft` | experiment, expectedVersion, fields | `experiments.manage`; draft only (running: name, hypothesis, owner) | Update draft version | `experiment.updated` |
| `DeleteExperimentDraft` | experiment, expectedVersion | `experiments.manage`; draft only | Delete rows | `experiment.deleted` |
| `CopyExperiment` | experiment | `experiments.manage`, Pro | New draft with a new salt | `experiment.created` |
| `StartExperiment` | experiment, expectedVersion, expectedDraftVersion (environment draft) | `experiments.manage`, Pro, eligibility §3.2 | Freeze version, set `starting`, generate `allocation_id`, call M4 `PlaceExperimentRule` (same transaction, through the Flags action) | `experiment.start_requested` / `internal.experiment.start_requested`. Returns the Changes URL for the environment |
| `CancelExperimentStart` | experiment, expectedVersion | `experiments.manage` | M4 `RemoveExperimentRule`, back to `draft`; the frozen version stays frozen and a new draft version copies it | `experiment.start_cancelled` |
| `ActivateStartingExperiments` | revision.published message | System | §3.2 | `experiment.started` (actor system, linked to the publisher) / `experiment.started` (R02) |
| `RecordExperimentRevisionEffects` | revision.published message | System | Allocation rows, interruptions | `experiment.interrupted` / `internal.experiment.interrupted` |
| `StopExperiment` | experiment, expectedVersion, decision, variationKey, note, expectedDraftVersion | `experiments.manage` (Pro not required) | Decision row, status `stopped`, M4 `ReplaceExperimentRule` when serving changes, queue final computation | `experiment.stopped` / `experiment.stopped` (R02) and `internal.experiment.decision_draft_created` |
| `RecordDecisionPublication` | revision.published message | System | Update `publication_state` | `experiment.decision_published` |
| `CreateMetricDefinition` / `UpdateMetricDefinition` / `ArchiveMetricDefinition` | project, fields | `experiments.manage` | Catalog row | `metric.created` / `metric.updated` / `metric.archived` |
| `ComputeExperimentResults` | experiment, final flag | System | Snapshot row | None; `internal.experiment.sample_reached` (once), `internal.experiment.guardrail_failed`, `internal.experiment.srm_detected` (once per experiment) |

M11 also binds M10's `ExperimentRegistry` interface (`EloquentExperimentRegistry`, cached per environment, invalidated on status changes) so that ingestion accepts exposures for this environment's experiments and stores conversions for their metric event names.

Provided by M4 (owned by Flags, M4 §3.3): `PlaceExperimentRule`, `ReplaceExperimentRule`, `RemoveExperimentRule`, `ReleaseExperimentRule`, each taking `expectedDraftVersion` and writing `flag.changed`, plus the managed-rule lock; M4's compiler emits `experimentId`, `allocationId` and `remainderVariation` in rollouts, and the evaluator (M1) returns them. Provided by M5: the `ExperimentsAffectedByRevisionChange` hook in the rollback dialog, which M11 binds.

### 3.4 Routes

**Portal (`routes/web.php`, under M0's prefix `/{organization}/{project}/{environment}`, written here as `…`)**

| Method and path | Page / response |
| --- | --- |
| `GET …/experiments?tab=all\|running\|drafts\|finished` | `experiments/Index` |
| `POST …/experiments` | Redirect to show (toast "Draft created. Start it when ready.") |
| `GET …/experiments/{experiment}` | `experiments/Show` |
| `PATCH`, `DELETE …/experiments/{experiment}` | Redirect |
| `POST …/experiments/{experiment}/start` | Redirect to M5's Changes page for the environment |
| `POST …/experiments/{experiment}/cancel-start` | Redirect |
| `POST …/experiments/{experiment}/stop` | Redirect to show (toast "Publish production from Changes to apply it.") |
| `POST …/experiments/{experiment}/copy` | Redirect to the new draft |
| `GET …/metrics` | `experiments/Metrics` (catalog with observed event names from M10) |
| `POST`, `PATCH …/metrics/{metric?}` | Redirect |

`experiments/Show` props:

```ts
type ExperimentShowProps = {
  experiment: { id: string; key: string; name: string; status: 'draft' | 'starting' | 'running' | 'stopped';
    flag: FlagRef; environment: EnvRef; audience: AudienceRef; assignmentKind: string;
    variants: Array<{ key: string; isControl: boolean; weight: number }>;
    startedAt: string | null; stoppedAt: string | null; dayNumber: number | null; plannedSamplePerVariant: number };
  metrics: Array<{ id: string; role: 'primary' | 'secondary' | 'guardrail'; name: string; definition: string; direction: 'increase' | 'decrease' }>;
  results: null | { computedAt: string; watermark: string; isFinal: boolean; method: string;
    verdict: Verdict; recommended: Decision | null; verdictsEnabled: boolean;
    perMetric: Array<{ metricId: string; variants: Array<{ key: string; units: number; converters: number | null;
      mean: number; relativeChange: number | null; interval: [number, number] | null; probabilityBetter: number | null; sufficient: boolean }> }>;
    progress: { smallestGroup: number; planned: number; fraction: number; unitsPerDay: number | null; estimatedCompletion: string | null };
    warnings: Array<{ code: string; severity: 'info' | 'warning' | 'critical'; message: string }> };
  chanceHistory: Array<{ day: string; variant: string; probabilityBetter: number }>;
  timeline: Array<{ icon: string; text: string; at: string; actor: string | null }>;
  decision: null | { decision: string; variationKey: string | null; note: string | null; publicationState: string; revision: number | null };
  can: { manage: boolean; planAllowsStart: boolean };
};
```

When `verdictsEnabled` is false (production before D07 sign-off), the page shows counts, rates and warnings, hides verdicts, chance to win and suggestions, and labels the method "Under review".

**Management API (`/api/v1`, scopes `experiments:read`, `experiments:write` proposed for D14)**

```text
GET    /projects/{project}/experiments
POST   /projects/{project}/experiments
GET    /projects/{project}/experiments/{experiment}
PATCH  /projects/{project}/experiments/{experiment}            expectedVersion
POST   /projects/{project}/experiments/{experiment}/start      expectedVersion, expectedDraftVersion, Idempotency-Key
POST   /projects/{project}/experiments/{experiment}/stop       decision, variationKey, note, Idempotency-Key
GET    /projects/{project}/experiments/{experiment}/results    latest snapshot; ?history=true for the series
GET    /projects/{project}/metrics
POST   /projects/{project}/metrics
```

Error codes added: `EXPERIMENT_ALREADY_ACTIVE` (409), `EXPERIMENT_NOT_EDITABLE` (409), `EXPERIMENT_FLAG_INELIGIBLE` (422, details list reasons), `EXPERIMENT_INVALID_STATE` (409), `METRIC_IN_USE` (409).

### 3.5 Frontend

| Path | Contents |
| --- | --- |
| `pages/experiments/Index.vue` | Tabbed list with counts, environment filter, cards (the 1a table layout is not built; its columns appear on each card), empty state 2d, Free plan upgrade card |
| `pages/experiments/Show.vue` | Header (no Pause), verdict banner, data-quality warnings, primary results, secondary metrics, guardrails, chance-history panel (1d), setup, progress, timeline, decision card |
| `pages/experiments/Metrics.vue` | Metric catalog, "Seen event names" from M10 with last seen and counts |
| `features/experiments/NewExperimentDialog.vue` | Name, flag (with variations "Its versions are what gets compared"), environment (production preselected), audience, primary goal, guardrails, split, planned users per version presets and custom |
| `features/experiments/StartExperimentDialog.vue` | Audience and split summary, "Each user keeps the same version …", rule position note, CTA "Create draft and go to Changes" |
| `features/experiments/StopExperimentDialog.vue` | Options with suggestion marker, early-stop warning, note, CTA "Stop and create draft" (or "Stop" for no serving change) |
| `features/experiments/VerdictBanner.vue`, `ResultsTable.vue`, `IntervalBar.vue` (text equivalent "−0.6% to +8.9%", bar is `aria-hidden`), `GuardrailList.vue`, `QualityWarnings.vue`, `ProgressPanel.vue`, `ChanceHistoryChart.vue` (with data table), `ExperimentTimeline.vue` |
| `features/flags/ExperimentRuleBadge.vue` | For M4's rule list: "Managed by experiment checkout-layout" with link |
| `composables/useExperimentPlan.ts` | Draft plan editing, split validation (sum of weights, one control), dirty state |

### 3.6 Jobs, events, outbox consumers

| Job / consumer | Queue | Trigger |
| --- | --- | --- |
| `ActivateStartingExperiments`, `RecordExperimentRevisionEffects`, `RecordDecisionPublication` (consumers of `revision.published`) | `default` | Outbox |
| `BuildExperimentUnits` | `reports` | Every 5 min per running experiment; reads exposures after the cursor, upserts units, then looks back for conversions of new units within their windows |
| `AttributeConversions` | `reports` | Every 5 min; reads conversions after the cursor for the experiment's metric event names, joins units, upserts unit metrics |
| `RebuildExperimentUnits` | `reports` | Nightly full rebuild for running experiments; compares totals with the incremental tables and alerts on drift |
| `ComputeExperimentResults` | `reports` | Every 15 min per running experiment, immediately after stop, hourly while stopped and provisional, and final at `final_at` (§3.2) |
| `RecomputeAfterSubjectDeletion` (consumer of `internal.experiment.data_changed`) | `reports` | M10 subject deletion |
| `InvalidateExperimentRegistry` | `default` | Any status change |

Outbox messages: R02 `experiment.started`, `experiment.stopped`; internal `internal.experiment.start_requested`, `internal.experiment.interrupted`, `internal.experiment.sample_reached` (M8 "An experiment you own reached its planned sample size"), `internal.experiment.guardrail_failed` and `internal.experiment.srm_detected` (M8 in-app type `experiment.attention` for the owner), `internal.experiment.decision_draft_created`.

The `reports` Horizon supervisor is separate from `publication`, `telemetry` and `integrations`; result computation reads the `telemetry` connection only through `ExperimentEventStore`.

### 3.7 Security and tenant isolation

- Every experiment route resolves `{experiment}` and `{metric}` inside `{project}`; environment-specific capability checks use the experiment's environment.
- Results and APIs expose aggregates only. No endpoint returns subject hashes, per-unit rows, or event IDs. Subject-level deletion is M10's action.
- The salt is not secret (it is in the server snapshot) but is never shown in browser-visible data beyond what the public projection already contains; experiments on client-visible flags follow the D06 public projection rules (a private audience segment blocks publication as usual).
- Units and unit metrics live on the `telemetry` connection, covered by the same tenant scope tests as M10.
- Jobs carry `organization_id` and `experiment_id` only, and re-check status at execution.

## 4. Implementation steps

1. **Contracts with M1, M4, M5.** Use M4's four Flags actions and managed-rule lock, M4's compiler output (`experimentId`, `allocationId`, `remainderVariation`) and M1's result fields; bind M5's `ExperimentsAffectedByRevisionChange` rollback hook and M4's `ExperimentDependencies` interface; add experiment cases to the protocol fixtures.
2. **Statistics package.** `Domain/Statistics/` with `NormalDistribution` (Cody's erf with documented error bound), `ChiSquare` (regularized incomplete gamma), `NormalApproximationV1`, `FixedHorizonV1`, `SampleRatioCheck`; reference generator `tools/stats-reference/generate.py` (SciPy and statsmodels, run manually, outputs committed to `tests/Fixtures/experiments/reference/*.json`).
3. **Schema and models.** Migrations for every table in §3.1 (telemetry tables under `database/migrations/telemetry/`), models, ID prefixes `exp_` (existing), `alc_`, `met_`.
4. **Metric catalog.** Actions, `experiments/Metrics` page, event names from M10.
5. **Draft experiments.** Create, update, delete, copy; `experiments/Index` and draft view of `Show`; Pro gate; Cypress `m11-experiments-free-plan.cy.ts`.
6. **Start flow.** `StartExperiment`, `CancelExperimentStart`, activation consumer, revision effects, registry binding for M10; Cypress `m11-experiment-start.cy.ts` (create, start, review and publish in Changes, see Running).
7. **Analysis pipeline.** Unit build, attribution, nightly rebuild, result snapshots, warnings, notifications.
8. **Results UI.** Verdict banner, tables, interval bars, guardrails, warnings, progress, chance history, timeline; `verdictsEnabled` switch; Vitest.
9. **Stop and decide.** `StopExperiment`, decision publication tracking, stop dialog; Cypress `m11-experiment-stop.cy.ts`.
10. **API and webhooks.** Management endpoints and OpenAPI; confirm M9 delivers `experiment.started` and `experiment.stopped`.
11. **Demo seed.** `ExperimentsDemoSeeder` creates the four mockup experiments and generates synthetic exposures and conversions through M10's ingestion path (never writes results directly): `checkout-layout` running at about 90% of plan with b ahead, `shipping-banner-copy` early, `recommendations-model` stopped with "Shipped v3", `free-shipping-threshold` draft.
12. **Validation and sign-off.** Simulation suite, statistical review document `docs/experiments/method-normal-approx-v1.md` in the `bitflag` repo with the reviewer's sign-off; enabling `experiments.verdicts_enabled` in production requires it (M12 gate).

## 5. Tests

**Pest unit (`tests/Unit/Experiments`)**

- Statistics against reference fixtures (tolerance 1e-9): normal CDF and quantile, chi-square p-values, every comparison (conversion and mean metrics, increase and decrease direction, k = 1 and k = 3 treatments), zero-variance and tiny-sample edge cases, very large counts.
- Stopping policy table: every row of §3.2, SRM override, guardrail override.
- State machine: every transition and every forbidden one.
- Split builder: equal split remainder to control, weight sum limits.

**Pest feature (`tests/Feature/Experiments`, real MySQL and Redis)**

- Start: plan freeze, rule placed in the draft with the experiment salt, `starting`; publish through M5 makes it `running`; a second start on the same flag and environment fails with `EXPERIMENT_ALREADY_ACTIVE` even under concurrency (two parallel requests, one wins).
- Revision effects: emergency disable and rollback create interruptions and allocation rows; restoring the rule reopens serving.
- Analysis on controlled synthetic datasets: units and first exposure, crossover exclusion, conversions before exposure ignored, conversions outside the window ignored, late events within lateness included on recompute, duplicate exposure and conversion events counted once, exposures after stop ignored, out-of-order arrival (conversions before exposures in ingestion order) attributed correctly; incremental equals nightly rebuild.
- Subject deletion recomputes results without that subject.
- Stop: each decision creates the right draft change (or none), `experiment.stopped` outbox, decision publication tracked on the next publish.
- Plan: Free cannot create or start; downgraded org can stop and decide.
- Isolation: experiment, metric and results endpoints with other tenants' IDs return 404; units of one experiment never feed another.
- `verdictsEnabled` false hides verdict fields from props and API.

**Simulation suite (`tests/Simulation/ExperimentsSimulationTest.php`, nightly CI job, not on every PR)**: 10,000 simulated A/A experiments per configuration (rates 1%, 5%, 30%; 2 and 4 variants) through the method and stopping policy; asserts false positive rate at the horizon within the expected band, interval coverage near 95%, and SRM false alarm rate near 0.1%. A/B simulations with known effects check power at the planned sample. Results are archived as CI artifacts for the reviewer.

**Pest arch**: `Domain/Statistics` has no framework imports; only `Infrastructure` touches telemetry tables; frozen versions have no update path.

**Vitest**: `ResultsTable` formatting and "Not enough data", `IntervalBar` text equivalent, `StopExperimentDialog` suggestion and early warning, `useExperimentPlan` split validation.

**Cypress**: `m11-experiments-list.cy.ts` (tabs, empty state), `m11-experiment-start.cy.ts`, `m11-experiment-results.cy.ts` (seeded results, warnings, too-early state 2c), `m11-experiment-stop.cy.ts`, `m11-experiments-readonly.cy.ts` (Ana Cruz, 2e), `m11-experiments-free-plan.cy.ts`.

## 6. Decisions

| Item | Default assumed until decided |
| --- | --- |
| **D07** Statistical method | `normal-approx-v1` as in §3.2; must pass reference fixtures, simulations and a qualified statistical review before verdicts are enabled in production |
| **D07** Stopping rule | `fixed-horizon-v1`: leader hidden below 50% of plan, "leading" between 50% and 100% with an explicit peeking warning, decisions at 100% |
| **D07** Meaning of "95% range" | Interval on relative change at 95%, Bonferroni-widened for several treatments |
| **D07** Exposure definition | Explicit `trackExposure` or adapter render only; first exposure assigns the unit |
| **D07** Attribution | Per metric, default 7 days after first exposure; "within the session" in the prototype has no server-side session concept and maps to 24 hours |
| **D07** Mid-run changes | Plan fields frozen while running; stop and copy to change them |
| **D07** SRM threshold | Chi-square p < 0.001 |
| **D07** Guardrails | Regression means interval entirely on the worse side; no automatic stop |
| **D09** Experiment data retention | Raw exposures and conversions until 90 days after stop (M10); units and unit metrics deleted with them; result snapshots kept |
| **D03** Result fields | `experimentId` and `allocationId` only for slot-assigned subjects |
| **D05** Emergency disable during an experiment | Allowed; recorded as an interruption |
| **D14** Scopes | `experiments:read`, `experiments:write` |

## 7. Done criteria

- An experiment goes draft → starting → running only through a reviewed publish, collects exposures and conversions from a real `bitflag-php` client in CI, shows results computed from that data, and stops with a decision that becomes a draft and then a published revision.
- No result, verdict or chart in production comes from invented data; seeded demo results are produced by the real pipeline.
- Statistics pass reference fixtures; the simulation suite's false positive rate and coverage are within their bands; the reviewer's sign-off is recorded before `experiments.verdicts_enabled` is turned on in production.
- SRM, guardrail, crossover, interruption, delayed-data and early-stop warnings each have a test that triggers them.
- A unit exposed just before stop whose conversion arrives inside its attribution window and the lateness policy is counted in the final result (`FinalizationWindowTest`).
- One active experiment per flag and environment holds under concurrency.
- Free and downgraded plans behave as specified; Cypress M11 specs pass.

## 8. Risks and open questions

- **D07 build-ahead.** The implementation plan says M11 cannot build ahead of D07. This plan builds the pipeline and a pluggable default, and keeps verdicts off in production until sign-off. Shei should confirm. **Resolved (R17, 8 October 2026): the default above is the decision.**
- **`starting` status** is not in the domain model (draft → running → stopped). It represents "rule drafted, waiting for review and publish". Alternative: stay `draft` with `start_requested_at`.
- **Pause button** still appears in canvas 1c, 1d, 2b, 2c and 2e; R04 removes it.
- **Stop option wording differs.** Canvas 2b: "Stop without changing: keep the 50/50 split, stop collecting results". Prototype: "Stop without a decision: remove the experiment rule". This plan follows the canvas; the prototype's version equals "Keep control" only if the flag's default is control.
- **"Chance to beat control" versus "95% range".** Canvas implies a Bayesian probability, the prototype computes a normal interval. The default reconciles them under a flat prior; D07 must choose the wording and method.
- **Plan input differs.** Canvas 2a uses "Run for 21 days · ~120k users"; the prototype uses users per version (5k, 10k, 25k). This plan uses users per version and shows the estimated end date once data arrives.
- **Environment.** Prototype copy says "Experiments run in production" while 2a lets you pick an environment. Default: any environment, production preselected.
- **Who can start.** The Overview mockup shows Priya Nair (Developer) starting an experiment; the capability table gives `experiments.manage` to Owners and Publishers only.
- **Percentile guardrails** ("Page load p75", "API latency p95" in the prototype) are not supported by the default aggregations; they need D07 and a different method.
- **Mockup numbers disagree** between canvas (41,208 users) and prototype (8,210 users) for the same experiment; the seed uses generated data, so Cypress asserts structure and states rather than mockup numbers.
- **Statistical expertise.** The reviewer is not yet named; the release gate depends on one.
