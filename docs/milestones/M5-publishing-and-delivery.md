# M5. Publishing and delivery

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Expands M5 in the [implementation plan](../implementation-plan.md).

## 1. Goal and scope

M5 turns saved drafts into live configuration and gets it to applications. After M5 a person can create a flag, publish it to development, fetch it with a real PHP client, and see the expected value. Production changes go through a frozen review, and every publish, rollback and emergency disable is an immutable, audited revision.

**In scope**

- Change sets for a whole environment, with contributors, frozen review candidates (content hash, base revision, policy version), approvals by Owners and Publishers (R01), stale-approval invalidation, and the D02 defaults behind a policy class.
- Protected-environment rules (who may publish, required approvals, required reason) as read from the M3 protection policy.
- The publication transaction: compile server and public projections, validate (including the public "no silent stripping" rule), canonical JSON and checksum, immutable `snapshots`, the `(generation, revision)` pointer, audit and outbox in the same transaction, idempotent retries.
- Rollback and emergency disable as new revisions (D05 defaults).
- SDK delivery: `GET /sdk/v1/snapshot` in `routes/sdk.php`, key auth from M3, authenticated ETag and `304`, Redis cache per environment and projection with MySQL fallback, rate limits, response headers.
- Outbox consumers for cache warm and reconciliation; hand-off of webhook fan-out to M9 and review emails to M8.
- The minimal `bitflag-php` client (fetch, cache, evaluate, report an evaluation summary) used to verify onboarding.
- Delivery observation sufficient for onboarding "Verify an evaluation" and the post-publish delivery panel.
- Portal pages: Changes (list, approvals, history, review, published), the publish and rollback dialogs reached from the flag editor, Audit log (filters, detail, object history, bounded export), project Overview with the onboarding checklist.
- Management API endpoints for change sets, approvals, publish, rollback, emergency disable, revisions and audit events.
- MySQL concurrency tests for publication races and the first end-to-end Cypress spec.

**Out of scope (owner)**

- Draft editing, segments, the simulator and the snapshot compiler for drafts: M4 (reused here).
- Streaming invalidations and polling defaults (D04): M13 part A; M5 messages carry `(generation, revision)` so a stream consumer can be added without schema change.
- Webhook delivery and provider notifications: M9. Review-request emails and in-app notifications: M8.
- Evaluation aggregates, analytics charts, full telemetry ingestion, delivery health beyond what onboarding needs: M10.
- Plan gating of required approvals and audit export: M6 (M5 reads an entitlement contract, section 3.7).
- Experiment allocation history and interruption records: M11 (M5 emits the events it needs).
- Remote evaluation (`/sdk/v1/evaluate`): M13 part A, with D06.

## 2. Mockups covered

