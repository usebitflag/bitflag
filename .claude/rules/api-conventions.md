# API Conventions

## Identifiers

- Primary keys are ULIDs (`HasUlids`, `char(26)`). Public IDs add a prefix and are derived, never stored:
  `org_`, `usr_`, `prj_`, `env_`, `flg_`, `var_`, `seg_`, `chg_`, `rev_`, `key_`, `exp_`, `whk_`, `evt_`,
  plus `aud_` and `req_`. New prefixes are added to `docs/conventions.md`.
- Use `PublicId::encode` / `decode` and the `HasPublicId` trait. A wrong prefix or malformed ID is a 404.
- Slugs and keys are for URLs and display. Authorization never relies on them.
- Secret prefixes (`bf_srv_`, `bf_pub_`, `bf_mgt_`, `bf_pat_`, `whsec_`) are not IDs.

## Errors

- API and SDK routes return `{ "error": { "code", "message", "request_id", "details" } }`. Codes are upper
  snake case. Every response has an `X-Request-Id` header equal to `request_id`.
- Mapping: 401 `UNAUTHENTICATED`, 403 `FORBIDDEN`, 404 `NOT_FOUND`, 419 `SESSION_EXPIRED`,
  422 `VALIDATION_FAILED` (`details.fields[]`), 409 `VERSION_CONFLICT`, 409 `IDEMPOTENCY_KEY_REUSED` /
  `IDEMPOTENCY_IN_PROGRESS`, 429 `RATE_LIMITED`, 500 `INTERNAL_ERROR` (no details).
- Throw `ApplicationException` subclasses; do not format error responses by hand.
- Never expose stack traces, SQL or another tenant's data outside local debug mode.

## Concurrency and idempotency

- Mutable records carry an integer `version` (or `draft_version`). Update with `VersionedUpdate::apply`;
  a mismatch throws `VersionConflict`.
- Retryable operations use `Idempotency::run`. Stored responses never contain one-time secrets.

## Capabilities

- The capability list and role map are in `docs/conventions.md`. A change that adds a capability
  is documented in `docs/conventions.md` and updates `RoleCapabilityMap` and its test.
- Inertia pages receive a `can` map for the current scope. The server always rechecks.

## Routes

- Portal URLs: `/{organization:slug}/{project:key}/{environment:key}/{page}`, resolved by `ResolvePortalScope`.
  Non-members get 404.
- Routes live in `routes/web/{module}.php`, `routes/api.php` (`/api/v1`), `routes/sdk.php` (`/sdk/v1`, no session
  or CSRF) and `routes/admin.php`. `routes/testing.php` loads only locally with the testing token.

## Secrets

- Credentials are shown once, stored hashed, and never logged, audited, serialized into jobs or put in props.
- `Redactor` drops keys matching `password|secret|token|plaintext|key_value|context|attributes|authorization`
  from audit details. Do not rely on it as the only protection.
