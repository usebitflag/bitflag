# Code Style

## PHP

- Pint for formatting, PHPStan + Larastan at max level. Four spaces for indentation.
- Actions are `final`, named as a verb phrase (`SaveFlagDraft`, `RevokeCredential`), with one public `handle()`.
- Inputs and results are `readonly` classes in `Data/`, named `{Action}Data` and `{Action}Result`.
- No `dd`, `dump`, `ray` or `var_dump`. `env()` only inside `config/`.
- Lifecycle is an explicit `status` column. No soft deletes for domain records.
- Timestamps are UTC `timestamp(6)`. Migrations stay globally ordered in `database/migrations`.
- A fixed-value column is `$table->string('status', 32)` with a backed PHP enum cast on the model, never
  `$table->enum()` or `set()` (an architecture test enforces it). Add values freely; never change or remove an
  existing value without a data migration.
- Dependencies point downward: `Shared` knows no module; a module's `Domain` knows no framework HTTP types.

## TypeScript and Vue

- Vue 3 `<script setup lang="ts">`. Two spaces for indentation. Strict TypeScript, no `any` without a comment.
- Page components compose; state and rules live in composables and `features/`. A component never imports a page.
- See `.claude/rules/frontend.md` for theme, components and accessibility.

## Naming

- Tables and columns: snake_case; tenant tables have `organization_id` first among foreign keys.
- Audit actions: `{object}.{verb}` past tense. Outbox types: webhook event names or `internal.*`.
- Tests: `tests/Feature/{Module}/…`, Cypress `cypress/e2e/{area}-{flow}.cy.ts`.

## Documentation

- Technical documentation lives in `docs/`. Do not put agent instructions or planning notes there; those belong in
  `AGENTS.md` and `.claude/rules/`.
- When a change alters a contract or behavior, update the affected document in `docs/` in the same pull request.
