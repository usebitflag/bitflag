# 0001. Runtime versions

## Pinned versions

| Component | Version | Where it is pinned |
| --- | --- | --- |
| PHP | 8.5 | Sail image (`compose.yaml`), CI |
| Laravel | 13 | `composer.json`, `composer.lock` |
| Node.js | 22 LTS | `.nvmrc`, Sail image build argument, CI |
| MySQL | 8.4 LTS | `compose.yaml`, CI service |
| Redis | 7 | `compose.yaml`, CI service |

`composer.json` requires `php: ^8.3`, which is the floor Laravel itself sets. The application is developed, tested and deployed on PHP 8.5, so the lockfile is resolved against it.

## Rationale

- The newest PHP that Laravel supports gets the longest security window and avoids a forced upgrade soon after launch.
- MySQL 8.4 is the current long-term-support release. The Sail image and the CI service use the same major version, so locking and transaction behavior is tested on the version that runs in production.
- Redis 7 is pinned instead of a floating `alpine` tag so queue and cache behavior does not change between pulls.
- Node 22 is the active long-term-support line. The build image, local development and CI all use it.

## Changing a version

Change the version in every place listed above in one pull request, run the full test suite on real MySQL and Redis, and update this record. A major MySQL or Redis change also needs a migration check against a copy of production data.
