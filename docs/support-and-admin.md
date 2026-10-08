# Support and admin console

[Documentation index](README.md) · Status: proposed design; scope decided (R12)

BitFlag staff need to answer "why isn't my flag updating?", "why was I charged?", and "I lost my MFA device" without database access and without seeing more customer data than the question needs. This document defines the internal admin console that does that. It is not a customer feature and is not part of the portal's navigation.

## 1. Principles (R12)

- **No impersonation.** Staff never act as a customer or see the portal as a customer sees it.
- **Read first.** Almost everything is a lookup. The few write actions are listed in §4 and each is audited.
- **Least data.** Staff see metadata (names, counts, states, timestamps, error codes), not flag rule contents, targeting attributes, override subject keys, telemetry subject data, or secrets.
- **Everything is logged** in a staff audit log, and customer-affecting actions also appear in the customer's audit with channel `support`.

## 2. Access

- Separate staff identities, not customer accounts. Staff sign-in requires MFA (security keys preferred) and is restricted to the company network or identity provider.
- Roles: **Support** (lookups and support actions) and **Billing support** (adds billing lookups). Engineering on-call uses operational dashboards and runbooks ([operations and release](operations-and-release.md) §6), not this console, for infrastructure work.
- Hosted inside the `bitflag` application as a separate route group and policy set, on a separate host name, never reachable from the customer portal.

## 3. Lookups

| Area | What staff can see |
| --- | --- |
| Find | Search by organization name or slug, user email, project slug, or a public ID/request ID from an error message |
| Organization | Plan, status, limits vs usage, Org Owners, member count, created date, MFA requirement, deletion state |
| User | Organizations and roles, verification state, MFA enrolled (yes/no), last sign-in, lockout state, recent security events |
| Project / environment | Flag, segment, and environment counts; current revision number and time per environment; protection on/off |
| Delivery health | Outbox backlog and oldest pending age for the tenant, recent publication delivery status, SDK key last-used times, observed SDK versions and revisions, recent `401`/`429` rates |
| Webhooks and integrations | Endpoint and connection states, recent delivery attempts with status codes and error classes (no payload or response bodies) |
| Billing | Stripe customer and subscription state, recent invoices and payment attempts, entitlement history (Billing support only) |
| Email | Recent emails to a user: type, time, delivery status (no bodies) |

## 4. Support actions

| Action | Guardrails | Customer audit |
| --- | --- | --- |
| Resend verification or invitation email | None beyond rate limits | Yes |
| Clear a sign-in lockout | Ticket reference required | Yes |
| Reset MFA after identity verification | Two staff members (requester and approver), ticket reference, 24-hour delay with email to the account and Org Owners before it takes effect | Yes |
| Replay failed outbox or webhook deliveries | Same replay rules as the customer action; idempotent | Yes |
| Apply a billing credit or extend a grace period | Billing support; performed through Stripe with BitFlag reconciling | Yes |
| Suspend an organization for abuse or non-payment past policy | Two staff members, documented reason. Suspension blocks management actions only; published flags keep serving, as billing rules require | Yes |

Staff cannot: edit flags, segments, environments, or members; publish or roll back; create, view, or rotate keys; change roles; delete organizations or data outside the documented deletion process (D12); disable MFA without the procedure above.

## 5. Staff audit

Every console view of an organization or user, every search, and every action is recorded with staff identity, ticket reference where required, time, and target. The log is append-only, reviewed periodically, and retained under D09. Customer-visible entries use actor "BitFlag support" with channel `support`, so the audit channel list becomes `dashboard`, `api`, `cli`, `mcp`, `system`, `support`.

## 6. What customers see

- Support contact: documentation and community on Free, email on Pro (R08).
- Errors in the portal, API, CLI, and MCP include a request ID that staff can search.
- The customer's audit log shows any support action taken on their organization.

## 7. Verification

- Console routes reject customer sessions and customer tokens, and portal routes reject staff sessions.
- No console response contains rule contents, override keys, context attributes, payloads, or secrets.
- Two-person actions cannot be completed by one staff identity.
- Every console action produces staff audit entries; customer-affecting ones also produce customer audit entries.
