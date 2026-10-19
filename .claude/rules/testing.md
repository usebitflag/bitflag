# Testing

## Principles

- Database tests run on real MySQL and Redis, never SQLite only.
- The tenant query guard is on for every feature test. A query on a tenant table without an `organization_id`
  predicate fails the test.
- A new tenant table gets an `assertTenantIsolated(Model::class, fn (Organization $organization) => …)` test (see
  `tests/Feature/Tenancy/IsolationTest.php`) and a classification in `config/tenancy.php`.
- Feature tests run on `RefreshDatabase`. Tables that only tests need go in `tests/Support/migrations`, never in a test body.
- A new write action gets tests for authorization, tenant isolation, version conflict, and rollback when the audit
  or outbox insert fails.
- External providers are never called. Use fakes or the in-process stubs.

## Layers

| Layer | Tool | Location |
| --- | --- | --- |
| Unit and feature | Pest | `tests/Unit`, `tests/Feature/{Module}` |
| Architecture | Pest, group `arch` | `tests/Arch` |
| Static analysis | PHPStan + Larastan (max), Pint | CI `backend-lint` |
| Frontend | Vitest, `vue-tsc` | `resources/js/**/*.spec.ts` |
| Workflows | Cypress against Sail | `cypress/e2e/{area}-{flow}.cy.ts` |

## Architecture rules and canaries

- Every architecture rule has a canary in `tests/Arch/Canaries/*.php.txt`. A new rule needs a new canary; the
  `arch-canary` job fails if a canary passes.
- Do not weaken or skip an architecture test to make a change pass. Fix the change.
- Declare a Pest architecture rule once per namespace. An expectation over several namespaces at once can pass without
  catching a violation; the canaries exist to show that.
- Pest only sees classes that exist. Rules about code that may not be installed (such as the evaluator package) read
  source text instead.

## Cypress

- Specs run against the demo seed (Acme with Storefront and Checkout API; Maya, Jon, Priya, Ana, Leo; Globex for isolation).
- Use `data-test` selectors. Freeze time with the testing clock route rather than waiting.
- Testing routes exist only locally and in CI; a production-boot test asserts they are absent.

## What to test

- New error mappings, enum value stability (stored values), parsers on empty and malformed input, and permission
  differences between roles (cell by cell against the conventions table).
- Do not test trivial getters or plain struct construction.