| Mockup | Artboards | Behavior implied |
| --- | --- | --- |
| BitFlag Changes | 1a | Changes list grouped by environment (chosen layout). Per group: protection tag, "N unpublished", Discard all, primary CTA "Review & request approval" (protected) or "Review & publish". Rows: object, what changed, author ("You"), edited, checks ("Valid" or the validation error such as "Weights add up to 110%"). Tabs "Not live yet", "Approvals", "Published history" with counts |
| BitFlag Changes | 1b, 1c | Alternative layouts. 1c's inline diff lines (`−` / `+`) are reused on the review page and in history |
| BitFlag Changes | 2a | Author view: "rev 42 → 43 · 2 changes · 1 flag, 1 segment", diffs, "How test users are affected", checks list, reason required for production, reviewer picker, "You can't approve your own change" |
| BitFlag Changes | 2b | Reviewer view: "Maya Santos asked you to review this", optional comment, "Approve & publish", "Request changes", "A new edit to these flags cancels this review" |
| BitFlag Changes | 2c | Approvals tab: Waiting for you, Requested by you (Cancel request), Recently decided (approved with comment, cancelled with cause "because Jon Reyes edited beta-testers after the request") |
| BitFlag Changes | 2d, 2e | Published history per environment: revision, "Maya Santos · approved by Jon Reyes", reason, when, "Roll back to this". Rollback dialog: "We'll publish rev 41's rules as a new revision, rev 44", diff, reason required for production, "Rollbacks skip review" |
| BitFlag Changes | 2f | Published screen: checksum, approver, reason, Back to changes, Roll back |
| BitFlag Changes | 2g, 2h | Empty state "Everything is live"; Viewer banner and read-only controls |
| portal/Changes | all states | Same tabs; "All / Mine" filter with "Publishing production still includes them" note; review states draft, waiting (requester, with Withdraw), reviewing (comment required to reject), approved ("Publish rev 43"), changes requested, review cancelled ("Start a new review"), nothing to publish; checks incl. "No prerequisite cycles", "Snapshot stays under 1 MB (46 KB)", "Drafts unchanged since the review request"; success screen with three delivery steps; discard-all and discard-one dialogs; rollback dialog "Load and review" (conflicts with 2e, section 8) |
| BitFlag Flags | 1c, 2a, 2d, 2e, 2g | Editor status bar states owned by M5: awaiting approval (Cancel request, View review), approved ("Jon Reyes approved this exact proposal", Publish revision 43), published ("Published" only after server confirms), invalid ("New edits require another review"), publish failed ("Revision 43 wasn't published. Nothing changed for users. Your draft is still saved", Retry) |
| portal/Flags | publish, success, rollback modals | Publish modal (reason, reviewer "must be someone other than you"), success with delivery steps, history tab "Roll back to this" |
| BitFlag Audit | 1a, 1c, 2a–2d | Audit grouped by day (chosen; 1b dense table is an alternative density), filters Type / Who / Source / Environment with counts, search, "N of M entries", entry drawer (who, source, environment, assistant / acting for / tool, command, IP, entry ID `aud_…`, reason, what changed, Open, Copy as JSON, More by), history of one object, no-results state, Viewer "Exporting needs the Owner role" |
| portal/Audit | all states | Same, with "Project-wide" environment option and CSV export of the filtered set |
| BitFlag Overview | 1a, 1b | Populated overview (live revision, publisher, unpublished banner, stat tiles, recent activity, needs attention, connect your app, environments table) and new-project state with a 4-step "Get your first flag live" |
| portal/Overview | all states | Checklist "Get one flag working end to end · N of 4 done · Hide": Create a flag, Publish to development, Connect an SDK, Verify an evaluation. Connect panel: language, environment, credential, install, evaluate, verify with three checks (Authenticated, Snapshot downloaded, Evaluation reported) and failure hint ("403 origin not allowed") |
| BitFlag Environments | detail | "Revision 42 · Checksum · Snapshot size 46 KB · 11 flags · 6 client-visible" (M5 supplies these values to M3's page); "Until something is published here, SDKs using these keys get an empty snapshot" (revision 0 behavior); "Max snapshot age 24 hours default" (delivered as a header) |

## 3. Design

### 3.1 Data model

All tables carry `organization_id`, `project_id`, `environment_id` where they apply, ULID keys, `timestamp(6)` UTC, and a composite index starting with `organization_id`. Canonical JSON that is hashed or delivered is stored as bytes (`mediumblob`), never in a MySQL `json` column, because MySQL normalizes `json` values and would break checksums.

**`publication_pointers`** (one row per environment, the only mutable publication record)

| Column | Type | Notes |
| --- | --- | --- |
| `environment_id` | ulid PK | Row created lazily with `INSERT IGNORE` on first publish; backfilled for existing environments |
| `organization_id`, `project_id` | ulid | |
| `revision_id` | ulid null | Current revision; null means never published |
| `revision_number` | int unsigned, default 0 | |
| `generation` | int unsigned, default 1 | Raised only by the restore runbook command, to `max(current + 1, unix_seconds(now))` so a restore from any older backup still exceeds every generation clients have seen (M12 §3.1). M5 is the single owner of `generation`; there is no `environments.generation` column (M12 extends the command) |
| `version` | int unsigned | Bumped on every move |
| `updated_at` | timestamp(6) | |

**`revisions`** (immutable)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid (`rev_`) | |
| `number` | int unsigned | Unique `(environment_id, number)` |
| `generation` | int unsigned | |
| `source` | enum `publish`, `rollback`, `emergency_disable`, `experiment` | `experiment` reserved for M11 |
| `change_set_id` | ulid null | Null for rollback and emergency disable |
| `restored_revision_id` | ulid null | Rollback target |
| `previous_revision_id` | ulid null | |
| `actor_type`, `actor_id`, `on_behalf_of_user_id`, `channel` | | From `Actor` (token creator for `bf_mgt_`) |
| `requested_by_user_id` | ulid null | Change set requester, for "Maya Santos · approved by Jon Reyes" |
| `reason` | varchar(500) null | |
| `bypassed_review` | bool | True for rollback and emergency disable in protected environments |
| `server_checksum`, `public_checksum` | char(71) | `sha256:` + 64 hex |
| `labels` | json | Display-only sidecar: rule id → name, segment key → name. Never delivered, not hashed |
| `published_at` | timestamp(6) | Database clock |

**`revision_changes`**: `revision_id`, `object_type` (`flag`, `segment`), `object_key`, `change_kind` (`added`, `modified`, `removed`), `summary` varchar(300), `diff` json (structured lines with a `sensitive` marker). Index `(organization_id, environment_id, object_type, object_key, revision_id)` for "Show history for new-checkout" and Audit 1c.

**`revision_delivery_states`**: `revision_id` PK, `cache_ready_at` null, `last_error` null, `attempts`. Mutable delivery facts live here so `revisions` stays immutable.

**`snapshots`** (immutable, one row per projection)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | |
| `revision_id`, `revision_number`, `generation` | | Unique `(environment_id, revision_number, projection)` |
| `projection` | enum `server`, `public` | |
| `protocol_version`, `algorithm_version` | smallint, varchar(40) | From M1 constants |
| `checksum` | char(71) | |
| `etag` | varchar(100) | `"{projection}-g{generation}-r{revision}-{checksum hex 0..16}"` |
| `byte_size`, `flag_count` | int unsigned | Feeds "46 KB · 11 flags · 6 client-visible" |
| `payload` | mediumblob | Canonical JSON bytes, exactly what is delivered |

MySQL triggers on `snapshots` and `revisions` reject `UPDATE` and `DELETE` (`SIGNAL SQLSTATE '45000'`). Retention purges (D09) will need an explicit, audited path later.

**`change_sets`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid (`chg_`) | |
| `state` | enum `draft`, `pending_review`, `approved`, `invalidated`, `published`, `rejected`, `withdrawn` | |
| `open_environment_id` | ulid, generated: `environment_id` when state in (`draft`, `pending_review`, `approved`, `invalidated`), else null | Unique index: at most one open change set per environment |
| `current_candidate_id` | ulid null | |
| `base_revision_number` | int unsigned | Of the current candidate |
| `requested_by` (actor columns), `requested_reviewer_user_id` null | | Requested reviewer is a notification target; any eligible reviewer may decide (D02 default) |
| `reason` | varchar(500) null | |
| `invalidated_cause` | enum `draft_changed`, `base_changed`, `policy_changed` null | |
| `invalidated_by_user_id`, `invalidated_object` (e.g. `segment:beta-testers`) | | Feeds "Cancelled because Jon Reyes edited beta-testers" |
| `published_revision_id` | ulid null | |
| `submitted_at`, `decided_at`, `closed_at` | timestamp(6) null | |
| `version` | int unsigned | Optimistic concurrency for portal commands |

**`review_candidates`** (immutable once written; replacements get a new row)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid (`cnd_`) | |
| `change_set_id`, `sequence` | | Unique `(change_set_id, sequence)` |
| `candidate_hash` | char(64) | SHA-256 of canonical `{environmentId, baseRevision, generation, policyVersion, content}` |
| `content` | mediumblob | Canonical server-projection content (flags, segments, capabilities) without envelope |
| `base_revision_number`, `base_generation` | int unsigned | |
| `env_draft_version` | bigint unsigned | Environment-level draft version (M4) at freeze time |
| `policy_id`, `policy_version` | | M3 `environment_policies` |
| `selected_drafts` | json | Draft IDs and per-object versions included |
| `diff`, `checks`, `impact` | json | Display: diff lines, validation results, test-user before/after |
| `object_count`, `flag_count`, `segment_count`, `byte_size` | | |
| `created_by` (actor columns), `created_at` | | |

**`review_candidate_contributors`**: `candidate_id`, `user_id` (token edits map to the token creator; MCP and PAT edits to their user), `objects` json. Primary key `(candidate_id, user_id)`.

**`approvals`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid (`apv_`) | |
| `change_set_id`, `candidate_id`, `candidate_hash` | | Unique `(candidate_id, reviewer_user_id)` |
| `reviewer_user_id` | ulid | Users only (PAT allowed, `bf_mgt_` not) |
| `decision` | enum `approved`, `rejected` | |
| `comment` | text null | Required for `rejected` |
| `policy_version` | int unsigned | |
| `expires_at` | timestamp(6) null | From policy TTL |
| `invalidated_at`, `invalidated_cause` | null | `reviewer_ineligible`, `expired`, `candidate_replaced` |

**`idempotency_records`** (from M0's `Shared/Idempotency`): `organization_id`, `actor_key` (e.g. `user:…`, `token:…`), `operation` (`publish`, `rollback`, `emergency_disable`), `idempotency_key` varchar(100), `request_fingerprint` char(64), `result` json, `created_at`, `expires_at` (24 h, D14). Unique `(organization_id, actor_key, operation, idempotency_key)`.

**`sdk_credential_observations`** (per-credential onboarding markers; M10 keeps writing them and adds its own bucketed `sdk_observations`): `credential_id` unique, `environment_id`, `first_fetch_at`, `last_fetch_at`, `last_revision`, `last_generation`, `last_sdk` varchar(60) (from `X-BitFlag-SDK`), `first_evaluation_at`, `evaluated_flag_keys` json (bounded to 50 keys), `last_rejection_code`, `last_rejection_at`. Raw IPs are not stored; rejected-request audit entries keep only a distinct-address count.

**`onboarding_dismissals`**: `project_id`, `user_id`, `dismissed_at`. Unique `(project_id, user_id)`.

**Read from other milestones (assumed names):** `environments`, `environment_policies` (M3: `is_protected`, `require_reason`, `required_approvals`, `allow_self_approval`, `approval_ttl_hours`, `version`), `credentials` (M3), `environment_flag_drafts`, segment version selections and the environment-level draft-version row, called `environment_draft_states` here (M4), `audit_events`, `outbox_messages` (M0).

**ID prefixes added:** `aud_` (audit entry, shown in Audit 2a), `apv_` (approval), `cnd_` (review candidate).

### 3.2 Domain rules and state machines

**Publication unit.** The whole environment draft. Every page that offers "publish" (flag editor, Changes, API) operates on the environment change set and lists every included object and contributor. A flag-only publish does not exist.

**Change set state machine** (`Publishing\Domain\ChangeSetStateMachine`)

| From | To | Trigger | Guard |
| --- | --- | --- | --- |
| (none) | `draft` | `CreateChangeSet` (API, or implicitly by portal submit/publish) | No other open change set; something to publish |
| `draft` | `pending_review` | `SubmitChangeSetForReview` | Policy requires ≥ 1 approval; checks pass; reason if required |
| `draft` | `published` | `PublishChangeSet` | Policy requires 0 approvals |
| `pending_review` | `approved` | `DecideReview(approve)` | Valid approvals ≥ required |
| `pending_review` | `rejected` | `DecideReview(reject)` | Eligible reviewer, comment present |
| `pending_review`, `approved` | `pending_review` | Approval expired or approver became ineligible | Count drops below required (content still matches) |
| `draft`, `pending_review`, `approved` | `invalidated` | Draft changed, base revision moved, policy version changed | Recorded with cause, actor, object |
| `invalidated` | `pending_review` / `draft` | `ResubmitChangeSet` | New candidate (`sequence + 1`); old approvals stay bound to the old hash |
| `approved` | `published` | `PublishChangeSet` or `DecideReview(approve, publish: true)` | Full recheck in the transaction |
| any open | `withdrawn` | `WithdrawChangeSet` | Requester, any contributor, or Owner |

`published`, `rejected` and `withdrawn` are terminal. After `rejected`, the next submit creates a new change set ("Edit the drafts, then request review again").

**Candidate freeze** (`CandidateFreezer`). In one read transaction with `FOR SHARE` on the environment draft-version row: read the selected drafts (M4 query `EnvironmentDraftChanges`), compile with the shared `SnapshotCompiler` (M4) to server content, run `PublicationValidator`, compute the diff against the current published server snapshot, evaluate saved test contexts against live and candidate with the `bitflag-php` evaluator, collect contributors, and hash. The portal preview (GET) runs the same freeze without persisting and returns `previewHash`; every submit or publish sends it back and gets `409 DRAFT_CHANGED` with a fresh preview if it no longer matches. This holds for unprotected environments too, so no one publishes something newer than what they saw.

**Approval policy (D02 defaults, `DefaultApprovalPolicy` behind the `ApprovalPolicy` interface)**

| Rule | Default |
| --- | --- |
| Who can approve | Users holding `changeset.approve` in that project (Owner, Publisher). Project tokens (`bf_mgt_`) cannot; PATs act as their user |
| Self-approval | Not allowed for any contributor of the candidate, including the requester and the creator of a token that contributed. `allow_self_approval` exists in the policy but defaults to false |
| Required count | `environment_policies.required_approvals` (set by M3's environment template) |
| Expiry | Approval valid for `approval_ttl_hours` after it is given (null means the `DefaultApprovalPolicy` default of 168); checked against the database clock |
| Reject | Any eligible reviewer; comment required ("Comment · required to reject") |
| Requested reviewer | Optional hint; any eligible reviewer may decide |
| Membership change | Approvals by a user who loses `changeset.approve` or leaves the project stop counting immediately |

**Protected-environment rules.** Publishing requires `environment.publish.protected` (Owner, Publisher) when `is_protected` (M3's `PolicyEnvironmentProtection`, R01), otherwise `environment.publish` (Owner, Publisher, Developer). A Developer can submit for review in a protected environment but cannot publish it; the approver uses "Approve & publish". A reason is required when `require_reason` (default true when protected); 1 to 500 characters.

**Stale-approval invalidation.** Synchronous and also rechecked lazily:

- Draft change: M4 draft actions dispatch `Flags\Events\EnvironmentDraftChanged` (and the Targeting equivalent) inside their transaction; M5's listener `InvalidateOpenChangeSet` updates the open change set in that same transaction (`draft_changed`, editor, object).
- Base change: any publication of another source (rollback, emergency disable) invalidates the open change set inside the publication transaction (`base_changed`).
- Policy change: M3's `EnvironmentPolicyUpdated` → `policy_changed`.
- Membership change: M2's `MemberRemovedFromProject` and `MemberRemovedFromOrganization` events and outbox `internal.member.role_changed` → `RecheckApprovals` (approvals marked `reviewer_ineligible`, state may drop back to `pending_review`).
- Every read of a change set compares `env_draft_version`, base revision and policy version, so a missed event still shows and enforces the invalid state.

**Rollback (D05 default).** Creates revision N+1 whose content is the target revision's server content. It is revalidated with today's validator and today's public-projection policy, and current authorization applies. Archived flags in the target block the rollback (`ROLLBACK_RESTORES_ARCHIVED_FLAG`, explicit restore first, D12). Flags that are no longer client-visible are left out of the public projection and listed as a warning. Rollbacks skip review (Changes 2e) for actors with the environment's publish capability; reason required when the policy requires one. Drafts are kept; the open change set is invalidated (`base_changed`) and the Changes page shows "Drafts are based on rev 43, live is now rev 44" with Review again or Discard.

**Emergency disable (D05 default).** Built from the current published server content under the pointer lock: set `enabled: false` on one flag, nothing else. Same capability as publishing in that environment, no approval, reason always required, `bypassed_review = true`. It also sets that flag's environment draft to disabled through M4's action, so the next ordinary publish cannot silently turn it back on. The dialog lists dependent flags whose prerequisite will fail and any running experiment (emits `internal.flag.emergency_disabled` for M11).

**Public projection (D06 default, `PublicProjectionPolicy`).** Derived purely from the server content by `PublicProjector`:

- Only flags marked client-visible; `clientVisible` itself, overrides, rule traces and labels are omitted.
- No silent stripping. Publication is rejected for a client-visible flag that has overrides in that environment (`PUBLIC_FLAG_HAS_OVERRIDES`), references a segment with include or exclude lists (`PUBLIC_FLAG_USES_PRIVATE_SEGMENT`), or has a prerequisite that is not client-visible (`PUBLIC_FLAG_PREREQUISITE_NOT_PUBLIC`).
- Attribute privacy: all attributes are treated as public until D06 adds an attribute registry; the policy exposes a `privateAttributes()` hook returning an empty set.
- Referenced segments are inlined by exact version.

**Snapshot envelope.** `protocolVersion`, `algorithmVersion`, `environment {id, key}`, `revision`, `generation`, `projection`, `publishedAt`, `requiredCapabilities`, `flags`, `segments`, `checksum`. Canonical serialization is RFC 8785 (JCS) via M1's `BitFlag\Protocol` canonicalizer; checksum is `sha256:` + hex over the canonical payload with `checksum` removed (D03 default). Size limit 1 MB per projection (`bitflag.publishing.max_snapshot_bytes`). An environment never published serves a synthetic empty snapshot with `revision: 0` and `flags: {}`.

**Ordering.** `(generation, revision)` only moves forward. Revision numbers are allocated under the pointer lock and protected by the unique index. `generation` changes only through `bitflag:delivery:bump-generation` in the restore runbook.

### 3.3 Actions

All in `app/Modules/Publishing/Actions`. Each follows the conventions pattern; "Tx" lists what commits together.

| Action | Input (Data class) | Capability | Tx contents | Audit / outbox |
| --- | --- | --- | --- | --- |
| `CreateChangeSet` | env, `previewHash`, reason?, `submit` bool | `changeset.submit` | change set, candidate, contributors | `changeset.created` |
| `SubmitChangeSetForReview` | change set, `candidateHash`, reason, requested reviewer?, `expectedVersion` | `changeset.submit` | state `pending_review` | `review.requested` / outbox `review.requested` (M8 email, M9 webhook) |
| `ResubmitChangeSet` | change set, `previewHash`, reason | `changeset.submit` | new candidate, state | `review.requested` / `review.requested` |
| `WithdrawChangeSet` | change set, `expectedVersion` | requester, contributor, or `project.manage` | state `withdrawn` | `review.withdrawn` / `internal.review.withdrawn` |
| `DecideReview` | change set, `candidateHash`, decision, comment, `publish` bool, idempotency key | `changeset.approve` (+ publish capability when `publish`) | approval row, state; with `publish`, the whole publication in the same transaction | `review.approved` or `review.rejected` / `review.decided` |
| `PublishChangeSet` | change set, `candidateHash`, `expectedRevision`, reason?, idempotency key | `environment.publish` or `environment.publish.protected` | via `PublicationWriter` | `revision.published` / `revision.published`, `internal.delivery.revision_published` |
| `PublishEnvironment` | env, `previewHash`, `expectedRevision`, reason?, idempotency key | as above | create change set + candidate + publish in one transaction (unprotected, 0 approvals) | as above |
| `RollbackEnvironment` | env, target revision, `expectedRevision`, reason, idempotency key | `EmergencyActionPolicy::canRollback` | via `PublicationWriter`, invalidates open change set | `revision.rolled_back` / `revision.published` (source `rollback`) |
| `EmergencyDisableFlag` | env, flag, reason, idempotency key | `EmergencyActionPolicy::canEmergencyDisable` | via `PublicationWriter`, M4 draft update, invalidates open change set | `flag.emergency_disabled` / `revision.published`, `internal.flag.emergency_disabled` |
| `DiscardEnvironmentDrafts` | env, object refs or all, `expectedDraftVersion` | `flags.draft.write` | orchestrates M4 discard actions | per M4 (`flag.draft_discarded`) plus `environment.drafts_discarded` |
| `InvalidateOpenChangeSet` | env, cause, actor, object | system (listener) | state `invalidated` | `review.invalidated` / `internal.review.invalidated` (M8 in-app) |
| `RecheckApprovals` | user, project | system (listener) | approval invalidation, state | `review.approval_withdrawn` |
| `ExpireApprovals` | none (scheduled) | system | same | `review.approval_expired` |
| `ExportAuditLog` | filters | `audit.export` + entitlement | none (streams) | `audit.exported` |
| `DismissOnboarding` | project | `project.read` | dismissal row | none |

**`PublicationWriter::commit(PublicationIntent)`** is the single path that moves a pointer. Steps:

1. Before the transaction: authorize with current memberships; build the final server and public payloads from the candidate (or published) content; validate both against the M1 schema and size limit.
2. `DB::transaction(..., attempts: 3)` with `innodb_lock_wait_timeout = 5`. Deadlocks retry; a lock timeout returns `409 PUBLICATION_IN_PROGRESS` with `Retry-After: 2`.
3. Lock order, the same for every action that touches these rows (draft-save actions in M4 take step c then d only):
   a. `INSERT IGNORE` then `SELECT … FOR UPDATE` on `publication_pointers`.
   b. Idempotency: `SELECT … FOR UPDATE` on the record; same fingerprint returns the stored result, different fingerprint returns `409 IDEMPOTENCY_KEY_REUSED`, absent inserts it.
   c. `FOR SHARE` on the environment draft-version row; compare with `env_draft_version`.
   d. `FOR UPDATE` on the change set; state and `current_candidate_id` unchanged.
   e. `FOR SHARE` on the current `environment_policies` row; version equals the candidate's; reason rule.
   f. `FOR SHARE` on approvals of the candidate and the approvers' `project_memberships`; count valid approvals against the database clock.
   g. `FOR SHARE` on the publisher's membership (or token row) and recheck the capability.
4. Check `(generation, revision_number)` equals the candidate base. Allocate `revision_number + 1`, set `publishedAt` from the database clock, canonicalize both projections, compute checksums and ETags.
5. Insert `revisions`, two `snapshots`, `revision_changes`, `revision_delivery_states`; update the pointer; transition the change set; call M4's `RecordPublishedBaseline` with the included flag IDs and the inlined segment version IDs; on the environment's first publication call M3's `RecordFirstPublication` and `LockProjectKey`; invalidate any other open change set (rollback, emergency disable).
6. `Audit::record` and `Outbox::push` (both messages); write the idempotency result.
7. Commit. After commit, dispatch the outbox relay for low latency.

On a failed recheck the transaction rolls back and a separate short transaction records the invalidation, so the cause is not lost. No network call happens inside the transaction.

**Error codes added** (envelope from conventions): `DRAFT_CHANGED` 409, `BASE_REVISION_CHANGED` 409, `CANDIDATE_MISMATCH` 409, `POLICY_CHANGED` 409, `APPROVAL_REQUIRED` 409, `CHANGESET_NOT_OPEN` 409, `CHANGESET_ALREADY_OPEN` 409, `PUBLICATION_IN_PROGRESS` 409, `IDEMPOTENCY_KEY_REUSED` 409, `SELF_APPROVAL_NOT_ALLOWED` 403, `REVIEWER_NOT_ELIGIBLE` 403, `REASON_REQUIRED` 422, `COMMENT_REQUIRED` 422, `NOTHING_TO_PUBLISH` 422, `PUBLICATION_INVALID` 422 (details per object and check), `ROLLBACK_RESTORES_ARCHIVED_FLAG` 422, `ALREADY_DISABLED` 422. Delivery: `INVALID_CREDENTIAL` 401, `CREDENTIAL_REVOKED` 401, `ORIGIN_NOT_ALLOWED` 403, `SCOPE_MISSING` 403, `RATE_LIMITED` 429.

**Capabilities added**

| Capability | Owner | Publisher | Developer | Viewer |
| --- | --- | --- | --- | --- |
| `changeset.submit` (create, submit, resubmit) | ✓ | ✓ | ✓ | |
| `audit.export` | ✓ | ✓ | ✓ | ✓ |

Rollback and emergency disable reuse `environment.publish` / `environment.publish.protected` through `EmergencyActionPolicy`, so D05 can change them in one class.

### 3.4 Routes

**Portal (`routes/web.php`, session + CSRF, under M0's prefix `/{organization}/{project}/{environment}`, written here as `…`)**

| Method and path | Controller → Inertia page / response | Main props |
| --- | --- | --- |
| `GET …/overview` | `OverviewController` → M0's `overview/Show` | `environment`, `liveRevision`, `stats`, `activity`, `needsAttention`, `environments[]`, `onboarding`, `connect`, `can` |
| `GET …/onboarding/verification?flag=` | JSON | `{ authenticated, snapshotDownloaded, evaluationReported, hint }` |
| `POST …/onboarding/dismiss` | redirect back | |
| `GET …/changes?tab=unpublished\|approvals\|history&mine=` | `ChangesController@index` → `changes/Index` | `groups[]` (env, protection, live rev, status, items, othersNote), `reviews {waitingForYou, requestedByYou, recentlyDecided}`, `revisions` (cursor), `can` |
| `GET …/changes/review` | `ReviewController@show` → `changes/Review` | `changeSet?`, `candidate` or `preview` (hash, diff, checks, impact, contributors), `policy`, `eligibleReviewers[]`, `viewerRole` (author, reviewer, other), `nextRevision` |
| `POST …/change-sets` | `CreateChangeSet` (+ submit or publish) | |
| `POST …/change-sets/{changeSet}/{submit\|resubmit\|withdraw\|approve\|reject\|publish}` | matching action | |
| `POST …/rollbacks` | `RollbackEnvironment` | |
| `POST …/flags/{flag}/emergency-disable` | `EmergencyDisableFlag` | |
| `POST …/drafts/discard` | `DiscardEnvironmentDrafts` | |
| `GET …/revisions/{revision}` | `RevisionController@show` → `changes/Revision` | checksum, approvals, reason, included objects, previous revision, `delivery` (cache ready, observed instances) |
| `GET …/audit` | `AuditController@index` → `audit/Index` | `entries` (cursor, grouped by day in project timezone), `facets` with counts, `filters`, `window {days, plan}`, `can` |
| `GET …/audit/{auditEvent}` | partial reload | entry detail with masked diff |
| `GET …/audit/export.csv` | `StreamedResponse` | |

Shell props filled for M0's layout: `scope.environment.revision` and `environments[].revision` (sidebar environment picker "rev 42") and the Changes navigation count (sidebar "Changes 3"), from `Publishing\Queries\ShellPublicationState`.

**Management API (`routes/api.php`, `/api/v1`, token scopes per D14 proposals)**

| Endpoint | Scope | Notes |
| --- | --- | --- |
| `GET /projects/{project}/environments/{environment}/changes` | `flags:read` | Unpublished objects with draft versions and preview hash |
| `POST /projects/{project}/environments/{environment}/change-sets` | `publish` | `{previewHash, reason, submit, requestedReviewer}` |
| `GET /change-sets/{changeSet}` | `flags:read` | Resolved inside the token's project; else `404` |
| `POST /change-sets/{changeSet}/submit`, `/withdraw` | `publish` | |
| `POST /change-sets/{changeSet}/approvals` | PAT only, user must hold `changeset.approve` | `{decision, comment, candidateHash, publish}` |
| `POST /change-sets/{changeSet}/publish` | `publish` | `Idempotency-Key` header required |
| `POST /projects/{project}/environments/{environment}/rollbacks` | `publish` | `{targetRevision, expectedRevision, reason}` + `Idempotency-Key` |
| `POST /projects/{project}/environments/{environment}/flags/{flag}/emergency-disable` | `publish` | `{reason}` + `Idempotency-Key` |
| `GET /projects/{project}/environments/{environment}/revisions` and `/revisions/{number}` | `flags:read` | Cursor pagination; `?include=snapshot` returns the server payload only with `sensitive.read` |
| `GET /projects/{project}/audit-events`, `GET /organizations/{organization}/audit-events` | `flags:read` | Same filters as the portal; organization scope requires `org.manage` |

These go into `contracts/management/openapi.yaml`.

**SDK delivery (`routes/sdk.php`, prefix `/sdk/v1`, stateless middleware group: no session, no cookies, no CSRF)**

`GET /sdk/v1/snapshot`

1. `AuthenticateSdkKey` (M3 `SdkKeyAuthenticator`): bearer `bf_srv_` or `bf_pub_`, requires `snapshot:read`. Unknown key `401 INVALID_CREDENTIAL`; revoked key `401 CREDENTIAL_REVOKED` (counted for the system audit entry); rotation overlap honored. The key fixes environment and projection (`server` for `bf_srv_`, `public` for `bf_pub_`); no request parameter can change them.
2. Browser keys: `Origin` must be in the environment's allowed origins (M3), else `403 ORIGIN_NOT_ALLOWED`. CORS headers are sent only for browser keys and only for allowed origins. Server keys never get CORS headers.
3. Rate limits (`RateLimiter::for`): per credential (placeholder 6,000/min, since many instances share a key) and per IP for failed authentication (placeholder 60/min). `429 RATE_LIMITED` with `Retry-After`. Numbers are D08 placeholders in `config/bitflag.php`.
4. `DeliveryCache::current(envId, projection)` reads the pointer key; `If-None-Match` equal to the current ETag returns `304` (only after steps 1 to 3).
5. Otherwise the body is served from the immutable body key, or loaded from MySQL and cached.
6. The observation is recorded (section 3.6). Errors there never affect the response.

Response headers: `ETag`, `Cache-Control: private, no-cache`, `Vary: Authorization, Origin, Accept-Encoding`, `Content-Type: application/json`, `Content-Encoding: gzip` when accepted, `X-BitFlag-Revision`, `X-BitFlag-Generation`, `X-BitFlag-Projection`, `X-BitFlag-Protocol`, `X-BitFlag-Default-Max-Age` (environment default, outside the checksum so changing it needs no publish), `X-Request-Id`, `RateLimit-Limit`, `RateLimit-Remaining`. Bodies and the `Authorization` header are never logged.

`POST /sdk/v1/events` (minimal, superseded by M10): requires `events:write`; accepts only `evaluation_summary` events from the protocol schema; records first-evaluation markers into `sdk_credential_observations`; returns `202 {accepted, rejected[]}` with `UNSUPPORTED_EVENT_TYPE` for others. Body limit 64 KB. M10 swaps the handler behind the same route and schema.

### 3.5 Frontend

**Pages**

| Page | Composition |
| --- | --- |
| `pages/changes/Index.vue` | Header and 3-step explainer, tabs (`ChangesTabs`), `ChangeGroupCard` per environment (production first), `EmptyAllLive` (2g), `ApprovalsList`, `RevisionHistoryTable` with environment and object filters, Viewer banner (2h) |
| `pages/changes/Review.vue` | `ReviewHeader` ("rev 42 → 43 · 2 changes · 1 flag, 1 segment", state tag), `ReviewStatusBanner` (waiting, reviewing an exact proposal, approved, changes requested, review cancelled, nothing to publish, drafts based on older revision), `ChangeItemDiff` list, `TestUserImpactTable`, `ChecksList`, then one of `RequestReviewForm`, `PublishForm`, `ReviewerDecisionForm` (Approve, Approve & publish, Reject with required comment), `ApprovedPanel` |
| `pages/changes/Revision.vue` | `PublishSuccess`: "Rev 43 is live in production", checksum, approver, reason, included objects, "Replaces rev 42", delivery steps (Saved to the database; Update sent to SDKs and webhooks = cache ready; SDK instances on rev 43: x of y reported in the last 5 minutes), the copy "Published in the database doesn't mean every SDK has it yet", Roll back |
| `pages/audit/Index.vue` | `AuditSearch`, `AuditFilterMenu` ×4 with counts, result label "N of M entries", `AuditDayGroup` rows, `AuditEntryDrawer`, `ObjectHistory` (Audit 1c, `?object=flag:new-checkout`), Export button (disabled with reason for non-Owners or plan), window note "Showing the last N days" |
| `pages/overview/Show.vue` (M0's page) | `ProjectHeader` (live revision, publisher, delivery line), `UnpublishedBanner`, `OnboardingChecklist`, `StatTiles`, `RecentActivity` (from audit), `ReviewAndDraftCards`, `NeedsAttention`, `ConnectSdkPanel`, `EnvironmentSummaryTable` |

**Dialogs** (`features/publishing`): `PublishDialog` (opened from the flag editor; shows the whole environment change set and "Publishing production includes drafts by Priya Nair, not only yours"), `RollbackDialog` (diff, reason, "We'll publish rev 41's rules as a new revision, rev 44"; exposes the hook `ExperimentsAffectedByRevisionChange`, null until M11 lists experiments the rollback changes), `EmergencyDisableDialog` (environment named in the title, dependents, reason), `DiscardDraftsDialog` (all or one).

**Flag editor integration (M4's `FlagEditorStatusBar`).** M5 adds the states awaiting approval, approved ("approved this exact proposal"), published (only after the server returns the revision), invalid ("New edits require another review", with Discard new edits / Request review again), and publish failed (2d, Retry reuses the same idempotency key). Emergency disable is a menu item on the flag's environment card (no mockup exists, section 8).

**Composables**

| Composable | Responsibility |
| --- | --- |
| `useChangeSet(env)` | Holds preview hash, change set version and state; posts commands; maps 409 codes to banner states and refreshes the preview |
| `useIdempotencyKey()` | One UUID per dialog opening, reused across retries, reset on close |
| `useAuditFilters()` | Syncs search and facets with the URL query; debounced partial reloads |
| `useOnboardingVerification(env, flag)` | Polls the verification endpoint every 2 s for up to 2 minutes after "Run verification"; exposes per-check status and hint |
| `useRelativeTime()` | Local time with exact UTC on hover (frontend doc §3) |

Types in `resources/js/types/publishing.ts` and `audit.ts`, matching the Data classes.

### 3.6 Jobs, events, outbox consumers

| Consumer / job | Trigger | Queue | Behavior |
| --- | --- | --- | --- |
| `WarmDeliveryCache` | outbox `internal.delivery.revision_published` | `publication` (highest priority) | Loads both snapshot rows, writes immutable body keys, then advances the pointer key with a Lua compare-and-set that only accepts a higher `(generation, revision)`. Sets `revision_delivery_states.cache_ready_at`. Idempotent |
| `PendingWebhookFanout` | outbox `revision.published`, `review.requested`, `review.decided` | `integrations` | Placeholder that marks messages consumed until M9 registers `FanOutWebhookEvent`; payloads follow the R02 envelope |
| M8 handlers (registered by M8) | `review.requested`, `review.decided`, `internal.review.invalidated` | `notifications` | Email to eligible reviewers, in-app to contributors |
| `ReconcileDeliveryCache` | schedule, every 10 min | `publication` | Compares pointers changed in the last 24 h with Redis and repairs |
| `ExpireApprovals` | schedule, every 5 min | `default` | Runs the action |
| `FlushSdkCredentialObservations` | schedule, every minute | `default` | Moves Redis observation markers into `sdk_credential_observations` |
| `RecordRejectedKeyRequests` | schedule, every 5 min | `default` | Writes system audit `key.requests_rejected` ("Rejected 14 requests with revoked key ••••0b77") with count and distinct-address count |

**Redis keys** (prefix `bf:`, every key contains the environment ULID and projection; Redis is never the only copy)

| Key | Content | TTL |
| --- | --- | --- |
| `snap:{env}:{projection}:ptr` | `{generation, revision, etag, bodyKey}` | 10 s (bounds staleness if a consumer stalls) |
| `snap:{env}:{projection}:g{gen}:r{rev}` | gzip body and plain length | 7 days (immutable) |
| `obs:{env}:instances` | hash: instance → `ts|gen|rev|sdk` | trimmed to 24 h |
| `obs:first:{credential}` | first-fetch marker | 30 days |

On a pointer miss, the reader takes a short `Cache::lock` per environment and projection (single flight), reads `publication_pointers` and `snapshots` by primary and unique keys, and repopulates. If Redis is unavailable, the endpoint reads MySQL directly (two indexed lookups per request) and logs a degraded-mode metric; rate limiting fails open for authenticated keys in that mode. M12 load tests validate that MySQL survives this.

**How SDK delivery is observed (onboarding and the published screen)**

- Each authenticated `200` or `304` records the credential's first fetch (`SET NX`) and the instance's latest `(generation, revision)`. Instance ID comes from `X-BitFlag-Instance` (random per process, sent by the PHP client); without it, a hash of credential fingerprint and client IP is used. The hash is never stored in MySQL.
- Rejections for a known credential (revoked, origin not allowed, scope missing) record `last_rejection_code` for the verify hint.
- An `evaluation_summary` naming a flag records `first_evaluation_at` and adds the key to `evaluated_flag_keys`.
- "SDK instances on rev N: x of y" counts instances seen in the last 5 minutes. The UI says "reported in the last 5 minutes" and never claims coverage of offline clients.

**Onboarding checklist (portal Overview).** Derived server-side by `Publishing\Queries\OnboardingProgress`, never from browser storage:

| Step | Done when |
| --- | --- |
| 1. Create a flag | The project has a flag in any non-archived state |
| 2. Publish to development | The first environment in order that is not protected has a revision whose `revision_changes` include a flag |
| 3. Connect an SDK | A key exists for that environment and an authenticated fetch was observed |
| 4. Verify an evaluation | For the onboarding flag (the first flag published there): Authenticated (any fetch observed), Snapshot downloaded (an instance reported a revision ≥ the first revision containing the flag), Evaluation reported (an `evaluation_summary` for that flag after that revision) |

The Connect panel offers the PHP client in M5 (other SDKs arrive in M13 and are shown as "coming soon", not hidden). It never shows a demo key: with `credentials.manage` it offers "Create a development server key" (M3 action, one-time reveal); without it, it says who can create one.

### 3.7 Security and tenant isolation

- Every change set, approval, revision and audit lookup resolves inside the authorized project; a valid ID from another organization returns `404`.
- SDK responses, including `304`, are produced only after key authentication, revocation, scope and origin checks. Cache keys include the environment ULID and projection; the body for a public key is always the public projection.
- Snapshot payloads, override subject keys and include lists never appear in logs, audit details, emails or webhook payloads. Diffs are stored with `sensitive` markers and rendered masked (`u_••••31`) for users without `sensitive.read`, in the portal and in CSV export.
- CSV export: bounded to the plan's visible window and 50,000 rows, streams in chunks, prefixes cells starting with `=`, `+`, `-` or `@` with `'`, and is itself audited.
- Approvals by tokens are refused; approver eligibility is rechecked under lock at publication.
- The audit window and export availability come from `App\Shared\Plans\Entitlements` (`auditHistoryDays`, `allows(Feature::AuditExport)`). Until M6 binds the real implementation, `StaticEntitlements` (implementing it) returns 90 days with export allowed. Required approvals are never re-gated at publish time: an existing policy keeps applying after a downgrade (billing doc §4). M6 gates editing the policy.
- Jobs carry `organization_id`, environment and revision IDs only, never payloads or keys.

## 4. Implementation steps

Each step is one pull request in `bitflag` unless marked otherwise.

1. **Schema and models.** Migrations for every table in 3.1, triggers on `snapshots` and `revisions`, generated column and unique index on `change_sets`, models, factories, tenant-scope coverage. Files: `database/migrations/2026_…_create_publication_tables.php`, `app/Modules/Publishing/Models/*`.
2. **Snapshot finalization.** `Domain/Snapshot/SnapshotFinalizer`, `PublicProjector`, `PublicProjectionPolicy`, `PublicationValidator`, `EtagFactory`, wired to M1's canonicalizer, schema validator and the M4 `SnapshotCompiler`. Unit tests against protocol fixtures.
3. **Change set domain.** `ChangeSetStateMachine`, `CandidateFreezer`, `SnapshotDiff` and `DiffDescriber` (human lines like `Rule 02 "PH premium" rollout 30% → 20%`), `TestUserImpact`, `ApprovalPolicy` + `DefaultApprovalPolicy`, `EmergencyActionPolicy`.
4. **Review actions.** `CreateChangeSet`, `SubmitChangeSetForReview`, `ResubmitChangeSet`, `WithdrawChangeSet`, `DecideReview` (without publish), listeners `InvalidateOpenChangeSet`, `RecheckApprovals`, job `ExpireApprovals`.
5. **Publication core.** `PublicationWriter`, `PublishChangeSet`, `PublishEnvironment`, `DecideReview(publish: true)`, M0's `Shared/Idempotency` service, outbox message classes.
6. **Concurrency harness and race tests** (`tests/Feature/Publishing/Concurrency`, section 5).
7. **Rollback and emergency disable.** Actions, revalidation, invalidation of the open change set, M4 draft update.
8. **Delivery cache and consumers.** `DeliveryCache` (Lua CAS), `WarmDeliveryCache`, `ReconcileDeliveryCache`, `PendingWebhookFanout`, commands `bitflag:delivery:warm`, `bitflag:delivery:bump-generation`.
9. **SDK snapshot endpoint.** `routes/sdk.php`, `Http/Controllers/Sdk/SnapshotController`, CORS for browser keys, limiters, headers, empty snapshot, `SdkObservationRecorder`, `FlushSdkCredentialObservations`, `RecordRejectedKeyRequests`.
10. **Minimal events endpoint** (`EventsController`, evaluation summaries only; superseded by M10's handler).
11. **`bitflag-php` client** (repository `bitflag-php`, two PRs): `src/Transport/HttpSnapshotFetcher` (PSR-18/17, timeouts, backoff with jitter, `If-None-Match`, `X-BitFlag-SDK`, `X-BitFlag-Instance`), `src/Cache/SnapshotStore` (PSR-16, keyed by base URL and key fingerprint, stores bytes, ETag, `lastValidatedAt`; best-effort refresh lock), `src/Client/BitFlagClient` (`ready`, `evaluate`, `getValue`, `isEnabled`, `refresh`, `close`; checksum and schema verification; never moves to an older `(generation, revision)`; `maxAge` → `SNAPSHOT_TOO_OLD`; `401/403` stops refresh), `src/Telemetry/EvaluationSummary` (counts flushed on `close`). Released as `0.x` (D03).
12. **Management API** controllers, resources, OpenAPI entries, error mapping.
13. **Changes page** (`changes/Index`, group cards, approvals list, history table, discard dialogs, shell counts).
14. **Review page and publish flow** (`changes/Review`, forms, banners, `useChangeSet`, `useIdempotencyKey`, `changes/Revision` success page).
15. **Flag editor integration** (status bar states, `PublishDialog`, `RollbackDialog`, `EmergencyDisableDialog`, publish-failed state).
16. **Audit page** (`AuditController`, `Audit\Queries\AuditLogQuery` with facets and cursor, drawer, object history, `ExportAuditLog`). Adds a `search_text` column and FULLTEXT index on `audit_events` if M0 did not.
17. **Overview page** (`OverviewQuery`, `OnboardingProgress`, `ConnectSdkPanel`, verification endpoint and polling, environment summary, needs-attention items available in M5: invalid drafts, temporary flags past removal date).
18. **Demo seed** (`PublishingDemoSeeder`): revisions matching the mockups (development 118, staging 76, production 42 with approvals by Jon Reyes), production protected with 1 approval, drafts as in Changes 1a. Snapshots are produced with the real compiler and finalizer so checksums are valid.
19. **Cypress specs and CI wiring** (section 5), including a CI step that runs the PHP client script inside the Sail app container.

## 5. Tests

**Unit (`tests/Unit/Publishing`)**

- `ChangeSetStateMachineTest`: every allowed and forbidden transition.
- `DefaultApprovalPolicyTest`: matrix of role × contributor × token × expiry × required count.
- `CandidateHashTest`: stable under key order and whitespace; changes with base revision, policy version or any content byte.
- `SnapshotFinalizerTest`: checksum equals the `bitflag-php` verifier's result on protocol fixtures; JCS edge cases (Unicode, numbers).
- `PublicProjectorTest` / `PublicProjectionPolicyTest`: invisible flags dropped; overrides, private segments, non-public prerequisites rejected; nothing silently stripped.
- `SnapshotDiffTest`, `DiffDescriberTest`, `EtagFactoryTest`, `TestUserImpactTest`.

**Feature (`tests/Feature/Publishing`, real MySQL and Redis)**

- `RequestReviewTest`, `DecideReviewTest` (self-approval refused, Developer and Viewer refused, `bf_mgt_` refused, reject needs comment, Approve & publish).
- `PublishChangeSetTest` (unprotected direct publish, protected requires approval, reason, preview hash mismatch, `NOTHING_TO_PUBLISH`).
- `StaleApprovalTest` (draft edit, segment edit, policy change, membership downgrade, expiry, lazy recheck without event).
- `IdempotentPublishTest` (same key returns the same revision; different payload conflicts; retry after a simulated timeout).
- `RollbackEnvironmentTest`, `EmergencyDisableFlagTest` (new revision, history intact, archived-flag block, drafts preserved, draft set to disabled).
- `PublicationAtomicityTest` (forced exception after snapshot insert leaves no revision, audit or outbox row).
- `SnapshotEndpointTest` (no `304` before auth; wrong, revoked and wrong-scope keys; browser origin; projection by key type; revision 0 snapshot; headers; `429`).
- `DeliveryCacheTest` (warm, CAS never regresses, Redis down falls back to MySQL, reconcile repairs).
- `OutboxConsumersTest` (duplicate and out-of-order messages are harmless).
- `EventsEndpointTest`, `OnboardingProgressTest`, `AuditLogQueryTest` (facets, search, window, cursor tie-break), `AuditExportTest` (cap, masking, formula guard, capability, entitlement), `OverviewQueryTest`.
- `PublishingTenantIsolationTest`: cross-organization IDs on every route and API endpoint return `404`; delivery cache keys never collide.

**MySQL concurrency (`tests/Feature/Publishing/Concurrency`, group `mysql-concurrency`).** The harness forks worker processes (`pcntl_fork`) with their own connections and a Redis barrier so actions start together. CI runs it on MySQL 8 with InnoDB defaults.

| Test | Expectation |
| --- | --- |
| `ParallelPublishSameChangeSetTest` | Exactly one revision; the other call gets the idempotent result or `CHANGESET_NOT_OPEN` |
| `PublishVersusDraftSaveTest` | Either the publish commits the frozen content and the save lands after it, or the save commits first and the publish fails `DRAFT_CHANGED`; never published content that differs from the candidate |
| `PublishVersusRollbackTest` | Consecutive revisions; the change set fails `BASE_REVISION_CHANGED` if the rollback wins |
| `ApproveVersusEditTest` | Never `approved` with a mismatched draft version |
| `ParallelEmergencyDisablesTest` | Ten disables of different flags give ten consecutive revision numbers, no gaps or duplicates, each built on the previous |
| `SameIdempotencyKeyRaceTest` | One revision, identical responses |
| `PolicyChangeVersusPublishTest`, `MembershipDowngradeVersusPublishTest` | Publish fails or the change waits; no publish under a stale policy or approver |
| `OpenChangeSetUniquenessTest` | Concurrent submits create one open change set |
| `LockOrderDeadlockTest` | Mixed draft saves and publishes complete with retries and no unrecoverable deadlock |

**Architecture (`tests/Arch/PublishingArchTest.php`).** Only `PublicationWriter` writes `publication_pointers`, `revisions` and `snapshots`; `Snapshot` and `Revision` models expose no update path; the SDK controllers do not import Flags or Targeting models; Publishing writes Flags data only through Flags actions.

**`bitflag-php` (`tests/Client`).** Fake PSR-18 transport: `304` refreshes `lastValidatedAt`, failed refresh does not; restored cache keeps its original `lastValidatedAt`; checksum mismatch keeps the previous snapshot; older `(generation, revision)` ignored; `401` stops refresh; `CLIENT_NOT_READY` before first snapshot; fallback type errors. Protocol cache and fallback fixtures.

**Vitest.** `useChangeSet.spec.ts` (409 mapping), `ReviewStatusBanner.spec.ts` (all states), `ChangeItemDiff.spec.ts` (masked lines), `useAuditFilters.spec.ts`, `useOnboardingVerification.spec.ts`, `PublishSuccess.spec.ts` (never shows "live everywhere"), `FlagEditorStatusBar.spec.ts` (M5 states).

**Cypress (seeded Acme world)**

| Spec | Flow |
| --- | --- |
| `cypress/e2e/m5-first-flag-end-to-end.cy.ts` | Maya creates `e2e-hello-{run}` (boolean), enables it in development with default `on`, saves, opens Changes, reviews and publishes development, sees the revision page. Creates a development server key and captures the one-time reveal. `cy.exec` runs `php tests/e2e-support/sdk-check.php --key … --flag … --context '{"kind":"user","key":"u_1"}'` (the real `bitflag-php` client over HTTP against the app), asserts `{"value":true,"reason":"DEFAULT","revision":N}`. Overview checklist then shows 4 of 4 after "Run verification" |
| `cypress/e2e/m5-protected-review.cy.ts` | Priya (Developer) requests production review; she cannot publish; Jon uses Approve & publish; history shows "approved by Jon Reyes" |
| `cypress/e2e/m5-stale-approval.cy.ts` | Jon approves; Maya edits the rule; Maya sees "New edits require another review"; Approvals tab shows the cancellation cause |
| `cypress/e2e/m5-rollback-and-emergency-disable.cy.ts` | Roll back production to an earlier revision with a reason; emergency-disable a flag; both appear as new revisions and in Audit |
| `cypress/e2e/m5-audit-log.cy.ts` | Filters, search, drawer, object history, Viewer cannot export |
| `cypress/e2e/m5-changes-viewer.cy.ts` | Ana Cruz sees read-only Changes (2h) |

## 6. Decisions

| Item | Default built until decided |
| --- | --- |
| D02 approvals | Section 3.2 table: no self-approval for any contributor, count from policy (1 when protected), 168 h expiry, any eligible reviewer, users only. All in `DefaultApprovalPolicy` and policy columns |
| D05 emergency and rollback | Emergency disable and rollback create new revisions, skip approval, require the environment's publish capability and a reason (always for emergency disable, per policy for rollback). Emergency disable also disables the draft. In `EmergencyActionPolicy` |
| D06 browser exposure | Public projection rules in section 3.2; all attributes public until an attribute registry exists; no browser remote evaluation |
| D03 protocol | RFC 8785 canonical JSON, `sha256:` checksum over the payload minus `checksum`, ETag format above, 1 MB limit, empty snapshot at revision 0, generation starts at 1. Client and snapshots ship as `0.x` |
| D04 delivery | Polling only; pointer cache TTL 10 s; observation window 5 minutes; no stream |
| D08 scale | Rate limits and pointer TTL are placeholders in `config/bitflag.php` |
| D09 retention | `sdk_credential_observations` kept 30 days after last activity; no raw IPs; snapshot and audit purges not implemented |
| D12 lifecycle | Rollback blocks on archived flags |
| D14 API | Routes, scopes (`flags:read` including audit reads, `publish`, `snapshot:read`, `events:write`), error codes and 24 h idempotency retention as above |
| R01, R02, R08, R10 | Applied as written: approvers are Owners and Publishers; R02 event names; audit window and export through `Entitlements`; review requests by email via M8 |

## 7. Done criteria

- `m5-first-flag-end-to-end.cy.ts` passes in CI: create flag, publish to development, fetch with the PHP client, expected value, onboarding verified from real observations.
- All concurrency tests pass on real MySQL; no test produces duplicate revision numbers, a pointer that moves backward, or published content that differs from an approved candidate.
- A forced failure inside the publication transaction leaves no revision, snapshot, audit or outbox row.
- Delivery tests prove `304` is never returned before authentication, public keys never receive the server projection, and the cache never serves an older revision after a newer one was warmed.
- Snapshots and revisions cannot be updated (trigger and arch tests).
- The `bitflag-php` client passes protocol cache and fallback fixtures and is tagged `0.x`.
- Every mockup state in section 2 has a page state, and every portal command maps to an action with audit and the documented error codes.
- Pest, PHPStan, Pint, Vitest, `vue-tsc` and all M5 Cypress specs are green.

## 8. Risks and open questions

**Inconsistencies for Shei to decide**

1. **Rollback flow.** BitFlag Changes 2e: "Rollbacks skip review so you can act fast" (direct new revision). portal/Changes and portal/Flags: "Rev 41's content becomes a draft. You'll review it and publish it", replacing current drafts. M5 builds the direct rollback (D05 default) and keeps drafts. Building "Load and review" too needs a decompile-into-drafts path from M4. **Resolved (R17, 8 October 2026): the default above is the decision.**
2. **Per-flag publish copy.** portal/Flags publish modal ("Your N unpublished changes to {flag}") and Approvals 2c ("production · payment-config") read as flag-scoped, while docs make the environment the unit. M5 makes every entry point show the whole environment.
3. **"Admin" reviewer.** portal/Changes and portal/Flags list "Jon Reyes · Admin" (role "Admin · reviewer"); R01 has no Admin. Built to R01.
4. **Audit window and export.** BitFlag Audit 2c says older entries are "in the CSV export"; portal/Audit says "kept for 1 year on this plan"; R08 says Free 7 days without export, Pro 90 days with export. Resolved (R17): every role with `audit.read` may export on Pro; the "Exporting needs the Owner role" copy is removed. M5 adds the `audit.export` capability so the rule can change later without code changes.
5. **Approve & publish vs requester publishes.** BitFlag 2b combines them; portal separates them. Both are supported; since Developers cannot publish protected environments, the approver must publish their changes.
6. **Streaming claims.** Changes 2f "Connected SDKs pick it up within seconds. 38 streams are connected" and Overview "38 SDK connections" assume streaming (D04, not in the core plan). M5 uses observed-instance wording.
7. **Onboarding steps.** BitFlag Overview 1b (create, configure, publish, connect), portal/Overview (create, publish to development, connect, verify) and accounts doc §2 (six steps incl. creating a key) differ. M5 builds portal/Overview's four, with key creation inside Connect. Its snippets name `@bitflag/node`, `@bitflag/js` and Laravel, which do not exist until after the core.
8. **Saved test contexts.** Changes 2a shows saved test users "evaluated against live and this change", while the simulator says "Context you enter here isn't saved or logged". M5 uses M4's explicit, named `test_contexts`. **Resolved (R17, 8 October 2026): the default above is the decision.**
9. **Emergency disable** is shown only as a plan feature (Pricing, Organization downgrade copy); no screen exists. M5 adds a menu item and dialog on the flag environment card.
10. **Audit of reads.** portal/Audit includes "Read flags list" by a management token. M5 audits writes only.
11. **Required approvals on Free.** R08 makes them Pro-only, yet every demo production environment requires one. M5 enforces whatever policy is stored; M6 decides what Free can configure. **Resolved (R17, 8 October 2026): the default above is the decision.**

**Risks**

- **Cross-milestone dependencies.** M5 relies on M4 for `SnapshotCompiler` (placed in `Publishing/Domain` per the architecture doc), `EnvironmentDraftChanges`, `environment_draft_states`, draft events and `RecordPublishedBaseline`; on M3 for `SdkKeyAuthenticator` and policy columns; on M1 for canonical JSON and the schema validator; on M0 for multi-handler outbox dispatch.
- **Lock order discipline.** A future action that locks change sets before the draft-version row can deadlock with publication. The order in 3.3 is documented in `Publishing/README` and covered by `LockOrderDeadlockTest`.
- **Public projection strictness.** Rejecting client-visible flags that have overrides may surprise users; M4's editor should warn before save. Relaxing it is a D06 product choice, not a silent strip.
- **MySQL fallback load.** If Redis fails, every SDK poll hits MySQL. The fallback is two indexed reads, but M12 must load-test it and may add a per-process memory cache.
- **Observation accuracy.** Instance IDs from SDKs that do not send `X-BitFlag-Instance` are approximate; the UI labels counts as reported, not complete.
- **Onboarding before M10.** The minimal events endpoint must not become a second ingestion design; it accepts only `evaluation_summary` and M10 replaces its handler.
