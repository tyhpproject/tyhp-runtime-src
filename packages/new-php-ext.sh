#!/usr/bin/env bash
# Scaffold a tyhpdef/php-ext-<name> package from a named PHP extension, then run
# `tyhp generate_tyhpdef` with --php-targets=8.2,8.3,8.4,8.5.
#
# Layout matches an existing generated package (bz2 / xmlwriter): Apache-2.0
# LICENSE, composer.json (php >=8.2 and ext-<name> in require; tyhpdef/php @dev in
# require-dev and extra.tyhp.require; path repo ../php; extra.tyhp.package
# include+overlay), _tyhpdef/{overlays/stubs,overlays,extensions} gitkeeps,
# tests/tyhp.json.
# Layer 1 is Ext.<Stem>.tyhpdef via --output-file.
#
# generate_tyhpdef is invoked from the repo root so Reflection harvest reuses
# tyhpdef_gen/snapshots/{minor}/ when present; otherwise managed PHP reflects.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" && pwd)"
TYHP_DLL="${TYHP_DLL:-$COMPILER_ROOT/bin/Debug/net9.0/tyhp.dll}"
LICENSE_SRC="$SCRIPT_DIR/php-ext-bz2/LICENSE"

DEFAULT_PHP_TARGETS="8.2,8.3,8.4,8.5"
ALWAYS_PRESENT_REGEX='^(core|date|filter|hash|json|libxml|pcre|random|reflection|spl|standard)$'

DRY_RUN=0
FORCE=0
PHP_TARGETS="$DEFAULT_PHP_TARGETS"
PHP_TARGETS_SET=0
PHP_BIN=""
EXT_NAME=""

usage() {
  cat <<'EOF'
Usage: new-php-ext.sh [options] <extension>

Scaffold packages/php-ext-<name>/ for a PHP extension and generate
Layer 1 + Layer 2 tyhpdefs with the real CLI:

  dotnet <tyhp.dll> generate_tyhpdef --ext-name=<extension> \
    --php-targets=8.2,8.3,8.4,8.5 \
    --output=packages/php-ext-<name>/_tyhpdef/ \
    --output-file=Ext.<Stem>.tyhpdef --overwrite

<extension> is the PHP extension name passed to --ext-name (e.g. redis, bz2,
pdo_mysql). The package directory is lowercase php-ext-<name>. Composer
require uses php >=8.2 and ext-<lowercase>. tyhpdef/php @dev goes in
require-dev and extra.tyhp.require, not require. A path repository
points at ../php. extra.tyhp.extensions keeps the name you passed.

Always-present builtins (Core, date, filter, hash, json, libxml, pcre, random,
Reflection, SPL, standard) belong in tyhpdef/php — this script refuses them.
json, hash, and libxml stay in tyhpdef/php; there is no tyhpdef/php-ext-json (etc.).

Options:
  -h, --help              Show this help
  -n, --dry-run           Print actions; do not write files or run the CLI
  -f, --force             Allow writing into an existing package directory
                          (does not delete hand-written _tyhpdef/overlays/*.tyhpdef)
  --php-targets=<list>    Minors for generate_tyhpdef (default: 8.2,8.3,8.4,8.5)
  --php=<path>            Ungated --php escape hatch (illegal with --php-targets).
                          Use for a private unpublished extension on a local binary.

Snapshots:
  Run from the repo root. generate_tyhpdef reuses tyhpdef_gen/snapshots/{minor}/
  when present; otherwise it reflects with Tyhp-managed PHP. Pass
  --refresh-snapshots on a manual generate_tyhpdef invocation to re-reflect.

Overlay stamp:
  After a successful generate, the script runs `overlay stamp` from tests/
  (that project includes this package and tyhpdef/php). Missing tyhp/core,
  tyhp/decimal, tyhp/async, and tyhp/lambda warn TYHP8027 and overlay stamp
  exits 5 (CompileWarning). That is expected; the stamp still applied.
  Working invocation:

    (cd packages/php-ext-<name>/tests && dotnet "$TYHP_DLL" overlay stamp)

  Treat exit 0 or 5 as success for that command. Do not add the four runtime
  packages to silence 8027 unless you intend to restamp their overlays too.

Examples:
  ./new-php-ext.sh redis
  ./new-php-ext.sh --dry-run xmlwriter
  ./new-php-ext.sh --force bz2
EOF
}

die() {
  echo "error: $*" >&2
  exit 1
}

is_always_present() {
  local lower
  lower="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
  [[ "$lower" =~ $ALWAYS_PRESENT_REGEX ]]
}

# pdo_mysql → PdoMysql; bz2 → Bz2; PDO → PDO; xmlwriter → Xmlwriter
ext_tyhpdef_stem() {
  local raw="$1"
  local out="" part first rest
  local IFS='_'
  local -a parts
  read -ra parts <<< "$raw"
  for part in "${parts[@]}"; do
    [[ -z "$part" ]] && continue
    first="$(printf '%s' "${part:0:1}" | tr '[:lower:]' '[:upper:]')"
    rest="${part:1}"
    out+="${first}${rest}"
  done
  printf '%s' "$out"
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
    --php-targets=*)
      PHP_TARGETS="${1#*=}"
      PHP_TARGETS_SET=1
      shift
      ;;
    --php-targets)
      [[ $# -ge 2 ]] || die "--php-targets requires a value"
      PHP_TARGETS="$2"
      PHP_TARGETS_SET=1
      shift 2
      ;;
    --php=*)
      PHP_BIN="${1#*=}"
      shift
      ;;
    --php)
      [[ $# -ge 2 ]] || die "--php requires a path"
      PHP_BIN="$2"
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
      if [[ -n "$EXT_NAME" ]]; then
        die "unexpected argument: $1"
      fi
      EXT_NAME="$1"
      shift
      ;;
  esac
done

[[ -n "$EXT_NAME" ]] || die "missing extension name
$(usage)"

if [[ "$EXT_NAME" == */* || "$EXT_NAME" == *[[:space:]]* ]]; then
  die "extension name must not contain slashes or spaces: $EXT_NAME
