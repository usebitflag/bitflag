# M2. Accounts, organizations and access

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Expands M2 in the [implementation plan](../implementation-plan.md).

## 1. Goal and scope

People can create an account, prove their email, sign in safely (password, TOTP two-factor, recovery codes), recover access without the product revealing who has an account, and manage their sessions. A new person goes through the hosted onboarding wizard and ends with an organization, a first project with environments, and pending invitations. Organizations have org roles (R07), projects have project roles (R01), and every policy checks capabilities per environment.

**In scope**

- Fortify-based sign-up, email verification (with the "locked until verified" rule from Auth 5b), sign-in, TOTP two-factor with 10 recovery codes, trusted browser (30 days), password rules, enumeration-safe reset, lockout, sessions list and revocation, idle and absolute session timeout, session-expired re-authentication.
- Hosted onboarding wizard Auth 5a to 5g: account, organization, plan (Free default, Pro hands off to M6), first project from the environment template, invite team, ready screen.
- Organizations, org memberships (Org Owner, Member), organization switcher and "choose organization" (Auth 3e).
- Invitations: create, queue until the inviter is verified, resend, revoke, expire after 7 days, accept as a new user or an existing user, wrong-account handling, allowed email domains.
- Project memberships with the four R01 roles, last-Owner protection, Org Owner self-add.
- Org capabilities added to M0's capability resolver and role maps, Laravel policies, the Inertia `can` map, and an interim `EnvironmentProtection` binding that M3 replaces.
- Organization settings: General, Members, Security (Organization 1a to 1c). Project Settings → Members tab (Settings 2a to 2c).
- Account settings: profile, email change, password, two-factor, trusted browsers, sessions (no mockup exists; see §8).
- Security log (`security_events`) for sign-in events that are not customer audit entries.

**Out of scope (owned elsewhere)**

| Item | Where |
| --- | --- |
| Project settings, environment settings and policies UI, credentials, project lifecycle, organization deletion | M3 |
| Overview onboarding checklist that verifies a real evaluation | M5 |
| Billing pages (Organization 2a to 3f), the onboarding plan step page (Auth 5d) and its Checkout hand-off, billing email field on General | M6 |
| Support actions: clear lockout, resend verification, two-person MFA reset | M7 |
| Email templates, provider, bounce handling, in-app notifications and preferences | M8 |
| Social sign-in (GitHub, Google), SSO, SCIM, WebAuthn | Not in release (R11); WebAuthn is a follow-up |
| Self-hosted Auth screens (1a "this instance" context, 4a setup wizard, "Self-hosted setup" link) | Superseded (R06, A03) |

**Builds on M0's tenancy spine.** M0 creates `users`, `sessions`, `organizations`, `projects`, `environments`, `organization_memberships` and `project_memberships` with identity columns, the `Capability` enum, `CapabilityResolver`, `Actor` and the portal URL scheme. M2 adds columns through its own migrations and writes every action for them. Onboarding needs a protected production environment, so M2 also lands the `environment_policies` migration (columns in M3 §3.1) and the first `CreateProject` with the environment template. M3 owns every later change to projects and environments and all settings, lifecycle and credential work. Plan limits go through M6's `EnforcePlanLimit` and `Entitlements` contracts in `app/Shared/Plans`, bound to permissive null implementations until M6 lands.

## 2. Mockups covered

| Mockup | Artboard | Behavior implied |
| --- | --- | --- |
| BitFlag Auth | 5a Create account | Work email + password; live checks "At least 12 characters", "Not a common password", "Not your email". Existing email: same next screen, email 1b sent instead. GitHub/Google buttons and "Self-hosted setup" link are **not built** (R11, R06) |
| | 5b Check your email | Link expires in 24 hours. Setup continues before verification; inviting people, creating keys and publishing unlock after confirmation. Resend, use a different email |
| | 5c Organization | Name, URL slug with live "Available", optional team size (Just me, 2–10, 11–50, 50+). Stepper: Account, Organization, Project, Team |
| | 5d Plan | Free ($0) default "Continue with Free"; Pro opens Stripe Checkout. Page built by M6 inside this wizard; M2 provides the step slot and resume logic |
| | 5e First project | Project name, key ("Used by SDKs and the CLI"), environment chips development/staging/production editable before creation; "Production starts protected: publishing needs a reason" |
| | 5f Invite team | Comma-separated emails, one project role (default Developer), "Invitations are sent after you confirm your email", Skip for now |
| | 5g Ready | Four steps (create flag, publish to development, connect SDK, verify) and "Go to Overview" |
| | 1a, 1b Sign in, invalid | Generic "That email and password don't match. Check them and try again." Forgot password link. Instance context panel dropped (R06) |
| | 1c Two-factor code | 6-digit code, "Trust this browser for 30 days", "Use a recovery code instead", "Signing in as" |
| | 2a, 2b, 2c Reset | Same "If an account exists" response; link works 60 minutes; resend countdown; new password with the same three checks; "You'll be signed out of other sessions" |
| | 3a Accept invitation | Email, name, password; "Already have an account? Sign in to accept"; context panel: inviter, role, projects, expiry |
| | 3b Expired or revoked | "Invitations to Acme last 7 days. Ask {inviter} or another owner to send a new one." |
| | 3c Access removed | Deactivated account screen with Sign out (copy reworded for hosted) |
| | 3d Session expired | Dialog over the dashboard; password for the same email; "Use another account"; unsaved draft kept in the browser |
| | 3e Choose organization | Orgs with role and project count; "Remember my choice" |
| portal/Auth | signin, code, signup, org, project | Same rules as above; the prototype skips plan and team steps, the canvas 5a to 5g wins |
| BitFlag Organization | 1a General | Name, URL slug ("changing it breaks old links"), billing email (M6), Projects table (environments, flags, members) |
| | 1b Members | Org role cards, table: person, org role, project access, two-factor, last active; Invite people. Billing Admin card and row **not built** (R07) |
| | 1c Security | Require two-factor (with count of members without it), sign out after inactivity, allowed email domains, SSO card disabled with reason, "Your sessions" |
| BitFlag Settings | 2a Members | Role cards with counts, member table (role select, two-factor, last active), pending invites with Resend and Cancel invite, "Links expire after 7 days" |
| | 2b Invite people | Emails textarea with live count and validation ("already a member or invited"), role select with help text |
| | 2c Developer read-only | Banner "You're a Developer. You can view this, but only owners can change it." |
| BitFlag Emails | 1a to 1i, 2a | Emails this milestone triggers (§3.6); designed and delivered in M8 |

## 3. Design

### 3.1 Data model

All IDs are ULIDs. Tables without `organization_id` are user-owned, not tenant-owned, and are listed in the tenant-scope allowlist test with a reason.

