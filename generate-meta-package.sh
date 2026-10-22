#!/usr/bin/env bash
# Write the public metapackage (composer.json, README.md, LICENSE) from an
# implementation version folder. Publish tags the public repo from this output.
#
# Public require of the impl is ~{numeric-core}.0 on a stable upstream version.
# Prerelease upstream versions require the exact impl version.
# Handed-off source folders emit the post-handoff metapackage (no impl require).
#
# Skip a new public tag when that public version already exists and the
# generated impl require range is unchanged (impl-only revision).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$SCRIPT_DIR"
PACKAGES_DIR="$REPO_ROOT/packages/tyhpdef"

DRY_RUN=0
OUT_DIR=""
EXISTING_PUBLIC=""
FOLDER=""

usage() {
  cat <<'EOF'
Usage: generate-meta-package.sh [options] <vendor-name>/<upstream>

Write the public tyhpdef metapackage from an impl version folder.

  ./generate-meta-package.sh nesbot-carbon/3.14.0
  ./generate-meta-package.sh --dry-run nesbot-carbon/3.14.0

The argument is packages/tyhpdef/<vendor>-<name>/<upstream>/ (the
packages/tyhpdef/ or packages/ prefix is optional). Public version is that
upstream folder name. Public require of
the impl is ~{numeric-core}.0 when the upstream version is stable, and the
exact impl version when it is a prerelease.

Options:
  -h, --help                 Show this help
  -n, --dry-run              Print the public composer.json; do not write files
  --out DIR                  Write composer.json, README.md, and LICENSE here
  --existing-public FILE     If this composer.json is already the same public
                             version and the generated impl require is unchanged,
                             print "public-tag: skip" and do not write files

Exit status:
  0  wrote the metapackage, or skipped an unchanged public tag
  2  usage or missing impl folder
EOF
}

die() {
  echo "error: $*" >&2
  exit 2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    -n|--dry-run) DRY_RUN=1; shift ;;
    --out)
      [[ $# -ge 2 ]] || die "--out requires a directory"
      OUT_DIR="$2"
      shift 2
      ;;
    --out=*) OUT_DIR="${1#*=}"; shift ;;
    --existing-public)
      [[ $# -ge 2 ]] || die "--existing-public requires a composer.json path"
      EXISTING_PUBLIC="$2"
      shift 2
      ;;
    --existing-public=*) EXISTING_PUBLIC="${1#*=}"; shift ;;
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *)
      [[ -z "$FOLDER" ]] || die "unexpected argument: $1"
      FOLDER="$1"
      shift
      ;;
  esac
done

[[ -n "$FOLDER" ]] || die "missing impl version folder (example: nesbot-carbon/3.14.0)
$(usage)"

FOLDER="${FOLDER%/}"
FOLDER="${FOLDER#packages/}"
FOLDER="${FOLDER#tyhpdef/}"
IMPL_DIR="$PACKAGES_DIR/$FOLDER"
[[ -f "$IMPL_DIR/composer.json" ]] || die "impl composer.json not found: $IMPL_DIR/composer.json"

if [[ -z "$OUT_DIR" ]]; then
  OUT_DIR="$REPO_ROOT/.public-meta/$FOLDER"
fi

export IMPL_DIR OUT_DIR EXISTING_PUBLIC DRY_RUN REPO_ROOT
php "$SCRIPT_DIR/generate-meta-package.php"
