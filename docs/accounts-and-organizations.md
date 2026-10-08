# Accounts and organizations

[Documentation index](README.md) · Status: proposed design for hosted signup, sign-in, and organization administration; screens for these flows are not yet drawn

This document covers how people get into the hosted product and how organizations are run: signup, verification, sign-in and MFA, recovery, sessions, organization roles, invitations, and the first-run path to a working flag. It replaces the blueprint's self-hosted install setup (R06). Data definitions are in the [domain model](domain-model.md); credential handling and session security are in [publishing and security](publishing-and-security.md).

## 1. Decisions this document applies

| Decision | Summary |
| --- | --- |
| R01 | Project roles: Owner, Publisher, Developer, Viewer |
| R07 | Organization roles: Org Owner and Member |
| R08 | Every new organization starts on the Free plan |
| R10 | Account messages are sent by email |
| R11 | Email and password with MFA at launch; no social sign-in or company SSO |

## 2. Signup

```text
Website "Start free" → Create account → Verify email → Create organization → Create first project → Onboarding checklist
```

1. **Create account:** name, work email, password. Passwords are checked for length and against known-breached lists rather than composition rules. The response never reveals whether the email already has an account: an existing address receives a "someone tried to sign up with your email" message instead.
2. **Verify email:** a single-use link that expires (proposed: 24 hours). Until verified, the person can see the onboarding screens but cannot invite anyone, create keys, or publish.
3. **Create organization:** name and slug (globally unique, editable later with a bookmark warning). The creator becomes Org Owner. The organization starts on the Free plan (R08).
4. **Create first project:** name, slug, and default environments `development`, `staging`, `production`, editable before creation. On Free the third environment fits the 3-environment limit exactly.
5. **Onboarding checklist** on the project Overview: create a flag → configure it → publish → create an SDK key → install an SDK → see a real evaluation. The last step completes only when BitFlag observes an evaluation from that key, not when the snippet is copied ([frontend and design](frontend-and-design.md) §4).

A person invited to an existing organization skips steps 3–4 (see §6). Abuse controls on signup (rate limits, disposable-domain checks, CAPTCHA only when risk signals warrant it) are an operations choice, not a product step.

## 3. Sign-in and MFA

- **Email and password** with generic failure messages ("That email and password don't match").
- **MFA:** authenticator app (TOTP) at launch, with 10 single-use recovery codes shown once. Security keys (WebAuthn) are a candidate follow-up. MFA is available on every plan and never gated (R08).
- **Organization MFA requirement:** an Org Owner can require MFA for all members. Members without MFA are asked to enroll at their next sign-in before they can open that organization.
- **Lockout:** progressive delays and temporary lockout after repeated failures, with an email notice to the account owner.
- **Choose organization:** after sign-in, a person in several organizations picks one (the existing Auth mockup 3e). The last-used organization is remembered.
- No social sign-in and no company SSO at launch (R11). Their addition later must not create a second account for the same email.

## 4. Recovery and account changes

| Flow | Behavior |
| --- | --- |
| Forgot password | Same response whether or not the account exists; single-use link (proposed 1 hour); all other sessions revoked after reset |
| Lost MFA device | Sign in with a recovery code, then re-enroll. Without codes, recovery goes through support with identity checks; support never disables MFA silently ([support and admin](support-and-admin.md)) |
| Change email | Confirm from the new address; notify the old address |
| Change password | Requires current password; revokes other sessions |
| Delete account | Blocked while the person is the last Org Owner of any organization; otherwise removes memberships and personal tokens, keeps audit attribution as a stable display name |

Every flow above sends the email listed in [email and notifications](email-and-notifications.md) §2.

## 5. Sessions

Secure, HttpOnly session cookies scoped to the app host, with CSRF protection ([publishing and security](publishing-and-security.md) §6). Account settings list active sessions (device, location estimate, last active) and allow revoking any or all. Proposed defaults: idle timeout 7 days, absolute lifetime 30 days, shorter if an organization sets a stricter session policy. The Auth mockup's "Session expired" state (3d) returns the person to the page they were on after signing in again, without losing unsaved editor state where the browser still holds it.

## 6. Organizations, membership, and invitations

**Roles (R07, R01):**

| Level | Role | Can |
| --- | --- | --- |
| Organization | Org Owner | Billing and plan, organization members, organization security (MFA requirement, session policy), create projects, delete or rename the organization |
| Organization | Member | See only projects they belong to |
| Project | Owner / Publisher / Developer / Viewer | As in R01 |

An Org Owner is not automatically a project member; they can add themselves to any project, and that action is audited. A project always has at least one project Owner; an organization always has at least one Org Owner.

**Invitations** are sent from a project's Members tab (as drawn in Settings 2b) or from organization Members. An invitation names the project and project role; accepting it also makes the person an organization Member. Links expire after 7 days (as drawn) and can be resent or revoked. Accepting with a different email address than the invitation was sent to is refused.

**Removing a member** revokes their sessions in that organization and their personal access tokens' access to it. Project management tokens they created keep working (R03); Owners see "created by a former member" so they can decide whether to rotate.

## 7. Organization settings

Proposed sections, reached from the "Organization settings" link in the sidebar:

| Section | Contents |
| --- | --- |
| General | Name, slug, organization deletion (typed confirmation, blocked while a paid subscription is active) |
| Members | Everyone in the organization, their org role, projects and project roles, last active; invite, change role, remove |
| Projects | All projects with owners and environment count; create project (subject to plan limits) |
| Billing | Plan, usage against limits, payment method, invoices, upgrade/downgrade ([billing and plans](billing-and-plans.md)) |
| Security | Require MFA, session policy, organization audit (membership, billing, and security events) |

These screens need mockups before implementation; none exist yet. Organization deletion timing, export, and transfer of ownership between organizations remain **D12**.

## 8. Audit

Sign-in failures and lockouts are security logs, not customer audit entries. Customer-visible audit records cover organization and project membership changes, role changes, MFA requirement changes, plan changes, organization rename/deletion scheduling, and Org Owner self-adds to projects. Staff actions from the support console are recorded with channel `support` ([support and admin](support-and-admin.md)).

## 9. Open points

| Topic | Where |
| --- | --- |
| Session timeout defaults, lockout thresholds | This document (proposed values) |
| Security keys (WebAuthn) after launch | Product follow-up |
| Organization deletion window, export, transfer | D12 |
| SSO, SCIM, and how they map to org roles | With Enterprise (R08, R11) |