**`users`** (Identity). M0 creates it with Laravel's columns, `timezone`, `status` and `last_organization_id`, `last_project_id`, `last_environment_id` (no Fortify `two_factor_*` columns; two-factor lives in `mfa_factors`, §8 item 9). M2 adds the columns below that M0 lacks.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | `usr_` public prefix |
| `name` | varchar(120) | |
| `email` | varchar(254) | unique; stored lowercased; compared case-insensitively |
| `email_verified_at` | timestamp(6) null | |
| `pending_email` | varchar(254) null | Email change waiting for confirmation (Emails 1e) |
| `password` | varchar(255) | Laravel `Hash` (argon2id) |
| `password_changed_at` | timestamp(6) | |
| `status` | enum(`active`,`deactivated`) | |
| `timezone` | varchar(64) | IANA, default `UTC` |
| `last_organization_id` | ulid null | From M0; "Remember my choice" and last used |
| `remember_org_choice` | bool | |
| `last_active_at` | timestamp(6) null | Updated at most every 5 minutes (Members "Last active") |
| `deleted_display_name` | varchar(120) null | Kept for audit attribution after account deletion |
| `created_at`, `updated_at` | timestamp(6) | |

No `remember_token`: there is no "remember me" (sessions already last up to 30 days).

**`mfa_factors`**: `id`, `user_id` (unique with `type`), `type` enum(`totp`), `secret` (text, `encrypted` cast), `confirmed_at` null, `last_used_timestep` bigint null (replay guard), `last_used_at`, `created_at`. A row with `confirmed_at` null is an enrollment in progress.

**`mfa_recovery_codes`**: `id`, `user_id`, `code_hash` binary(32) (`SecretHasher`: HMAC-SHA256 with the credential pepper, M3 §3.2), `used_at` null, `created_at`. Index `(user_id, code_hash)`. Ten rows per generation; regeneration deletes the old set.

**`trusted_browsers`**: `id`, `user_id`, `token_hash` binary(32) unique, `device_label` varchar(120), `created_at`, `expires_at` (+30 days), `last_used_at`, `revoked_at` null.

**`known_devices`**: `id`, `user_id`, `device_hash` binary(32), `device_label`, `first_seen_at`, `last_seen_at`. Unique `(user_id, device_hash)`. Backs the "New sign-in from a device we haven't seen" email (1g). The device ID is a random value in a long-lived HttpOnly cookie `bf_dev`.

**`sessions`** (created by M0 for the Laravel database driver; extended here through `TrackedDatabaseSessionHandler`): `id` varchar(128) PK, `user_id` null indexed, `ip_address`, `user_agent`, `payload`, `last_activity` int, plus `created_at` int, `device_label` varchar(120), `location_estimate` varchar(120) null, `trusted_browser_id` null. Revoking a session deletes its row.

**`security_events`** (Identity; security log, not customer audit): `id`, `user_id` null, `email_hash` binary(32) (for attempts against unknown emails), `type` enum(`signin.succeeded`,`signin.failed`,`signin.locked`,`mfa.challenge_failed`,`mfa.recovery_code_used`,`mfa.enabled`,`mfa.disabled`,`password.reset`,`password.changed`,`email.changed`,`session.revoked`), `ip_address`, `user_agent`, `created_at`. Index `(user_id, created_at)`. Read by M7. Retention D09.

**`organizations`** (Organizations). M0 creates `id`, `name`, `slug`, `status` (`active`, `suspended`, `deletion_scheduled`) and timestamps; M2 adds the rest.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | `org_` |
| `name` | varchar(120) (M0) | |
| `slug` | varchar(40) | globally unique; `^[a-z][a-z0-9-]{2,39}$`; not in the reserved list (§3.2) |
| `status` | enum from M0 | M3 adds `deleted` and builds the lifecycle transitions |
| `require_mfa` | bool default false | Organization 1c |
| `session_idle_timeout_minutes` | int null | null = platform default; options 60, 720, 1440 (§3.2) |
| `allowed_email_domains` | json | `[]` = any domain; lowercase domains, max 20 |
| `team_size_hint` | enum(`solo`,`small`,`medium`,`large`) null | 5c, analytics only |
| `onboarding` | json | `{plan_chosen_at, team_step_done_at}`; wizard resume state |
| `version` | int | Optimistic concurrency for settings forms |
| `created_by` | ulid | |
| `created_at`, `updated_at` | timestamp(6) | |

Plan and entitlements are not stored here; M6 owns them.

**`organization_memberships`** (from M0): `id`, `organization_id`, `user_id`, `role` enum(`owner`,`member`) (shown as Org Owner and Member), `created_at`, `updated_at`. Unique `(organization_id, user_id)`; M2 adds index `(organization_id, role)`. A third value `billing_admin` is where the Organization mockup's Billing Admin would plug in (§8).

**`project_memberships`** (from M0): `id`, `organization_id`, `project_id`, `user_id`, `role` enum(`owner`,`publisher`,`developer`,`viewer`), `created_at`, `updated_at`. Unique `(organization_id, project_id, user_id)`. M2 adds `added_by`.

**`invitations`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid | |
| `organization_id` | ulid | |
| `email` | varchar(254) | lowercased |
| `org_role` | enum(`owner`,`member`) | Same values as `organization_memberships.role`; `member` from project tabs |
| `status` | enum(`queued`,`pending`,`accepted`,`revoked`,`expired`) | `queued` while the inviter's email is unverified |
| `token_hash` | binary(32) null unique | HMAC-SHA256; set when the email is sent (§3.6); replaced on resend |
| `invited_by` | ulid | |
| `sent_at`, `expires_at` | timestamp(6) null | `expires_at` = `sent_at` + 7 days |
| `accepted_by`, `accepted_at`, `revoked_by`, `revoked_at` | | |
| `created_at`, `updated_at` | timestamp(6) | |

Index `(organization_id, status)`, `(organization_id, email)`. A partial uniqueness rule (one open invitation per org and email) is enforced in the action under a lock, because MySQL has no partial unique index.

**`invitation_project_grants`**: `id`, `organization_id`, `invitation_id`, `project_id`, `role`. Unique `(invitation_id, project_id)`. One row from a project Members tab; zero or more from Organization Members (this is how Auth 3a shows "Projects: Storefront, Mobile app").

**`environment_policies`**: migration lands in M2 with the column set from M3 §3.1, so `CreateProject` can write policy version 1 for each template environment. `projects` and `environments` come from M0; M3 adds their settings and lifecycle columns.

### 3.2 Domain rules and state machines

**Password rules** (`Identity\Domain\PasswordRules`, shared by sign-up, reset, change and invitation accept):

