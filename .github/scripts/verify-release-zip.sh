#!/usr/bin/env bash
# Build the release tree like release.yml and check its contents.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "${BUILD_DIR}"' EXIT
STAGE="${BUILD_DIR}/bricks-mcp"
ZIP="${BUILD_DIR}/bricks-mcp.zip"

echo "==> Ability registration smoke (RegistrarTest)"
php vendor/bin/phpunit -c phpunit-unit.xml --filter RegistrarTest

echo "==> Staging production tree at ${STAGE}"
mkdir -p "$STAGE"
rsync -a \
	--exclude='.git' \
	--exclude='node_modules' \
	--exclude='vendor' \
	--exclude='docs' \
	--exclude='mu-plugins' \
	--exclude='logs' \
	--exclude='tests' \
	--exclude='.planning' \
	--exclude='.wp-env.json' \
	--exclude='package.json' \
	--exclude='package-lock.json' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='phpunit.xml' \
	--exclude='phpunit-unit.xml' \
	--exclude='phpcs.xml' \
	--exclude='.editorconfig' \
	--exclude='.gitignore' \
	--exclude='build' \
	--exclude='.github' \
	--exclude='update-server' \
	--exclude='.claude' \
	--exclude='README.md' \
	--exclude='.wordpress-org' \
	--exclude='.distignore' \
	./ "$STAGE/"

echo "==> Building ZIP"
(cd "$BUILD_DIR" && zip -rq bricks-mcp.zip bricks-mcp)

echo "==> Checking ZIP contents"
ZIP_LIST="$(unzip -l "$ZIP")"
echo "$ZIP_LIST" | grep -q 'bricks-mcp/bricks-mcp.php'
echo "$ZIP_LIST" | grep -q 'bricks-mcp/includes/data/GUIDE.md'
if echo "$ZIP_LIST" | grep -qE 'bricks-mcp/(vendor|tests)/'; then
	echo "ZIP must not contain vendor/ or tests/" >&2
	exit 1
fi

echo "verify-release-zip: OK"