For Zend OPcache, pass the PHP name generate_tyhpdef expects (see an existing php-ext-opcache package)."
fi

if is_always_present "$EXT_NAME"; then
  die "'$EXT_NAME' is always present and lives in tyhpdef/php, not tyhpdef/php-ext-*.
json, hash, and libxml also stay in tyhpdef/php."
fi

if [[ -n "$PHP_BIN" && "$PHP_TARGETS_SET" -eq 1 ]]; then
  die "--php cannot be combined with --php-targets (TYHP7510). Omit --php-targets to use a local binary."
fi

EXT_LOWER="$(printf '%s' "$EXT_NAME" | tr '[:upper:]' '[:lower:]')"
PKG_DIRNAME="php-ext-${EXT_LOWER}"
PKG_DIR="$SCRIPT_DIR/$PKG_DIRNAME"
PKG_COMPOSER_NAME="tyhpdef/${PKG_DIRNAME}"
STEM="$(ext_tyhpdef_stem "$EXT_NAME")"
LAYER1_FILE="Ext.${STEM}.tyhpdef"
TEST_BASENAME="test_${EXT_LOWER}"
TEST_BASENAME="${TEST_BASENAME//-/_}"

if [[ -e "$PKG_DIR" && "$FORCE" -ne 1 ]]; then
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "warning: package directory already exists (would refuse without --force): $PKG_DIR" >&2
  else
    die "package directory already exists: $PKG_DIR
Re-run with --force to write into it (hand-written overlays are kept)."
  fi
fi

if [[ ! -f "$LICENSE_SRC" ]]; then
  die "Apache-2.0 LICENSE template not found: $LICENSE_SRC"
fi

echo "Package:     $PKG_COMPOSER_NAME"
echo "Directory:   $PKG_DIR"
echo "Extension:   $EXT_NAME"
echo "Layer 1:     _tyhpdef/$LAYER1_FILE"
if [[ -n "$PHP_BIN" ]]; then
  echo "PHP binary:  $PHP_BIN (ungated)"
else
  echo "PHP targets: $PHP_TARGETS"
fi

