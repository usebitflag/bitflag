# M8. Email and notifications

Status: draft for review, 6 October 2026. Follows [00-conventions](00-conventions.md). Sources: `email-and-notifications.md` (all), decision register R10, R11, D08, D09, `accounts-and-organizations.md` §2 to §4, `billing-and-plans.md` §6, `support-and-admin.md` §4, Emails mockup 1a–3c, top bar bell in the Overview and Flags mockups.

## 1. Goal and scope

Tell people what they need to know through transactional email and in-app notifications (R10), with every message produced from the outbox, never inside a request.

**In scope**

- Every email in the Emails mockup (1a–3c) and every email listed in email-and-notifications §2, as Laravel Mailables with HTML and text versions in one shared Nocturne-styled layout.
- Emails produced by other milestones' outbox messages: account and security (M2), invitations (M2), review requests and decisions (M5), billing (M6), support MFA reset and suspension (M7), lifecycle (M3).
- Three sender streams: `account@`, `notifications@`, `billing@` (see §8 about `billing@`).
- In-app notifications: bell with unread count in the top bar, inbox grouped by day, mark read, mark all read, permission re-check on open.
- Preferences per person and organization with per-project overrides: mute in-app types, "Also email me", daily digest, one-click unsubscribe per optional type.
- Provider abstraction: Laravel mailers per stream, Mailpit locally, a capture guard outside production, provider adapters for bounces and complaints.
- Suppression of optional mail on hard bounce or complaint, and a banner asking the person to update their email.
- Rate limits and coalescing so no event can flood an inbox.
- Email message log (no bodies) that M7 reads.

**Out of scope**

- Slack and webhooks (team-wide channels, M9).
- Choosing the email provider (D08). M8 ships the adapter interface, Mailpit and one reference adapter behind config; the production adapter is wired when the provider is chosen.
- The landing routes behind email links (verify, reset, accept invitation, confirm new email): built in M2. M8 builds the links.
- Languages other than English.
- Notification API endpoints for CLI or MCP.

## 2. Mockups covered

| Artboard | Email | Stream / sender | Notes and rules implied |
| --- | --- | --- | --- |
| 1a | Verify your email | account@ | "Unlocks after you confirm" list; link single-use (moot after verification), 24 hours |
| 1b | Someone tried to sign up with your email | account@ | Sent instead of 1a so signup never reveals an existing account |
| 1c | Reset your password | account@ | Request time and device (approximate location); link single-use, 1 hour; reset signs out everywhere |
| 1d | Password changed | account@ | When (local and UTC), device, location; "Reset password" |
| 1e | Confirm a new email address | account@ | Sent to the new address; From and To rows; 24 hours; old address stays until confirmed |
| 1f | Email changed (to old address) | account@ | New address partly masked (`m•••••••@acme.dev`); "Contact support" |
| 1g | New sign-in | account@ | Unrecognized device only |
| 1h | Recovery code used | account@ | Codes left; the same layout covers "two-factor turned on" and "turned off" |
| 1i | Account locked | account@ | Lock duration and unlock time |
| 2a | Invitation | notifications@ | Organization, project, role with description, inviter, expiry; always sent |
| 2b | Review requested | notifications@ | Environment (protected badge), revision from → to, change count; the free-text reason is not in the email and is shown in the portal (R17); "Approving by email isn't possible" |
| 2c | Your change was approved (optional, off by default) | notifications@ | Environment, change count, approved time; approval invalid if edited |
| 2d | Daily digest (optional) | notifications@ | Per project "yesterday": publish count, reviews waiting, past removal date, item list |
| 3a | Payment failed | billing@ | Plan, card brand and last four, grace end date |
| 3b | Plan changed to Free | billing@ | Over-limit effects list from M6's read-only projects and M9's paused-by-plan list; "Upgrade to Pro" |
| 3c | Project scheduled for deletion | notifications@ | Deletion time (UTC), behavior until and after; "Review deletion"; window is a D12 placeholder |
| Overview, Flags (top bar) | Bell icon with unread dot | in-app | No inbox panel or preferences screen is drawn; M8 designs them from the doc (§3.5) |

Common layout rules from the canvas: inbox line (sender, subject, preheader) above the message; header with "BitFlag" and a category label (Account, Security alert, Team, Review, Daily digest, Billing, Project); icon; headline; greeting; one or two paragraphs; a details table; one primary button; fine print; footer with the reason the person gets it, a manage link for optional mail, "BitFlag · Sent to {email} · Help · Privacy".

## 3. Design

### 3.1 Data model

All tables belong to the Notifications module.

