#!/usr/bin/env bash
# Build the plugin release ZIP from an allow-list of tracked files and check its contents.
# Shared by release.yml, ci.yml and verify-release-zip.sh so the build and the check cannot drift.
#
# Usage: build-release-zip.sh <out-dir>
# Writes <out-dir>/bricks-mcp.zip. Files are taken from the working tree (so a stamped version is kept),
# but only paths tracked by git under the allow-list are copied; untracked files never ship.
set -euo pipefail

if [ $# -ne 1 ]; then
	echo "Usage: $0 <out-dir>" >&2
	exit 2
fi

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$1"
OUT_DIR="$(cd "$1" && pwd)"
STAGE_ROOT="$(mktemp -d)"
trap 'rm -rf "${STAGE_ROOT}"' EXIT
STAGE="${STAGE_ROOT}/bricks-mcp"
ZIP="${OUT_DIR}/bricks-mcp.zip"

# Everything the plugin needs at runtime. Anything else stays out of the ZIP.
ALLOW=(
	bricks-mcp.php
	uninstall.php
	includes
	assets
	languages
	readme.txt
	LICENSE
)

# Files that must be in every release.
REQUIRED=(
	bricks-mcp.php
	uninstall.php
	includes/Plugin.php
	includes/data/GUIDE.md
	readme.txt
	LICENSE
)

# Dev, CI and local-environment paths that must never ship, at any depth.
FORBIDDEN_DIRS='\.git|\.github|\.claude|\.planning|\.wordpress-org|\.wp-env|node_modules|vendor|tests|mu-plugins|docs|logs|build|update-server'
FORBIDDEN_FILES='\.env[^/]*|\.wp-env[^/]*\.json|\.mcp\.json|AGENTS\.md|CLAUDE\.md|README\.md|composer\.(json|lock)|package(-lock)?\.json|phpunit[^/]*\.xml(\.dist)?|phpcs\.xml(\.dist)?|\.editorconfig|\.gitignore|\.gitattributes|\.distignore|wp-env-fixes\.php'

echo "==> Staging allow-listed tracked files at ${STAGE}"
mkdir -p "$STAGE"
(cd "$ROOT" && git ls-files -z -- "${ALLOW[@]}" | tar --null -T - -cf -) | tar -xf - -C "$STAGE"

echo "==> Building ${ZIP}"
rm -f "$ZIP"
(cd "$STAGE_ROOT" && zip -rq "$ZIP" bricks-mcp)

echo "==> Checking ZIP contents"
ENTRIES="$(zipinfo -1 "$ZIP")"
FAILED=0

for path in "${REQUIRED[@]}"; do
	if ! grep -qxF "bricks-mcp/${path}" <<<"$ENTRIES"; then
		echo "ZIP is missing required file: ${path}" >&2
		FAILED=1
	fi
done

ALLOW_RE="$(printf '%s|' "${ALLOW[@]}" | sed 's/\./\\./g; s/|$//')"
OUTSIDE="$(grep -vE "^bricks-mcp/(${ALLOW_RE})(/|$)" <<<"$ENTRIES" | grep -vxF 'bricks-mcp/' || true)"
if [ -n "$OUTSIDE" ]; then
	printf 'ZIP contains entries outside the allow-list:\n%s\n' "$OUTSIDE" >&2
	FAILED=1
fi

BANNED="$(grep -E "(^|/)((${FORBIDDEN_DIRS})(/|$)|(${FORBIDDEN_FILES})$)" <<<"$ENTRIES" || true)"
if [ -n "$BANNED" ]; then
	printf 'ZIP contains forbidden dev paths:\n%s\n' "$BANNED" >&2
	FAILED=1
fi

if [ "$FAILED" -ne 0 ]; then
	rm -f "$ZIP"
	exit 1
fi

echo "build-release-zip: OK ($(grep -cv '/$' <<<"$ENTRIES") files)"
