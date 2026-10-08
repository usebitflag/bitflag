# M9. Webhooks and integrations

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Expands M9 in the [implementation plan](../implementation-plan.md).

## 1. Goal and scope

Give every project signed outgoing webhooks for the R02 event catalog, and connections to Slack, GitHub, Datadog, Jira, Vercel and Sentry with real connection states, health checks and delivery history. Integration failures never block or reverse a publication; they become visible, retryable deliveries.

**In scope**

- Webhook endpoints per project: name, HTTPS URL, subscribed R02 events, environment filter, signing secret (shown once, rotation with a 24-hour dual-signing overlap), pause and resume, delete, test event, delivery history with every attempt, manual retry and bounded replay.
- Egress safety: SSRF validation at save time and at every connection (DNS pinning against rebinding), no redirects, timeouts, response size caps.
- Retry with backoff ("5 times over 2 hours"), automatic pause after 24 hours of continuous failure, owner notification through M8.
- Provider framework: organization-level connections (OAuth or token), project-level bindings (resource mapping), encrypted credentials, the states `connecting`, `connected`, `degraded`, `expired`, `revoked`, scheduled health checks, provider deliveries with retries, verified inbound provider webhooks.
- The six providers, each built against the D11 default contract in §6 and verified against the provider's current API before its UI copy is frozen.
- GitHub code reference scans (Pro), consumed by M4's flag detail and M10's cleanup evidence.
- Plan gates through M6: Free gets Slack and 1 enabled webhook per organization; everything else is Pro. Downgrade pauses deliveries without deleting configuration.
- The "AI assistants (MCP)" tab: per-project assistant policy and the list of connected assistants, enforced on management API calls made with assistant tokens.

**Out of scope**

- Producing the R02 outbox messages (M3 `key.revoked`, M4 `flag.changed`, M5 `revision.published`, `review.requested`, `review.decided`, M11 `experiment.started`, `experiment.stopped`). M9 only consumes them.
- In-app and email notification delivery (M8). M9 emits `internal.` outbox messages that M8 turns into notifications.
- The MCP server itself and its OAuth client flow (`bitflag-mcp`, M13 part G, D11). M9 stores policy and lists tokens that M3 issues.
- SDK-side Sentry hooks that attach flag values to error events (`bitflag-js`, `bitflag-php`, M13).
- Network-level egress proxy provisioning (D08). M12 verifies it.

## 2. Mockups covered

