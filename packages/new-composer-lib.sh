#!/usr/bin/env bash
# Scaffold a tyhpdef/<vendor>-<name> runtime package that wraps a Packagist library
# and generate Layer 1 tyhpdefs with `tyhp generate_tyhpdef --package-path`.
#
# Per-upstream-version trees: packages/<vendor>-<name>/<upstream>/.
# Folder name is the upstream version string (3.0.2, 3.0.0-alpha.1), not the
# Tyhp four-part version. Tyhpdef-only revisions (3.0.2.1) stay in that same
# folder and only bump composer.json version. Other version directories are
# never overwritten. --force replaces only the requested version directory.
#
# PHP-ext packages and php/core/async/decimal/lambda stay flat (no version
# folder). This script only writes Composer-lib version trees.
#
# composer.json: php >=8.2 and vendor/package in require; tyhpdef/php @dev in
# require-dev and extra.tyhp.require (never require, never a published pin such
# as 0.0.1); extra.tyhp.package include+overlay. Path repository ../../php —
# version directories are one level deeper than php-ext-* (those use ../php).
# Other tyhpdef/* companions belong in require-dev + extra.tyhp.require + a
# sibling path repo the same way.
#
# Versioning: parse --version as {numeric-core}[-{prerelease}][+{build}] (strip a
# leading v). Tyhp package version is {numeric-core}.{revision}[-{prerelease}].
# {revision} is always present (default 0). +build is not copied.
# Upstream composer.json require stays the exact string passed to --version.
#   3.5.0 + rev 0              → 3.5.0.0  (dir 3.5.0)
#   1.2.3.4 + rev 0            → 1.2.3.4.0  (dir 1.2.3.4)
#   3.5.0-alpha.1 + rev 0      → 3.5.0.0-alpha.1  (dir 3.5.0-alpha.1)
#   3.5.0-RC.2 + rev 0         → 3.5.0.0-RC.2  (dir 3.5.0-RC.2)
# Reject dev-* / branch aliases; do not invent a semver.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" && pwd)"
TYHP_DLL="${TYHP_DLL:-$COMPILER_ROOT/bin/Debug/net9.0/tyhp.dll}"
LICENSE_SRC="$SCRIPT_DIR/php-ext-bz2/LICENSE"

DRY_RUN=0
FORCE=0
INCLUDE_DEV=0
UPSTREAM_VERSION=""
REVISION=0
PACKAGE_NAME=""