| Rule | Check | Message |
| --- | --- | --- |
| Length | 12 to 128 characters (multibyte-aware) | "At least 12 characters" |
| Not common | Not in the bundled list `resources/security/common-passwords.txt` (top 100,000 common and breached passwords, case-insensitive, refreshed with each release). No external breach service is called (R17) | "Not a common password" |
| Not email | Not equal to the email or its local part, case-insensitive, ignoring surrounding whitespace | "Not your email" |

No composition rules. The Vue checklist mirrors the first and third rule locally and the second through a debounced `POST /password/check` (rate-limited, returns only `common: bool`).

**Verification gate.** `email_verified_at` null means the actor may complete onboarding, create an organization and a project, and browse, but these actions throw `EmailNotVerified` (HTTP 403, code `EMAIL_NOT_VERIFIED`): sending invitations (they are stored as `queued` instead, see below), issuing SDK keys, management tokens and personal tokens (M3), submitting for review and publishing (M5). The portal shows a persistent banner "Confirm your email to invite people, create keys and publish" with Resend. Verification links are Laravel signed URLs valid 24 hours (`auth.verification.expire = 1440`). A used link on an already-verified account shows "Your email is already confirmed".

**Sign-up without enumeration.** `RegisterAccount` always returns the same result page (5b) for a syntactically valid email. If the email is free, it creates the user, signs them in and queues email 1a. If the email belongs to an account, it creates nothing, signs nobody in, and queues email 1b to that address. Response timing is equalized by doing the password hash in both branches. Limitation: "Continue setup" on 5b only works for a real new session, so a determined attacker can still tell the cases apart at that click. Mitigations: rate limit 5 sign-ups per hour per IP and 3 per hour per email hash, and CAPTCHA only on risk signals (operations choice). Shei should confirm this tradeoff (§8).

**Sign-in and lockout.**

| Control | Default |
| --- | --- |
| Per-email failure counter | Redis key on `sha256(lowercased email)`, so it works for unknown emails too |
| Lock | 5 failures within 15 minutes locks that email for 15 minutes (Emails 1i). While locked, every attempt returns "Too many attempts. Try again in 15 minutes." whether or not the account exists |
| Progressive delay | From the 3rd failure, responses wait 1 s, then 2 s |
| Per-IP limit | 30 attempts per minute across emails (`throttle:signin-ip`) |
| Two-factor | 5 wrong codes end the pending sign-in; the person starts again with the password. Counts toward the email counter |
| Email | 1i sent once per lock, only if the account exists |
| Clear | Automatic after 15 minutes; support can clear early (M7) |

Failures always say "That email and password don't match. Check them and try again." Deactivated accounts get the same message at sign-in and see 3c only after a correct password and second factor.

**Two-factor (TOTP).** RFC 6238, SHA-1, 6 digits, 30 s step, ±1 step window. A code is rejected if its timestep is not greater than `last_used_timestep`. Enrollment: generate secret, show QR and the text secret, confirm with one valid code, then generate 10 recovery codes (format `xxxxx-xxxxx`, base32, about 50 bits each) shown once with Copy, Download .txt and an "I've stored these codes" checkbox. There is no "view codes again" route. Regenerating requires password confirmation and invalidates the old set. Disabling requires password confirmation and a current code, and is blocked while any organization the user belongs to has `require_mfa` ("Acme requires two-factor sign-in"). Using a recovery code signs in, logs `mfa.recovery_code_used`, sends 1h with "codes left", and when 2 or fewer remain shows a prompt to regenerate. Lost device without codes: support process (M7).

**Sign-in states.** A correct password does not authenticate anyone. `ValidateCredentials` checks the password with `Auth::validate()`, which never logs in, and returns the user. If the user has two-factor and the browser is not trusted, `RedirectIfTwoFactorRequired` regenerates the session ID, stores only `login.pending = {user_id, remember, expires_at (+10 minutes), attempts}` and redirects to the challenge; the session has no authenticated user. Only `CompleteSignIn` calls `Auth::login()`, and only when no second factor is required or `CompleteTwoFactorChallenge` has just succeeded. A password reset follows the same path: it ends with `login.pending`, not a login, when two-factor is enrolled. The `auth` guard therefore cannot see a pending user, so no middleware or route has to remember to check for it.

**Trusted browser.** Ticking "Trust this browser for 30 days" on 1c creates a `trusted_browsers` row and sets cookie `bf_tb` (`{id}.{secret}`, HttpOnly, Secure, SameSite=Lax, 30 days). The next password sign-in for the same user from that browser skips the TOTP step. Trust is revoked on password reset, password change, disabling two-factor, "Sign out all sessions", and manually in Account security. A trusted browser still counts as having two-factor for org `require_mfa`.

**Password reset.** `POST /forgot-password` always answers with 2b ("If an account exists for {email}, a reset link is on its way. The link works for 60 minutes."), resend allowed after 60 s. Tokens use the Laravel broker (`auth.passwords.users.expire = 60`, single use, hashed by the broker). Rate limit: 3 per hour per email hash, 10 per hour per IP. A successful reset: sets the password, deletes every session row and trusted browser for the user, clears the lock, logs `password.reset`, sends 1d, and signs the person in on the current browser only (then the two-factor step if enrolled; a reset never bypasses two-factor).

**Sessions and timeout.**

| Setting | Default | Source |
| --- | --- | --- |
| Idle timeout | 7 days | accounts doc §5 |
| Absolute lifetime | 30 days from `created_at` | accounts doc §5 |
| Org idle policy | Off, 1 hour, 12 hours, 24 hours | Organization 1c ("Sign out after 12 hours of inactivity") |

`EnforceSessionPolicy` middleware runs on every portal request. It computes the strictest idle timeout among the organization in the current route scope and the platform default. If exceeded, it ends the session and returns 409 `SESSION_EXPIRED` to Inertia requests (full-page requests redirect to `/signin?intended=`). Org policy applies only when the person opens that organization, so one session can span organizations with different policies. Cookies are host-only on the app host, Secure, HttpOnly, SameSite=Lax; CSRF via Laravel's `VerifyCsrfToken`. Session ID rotates on sign-in, two-factor completion and password change.

