#!/usr/bin/env bash
# Runs, in order, what the backend-lint, backend-test, arch-canary and frontend jobs of CI run, and stops at the first
# failure. Run it before pushing:
#
#   scripts/check.sh            # everything
#   scripts/check.sh backend    # PHP only
#   scripts/check.sh frontend   # JavaScript only
#
# The Feature tests need the PHP version of the containers, so on a host with an older PHP run the backend part in
# Sail: sail bash scripts/check.sh backend. The pinned protocol release must be fetched first (see
# docs/development.md). The end-to-end specs, the production build checks and the security audits are separate CI
# jobs and are not run here; `composer audit` is included because new advisories fail CI without any code change.
set -euo pipefail
cd "$(dirname "$0")/.."

part="${1:-all}"
if [[ ! "$part" =~ ^(all|backend|frontend)$ ]]; then
  echo "Usage: scripts/check.sh [backend|frontend]" >&2
  exit 2
fi

step() {
  echo
  echo "==> $*"
  "$@"
}

if [[ "$part" != frontend ]]; then
  step composer validate --strict
  step composer audit
  step vendor/bin/pint --test
  step vendor/bin/phpstan analyse --memory-limit=1G
  step php scripts/ci/no-path-repositories.php
  step vendor/bin/pest --parallel
  step bash scripts/ci/arch-canary.sh
fi

if [[ "$part" != backend ]]; then
  step php artisan wayfinder:generate
  step php artisan bitflag:types --check
  step npm run tokens:check
  step npm run lint
  step npm run format:check
  step npm run stylelint
  step npm run typecheck
  step npm run test:coverage
fi

echo
echo "All checks passed."