usage() {
  cat <<'EOF'
Usage: new-composer-lib.sh [options] <vendor/package>

Download a Packagist package and scaffold a Tyhp runtime package at
packages/<vendor>-<name>/<upstream>/ (slash → dash; folder name is the
upstream version, not the Tyhp four-part version). Then run Composer in that
directory and generate Layer 1 from vendor/<vendor>/<package> via the existing
--package-path flag (there is no --composer-package alias).

Defaults
  output dir     packages/<vendor>-<name>/<upstream>/
  composer       run in that version directory
  generate       tyhp generate_tyhpdef --package-path=./vendor/<vendor>/<package>
                 --output=./_tyhpdef/ --overwrite
  --revision     0
  composer.json  require: php >=8.2 + the upstream pin; require-dev and
                 extra.tyhp.require: tyhpdef/php @dev; extra.tyhp.package
                 include+overlay; repositories: path
                 ../../php (not ../php — this tree is <name>/<version>/)

Versioning
  Parse --version as {numeric-core}[-{prerelease}][+{build}] (a leading v is
  stripped for parsing only). The Tyhp package version is always

    {numeric-core}.{revision}[-{prerelease}]

  {revision} is always present (default 0). +build metadata is not copied.
  The composer.json *dependency* on upstream stays the exact --version string
  you passed (including a leading v, a 4-part core, or -alpha.1), not the
  Tyhp package version.

  --version 3.5.0                         → tyhp 3.5.0.0,     require 3.5.0
  --version 3.5.0 --revision 1            → tyhp 3.5.0.1,     require 3.5.0
  --version 1.2.3.4                       → tyhp 1.2.3.4.0,   require 1.2.3.4
  --version 1.2.3.4 --revision 1          → tyhp 1.2.3.4.1,   require 1.2.3.4
  --version 3.5.0-alpha.1                 → tyhp 3.5.0.0-alpha.1, require 3.5.0-alpha.1
  --version 3.5.0-alpha.1 --revision 1    → tyhp 3.5.0.1-alpha.1, require 3.5.0-alpha.1
  --version 3.5.0-RC.2                    → tyhp 3.5.0.0-RC.2, require 3.5.0-RC.2

  Tyhpdef-only bugfixes bump {revision} (3.5.0.1, 3.5.0.2, …) in the same
  <upstream> folder. Do not use -p1 as a Tyhp revision scheme.

  dev-* / branch aliases (dev-main, dev-master, …) are rejected. This script
  will not invent a semver from a branch. Pass a Packagist tag. To consume an
  untagged commit, tag a prerelease first; a commit SHA alone is not a Tyhp
  package version.

Per-upstream-version directories
  Each upstream version is its own tree:

    packages/<vendor>-<name>/<upstream>/

  Scaffolding 3.0.1 while 3.0.2 already exists writes only 3.0.1/ and does not
  need --force. --force replaces that one version directory (hand-written
  overlays in it are kept) and never overwrites a sibling version. Tyhpdef-only
  revisions stay in the same folder (3.0.2.1 lives in 3.0.2/).

Options:
  -h, --help              Show this help
  -n, --dry-run           Print actions; do not write, composer, or generate
  -f, --force             Replace an existing <upstream> version directory
                          (does not delete hand-written _tyhpdef/overlays/*.tyhpdef;
                          other versions under <vendor>-<name>/ are not touched)
  --version <ver>         Upstream Packagist version to pin (required). Tag
                          form {numeric-core}[-prerelease][+build], optional
                          leading v. Not a Composer constraint range. Folder
                          name is {numeric-core}[-prerelease] (leading v and
                          +build stripped).
  --revision <n>          Digit inserted after the numeric core (default: 0)
  --include-dev           Pass --include-dev to generate_tyhpdef (autoload-dev)

Examples:
  ./new-composer-lib.sh psr/log --version 3.0.2
                          → packages/psr-log/3.0.2/
  ./new-composer-lib.sh psr/log --version 3.0.1
                          → packages/psr-log/3.0.1/ (3.0.2/ untouched)
  ./new-composer-lib.sh monolog/monolog --version 3.5.0
  ./new-composer-lib.sh monolog/monolog --version 3.5.0 --revision 1
  ./new-composer-lib.sh monolog/monolog --version 3.5.0-alpha.1
  ./new-composer-lib.sh --dry-run guzzlehttp/guzzle --version 7.9.2
EOF
}

die() {
  echo "error: $*" >&2
  exit 1
}

# Prints "TYHP_VERSION<TAB>REQUIRE_PIN<TAB>DIR_NAME" or dies. REQUIRE_PIN is the
# exact --version string. DIR_NAME is {numeric-core}[-{prerelease}] (leading v
# and +build stripped). TYHP_VERSION is {numeric-core}.{revision}[-{prerelease}].
parse_upstream_version() {
  local raw="$1"
  local rev="$2"
  python3 - "$raw" "$rev" <<'PY'
import re, sys

raw, rev = sys.argv[1], sys.argv[2]
if not re.fullmatch(r"[0-9]+", rev):
    print(f"error: --revision must be a non-negative integer (default 0): {rev}", file=sys.stderr)
    sys.exit(1)

stripped = raw.strip()
if not stripped:
    print("error: --version is empty", file=sys.stderr)
    sys.exit(1)

lower = stripped.lower()
if lower.startswith("dev-") or lower in {"main", "master", "head", "trunk", "develop", "default"}:
    print(
        f"error: --version {raw!r} is a branch alias, not a tagged version.\n"
        "Tyhp package versions are {numeric-core}.{revision}[-prerelease]; "
        "this script will not invent a semver from dev-* / branch names.\n"
        "Pass a Packagist tag (3.5.0, 3.5.0-alpha.1, 1.2.3.4). "
        "To consume an untagged commit, tag a prerelease first; a commit SHA "
        "alone is not a Tyhp package version.",
        file=sys.stderr,
    )
    sys.exit(1)

if re.match(r"^[<>=^~*]|@", stripped) or "||" in stripped or " " in stripped:
    print(
        f"error: --version {raw!r} looks like a Composer constraint range, not a pin.\n"
        "Pass an exact Packagist version (3.5.0, 3.5.0-alpha.1).",
        file=sys.stderr,
    )
    sys.exit(1)

parsed = stripped
if re.match(r"v[0-9]", parsed, re.IGNORECASE):
    parsed = parsed[1:]

core_pre, plus, build = parsed.partition("+")
if plus and not build:
    print(f"error: --version {raw!r}: empty +build metadata", file=sys.stderr)
    sys.exit(1)

m = re.fullmatch(
    r"(?P<core>[0-9]+(?:\.[0-9]+)*)(?:-(?P<pre>[0-9A-Za-z.-]+))?",
    core_pre,
)
if not m:
    print(
        f"error: --version {raw!r} is not {{numeric-core}}[-{{prerelease}}][+{{build}}].\n"
        "Examples: 3.5.0, v3.5.0, 1.2.3.4, 3.5.0-alpha.1, 3.5.0-RC.2.",
        file=sys.stderr,
    )
    sys.exit(1)

core = m.group("core")
pre = m.group("pre")
tyhp = f"{core}.{rev}"
if pre:
    tyhp = f"{tyhp}-{pre}"
print(f"{tyhp}\t{stripped}\t{core_pre}")
PY
}

write_text() {
  local path="$1"
  local contents="$2"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "dry-run: write $path"
    return 0
  fi
  mkdir -p "$(dirname "$path")"
  printf '%s' "$contents" > "$path"
}

ensure_gitkeep() {
  local path="$1"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "dry-run: gitkeep $path"
    return 0
  fi
  mkdir -p "$(dirname "$path")"
  : > "$path"
}

run_tyhp() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "dry-run: dotnet $TYHP_DLL $*"
    return 0
  fi
  if [[ ! -f "$TYHP_DLL" ]]; then
    die "tyhp compiler not found at: $TYHP_DLL
Build the compiler first: dotnet build $COMPILER_ROOT/tyhp.csproj"
  fi
  (cd "$REPO_ROOT" && dotnet "$TYHP_DLL" "$@")
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help)
      usage
      exit 0
      ;;
    -n|--dry-run)
      DRY_RUN=1
      shift
      ;;
    -f|--force)
      FORCE=1
      shift
      ;;
    --include-dev)
      INCLUDE_DEV=1
      shift
      ;;
    --version=*)
      UPSTREAM_VERSION="${1#*=}"
      shift
      ;;
    --version)
      [[ $# -ge 2 ]] || die "--version requires a value"
      UPSTREAM_VERSION="$2"
      shift 2
      ;;
    --revision=*)
      REVISION="${1#*=}"
      shift
      ;;
    --revision)
      [[ $# -ge 2 ]] || die "--revision requires a value"
      REVISION="$2"
      shift 2
      ;;
    --)
      shift
      break
      ;;
    -*)
      die "unknown option: $1
$(usage)"
      ;;
    *)
      if [[ -n "$PACKAGE_NAME" ]]; then
        die "unexpected argument: $1"
      fi
      PACKAGE_NAME="$1"
      shift
      ;;
  esac
done

[[ -n "$PACKAGE_NAME" ]] || die "missing Packagist package name (vendor/package)
$(usage)"
[[ -n "$UPSTREAM_VERSION" ]] || die "--version is required
$(usage)"

if [[ "$PACKAGE_NAME" != */* || "$PACKAGE_NAME" == */*/* ]]; then
  die "package name must be vendor/package (one slash): $PACKAGE_NAME"
fi

if [[ ! "$REVISION" =~ ^[0-9]+$ ]]; then
  die "--revision must be a non-negative integer (default 0): $REVISION"
fi

PARSED="$(parse_upstream_version "$UPSTREAM_VERSION" "$REVISION")" || exit 1
IFS=$'\t' read -r TYHP_VERSION UPSTREAM_REQUIRE UPSTREAM_DIR <<< "$PARSED"
VENDOR="${PACKAGE_NAME%%/*}"
PROJ="${PACKAGE_NAME#*/}"
DIR_NAME="${VENDOR}-${PROJ}"
PKG_PARENT="$SCRIPT_DIR/$DIR_NAME"
PKG_DIR="$PKG_PARENT/$UPSTREAM_DIR"
PUBLIC_COMPOSER_NAME="tyhpdef/${DIR_NAME}"
PKG_COMPOSER_NAME="${PUBLIC_COMPOSER_NAME}-impl"
VENDOR_PATH="$PKG_DIR/vendor/${VENDOR}/${PROJ}"
LAYER1_FILE="${VENDOR}.${PROJ}.tyhpdef"
TEST_SAFE="$(printf '%s' "${VENDOR}_${PROJ}" | tr '.-' '_')"

if [[ -f "$PKG_PARENT/composer.json" ]]; then
  die "flat package layout at $PKG_PARENT (composer.json at the package root).
Move that tree into $PKG_PARENT/<upstream>/ first (folder name = upstream version)."
fi

if [[ -e "$PKG_DIR" && "$FORCE" -ne 1 ]]; then
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "warning: version directory already exists (would refuse without --force): $PKG_DIR" >&2
  else
    die "version directory already exists: $PKG_DIR
Re-run with --force to replace this upstream version only (hand-written overlays
are kept). Sibling versions under $PKG_PARENT are never overwritten."
  fi
fi

if [[ ! -f "$LICENSE_SRC" ]]; then
  die "Apache-2.0 LICENSE template not found: $LICENSE_SRC"
fi

echo "Tyhp package:  $PKG_COMPOSER_NAME ${TYHP_VERSION}"
echo "Directory:     $PKG_DIR"
echo "Upstream:      ${PACKAGE_NAME} ${UPSTREAM_REQUIRE} (require pin)"
echo "Layer 1:       _tyhpdef/${LAYER1_FILE}"

COMPOSER_JSON="$(python3 - "$PKG_COMPOSER_NAME" "$PUBLIC_COMPOSER_NAME" "$PACKAGE_NAME" "$UPSTREAM_REQUIRE" "$TYHP_VERSION" <<'PY'
import json, sys
pkg, public, upstream, upstream_ver, tyhp_ver = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5]
doc = {
    "name": pkg,
    "description": f"Implementation package for {public}. Require {public}, not this package.",
    "keywords": ["dev", "static analysis", "internal"],
    "type": "library",
    "license": "Apache-2.0",
    "version": tyhp_ver,
    "minimum-stability": "dev",
    "prefer-stable": True,
    "extra": {
        "tyhp": {
            "public": public,
            "interopContractVersion": 1,
            "php-version": ">=8.2",
            "require": {
                "tyhpdef/php": "@dev",
            },
            "package": {
                "include": [
                    "./_tyhpdef/*.tyhpdef",
                    "./_tyhpdef/extensions/*.tyhpdef",
                ],
                "overlay": [
                    "./_tyhpdef/overlays/stubs/*.tyhpdef",
                    "./_tyhpdef/overlays/*.tyhpdef",
                ],
            },
        },
    },
    "require": {
        "php": ">=8.2",
        upstream: upstream_ver,
    },
    "require-dev": {
        "tyhpdef/php": "@dev",
    },
    "repositories": [
        {
            "type": "path",
            "url": "../../php",
        },
    ],
}
print(json.dumps(doc, indent=4, ensure_ascii=True))
print()
PY
)"

