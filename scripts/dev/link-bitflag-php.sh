#!/usr/bin/env bash
# Develops bitflag-php and this application together: installs ../bitflag-php as a symlinked path repository and
# mounts it into the Sail containers. Undo it with scripts/dev/unlink-bitflag-php.sh.
#
#   scripts/dev/link-bitflag-php.sh
#
# A path repository must never be committed. This script changes composer.json and composer.lock; the
# no-path-repositories check fails CI if they reach a pull request, and unlinking restores both.
# Set COMPOSER_CMD to run Composer another way, for example COMPOSER_CMD=composer on a host that has it.
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

if [[ ! -d ../bitflag-php ]]; then
  echo "Expected a checkout of bitflag-php next to this one (../bitflag-php)." >&2
  exit 1
fi

composer=${COMPOSER_CMD:-./vendor/bin/sail composer}
version="$(php -r '$c = json_decode(file_get_contents("composer.json"), true); echo $c["require"]["bitflag/bitflag-php"];')"

# Sail mounts only this directory, so the sibling checkout is mounted at the same relative place.
cat > compose.override.yaml <<YAML
# Written by scripts/dev/link-bitflag-php.sh and ignored by Git.
services:
    laravel.test:
        volumes:
            - '../bitflag-php:/var/www/bitflag-php'
    horizon:
        volumes:
            - '../bitflag-php:/var/www/bitflag-php'
    outbox:
        volumes:
            - '../bitflag-php:/var/www/bitflag-php'
    scheduler:
        volumes:
            - '../bitflag-php:/var/www/bitflag-php'
YAML

$composer config repositories.bitflag-php-local "{\"type\":\"path\",\"url\":\"../bitflag-php\",\"options\":{\"symlink\":true,\"versions\":{\"bitflag/bitflag-php\":\"$version\"}}}"
$composer update bitflag/bitflag-php --no-interaction

echo
echo "bitflag-php is linked from ../bitflag-php. Restart the containers (sail down; sail up -d) to mount it."
echo "Do not commit composer.json or composer.lock while linked; run scripts/dev/unlink-bitflag-php.sh first."
