#!/usr/bin/env bash
# Validate a release tag and stamp its version into the plugin header,
# BRICKS_MCP_VERSION and the readme's Stable tag.
# Usage: stamp-release-version.sh <tag>   (for example v2.1.0 or v2.1.0-rc.1)
set -euo pipefail
export LC_ALL=C

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

TAG="${1:-}"
if ! [[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
	echo "Refusing release tag '${TAG}': it must match v<major>.<minor>.<patch>[-<prerelease>]" >&2
	exit 1
fi
VERSION="${TAG#v}"

echo "Stamping version: $VERSION"
sed -i.bak "s/^ \* Version:.*/ * Version:           $VERSION/" bricks-mcp.php
sed -i.bak "s/define( 'BRICKS_MCP_VERSION', '.*' );/define( 'BRICKS_MCP_VERSION', '$VERSION' );/" bricks-mcp.php
sed -i.bak "s/^Stable tag:.*/Stable tag: $VERSION/" readme.txt
rm -f bricks-mcp.php.bak readme.txt.bak

# Fail if a stamp pattern no longer matches its file.
expect_line() {
	if [ "$(grep -cxF -- "$2" "$1")" != "1" ]; then
		echo "Stamping failed: $1 must contain exactly one line '$2'" >&2
		exit 1
	fi
	echo "$1: $2"
}
expect_line bricks-mcp.php " * Version:           $VERSION"
expect_line bricks-mcp.php "define( 'BRICKS_MCP_VERSION', '$VERSION' );"
expect_line readme.txt "Stable tag: $VERSION"
