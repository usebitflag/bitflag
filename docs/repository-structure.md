# BitFlag repository structure

[Documentation index](README.md)

## Separate repositories

Every row below is a separate Git repository with its own lockfiles, CI, release tags and access settings.

| Repository | Owns | Runtime dependencies |
| --- | --- | --- |
| `bitflag` | Laravel backend and Inertia/Vue portal, management API, publishing, delivery and workers | Released PHP SDK evaluation core; MySQL and Redis |
| `bitflag-website` | Astro marketing website | Public content and optional frontend components |
| `bitflag-docs` | Astro/Starlight customer documentation | Released API/protocol references and SDK examples |
| `bitflag-js` | TypeScript evaluator, Node/browser transport, caching, telemetry API | Released delivery contract |
| `bitflag-php` | Pure PHP evaluator, PHP SDK transport/cache adapters and result types | Released delivery contract; approved PSR interfaces |
| `bitflag-laravel` | Laravel service provider, configuration, context bindings and helpers | Released `bitflag-php` |
| `bitflag-vue` | Provider, composables, reactive subscriptions and render helpers | Released `bitflag-js` |
| `bitflag-react` | Provider, hooks, external-store subscriptions and render helpers | Released `bitflag-js` |
| `bitflag-cli` | Human/CI management client | Versioned management API |
| `bitflag-mcp` | MCP transport, tool contracts and management API adapter | Versioned management API |
| `bitflag-protocol` | Delivery schemas, event contracts, algorithm specification and canonical conformance fixtures | None of the application runtimes |

The portal frontend belongs inside `bitflag` because it is one Inertia application. It is not the consumer-facing Vue SDK. Hosted-only operation does not determine whether a repository is public or private.

## Main application

```text
bitflag/
├── app/
│   ├── Http/Middleware/
│   ├── Modules/
│   │   ├── Identity/
│   │   ├── Organizations/
│   │   ├── Projects/
│   │   ├── Environments/
│   │   ├── Flags/
│   │   ├── Targeting/
│   │   ├── Publishing/
│   │   ├── Credentials/
│   │   ├── Experiments/
│   │   ├── Analytics/
│   │   ├── Integrations/
│   │   ├── Audit/
│   │   ├── Billing/
│   │   ├── Notifications/
│   │   └── Support/
│   ├── Providers/
│   ├── Admin/
│   └── Shared/
├── bootstrap/
├── config/
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── resources/
│   ├── js/
│   │   ├── pages/
│   │   ├── features/
│   │   ├── components/ui/
│   │   ├── layouts/
│   │   ├── composables/
│   │   ├── lib/
│   │   └── types/
│   ├── css/
│   └── views/app.blade.php
├── routes/
│   ├── web.php
│   ├── api.php
│   ├── sdk.php
│   └── admin.php
├── tests/
│   ├── Unit/
│   ├── Feature/
│   └── Arch/
├── cypress/e2e/
├── design/bitflag/           # reviewed design tokens and component specifications
├── docs/
├── infrastructure/
├── composer.json
├── package.json
└── vite.config.ts
```

Custom SDK routes and module namespaces/providers are registered explicitly; they are not discovered merely by placing files in these folders.

## Module layout

```text
app/Modules/Publishing/
├── Actions/                 # PublishEnvironment, ApproveChangeSet, RollbackEnvironment
├── Domain/                  # Transition rules, compiler and validation objects
│   └── Exceptions/
├── Data/                    # Typed action inputs and results
├── Http/
│   ├── Controllers/
│   │   ├── Web/
│   │   └── Api/V1/
│   ├── Requests/
│   └── Resources/
├── Models/                  # Eloquent persistence models
├── Policies/
├── Queries/
├── Events/
└── Jobs/
```

Use only folders a module needs. Migrations remain globally ordered under `database/migrations`; test folders mirror the module or workflow being tested. Modules are plain namespaces with a service provider each, not a framework module package.

## Frontend organization

`pages/flags/Show.vue` composes components from `features/flags`, receives initial server props and capabilities, and delegates draft state to `useFlagDraft`. Shared simulation belongs in `features/evaluation`; publication review belongs in `features/publishing`. Generic dialogs and badges live under `components/ui`. Components should not import another page to reuse business behavior.

Keep website presentation components in `bitflag-website`. The design system supplies a common specification; do not create runtime coupling to the portal repository just to share a button. A published token package can be introduced if actual cross-repository drift warrants it.

## SDK internal boundaries

| Repository | Source areas | Important restriction |
| --- | --- | --- |
| `bitflag-php` | `src/Evaluation`, `src/Protocol`, `src/Client`, `src/Transport`, `src/Cache`, `src/Telemetry` | Evaluation imports no transport or framework classes |
| `bitflag-js` | `src/evaluation`, `src/protocol`, `src/client`, `src/browser`, `src/node`, `src/telemetry` | Browser exports cannot pull in server secrets or Node-only modules |
| Framework adapters | Provider/bindings, context resolution, subscriptions, helpers, examples | No duplicate rule engine and no network client per component render |
| CLI and MCP | Transport/API client, commands/tools, authentication, output/error formatting | No direct database access or alternative authorization implementation |

The backend reuses the PHP evaluation core directly; it does not call its own delivery API through the SDK to evaluate a preview. The Laravel adapter is for customer applications and is not a dependency of the BitFlag backend. This avoids a dependency cycle.

## Contract ownership and releases

`bitflag` owns the management OpenAPI specification at `contracts/management/openapi.yaml`. `bitflag-protocol` owns snapshot, evaluation and telemetry schemas, versioned webhook envelopes, bucketing semantics and canonical fixtures. Avoid two independently editable copies of the same contract. Generated copies identify their source release and checksum.

For a cross-repository protocol change:

1. Review the schema/semantic change and fixtures in `bitflag-protocol`.
2. Publish an immutable prerelease contract artifact for compatibility work.
3. Implement consumer support in PHP and JavaScript and run the exact same fixtures.
4. Implement compatible server publication/delivery and test supported older SDKs.
5. Publish the contract, SDKs, server support, examples and compatibility documentation in a coordinated order.
6. Enable configurations requiring the new capability only after the supported-client policy allows it.

Never build against another repository's floating `main` branch. Pin fixture artifacts and package dependencies; verify artifact checksums. SDK package versions, delivery protocol versions, management API versions and evaluation algorithm versions are separate identifiers.

The compatibility matrix records server release, supported protocol versions, evaluator algorithms, PHP/JS SDK versions, and supported language/framework runtimes, together with the deprecation window.
