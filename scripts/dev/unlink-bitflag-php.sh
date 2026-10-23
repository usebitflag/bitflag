#!/usr/bin/env bash
# Undoes scripts/dev/link-bitflag-php.sh: removes the path repository and the container mount, and installs the
# pinned bitflag-php release again.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

composer=${COMPOSER_CMD:-./vendor/bin/sail composer}

rm -f compose.override.yaml
$composer config --unset repositories.bitflag-php-local
$composer update bitflag/bitflag-php --no-interaction

echo
echo "bitflag-php is installed from the pinned release again. Restart the containers to drop the mount."