COMPOSER_JSON="$(python3 - "$PKG_COMPOSER_NAME" "$EXT_NAME" "$EXT_LOWER" <<'PY'
import json, sys
pkg, ext, ext_lower = sys.argv[1], sys.argv[2], sys.argv[3]
doc = {
    "name": pkg,
    "description": (
        f"Tyhp type definitions for the PHP {ext} extension (8.2+). "
        "Version-specific APIs are gated via declare(php=…) / #[\\Tyhp\\Php] (Story 20.5)."
    ),
    "keywords": ["dev", "static analysis"],
    "type": "library",
    "license": "Apache-2.0",
    "version": "0.1",
    "extra": {
        "tyhp": {
            "interopContractVersion": 1,
            "php-version": ">=8.2",
            "supported-minors": ["8.2", "8.3", "8.4", "8.5"],
            "extensions": [ext],
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
        }
    },
    "require": {
        "php": ">=8.2",
        f"ext-{ext_lower}": "*",
    },
    "require-dev": {
        "tyhpdef/php": "@dev",
    },
    "repositories": [
        {
            "type": "path",
            "url": "../php",
        }
    ],
}
print(json.dumps(doc, indent=4, ensure_ascii=True))
print()
PY
)"

TEST_TYHP_JSON="$(python3 - "$TEST_BASENAME" <<'PY'
import json, sys
test = sys.argv[1]
doc = {
    "quiet": True,
    "locale": "en-US",
    "type": "application",
    "include": [
        f"./{test}.tyhp",
        "../composer.json",
        "../../php/composer.json",
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

function ${TEST_BASENAME}_package_loads(): bool
{
    return true;
}
EOF
)"

write_text "$PKG_DIR/composer.json" "$COMPOSER_JSON"
write_text "$PKG_DIR/tests/tyhp.json" "$TEST_TYHP_JSON"
write_text "$PKG_DIR/tests/${TEST_BASENAME}.tyhp" "$TEST_TYHP"$'\n'
ensure_gitkeep "$PKG_DIR/_tyhpdef/extensions/.gitkeep"
ensure_gitkeep "$PKG_DIR/_tyhpdef/overlays/.gitkeep"
ensure_gitkeep "$PKG_DIR/_tyhpdef/overlays/stubs/.gitkeep"

if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "dry-run: cp $LICENSE_SRC $PKG_DIR/LICENSE"
else
  cp "$LICENSE_SRC" "$PKG_DIR/LICENSE"
fi

GEN_ARGS=(
  generate_tyhpdef
  "--ext-name=${EXT_NAME}"
  "--output=${PKG_DIR}/_tyhpdef"
  "--output-file=${LAYER1_FILE}"
  --overwrite
)
if [[ -n "$PHP_BIN" ]]; then
  GEN_ARGS+=("--php=${PHP_BIN}")
else
  GEN_ARGS+=("--php-targets=${PHP_TARGETS}")
fi

echo "Generating tyhpdefs…"
set +e
run_tyhp "${GEN_ARGS[@]}"
gen_rc=$?
set -e
if [[ "$DRY_RUN" -ne 1 && "$gen_rc" -ne 0 ]]; then
  echo "error: generate_tyhpdef failed (exit $gen_rc)." >&2
  echo "Managed PHP often reports TYHP7511 when it cannot load '${EXT_NAME}'." >&2
  echo "The package skeleton is at $PKG_DIR. Do not invent APIs for a missing extension." >&2
  echo "Fix provisioning, or use --php=<binary> that has the extension loaded (ungated)." >&2
  exit "$gen_rc"
fi

echo "Stamping overlays (from tests/; TYHP8027 → exit 5 is expected)…"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "dry-run: (cd $PKG_DIR/tests && dotnet $TYHP_DLL overlay stamp)"
else
  set +e
  (cd "$PKG_DIR/tests" && dotnet "$TYHP_DLL" overlay stamp)
  stamp_rc=$?
  set -e
  if [[ "$stamp_rc" -ne 0 && "$stamp_rc" -ne 5 ]]; then
    die "overlay stamp failed with exit $stamp_rc
Working invocation: (cd $PKG_DIR/tests && dotnet \"$TYHP_DLL\" overlay stamp)
Exit 5 is CompileWarning from TYHP8027 (runtime packages not in this tests project)."
  fi
fi

echo "Done: $PKG_DIR"
echo "Smoke test is tests/${TEST_BASENAME}.tyhp (package load only). Add a real API call from Layer 1 if you want a signature check."
