#!/bin/sh
# Run the PHP suite. Local PHP+composer if present, else Docker (composer:2 ships PHP 8.3).
set -e
cd "$(dirname "$0")/.."
if command -v php >/dev/null 2>&1 && command -v composer >/dev/null 2>&1; then
  [ -d vendor ] || composer install --no-interaction --quiet
  exec vendor/bin/phpunit "$@"
fi
docker run --rm -v "$(pwd)":/app -w /app composer:2 sh -c \
  '[ -d vendor ] || composer install --no-interaction --quiet --no-progress; vendor/bin/phpunit '"$*"
