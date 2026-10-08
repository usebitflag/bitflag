# APIs, integrations, CLI, and MCP

[Documentation index](README.md) · Status: proposed contract boundaries; paths below are design examples, not available endpoints

## 1. Three API surfaces

| Surface | Consumers | Authentication and purpose |
| --- | --- | --- |
| Portal | Inertia/Vue application | Session + CSRF; page data and user commands |
| Management API | CLI, MCP, automation, integrations | Scoped management credentials; resource administration and publication |
| Delivery API | SDKs and customer applications | Environment/audience-scoped credentials; snapshots, invalidations, remote evaluation, telemetry |

Inertia is the portal integration layer, not the SDK protocol. Do not force SDKs to consume page props or make the portal call a separately deployed frontend API solely for architectural symmetry. Both controller types call the same application actions.

Management OpenAPI is owned by the `bitflag` repository, proposed at `contracts/management/openapi.yaml`. Delivery, evaluation, telemetry, and webhook schemas and conformance fixtures belong to the independent `bitflag-protocol` repository. Published versions are pinned; no duplicated hand-maintained source of truth.

## 2. Management resources

Illustrative `/api/v1` routes:

```text
GET    /organizations/{organization}/projects
POST   /organizations/{organization}/projects
GET    /projects/{project}/environments
GET    /projects/{project}/flags
POST   /projects/{project}/flags
PATCH  /projects/{project}/environments/{environment}/flags/{flag}/draft
POST   /projects/{project}/environments/{environment}/simulate
GET    /projects/{project}/environments/{environment}/changes
POST   /projects/{project}/environments/{environment}/change-sets
POST   /change-sets/{changeSet}/approvals
POST   /change-sets/{changeSet}/publish
POST   /projects/{project}/environments/{environment}/rollbacks
GET    /projects/{project}/environments/{environment}/revisions
GET    /organizations/{organization}/audit-events
```

Segments, overrides, experiments, members, invitations, roles, credentials, webhooks, integration connections, and billing also need explicit resource contracts. The list above illustrates boundaries; it is not a reduced feature list or a complete OpenAPI specification. Resolve every child ID within the authorized parent scope, including globally addressed change sets.

Use cursor pagination for growing histories with a deterministic tie-breaker. Whitelist filter/sort fields and bound page size. Store timestamps in UTC and return explicit ISO 8601 offsets. Public IDs remain stable; user-visible slugs and names may change.

### Concurrency and errors

The proposed command convention is an explicit `expectedVersion` (and `expectedPublishedRevision` when required) in the request. Reject stale state with `409 Conflict`. Do not mix several concurrency conventions without defining which endpoint uses which. Delivery cache ETags are a separate mechanism.

Use `401` for invalid/missing authentication, `403` for denied authorized operations, `404` where resource existence should not be disclosed, `409` for conflicts, `422` for validated input errors, and `429` for rate limits with retry guidance. Infrastructure failures remain appropriate `5xx` responses.

```json
{
  "error": {
    "code": "VERSION_CONFLICT",
    "message": "The draft changed after you opened it.",
    "request_id": "req_example",
    "details": { "expectedVersion": 17, "currentVersion": 18 }
  }
}
```

Field validation errors need machine-readable field paths. Do not return stack traces, raw SQL, secret values, or another tenant's current versions. SDK fallback values do not convert HTTP authentication failures into successful API responses.

### Idempotency

Publication, rollback, credential-changing operations where appropriate, and external effects need explicit retry semantics. Scope an idempotency key to organization, actor, and operation; bind it to a canonical request fingerprint. Reserve it atomically with the operation. The same key and payload returns the committed result; a different payload returns a conflict. Concurrent in-progress requests have a documented response/retry policy.

Reauthenticate and authorize retries before disclosing a stored result. Retention of idempotency records, handling one-time secret responses, and exact endpoint coverage are **D14** decisions. Do not retain plaintext generated credentials merely to replay a response conveniently.

## 3. Delivery resources

Proposed route families, finalized in the protocol repository:

```text
GET  /sdk/v1/snapshot
GET  /sdk/v1/stream
POST /sdk/v1/evaluate
POST /sdk/v1/evaluate-batch
POST /sdk/v1/events
```

The credential determines the authorized environment and audience. An untrusted request parameter cannot switch a key to production. Snapshot fetching supports conditional requests; streams announce changes and clients refetch authorized snapshots. Remote evaluation requires bounded context and request size and must not disclose private rule traces to browser clients.

Telemetry batches include stable event IDs, event type, timestamp, SDK metadata, relevant revision/allocation IDs, and the minimum approved subject information. Enforce payload limits, rate limits, deduplication, and explicit partial-batch acknowledgement semantics. Evaluation counts, experiment assignments, exposures, and conversions are separate event types. Browser events are untrusted input; evaluate abuse and measurement integrity independently from transport authentication.

See [evaluation and SDK protocol](evaluation-and-sdk-protocol.md) for deterministic evaluation, caching, browser projections, and failure behavior.

