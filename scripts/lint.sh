#!/bin/sh
# Syntax-check every PHP file. Uses local php if present, else Docker.
set -e
cd "$(dirname "$0")/.."
if command -v php >/dev/null 2>&1; then PHP=php; else PHP=./scripts/php; fi
fail=0
for f in $(find . -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' | sort); do
  out=$($PHP -l "$f" 2>&1) || { echo "$out"; fail=1; }
done
[ $fail -eq 0 ] && echo "php lint: all files OK" || exit 1
