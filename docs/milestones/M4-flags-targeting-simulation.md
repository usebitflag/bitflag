# M4. Flags, targeting and simulation

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Expands M4 in the [implementation plan](../implementation-plan.md).

## 1. Goal and scope

M4 lets people model flags and edit per-environment drafts safely, then see exactly what a draft or a published revision would return for a given context. It ends where publishing starts: M4 produces versioned drafts, a structural diff and the compiler input; M5 freezes them into review candidates, approves and publishes.

**In scope**

- Flags: key, name, description, type, typed variations with versioned values, owner, tags, lifecycle intent (temporary with remove-by date, or permanent), lifecycle status (`draft`, `active`, `deprecated`, `archiving`, `archived`), client visibility, clone, bulk tag/owner/archive.
- Project flag defaults ("New flags" section of project Settings): key format, require description, require owner, client-visible default, stale-after days. Stored as M3's `projects` columns (`flag_key_format`, `require_flag_description`, `require_flag_owner`, `client_visible_by_default`, `stale_after_days`); M4 enforces them and M10 reads `stale_after_days`.
- Environment drafts (`environment_flag_drafts`) with `draft_version` and `overrides_version` optimistic concurrency, an environment-level draft head, enabled switch, off and default variations, ordered rules with stable IDs, all/any condition groups with bounded depth, allocations with salts and slot weights out of 100,000, rollout-safety warnings, overrides with expiry and masking, prerequisites with cycle and depth checks.
- Segments with immutable (sealed) versions, one open draft version, per-environment version pins, include/exclude lists, "used by" analysis, preview ("Check a user"), delete blocking.
- The archive workflow up to staging removals in every environment draft. M5 publishes the removals; M4 finalizes the archive once no live snapshot contains the flag.
- The four editor states and the draft vs published presentation, including the fix for the env badge bug (frontend-and-design.md §3).
- The snapshot compiler (pure, in `app/Modules/Publishing/Domain/Compiler`), shared with M5, and the simulator that evaluates compiled output with `bitflag-php`.
- Saved test contexts ("test users") used by the simulator presets and later by M5's "How test users are affected" table.
- Flag list with filters, search, sort, cursor pagination and saved-view URLs.
- Management API for flags, variations, drafts, overrides, segments, simulation, flag defaults and test contexts.

**Out of scope (and where it lives)**

| Item | Milestone |
| --- | --- |
| Change sets, review candidates, approvals, publication transaction, snapshots table, revision pointer, public projection rules, rollback, emergency disable, Changes page | M5 |
| Status bar states "Awaiting approval", "Approved", "Revision published", "New edits require another review", "Publish failed" (M4 ships the component and the slots) | M5 |
| Insights tab data, "Last evaluated" column, usage-based "Needs attention" reasons, stale flag detection | M10 |
| Deciding when experiment rules (`source: experiment`) are placed into drafts | M11, through the M4 actions in §3.3 "Experiment rule actions (used by M11)"; M4 renders them locked |
| Flag reminder notifications (past removal date, owner left) | M8 consumes M4 queries |
| Plan limit enforcement (Free: 10 flags) | M6 implements the `EnforcePlanLimit` and `PlanRestrictionGate` contracts (`app/Shared/Plans`) M4 calls |
| Webhook delivery of `flag.changed` | M9 (M4 writes the outbox message) |

**Boundary with M5**

| M4 produces | M5 consumes / does |
| --- | --- |
| `environment_flag_drafts` (config document, `draft_version`, `overrides_version`, `content_hash`, contributors) and `environment_draft_states.draft_version` | Records these versions and the head in a review candidate; treats a head change as a stale candidate |
| `segment_versions` (draft or sealed) and `environment_segment_pins` | Freezes compiled content (later edits only invalidate the candidate through the draft state), then seals the published versions through `RecordPublishedBaseline` inside the publication transaction |
| `Flags\Events\EnvironmentDraftChanged` and `Targeting\Events\SegmentDraftChanged`, dispatched inside every draft-writing transaction | M5's `InvalidateOpenChangeSet` listener marks the open change set invalid in the same transaction |
| `FlagConfigDocument` schema, `FlagConfigDiff`, `DraftValidator` | Stores the frozen document, renders the review diff, re-runs validation at submit and publish |
| `SnapshotCompiler` + `EnvironmentDraftChanges` (server-shaped flags and segments map) | Adds envelope (revision, generation, checksum, publishedAt), builds the public projection, runs publish-only checks, stores snapshots |
| `PublishedStateReader` and `ReviewStateReader` interfaces with null implementations | Implements both against revisions and change sets |
| Actions `RecordPublishedBaseline` (sets `first_published_at`, `draft → active`, clears contributors, seals included segment versions) and `CompleteFlagArchive` | Calls the first inside the publication transaction; the second runs from the outbox consumer in §3.6 |

## 2. Mockups covered

**`BitFlag Flags.dc.html`** (canvas, 10 artboards)

| Artboard | Label | Behaviors implied |
| --- | --- | --- |
| 1a | Flag list | Columns Flag (key + name), Type, Lifecycle, Status in {env}, Owner, Tags, Changed, Last evaluated; filter chips Type, Needs attention, Owned by me, Show archived, Tag; Sort "Last changed"; "Showing 11 of 12 flags" (archived hidden by default) |
| 1b | Create flag, type Boolean | Display name, key with availability check, type locked after first publish, Boolean variations fixed Off/On, Markdown description, owner, tags, lifecycle Temporary with "Remove by" date or Permanent config, "Visible to browser SDKs" opt-in with public warning, usage snippet (JS and PHP/Laravel), fallback explanation, "Every environment starts disabled, serving the off variation" |
| 1c | Flag detail, Rules, production, "Show 8 environments" | Header (name, lifecycle, intent, key, type, owner, tags, changed), env cards with collapse beyond the first few, tabs Configuration / Rules 3 / Overrides 4 / Variations 2 / Insights / History / Settings, rule cards, "Try a user" panel, status bar. Shows the prototype bug: the production card reads "Enabled · Rule 02: 50%" from the draft |
| 2a | Editing draft, Draft saved | "Editing draft" bar with "View published (rev 42)"; env cards lead with "Live:" and list "Draft:" separately; rule shows "published: 20%" next to the draft 50%; status bar "Draft saved · Production is unchanged" with "Discard draft / Review & publish" |
| 2b | Viewing published, read-only | Read-only published view, controls disabled, note "A draft exists: increase to 50%", "Edit draft" returns. Contains a second prototype bug: slots read true 20% / false 50% |
| 2c | Unsaved changes | Local edits only change the status bar ("Rollout 20% → 50% in production", Discard / Save draft); the Live line does not move |
| 2d | Publish failed, draft kept | M5 state; M4 provides the slot and keeps the draft untouched |
| 2e | Review invalidated by a new edit | M5 state; M4 shows "published: 20% · approved: 50%" annotation when M5 supplies the approved document |
| 2f | Conflict | Banner "This draft changed while you were editing · Priya Nair saved a change to rule 01"; local edits kept; "Discard mine / Compare & merge" |
| 2g | Editor status states | Seven states, each icon + label (no color-only cue); "Published" only after server confirmation |

**`portal/Flags.dc.html`** (clickable prototype, template + logic)

| Screen or modal | Behaviors implied |
| --- | --- |
| List | Filter popover groups Type, Status in {env} (Enabled / Disabled / Not published), Lifecycle, Owner ("Me"), Tag, Other (Needs attention); active filter chips with Clear; sorts changed / key / last evaluated; pencil marks unpublished changes; empty "No flags match these filters"; row selection with bulk bar (Add tag, Change owner, Archive…) |
| Create | Key regex `^[a-z][a-z0-9_-]{0,99}$`, availability, JSON validity "under the 32 KB limit", finite numbers; creates drafts disabled everywhere |
| Detail header and cards | More menu: Simulate, Clone flag, Mark deprecated, Archive…; archived banner "Archived. This flag isn't in new snapshots, so SDKs return your fallback. It's read-only; history stays available." |
| Configuration tab | Enabled switch with help text, off variation, default variation, visibility text, "Draft vs published · N changes" diff |
| Rules tab | Rule menu Edit / Duplicate / Move up / Move down / Disable / Delete…; "Everyone" rule; Serve "A variation" or "Percentage rollout"; "Going below the published 20% moves about N% of matching users…"; "No rule matched · Serve default … · Change" |
| Overrides tab | "Checked before rules, in {env} only. Max 500."; table Subject / Serves / Reason / Added by / Expires; Add override (kind user/account/device, key, serve, expires 7 days / 30 days / Never, reason); Import CSV "kind, key, variation, reason. Up to 500." with valid/skipped summary; Remove override |
| Variations tab | Variation, "Used in {env}" (off/default role, rules/overrides count), "Can't delete while in use" |
| Insights / History / Settings | Insights empty state (M10 data); History timeline; Settings: key and type "locked after first publish", Clone, Deprecate, Archive with copy distinguishing archive from disable |
| Simulator drawer | "Simulate {key} · In {env}. What you enter isn't saved or logged."; JSON context; presets (PH pro user, Acme staff, US free user, No key); errors for invalid JSON and missing `kind`; Draft vs Live results; "Publishing this draft changes the result for this user." |
| Modals | Switch environment with unsaved changes (Stay / Discard and switch / Save draft and switch), Discard draft, Archive (type key to confirm), Bulk archive with per-item blockers ("Blocked: new-checkout uses it as a prerequisite"), Bulk tag, Bulk owner, Delete rule, Compare across environments. Publish, success and rollback modals are M5 |

