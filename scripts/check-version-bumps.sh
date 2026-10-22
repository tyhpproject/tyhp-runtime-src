#!/usr/bin/env bash
# List packages whose source changed without a version bump: the tree
# publish-runtime-packages.sh would publish differs from the GitHub tag that
# already exists for that version. Nothing is built, cloned, tagged, or pushed.
# See --help.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../packages" && pwd)"
# shellcheck source=../packages/build-common.sh
source "${SCRIPT_DIR}/build-common.sh"

for tool in php gh; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "error: ${tool} is required." >&2
    exit 1
  fi
done

majors=""
for entry in "${DIST_BUILDS[@]}"; do
  majors+="${entry%%:*} "
done

CHECK_DIST_PACKAGES="${DIST_PACKAGES[*]}" CHECK_PHP_MAJORS="$majors" \
  exec php "${SCRIPT_DIR}/../scripts/check-version-bumps.php" "$@"
