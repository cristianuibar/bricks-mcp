#!/usr/bin/env bash
set -euo pipefail

ZIP="${1:?Usage: verify-release-zip.sh <zip>}"
EXCLUDES="$(cd "$(dirname "$0")/.." && pwd)/release-excludes.txt"

ENTRIES="$(unzip -Z1 "$ZIP")"

OUTSIDE="$(grep -v '^bricks-mcp/' <<<"$ENTRIES" || true)"
if [ -n "$OUTSIDE" ]; then
	echo "ZIP entries outside bricks-mcp/:" >&2
	echo "$OUTSIDE" >&2
	exit 1
fi

for REQUIRED in bricks-mcp/bricks-mcp.php bricks-mcp/includes/data/GUIDE.md; do
	if ! grep -qxF "$REQUIRED" <<<"$ENTRIES"; then
		echo "ZIP is missing ${REQUIRED}" >&2
		exit 1
	fi
done

FORBIDDEN="$(awk -F/ 'NR==FNR { excluded[$0]; next } { for (i = 1; i <= NF; i++) if ($i in excluded) { print; break } }' "$EXCLUDES" - <<<"$ENTRIES")"
if [ -n "$FORBIDDEN" ]; then
	echo "ZIP contains entries from release-excludes.txt:" >&2
	echo "$FORBIDDEN" >&2
	exit 1
fi

echo "verify-release-zip: OK"