**`BitFlag Segments.dc.html`** (canvas, 9 artboards)

| Artboard | Label | Behaviors implied |
| --- | --- | --- |
| 1a / 1b | List as table / cards | Columns Segment, Who's in it (summary), Size, Used by, Version ("v5 draft"); filter "Used by Any"; search. Plan builds 1a (table) |
| 1c | Detail: condition builder | "Draft v5", "Used by 2 flags in 3 environments", condition groups, Used by table (Flag, Rule, Environments), Check a user, Versions list (Draft, Live in production · rev 42, older) |
| 1d | Detail: plain-language summary | "In plain words" sentence generated from the definition; Estimated size; Used by; Last published version |
| 2a | New segment | Name, key availability, description, start from Conditions or "A list of users · up to 10,000" |
| 2b | Check a user: match | "In this segment · Matched 'email ends with @acme.dev' in the also-include group" |
| 2c | Delete blocked: in use | "Beta testers is still in use. Remove it from these rules first." with flag/environment table |
| 2d | Empty | "No segments yet" with explanation and "New segment" |
| 2e | Viewer, read-only | Banner "You're a Viewer. Everything here is read-only." |

**`portal/Segments.dc.html`**: environment-scoped view ("Who's in this segment · in {env}"), "Always include these users", "Also include users who match all/any of these conditions", "Never include these users · wins over everything above", Save draft / Reset / Unsaved changes, "Draft in {env}. Users still match the published version until you publish {env}. · Go to Changes", Used by per environment, "Check a user · using your unsaved edits" with presets, Versions, Create ("It starts empty in every environment"), Delete (blocked or allowed), "Leave without saving?".

**Also used:** `BitFlag Settings.dc.html` and `portal/Settings.dc.html` (General → "New flags" section), `BitFlag Design System.dc.html` §08 (Rule card, Diff viewer, Evaluation result), `BitFlag Changes.dc.html` 1a (drafts saved with failing checks such as "Weights add up to 110%"; "How test users are affected").

## 3. Design

### 3.1 Data model

Rules, condition groups and prerequisites are stored as one validated JSON document per draft, not as `rules`, `condition_groups` and `prerequisites` rows (domain-model.md "Proposed persistence groups" leaves this open). Reasons: one version check covers the whole configuration, rule order and stable IDs live in one place, M5 can freeze and hash the exact same document, and the compiler is a pure function of it. A derived `draft_references` index replaces relational joins for "used by" and delete blocking. Overrides stay relational because they are numerous, sensitive, individually managed and imported.

| Table | Columns (all tenant tables also have `organization_id`, `project_id`; timestamps `timestamp(6)`) | Indexes and constraints |
| --- | --- | --- |
| `flags` | `id` ulid, `key` varchar(100), `name` varchar(120), `description` text null (max 5,000 chars), `type` enum(boolean,string,number,json), `status` enum(draft,active,deprecated,archiving,archived), `status_before_archive` enum null, `intent` enum(temporary,permanent), `remove_by` date null, `owner_user_id` ulid null, `client_visible` bool, `json_schema` json null, `first_published_at` null, `archive_requested_at` null, `archive_requested_by` null, `archived_at` null, `last_changed_at`, `version` int, `created_by`, `created_at`, `updated_at` | unique (`project_id`,`key`); (`organization_id`,`project_id`,`status`,`last_changed_at`,`id`); (`organization_id`,`project_id`,`owner_user_id`); FULLTEXT (`name`,`description`) |
| `flag_tags` | `flag_id`, `tag` varchar(40) | PK (`flag_id`,`tag`); (`organization_id`,`project_id`,`tag`) |
| `variations` | `id`, `flag_id`, `key` varchar(64), `name` varchar(80) null, `description` varchar(500) null, `position` smallint, `latest_value_id` ulid, `status` enum(active,removed), `created_at`, `updated_at` | unique (`flag_id`,`key`) (keys never reused) |
| `variation_values` | `id` (`vvl_`), `flag_id`, `variation_id`, `version` int, `value` json, `value_bytes` int, `value_sha256` char(64), `created_by`, `created_at` | unique (`variation_id`,`version`); immutable (no update path) |
| `environment_flag_drafts` | `id`, `environment_id`, `flag_id`, `config` json (`FlagConfigDocument`), `draft_version` int unsigned, `overrides_version` int unsigned, `removal_staged` bool, `issues` json, `has_blocking_issues` bool, `acknowledged_warnings` json, `content_hash` char(64), `contributors` json (user IDs and channels since last publish), `last_edited_by`, `last_edited_at`, `created_at`, `updated_at` | unique (`environment_id`,`flag_id`); (`organization_id`,`project_id`,`environment_id`,`last_edited_at`) |
| `environment_draft_states` (the "draft head" below; name matches M5) | `environment_id` PK, `draft_version` bigint unsigned, `updated_at` | Row locked `FOR UPDATE` first by every draft write in that environment; M5 reads it `FOR SHARE` |
| `subject_overrides` | `id` (`ovr_`), `environment_id`, `flag_id`, `subject_kind` varchar(64), `subject_key` varchar(256), `subject_fingerprint` char(64), `variation_key` varchar(64), `reason` varchar(500), `expires_at` null, `source` enum(dashboard,api,import), `created_by`, `created_at` | unique (`environment_id`,`flag_id`,`subject_kind`,`subject_key`); (`organization_id`,`project_id`,`environment_id`,`flag_id`,`expires_at`) |
| `draft_references` | `environment_id`, `source_flag_id`, `ref_type` enum(segment,prerequisite,variation), `ref_id` ulid, `rule_id` varchar(32) not null default `''` (empty string when the reference is not inside a rule, for example a prerequisite or the off variation; MySQL primary-key columns cannot be null), `detail` json | PK (`environment_id`,`source_flag_id`,`ref_type`,`ref_id`,`rule_id`); (`organization_id`,`project_id`,`ref_type`,`ref_id`); rewritten on every draft save |
| `segments` | `id`, `key` varchar(100), `name` varchar(120), `description` varchar(1000) null, `owner_user_id` null, `status` enum(active,deleted), `deleted_at` null, `version` int, `created_by`, `created_at`, `updated_at` | unique (`project_id`,`key`) |
| `segment_versions` | `id` (`sgv_`), `segment_id`, `number` int, `status` enum(draft,sealed), `match` json (condition group), `include_count` int, `exclude_count` int, `content_hash` char(64), `lock_version` int, `based_on_version_id` null, `created_by`, `updated_by`, `sealed_at` null, `created_at`, `updated_at`; generated `open_draft_segment_id` = IF(status='draft', segment_id, NULL) | unique (`segment_id`,`number`); unique (`open_draft_segment_id`) so a segment has at most one open draft |
| `segment_version_subjects` | `segment_version_id`, `list` enum(include,exclude), `subject_kind`, `subject_key`, `subject_fingerprint` | PK (`segment_version_id`,`list`,`subject_kind`,`subject_key`); copied with `INSERT … SELECT` when a new draft version starts |
| `environment_segment_pins` | `environment_id`, `segment_id`, `segment_version_id`, `pinned_by`, `pinned_at` | PK (`environment_id`,`segment_id`) |
| `test_contexts` | `id` (`tcx_`), `name` varchar(80), `context` json (max 8 KB), `version` int, `created_by`, `updated_by`, `created_at`, `updated_at` | unique (`project_id`,`name`) |

New-flag defaults have no M4 table: they are M3's `projects` columns, saved through M3's `UpdateProjectSettings`.

New public ID prefixes: `rule_`, `ovr_`, `sgv_`, `vvl_`, `tcx_`. No soft deletes: variations use `status=removed`, segments `status=deleted`.

**`FlagConfigDocument` (`flag-config.v1`)**, stored in `environment_flag_drafts.config`, frozen by M5 as the published copy. JSON Schema at `contracts/management/flag-config.v1.schema.json`; TypeScript types generated into `resources/js/types/flag-config.ts`.

