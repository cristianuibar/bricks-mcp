#!/usr/bin/env bash
# Run the ability registration smoke, then build the release ZIP exactly like release.yml and check its contents.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "${BUILD_DIR}"' EXIT

echo "==> Ability registration smoke (RegistrarTest)"
php vendor/bin/phpunit -c phpunit-unit.xml --filter RegistrarTest

bash "${ROOT}/.github/scripts/build-release-zip.sh" "$BUILD_DIR"

echo "verify-release-zip: OK"
