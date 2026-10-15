# Email and notifications

BitFlag tells people about things in three ways: transactional email, in-app notifications, and integrations the customer configures (Slack and webhooks, covered in [API and integrations](api-and-integrations.md)). This document covers the first two.

## 1. Channel rule

| Channel | Used for |
| --- | --- |
| Email | Account and security messages, invitations, review requests, billing problems |
| In-app | Everything else that concerns a person: reviews decided, publications they care about, flag reminders, failing webhooks, limits |
| Slack / webhooks | Team-wide events, configured per project; not personal notifications |

Each person controls their own in-app and optional email notifications. Security, account, and billing emails cannot be turned off.

## 2. Emails

| Email | Sent to | Can be disabled |
| --- | --- | --- |
| Verify your email | New account | No |
| Someone tried to sign up with your email | Existing account | No |
| Reset your password / password changed | Account | No |
| Email address changed (to old and new address) | Account | No |
| New sign-in from an unrecognized device | Account | No |
| MFA enabled, disabled, or recovery code used | Account | No |
| Account locked after failed attempts | Account | No |
| You're invited to {project} at {organization} | Invitee | No |
| Review requested: {environment} in {project} | Eligible reviewers (Owners and Publishers) | Yes, falls back to in-app |
| Payment failed / subscription ending / plan changed | Org Owners | No |
| Organization or project scheduled for deletion | Org Owners, project Owners | No |

**Content rules:** no secrets, key values, rule contents, or targeting attributes in any email. Emails link into the portal, where the viewer's permissions apply. Review-request emails show the environment, project, requester, and change count only; the requester's reason is shown in the portal behind permissions. Links in security emails are single-use and expire.

## 3. In-app notifications

A bell in the top bar opens a list grouped by day, with unread state and "mark all read".

| Notification | Default recipients |
| --- | --- |
| Your change set was approved / rejected | Contributors |
| A change set you reviewed was published or withdrawn | Reviewer |
| A flag you own was published, rolled back, or emergency-disabled | Flag owner |
| A temporary flag you own passed its removal date | Flag owner |
| A flag you own looks unused (with evidence and coverage caveats) | Flag owner |
| A webhook or integration is failing or was paused by plan | Project Owners |
| A plan limit was reached | Org Owners and the person blocked |
| An experiment you own reached its planned sample size | Experiment owner |
| You were added to a project or your role changed | That person |

Notifications reference objects by link and re-check permissions when opened; a person who lost access sees "You no longer have access" rather than content.

## 4. Preferences

Per person, per organization:

- Each in-app notification type can be muted.
- "Also email me" can be turned on for: review requests (on by default), approvals and rejections of my changes, flags I own being published or rolled back, and owned flag reminders.
- An optional daily digest replaces individual optional emails.
- Every optional email has a one-click unsubscribe link for that type.

## 5. Delivery

- **Provider:** a transactional email service, behind a provider adapter. A dedicated sending domain with SPF, DKIM, and DMARC, and separate streams for account mail and notification mail, so a notification complaint cannot hurt password-reset deliverability.
- **Sending path:** every email is queued through the outbox ([publishing and security](publishing-and-security.md) §3), never sent inside a request transaction. Sends are idempotent per message ID.
- **Bounces and complaints:** hard bounces and complaints mark the address undeliverable for optional mail and show a banner asking the person to update their email; security mail still attempts delivery.
- **Templates:** plain, text-first, with an HTML version in the product style; times shown in the recipient's timezone with UTC alongside.
- **Development and staging:** never send to real customer addresses; route to a capture inbox.
- **Language:** English.

## 6. Domain records

Notification (recipient, organization, type, object reference, created, read), notification preferences (per person and organization), and email message log (type, recipient, provider message ID, status, without body content). Logs are retained for 90 days.

## 7. Verification

- Account-existence is never revealed by signup or reset emails.
- No email body contains a secret, rule content, or context attribute.
- Unsubscribing from an optional type never stops security, account, or billing mail.
- Permission loss between notification and click hides the content.