TEST_TYHP_JSON="$(python3 - "$TEST_SAFE" <<'PY'
import json, sys
test = sys.argv[1]
doc = {
    "quiet": True,
    "locale": "en-US",
    "type": "application",
    "include": [
        f"./test_{test}.tyhp",
        "../composer.json",
        "../../../php/composer.json",
    ],
    "exclude": [],
    "output": {"path": "./build", "phpVersion": "8.2"},
}
print(json.dumps(doc, indent=4))
print()
PY
)"

TEST_TYHP="$(cat <<EOF
<?tyhp

function test_${TEST_SAFE}_package_loads(): bool
{
    return true;
}
EOF
)"

NOTICE="$(cat <<EOF
This directory is an Apache-2.0 Tyhp type-definition wrapper.
It depends on ${PACKAGE_NAME} ${UPSTREAM_VERSION}; the upstream package keeps
its own license for the PHP sources under vendor/.
EOF
)"

SOURCES="$(cat <<EOF
# Sources for this tyhpdef generation

- Composer package: ${PACKAGE_NAME} ${UPSTREAM_VERSION}
- Layer 1 from generate_tyhpdef --package-path=vendor/${VENDOR}/${PROJ} (parsed PHP autoload; no Reflection)
- Catalog: see the Tyhp repository THIRD_PARTY.md
EOF
)"

