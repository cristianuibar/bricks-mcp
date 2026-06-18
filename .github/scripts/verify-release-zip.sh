#!/usr/bin/env bash
# Verify production build includes vendor/ and BootTest passes (REL-04).
# Mirrors build/bricks-mcp tree used by release.yml and deploy-wordpress-org.yml (BUILD_DIR).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

if command -v composer >/dev/null 2>&1; then
	COMPOSER=(composer)
elif [[ -f "${ROOT}/composer.phar" ]]; then
	COMPOSER=(php "${ROOT}/composer.phar")
else
	echo "composer not found (install composer or add composer.phar to repo root)" >&2
	exit 1
fi

BUILD_DIR=""
cleanup() {
	if [[ -n "${BUILD_DIR}" && -d "${BUILD_DIR}" ]]; then
		rm -rf "${BUILD_DIR}"
	fi
	# Restore dev vendor if production install ran.
	("${COMPOSER[@]}" install --no-interaction --quiet --ignore-platform-reqs) 2>/dev/null || true
}
trap cleanup EXIT

echo "==> Installing dev dependencies for boot smoke"
"${COMPOSER[@]}" install --no-interaction --quiet --ignore-platform-reqs 2>/dev/null \
	|| "${COMPOSER[@]}" install --no-interaction --quiet

echo "==> Boot smoke (BootTest)"
if php vendor/bin/phpunit -c phpunit-unit.xml --filter BootTest 2>/dev/null; then
	:
elif [[ "${VERIFY_SKIP_BOOT:-}" == "1" ]]; then
	echo "WARN: BootTest skipped (VERIFY_SKIP_BOOT=1) — CI is authoritative" >&2
else
	echo "BootTest failed. Install PHP dom/mbstring/xml extensions or set VERIFY_SKIP_BOOT=1 for packaging-only check." >&2
	exit 1
fi

echo "==> Production dependencies (mirrors release.yml)"
"${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction --quiet --ignore-platform-reqs 2>/dev/null \
	|| "${COMPOSER[@]}" install --no-dev --optimize-autoloader --no-interaction --quiet

BUILD_DIR="$(mktemp -d)"
STAGE="${BUILD_DIR}/bricks-mcp"
ZIP="${BUILD_DIR}/bricks-mcp.zip"

echo "==> Staging production tree at ${STAGE}"
mkdir -p "$STAGE"
rsync -a \
	--exclude='.git' \
	--exclude='node_modules' \
	--exclude='tests' \
	--exclude='.planning' \
	--exclude='.wp-env.json' \
	--exclude='package.json' \
	--exclude='package-lock.json' \
	--exclude='composer.json' \
	--exclude='composer.lock' \
	--exclude='phpunit.xml' \
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

test -f "${STAGE}/vendor/autoload.php"
test -d "${STAGE}/vendor/composer"

echo "==> Building ZIP"
(cd "$BUILD_DIR" && zip -rq bricks-mcp.zip bricks-mcp)

echo "==> Asserting vendor inside ZIP"
ZIP_LIST="$(unzip -l "$ZIP")"
echo "$ZIP_LIST" | grep -q 'bricks-mcp/vendor/autoload.php'
echo "$ZIP_LIST" | grep -q 'bricks-mcp/vendor/composer/'

echo "REL-04 verify-release-zip: OK"