**Session expired (3d).** The frontend intercepts `SESSION_EXPIRED` and 419 responses and opens `SessionExpiredDialog` without navigating, so composable state (for example M4's `useFlagDraft`) stays in memory. The dialog posts to `/signin` as JSON, completes two-factor inline if needed (trusted browser usually skips it), refreshes the CSRF token, then calls `router.reload()`. "Use another account" signs out and goes to `/signin?intended=`. Copy shows the actual timeout that applied.

**Organization slug.** Lowercase, 3 to 40 characters, starts with a letter, letters, digits and `-`. Reserved list: M0's list plus M2's additions (§3.4), `settings`, `billing`, `docs`, `status`, `help`, and anything starting with `bf-`. Changing it does not create redirects; the form warns "changing it breaks old links". Availability check endpoint is rate-limited.

**Organization roles (R07) and org capabilities.**

| Capability | Org Owner | Member | Notes |
| --- | --- | --- | --- |
| `org.read` (added here) | ✓ | ✓ | Org name, own memberships, General read-only |
| `org.manage` | ✓ | | Name, slug, security policy, deletion (M3) |
| `org.members.manage` | ✓ | | Invite, change org role, remove |
| `org.billing.manage` | ✓ | | Used by M6. Billing Admin would hold only this plus `org.read` |
| `projects.create` (added here) | ✓ | | Create projects (subject to `EnforcePlanLimit`) |

An Org Owner is not automatically a project member. `AddSelfToProject` lets them join any project as project Owner; it is audited (`project_member.self_added`). Organization settings pages are visible to Org Owners; Members see General read-only and their own row.

**Project roles (R01).** Capabilities follow the table in 00-conventions and M0's `RoleCapabilityMap`. All checks go through M0's `CapabilityResolver::can(Actor, Capability, Scope)`, memoized per request. M2 adds `org.read` and `projects.create` to the `Capability` enum and `OrgRoleCapabilityMap`. For the two publish capabilities, the resolver asks M0's `EnvironmentProtection` interface which capability an environment requires. M2 rebinds it to read `environment_policies.is_protected` (protected requires `environment.publish.protected`); M3 replaces it with the full policy logic (M3 §3.2). Last-Owner rule: a project always keeps at least one Owner and an organization at least one Org Owner. A person cannot change their own role or remove themselves from the Members tab (Settings 2a); "Leave project" lives in Account settings and applies the same last-Owner rule.

**Invitation state machine.**

```text
queued ──inviter verifies──▶ pending ──accept──▶ accepted
  │                            │  ├─resend (new token, new 7-day expiry)─▶ pending
  └──revoke──▶ revoked ◀─revoke┘  └─expires_at passes─▶ expired
```

- Created from a project Members tab (one grant, org role `member`), from Organization Members (org role plus zero or more grants), or from onboarding 5f.
- Rejected at creation: invalid email, already an org member with the same grants, an open invitation for the same email, a domain outside `allowed_email_domains` ("Invitations to other domains are blocked"), more than 20 emails per request, more than 200 invitations per org per day.
- `expired` is set by a scheduled job, but acceptance checks `expires_at` directly, so the job only affects listings.
- **Accept, signed out, no account (3a):** form shows the invited email read-only, name, password. Creates the user with `email_verified_at = now()` (the link proves the mailbox), then accepts. If the email already has an account, the form answers "You already have an account. Sign in to accept." This reveals existence only to the holder of the mailbox's invitation link.
- **Accept, signed in as the invited email:** confirmation card "Join Acme" with the grants, then accept.
- **Accept, signed in as a different email:** refused. Screen: "This invitation was sent to j•••@acme.dev. You're signed in as maya@acme.dev." Actions: "Sign out and continue" and "Back to BitFlag". Nothing is consumed.
- **Expired or revoked:** 3b with the inviter's name. **Already accepted:** "This invitation was already used" with a link to sign in.
- **On accept (transaction):** lock the invitation row, recheck status, expiry, email match and allowed domains, create the org membership if missing (never downgrade an existing Org Owner), create each project membership that does not exist yet (existing memberships keep their role; the result lists them), set `accepted`, write audit, push outbox. If the org requires MFA and the person has none, the next request into that org goes to enrollment.

**Onboarding wizard.** Resume state is derived from facts, so refreshing or returning later lands on the right step:

| Condition | Step |
| --- | --- |
| No organization membership and no pending invitation | 5c Organization |
| Org created by this user, `onboarding.plan_chosen_at` null, and M6's plan step registered | 5d Plan |
| Org has no projects | 5e First project |
| `onboarding.team_step_done_at` null | 5f Invite team |
| Otherwise | 5g Ready once, then Overview |

A person who arrives through an invitation skips 5c to 5f. A person who abandons the wizard and signs in later is sent to the resume step. Org creation is limited to 5 organizations per user (abuse guard; config).

### 3.3 Actions

Each action follows the conventions (authorize, load, version check, transaction with audit and outbox, typed result). "Security log" means a `security_events` row, not customer audit.

| Action | Module | Input | Authorization | Writes / audit / outbox |
| --- | --- | --- | --- | --- |
| `RegisterAccount` | Identity | `RegisterAccountData(name?, email, password, ip, ua)` | Guest, rate-limited | user (if new); outbox `internal.email.requested{template: verify_email}` or `{template: signup_attempt_existing}` |
| `ChangeUnverifiedEmail` | Identity | new email | Signed-in, unverified | Same enumeration rule as sign-up; resends 1a |
| `ResendVerificationEmail` | Identity | | Signed-in, unverified, 1 per minute | outbox `verify_email` |
| `MarkEmailVerified` | Identity | signed URL | Signed URL + matching user | `email_verified_at`; dispatches `EmailVerified` → `ReleaseQueuedInvitations` |
| `AttemptSignIn` | Identity | email, password, device cookie | Guest | Security log; may require two-factor; new-device check → outbox `new_signin` |
| `CompleteTwoFactorChallenge` | Identity | code or recovery code, trust flag | Pending sign-in in session | Security log; trusted browser row; outbox `recovery_code_used` when applicable |
| `RequestPasswordReset` | Identity | email | Guest, rate-limited | outbox `password_reset{user_id}` only if the account exists; same response either way |
| `ResetPassword` | Identity | token, email, password | Broker token | Password, sessions and trusted browsers cleared, security log, outbox `password_changed` |
| `ChangePassword` | Identity | current, new | Signed-in, password confirmed | Other sessions deleted, security log, outbox `password_changed` |
| `RequestEmailChange` / `ConfirmEmailChange` | Identity | new email / signed URL | Password confirmed / signed URL | `pending_email`; outbox `confirm_new_email` then `email_changed` to the old address |
| `StartTwoFactorEnrollment`, `ConfirmTwoFactorEnrollment` | Identity | code | Password confirmed | `mfa_factors`, `mfa_recovery_codes`; outbox `mfa_enabled` |
| `DisableTwoFactor`, `RegenerateRecoveryCodes` | Identity | code / none | Password confirmed; not blocked by org policy | outbox `mfa_disabled` |
| `RevokeSession`, `RevokeOtherSessions`, `RevokeTrustedBrowser` | Identity | session or browser ID | Owner of the session | Delete rows; security log |
| `DeleteAccount` | Identity | password, typed email | Password confirmed; not last Org Owner anywhere | Remove memberships, revoke personal tokens (M3 hook), set `deleted_display_name`, anonymize email; audit `member.removed` per org |
| `CreateOrganization` | Organizations | name, slug, team size | Signed-in, under 5 orgs | org, Org Owner membership; audit `organization.created` |
| `UpdateOrganization` | Organizations | name, slug, `expectedVersion` | `org.manage` | audit `organization.updated` (before/after name and slug) |
| `UpdateOrganizationSecurity` | Organizations | require MFA, idle timeout, domains, `expectedVersion` | `org.manage` | audit `organization.security_changed`; turning on MFA does not sign anyone out |
| `ChooseOrganization` | Organizations | org, remember | `org.read` | `last_organization_id`, `remember_org_choice` |
| `InviteMembers` | Organizations | `InviteMembersData(emails[], orgRole, grants[])` | `org.members.manage`, or `members.manage` on the granted project (project-tab invites are `member` org role only) | invitations (`queued` if the inviter is unverified), grants; audit `invitation.created` per email; outbox `internal.email.requested{template: invitation, invitation_id}` when pending |
| `ResendInvitation` | Organizations | invitation | Same as invite | New token on send, expiry reset to 7 days; audit `invitation.resent` |
| `RevokeInvitation` | Organizations | invitation | Same as invite | audit `invitation.revoked` |
| `AcceptInvitation` | Organizations | token, actor or new-account data | Token + email match | memberships; audit `invitation.accepted`, `project_member.added`; outbox `internal.member.added` (M8 in-app "You were added") |
| `ReleaseQueuedInvitations` | Organizations | user | System, on `EmailVerified` | queued → pending, outbox per invitation |
| `ChangeOrganizationRole` | Organizations | membership, role | `org.members.manage`; last Org Owner rule | audit `org_member.role_changed` |
| `RemoveOrganizationMember` | Organizations | membership | `org.members.manage`; last Org Owner rule; not self | Deletes org and project memberships; dispatches `MemberRemovedFromOrganization` (M3 revokes personal tokens); audit `org_member.removed` |
| `ChangeProjectRole` | Organizations | membership, role, `expectedVersion` | `members.manage`; last Owner rule; not self | audit `project_member.role_changed`; outbox `internal.member.role_changed` |
| `RemoveProjectMember` / `LeaveProject` | Organizations | membership | `members.manage` and not self / self | Dispatches `MemberRemovedFromProject` (M3 revokes that user's personal tokens for the project); audit `project_member.removed` |
| `AddSelfToProject` | Organizations | project | Org Owner | membership as Owner; audit `project_member.self_added` |
| `CreateProject` | Projects | name, key (`projects.key`), environment list | `projects.create`, `EnforcePlanLimit` (`LimitKey::Projects`, `LimitKey::EnvironmentsPerProject`), verified not required | project, environments from template, policy v1 per environment, creator as Owner; audit `project.created`, `environment.created`; outbox `internal.project.created` |

`CreateProject` is specified fully in M3 §3.3; M2 implements its first version.

### 3.4 Routes

Route scheme from M0: organization pages under `/{organization}`, project pages under `/{organization}/{project}/{environment}/…` (M0's `ResolvePortalScope` resolves and authorizes the segments). Fortify keeps M0's `/signin` and `/signout` paths (the website links `/signin` and `/signup`) and adds `/two-factor-challenge`; `/login` redirects to `/signin`. M2 adds `signin`, `signout`, `login`, `signup`, `onboarding`, `forgot-password`, `reset-password`, `email`, `password`, `organizations` to M0's reserved slug list.

**Guest and account pages** (Inertia page names under `resources/js/pages/`)

| Method and path | Page / response | Notes |
| --- | --- | --- |
| `GET /signup`, `POST /signup` | `auth/SignUp` (5a) | Redirect to `/signup/check-email` in both cases |
| `GET /signup/check-email` | `auth/CheckEmail` (5b) | Props: masked email, `canContinue` |
| `POST /signup/change-email`, `POST /email/verification-notification` | redirect | 1/min |
| `GET /email/verify/{id}/{hash}` | redirect | Fortify `VerifyEmailController`, signed |
| `GET /signin`, `POST /signin` | `auth/SignIn` (1a, 1b) replacing M0's `auth/Login` | JSON variant for `SessionExpiredDialog`; `GET /login` redirects here |
| `GET /two-factor-challenge`, `POST /two-factor-challenge` | `auth/TwoFactorChallenge` (1c) | Props: masked email, `canUseRecovery` |
| `POST /signout` | redirect | |
| `GET /forgot-password`, `POST /forgot-password` | `auth/ForgotPassword` (2a), `auth/ResetLinkSent` (2b) | |
| `GET /reset-password/{token}`, `POST /reset-password` | `auth/ResetPassword` (2c) | |
| `POST /password/check` | JSON `{common}` | Rate-limited |
| `GET /invitations/{token}` | `auth/AcceptInvitation` (3a) / `auth/InvitationUnavailable` (3b) / `auth/InvitationWrongAccount` | Never reveals other invitations |
| `POST /invitations/{token}/accept` | redirect into the org | Signed-in or new-account body |
| `GET /organizations/choose`, `POST /organizations/choose` | `auth/ChooseOrganization` (3e) | |
| `GET /account/deactivated` | `auth/AccessRemoved` (3c) | |
| `GET /onboarding/organization`, `POST` | `onboarding/Organization` (5c) | `EnsureOnboardingStep` redirects to the resume step |
| `GET /onboarding/{organization}/plan`, `POST` | `onboarding/Plan` (5d) | Built by M6. Until M6, the step resolver skips it (every organization is Free) |
| `GET /onboarding/{organization}/project`, `/team`, `/ready`; `POST .../project`, `.../team`, `.../team/skip` | `onboarding/Project`, `Team`, `Ready` (5e to 5g) | |
| `GET /onboarding/slug-availability?slug=` | JSON `{available, reason}` | 30/min per user |
| `GET /account/profile`, `PUT` | `account/Profile` | Name, timezone, email change |
| `GET /account/security` | `account/Security` | Password, two-factor, recovery codes, trusted browsers, sessions |
| `POST /account/two-factor`, `POST /account/two-factor/confirm`, `DELETE /account/two-factor`, `POST /account/recovery-codes` | JSON | Codes returned once in the JSON body, never flashed to the session |
| `DELETE /account/sessions/{session}`, `DELETE /account/sessions` | redirect | |
| `DELETE /account/trusted-browsers/{id}` | redirect | |
| `GET /account/delete`, `DELETE /account` | `account/Delete` | |

**Organization and project pages**

| Method and path | Page | Capability |
| --- | --- | --- |
| `GET /{organization}/settings` | redirect | M0's placeholder now redirects to General |
| `GET /{organization}/settings/general`, `PUT` | `organization/General` (1a) | `org.read` view, `org.manage` save |
| `GET /{organization}/settings/members` | `organization/Members` (1b) | `org.members.manage` (Members get their own row) |
| `POST /{organization}/settings/members/invitations`, `POST .../{invitation}/resend`, `DELETE .../{invitation}` | redirect | `org.members.manage` |
| `PATCH /{organization}/settings/members/{membership}`, `DELETE` | redirect | `org.members.manage` |
| `GET /{organization}/settings/security`, `PUT` | `organization/Security` (1c) | `org.manage` |
| `GET /{organization}/settings/projects`, `POST /{organization}/settings/projects/{project}/join` | `organization/Projects` | `projects.create` to create (M3 builds the create dialog), Org Owner to join |
| `GET /{organization}/{project}/{environment}/settings/members` | `project/settings/Members` (2a, 2c) | `project.read` view, `members.manage` edit |
| `POST /{organization}/{project}/{environment}/settings/members/invitations` and resend/cancel | redirect | `members.manage` |
| `PATCH /{organization}/{project}/{environment}/settings/members/{membership}`, `DELETE` | redirect | `members.manage` |

Shared Inertia props: M0's `SharedProps` shape (`auth`, `scope`, `environments`, lazy `switcher`, `navigation`, `can`, `flash`, `requestId`). M2 adds `verified` and `twoFactorEnabled` to `auth.user`, and org role and project count to each `switcher.organizations` entry. The current organization and project are read from `scope`; there are no separate `currentOrganization` or `currentProject` props. The switcher and environment picker sit in the sidebar (M0 shell).

**Management API.** M2 adds no token-authenticated endpoints; token auth arrives in M3. Members and invitations API contracts are listed for D14 and implemented when the CLI needs them.

### 3.5 Frontend

| Area | Files |
| --- | --- |
| Auth layout | `layouts/AuthLayout.vue` (centered card, optional context panel, footer with Docs and Status), `layouts/OnboardingLayout.vue` (stepper: Account, Organization, Project, Team) |
| Pages | `pages/auth/*`, `pages/onboarding/*`, `pages/account/*`, `pages/organization/*`, `pages/project/settings/Members.vue` |
| Feature components | `features/auth/PasswordChecklist.vue`, `features/auth/OtpInput.vue` (6 boxes, paste support, numeric keyboard), `features/auth/RecoveryCodesPanel.vue` (copy, download, acknowledgement), `features/auth/SessionExpiredDialog.vue`, `features/account/SessionsTable.vue`, `features/account/TwoFactorSetup.vue` (QR from server SVG), `features/members/InviteDialog.vue` (email parsing, live count, duplicate and domain errors), `features/members/MembersTable.vue`, `features/members/RoleCards.vue`, `features/members/PendingInvites.vue`, `features/organization/OrgSwitcher.vue`, `features/organization/SlugField.vue` |
| Composables | `usePasswordChecks` (debounced server check), `useSlugAvailability`, `useEmailList` (split on commas, spaces, new lines; validate; dedupe), `useSessionExpiry` (Inertia `invalid` and `exception` hooks), `useCan` (reads `can` prop) |
| Read-only state | Members tab for non-Owners: selects disabled, actions hidden, banner text per role (2c) |
| Unverified banner | `components/ui/VerifyEmailBanner.vue` in the app layout |

Copy changes from the mockups: 3b says "another owner" instead of "another admin"; 3c reads "Your access was removed. Your BitFlag account was deactivated. Contact support if you think this is a mistake."

### 3.6 Jobs, events and outbox consumers

| Item | Kind | Behavior |
| --- | --- | --- |
| `internal.email.requested` | Outbox type | Account-scoped (`scope = account`, M0) when no organization applies, for example sign-up, verification, password and MFA mail; organization-scoped for invitations. Payload `{template, user_id or invitation_id, context}`; never contains tokens or links. Templates: `verify_email` (1a), `signup_attempt_existing` (1b), `password_reset` (1c), `password_changed` (1d), `confirm_new_email` (1e), `email_changed` (1f), `new_signin` (1g), `recovery_code_used` (1h), `mfa_enabled`, `mfa_disabled` (1h layout), `account_locked` (1i), `invitation` (2a) |
| `SendAccountEmail` | Outbox consumer | Builds secrets at send time: signed verification URL, broker reset token, invitation token (stores only `token_hash`, sets `sent_at` and `expires_at`). M2 ships plain-text Laravel notifications through Mailpit; M8 replaces templates and the provider without changing the outbox contract. Idempotent per outbox message ID |
| `EmailVerified` | Event | `ReleaseQueuedInvitations` |
| `MemberRemovedFromOrganization`, `MemberRemovedFromProject` | Domain events (sync, after commit) | M3 listener revokes personal tokens |
| `internal.member.added`, `internal.member.role_changed` | Outbox | M8 in-app notifications |
| `ExpireInvitations` | Scheduled, every 15 min | `pending` past `expires_at` → `expired` |
| `PruneSecurityState` | Scheduled, daily | Delete expired trusted browsers, sessions past absolute lifetime, stale enrollments older than 1 day |
| `TouchLastActive` | Middleware | Updates `users.last_active_at` at most every 5 minutes |

### 3.7 Security and tenant isolation

- Every org-scoped route resolves `{organization}` by slug, then requires a membership (or 404). Every project route resolves `{project}` inside that organization, then requires a project membership (or 404). Org Owners without project membership get 404 on project pages and see "Join as Owner" in Organization → Projects.
- Membership and role are loaded per request, never cached in the session, so removal and role changes apply on the next request.
- `require_mfa`: `EnsureOrganizationMfa` middleware sends members without a confirmed factor to `/account/security?required_by={org}` before any page in that org renders. API tokens are covered by M3.
- Invitation tokens, recovery codes and trusted-browser secrets are stored only as HMAC-SHA256 hashes. TOTP secrets are encrypted with the app key (they must be recoverable).
- Generic errors: sign-in, reset and sign-up never reveal whether an email has an account. Lockout responses are identical for unknown emails.
- Password confirmation (`password.confirm`, 15-minute window) before two-factor changes, recovery code regeneration, email change, account deletion, and (M3) token creation.
- Log redaction: request logging never records `password`, `code`, `recovery_code`, `token` fields (Laravel `dontFlash` and a log processor).
- Tenant isolation tests cover `organizations`, `organization_memberships`, `project_memberships`, `invitations`, `invitation_project_grants`. User-owned tables (`users`, `mfa_*`, `trusted_browsers`, `known_devices`, `sessions`, `security_events`) are in the allowlist with the reason "user-scoped, never returned across users".

## 4. Implementation steps

Each step is one pull request.

1. **Identity schema and session handler.** Migrations for `users` changes, `mfa_factors`, `mfa_recovery_codes`, `trusted_browsers`, `known_devices`, `security_events`, extended `sessions`; `TrackedDatabaseSessionHandler`; `App\Shared\Security\SecretHasher` (peppered HMAC-SHA256, specified in M3 §3.2, used here for recovery codes, invitation tokens and trusted browsers); `DeviceLabel` (UA parsing) and `GeoLocator` interface with `NullGeoLocator` ("Unknown location") until D08 picks a GeoIP source. Files: `app/Modules/Identity/Models/*`, `app/Modules/Identity/Support/TrackedDatabaseSessionHandler.php`.
2. **Fortify wiring and sign-in.** `config/fortify.php` features (`registration` off, own routes), `FortifyServiceProvider` with `Fortify::authenticateThrough([EnsureEmailNotLocked, ValidateCredentials, RedirectIfTwoFactorRequired, CompleteSignIn, PrepareAuthenticatedSession])` (order and meaning in §3.2 "Sign-in states"; Fortify's own `AttemptToAuthenticate` is not used because it logs the user in), lockout limiter (`Identity/Domain/SignInThrottle`), `AttemptSignIn`, `auth/SignIn.vue`, `AuthLayout.vue`.
3. **Outbox email contract.** `internal.email.requested` type, `SendAccountEmail` consumer, interim notifications for all templates in §3.6, Mailpit in Sail. Files: `app/Modules/Identity/Jobs/SendAccountEmail.php`, `app/Modules/Identity/Notifications/*`.
4. **Sign-up and verification.** `PasswordRules`, common-password list, `POST /password/check`, `RegisterAccount`, `ChangeUnverifiedEmail`, `ResendVerificationEmail`, `MarkEmailVerified`, `EmailNotVerified` exception and banner, `auth/SignUp.vue`, `auth/CheckEmail.vue`, `PasswordChecklist.vue`.
5. **Password reset and change.** `RequestPasswordReset`, `ResetPassword`, `ChangePassword`, pages 2a to 2c, session and trusted-browser revocation.
6. **Two-factor.** `StartTwoFactorEnrollment`, `ConfirmTwoFactorEnrollment`, `DisableTwoFactor`, `RegenerateRecoveryCodes`, `CompleteTwoFactorChallenge`, trusted browser cookie, `known_devices` and the new-sign-in email, `auth/TwoFactorChallenge.vue`, `OtpInput.vue`, `RecoveryCodesPanel.vue`, `TwoFactorSetup.vue`.
7. **Sessions.** `EnforceSessionPolicy`, `RevokeSession`, `RevokeOtherSessions`, `account/Security.vue` sessions and trusted browsers sections, `SessionExpiredDialog.vue`, `useSessionExpiry`.
8. **Organizations.** Column migrations on M0's `organizations` and `organization_memberships`; `CreateOrganization`, `UpdateOrganization`, `ChooseOrganization`; slug rules and availability; `OrgSwitcher.vue` extends M0's `OrgProjectSwitcher.vue` with roles and project counts; `auth/ChooseOrganization.vue`; post-sign-in redirect logic (`Identity/Http/Responses/SignInResponse`).
9. **Projects core and memberships.** `environment_policies` migration (M3 §3.1 columns), `project_memberships.added_by`; first `CreateProject` with template, calling `EnforcePlanLimit` (null binding until M6).
10. **Capabilities.** Extend M0's `app/Shared/Capabilities` (`org.read`, `projects.create`; interim `EnvironmentProtection` binding), policies (`OrganizationPolicy`, `ProjectPolicy`, `EnvironmentPolicy`, `InvitationPolicy`), `can` map in `HandleInertiaRequests`, `useCan`, `ResolveOrganization` for org-only routes (M0's `ResolvePortalScope` covers project routes), `EnsureOrganizationMfa`.
11. **Invitations.** Migrations `invitations`, `invitation_project_grants`; `InviteMembers`, `ResendInvitation`, `RevokeInvitation`, `AcceptInvitation`, `ReleaseQueuedInvitations`, `ExpireInvitations`; pages 3a, 3b, wrong-account.
12. **Onboarding wizard.** `EnsureOnboardingStep` (skips the plan step until M6 registers it), `OnboardingLayout.vue`, pages 5c, 5e, 5f, 5g.
13. **Organization settings.** `organization/General.vue`, `Members.vue`, `Security.vue`, `Projects.vue`; `UpdateOrganizationSecurity`, `ChangeOrganizationRole`, `RemoveOrganizationMember`, `AddSelfToProject`.
14. **Project Members tab.** `project/settings/Members.vue`, `InviteDialog.vue`, `MembersTable.vue`, `RoleCards.vue`, `PendingInvites.vue`; `ChangeProjectRole`, `RemoveProjectMember`, `LeaveProject`.
15. **Account settings and deletion.** `account/Profile.vue`, `RequestEmailChange`, `ConfirmEmailChange`, `DeleteAccount`, `account/Delete.vue`.
16. **Seed and end-to-end.** Extend M0's demo seed with two-factor for Maya and Jon, a pending invitation (`dev@northwind.studio`, Developer, Storefront) and a second organization for Maya (Auth 3e); add `/_testing/mailpit` to M0's Cypress harness (`/_testing/clock` already exists); Cypress specs.

## 5. Tests

**Pest unit** (`tests/Unit/Identity`, `tests/Unit/Organizations`)

- `PasswordRulesTest`: 11 vs 12 characters, multibyte length, common list (case-insensitive), equals email, equals local part, and no outbound HTTP call during sign-up.
- `TotpVerifierTest`: window ±1, replay of the same timestep rejected, wrong length.
- `OrgCapabilitiesTest`: `org.read` and `projects.create` added to M0's maps; Billing Admin absent.
- `InvitationStateMachineTest`, `SlugRulesTest` (reserved words, length, characters), `OnboardingStepResolverTest`.

**Pest feature** (`tests/Feature/Identity`, `tests/Feature/Organizations`, real MySQL and Redis)

- Sign-up: new email signs in and queues `verify_email`; existing email creates nothing, signs nobody in, queues `signup_attempt_existing`; both responses identical (status, redirect, body hash).
- Verification gate: unverified actor gets `EMAIL_NOT_VERIFIED` on `InviteMembers` (stored as queued), and invitations release on verification.
- Sign-in: generic error; lockout after 5 failures for existing and unknown emails with identical responses; lock email sent once; per-IP limit; deactivated account.
- Two-factor: challenge required; a session holding only `login.pending` gets the same response as a guest on every route that requires authentication (generated from the route list, portal and API, including Inertia partial reloads and the `can` map), and `Auth::check()` is false; 5 wrong codes end the attempt; recovery code single use and email; trusted browser skips TOTP and is revoked by password reset; disable blocked by org `require_mfa`.
- Reset: same response for unknown email; token expires after 60 minutes (clock travel); all sessions deleted after reset; two-factor still required, and the post-reset session is only `login.pending` until the code is entered.
- Sessions: idle and absolute timeout; org idle policy only applies inside that org; `SESSION_EXPIRED` for Inertia requests; revoke other sessions.
- Invitations: every state transition; wrong account refused without consuming; expired and revoked show 3b; allowed domains at create and accept; concurrent double accept creates one membership (two connections); existing memberships never downgraded.
- Memberships: last project Owner and last Org Owner cannot be removed or downgraded (including concurrent demotion of two Owners, which must leave one); self role change refused; Org Owner self-add audited; removal fires the M3 hook event.
- Emails: no outbox payload contains a token, link or password (scan payload JSON).
- **Tenant isolation:** a user of org A cannot read or change B's members, invitations, settings or project members via any route (generated from the route list); scoped query helper test covers each new tenant table.

**Pest arch** (`tests/Arch`)

- Controllers in `Identity` and `Organizations` call actions only; actions never call `auth()`; `Organizations` does not write `projects` except through `Projects` actions.

**Vitest**

- `PasswordChecklist.spec.ts`, `OtpInput.spec.ts` (paste, backspace, non-digits), `useEmailList.spec.ts`, `InviteDialog.spec.ts` (duplicate and domain messages, button label "Send 2 invites"), `SessionExpiredDialog.spec.ts` (keeps a composable's state across re-authentication), `RecoveryCodesPanel.spec.ts` (Done disabled until acknowledged).

**Cypress** (`cypress/e2e/`, seeded demo data, Mailpit read through a Cypress task, TOTP codes from `otplib` in a task)

| Spec | Flow |
| --- | --- |
| `m2-signup-onboarding.cy.ts` | 5a → 5b → 5c → 5e → 5f (queued invites) → 5g (5d joins once M6 lands, `m6-onboarding-plan.cy.ts`); verify email from Mailpit; invites are sent; Overview loads |
| `m2-signup-existing-email.cy.ts` | Same 5b screen; Mailpit has 1b, not 1a |
| `m2-signin-two-factor.cy.ts` | Enroll, recovery codes, sign out, sign in with TOTP, trust browser, sign in again without code, recovery code sign-in |
| `m2-password-reset.cy.ts` | 2a → 2b → link → 2c → signed in; old session elsewhere is gone |
| `m2-lockout.cy.ts` | 5 failures → locked message → clock travel → sign in |
| `m2-invitation-accept.cy.ts` | New user accepts; existing user accepts; wrong account refused; expired (clock travel) and revoked show 3b |
| `m2-organization-settings.cy.ts` | Require MFA forces Priya to enroll; idle policy; allowed domains block an invite; change org role; last Org Owner protected |
| `m2-project-members.cy.ts` | Invite from Members tab, resend, cancel; change role; remove; Jon as Developer sees 2c read-only |
| `m2-sessions.cy.ts` | Revoke another session; idle expiry opens the dialog and keeps the page |

## 6. Decisions

| Item | Default assumed until decided |
| --- | --- |
| R01, R07, R10, R11 | Applied as written. Billing Admin not built; GitHub/Google not built |
| R08 | New orgs are Free; the plan step is skipped until M6 |
| D02 (ownership recovery, changed-membership behavior) | Last Owner rules as above; no ownership transfer UI beyond role changes |
| D08 (GeoIP, email provider) | `NullGeoLocator`; Mailpit interim sender |
| D09 (security log and session retention) | Security events kept 90 days; expired sessions pruned daily |
| D10 (trial) | No trial; Free is the trial |
| D12 (organization deletion) | Built in M3 |
| D14 (members and invitations API, error codes) | Codes used here: `EMAIL_NOT_VERIFIED`, `SESSION_EXPIRED`, `LAST_OWNER`, `INVITATION_UNAVAILABLE`, `INVITATION_WRONG_ACCOUNT`, `DOMAIN_NOT_ALLOWED`, `PLAN_LIMIT` |
| Proposed values (accounts doc §9) | Verification 24 h, reset 60 min, lockout 5 failures / 15 min, idle 7 days, absolute 30 days, invitations 7 days, trusted browser 30 days, recovery codes 10 |

## 7. Done criteria

- Cypress specs in §5 pass in CI, covering sign-up to first project, sign-in with two-factor, password reset, and accepting and expiring an invitation.
- Sign-up, sign-in, reset and lockout responses are identical for existing and unknown emails (feature tests compare responses).
- A person who has entered a correct password but not yet the second factor cannot read or change anything that requires authentication (route-list test in §5).
- Unverified accounts cannot send invitations; queued invitations send after verification.
- Last Owner and last Org Owner invariants hold under concurrent requests.
- Tenant isolation tests pass for every new tenant table and every org or project route.
- No outbox payload, log line or session payload contains a password, reset token, invitation token, TOTP secret or recovery code (scan test).
- PHPStan, Pint, Vitest and `vue-tsc` are clean.

## 8. Risks and open questions

1. **Sign-up enumeration vs "continue setup before verifying" (5b).** The "Continue setup" click can still distinguish a new account from an existing one. Default: accept with rate limits. Alternative: require verification before 5c, which changes the drawn flow. **Resolved (R17, 8 October 2026): the default above is the decision.**
2. **Session timeout values disagree.** The accounts doc proposes 7 days idle and 30 days absolute; Auth 3d says "Your session ended after 12 hours"; Organization 1c offers "Sign out after 12 hours of inactivity" as an org policy. Default: platform 7 days and 30 days, with an org option of 1, 12 or 24 hours.
3. **Billing Admin (Organization 1b, 2a, 3b).** Not built (R07). If added: enum value `billing_admin` with `org.read` and `org.billing.manage`, no project access, and Organization 3b's "Billing admin" line in the upgrade prompt.
4. **Invitation shape.** Auth 3a shows several projects and an environment-level "Publish development, staging" line; R01 roles apply to every environment and the accounts doc says an invitation names "the project". Default: one org role plus zero or more (project, role) grants, no environment-level grants.
5. **Account settings has no mockup.** Profile, email change, password, two-factor enrollment, recovery codes, trusted browsers and sessions are designed here from the docs, in the existing visual language. "Your sessions" also appears on Organization 1c, where it is rendered with the same component.
6. **Allowed email domains** appear only in Organization 1c, not in the docs. Built as drawn.
7. **Deactivated account (3c)** is self-hosted copy. Hosted meaning assumed: support deactivated the account (M7). A person removed from their last organization instead sees "You're not in any organization" with "Create an organization".
8. **Breached-password check.** Resolved (R17): local list only, no HIBP call, so sign-up has no third-party dependency. The list is refreshed each release.
9. **Fortify scope.** Fortify is used for the sign-in pipeline, the password broker, email verification and password confirmation. Its two-factor feature is not used, because recovery codes must be shown once and stored hashed, and replay protection needs `last_used_timestep`. This keeps the `mfa_factors` table from the domain model.