write_text "$PKG_DIR/composer.json" "$COMPOSER_JSON"
write_text "$PKG_DIR/tests/tyhp.json" "$TEST_TYHP_JSON"
write_text "$PKG_DIR/tests/test_${TEST_SAFE}.tyhp" "$TEST_TYHP"$'\n'
write_text "$PKG_DIR/_tyhpdef/NOTICE" "$NOTICE"$'\n'
ensure_gitkeep "$PKG_DIR/_tyhpdef/extensions/.gitkeep"
ensure_gitkeep "$PKG_DIR/_tyhpdef/overlays/.gitkeep"
ensure_gitkeep "$PKG_DIR/_tyhpdef/overlays/stubs/.gitkeep"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "dry-run: cp $LICENSE_SRC $PKG_DIR/LICENSE"
else
  cp "$LICENSE_SRC" "$PKG_DIR/LICENSE"
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "dry-run: (cd $PKG_DIR && composer update --no-dev --no-interaction)"
else
  command -v composer >/dev/null 2>&1 || die "composer not found on PATH"
  (cd "$PKG_DIR" && composer update --no-dev --no-interaction)
fi

if [[ "$DRY_RUN" -ne 1 && ! -d "$VENDOR_PATH" ]]; then
  die "Composer did not install ${PACKAGE_NAME} at $VENDOR_PATH"