```json
{
  "schema": "flag-config.v1",
  "enabled": true,
  "offVariation": "off",
  "defaultVariation": "off",
  "variationValues": { "off": "vvl_01J…", "on": "vvl_01J…" },
  "prerequisites": [ { "flagId": "flg_01J…", "variations": ["on"] } ],
  "rules": [ {
    "id": "rule_01J9…", "name": "PH premium", "description": null, "enabled": true, "source": "user",
    "match": { "all": [
      { "attribute": "country", "op": "eq", "values": ["PH"] },
      { "attribute": "plan", "op": "in", "values": ["pro", "team"] },
      { "segmentId": "seg_01J…", "op": "in_segment" } ] },
    "serve": { "rollout": { "bucketBy": "key", "salt": "s_k3m9…",
      "slots": [ { "variation": "on", "weight": 50000 }, { "variation": "off", "weight": 50000 } ],
      "remainderVariation": "off" } }
  } ]
}
```

References use IDs (`flagId`, `segmentId`) so renames never change behavior. Overrides are not in the document; they are rows, and the content hash covers them.

**Limits** (defaults proposed under D03/D06/D09, held in `config/bitflag-limits.php` and enforced in `DraftValidator`):

| Limit | Default | Source |
| --- | --- | --- |
| Variations per flag | 2 to 20 (Boolean exactly 2) | proposal |
| JSON variation value | 32 KB | portal create copy |
| String variation value | 4 KB | proposal |
| Number value | finite, integers within ±(2^53 − 1) | D03 numeric representation |
| Rules per flag and environment | 100 | proposal |
| Condition group depth / conditions per rule | 3 levels / 50 | proposal (D03) |
| Values per `in` condition | 1,000 | proposal |
| Overrides per flag and environment | 500 | portal Overrides copy |
| Segment include and exclude lists | 10,000 each | Segments 2a copy (D06/D09) |
| Prerequisites per flag / chain depth | 10 / 5 | proposal (D03) |
| Simulation context | 8 KB JSON, 50 attributes, key 256 chars, value 1,024 chars, arrays 100 items | protocol §1 bounds (D03) |
| Tags per flag | 20, pattern `^[a-z0-9][a-z0-9._:-]{0,39}$` | proposal |

### 3.2 Domain rules and state machines

**Flag keys and types.** Key pattern `^[a-z][a-z0-9_-]{0,99}$`, further narrowed by `projects.flag_key_format` (M3) (kebab: `^[a-z][a-z0-9-]*$`; snake: `^[a-z][a-z0-9_]*$`). Key and type are editable until `first_published_at` is set (M5 calls `RecordPublishedBaseline`); afterwards `FLAG_KEY_LOCKED` / `FLAG_TYPE_LOCKED`. A type change before first publish must resupply variations and resets every environment draft to the initial configuration. Variation values are validated against the type; JSON values also against `flags.json_schema` when present (proposed library: `opis/json-schema`). Boolean flags always have variations `off` = false and `on` = true; their keys and values are fixed.

**Initial drafts.** Creating a flag writes no draft rows. A missing `environment_flag_drafts` row means the initial configuration: disabled, off and default = first variation, no rules (1b: "Every environment starts disabled, serving the off variation"). The first write materializes the row.

**Lifecycle state machine.**

| From | To | Trigger | Guard |
| --- | --- | --- | --- |
| `draft` | `active` | `RecordPublishedBaseline` (M5, first publish in any environment) | none |
| `active` | `deprecated` | `DeprecateFlag` | none; flag keeps serving, attention reason "Deprecated" |
| `deprecated` | `active` | `ReactivateFlag` | none |
| `draft`, `active`, `deprecated` | `archiving` | `RequestFlagArchive` (type the key to confirm) | no other flag's draft or live config uses it as a prerequisite; no running experiment (M11 query, null until then) |
| `archiving` | previous status | `CancelFlagArchive` | restores un-published removals; environments already removed get a draft rebuilt from their last published document |
| `archiving` | `archived` | `CompleteFlagArchive` (system) | `PublishedStateReader` shows no live revision containing the flag |
| `archived` | `active` (or `draft` if never published) | `RestoreFlag` | plan limit check; drafts rebuilt from the last published document of each environment, not live until published |

**Archive workflow (D12 default).** `RequestFlagArchive` sets `status=archiving` and, in one transaction, sets `removal_staged=true` on every environment draft where the flag is live; environments where it was never published need nothing. Each staged removal bumps that environment's draft head and appears in M5's change set, so protected environments keep their review. The editor shows per-environment progress ("Removed from 1 of 3 environments · production removal awaiting publish") rather than claiming all environments stopped. While archiving, drafts are read-only except Cancel. Archived flags are read-only, excluded from compilation, and do not count toward the plan's flag limit; `archiving` still counts. Code-reference and usage evidence (M9/M10) are shown as warnings only.

**Variation values.** Editing a value creates `variation_values` version n+1 (`CreateVariationValue`) and pins it in the drafts of the environments the user selects (default: all environments, each checkbox shown with its protection badge). Published snapshots keep their inlined value. A variation can be removed only when no draft, no published revision inside the rollback window (M5 query), no rule, override or prerequisite, and no experiment references it (`VARIATION_IN_USE` with the list).

**Drafts and concurrency.**

- `draft_version` guards the config document; `overrides_version` guards the override set. Separate counters let someone add an override while another person edits rules, without a false conflict.
- `environment_draft_states.draft_version` advances on every evaluation-affecting write in that environment: draft save or discard, override change, value pin, segment pin, segment draft edit for pinned environments, removal staging, client-visibility change (all environments). M5 compares it with the head stored in a candidate.
- Lock order in every action: environment head rows (ascending `environment_id`), then draft rows, then segment versions. A mismatch on the expected version throws `VersionConflict` (HTTP 409 `VERSION_CONFLICT`) with `details: { expectedVersion, currentVersion, changedBy, changedAt, current }`.
- `content_hash` = SHA-256 over the canonical JSON (RFC 8785, pending D03) of: flag type, `client_visible`, the config document with value version IDs replaced by `value_sha256`, unexpired overrides sorted by (kind, key), and the `content_hash` of every referenced pinned segment version. "Has unpublished changes" means `content_hash` differs from the hash of the live published document (from `PublishedStateReader`). A pin or segment edit recomputes the hashes of referencing drafts in the same transaction (bounded by `draft_references`).
- `contributors` accumulates (user, channel) for every write since the last publish; M5 resets it and uses it for D02 self-approval checks.

**Validation tiers** (`DraftValidator`, shared with M5):

| Tier | Examples | Effect |
| --- | --- | --- |
| Rejected (422 `VALIDATION_FAILED`, field paths like `rules[1].serve.rollout.slots[0].weight`) | Document fails schema, unknown operator, value of wrong type, reference to an ID outside the project, limits exceeded, prerequisite cycle or depth | Not saved |
| Blocking issue (saved, `has_blocking_issues=true`) | Empty condition value list, weights above 100,000 (Changes 1a "Weights add up to 110%"), missing remainder variation, segment not pinned in this environment, prerequisite flag archived or not live here | Shown inline and in a page summary; simulator refuses that flag's closure; M5 refuses to freeze |
| Warning | Rollout movement (below); client-visible flag that has overrides, uses a segment with include or exclude lists, or has a prerequisite that is not client-visible (M5 rejects these at publication, D06); deprecated prerequisite | Shown; movement warnings need acknowledgement |

**Conditions and operators.** Condition group: `{ "all": [...] }` or `{ "any": [...] }`, nested up to depth 3. Empty root `all` means everyone ("Everyone" rule); empty `any` is rejected (D03 asks to fix empty-group semantics). Segment conditions are not allowed inside segment definitions (no segment nesting, so no cycles). Operator catalog (`Targeting\Domain\OperatorCatalog`, mirrored by `useConditionOperators`):

| UI label | Protocol op | Value type | Notes |
| --- | --- | --- | --- |
| is / is not | `eq` / `neq` | string, number, boolean | No coercion; value type chosen in the row |
| is one of / is not one of | `in` / `not_in` | string, number | Chips input, max 1,000 |
| starts with / ends with / contains | `starts_with` / `ends_with` / `contains` | string | "Ignore case" toggle sets `ignoreCase` |
| at least / at most / more than / less than | `gte` / `lte` / `gt` / `lt` | number | Finite numbers only |
| version at least / version below / version is | `semver_gte` / `semver_lt` / `semver_eq` | SemVer string | Separate from numeric (portal prototype mixed them) |
| before / after | `before` / `after` | ISO 8601 date-time | UTC instants |
| is set / is not set | `exists` / `not_exists` | none | The only missing-attribute tests |
| in segment / not in segment | `in_segment` / `not_in_segment` | segment | Segment picker |

The editor explains the proposed missing-attribute rule next to negated operators: "Users without `country` don't match 'is not'. Add 'country is not set' with Any to include them." Attribute names are free text with suggestions from attributes already used in the project, plus `kind` and `key`.