## 4. Outgoing webhooks

Use a versioned event envelope with a stable event ID, event type, occurrence time, tenant/resource references, and minimal payload. A delivery attempt has its own ID so retries are observable without changing the underlying event identity.

Event types (decision register R02):

| Event | Sent when |
| --- | --- |
| `revision.published` | A new revision goes live in an environment, including rollbacks |
| `review.requested` | Someone asks for approval of a change set |
| `review.decided` | A change set is approved or rejected |
| `flag.changed` | Any draft edit to a flag |
| `key.revoked` | An SDK key is revoked |
| `experiment.started` | An experiment starts running |
| `experiment.stopped` | An experiment is stopped and its decision recorded |

An endpoint subscribes to event types and, as in the mockups, may be limited to selected environments. Payload schemas are versioned in `bitflag-protocol`.

Sign the exact request bytes with a versioned HMAC construction and timestamp. Document verification, key rotation, replay window, and duplicate handling. A receiver must deduplicate by event ID; a signature by itself does not prevent replay. Exact headers and canonical signing rules belong to `bitflag-protocol` (**D03**).

Provide destination verification, a test event, delivery history, response status, retry count, next attempt, secret rotation, disable controls, and explicit retry/replay actions. Redact payloads and response bodies according to policy. Enforce timeouts, response-size limits, egress restrictions, and bounded exponential backoff. Persist delivery intent before acknowledging the originating operation.

## 5. Product integrations

All six integrations shown in the mockups remain in the release scope. These are proposed concrete contracts to validate against the final screens and provider capabilities, not claims that provider apps already exist.

| Integration | Intended responsibility | Required safety/UX detail |
| --- | --- | --- |
| Slack | Change, review, publication, and incident notifications | Channel mapping, delivery status, reconnect, minimal OAuth scopes; any interactive approval must map to an authenticated authorized BitFlag actor |
| GitHub | Repository connection and flag usage references | Repository/branch selection, commit and scan coverage, stale-scan indication; no-reference result is not proof that deletion is safe |
| Datadog | Publication/configuration annotations and operational context | Region/site configuration, credential verification, retry status, outbound data allowlist |
| Jira | Link change sets or flags to work items and attach approved summaries | Project permissions, stable links, missing/deleted issue behavior, no automatic permission bypass |
| Vercel | Associate deployments/previews with the intended environment | Explicit preview mapping and lifecycle, no accidental production key injection, server/browser credential separation |
| Sentry | Attach approved flag/revision context to errors | Allowlisted values, payload limits, no sensitive targeting rules or raw user context |

Provider authorization, exact write permissions, app registration/approval, and any provider-plan prerequisites must be verified during implementation (**D11**). Avoid promising capabilities from a “Connected” mockup badge alone. Include disconnected, connecting, permission-rejected, expired, degraded, retrying, and revoked states.

Inbound provider webhooks require signature verification, replay protection, idempotency, and out-of-order handling. OAuth callbacks validate state and bind the connection to the initiating organization and authorized actor. Tokens are encrypted and never returned as ordinary portal props.

## 6. CLI

The separate `bitflag-cli` repository provides scriptable resource inspection, flag/segment changes, simulation, change-set review, publication, rollback, and environment/key administration within the same policies as the portal.

Support human-readable output and stable JSON output, meaningful exit codes, explicit organization/project/environment context, noninteractive authentication, and confirmation for sensitive interactive commands. Production writes should print the target environment and intended diff. A `--yes` flag skips a local confirmation, not server approval requirements.

Keep secrets out of command arguments where possible; use a secure credential store or documented environment input. Dry-run performs validation/simulation without committing state. CI credentials must be narrowly scoped and revocable. No direct database access.

## 7. MCP server

The separate `bitflag-mcp` repository exposes bounded management tools through the management API. Separate read, simulate, draft-write, approve, publish, and rollback capabilities. Tools must not grant themselves credentials, elevate roles, or bypass environment policy.

Return structured results including target scope, versions, proposed diff, and resulting publication revision. Require explicit parameters for production targets and use expected versions/idempotency for mutations. Treat flag descriptions, integration messages, and other tool-returned content as untrusted data, not instructions to execute additional actions.

Authentication flow, transport support, credential storage, and client confirmation boundaries are **D11** decisions. MCP server configuration does not replace server-side authorization.

## 8. Billing and entitlements

Billing stays inside the hosted product boundary, with a separate provider adapter. Verify provider callbacks, deduplicate events, handle reordering, and reconcile state against the provider when needed. Never trust a browser return URL as proof of successful payment.

Model plan limits and entitlements centrally. Count SDK requests, events, seats, environments, and other metered units according to a documented definition, not whichever counter is easiest to implement. Trial, cancellation, failed payment, grace, overage, upgrades, and downgrades need UI and server behavior.

Proposed safety principle: billing transitions should not silently flip already published flags or abruptly make production applications fail. Exact commercial limits, grace behavior, and enforcement points are **D10** decisions, not finalized pricing.