fi

GEN_ARGS=(
  generate_tyhpdef
  "--package-path=${VENDOR_PATH}"
  "--output=${PKG_DIR}/_tyhpdef"
  "--output-file=${LAYER1_FILE}"
  --overwrite
)
if [[ "$INCLUDE_DEV" -eq 1 ]]; then
  GEN_ARGS+=(--include-dev)
fi

echo "Generating tyhpdefs from vendor/${VENDOR}/${PROJ}…"
set +e
run_tyhp "${GEN_ARGS[@]}"
gen_rc=$?
set -e
if [[ "$DRY_RUN" -ne 1 && "$gen_rc" -ne 0 ]]; then
  echo "error: generate_tyhpdef --package-path failed (exit $gen_rc)." >&2
  echo "The package skeleton is at $PKG_DIR. Do not invent APIs." >&2
  exit "$gen_rc"
fi

write_text "$PKG_DIR/_tyhpdef/SOURCES.md" "$SOURCES"$'\n'

echo "Stamping overlays (from tests/; TYHP8027 → exit 5 is expected)…"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "dry-run: (cd $PKG_DIR/tests && dotnet $TYHP_DLL overlay stamp)"
else
  set +e
  (cd "$PKG_DIR/tests" && dotnet "$TYHP_DLL" overlay stamp)
  stamp_rc=$?
  set -e
  if [[ "$stamp_rc" -ne 0 && "$stamp_rc" -ne 5 ]]; then
    die "overlay stamp failed with exit ${stamp_rc}. Working invocation: (cd ${PKG_DIR}/tests && dotnet ${TYHP_DLL} overlay stamp). Exit 5 is CompileWarning from TYHP8027 (runtime packages not in this tests project)."
  fi
fi

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "dry-run: $REPO_ROOT/write-package-readmes.sh --package $DIR_NAME --version $UPSTREAM_DIR"
else
  "$REPO_ROOT/write-package-readmes.sh" --package "$DIR_NAME" --version "$UPSTREAM_DIR"
fi

echo "Done: $PKG_DIR ($PKG_COMPOSER_NAME ${TYHP_VERSION})"
echo "Public name:   $PUBLIC_COMPOSER_NAME"
echo "Upstream pin remains ${PACKAGE_NAME}:${UPSTREAM_VERSION}."
