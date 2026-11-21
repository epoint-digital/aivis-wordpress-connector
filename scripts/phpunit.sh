#!/bin/sh
# Run the PHP suite. Local PHP+composer if present, else Docker.
#
# In Docker, PHPUnit runs in the image scripts/php pins, never in composer:2.
# That tag floats (seen 2026-11-20: PHP 8.6, no mbstring; #78), so it is only
# the installer for a missing vendor/: told to resolve for the PHP that will
# run the suite, and not to judge extensions it does not have itself.
set -e
cd "$(dirname "$0")/.."
if command -v php >/dev/null 2>&1 && command -v composer >/dev/null 2>&1; then
  [ -f vendor/bin/phpunit ] || composer install --no-interaction --quiet
  exec vendor/bin/phpunit "$@"
fi
if [ ! -f vendor/bin/phpunit ]; then
  php_version=$(./scripts/php -r 'echo PHP_VERSION;')
  docker run --rm -v "$(pwd)":/app -w /app -e PHP_TARGET="$php_version" composer:2 sh -c '
    composer config --global platform.php "$PHP_TARGET" &&
    composer install --no-interaction --no-progress --ignore-platform-req="ext-*"'
fi
exec ./scripts/php vendor/bin/phpunit "$@"