**Allocations and rollout safety.** Weights are integers out of 100,000; the UI shows percentages with up to three decimals. The sum must be at most 100,000; any remainder serves `remainderVariation` (copy says "remainder variation", not "fallback"). For a single-variation rollout the rollout variation stays in slot 0. `bucketBy` defaults to `key`. Salts are `s_` + 22 random base32 characters from `SaltGenerator`, created with the rule and kept across edits; "Duplicate rule" and "Clone flag" generate new salts; "Reshuffle" (explicit menu item) regenerates one with a warning. `AllocationChangeAnalyzer` compares each draft rule with the live rule of the same ID and emits:

| Code | When | Message (example) |
| --- | --- | --- |
| `SLOT_WEIGHT_REDUCED` | Any slot's interval shrinks | "Going below the published 20% moves about 25% of matching users from true back to false." |
| `SLOTS_REORDERED` | Slot order changes | "Reordering slots moves users between variations." |
| `SALT_CHANGED` / `BUCKET_BY_CHANGED` | Salt or bucketBy differs | "All matching users are reshuffled; about N% get a different variation." |
| `AUDIENCE_CHANGED` | Conditions of a rule with a rollout change | "Changing who matches can move users in and out of this rollout." |
| `RULE_ORDER_CHANGED` / `RULE_REMOVED` | Precedence changes or a live rule is deleted | "Users matching both rules may get a different value." |

Moved share is computed exactly from bucket intervals: with the same salt and bucketBy, it is the measure of buckets whose slot variation differs between live and draft, divided by 100,000; with a new salt it is `1 − Σ p_i·q_i` over variations. Raising the first slot only adds users, so no warning ("Users already getting true keep it. Raising the rollout only adds users."). Saves that introduce a new warning must include its fingerprint in `acknowledge` or get 422 `ROLLOUT_CHANGE_UNACKNOWLEDGED`; acknowledged fingerprints persist in `acknowledged_warnings`, so the same warning is not asked again on each save. The shared fixture set `tests/Fixtures/rollout-impact/*.json` drives both the PHP analyzer and the `useRolloutImpact` composable.

**Overrides.** Exact (kind, key) → variation with required reason and optional expiry (7 days, 30 days, Never, or a date). Kinds follow the context `kind` pattern; user/account/device are suggested. Unique per draft subject; max 500. Overrides are draft content: adding or removing one changes nothing live until M5 publishes (the portal prototype applied them immediately; this plan does not). Expired overrides stay visible with an "Expired" badge and are omitted by the compiler; the evaluator still checks `expiresAt` against evaluation time for unexpired ones. "Remove expired" is a bulk draft change. CSV import (`kind,key,variation,reason`, max 500 lines, 1 MB) runs as a dry run first: valid, skipped (unknown kind, missing key, variation not in flag), and "replaces existing" counts. `subject_fingerprint` is HMAC-SHA256 of `kind:key` with a per-organization secret; audit stores the fingerprint, never the key.

**Prerequisites.** Ordered list of (flag, required variations) in the same project, evaluated in the same environment. No self-reference; the target must not be archived or archiving. `PrerequisiteGraph` loads the environment's prerequisite edges from `draft_references` under the head lock, adds the proposed edges and rejects cycles (`PREREQUISITE_CYCLE`, with the path) and chains deeper than 5 (`PREREQUISITE_DEPTH_EXCEEDED`). This authoring limit of 5 is deliberately below M1's evaluator safety ceiling of 10. A prerequisite that is valid in the draft but not live in the environment is a blocking issue ("payment-config is not published in production"). The picker excludes self and flags that would create a cycle and previews: "If payment-config isn't adyen, this flag serves its off variation (false)."

**Segments.**

- A segment has sealed versions and at most one open draft version. "Save draft" updates the open draft (guarded by `lock_version`) or creates version n+1 copied from the version pinned in the current environment.
- Each environment pins exactly one version (`environment_segment_pins`). Saving a draft in an environment pins it there; other environments keep their pin and show "v5 draft available · Use in staging". `CreateSegment` creates v1 as a draft and pins it in every active environment ("It starts empty in every environment"). A new environment created in M3 gets pins via the `PinSegmentsForNewEnvironment` job (§3.6).
- Editing an open draft version that several environments pin bumps each of those heads and recomputes referencing draft hashes.
- When an environment publishes, M5's call to `RecordPublishedBaseline` seals the segment versions it inlined; sealed versions are immutable, so the next edit starts a new draft version. A candidate that points at a still-open draft version is safe because its content is frozen and any edit advances the environment draft state, which invalidates it.
- Precedence inside a version: exclude list, then include list, then the condition group. Lists hold (kind, key) and are sensitive.
- "Used by" = `draft_references` (drafts) plus published references from `PublishedStateReader`, grouped by flag, rule and environment, with "serves" text.
- `DeleteSegment` is blocked (`SEGMENT_IN_USE`, 2c) while any draft, live snapshot or experiment (M11) references it; otherwise `status=deleted`, pins removed, key stays reserved.
- The plain-language summary (1d) is generated by `SegmentSummarizer` on the server so the list, detail and Changes share one wording.

**Four editor states and the live-vs-draft rule.**

| State | Source of truth | How the portal shows it |
| --- | --- | --- |
| Local edits | `useFlagDraft` working copy in memory | Status bar "Unsaved changes · Not saved yet. Production still serves rev 42."; "Edited" marks on changed rules; nothing on env cards changes |
| Saved draft | `environment_flag_drafts` row | "Draft saved · Production is unchanged"; env card line "Draft: increase to 50%"; list pencil icon |
| Review candidate | M5 `ReviewStateReader` | Status bar states awaiting / approved / invalid; first local edit while a review is pending asks "Editing now cancels this review" |
| Published | M5 `PublishedStateReader` (live revision document + metadata) | Env card "Live:" line, Enabled/Disabled badge, "Published revision 42 · 2 hours ago", list "Status in {env}", "View published (rev 42)" |

Every live indicator (badge, live summary, revision line, list status, compare-dialog state column) is built by `FlagEnvironmentSummaryQuery` from one `PublishedFlagState` object. The draft summary is a separate `DraftPresence` object (`exists`, `summary`, `issuesCount`, `removalStaged`). The TypeScript type `EnvironmentCardDTO = { environment, live: PublishedFlagStateDTO | null, draft: DraftPresenceDTO }` gives the badge no path to draft data. This fixes the prototype bug (1c and `envCards` reading `cfg[...].on`). A draft enabled state, when shown, is labelled "Draft: enabled".

**Simulator semantics.**

