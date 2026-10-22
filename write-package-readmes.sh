#!/usr/bin/env bash
# Idempotent README sections for published tyhpdef packages.
# Markers: <!-- tyhp-readme:start --> … <!-- tyhp-readme:end -->
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$SCRIPT_DIR"
PACKAGES_DIR="$REPO_ROOT/packages/tyhpdef"
OWNERSHIP_ISSUE_URL="https://github.com/tyhpproject/tyhp-runtime-src/issues/new?template=tyhpdef-ownership.yml"
OWNERSHIP_DOC_URL="https://github.com/tyhpproject/tyhp-runtime-src"

DRY_RUN=0
ONLY_PACKAGE=""
ONLY_VERSION=""
EMIT="all"
OUT_DIR=""

usage() {
  cat <<'EOF'
Usage: write-package-readmes.sh [options]

Refresh README sections marked with <!-- tyhp-readme:start --> and
<!-- tyhp-readme:end -->.

  ./write-package-readmes.sh
  ./write-package-readmes.sh --package nesbot-carbon
  ./write-package-readmes.sh --dry-run

Scans packages/tyhpdef/ only. Composer-lib version folders
get the implementation README. php and php-ext-* get the first-party section.
Handed-off public metapackage text is written with --emit public or
--emit handed-off (used by generate-meta-package.sh).

Options:
  -h, --help              Show this help
  -n, --dry-run           Print README bodies; do not write files
  --package NAME          Limit to one folder (nesbot-carbon or nesbot/carbon)
  --version VER           With --emit, the upstream folder (default: every version)
  --emit MODE             all (default), impl, public, or handed-off
  --out DIR               With --emit public or handed-off, write README.md here
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
    --package)
      [[ $# -ge 2 ]] || die "--package requires a name"
      ONLY_PACKAGE="$2"
      shift 2
      ;;
    --package=*) ONLY_PACKAGE="${1#*=}"; shift ;;
    --version)
      [[ $# -ge 2 ]] || die "--version requires a value"
      ONLY_VERSION="$2"
      shift 2
      ;;
    --version=*) ONLY_VERSION="${1#*=}"; shift ;;
    --emit)
      [[ $# -ge 2 ]] || die "--emit requires a mode"
      EMIT="$2"
      shift 2
      ;;
    --emit=*) EMIT="${1#*=}"; shift ;;
    --out)
      [[ $# -ge 2 ]] || die "--out requires a directory"
      OUT_DIR="$2"
      shift 2
      ;;
    --out=*) OUT_DIR="${1#*=}"; shift ;;
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *) die "unexpected argument: $1" ;;
  esac
done

case "$EMIT" in
  all|impl|public|handed-off) ;;
  *) die "--emit must be all, impl, public, or handed-off" ;;
esac

export PACKAGES_DIR DRY_RUN ONLY_PACKAGE ONLY_VERSION EMIT OUT_DIR OWNERSHIP_ISSUE_URL OWNERSHIP_DOC_URL
php "$SCRIPT_DIR/write-package-readmes.php"