**`notifications`** (in-app; deliberately not Laravel's database-channel schema)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `organization_id` | ulid | |
| `project_id` | ulid null | |
| `recipient_user_id` | ulid | |
| `type` | varchar(64) | From the catalog in §3.2 |
| `object_type` | varchar(32) | `change_set`, `revision`, `flag`, `webhook_endpoint`, `provider_connection`, `experiment`, `project`, `organization` |
| `object_id` | varchar(40) | Public ID (`chg_…`, `flg_…`) |
| `data` | json | Display-safe fields only (names, keys, counts, revision numbers, actor display name). Built from typed DTOs |
| `source_outbox_message_id` | ulid null | |
| `dedup_key` | varchar(191) | Unique with `recipient_user_id` |
| `read_at` | timestamp(6) null | |
| `created_at` | timestamp(6) | |

Indexes: `(organization_id, recipient_user_id, created_at)`, `(recipient_user_id, read_at)`, unique `(recipient_user_id, dedup_key)`.

**`notification_preferences`**

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | |
| `organization_id` | ulid | |
| `project_id` | ulid null | Null is the organization-wide setting; a row with a project overrides it |
| `user_id` | ulid | |
| `type` | varchar(64) | |
| `in_app_enabled` | bool | |
| `email_enabled` | bool null | Null for types without an email option |
| `updated_at` | timestamp(6) | |

Generated `project_scope` char(26) = `COALESCE(project_id, '')`. Unique `(organization_id, user_id, project_scope, type)`, so there is one organization-wide row per type (a unique key on the nullable `project_id` would allow duplicates). Missing rows mean catalog defaults.

**`notification_digest_settings`**: `id`, `organization_id`, `user_id` (unique pair), `enabled` bool, `send_at_local` time (default 08:00), `last_sent_for_date` date null, `updated_at`.

**`email_messages`** (the email message log; never stores a rendered body)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | ulid PK | Also used as the `Message-ID` local part and provider idempotency key |
| `organization_id` | ulid null | Null for account mail |
| `recipient_user_id` | ulid null | Null for invitees without an account and billing email addresses |
| `recipient_email` | varchar(320) | Encrypted at rest (`encrypted` cast); `recipient_email_hash` for lookups |
| `recipient_email_hash` | char(64) | SHA-256 of the normalized address |
| `type` | varchar(64) | Catalog key |
| `stream` | enum(`account`,`notifications`,`billing`) | |
| `mandatory` | bool | Account, security, billing, lifecycle and invitation mail |
| `template_data` | json null | Display-safe DTO for rendering; nulled after a terminal status |
| `dedup_key` | varchar(191) unique | |
| `source_outbox_message_id` | ulid null | |
| `status` | enum(`queued`,`sending`,`sent`,`delivered`,`bounced`,`complained`,`failed`,`suppressed`,`rate_limited`,`captured`) | |
| `provider` | varchar(32) null | |
| `provider_message_id` | varchar(255) null | Index |
| `attempts` | int | |
| `last_error_code` | varchar(64) null | |
| `queued_at`, `sent_at`, `delivered_at`, `bounced_at` | timestamp(6) | |

Indexes: `(recipient_user_id, queued_at)`, `(organization_id, queued_at)`, `(recipient_email_hash, queued_at)`, `(status, queued_at)`. Retention proposed 90 days (D09).

**`email_suppressions`**: `id`, `email_hash` char(64), `reason` enum(`hard_bounce`,`complaint`,`manual`), `scope` enum(`optional`) (only optional mail is ever suppressed), `source_email_message_id` null, `created_at`, `cleared_at` null. Index `(email_hash, cleared_at)`.

**`email_provider_events`**: `id`, `provider`, `provider_event_id` (unique with provider), `type` enum(`delivered`,`bounce_hard`,`bounce_soft`,`complaint`,`other`), `provider_message_id`, `payload` json (provider envelope, no message body), `status` enum(`received`,`processed`,`ignored`), `received_at`, `processed_at`.

**`users` (owned by M2; M8 adds through an Identity migration and action)**: `email_undeliverable_at` timestamp(6) null, `email_undeliverable_reason` varchar(32) null.

### 3.2 Domain rules and catalogs

**Channel rule (R10).** Email for account, security, invitations, review requests and billing problems; in-app for everything else that concerns a person; Slack and webhooks for team-wide events (M9). Mandatory mail ignores preferences and suppression lists (it still attempts delivery after a bounce).

**Message style rule.** Account and security emails produced by M2 and M3 use `internal.email.requested{template, …}`, which names the template directly. Notification, billing and support emails come from domain messages (R02 names such as `review.requested`, and `internal.*` messages such as `internal.billing.payment_failed` or `internal.support.mfa_reset_scheduled`) that M8 maps to its email catalog below. New producers follow the same split: account and security mail through `internal.email.requested`, everything else as a domain message.

**Email catalog** (`Notifications\Domain\EmailCatalog`; each entry names its Mailable, stream, trigger and recipients)

| Key | Mailable | Mockup | Stream | Trigger (outbox message) | Recipients | Optional | Link |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `account.verify_email` | `VerifyEmailMail` | 1a | account | `internal.email.requested{template: verify_email}` (M2) | The account | No | Signed verify URL, 24 h, minted at send |
| `account.signup_attempt_existing` | `SignupAttemptExistingMail` | 1b | account | `internal.email.requested{template: signup_attempt_existing}` | Existing account | No | Sign in, reset |
| `account.password_reset` | `PasswordResetMail` | 1c | account | `internal.email.requested{template: password_reset}` (carries the user ID, never a token) | Matching account, if any | No | Reset token minted at send by Identity `MintPasswordResetLink`, 1 h |
| `account.password_changed` | `PasswordChangedMail` | 1d | account | `internal.email.requested{template: password_changed}` | Account | No | Reset password |
| `account.email_change_confirm` | `EmailChangeConfirmMail` | 1e | account | `internal.email.requested{template: confirm_new_email}` | New address | No | Token minted at send by Identity `MintEmailChangeLink`, 24 h |
| `account.email_changed_notice` | `EmailChangedNoticeMail` | 1f | account | `internal.email.requested{template: email_changed}` | Old address | No | Contact support |
| `account.new_sign_in` | `NewSignInMail` | 1g | account | `internal.email.requested{template: new_signin}` | Account | No | Review sessions |
| `account.recovery_code_used` | `MfaChangedMail` (variant) | 1h | account | `internal.email.requested{template: recovery_code_used}` | Account | No | Set up two-factor |
| `account.mfa_enabled`, `account.mfa_disabled` | `MfaChangedMail` (variants) | 1h layout | account | `mfa_enabled`, `mfa_disabled` templates | Account | No | Account security |
| `account.locked` | `AccountLockedMail` | 1i | account | `internal.email.requested{template: account_locked}` | Account | No | Reset password |
| `account.mfa_reset_scheduled` | `MfaResetScheduledMail` | 1h layout | account | `internal.support.mfa_reset_scheduled` (M7) | Account | No | Single-use cancel link (M7 token) |
| `org.member_mfa_reset_scheduled` | `MemberMfaResetScheduledMail` | 1h layout | account | same | Org Owners of each organization of the person | No | Contact support |
| `account.mfa_reset_completed` | `MfaResetCompletedMail` | 1h layout | account | `internal.support.mfa_reset_completed` | Account and the same Org Owners | No | Set up two-factor |
| `team.invitation` | `InvitationMail` | 2a | notifications | `internal.email.requested{template: invitation}` (M2, on create when pending and on resend) | Invitee address | No | Accept link, token minted at send by Organizations `MintInvitationLink`, 7 days |
| `review.requested` | `ReviewRequestedMail` | 2b | notifications | `review.requested` (R02) | Eligible reviewers | Yes, default on | Open the review |
| `review.approved` | `ReviewDecidedMail` (approved) | 2c | notifications | `review.decided` | Contributors | Yes, default off | Publish (opens the change set) |
| `review.rejected` | `ReviewDecidedMail` (rejected) | 2c layout | notifications | `review.decided` | Contributors | Yes, default off | Open the change set |
| `flag.published` | `OwnedFlagPublishedMail` | 2c layout | notifications | `revision.published` (source publish, rollback), `internal.flag.emergency_disabled` | Flag owners | Yes, default off | Open the flag |
| `flag.reminder` | `OwnedFlagReminderMail` | 2c layout | notifications | `internal.notifications.flag_removal_date_passed` (M8 scan), `internal.flag.unused_detected` (M10) | Flag owners | Yes, default off | Open the flag |
| `digest.daily` | `DailyDigestMail` | 2d | notifications | Scheduler | People with the digest on | Yes, default off | Open BitFlag |
| `billing.payment_failed` | `PaymentFailedMail` | 3a | billing | `internal.billing.payment_failed` (M6) | Org Owners and `billing_email` | No | Update payment method (billing page) |
| `billing.plan_changed` | `PlanChangedMail` (to Free or to Pro) | 3b | billing | `internal.billing.plan_changed` | Org Owners and `billing_email` | No | Upgrade or billing page |
| `billing.subscription_ending` | `SubscriptionEndingMail` | 3b layout | billing | `internal.billing.subscription_ending` | Org Owners and `billing_email` | No | Billing page |
| `lifecycle.project_deletion_scheduled` | `ProjectDeletionScheduledMail` | 3c | notifications | `internal.email.requested{template: project_deletion_scheduled}` (M3) | Org Owners and project Owners | No | Review deletion |
| `lifecycle.organization_deletion_scheduled` | `OrganizationDeletionScheduledMail` | 3c layout | notifications | `internal.email.requested{template: organization_deletion_scheduled}` (M3 `ScheduleOrganizationDeletion`) | Org Owners | No | Review deletion |
| `org.suspended`, `org.unsuspended` | `OrganizationSuspensionMail` | 3c layout | notifications | `internal.organization.suspended`, `.unsuspended` (M7) | Org Owners | No | Contact support |

Producers that do not exist yet when M8 lands (M9, M10, M11) are handled by registering their message types now and testing with synthetic outbox messages.

**In-app catalog** (`Notifications\Domain\NotificationCatalog`)

| Type | Trigger | Default recipients | Email option (preference label) |
| --- | --- | --- | --- |
| `review.requested` | `review.requested` | Eligible reviewers | "Review requests", on |
| `changeset.decided` | `review.decided` | Contributors | "Approvals and rejections of my changes", off |
| `changeset.outcome` | `revision.published`, `internal.review.withdrawn` (M5) | Reviewers of that change set | none |
| `changeset.invalidated` | `internal.review.invalidated` (M5) | Contributors and reviewers | none |
| `flag.published` | `revision.published`, `internal.flag.emergency_disabled` (M5) | Flag owner (publish, rollback, emergency disable) | "Flags I own being published or rolled back", off |
| `flag.removal_date_passed` | daily scan over M4 `FlagAttentionQuery` | Flag owner | "Owned flag reminders", off |
| `flag.looks_unused` | `internal.flag.unused_detected` (M10) | Flag owner, with evidence and coverage caveats | "Owned flag reminders", off |
| `integration.failing` | `internal.webhook.failing`, `internal.webhook.auto_paused`, `internal.integration.degraded` (M9) | Project Owners | none |
| `integration.paused_by_plan` | `internal.integrations.plan_paused` (M9, after M6's `internal.entitlements.changed`) | Project Owners | none |
| `credential.expiring` | `internal.credential.expiring` (M3) | Credential creator and project Owners | none |
| `plan.limit_reached` | `internal.plan.limit_reached` (M6) | Org Owners and the blocked person | none |
| `experiment.sample_reached` | `internal.experiment.sample_reached` (M11) | Experiment owner | none |
| `experiment.attention` | `internal.experiment.guardrail_failed`, `internal.experiment.srm_detected` (M11) | Experiment owner | none |
| `membership.changed` | `internal.member.added`, `internal.member.role_changed` (M2) | That person | none |

Every in-app type can be muted. `review.requested` always creates an in-app notification, which is the fallback when the email is off.

**Recipient resolution** happens when the outbox message is consumed, with current permissions, through named queries: `Organizations\Queries\MembersWithCapability(project, environment, capability)`, `Organizations\Queries\OrgOwners(org)`, `Publishing\Queries\ChangeSetParticipants`, `Flags\Queries\FlagOwners`. Eligible reviewers are members with `changeset.approve` on the environment, minus the requester and other contributors (default until D02 decides self-approval). Recipients without `project.read` are skipped.

**Content rules (email-and-notifications §2).** Mail and notification data come from readonly DTOs in `Notifications\Data\Mail\*` and `Notifications\Data\InApp\*` with typed scalar fields only. Allowed: names, keys, environment and project names, roles, counts, revision numbers, times, actor display names, card brand and last four, masked addresses. Never: secrets, key values or prefixes, rule contents, targeting attributes or values, override subject keys, segment lists, JSON variation values. Review request emails carry environment, project, requester, change count, revision numbers and the requester's reason text (as drawn in 2b; see §8).

**Links and tokens.** M2 already mints secrets at send time in its `SendAccountEmail` consumer (signed verification URL, broker reset token, invitation token with only `token_hash` stored). M8 keeps that rule and moves the minting into owning-module classes that `DeliverEmailMessage` calls: `Identity\Mail\MintVerificationLink`, `MintPasswordResetLink`, `MintEmailChangeLink`, `Organizations\Mail\MintInvitationLink`. Plaintext tokens therefore never sit in the outbox, the email log or a queue payload. Re-sending mints a new token and invalidates the previous one. All other links are plain portal URLs where the viewer's permissions apply.

**Preference resolution** for (user, organization, project, type): project row, else organization row, else catalog default. Mandatory emails skip this. When the digest is on, optional emails are not sent individually; their content appears in the next digest. Security, account, billing and lifecycle mail is never bundled.

**Digest.** At `send_at_local` in the user's timezone, for each organization with the digest on, build per project: publications in the last 24 hours (count and list, through `Publishing\Queries\RecentRevisions`, permission-checked), reviews waiting for the person, owned flags past removal date, plus any optional email types the person turned on. Skip if empty. `last_sent_for_date` prevents duplicates.

**Unsubscribe.** Each optional email has a `List-Unsubscribe` header with a signed URL and `List-Unsubscribe-Post: List-Unsubscribe=One-Click`. The signed URL carries user, organization, project (if any) and type, does not expire, and can only turn that email type off. Unsubscribing never touches mandatory mail.

**Bounces and complaints.** Hard bounce or complaint → `email_suppressions` (`scope = optional`) and `users.email_undeliverable_at`; optional mail to that address becomes `suppressed`; mandatory mail still attempts. The banner clears when the person changes or re-verifies their email (`ClearEmailUndeliverable`). Soft bounces only update the message status. An invitation that hard-bounces is reported to Organizations (`internal.email.invitation_bounced`) so M2 can show "Email bounced" on the invitation row (proposed).

**Rate limits and coalescing** (Redis limiters in `Notifications\Domain\SendPolicy`)

| Rule | Limit | When exceeded |
| --- | --- | --- |
| Verification resend | 3 per account per hour | `rate_limited`, no send |
| Password reset | 5 per address per hour (M2 also limits the request) | `rate_limited` |
| New sign-in | 1 per device fingerprint per account | Deduplicated |
| Account locked | 1 per lockout period | Deduplicated |
| Review requested | 1 per change set and reviewer; a replacement candidate within 10 minutes is coalesced | Deduplicated |
| Optional notification mail | 30 per recipient per hour | Overflow goes to in-app only, then the digest if on |
| Per organization, notifications stream | 500 per hour (proposed) | Overflow in-app only, alert to operations |
| Provider throughput | `mail.provider_rate` per second, per stream | Job released with delay (Redis funnel) |

**Times.** `MailTime::format($instant, $user)` renders "6 Oct 2026, 17:42 PHT (09:42 UTC)" using the recipient's timezone (M2 `users.timezone`); recipients without an account get UTC only.

**Senders and streams.** `config/mail.php` defines mailers `account`, `notifications` and `billing`, each with its own from address (`account@`, `notifications@`, `billing@` on `MAIL_FROM_DOMAIN`, placeholder `mail.bitflag.example`) and provider stream (for example Postmark message streams or SES configuration sets), so a complaint on notification mail cannot affect password-reset deliverability. Sending domain needs SPF, DKIM and DMARC (operations task with D08).

### 3.3 Actions

| Action | Input | Capability | Transaction contents | Audit / outbox |
| --- | --- | --- | --- | --- |
| `ConsumeOutboxMessage` (implements M0's `OutboxConsumer`; `handles()` lists every trigger in §3.2, including `internal.email.requested`, which replaces M2's interim `SendAccountEmail` consumer) | outbox message | system | Resolve recipients and preferences; insert `notifications` and `email_messages` rows (dedup keys make replays no-ops); after commit dispatch `SendEmailMessage` jobs | none |
| `QueueEmail` | `QueueEmailData(type, recipient, organizationId, projectId, templateData DTO, dedupKey)` | system | Insert `email_messages` (`queued`, or `suppressed` / `rate_limited` by `SendPolicy`) | none |
| `DeliverEmailMessage` | email message ID | system | Lock row, skip if terminal; mint links through the owning module; render Mailable; capture guard; send via the stream's mailer; set `sent`, provider ID; null `template_data` | none |
| `MarkNotificationRead` | notification ID | recipient only | Set `read_at` | none |
| `MarkAllNotificationsRead` | organization ID | recipient only | Bulk `read_at` for the person in that organization | none |
| `UpdateNotificationPreferences` | `UpdateNotificationPreferencesData(organizationId, projectId?, rows[], digest?)` | self, member of the organization (and project if given) | Upsert preferences and digest settings | none (personal settings are not customer audit) |
| `UnsubscribeFromEmailType` | signed payload | signature | Upsert preference `email_enabled = false` | none |
| `RecordEmailProviderEvent` | verified provider webhook | provider signature | Insert `email_provider_events` (unique), push `internal.email.provider_event` | none |
| `ApplyEmailProviderEvent` | provider event ID | system | Update `email_messages` status; on hard bounce or complaint insert suppression and call Identity `MarkEmailUndeliverable` | none |
| `ClearEmailUndeliverable` (Identity module) | user ID | system, called by M2's email-change confirmation and email verification actions | Clears `email_undeliverable_at`; suppressions for the old hash are kept, the new address has none | none |
| `BuildDailyDigest` | user ID, organization ID, date | system | Build sections, `QueueEmail(digest.daily)`, set `last_sent_for_date` | none |
| `ScanFlagRemovalDates` | date | system | For flags whose expected removal date passed yesterday (M4 `FlagAttentionQuery`, reason past removal date), push `internal.notifications.flag_removal_date_passed` per flag | outbox |
| `PruneNotificationRecords` | none | system | Delete notifications older than 90 days and email log rows older than the retention window | none |

Actions never call `Mail::` or `Notification::` directly except `DeliverEmailMessage`.

### 3.4 Routes

Portal (`routes/web.php`, signed-in unless noted):

| Method and path | Controller | Page / response |
| --- | --- | --- |
| GET `/notifications` | `Notifications\Http\Controllers\Web\InboxController@index` | `notifications/Index` (full inbox for the current organization, filters Unread and All) |
| GET `/notifications/feed?organization={id}&cursor=` | `InboxController@feed` | JSON page for the bell (20 items, cursor pagination, grouped client-side by day in the viewer's timezone) |
| POST `/notifications/{notification}/read` | `InboxController@read` | `204` |
| POST `/notifications/read-all?organization={id}` | `InboxController@readAll` | `204` |
| GET `/notifications/{notification}/open` | `OpenNotificationController` | Re-checks access to the object; redirects to it, or renders `notifications/NoAccess` ("You no longer have access") |
| GET `/account/notifications?organization=&project=` | `PreferencesController@show` | `account/NotificationPreferences` |
| PUT `/account/notifications/{organization}` | `PreferencesController@update` | redirect back |
| GET `/unsubscribe/{payload}` (signed, no sign-in) | `UnsubscribeController@show` | `notifications/Unsubscribe` (confirm, with "Manage all notifications") |
| POST `/unsubscribe/{payload}` (signed, CSRF-exempt, no sign-in) | `UnsubscribeController@store` | `notifications/Unsubscribed`, or `200` for one-click |
| POST `/webhooks/email/{provider}` | `Notifications\Http\Controllers\Webhooks\EmailProviderWebhookController` | `200` / `400`; provider-specific verification |
| GET `/dev/mail`, `/dev/mail/{type}/{variant?}` (local only) | `MailPreviewController` | Renders every Mailable with fixture DTOs for design review against the mockup |

Shared Inertia props (M0 `HandleInertiaRequests`): `notifications: { unreadCount }` (cached per user and organization for 30 seconds, busted on write), `account: { emailUndeliverable }`.

No management API or SDK endpoints.

### 3.5 Frontend

No mockup exists for the inbox or preferences; both follow the Nocturne design system and the doc.

**Components** (`resources/js/features/notifications/`)

| Component | Behavior |
| --- | --- |
| `NotificationBell.vue` | Bell in the top bar (M0 shell) with unread dot and count; opens `NotificationInbox`; polls `unreadCount` every 60 seconds while the tab is visible |
| `NotificationInbox.vue` | Dropdown list grouped "Today", "Yesterday", then dates; unread styling; "Mark all read"; "View all" to `/notifications`; empty state "You're all caught up" |
| `NotificationItem.vue` | Icon by type, one-line text from `data`, relative time with exact UTC on hover; inaccessible items show "You no longer have access to this project" without other content |
| `PreferencesMatrix.vue` | Rows per type, columns "In-app" and "Also email me" (disabled where no email option), with a project selector for overrides and "Reset to organization default" |
| `DigestSettings.vue` | Toggle and local time picker; note "Replaces individual optional emails. Security and billing emails are never bundled." |
| `MandatoryEmailsNote.vue` | Lists account, security, billing and invitation emails as always on |
| `EmailUndeliverableBanner.vue` | App-wide banner: "We couldn't deliver email to {address}. Update your email." linking to account settings |

**Composables**: `useNotifications()` (feed loading, cursor, optimistic read state), `useUnreadCount()` (shared prop plus polling), `useNotificationPreferences()` (local edit state, dirty tracking, save).

**Pages**: `notifications/Index.vue`, `notifications/NoAccess.vue`, `notifications/Unsubscribe.vue`, `notifications/Unsubscribed.vue`, `account/NotificationPreferences.vue`.

**Email templates** (`resources/views/mail/`)

- `layout.blade.php` and `layout-text.blade.php`: the shared frame from the mockup (category label and icon, headline, body slot, details table slot, single button, fine print, footer).
- Components: `<x-mail.header category icon>`, `<x-mail.details :rows>`, `<x-mail.button :url>`, `<x-mail.fine-print>`, `<x-mail.footer variant="mandatory|optional|org-owners|invitation" :manage-url :email>`.
- `theme.css` generated at build time from the Nocturne tokens in `_ds/nocturne-*/styles.css`, with CSS variables resolved to literal values (email clients do not support variables) and inlined by Laravel's CSS inliner. Icons are inline PNGs or table-drawn shapes, never remote images that track opens.
- One Blade view pair (`html`, `text`) per Mailable in `resources/views/mail/{stream}/{key}.blade.php`. Text versions are written, not generated from HTML.
- Every Mailable sets a preheader (the inbox line in the mockup), the stream's from address, `Message-ID` from the `email_messages.id`, and, for optional mail, the unsubscribe headers.

### 3.6 Jobs, events, outbox consumers

| Job | Queue | Trigger | Notes |
| --- | --- | --- | --- |
| `SendEmailMessage` | `mail-account`, `mail-billing` (high), `mail-notifications` (normal) by stream | After `QueueEmail` commits | `DeliverEmailMessage`; 5 attempts with backoff 1, 5, 15, 60 minutes; unique per message ID |
| `ProcessEmailProviderEvent` | `mail-events` | outbox `internal.email.provider_event` | `ApplyEmailProviderEvent` |
| `DispatchDailyDigests` | `mail-digest` (low) | scheduler, every 15 minutes | Finds people whose local send time falls in the window and dispatches `BuildDailyDigestJob` |
| `ScanFlagRemovalDatesJob` | `default` | scheduler, daily 00:30 UTC | `ScanFlagRemovalDates` |
| `PruneNotificationRecordsJob` | `default` | scheduler, daily | `PruneNotificationRecords` |

Horizon supervisors keep the account and billing queues isolated from notification and digest volume. The `mail-*` queues are additions to M0's queue list (`publication`, `default`, `notifications`, `integrations`, `telemetry`); `ConsumeOutboxMessage` itself runs on M0's `notifications` queue.

**Provider abstraction**

- Sending uses Laravel mail transports (SMTP for Mailpit, and the chosen provider's transport later). Per-stream mailers make the stream explicit.
- `Notifications\Providers\EmailProviderAdapter` interface: `streamHeaders(stream): array`, `verifyWebhook(Request): bool`, `parseEvents(Request): list<ProviderEventData>`. Implementations: `MailpitAdapter` (no webhooks; marks messages `delivered` on send in local), `PostmarkAdapter` (reference implementation behind config, used for contract tests with recorded fixtures). SES and Resend adapters are added only if chosen.
- `CaptureGuard`: outside production, any recipient not on `mail.capture.allowlist` (domains) is rewritten to `mail.capture.inbox` and the message is marked `captured`. Production refuses to boot if `mail.capture.enabled` is true and no inbox is set.

### 3.7 Security and tenant isolation

- **Account existence:** signup and reset requests always push their outbox message and return the same response; the consumer decides between 1a and 1b, or sends nothing for an unknown reset address. No timing difference arises in the request because nothing is sent there.
- **No secrets at rest:** tokens are minted in the send job; `template_data` holds DTOs without tokens and is nulled after sending; queue payloads carry only the message ID.
- **Content scanning:** a test renders every Mailable and every in-app type with sentinel values planted in rules, overrides, keys, context and webhook secrets, and asserts no sentinel appears.
- **Permissions on open:** notifications store references, not content; `/open` re-authorizes with the object's policy; the inbox hides content for projects the person no longer belongs to.
- **Tenant scoping:** inbox and preference queries scope by `organization_id` and the signed-in user; a notification ID from another user returns `404`.
- **Unsubscribe links:** signed with the app key, scoped to one type, and only able to turn email off.
- **Provider webhooks:** verified per adapter (HTTP basic auth plus IP allowlist for Postmark, SNS signature for SES, HMAC for Resend), deduplicated by provider event ID.
- **Development and staging** never reach real customer addresses (`CaptureGuard`, tested).
- **Masking:** 1f masks the new address to first character and domain.

## 4. Implementation steps

1. **Mail foundation.** Per-stream mailers in `config/mail.php`, `MAIL_FROM_DOMAIN`, Mailpit in Sail (if M0 has not added it), `CaptureGuard`, `MailTime`, Nocturne `theme.css` build step, `layout` and components, `MailPreviewController`.
2. **Message log and send path.** `email_messages`, `email_suppressions`, `QueueEmail`, `DeliverEmailMessage`, `SendEmailMessage`, `SendPolicy`, Horizon queues; architecture test that only `DeliverEmailMessage` sends mail.
3. **Account and security emails (1a–1i).** Mailables and views for every M2 template; move M2's minting into the `Mint*` classes; register `ConsumeOutboxMessage` for `internal.email.requested` and remove M2's interim `SendAccountEmail` consumer and plain-text notifications (the outbox contract does not change).
4. **Invitations and lifecycle (2a, 3c).** `InvitationMail` with `MintInvitationLink`, project and organization deletion mails, suspension mails.
5. **In-app foundation.** `notifications`, `ConsumeOutboxMessage` with the catalog and recipient queries, inbox routes, `NotificationBell`, `NotificationInbox`, `NotificationItem`, `notifications/Index`, `NoAccess`.
6. **Review notifications (2b, 2c).** `review.requested`, `review.decided`, `changeset.outcome`, `flag.published` in-app and optional emails; coalescing.
7. **Preferences and unsubscribe.** `notification_preferences`, `UpdateNotificationPreferences`, `account/NotificationPreferences`, `PreferencesMatrix`, unsubscribe routes and headers.
8. **Digest (2d).** `notification_digest_settings`, `DispatchDailyDigests`, `BuildDailyDigest`, `DailyDigestMail`, `DigestSettings`.
9. **Billing emails (3a, 3b) and plan notifications.** Mailables for M6 messages including subscription ending and upgrade confirmation; `plan.limit_reached`, `integration.paused_by_plan` in-app.
10. **Support emails.** M7 MFA reset scheduled, cancelled, completed; Org Owner notice.
11. **Bounces and complaints.** `email_provider_events`, webhook controller, `EmailProviderAdapter`, `PostmarkAdapter` with fixtures, `ApplyEmailProviderEvent`, `users.email_undeliverable_at`, `EmailUndeliverableBanner`.
12. **Remaining producers and cleanup.** `ScanFlagRemovalDates`, registrations for M9, M10, M11 types with synthetic tests, pruning job, Cypress specs.

## 5. Tests

**Unit (`tests/Unit/Notifications/`)**: `EmailCatalogTest` (every key has Mailable, stream, trigger, views, and a mockup or layout reference; mandatory flags match the doc); `PreferenceResolutionTest` (project over organization over default; mandatory ignores); `SendPolicyTest` (each limit and coalescing rule with a fixed clock); `MailTimeTest` (PHT and UTC formatting, no timezone); `EmailMaskTest`.

**Feature (`tests/Feature/Notifications/`, real MySQL and Redis)**

- `NoInlineSendTest`: every M2 account action, run in a request, leaves `Mail::fake()` empty and pushes one outbox message; only the worker sends.
- `AccountExistenceTest`: signup with a new and an existing address returns identical responses; the worker sends 1a and 1b respectively; reset for an unknown address sends nothing.
- `SecurityLinksTest`: reset link works once and expires after 1 hour; email change link 24 hours; resend invalidates the previous link; no token appears in `outbox_messages`, `email_messages` or serialized jobs.
- `EmailContentSafetyTest`: renders every catalog entry (HTML and text) with sentinel data; no sentinel in output; review request contains only environment, project, requester, change count and revisions, and never the reason text.
- `MailSnapshotTest`: HTML and text snapshots of every Mailable with fixture DTOs and a frozen clock.
- `IdempotentSendTest`: consuming the same outbox message twice creates one `email_messages` row and one send; a job retry after a provider timeout reuses the message ID.
- `RecipientResolutionTest`: review requests go to members with `changeset.approve` on that environment, not to the requester, Developers or Viewers; billing mail goes to Org Owners and `billing_email`; deletion mail to Org Owners and project Owners.
- `PreferencesTest`: muting an in-app type stops new rows; "Also email me" sends; project override wins; digest on suppresses individual optional emails and includes them in the digest.
- `UnsubscribeTest`: one-click POST turns off only that type; mandatory mail still sends afterwards; tampered signature is rejected.
- `BounceHandlingTest`: hard bounce fixture → suppression, banner flag, next optional email `suppressed`, next password reset still sent; complaint same; soft bounce only updates status; duplicate provider event ignored; forged webhook `400`.
- `PermissionOnOpenTest`: notification created, membership removed, `/open` renders `NoAccess`; inbox shows the item without content.
- `InboxIsolationTest`: another user's notification ID returns `404`; `read-all` touches only the caller's rows in that organization.
- `CaptureGuardTest`: with capture on, a customer address is rewritten and marked `captured`; production config with capture on and no inbox fails to boot.
- `DigestTest`: local 08:00 in Asia/Manila; empty digest skipped; no duplicate for the same date; content permission-checked.
- `RateLimitTest`: 4th verification resend in an hour is `rate_limited`; review request coalescing within 10 minutes.

**Architecture (`tests/Arch/NotificationsArchTest.php`)**: `Mail`, `Notification` facades and `Mailable::send` used only in `Notifications\Actions\DeliverEmailMessage`; `Notifications\Data` classes do not use `Flags`, `Targeting` or `Credentials` models; Mailables accept only `Notifications\Data\Mail` DTOs.

**Vitest (`resources/js/features/notifications/__tests__/`)**: `NotificationInbox` grouping by day across a timezone boundary; unread styling and "Mark all read" optimistic update; `NotificationItem` no-access rendering; `PreferencesMatrix` override and reset; `useUnreadCount` polling pauses when hidden.

**Cypress (`cypress/e2e/`)**, reading mail through the Mailpit API with a `cy.mailpit` command:

- `m8-email-account.cy.ts`: forgot password, open the reset email in Mailpit, reset, receive 1d.
- `m8-email-review-request.cy.ts`: Priya submits a change set for production; Jon receives 2b; the email shows no rule text; the button opens the review.
- `m8-notifications-bell.cy.ts`: Jon approves; Priya sees the unread dot, opens the inbox, the item opens the change set, "Mark all read" clears the dot.
- `m8-notification-preferences.cy.ts`: turn on "Approvals and rejections" for Storefront only; approval sends 2c; turn on the digest; next optional email is held for the digest.
- `m8-unsubscribe.cy.ts`: open the one-click link from 2c in Mailpit; preference shows off; a password reset still arrives.

**Fixtures**: `tests/Fixtures/mail/*.php` (DTO fixtures per catalog key, shared with the preview route), `tests/Fixtures/email-provider/postmark/{delivery,bounce_hard,bounce_soft,spam_complaint}.json`.

## 6. Decisions

| Item | Status | Default assumed |
| --- | --- | --- |
| R10 Channel split | Resolved | As in §3.2 |
| R11 Sign-in | Resolved | Account emails cover password and MFA only; no social sign-in mails |
| R07 Org roles | Resolved | Billing mail to Org Owners plus `billing_email`; a future Billing Admin would be added as a recipient |
| Email provider (D08) | Open | Mailpit locally; `PostmarkAdapter` as the reference adapter behind config; capture inbox in staging |
| Sender addresses and domain | Open | `account@`, `notifications@`, `billing@` on `MAIL_FROM_DOMAIN` (`mail.bitflag.example` placeholder) |
| D09 Retention | Open | Notifications 90 days, email log 90 days, provider events 90 days |
| D02 Self-approval | Open | Requester and contributors do not receive review-request mail |
| D12 Deletion window | Open | Email shows whatever date M3 scheduled; copy does not hard-code 7 or 30 days |
| Digest time | Not decided | 08:00 local, per person and organization |
| Per-organization notification cap | Not decided | 500 per hour |

## 7. Done criteria

- Every artboard 1a–3c has a Mailable, HTML and text views, a preview at `/dev/mail`, and a snapshot test; every row of email-and-notifications §2 has a catalog entry.
- No email is sent inside a request (`NoInlineSendTest`, architecture test).
- `EmailContentSafetyTest` passes for every email and in-app type.
- Unsubscribing from an optional type never stops security, account or billing mail.
- Permission loss between notification and click hides the content.
- Bounce and complaint fixtures drive suppression and the banner.
- Staging cannot email a non-allowlisted address.
- Pest, PHPStan, Vitest and the five Cypress specs pass in CI.

## 8. Risks and open questions

- **`billing@` sender.** The Emails mockup sends 3a and 3b from `billing@`; email-and-notifications §5 describes only two streams (account and notification). Built as a third stream; confirm, and confirm it should be isolated like account mail.
- **Invitation footer (2a).** It says "You get this because of your Storefront notification settings. Manage notifications", but invitations cannot be turned off and the invitee may have no account. Built with an invitation-specific footer ("{inviter} invited this address"). Confirm.
- **Project deletion email (3c).** Sent from `notifications@` but marked "can't be turned off", and the footer says Org Owners only while the doc says Org Owners and project Owners. Built as mandatory, to both groups, from `notifications@`. The mockup shows 30 days (6 Oct to 5 Nov) while Settings 2i says 7 days; D12.
- **Preferences scope.** The doc says "per person, per organization"; the mockup footers say "your Storefront notification settings" (per project). Built as organization defaults with per-project overrides; confirm this is wanted rather than one level.
- **Review request content.** The doc limits it to environment, project, requester and change count; 2b also shows revision numbers and the free-text reason. Reason text is written by the requester and could contain targeting values ("Start PH premium rollout at 20%"). Resolved (R17): the reason stays in the portal, behind permissions; the email omits it.
- **Undrawn emails:** subscription ending, upgrade confirmation (2c in Organization promises "we'll email you either way"), organization deletion, MFA enabled and disabled variants, owned flag published and reminders, MFA reset and suspension mails. Built on the drawn layouts; they need a design pass.
- **No inbox or preferences mockups.** The bell exists in the top bar mockups but no panel, full inbox or preferences screen is drawn. Built from the doc; needs design review before Cypress screenshots are treated as acceptance.
- **Location in security emails** ("Manila (approximate)") depends on an IP geolocation source M2 must choose; without it, the row is omitted.
- **Provider choice (D08)** affects bounce webhook verification and stream separation. The adapter interface keeps this isolated, but the reference adapter may be thrown away.