| Mockup | Artboard | Behavior implied |
| --- | --- | --- |
| BitFlag Integrations | 1a Tabs by kind | Chosen layout. Tabs Webhooks (count), Apps ("2 connected"), AI assistants (MCP) (count). Webhook table: name + URL, events (single name or "N events"), environments ("all environments" when every one is selected), last delivery ("2 h ago · 200" or "Never"), status Healthy / Failing / Paused |
| BitFlag Integrations | 1b, 1c | Alternative layouts (connected-first page, catalog with categories Chat/Code/Monitoring/Deploys/AI assistants and search). Not built; 1c's catalog search is noted as a follow-up only |
| BitFlag Integrations | 2a Webhook detail, failing | Header actions Send test event, Pause/Resume, Delete. Failure banner "The last 3 deliveries failed. We retry each one 5 times over 2 hours. After 24 hours of failures the webhook pauses itself and we email the owner." Sends list and "From:" environments. Signing secret `whsec_••••••••41be` with Rotate secret and the `BitFlag-Signature` header hint. Deliveries table: event, summary ("production rev 42 · retry 2"), response code, time taken ("timed out (10 s)"), when, Retry |
| BitFlag Integrations | 2b New webhook | Name, URL with live hint ("Must be https. We time out after 10 seconds." / "Looks good" / "Use a full https:// URL"), event checkboxes with help text, environment checkboxes; Create disabled until name, valid URL, one event and one environment. Mockup lists the five original events; the form adds `experiment.started` and `experiment.stopped` (R02) |
| BitFlag Integrations | 2c Signing secret shown once | Modal "Copy the signing secret now. It won't be shown again." Done enabled only after "I've stored the secret"; Escape and backdrop do not close it |
| BitFlag Integrations | 2d AI assistants (MCP) | MCP server on/off (turning off revokes access immediately, with confirm). Permission toggles: read (locked on), create and edit drafts, publish non-production, create and revoke SDK keys ("Not recommended"). Client snippets. Connected assistants list with person, role, last used, Revoke. Link to Audit filtered to channel `mcp` |
| BitFlag Integrations | 2e Connect an app | Connect dialog per provider with fields; "You'll be sent to {app} to approve access, then brought back here." Slack: Channel, Send ("Publishes and review requests" / "Publishes only"), note "Reviewers can approve from Slack. Production publishes still need their BitFlag role." |
| BitFlag Integrations | 2f Empty: no webhooks | Empty state copy and "Webhook payload reference" docs link |
| BitFlag Integrations | 2g Developer, read-only | Banner "You're a Developer. You can view this, but only owners can change it." All controls disabled; list and detail still readable |
| portal/Integrations | prototype | Provider configs: Slack Channel + Send; GitHub Repository + Show; Datadog Site (`datadoghq.com`, `datadoghq.eu`) + "Publishes as deploy events"; Jira Project + "Flags to issues"; Vercel Project + "Preview deploys use development / staging"; Sentry Project + "Errors with flag values". Configure dialog "Changes apply to the next event." Disconnect "BitFlag stops sending to {app} right away. You can reconnect later." Pause toast "Events are not queued while paused." Delete "Its delivery history is removed. This can't be undone." Rotate "For 24 hours we'll sign each request with both the old and new secret." Test event appears as a `ping` delivery |
| BitFlag Overview | 1a Needs attention | "slack-releases Webhook: 3 failed deliveries since 09:10": M9 provides the attention items query used by M5's Overview |
| BitFlag Organization | 2a–3f (billing) | "Webhooks 1 / 1" on Free; downgrade "GitHub, Datadog and 2 extra webhooks: Paused. Settings are kept and resume if you upgrade." |
| BitFlag Emails | 3b | "Integrations GitHub and Datadog paused" in the plan-changed email (M8 renders it from M9's paused-by-plan list) |

## 3. Design

### 3.1 Data model

All tables are tenant-owned, use ULID keys, `timestamp(6)` UTC, and start every composite index with `organization_id`.

**`webhook_endpoints`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | Public `whk_` |
| `organization_id`, `project_id` | ulid | FK; webhooks belong to a project (mockup is project-scoped) |
| `name` | varchar(80) | Required |
| `url` | varchar(2048) | Normalized (lowercase scheme and host, punycode host, no fragment, no userinfo) |
| `url_host` | varchar(253) | For display and support lookups |
| `events` | json | Array of R02 event types, at least one |
| `status` | enum(`active`,`paused`) | |
| `paused_reason` | enum(`manual`,`failing`,`plan`) null | Set when paused |
| `paused_at` | timestamp null | |
| `secret_ciphertext` | text | Laravel encrypter (recoverable secret, key from the secret manager) |
| `secret_tail` | char(4) | Shown as `whsec_••••••••41be` |
| `previous_secret_ciphertext` | text null | Kept during rotation overlap |
| `previous_secret_expires_at` | timestamp null | Rotation time + 24 h |
| `failing_since` | timestamp null | First failed attempt after the last success |
| `last_attempt_at`, `last_success_at` | timestamp null | |
| `last_status_code` | smallint null | Last completed attempt |
| `created_by_user_id` | ulid | |
| `version` | int | Optimistic concurrency |

Indexes: `(organization_id, project_id, status)`, `(organization_id, status)` for the plan count.

**`webhook_endpoint_environments`**: `organization_id`, `webhook_endpoint_id`, `environment_id`; primary key `(webhook_endpoint_id, environment_id)`; composite FK guarantees the environment belongs to the endpoint's project. At least one row per endpoint.

**`webhook_deliveries`** (one per endpoint and event)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | Public `dlv_` (new prefix) |
| `organization_id`, `project_id`, `webhook_endpoint_id` | ulid | |
| `environment_id` | ulid null | Null for `ping` |
| `event_id` | ulid | The outbox message ID, public `evt_` |
| `event_type` | varchar(40) | R02 type or `ping` |
| `payload` | json | Signed envelope body (no secrets, no rule contents), max 64 KB |
| `status` | enum(`pending`,`in_progress`,`retrying`,`succeeded`,`failed`,`cancelled`) | |
| `attempt_count` | tinyint | |
| `next_attempt_at` | timestamp null | |
| `last_status_code` | smallint null | |
| `last_error_class` | varchar(32) null | `timeout`, `connection`, `dns`, `destination_forbidden`, `tls`, `response_too_large`, `http_status` |
| `completed_at` | timestamp null | |

Unique `(webhook_endpoint_id, event_id)` makes fan-out idempotent. Indexes `(organization_id, webhook_endpoint_id, created_at)`, `(status, next_attempt_at)`.

**`webhook_delivery_attempts`**: `id` (public `att_`, new prefix), `organization_id`, `webhook_delivery_id`, `attempt_number`, `trigger` enum(`initial`,`retry`,`manual`), `requested_by_user_id` null, `started_at`, `duration_ms`, `status_code` null, `error_class` null, `response_excerpt` varchar(1024) null (first 1 KB, UTF-8 sanitized, shown only to `integrations.manage`), `resolved_ip` varbinary(16) null. Index `(organization_id, webhook_delivery_id, attempt_number)`.

**`provider_connections`** (organization level: one Slack workspace, GitHub installation, Atlassian site, and so on)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | Public `int_` (new prefix) |
| `organization_id` | ulid | |
| `provider` | enum(`slack`,`github`,`datadog`,`jira`,`vercel`,`sentry`) | |
| `status` | enum(`connecting`,`connected`,`degraded`,`expired`,`revoked`) | Provider states proposed here (the domain model lists `provider_connections` without states) |
| `status_reason` | varchar(48) null | For example `PERMISSION_REJECTED`, `TOKEN_REFRESH_FAILED`, `USER_DISCONNECTED`, `PROVIDER_UNINSTALLED`, `CHANNEL_NOT_FOUND` |
| `external_account_id`, `external_account_name` | varchar | Slack team, GitHub installation, Atlassian cloud ID, Vercel team, Datadog site, Sentry org |
| `granted_scopes` | json | As returned by the provider |
| `credentials_ciphertext` | text null | Encrypted JSON: access token, refresh token, API key; never in props, logs or job payloads |
| `credentials_expires_at` | timestamp null | Drives refresh |
| `oauth_state_hash` | char(64) null | SHA-256 of the state while `connecting` |
| `connecting_context` | json null | Initiating user, project, chosen binding settings, PKCE verifier (encrypted), expiry (15 min) |
| `connected_by_user_id`, `connected_at` | | |
| `last_health_check_at`, `last_healthy_at`, `degraded_since` | timestamp null | |
| `version` | int | |

Unique `(organization_id, provider, external_account_id)` for non-revoked rows (generated column `active_key`).

**`provider_bindings`** (project level: what this project sends where)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | |
| `organization_id`, `project_id`, `provider_connection_id` | ulid | |
| `resource_type` | enum(`slack_channel`,`github_repository`,`datadog_site`,`jira_project`,`vercel_project`,`sentry_project`) | |
| `resource_id`, `resource_name` | varchar | Channel ID and `#releases`, repo ID and `acme/storefront`, and so on |
| `settings` | json | Validated per provider (§3.2) |
| `status` | enum(`active`,`paused`,`broken`) | `paused` with `paused_reason = plan`; `broken` when the resource disappeared |
| `paused_reason` | enum(`plan`) null | |
| `version` | int | |

Unique `(project_id, provider_connection_id, resource_type)`: one binding per provider per project (mockup shows one channel, one repository).

**`provider_deliveries`**: same shape as `webhook_deliveries` minus payload size and response excerpt, keyed by `provider_binding_id` and `event_id`, unique on both. Provider attempts are summarized on the delivery row (`attempt_count`, `last_error_class`, `last_provider_error`), not a separate attempts table.

**`provider_inbound_events`**: `organization_id` null (unknown until resolved), `provider`, `external_event_id` (GitHub `X-GitHub-Delivery`, Slack `event_id`, and so on), `received_at`, `event_type`, `provider_event_at`, `status` enum(`received`,`processed`,`ignored`,`failed`). Unique `(provider, external_event_id)`. Payload is not stored beyond the fields the handler needs.

**`provider_user_links`** (only if D11 approves in-Slack approvals): `organization_id`, `provider_connection_id`, `user_id`, `external_user_id`, `linked_at`, `revoked_at`. Unique `(provider_connection_id, external_user_id)`.

**`code_reference_scans`** (domain model persistence groups: Integrations): `id`, `organization_id`, `project_id`, `provider_binding_id`, `repository`, `branch`, `commit_sha`, `trigger` enum(`push`,`schedule`,`manual`), `status` enum(`queued`,`running`,`succeeded`,`failed`,`skipped`), `failure_code` null (`TOO_LARGE`, `ACCESS_DENIED`, `TIMEOUT`), `files_scanned`, `files_skipped`, `bytes_scanned`, `started_at`, `finished_at`. Index `(organization_id, project_id, finished_at)`.

**`code_references`**: `organization_id`, `project_id`, `code_reference_scan_id`, `flag_id` null, `flag_key`, `path` varchar(1024), `line` int. No code excerpt is stored (excerpts can contain secrets); the UI links to the provider's blob URL at the scanned commit. Index `(organization_id, project_id, flag_id, code_reference_scan_id)`.

**`flag_issue_links`** (Jira): `organization_id`, `project_id`, `flag_id`, `provider_binding_id`, `issue_key`, `issue_url`, `status` enum(`linked`,`missing`), `last_synced_at`, `created_by_user_id`. Unique `(flag_id, issue_key)`.

**`mcp_policies`**: `organization_id`, `project_id` (unique), `enabled` bool (default true), `allow_draft_write` bool (default true), `allow_publish_non_production` bool (default false), `allow_key_management` bool (default false), `version`.

Retention defaults (D09 open): webhook deliveries and attempts 30 days, provider deliveries 30 days, inbound events 14 days, code reference scans keep the latest 20 per binding, `connecting` connections deleted 15 minutes after expiry.

### 3.2 Domain rules and state machines

**Event catalog.** `WebhookEventType` enum: `revision.published`, `review.requested`, `review.decided`, `flag.changed`, `key.revoked`, `experiment.started`, `experiment.stopped` (R02), plus `ping` for test deliveries only (cannot be subscribed). Every R02 event carries an environment, so the environment filter applies to all of them.

**Envelope** (schema owned by `bitflag-protocol`, D03; this is the proposal M9 builds to):

```json
{
  "id": "evt_01JAB3…",
  "type": "revision.published",
  "schemaVersion": 1,
  "occurredAt": "2026-10-06T08:00:00.000Z",
  "organization": { "id": "org_…" },
  "project": { "id": "prj_…", "key": "storefront" },
  "environment": { "id": "env_…", "key": "production" },
  "data": {
    "revision": 42, "generation": 1, "source": "publish",
    "changeSetId": "chg_…", "flagKeys": ["new-checkout"], "flagKeysTruncated": false,
    "actor": { "type": "user", "id": "usr_…", "name": "Maya Santos" },
    "url": "https://app.bitflag…/acme/storefront/production/revisions/42"
  }
}
```

| Event | `data` fields (minimal, never rule contents, subject keys, key values or attributes) |
| --- | --- |
| `revision.published` | revision, generation, source (`publish`, `rollback`, `emergency_disable`, `experiment`), changeSetId, flagKeys (max 100), actor, url |
| `review.requested` | changeSetId, changeCount, requestedBy, url |
| `review.decided` | changeSetId, decision (`approved`, `rejected`), reviewer, url |
| `flag.changed` | flagKey, flagId, draftVersion, changedBy, url |
| `key.revoked` | keyId, label, keyType (`server`, `browser`, `management`), displayPrefix (`bf_srv_prod_••••0b77`), revokedBy |
| `experiment.started` | experimentId, key, flagKey, revision, url |
| `experiment.stopped` | experimentId, key, flagKey, decision (`ship_variation`, `keep_control`, `no_serving_change`), shippedVariationKey, url |

**Signing** (proposal for D03): HMAC-SHA256 over `{timestamp}.{raw body bytes}` with the endpoint secret, hex encoded.

```text
BitFlag-Signature: t=1791273600,v1=5f0c…e1,v1=a93b…07
BitFlag-Event-Id: evt_01JAB3…
BitFlag-Event-Type: revision.published
BitFlag-Delivery-Id: dlv_01JAB4…
BitFlag-Attempt: 3
User-Agent: BitFlag-Webhooks/1
Content-Type: application/json
```

- During the 24-hour rotation overlap the header carries two `v1` values, new secret first. After `previous_secret_expires_at` a scheduled job clears the old ciphertext.
- Receivers must reject timestamps older than 5 minutes and deduplicate by `BitFlag-Event-Id` (documented; retries and manual retries reuse the event ID with a fresh timestamp and signature).
- Secrets: `whsec_` + 32 characters from a 32-symbol alphabet (160 bits), generated with `random_bytes`.

**Destination policy (SSRF).** `Domain\Egress\DestinationPolicy` is used at save time and again at every connection by `SafeHttpClient`:

1. Scheme `https` only. Port 443 or 8443 (open question). No userinfo, no fragment, URL at most 2,048 characters.
2. Host is a DNS name or a literal public IP. Reject `localhost`, single-label names, `.local`, `.internal`, `.localhost`, and the app's own hosts.
3. Resolve A and AAAA. Every resolved address must be public: deny 0.0.0.0/8, 10/8, 100.64/10, 127/8, 169.254/16 (cloud metadata), 172.16/12, 192.0.0/24, 192.0.2/24, 192.168/16, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4, ::/128, ::1, ::ffff:0:0/96 (checked as the mapped IPv4), 64:ff9b::/96, fc00::/7, fe80::/10, ff00::/8.
4. At connection time, resolve again, choose a public address, and pin it with `CURLOPT_RESOLVE` so a second lookup (DNS rebinding) cannot redirect the request. TLS still verifies the original host name.
5. Redirects are not followed. A 3xx counts as a failed attempt (`http_status`) with the hint "Redirects are not followed".
6. Connect timeout 3 s, total 10 s ("timed out (10 s)"). Response body read capped at 64 KB, then the connection is dropped (`response_too_large` only if the status line was not received).
7. Testing allowlist: `config('integrations.egress.test_allowlist')` lets CI reach the `webhook-sink` container. The service provider throws at boot if the list is non-empty when `app.env` is `production`.

The network-level egress proxy (D08) is a second, independent layer; the application check is not relied on alone.

**Webhook endpoint state.**

```text
active ──pause (manual)──▶ paused(manual) ──resume──▶ active
active ──24 h failing──▶ paused(failing) ──resume──▶ active (failing_since cleared)
active|paused ──plan downgrade──▶ paused(plan) ──upgrade──▶ previous state
any ──delete──▶ (row and history removed; audit keeps the record)
```

- Displayed health: `Paused` if paused; `Failing` if active and the last completed attempt failed; `Healthy` otherwise; "Never" when no attempt exists.
- While paused, events are not queued (mockup copy). Fan-out skips paused endpoints; nothing is backfilled on resume.
- Pausing cancels `pending` and `retrying` deliveries (`cancelled`); they can still be retried manually.
- Resume from `paused(plan)` is only possible if the plan allows it (M6 guard).

**Delivery state and retries.**

```text
pending ─▶ in_progress ─2xx─▶ succeeded
                      └─fail─▶ retrying (next_attempt_at) ─▶ in_progress … ─after 6th attempt─▶ failed
```

- Schedule after the initial attempt: +1 min, +5 min, +15 min, +40 min, +60 min, each with ±10% jitter: 5 retries over about 2 hours. Any non-2xx, timeout, connection or DNS failure is retryable. `429` honors `Retry-After` up to 60 minutes without consuming extra attempts beyond the five.
- Manual Retry (any delivery in retention, including succeeded ones) creates a new attempt with `trigger = manual`, same event ID. It does not reopen the automatic schedule.
- Replay: `ReplayWebhookDeliveries` re-sends failed or cancelled deliveries of one endpoint since a timestamp, at most 500 per call, rate limited.
- Auto-pause: a scheduled job pauses an endpoint whose `failing_since` is older than 24 hours with no success since, cancels its queued deliveries, and pushes `internal.webhook.auto_paused`.
- Per-endpoint concurrency is limited to 4 in-flight attempts (Redis funnel), so one slow receiver cannot hold all integration workers. Delivery order is not guaranteed; receivers order by `revision` and deduplicate by event ID.

**Provider connection state.**

| From | To | Trigger |
| --- | --- | --- |
| (none) | `connecting` | Connect started; state stored hashed, 15-minute expiry |
| `connecting` | `connected` | Valid callback for the same organization and initiating user, token exchange succeeds, scopes include the required set |
| `connecting` | (deleted) | User denied (flash "Slack access was not granted"), state expired, or scopes insufficient (`PERMISSION_REJECTED`) |
| `connected` | `degraded` | 3 consecutive delivery or health failures that are not auth failures, or a binding resource disappeared |
| `degraded` | `connected` | Next successful health check or delivery |
| `connected`, `degraded` | `expired` | Token refresh returns `invalid_grant` or the provider says the token expired |
| `connected`, `degraded`, `expired` | `revoked` | User disconnects (reason `USER_DISCONNECTED`, token revoked at the provider first, best effort) or the provider reports uninstall (`PROVIDER_UNINSTALLED`) |
| `expired`, `revoked` | `connecting` | Reconnect |

Deliveries to an `expired` or `revoked` connection are cancelled; `degraded` connections keep retrying on the normal schedule.

**Bindings and plans.**

- Connecting from a project's Integrations page reuses the organization's existing connection for that provider if one is `connected` (no second OAuth round trip) and only creates the binding. Disconnecting removes the project's binding; if it was the last binding, the connection is revoked at the provider.
- Free plan: `Slack` allowed, other providers `FEATURE_NOT_IN_PLAN`; at most 1 `active` webhook endpoint per organization (`PLAN_LIMIT`). The count and the insert run in one transaction with the organization's entitlements row locked (M6 `EnforcePlanLimit`), so two concurrent creates cannot both pass.
- On downgrade (`internal.entitlements.changed` from M6): all non-Slack bindings become `paused(plan)`; webhook endpoints beyond the first become `paused(plan)`, keeping the oldest active endpoint running. On upgrade, everything paused by plan returns to its previous state.
- Code references are Pro: scans stop on Free; the last results stay visible with their scan date (billing doc §4).

**Provider settings validation** (`Domain\Providers\{Provider}Settings` value objects):

| Provider | Settings |
| --- | --- |
| Slack | `channel_id`, `events`: `publishes` or `publishes_and_reviews` |
| GitHub | `repository_id`, `branch` (default branch if empty), `exclude_paths` (globs, max 20) |
| Datadog | `site` in {`datadoghq.com`, `us3.datadoghq.com`, `us5.datadoghq.com`, `datadoghq.eu`, `ap1.datadoghq.com`}, `send`: `publishes_as_events` |
| Jira | `jira_project_key`, `post_status_on_publish` bool |
| Vercel | `vercel_project_id`, `preview_environment_id` (development or staging; never an environment marked protected unless confirmed), `production_environment_id` null (explicit opt-in), `include_browser_key` bool |
| Sentry | `sentry_org_slug`, `sentry_project_slug`, `allowed_flag_keys` (max 50; only these may ever be attached) |

**MCP policy.** Management API requests authenticated with a personal access token whose audience is `mcp` (M3's `credentials.audience`) pass through `EnforceMcpPolicy`: deny everything if `enabled` is false; deny draft writes unless `allow_draft_write`; deny publish unless `allow_publish_non_production` and the environment is not protected or named production; deny credential actions unless `allow_key_management`. The token's user's own capabilities still apply first, so the policy can only narrow.

### 3.3 Actions

All actions live in `app/Modules/Integrations/Actions`, take `Actor`, check capabilities through policies, and write audit and outbox records in the same transaction.

| Action | Input | Capability and gates | Transaction contents | Audit / outbox |
| --- | --- | --- | --- | --- |
| `CreateWebhookEndpoint` | project, name, url, events[], environmentIds[] | `integrations.manage`; M6 `EnforcePlanLimit::handle(org, LimitKey::Webhooks)` with entitlements row lock | Validate destination (DNS), generate secret, insert endpoint + environments | `webhook.created` (no secret) / none. Returns plaintext secret once in `CreateWebhookEndpointResult` |
| `UpdateWebhookEndpoint` | endpoint, expectedVersion, name, url, events, environmentIds | `integrations.manage` | Revalidate URL if changed | `webhook.updated` with changed fields |
| `PauseWebhookEndpoint` / `ResumeWebhookEndpoint` | endpoint, expectedVersion | `integrations.manage`; resume checks plan | Status change, cancel queued deliveries on pause | `webhook.paused` / `webhook.resumed` |
| `DeleteWebhookEndpoint` | endpoint, expectedVersion | `integrations.manage` | Delete endpoint and environments; dispatch `PurgeWebhookHistory` after commit | `webhook.deleted` (name, host) |
| `RotateWebhookSecret` | endpoint, expectedVersion | `integrations.manage` | Move current to previous with 24 h expiry, new secret | `webhook.secret_rotated`; result returns new plaintext once |
| `SendWebhookTestEvent` | endpoint | `integrations.manage`; rate limit 10/min | Insert `ping` delivery | `webhook.test_sent` |
| `RetryWebhookDelivery` | delivery | `integrations.manage` | Mark delivery `pending`, enqueue manual attempt | `webhook.delivery_retried` |
| `ReplayWebhookDeliveries` | endpoint, since | `integrations.manage` (also callable by support, M7) | Reset up to 500 failed/cancelled deliveries | `webhook.deliveries_replayed` with count |
| `StartProviderConnection` | project, provider, settings | `integrations.manage`; `Entitlements::assertAllows(org, Feature::integration($provider))` | Insert `connecting` row with hashed state and context, or reuse connected row | `integration.connect_started` |
| `CompleteProviderConnection` | provider, state, code (or installation ID) | Recheck initiating user still has `integrations.manage` | Exchange code (outside transaction), then in transaction: store encrypted credentials, `connected`, create binding | `integration.connected` / `internal.integration.connected` |
| `ConnectTokenProvider` | project, provider (`datadog`), apiKey, appKey, settings | Same as start | Verify key against the provider first, then store and bind | `integration.connected` |
| `ConfigureProviderBinding` | binding, expectedVersion, settings | `integrations.manage` | Validate settings, update | `integration.configured` |
| `DisconnectProvider` | project, provider | `integrations.manage` | Delete binding; if last, revoke at provider after commit (job) and mark `revoked` | `integration.disconnected` |
| `RecordProviderHealth` | connection, outcome | System actor | State transition per §3.2 | `integration.state_changed` (system) / `internal.integration.degraded` when entering `degraded`, `expired` or `revoked` |
| `ApplyPlanToIntegrations` | organization, entitlements | System actor | Pause or restore bindings and endpoints per §3.2 | `integration.paused_by_plan` / `integration.resumed` per item; `internal.integrations.plan_paused` (M8 email list) |
| `RequestCodeReferenceScan` | binding, trigger | `integrations.manage` for manual; system for push/schedule; Pro | Insert `queued` scan unless one is queued or running for the same commit | `code_scan.requested` (manual only) |
| `RecordCodeReferenceScan` | scan, results | System actor | Replace references for that scan, mark finished | none (high volume) |
| `LinkFlagToIssue` / `UnlinkFlagIssue` | flag, issueKey | `flags.draft.write`; Jira binding active | Verify issue exists via adapter before insert | `flag.issue_linked` / `flag.issue_unlinked` |
| `ProvisionVercelKeys` | binding | System actor after connect or configure | Calls M3 `IssueCredential` (label "Vercel preview (managed)"), pushes plaintext to Vercel in the same job, never stores it | `integration.keys_provisioned` |
| `UpdateMcpPolicy` | project, expectedVersion, flags | `integrations.manage` | Update policy; turning off also calls M3 `RevokeCredential` for every `mcp` token in the project | `mcp.policy_updated` |
| `RevokeAssistantConnection` | token | `integrations.manage` or the token's own user | M3 `RevokeCredential` | `mcp.assistant_revoked` |

`Actor` for provider-initiated work is `Actor::system('integrations')`. Interactive Slack approval (if D11 approves it) calls M5's `DecideReview` with the linked user as actor, never with a system actor.

### 3.4 Routes

**Portal (`routes/web.php`, session + CSRF, under M0's prefix `/{organization}/{project}/{environment}`, written here as `…`)**

| Method and path | Controller | Inertia page / response |
| --- | --- | --- |
| `GET …/integrations?tab=webhooks\|apps\|assistants` | `Web\IntegrationsController@index` | `integrations/Index` |
| `POST …/webhooks` | `Web\WebhookController@store` | Redirect to show with one-time `flash.revealedSecret` |
| `GET …/webhooks/{webhook}` | `@show` | `integrations/WebhookShow` |
| `PATCH`, `DELETE …/webhooks/{webhook}` | `@update`, `@destroy` | Redirect |
| `POST …/webhooks/{webhook}/pause\|resume\|rotate-secret\|test` | `WebhookCommandController` | Redirect with toast or one-time secret |
| `POST …/webhooks/{webhook}/deliveries/{delivery}/retry` | `WebhookDeliveryController@retry` | Redirect |
| `POST …/integrations/{provider}/connect` | `ProviderConnectionController@start` | External redirect (Inertia `location`) or redirect back when reused |
| `GET /integrations/oauth/{provider}/callback` | `OAuthCallbackController` | Redirect to the initiating project's Apps tab with toast |
| `PATCH`, `DELETE …/integrations/{provider}` | `ProviderBindingController` | Redirect |
| `POST …/integrations/github/scan` | `CodeReferenceController@scan` | Redirect |
| `PATCH …/integrations/mcp` | `McpPolicyController@update` | Redirect |
| `DELETE …/integrations/mcp/assistants/{credential}` | `McpPolicyController@revoke` | Redirect |

`integrations/Index` props:

```ts
type IntegrationsIndexProps = {
  project: ProjectRef; tab: 'webhooks' | 'apps' | 'assistants';
  can: { manage: boolean };
  plan: { webhooksUsed: number; webhooksLimit: number | null; paidProvidersAllowed: boolean; upgradeUrl: string | null };
  webhooks: Array<{ id: string; name: string; url: string; events: WebhookEventType[]; environments: EnvRef[];
    health: 'healthy' | 'failing' | 'paused'; pausedReason: 'manual' | 'failing' | 'plan' | null;
    lastDelivery: { at: string; statusCode: number | null } | null }>;
  apps: Array<{ provider: Provider; state: 'not_connected' | ConnectionState; pausedByPlan: boolean;
    resourceName: string | null; settingsSummary: string; lastError: string | null; lastHealthyAt: string | null }>;
  assistants: { policy: McpPolicy; connections: Array<{ credentialId: string; client: string; person: string; role: string; lastUsedAt: string | null }> };
};
```

`integrations/WebhookShow` adds the secret tail, failure banner data (`consecutiveFailures`, `failingSince`, `willAutoPauseAt`), and a cursor-paginated `attempts` list (25 per page) with `responseExcerpt` only when `can.manage`.

**Management API (`routes/api.php`, `/api/v1`, scopes proposed for D14: `webhooks:read`, `webhooks:write`, `integrations:read`)**

```text
GET    /projects/{project}/webhooks
POST   /projects/{project}/webhooks                          201, secret in body once
GET    /projects/{project}/webhooks/{webhook}
PATCH  /projects/{project}/webhooks/{webhook}                 expectedVersion required
DELETE /projects/{project}/webhooks/{webhook}
POST   /projects/{project}/webhooks/{webhook}/rotate-secret   Idempotency-Key required
POST   /projects/{project}/webhooks/{webhook}/test
GET    /projects/{project}/webhooks/{webhook}/deliveries      cursor pagination, status filter
POST   /projects/{project}/webhooks/{webhook}/deliveries/{delivery}/retry
POST   /projects/{project}/webhooks/{webhook}/replay          { "since": "…" }
GET    /projects/{project}/integrations
GET    /projects/{project}/flags/{flag}/code-references
```

Provider connect, configure and disconnect stay portal-only (OAuth needs a browser session).

**Inbound provider endpoints (`routes/api.php`, no session, signature middleware per provider)**

```text
POST /integrations/inbound/github      X-Hub-Signature-256 (GitHub App webhook secret)
POST /integrations/inbound/slack/events     X-Slack-Signature + 5-minute timestamp window
POST /integrations/inbound/slack/interactivity   (D11 gated)
POST /integrations/inbound/vercel      x-vercel-signature
POST /integrations/inbound/jira        Atlassian JWT / webhook secret
POST /integrations/inbound/sentry      Sentry-Hook-Signature
```

Each handler: verify signature on raw bytes, reject stale timestamps, insert `provider_inbound_events` (unique ID, duplicate returns 200 without work), resolve the connection by external account ID, ignore events older than the last applied `provider_event_at` for the same resource, then enqueue processing. Responds within 3 seconds.

Error codes added: `WEBHOOK_URL_INVALID` (422, field `url`), `WEBHOOK_DESTINATION_FORBIDDEN` (422, "resolves to a private or reserved address"), `PROVIDER_NOT_CONNECTED` (409), `PROVIDER_STATE_INVALID` (409), `OAUTH_STATE_INVALID` (400), `INTEGRATION_PAUSED_BY_PLAN` (403), plus M6's `PLAN_LIMIT` and `FEATURE_NOT_IN_PLAN`.

### 3.5 Frontend

| Path | Contents |
| --- | --- |
| `resources/js/pages/integrations/Index.vue` | Tabs (1a), read-only banner when `!can.manage` (2g), plan notice ("Webhooks 1 / 1 on Free"), empty state (2f) |
| `resources/js/pages/integrations/WebhookShow.vue` | Header actions, failure banner, sends/environments card, signing secret card, deliveries table (2a) |
| `features/integrations/WebhookTable.vue` | Row status tag with text, not color only |
| `features/integrations/WebhookFormDialog.vue` | Create and edit (2b); client-side URL hint mirrors server rules but the server decides |
| `features/integrations/SecretRevealDialog.vue` | One-time secret (2c); non-dismissable until acknowledged; secret cleared from component state on close |
| `features/integrations/DeliveryAttemptsTable.vue` | Cursor pagination, Retry button, `timed out (10 s)` rendering, response excerpt disclosure |
| `features/integrations/AppCard.vue`, `ConnectAppDialog.vue`, `ConfigureAppDialog.vue` | Provider cards with state tags (Connected, Connecting, Needs attention, Reconnect required, Paused on Free), settings fields loaded from `GET …/integrations/{provider}/options` (channels, repos, Jira projects) |
| `features/integrations/McpPanel.vue` | Toggle with confirm, permission switches (`role="switch"`), client snippet tabs with copy, assistants list (2d) |
| `features/integrations/CodeReferencesPanel.vue` | Used by M4's flag detail: "3 references in acme/storefront@main · scanned 2 h ago", links to blob URLs, "No references found is not proof the flag is unused" |
| `features/integrations/JiraIssuesPanel.vue` | Flag detail panel: linked issues, missing state |
| `composables/useWebhookForm.ts` | Form state, URL hint, dirty tracking |
| `composables/useOneTimeSecret.ts` | Copy, acknowledgment, wipe on unmount |

`resources/js/types/integrations.ts` holds the prop types above. All controls use the shared `components/ui` primitives; disabled paid controls show the plan reason inline (billing doc §3).

### 3.6 Jobs, events, outbox consumers

| Item | Queue | Behavior |
| --- | --- | --- |
| `FanOutWebhookEvent` (outbox consumer for the seven R02 types) | `integrations` | Find `active` endpoints in the message's project subscribed to the type whose environment set contains the message's environment; insert deliveries (unique per endpoint and event); dispatch `DeliverWebhook` per delivery after commit. Idempotent through the unique index |
| `FanOutProviderEvent` (same messages) | `integrations` | For each active binding whose settings want the event (Slack publishes or reviews, Datadog publishes, Jira publishes touching linked flags), insert `provider_deliveries` |
| `DeliverWebhook` | `integrations` | Load delivery and endpoint fresh; skip if paused or deleted; sign; send through `SafeHttpClient`; record attempt; schedule retry with `release()` delay or mark failed; update endpoint `failing_since`, `last_*` |
| `DeliverProviderEvent` | `integrations` | Call the adapter (`ProviderAdapter::deliver`), classify errors (`transient`, `rate_limited`, `auth`, `permission`, `resource_missing`), refresh tokens once on `auth`, then retry or transition via `RecordProviderHealth` |
| `CheckProviderConnectionHealth` (scheduled hourly per connection, staggered) | `integrations` | Adapter `healthCheck()`; refresh tokens 10 minutes before expiry |
| `AutoPauseFailingWebhooks` (every 5 min) | `integrations` | §3.2 auto-pause |
| `ExpireWebhookPreviousSecrets` (hourly) | `default` | Clear previous ciphertext after overlap |
| `ScanCodeReferences` | `integrations-long` (separate supervisor, 1 to 2 workers, 10-minute timeout) | §6 GitHub |
| `ScheduleCodeReferenceScans` (nightly) | `default` | One scan per active GitHub binding on Pro |
| `SyncJiraIssueLinks` (on publish and nightly) | `integrations` | Update remote links, mark missing issues |
| `PurgeWebhookHistory`, `PruneIntegrationRecords` (daily) | `default` | Retention per §3.1 |
| `ApplyPlanToIntegrations` (consumer of `internal.entitlements.changed`) | `default` | §3.2 |

Outbox messages pushed by M9: `internal.webhook.auto_paused`, `internal.webhook.failing` (first time an endpoint has 3 consecutive failed deliveries; M8 in-app to project Owners and Overview attention), `internal.integration.degraded`, `internal.integrations.plan_paused`, `internal.integration.connected`.

Horizon supervisors: `integrations` (bounded `maxProcesses`, separate from `publication` and `telemetry`, see M10), `integrations-long`. Publication delivery never waits on these queues.

### 3.7 Security and tenant isolation

- Every query in `Queries/` filters by `organization_id` and `project_id` from the resolved route scope; `{webhook}`, `{delivery}` and `{provider}` resolve only inside `{project}`.
- Secrets: webhook secrets and provider credentials are encrypted at rest, decrypted only inside `DeliverWebhook`, adapters and `SafeHttpClient`; they never appear in Inertia props (except the one-time reveal), logs, exceptions (custom `__debugInfo` and `#[SensitiveParameter]`), job payloads (jobs carry IDs only) or audit details.
- OAuth: state is 32 random bytes, stored hashed, single use, 15-minute expiry, bound to organization, project and initiating user; the callback requires the same signed-in user. PKCE where the provider supports it. Redirect URIs are fixed per environment.
- Inbound webhooks: signature on raw body, timestamp window, unique event IDs, and resolution of the tenant only from the verified external account ID, never from payload fields alone.
- Response excerpts and provider error messages are rendered as text (no HTML), truncated, and hidden from Viewers and Developers.
- Outbound provider data is allowlisted per adapter (`Domain\Providers\OutboundFields`): project key, environment key, revision, flag keys, actor display name, links. No rule contents, segment lists, override keys, context attributes or key values. A Pest test serializes every adapter payload for the demo seed and asserts none of the forbidden fields appear.
- Support console (M7) sees endpoint and connection states, attempt codes and error classes only, never payloads or response excerpts.

## 4. Implementation steps

1. **Egress foundation.** `Domain/Egress/DestinationPolicy.php`, `IpRange.php`, `Infrastructure/SafeHttpClient.php` (Guzzle handler with pinning, no redirects, size cap), config `integrations.php`, boot-time guard for the test allowlist. Unit tests with a fake resolver.
2. **Webhook schema and models.** Migrations for `webhook_endpoints`, `webhook_endpoint_environments`, `webhook_deliveries`, `webhook_delivery_attempts`; models; `WebhookEventType` enum; ID prefixes `dlv_`, `att_` registered in `Shared/Ids`.
3. **Webhook actions.** Create, update, pause, resume, delete, rotate secret; `WebhookSigner`; M6 guard calls; audit events; Pest feature tests including the plan race.
4. **Fan-out and delivery.** `FanOutWebhookEvent` consumer registration, `DeliverWebhook`, retry schedule, auto-pause and secret expiry jobs, `internal.webhook.*` outbox messages. Sail `webhook-sink` service (small container that records requests) for local and CI.
5. **Test event, retry, replay.** Actions, routes, API endpoints, OpenAPI entries in `contracts/management/openapi.yaml`.
6. **Webhook UI.** `integrations/Index` (Webhooks tab), `WebhookShow`, dialogs, composables, read-only and empty states; Vitest; Cypress `m9-webhooks.cy.ts`, `m9-webhook-detail.cy.ts`.
7. **Provider framework.** Migrations for `provider_connections`, `provider_bindings`, `provider_deliveries`, `provider_inbound_events`; `ProviderAdapter` interface (`authorizationUrl`, `completeAuthorization`, `refresh`, `healthCheck`, `listResources`, `deliver`, `revoke`, `verifyInbound`); `FakeProviderAdapter` for tests; state machine `ConnectionStateMachine`; connect, callback, configure, disconnect actions and routes; health check job; Apps tab UI with all states.
8. **Plan application.** `ApplyPlanToIntegrations` consumer, paused-by-plan UI, plan-paused list for M8's email.
9. **Slack adapter.** OAuth v2 install, channel listing, message formatting (Block Kit with "Review in BitFlag" link buttons), `chat.postMessage` delivery, uninstall event handling. Cypress `m9-connect-slack.cy.ts` against the fake adapter.
10. **GitHub App adapter and code references.** App JWT and installation tokens, repo listing, push webhook, `ScanCodeReferences`, `code_reference_scans`, `code_references`, `CodeReferencesPanel` for M4's flag detail, `CodeReferenceSummary` query for M10.
11. **Datadog adapter.** API key and site form, key validation, publication events.
12. **Jira adapter.** Atlassian OAuth 3LO with rotating refresh tokens, project listing, `flag_issue_links`, remote links on publish, missing-issue handling, `JiraIssuesPanel`.
13. **Vercel adapter.** Integration OAuth, project listing, `ProvisionVercelKeys`, env var sync on key rotation (`internal.key.rotated` consumer), cleanup on disconnect with confirmation.
14. **Sentry adapter.** Integration install, project listing, allowlist setting, publication markers (§6), allowlist exposed through `GET /api/v1/projects/{project}/integrations/sentry/allowlist` for SDK hooks.
15. **MCP tab.** `mcp_policies`, `UpdateMcpPolicy`, `RevokeAssistantConnection`, `EnforceMcpPolicy` middleware on `/api/v1` routes, `McpPanel`; Cypress `m9-mcp-policy.cy.ts`.
16. **Overview and attention.** `IntegrationAttentionItems` query wired into M5's Overview "Needs attention"; demo seeder `IntegrationsDemoSeeder` (Deploy tracker healthy, Cache purge failing with 503/504 history, Security alerts paused, Slack `#releases`, GitHub `acme/storefront`).
17. **Slack interactive approvals (only after D11 confirms).** `provider_user_links`, link-account flow, interactivity endpoint calling M5 `DecideReview` with the linked user, audit channel decision.

## 5. Tests

**Pest unit (`tests/Unit/Integrations`)**

- `DestinationPolicyTest`: every denied range including IPv4-mapped IPv6 and NAT64, literal IPs, ports, userinfo, punycode hosts, trailing dots, uppercase schemes, overlong URLs.
- `SafeHttpClientTest`: DNS rebinding (first lookup public, second private: request goes to the pinned address or fails `destination_forbidden`), redirect not followed, 64 KB cap, 10 s timeout with a fake clock.
- `WebhookSignerTest`: known-answer vectors (also published as fixtures in `bitflag-protocol`), dual signature during overlap, header format.
- `RetryScheduleTest`, `ConnectionStateMachineTest` (every allowed and forbidden transition), provider `*SettingsTest`.

**Pest feature (`tests/Feature/Integrations`, real MySQL and Redis)**

- Webhook CRUD with optimistic version conflicts (`VERSION_CONFLICT`), environment filter, event filter, one-time secret never persisted in plaintext (scan the DB and logs after create and rotate).
- Plan: Free allows Slack and exactly one active endpoint; two concurrent `CreateWebhookEndpoint` calls at the limit produce one success and one `PLAN_LIMIT`; non-Slack connect returns `FEATURE_NOT_IN_PLAN`; downgrade pauses all but the oldest endpoint and every non-Slack binding; upgrade restores previous states; a paused-by-plan endpoint receives nothing.
- Fan-out idempotency: the same outbox message consumed twice creates one delivery per endpoint; crash between delivery insert and dispatch is recovered by a sweeper that follows M0's outbox durability contract (rows are the work list, jobs are signals).
- Delivery lifecycle against `webhook-sink`: success, 503 then success, six failures then `failed`, auto-pause after 24 h (time travel), pause cancels queued deliveries, resume does not backfill.
- Isolation: every route and API endpoint with another organization's and another project's IDs returns 404; deliveries of another project never listed; inbound GitHub event for an unknown installation is ignored.
- OAuth: wrong state, reused state, expired state, different signed-in user, denied consent, insufficient scopes.
- Inbound: bad signature 401, stale timestamp 401, duplicate delivery ID processed once, out-of-order push events keep the newest commit.
- Outbound field allowlist assertion for every adapter.
- MCP policy enforcement on management API with an `mcp` audience token.

**Pest arch (`tests/Arch/IntegrationsTest.php`)**: only `SafeHttpClient` may construct HTTP clients in `Integrations`; adapters do not import other modules' models; jobs have no constructor parameters typed as credentials or secrets.

**Vitest**: `WebhookFormDialog` validation hints, `SecretRevealDialog` (cannot close before acknowledgment, wipes on close), `AppCard` state rendering, `McpPanel` locked read permission.

**Cypress** (`cypress/e2e/`): `m9-webhooks.cy.ts` (empty state, create, secret reveal, list), `m9-webhook-detail.cy.ts` (test event, failing banner, retry, rotate, pause, delete), `m9-connect-slack.cy.ts` (fake provider round trip, configure, disconnect), `m9-integrations-readonly.cy.ts` (Developer view 2g), `m9-integrations-free-plan.cy.ts` (Webhooks 1 / 1, disabled paid apps with reason), `m9-mcp-policy.cy.ts`.

**Fixtures**: webhook signature vectors and envelope examples for every R02 type (shared with `bitflag-protocol`), recorded provider responses per adapter in `tests/Fixtures/providers/{provider}/`.

## 6. Decisions

| Item | Default assumed until decided |
| --- | --- |
| **D11** Slack | Slack app with OAuth v2, bot scopes `chat:write`, `channels:read` (public channel picker; private channels by inviting the bot). Messages for publishes and review requests with a "Review in BitFlag" link. In-Slack approval is not built until D11 confirms identity linking and the audit channel (step 17) |
| **D11** GitHub | GitHub App (not OAuth app) with `contents:read`, `metadata:read`, push webhook. Scan: download the tarball for the mapped branch head (cap 100 MB compressed, 50,000 files, 1 MB per file, skip binaries, `vendor/`, `node_modules/` and `exclude_paths`), match flag keys as quoted string literals, store path and line only. "0 references" always shows repo, branch, commit and scan age |
| **D11** Datadog | API key + application key and site; key verified with the validate endpoint; one event per `revision.published` tagged `bitflag_project`, `bitflag_env`, `source:bitflag` |
| **D11** Jira | Atlassian OAuth 2.0 (3LO) with `read:jira-work`, `write:jira-work`, `offline_access`; links are explicit (no automatic issue creation); on publish, update a remote link titled with the flag key and per-environment on/off state |
| **D11** Vercel | Vercel integration OAuth; sets `BITFLAG_SERVER_KEY` (and `NEXT_PUBLIC_BITFLAG_CLIENT_KEY` when browser key enabled) for the Preview target from managed keys of the mapped environment; Production target only with explicit mapping and a separate key; never copies a production key into Preview |
| **D11** Sentry | Sentry integration install with project read and release write; BitFlag posts a deploy marker per production publication; flag values on errors come from SDK hooks reading the allowlist (after the core) |
| **D11** MCP | Assistants authenticate with personal tokens of audience `mcp` issued by M3 once the OAuth client flow is decided; until then the tab shows the policy and "No assistants connected" |
| **D03** Webhook signature and envelope | Format in §3.2, published as `bitflag-protocol` fixtures; header names final only with the protocol release |
| **D09** Retention | Deliveries 30 days, inbound events 14 days, last 20 scans per binding |
| **D08** Egress | Application check as specified plus a network egress proxy chosen with hosting |
| **D10** Plan pause selection | Keep the oldest active webhook on downgrade |
| **D14** Scopes | `webhooks:read`, `webhooks:write`, `integrations:read` |

## 7. Done criteria

- A publish in Storefront production produces exactly one signed `revision.published` delivery per subscribed endpoint whose environments include production, and none for others; the receiver can verify the signature with the documented algorithm.
- The failing-endpoint scenario in 2a reproduces end to end: retries at the documented schedule, Failing status, auto-pause after 24 hours, an in-app notice to Owners through M8.
- SSRF suite passes, including DNS rebinding and IPv6-mapped addresses; no request ever reaches a private address in CI.
- All six providers connect, show each state (`connecting`, `connected`, `degraded`, `expired`, `revoked`), and recover through reconnect, against fakes in CI and against each provider's sandbox or test workspace in a manual acceptance run recorded with screenshots.
- Free plan gates work in portal and API with the documented error codes; downgrade and upgrade pause and resume without losing configuration.
- No secret, token or provider credential appears in props, logs, job payloads or audit (automated scan).
- Cypress M9 specs pass in CI.

## 8. Risks and open questions

- **Scope of integrations: project or organization.** The mockup puts Integrations under the project; the domain model puts provider connections on the organization; billing counts webhooks per organization. This plan uses organization connections with project bindings and project webhooks, counted per organization. Shei should confirm.
- **Event list in the mockup.** 2b lists five events; R02 has seven. The form adds the experiment events.
- **Failure email.** 2a says "we email the owner" after auto-pause, but R10 and the email doc make failing webhooks an in-app notification. Default: in-app only; change the copy or R10.
- **Read-only role in 2g.** The mockup shows Jon Reyes as a Developer; the seed and conventions make Jon a Publisher. Cypress uses Priya Nair (Developer) for 2g.
- **MCP placement.** 2d shows a hosted MCP URL (`https://bitflag.acme.dev/mcp`) and OAuth sign-in, while the repository plan has a separate `bitflag-mcp` package after the core. Which one ships, and who hosts the remote endpoint, is D11.
- **`flag.changed` volume.** It fires on every draft save; busy projects may flood receivers. Option: coalesce saves per flag per 30 seconds. Not done by default because R02 says "any draft edit".
- **Ports.** 443 and 8443 allowed by default; other ports may be needed by some customers.
- **Slack "Approve reviews from Slack".** Copy promises in-Slack approval; until D11 decides identity linking, the button opens the portal review. The copy should say so or step 17 must land before release.
- **Provider APIs change.** Each adapter has recorded fixtures and a manual sandbox run; a provider rejecting our app registration (for example marketplace review) is a release blocker to track early.
- **Delete removes history** (mockup copy) while the conventions avoid deleting domain records. This plan hard-deletes the endpoint and its deliveries and keeps the audit entry; confirm.
