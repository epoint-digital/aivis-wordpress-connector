#!/bin/sh
# Build a reproducible release ZIP: same tree + same commit => same bytes.
#   scripts/build-zip.sh [outdir]      -> outdir/aivis-os.zip + SHA-256SUMS
set -eu
cd "$(dirname "$0")/.."
OUT=${1:-build}
STAGE="$OUT/aivis-os"
rm -rf "$STAGE" && mkdir -p "$STAGE"

# Reproducibility: mtimes come from the commit, not the clock.
EPOCH=${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct 2>/dev/null || date +%s)}
export SOURCE_DATE_EPOCH=$EPOCH

rsync -a --exclude-from=.distignore ./ "$STAGE/"
find "$STAGE" -exec touch -h -d "@$EPOCH" {} + 2>/dev/null || find "$STAGE" -exec touch -h -t "$(date -u -r "$EPOCH" +%Y%m%d%H%M.%S)" {} +

rm -f "$OUT/aivis-os.zip"
# Sorted file list, no extra attributes, fixed timestamps => deterministic archive.
( cd "$OUT" && find aivis-os -type f | LC_ALL=C sort | zip -X -q -@ aivis-os.zip )
( cd "$OUT" && shasum -a 256 aivis-os.zip > SHA-256SUMS )
rm -rf "$STAGE"
echo "built $OUT/aivis-os.zip"; cat "$OUT/SHA-256SUMS"
