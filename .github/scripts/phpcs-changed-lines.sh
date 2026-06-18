#!/usr/bin/env bash
# Run PHPCS but only fail on violations in added/changed lines (PR-scoped gate).
set -euo pipefail

BASE_SHA="${1:?Base SHA required}"
HEAD_SHA="${2:?Head SHA required}"
shift 2
FILES=("$@")

if [ ${#FILES[@]} -eq 0 ]; then
	echo "No PHP files to check"
	exit 0
fi

TMP_REPORT="$(mktemp)"
trap 'rm -f "$TMP_REPORT"' EXIT

php -d memory_limit=512M vendor/bin/phpcs --report=json --report-file="$TMP_REPORT" "${FILES[@]}" || true

python3 - "$BASE_SHA" "$HEAD_SHA" "$TMP_REPORT" "${FILES[@]}" <<'PY'
import json
import re
import subprocess
import sys

base, head, report_path = sys.argv[1], sys.argv[2], sys.argv[3]
files = sys.argv[4:]

changed: dict[str, set[int]] = {f: set() for f in files}

for path in files:
    diff = subprocess.check_output(
        ["git", "diff", "-U0", base, head, "--", path],
        text=True,
        stderr=subprocess.DEVNULL,
    )
    for line in diff.splitlines():
        m = re.match(r"@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@", line)
        if not m:
            continue
        start = int(m.group(1))
        count = int(m.group(2) or "1")
        if count == 0:
            continue
        changed[path].update(range(start, start + count))

with open(report_path, encoding="utf-8") as fh:
    data = json.load(fh)

blocking = []
for f in data.get("files", {}).values():
    rel = f.get("name", "")
    for msg in f.get("messages", []):
        line = msg.get("line", 0)
        severity = msg.get("type", "")
        if severity != "ERROR":
            continue
        # Match repo-relative paths from PHPCS JSON.
        for path, lines in changed.items():
            if rel.endswith(path) and line in lines:
                blocking.append((path, line, msg.get("source", ""), msg.get("message", "")))
                break

if blocking:
    print("PHPCS errors on changed lines:")
    for path, line, source, message in blocking:
        print(f"  {path}:{line} [{source}] {message}")
    sys.exit(1)

print("PHPCS changed-line gate passed")
PY