- Sources: `local` (unsaved working copy posted with the request, validated, compiled, never stored), `draft` (saved drafts), `published` (live revision, or `revision=N` from M5's history). "Compare" evaluates two sources side by side and flags "Publishing this draft changes the result for this user."
- Draft compilation covers only the dependency closure of the flag: the flag, its prerequisites transitively, and referenced pinned segment versions. A blocking issue in the closure returns `DRAFT_HAS_ISSUES` with the list.
- Result: value, variation key, reason, rule (number and name), override match, prerequisite path, bucket with slot bounds ("31,870 vs 50,000"), revision or "draft", and a trace (each rule: skipped as disabled, condition results including "missing attribute", segment include/exclude/conditions outcome). Disabled rules are not compiled; the simulator adds them to the trace from the document.
- Inputs: context JSON (protocol §1 shape and bounds), typed fallback (defaults false, "", 0, null), optional evaluation time for expiry and date rules (default now).
- No exposures, no telemetry, no audit entry, no storage of the context; request bodies are excluded from logs and error reports. Rate limit 60 requests per minute per user.
- Presets come from `test_contexts` (saved deliberately with "Save as test user" and a warning not to use real customer data) plus a generated template that lists the attributes the flag's rules use.

**Compiler.** `Publishing\Domain\Compiler\SnapshotCompiler::compile(CompilerInput): CompiledFlags` is pure (no Eloquent, facades or clock; time comes in the input). It maps each `FlagConfigDocument` to the protocol flag entry: inline variation values, `offVariation`, `defaultVariation`, prerequisites by key, unexpired overrides, enabled rules with `id`, `match` (segment IDs resolved to keys) and `serve` (rollouts carry `remainderVariation`, and experiment rules also `experimentId` and `allocationId`, using M1's field names); `segments` keyed by segment key with `version`, `include`, `exclude` and `match`; and `requiredCapabilities` derived from the features used. Flags that are archived or have `removal_staged` are omitted. `EnvironmentDraftChanges` (Flags) and `PinnedSegmentsQuery` (Targeting) build `CompilerInput`. M5 wraps the output in the envelope and computes the checksum.

### 3.3 Actions

All actions are `final` with one `handle(Actor, Data)` method. Transactions write audit and outbox records. "Heads" means advancing `environment_draft_states.draft_version` for the affected environments and dispatching `EnvironmentDraftChanged` or `SegmentDraftChanged`.

| Action | Module | Input (main fields) | Capability | Transaction, audit, outbox |
| --- | --- | --- | --- | --- |
| `CreateFlag` | Flags | key, name, type, variations, description, ownerId, tags, intent, removeBy, clientVisible | `flags.draft.write` | `EnforcePlanLimit::handle(org, LimitKey::FlagsPerProject, project)` and `PlanRestrictionGate::assertConfigWritable(project)`; defaults enforced; flag, variations, values v1; `flag.created`; `flag.changed` |
| `UpdateFlagMetadata` | Flags | expectedVersion, name, description, ownerId, tags, intent, removeBy, clientVisible, key/type (unpublished only), jsonSchema | `flags.draft.write` | Client-visibility change bumps all heads and hashes; `flag.updated` with changed field names |
| `CloneFlag` | Flags | sourceFlagId, newKey, newName | `flags.draft.write` | Plan limit; copies variations, metadata, draft configs with new rule IDs and salts; no overrides; `flag.cloned` |
| `DeprecateFlag` / `ReactivateFlag` | Flags | flagId, expectedVersion | `flags.draft.write` | `flag.deprecated` / `flag.reactivated` |
| `RequestFlagArchive` / `CancelFlagArchive` | Flags | flagId, confirmKey | `flags.draft.write` | Dependency check, removal staging, heads; `flag.archive_requested` / `flag.archive_cancelled`; `internal.flag.archive_requested` |
| `CompleteFlagArchive` | Flags | flagId | system | `status=archived`; `flag.archived` |
| `RestoreFlag` | Flags | flagId | `flags.draft.write` | Plan limit; drafts rebuilt; `flag.restored` |
| `BulkUpdateFlags` | Flags | flagIds (max 100), operation (addTag, setOwner, archive) | `flags.draft.write` | Per-item result list; one audit event per flag sharing a correlation ID |
| `AddVariation` / `UpdateVariation` / `RemoveVariation` | Flags | flagId, key, name, description, value | `flags.draft.write` | Removal checks references; `variation.added` / `.updated` / `.removed` |
| `CreateVariationValue` | Flags | variationId, value, environmentIds | `flags.draft.write` | New value version, pins in chosen drafts, heads; `variation_value.created` |
| `SaveFlagDraft` | Flags | environmentId, flagId, expectedDraftVersion, sections (enabled, offVariation, defaultVariation, variationValues, prerequisites, rules), acknowledge[] | `flags.draft.write` | Head lock, version check, validation, `draft_references` rewrite, hash, contributors; `flag.draft_saved` (summary of changed sections, rule IDs); `flag.changed` |
| `DiscardFlagDraft` | Flags | environmentId, flagId, expectedDraftVersion, expectedOverridesVersion | `flags.draft.write` | Resets config and overrides to the live document (or initial); `flag.draft_discarded`; `flag.changed` |
| `AddOverride` / `RemoveOverride` / `RemoveExpiredOverrides` | Flags | environmentId, flagId, expectedOverridesVersion, kind, key, variation, reason, expiresAt | `flags.draft.write` + `sensitive.read` | `overrides_version`+1, head, hash; `override.added` / `.removed` (fingerprint only); `flag.changed` |
| `ImportOverrides` | Flags | environmentId, flagId, expectedOverridesVersion, csv, replaceExisting, dryRun | `flags.draft.write` + `sensitive.read` | Dry run returns summary only; commit writes all or nothing; `override.imported` with counts |
| `SaveTestContext` / `DeleteTestContext` | Flags | name, context, expectedVersion | `flags.draft.write` + `sensitive.read` | `test_context.saved` / `.deleted` (name only) |
| `RecordPublishedBaseline` | Flags | environmentId, revision, included flag IDs and segment version IDs | system (M5, inside the publication transaction) | Sets `first_published_at` and `draft→active` on first publish, clears contributors, calls `SealSegmentVersions`; no own audit (M5 writes `revision.published`) |
| `SimulateFlag` | Flags | environmentId, flagKeys (1 to 50), source(s), revision, context, fallback, evaluateAt, localDocument | `project.read` | Read only: no transaction, audit or outbox |
| `CreateSegment` | Targeting | key, name, description, ownerId, startFrom (conditions or list) | `segments.write` | Segment, v1 draft, pins in all environments, heads; `segment.created` |
| `UpdateSegmentMetadata` | Targeting | expectedVersion, name, description, ownerId | `segments.write` | `segment.updated` |
| `SaveSegmentDraft` | Targeting | segmentId, environmentId, expectedLockVersion (or baseVersionId), match, includeAdd/Remove, excludeAdd/Remove | `segments.write` (+ `sensitive.read` for list edits) | Draft version create or update, pin in this environment, heads, hashes; `segment_version.saved`; `internal.segment.changed` |
| `PinSegmentVersion` | Targeting | segmentId, environmentIds, versionId | `segments.write` | Heads, hashes; `segment_version.pinned` |
| `DiscardSegmentDraft` | Targeting | segmentId, expectedLockVersion | `segments.write` | Repins environments to their previous sealed version; `segment_version.discarded` |
| `DeleteSegment` | Targeting | segmentId, expectedVersion | `segments.write` | Usage check; `segment.deleted` |
| `SealSegmentVersions` | Targeting | versionIds | system (called by `RecordPublishedBaseline`) | `status=sealed`; `segment_version.sealed` |
| `PreviewSegment` | Targeting | segmentId, environmentId, definition (optional unsaved), context | `project.read` | Read only |

**Experiment rule actions (used by M11).** Flags actions that M11's experiment lifecycle calls with a system or user actor; each takes `expectedDraftVersion`, takes the head lock and writes `flag.changed`:

| Action | Input (main fields) | Effect |
| --- | --- | --- |
| `PlaceExperimentRule` | environmentId, flagId, expectedDraftVersion, experimentId, allocationId, rule (match, slots, salt, `remainderVariation`), position | Inserts a rule with `source: experiment` and `experimentId` |
| `ReplaceExperimentRule` | environmentId, flagId, expectedDraftVersion, experimentId, new slots or allocation | Replaces the managed rule in place, keeping its rule ID |
| `RemoveExperimentRule` | environmentId, flagId, expectedDraftVersion, experimentId | Removes the managed rule |
| `ReleaseExperimentRule` | environmentId, flagId, expectedDraftVersion, experimentId, winning variation | Turns the managed rule into a normal user rule serving the chosen variation, clearing `experimentId` |

Managed-rule lock: a rule with `experimentId` refuses direct edits through `SaveFlagDraft` (422 `VALIDATION_FAILED`, "Managed by experiment {key}"); only these actions change it. `RequestFlagArchive`, `RemoveVariation` and type changes call M11's `ExperimentDependencies` (null implementation until M11) and are refused while an experiment is `starting` or `running`.

### 3.4 Routes

**Portal pages** (under M0's prefix `/{organization}/{project}/{environment}`, written here as `…`; the environment comes from the URL, chosen with the sidebar environment picker):

| Route | Inertia page | Main props |
| --- | --- | --- |
| `GET …/flags` | `flags/Index` | `flags` (cursor page of `FlagListRowDTO`: key, name, type, lifecycle, liveStatus, hasDraft, owner, tags, changedAt, lastEvaluatedAt null until M10, attention[]), `filters`, `filterOptions`, `counts` (shown, total), `environment`, `can` |
| `GET …/flags/create` | `flags/Create` | `defaults` (project flag defaults), `members` (owner picker), `existingTags`, `can` |
| `GET …/flags/{flag}?tab=&view=draft\|published` | `flags/Show` | `flag`, `environments` (ordered, protection), `cards` (`EnvironmentCardDTO[]`), `draft` (document, versions, issues, acknowledgedWarnings, lastEditedBy/At), `published` (`PublishedFlagStateDTO` or null), `diff`, `variations` (with value versions and per-environment pins), `segmentsCatalog`, `review` (null until M5), `can`; lazy: `overrides`, `history`, `prerequisiteCandidates`, `testContexts` |
| `GET …/segments` | `segments/Index` | `segments` (rows with summary, usedBy, pinned/draft state in env, updatedAt), `environment`, `can` |
| `GET …/segments/{segment}` | `segments/Show` | `segment`, `pinnedVersion`, `openDraft`, `subjects` (masked or full), `usedBy`, `versions`, `summary`, `can` |

Project Settings → General → "New flags" is a section component (`features/flags/ProjectFlagDefaultsForm.vue`) mounted in M3's settings page, posting to M3's `UpdateProjectSettings`.

**Portal JSON commands** (web middleware, session + CSRF, same actions; JSON so the editor keeps local state on 409): `POST …/flags`, `PATCH …/flags/{flag}`, `PUT …/flags/{flag}/draft`, `DELETE …/draft`, `POST …/flags/{flag}/simulate`, override, variation, lifecycle, bulk, segment and test-context commands mirroring the API below.

**Management API** (`routes/api.php`, prefix `/api/v1`; token scopes per D14 proposal: `flags:read` for reads, `flags:write` for writes including segments):

| Method and path | Action / query |
| --- | --- |
| `GET /projects/{project}/flags` (`q`, `type[]`, `status[]` with `environment`, `lifecycle[]`, `owner`, `tag[]`, `attention`, `sort`, `cursor`, `limit` ≤ 100) | `FlagListQuery` |
| `POST /projects/{project}/flags` · `GET`/`PATCH /projects/{project}/flags/{flag}` | `CreateFlag`, `FlagDetailQuery`, `UpdateFlagMetadata` |
| `POST …/flags/{flag}/clone` · `/deprecate` · `/reactivate` · `/restore` | lifecycle actions |
| `POST …/flags/{flag}/archive-request` · `DELETE …/flags/{flag}/archive-request` | `RequestFlagArchive`, `CancelFlagArchive` |
| `POST /projects/{project}/flags/bulk` | `BulkUpdateFlags` |
| `GET`/`POST …/flags/{flag}/variations` · `PATCH`/`DELETE …/variations/{variation}` · `POST …/variations/{variation}/values` | variation actions |
| `GET`/`PATCH`/`DELETE /projects/{project}/environments/{environment}/flags/{flag}/draft` | `FlagDraftQuery`, `SaveFlagDraft`, `DiscardFlagDraft` |
| `GET …/environments/{environment}/flags/{flag}/diff?against=published` | `FlagConfigDiff` |
| `GET`/`POST …/flags/{flag}/overrides` · `DELETE …/overrides/{override}` · `POST …/overrides/import` · `POST …/overrides/remove-expired` | override actions |
| `POST /projects/{project}/environments/{environment}/simulate` | `SimulateFlag` |
| `GET`/`POST /projects/{project}/segments` · `GET`/`PATCH`/`DELETE …/segments/{segment}` | segment actions |
| `GET …/segments/{segment}/versions[/{number}]` · `PUT …/segments/{segment}/draft` · `DELETE …/segments/{segment}/draft` · `POST …/segments/{segment}/pins` | version actions |
| `GET …/segments/{segment}/usages` · `POST …/segments/{segment}/preview` | `SegmentUsageQuery`, `PreviewSegment` |
| `GET`/`PATCH /projects/{project}/flag-defaults` · `GET`/`POST`/`PATCH`/`DELETE /projects/{project}/test-contexts[/{id}]` | defaults (reads M3's `projects` columns; `PATCH` calls M3's `UpdateProjectSettings`) and test contexts |

`PATCH …/draft` replaces each top-level section present in the body and leaves absent sections unchanged; `expectedDraftVersion` is required. Error codes added: `VERSION_CONFLICT`, `VALIDATION_FAILED`, `FLAG_KEY_TAKEN`, `FLAG_KEY_LOCKED`, `FLAG_TYPE_LOCKED`, `FLAG_IN_USE`, `FLAG_READ_ONLY`, `VARIATION_IN_USE`, `SEGMENT_IN_USE`, `PREREQUISITE_CYCLE`, `PREREQUISITE_DEPTH_EXCEEDED`, `ROLLOUT_CHANGE_UNACKNOWLEDGED`, `LIMIT_EXCEEDED`, `DRAFT_HAS_ISSUES`, `INVALID_CONTEXT`. No SDK endpoints in M4.

### 3.5 Frontend

| Area | Files |
| --- | --- |
| Pages | `resources/js/pages/flags/Index.vue`, `Create.vue`, `Show.vue`; `resources/js/pages/segments/Index.vue`, `Show.vue` |
| Flag list | `features/flags/FlagTable.vue`, `FlagFilterBar.vue` (popover groups + chips), `FlagBulkBar.vue`, `BulkArchiveDialog.vue`, `BulkTagDialog.vue`, `BulkOwnerDialog.vue`, `FlagListEmpty.vue` (no flags vs no matches) |
| Create | `CreateFlagForm.vue`, `VariationListEditor.vue`, `JsonValueEditor.vue` (syntax, size, schema), `UsageSnippet.vue`, `KeyAvailabilityField.vue` |
| Detail shell | `FlagHeader.vue`, `EnvironmentStateCards.vue` + `EnvironmentStateCard.vue` (collapse "Show N environments"), `CompareEnvironmentsDialog.vue`, `DraftModeBar.vue` ("Editing draft" / "Viewing published"), `FlagEditorStatusBar.vue`, `UnsavedChangesDialog.vue`, `DiscardDraftDialog.vue`, `ConflictMergeDialog.vue`, `ArchivedBanner.vue`, `ArchiveProgress.vue` |
| Tabs | `ConfigurationPanel.vue` (`EnabledSwitch`, `OffDefaultVariationSelect`, `PrerequisiteEditor`, `ClientVisibilityNote`, `DraftDiffPanel`), `RulesPanel.vue` (`RuleCard`, `RuleMenu` with Move up/down and Alt+Arrow, `ConditionGroupEditor`, `ConditionRow`, `ServeEditor`, `AllocationEditor`, `RolloutImpactNotice`, `DefaultRuleRow`), `OverridesPanel.vue` (`OverrideTable`, `AddOverrideDialog`, `ImportOverridesDialog`, `RemoveOverrideDialog`), `VariationsPanel.vue` (`ValueVersionDialog` with environment checkboxes), `InsightsPanel.vue` (empty state until M10), `HistoryPanel.vue` (audit-derived; M5 adds publications), `SettingsPanel.vue` (`FlagMetadataForm`, `LifecyclePanel`, `ArchiveDialog`) |
| Simulation (`features/evaluation`) | `SimulatorDrawer.vue`, `TryAUserPanel.vue` (compact, in Rules tab), `ContextEditor.vue`, `SimulationResultCard.vue`, `TraceView.vue`, `TestContextPicker.vue`, `SaveTestContextDialog.vue` |
| Segments | `SegmentTable.vue`, `CreateSegmentDialog.vue`, `SegmentEditor.vue` (`SubjectListEditor` for include and exclude, reuses `ConditionGroupEditor`), `SegmentSummary.vue`, `SegmentUsedBy.vue`, `SegmentPreview.vue`, `SegmentVersions.vue`, `DeleteSegmentDialog.vue`, `LeaveWithoutSavingDialog.vue` |
| Composables | `useFlagDraft` (base, working copy, dirty, save, discard, conflict with base/mine/theirs), `useEditorStatus` (one state from local dirty, draft presence, review state, conflict, publish failure; precedence as 2g), `useRolloutImpact`, `useSimulator` (context in component memory only, never browser storage), `useFlagFilters` (URL query sync for saved views), `useSegmentDraft`, `useUnsavedGuard` (Inertia `before` navigation + `beforeunload` + environment switch), `useConditionOperators` |
| Types | `types/flag-config.ts` (generated), `types/flags.ts` (DTOs), `types/segments.ts`, `types/simulation.ts` |

Behavior notes:

- **Status bar.** One area, icon + label + text + actions, `aria-live="polite"`. M4 states: Up to date, Unsaved changes, Draft saved, Viewing published, Conflict, Removal staged. M5 fills awaiting, approved, invalid, published and publish failed through the `review` prop. "Review & publish" (or "Review & request approval" on protected environments) links to M5's environment change set, never a flag-only publish.
- **Compare & merge.** `ConflictMergeDialog` does a three-way merge by section and rule ID. Changes that don't overlap merge automatically and are listed for confirmation; overlapping edits (same rule, or both changed enabled/off/default) are shown side by side for a choice. Saving uses the server's version. "Discard mine" loads theirs.
- **Read-only modes.** Viewers, archived flags, archiving drafts and the published view render the same components with `readonly`; the server rejects writes regardless.
- **Masking.** Without `sensitive.read`, override rows show `user:••••••` with a short fingerprint suffix and the reason; segment lists show counts only ("16 listed users"). Masking happens on the server, so props never carry the keys.
- **Accessibility.** Rule reordering has keyboard alternatives; allocation sliders have number inputs; status uses icons and text; dialogs restore focus.

### 3.6 Jobs, events, outbox consumers

| Item | Trigger | Behavior |
| --- | --- | --- |
| Outbox `flag.changed` (R02) | Every draft, override, value pin, metadata or lifecycle write | Payload: flag ID and key, environment ID (null for project-level), change kind, draft version, actor. Delivered by M9 |
| Outbox `internal.segment.changed`, `internal.flag.archive_requested`, `internal.flag.archived` | Segment and archive actions | For M8 notifications and M9 integrations |
| Domain events `Flags\Events\EnvironmentDraftChanged`, `Targeting\Events\SegmentDraftChanged` | Dispatched synchronously inside every transaction that advances `environment_draft_states` (environment, object, editor) | No M4 listener; M5 registers `InvalidateOpenChangeSet`. Lock order: draft state rows, draft rows, segment versions, then M5's change set row |
| `ReconcileArchivingFlags` job | Consumes `revision.published` (M5) plus a daily sweep | For each `archiving` flag in that project, calls `CompleteFlagArchive` once no live revision contains it. Idempotent |
| `PinSegmentsForNewEnvironment` job | Consumes M3's `internal.environment.created` | Pins each segment's latest sealed version, or its open draft if none is sealed. Idempotent |
| `FlagAttentionQuery` | Read model, no job | Reasons in M4: past removal date, owner no longer a project member, blocking draft issues, archive in progress. M10 adds usage reasons. M8 reads it for reminders |

### 3.7 Security and tenant isolation

- Every query and action resolves children inside the authorized parent: flag in project, environment in project, override in flag and environment, segment in project, prerequisite flag in the same project, value version in the flag. Cross-project IDs inside a document are rejected with 422, never followed.
- All new tables carry `organization_id` and `project_id`; composite indexes start with `organization_id`. The M0 scope test covers them.
- `sensitive.read` gates override keys, segment lists, test contexts and override search. Audit stores fingerprints. Support staff (M7) see counts only.
- Simulation and preview: bodies are never logged (`RedactRequestBody` middleware on those routes, exception reporter scrubbing `context`), nothing is persisted, rate-limited. Actors without `sensitive.read` still get a result for the key they typed, but the trace never shows other subjects' keys.
- Input bounds are checked before parsing: draft save body ≤ 256 KB, import ≤ 1 MB, context ≤ 8 KB. No regex operators exist, so there is no ReDoS surface.
- Descriptions are Markdown rendered server-side with HTML input escaped and unsafe links removed; flag names and JSON values are output-encoded.
- Billing: creation, clone and restore call `EnforcePlanLimit::handle(org, LimitKey::FlagsPerProject, project)`; every draft write calls `PlanRestrictionGate::assertConfigWritable`. Nothing in M4 can change a published snapshot.

## 4. Implementation steps

Each step is one pull request with tests.

1. **Flag schema and models.** Migrations for `flags`, `flag_tags`, `variations`, `variation_values`; models, factories, `Flags` policies, tenancy tests. Files: `database/migrations/*_create_flags_tables.php`, `app/Modules/Flags/Models/*`.
2. **Create and edit flags.** `CreateFlag`, `UpdateFlagMetadata`, `Flags\Domain\FlagKeyPolicy` (reads M3's `projects` defaults), `VariationValueValidator`, calls to the M6 `EnforcePlanLimit` and `PlanRestrictionGate` contracts (null bindings); API controllers and requests. Files: `app/Modules/Flags/Actions/*`, `Http/Controllers/Api/V1/FlagController.php`.
3. **Flag list.** `FlagListQuery` (filters, search, cursor, counts), `flags/Index` page, filter bar with URL sync, bulk tag/owner. Files: `Queries/FlagListQuery.php`, `pages/flags/Index.vue`, `features/flags/FlagFilterBar.vue`.
4. **Create page.** `flags/Create`, variation editors, JSON editor, usage snippet, key availability endpoint.
5. **Draft core.** Migrations for `environment_flag_drafts`, `environment_draft_states`, `draft_references`; `FlagConfigDocument` value objects and JSON Schema; `DraftValidator` (rejected and blocking tiers); `SaveFlagDraft`, `DiscardFlagDraft` with lock order and `VersionConflict`; content hash; API draft endpoints.
6. **Targeting domain.** `OperatorCatalog`, `ConditionGroup` limits, `Allocation`, `SaltGenerator`, `AllocationChangeAnalyzer` with shared rollout-impact fixtures and acknowledgement handling in `SaveFlagDraft`.
7. **Prerequisites.** `PrerequisiteGraph`, reference indexing, blocking-issue rules.
8. **Overrides.** `subject_overrides` migration, override actions, CSV import with dry run, fingerprinting, masking resources.
9. **Segments back end.** Migrations for `segments`, `segment_versions`, `segment_version_subjects`, `environment_segment_pins`; segment actions, `SegmentUsageQuery`, `SegmentSummarizer`, `PinSegmentsForNewEnvironment`.
10. **Compiler.** `app/Modules/Publishing/Domain/Compiler/{SnapshotCompiler,CompilerInput,CompiledFlags}.php`, `EnvironmentDraftChanges`, `PinnedSegmentsQuery`, golden tests against protocol fixtures.
11. **Simulation back end.** `App\Shared\Evaluation\EvaluatorGateway` (only class importing `BitFlag\`), `SimulateFlag`, `PreviewSegment`, `test_contexts` and actions, redaction middleware, rate limiter.
12. **Published-state contracts.** `PublishedStateReader`, `ReviewStateReader` interfaces with null implementations, `FlagEnvironmentSummaryQuery`, `FlagStateSummarizer`, `FlagConfigDiff`.
13. **Detail shell.** `flags/Show`, header, env cards, draft/published mode bar, `FlagEditorStatusBar`, `useFlagDraft`, `useEditorStatus`, `useUnsavedGuard`, switch/discard dialogs.
14. **Rules editor UI.** Rule cards, condition group editor, serve and allocation editors, impact notices, keyboard reorder, delete rule dialog.
15. **Configuration and Variations tabs.** Enabled/off/default, prerequisite editor, diff panel, variations table, value version dialog.
16. **Overrides tab UI.** Table with masking, add, import preview, remove, remove expired.
17. **Conflict handling UI.** `ConflictMergeDialog` and the three-way merge helper.
18. **Simulator UI.** Drawer, Try a user panel, context editor, result cards, trace, test contexts.
19. **Segments UI.** List, create, detail editor, lists, preview, used by, versions, delete, viewer state.
20. **Lifecycle.** Deprecate/reactivate, archive request/cancel/restore, archive progress, bulk archive, clone, `ReconcileArchivingFlags`, Settings tab metadata form, project defaults section.
21. **Experiment rule actions (used by M11).** `PlaceExperimentRule`, `ReplaceExperimentRule`, `RemoveExperimentRule`, `ReleaseExperimentRule`, the managed-rule lock in `SaveFlagDraft` and the editor, `ExperimentDependencies` null binding, compiler output of `experimentId` and `allocationId` with golden fixtures.
22. **Demo seed and end-to-end.** `FlagsDemoSeeder`, `SegmentsDemoSeeder`, `TestContextsDemoSeeder` (mockup world), Cypress specs.

## 5. Tests

**Pest unit** (`tests/Unit/Flags`, `tests/Unit/Targeting`, `tests/Unit/Publishing/Compiler`): `FlagKeyPolicyTest` (patterns, key formats, locks), `VariationValueValidatorTest` (finite numbers, 32 KB JSON, schema), `DraftValidatorTest` (each tier and field path), `ConditionGroupLimitsTest` (depth, size, empty groups), `OperatorCatalogTest`, `AllocationChangeAnalyzerTest` (shared rollout-impact fixtures, exact moved share), `SaltGeneratorTest`, `PrerequisiteGraphTest` (self, cycle path, depth 5), `SegmentSummarizerTest`, `FlagStateSummarizerTest`, `FlagConfigDiffTest`, `ContentHashTest` (canonical order, segment and override sensitivity), `SnapshotCompilerTest` (golden: each `tests/Fixtures/drafts/*.json` compiles byte-equal to its expected protocol snapshot).

**Pest feature** (`tests/Feature/Flags`, `tests/Feature/Targeting`, real MySQL):

- `SaveFlagDraftConcurrencyTest`: two actors load version 7; first save wins, second gets 409 with `currentVersion` 8 and the current document; head advances once per save; parallel saves on separate connections never lose an update.
- `OverridesVersionTest`: adding an override while rules are edited causes no conflict; head bumps.
- `CreateFlagTest`, `ProjectDefaultsEnforcementTest`, `FlagKeyLockedAfterPublishTest` (via `RecordPublishedBaseline`).
- `RolloutAcknowledgementTest`: weight reduction without `acknowledge` returns 422; acknowledged fingerprint is not asked again.
- `PrerequisiteCycleTest` across two concurrent saves (head lock serializes them).
- `SegmentVersioningTest`: open-draft uniqueness, pin per environment, sealed versions immutable, hash recomputation on referencing drafts.
- `DeleteSegmentBlockedTest`, `RemoveVariationBlockedTest`.
- `ArchiveWorkflowTest`: request stages removals only where live, blocked by prerequisites, cancel, completion via a fake `PublishedStateReader`, restore, plan limit counting.
- `OverrideMaskingTest` and `SegmentListMaskingTest`: Viewer props and API responses never contain keys; audit holds fingerprints only.
- `SimulateFlagTest`: local/draft/published sources, `DRAFT_HAS_ISSUES`, missing key reason `MISSING_SUBJECT_KEY`, fixed evaluation time, no rows written, no outbox, no audit, logs contain no context values.
- `SimulatorFixtureConformanceTest`: every pinned `bitflag-protocol` fixture snapshot runs through `EvaluatorGateway` and the simulator response mapper with identical value, variation, reason, rule and revision.
- `TenantIsolationTest` for every new table and query (cross-organization IDs in paths and inside documents).
- `FlagChangedOutboxTest`: each write emits exactly one `flag.changed` in the same transaction.

**Pest architecture** (`tests/Arch`): only `App\Shared\Evaluation` and `App\Modules\Publishing` (canonicalizer) import `BitFlag\`; `Publishing\Domain\Compiler` uses no Eloquent, facades or `now()`; Targeting writes Flags tables only through Flags actions; controllers call actions or queries only.

**Vitest**: `useFlagDraft.spec.ts` (dirty tracking, save, 409 handling keeps local edits), `mergeDraft.spec.ts` (three-way merge cases), `useEditorStatus.spec.ts` (state precedence table from 2g), `useRolloutImpact.spec.ts` (same fixtures as PHP), `EnvironmentStateCard.spec.ts` (badge and live line read only `live`; a draft with `enabled: true` over a disabled live state still shows Disabled), `AllocationEditor.spec.ts`, `ConditionGroupEditor.spec.ts`, `ContextEditor.spec.ts` (protocol error messages), `useFlagFilters.spec.ts` (URL round trip), `SubjectListEditor.spec.ts`.

**Cypress** (seeded Acme / Storefront): `m4-flag-list.cy.ts` (filters, search, sort, pagination, archived hidden, bulk tag and owner), `m4-create-flag.cy.ts` (four types, key availability, JSON errors, defaults enforced), `m4-draft-editing.cy.ts` (unsaved → saved, env card Live unchanged, view published, discard, env switch guard), `m4-draft-conflict.cy.ts` (two sessions, Compare & merge, Discard mine), `m4-rollout-warnings.cy.ts`, `m4-overrides.cy.ts` (add, import preview, remove, Viewer masking), `m4-prerequisites.cy.ts` (cycle blocked), `m4-segments.cy.ts` (create, include/exclude, check a user with unsaved edits, pin in another environment, delete blocked), `m4-simulator.cy.ts` (draft vs published, presets, no-key preset, invalid JSON), `m4-archive.cy.ts` (request, progress, cancel), `m4-viewer-readonly.cy.ts`.

**Fixtures**: pinned `bitflag-protocol` release (from M1), `tests/Fixtures/drafts/*.json` with expected snapshots, `tests/Fixtures/rollout-impact/*.json` (shared with Vitest), override CSV samples including malformed lines.

## 6. Decisions

| Item | Default assumed in M4 |
| --- | --- |
| D03 operators and missing/negated semantics | Protocol §4 proposal: positive comparisons on missing, null or wrong type are false; negations also false; arrays match any element |
| D03 empty groups, limits, numeric range, canonical JSON | Empty root `all` = everyone, empty `any` rejected; limits in §3.1; integers within ±(2^53 − 1); RFC 8785 canonical JSON for hashes |
| D03 bucketing | Decided (R15): `bucket-sha256-v1` with per-allocation salt only (evaluator from M1); movement estimates assume it. Every new, cloned or copied allocation gets a fresh `SaltGenerator` salt; `DraftValidator` rejects two allocations in one environment with the same salt (`DUPLICATE_SALT`) |
| D06 browser exposure | M4 stores `client_visible` and warns when a client-visible flag uses segments; projection rules and rejection are M5 |
| D09 context and subject data | Simulator stores nothing; test contexts only when saved deliberately; override keys and lists stored in plain columns, masked by capability, fingerprinted in audit; lists up to 10,000 |
| D12 archive and deletion | Staged archive with `archiving` status (§3.2); segments soft-deleted by status with key reserved; variation keys never reused |
| D14 API details | Routes in §3.4, `expectedDraftVersion` / `expectedVersion` fields, `flags:read` / `flags:write` scopes cover segments, error codes listed |
| D02 approvals | Not decided here; M4 records `contributors` per draft so M5 can apply any self-approval rule |
| D10 billing | `EnforcePlanLimit` and `PlanRestrictionGate` contracts; null bindings until M6 binds the real ones |

## 7. Done criteria

- Two people saving the same draft get a 409 with the other person's change shown, never a silent overwrite (Pest concurrency test and `m4-draft-conflict.cy.ts`).
- The simulator agrees with every pinned protocol fixture, and compiled drafts match their golden snapshots byte for byte.
- No live indicator anywhere (env badge, live line, list status, compare dialog) reads draft data; the Vitest and Cypress checks for the 1c bug pass.
- Rollout reductions, slot reorders, salt and bucketBy changes, audience edits and rule moves warn before saving in both portal and API.
- Prerequisite cycles and over-deep chains are rejected at save; segment and variation deletion is blocked while referenced.
- Viewers and token holders without `sensitive.read` never receive override keys or segment list members (props, API, audit).
- Simulation writes nothing, emits nothing and logs no context (tested).
- Archive request stages removals per environment and the flag reaches `archived` only after the last live removal (with a fake published reader until M5).
- All listed Pest, PHPStan, Vitest and Cypress suites are green in CI.

## 8. Risks and open questions

**Inconsistencies for Shei to decide**

1. **Env badge bug** (frontend-and-design.md §3; Flags 1c and the portal `envCards`/compare rows read the draft `on` value). Fixed as described in §3.2; no decision needed, listed for traceability.
2. **Published view shows true 20% / false 50%** in Flags 2b (slots must sum to 100%). Treated as a mockup error.
3. **Overrides apply immediately in the portal prototype** and the simulator uses the same overrides for Draft and Live. The domain model says draft overrides never affect live decisions before publication. Plan: overrides are draft content.
4. **Segments are per-environment definitions in the portal prototype** (different include lists in staging and production), while the domain model has project-scoped immutable versions. Plan: project versions with a per-environment pin, which reproduces the prototype's behavior. Confirm.
5. **Segment structure differs between mockups**: canvas 1c has "match all" plus "also include users who match any", the portal has include list, conditions and "never include" list. Plan follows the portal and the domain precedence (exclude, include, conditions).
6. **Segment size estimates** ("~1.2k users from last 7 days of checks") need retained evaluation contexts, which conflicts with "context is used and discarded" (protocol §1, D09). Plan omits the Size column until a telemetry-based design exists (M10/D09).
7. **"Unallocated traffic gets the rule's fallback"** (Design System rule card) contradicts the domain's remainder variation. Plan uses remainder variation and changes the copy.
8. **Prerequisites have no UI in any mockup** (only a publish check and a bulk-archive blocker). Plan designs `PrerequisiteEditor` in the Configuration tab; needs a design pass.
9. **Flag Settings tab has no metadata editing** (name, description, owner, tags, intent, client visibility, JSON Schema). Plan adds `FlagMetadataForm`.
10. **Archive copy** says it "removes the flag from new snapshots in every environment" as one action; the domain workflow stages removals per environment with review. Plan uses the staged workflow, adds the `archiving` status (not in the domain status list) and adjusts copy.
11. **Boolean variation keys**: the portal uses `disabled`/`enabled`, canvas 1b labels Off/On, the protocol example uses `off`/`on`. Plan fixes keys `off`/`on`.
12. **Segment key rule**: portal `^[a-z][a-z0-9-]{0,39}$`; plan uses the flag key rule (letters, digits, `-`, `_`, up to 100) for consistency.
13. **Portal operators** ("at least" switches to version comparison when the value contains a dot) differ from the protocol's separate numeric and semver operators. Plan follows the protocol.
14. **Saved test contexts** are implied by Changes 2a "How test users are affected" and the simulator presets but are not in the domain model. Plan adds `test_contexts` (explicit save, `sensitive.read`). Confirm, given D09. **Resolved (R17, 8 October 2026): the default above is the decision.**
15. **Project defaults** in Settings include key format and "Mark as stale after", which the domain model's project defaults list omits; "Time zone · used for scheduled rollouts" refers to scheduled rollouts that no document or milestone covers.
16. **Publish from the flag page**: the portal publishes a single flag ("Publish rev 43"), while the docs make the environment the publication unit. M4's status bar links to M5's environment change set. The publish modal's reviewer "Jon Reyes · Admin" names a role that R01 removed (M5 concern).
17. **Table map**: plan replaces `rules`, `condition_groups`, `prerequisites` with the draft document plus `draft_references`, and adds `environment_draft_states`, `segment_version_subjects`, `environment_segment_pins`, `test_contexts`, `flag_tags`. api-and-integrations.md shows `DRAFT_VERSION_CONFLICT`; conventions use `VERSION_CONFLICT` (plan uses the conventions). **Resolved (R17, 8 October 2026): the default above is the decision.**

**Risks**

- Whole-environment locking on the draft head serializes writes per environment. Acceptable at expected team sizes; revisit if large automated imports contend.
- Simulation probing: a Viewer can learn whether a typed subject key has an override. Accepted by default; restricting simulation to `sensitive.read` holders is the alternative. **Resolved (R17, 8 October 2026): the default above is the decision.**
- M1 must expose trace output and an explicit evaluation time in `bitflag-php`; M4 assumes `Evaluator::evaluate(snapshot, key, context, fallback, options{trace, now})`.
- Until M5 lands, every environment shows "Never published", so the mockup world (revision 42 and so on) is only fully reproducible after M5's seeders.
