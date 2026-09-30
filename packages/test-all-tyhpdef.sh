#!/usr/bin/env bash
# Test every tyhpdef package that has tests/tyhp.json, from that tests/
# directory — the same invocation as `./tyhp.sh lint` there, but using the
# already-built compiler (one `dotnet build`, then many lints). Packages run
# in parallel by default (one job per package/version):
#
#   rm -f <package>/composer.lock && rm -rf <package>/vendor
#   cd <package>/tests
#   dotnet <repo>/bin/Debug/net9.0/tyhp.dll lint
#
# Positives: tests/*.tyhp (and subdirs except fail/) must lint clean against
# that folder's tyhp.json (and, for tyhpdef/php, sibling tyhp-php*.json matrix
# files so version-gated overlay contracts are checked).
# Fail cases: tests/fail/<stem>.tyhp is compiled alone via `tyhp lint --file`
# and asserted against tests/fail/<stem>.expect.json (numeric diagnostic
# codes, not in-source comments). An empty or missing fail/ directory is a
# skip, not a failure.
#
# Skips tyhp/core, tyhp/async, tyhp/lambda, tyhp/decimal, and tyhp/compiler.
# Versioned Composer-lib trees (psr-log/3.0.2, monolog-monolog/3.10.0) are
# tested per version folder. cwd is always tests/ so the compiler loads that
# tyhp.json. Parallel jobs give each package its own lint cache directory so
# concurrent lints do not share the process-wide AST cache. A package whose
# lints take longer than --timeout (default 90s) fails. Each package prints a
# start line and one result line with its wall-clock time; step details follow
# only on failure or with --verbose. The summary reports
# overall wall-clock time, the fastest and slowest packages, and the
# average time per package.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPILER_ROOT=""
if [[ -d "$REPO_ROOT/../tyhp" ]]; then
  COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" && pwd)"
fi
PACKAGES_DIR="$SCRIPT_DIR"
TYHP_CSPROJ=""
if [[ -n "$COMPILER_ROOT" ]]; then
  TYHP_CSPROJ="$COMPILER_ROOT/tyhp.csproj"
fi
# Sibling compiler Debug build, an explicit TYHP_DLL, or a released TYHP_BIN.
if [[ -z "${TYHP_BIN:-}" && -z "${TYHP_DLL:-}" && -n "$COMPILER_ROOT" ]]; then
  TYHP_DLL="$COMPILER_ROOT/bin/Debug/net9.0/tyhp.dll"
fi
ASSERT_EXPECT="$SCRIPT_DIR/assert-tyhpdef-fail-expect.py"

DRY_RUN=0
FAIL_FAST=0
VERBOSE=0
# Failed and warned packages' step output is appended here (truncated per run).
ISSUES_FILE=""
ISSUE_COUNT=0
JOBS=""
# Every lint of one package/version.
TIMEOUT_SECONDS=""
# Empty means every discovered package. Entries are paths relative to
# packages (php-ext-curl, monolog-monolog/3.10.0).
PACKAGE_FILTERS=()
LINT_ARGS=()
# Set per parallel job so concurrent lints do not write one shared AST cache.
PACKAGE_CACHE_DIR=""
STATUS_FILE=""
# Per-package step output (lint, fail-case asserts). Printed under
# the result line only on failure or with --verbose.
DETAIL_FILE=""
# auto | always | never — --no-color always wins over --color
COLOR_WANT=auto
NO_COLOR_FLAG=0
USE_COLOR=0
C_RESET=""
C_BOLD=""
C_RED=""
C_GREEN=""
C_YELLOW=""
C_CYAN=""
C_DIM=""

usage() {
  cat <<'EOF'
Usage: test-all-tyhpdef.sh [options] [-- lint-options...]

Run tyhpdef-package tests in each runtime package's tests/ directory (so that
folder's tyhp.json is used). Equivalent to `./tyhp.sh lint` from tests/, plus
per-file fail/ cases with sidecar .expect.json.

Skipped packages: core, async, lambda, decimal, compiler.

Packages are discovered by tests/tyhp.json: flat trees (php, php-ext-*) and
versioned Composer-lib trees (psr-log/<ver>, monolog-monolog/<ver>).

Each package:
  1. Remove a leftover composer.lock and vendor/ in the package root (parent
     of tests/). Dependency tyhpdefs are loaded from the tests/tyhp.json
     include list.
  2. Positive lint of tests/tyhp.json (must be clean; exit 0 or 5 = pass).
  3. For tyhpdef/php, also lint each tests/tyhp-php*.json matrix file.
  4. Each tests/fail/*.tyhp compiled alone (`tyhp lint --file --format=json`)
     and checked against tests/fail/<stem>.expect.json.

Options:
  -h, --help              Show this help
  -n, --dry-run           Print the cleanup and lint / fail-case commands; do not run them
  -v, --verbose           Print every package's step details (each lint, each
                          fail case), not only for failures
  --issues-file FILE      Write the result line and full step details of every
                          package that failed or passed with warnings to FILE
                          (plain text, no color). FILE is truncated at the start
                          of the run. Ignored with --dry-run.
  -j, --jobs N            Run up to N packages at once (default: CPU count).
                          Each job is one package/version. A package's result
                          is printed when that job finishes. -j 1 runs one
                          package at a time.
                          --fail-fast does not start further packages; jobs
                          already running finish. Dry-run always prints
                          sequentially.
  --timeout SECONDS       Abort a package/version whose lints (positives and
                          fail cases together) take longer than SECONDS
                          (default 90). The package fails.
  --package NAME          Run only this package (repeatable). NAME is the path
                          under packages, as printed by a full run
                          (php-ext-curl, monolog-monolog/3.10.0). A name with
                          no version runs every version folder of that package.
                          Default: every discovered package.
  --fail-fast             Abort on the first package failure (default: continue)
  --color[=WHEN]          Colorize status output. WHEN is always, never, or
                          auto (default auto: color when stdout is a TTY and
                          NO_COLOR is unset). --color means always.
  --no-color              Do not colorize (overrides --color)

Anything after `--`, or any other arguments, are forwarded to positive
`tyhp lint` runs.

Each package prints a start line and one result line with its status,
positive / fail-case counts, and wall-clock time. Step details (lint
diagnostics, fail-case mismatches) follow the result line
only when the package fails, or always with --verbose. The summary reports
overall wall-clock time, the fastest and slowest packages, and the
average time per package.

Exit 0 and 5 (success / compile warning) count as a pass for positives.
Fail cases must produce the diagnostics in the sidecar (at least one error).
The script exits 1 if any package failed.

Examples:
  ./test-all-tyhpdef.sh
  ./test-all-tyhpdef.sh --dry-run
  ./test-all-tyhpdef.sh --verbose --package php-ext-curl
  ./test-all-tyhpdef.sh --issues-file /tmp/tyhpdef-issues.txt
  ./test-all-tyhpdef.sh --fail-fast
  ./test-all-tyhpdef.sh --jobs 4
  ./test-all-tyhpdef.sh --package php-ext-curl
  ./test-all-tyhpdef.sh --package monolog-monolog/3.10.0
  ./test-all-tyhpdef.sh --no-color
  ./test-all-tyhpdef.sh -- --strict
EOF
}

die() {
  echo "error: $*" >&2
  exit 1
}

is_skipped_dir_name() {
  case "$1" in
    dist|vendor) return 0 ;;
    *) return 1 ;;
  esac
}

# First-party dist runtime packages built by base-build-all.sh — not tested here.
is_skipped_runtime_package() {
  case "$1" in
    core|async|lambda|decimal|compiler) return 0 ;;
    *) return 1 ;;
  esac
}

default_job_count() {
  local n
  n="$(getconf _NPROCESSORS_ONLN 2>/dev/null || true)"
  if [[ "$n" =~ ^[1-9][0-9]*$ ]]; then
    echo "$n"
  else
    echo 1
  fi
}

# TARGETS entries are paths relative to PACKAGES_DIR (php-ext-curl, psr-log/3.0.2).
discover_targets() {
  TARGETS=()
  local dir
  local name
  local child
  local child_name
  local sorted

  for dir in "${PACKAGES_DIR}"/*; do
    if [[ ! -d "$dir" ]]; then
      continue
    fi
    name="$(basename "$dir")"
    if is_skipped_dir_name "$name" || is_skipped_runtime_package "$name"; then
      continue
    fi

    if [[ -f "$dir/tests/tyhp.json" ]]; then
      TARGETS+=("$name")
    fi

    for child in "$dir"/*; do
      if [[ ! -d "$child" ]]; then
        continue
      fi
      child_name="$(basename "$child")"
      if is_skipped_dir_name "$child_name"; then
        continue
      fi
      if [[ -f "$child/tests/tyhp.json" ]]; then
        TARGETS+=("${name}/${child_name}")
      fi
    done
  done

  if [[ ${#TARGETS[@]} -eq 0 ]]; then
    return 0
  fi
  sorted="$(printf '%s\n' "${TARGETS[@]}" | sort)"
  TARGETS=()
  while IFS= read -r name; do
    if [[ -n "$name" ]]; then
      TARGETS+=("$name")
    fi
  done <<< "$sorted"
}

# Keep TARGETS that match PACKAGE_FILTERS. An exact path matches one
# package/version. A name with no slash also matches version folders under it.
apply_package_filters() {
  local filter rel kept_one sorted
  local -a kept=() missing=()
  if [[ ${#PACKAGE_FILTERS[@]} -eq 0 ]]; then
    return 0
  fi
  for filter in "${PACKAGE_FILTERS[@]}"; do
    filter="${filter#/}"
    filter="${filter%/}"
    kept_one=0
    for rel in "${TARGETS[@]+"${TARGETS[@]}"}"; do
      if [[ "$rel" == "$filter" || "$rel" == "$filter"/* ]]; then
        kept+=("$rel")
        kept_one=1
      fi
    done
    if [[ "$kept_one" -eq 0 ]]; then
      missing+=("$filter")
    fi
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    die "no testable package matching: ${missing[*]}
Paths look like php-ext-curl or monolog-monolog/3.10.0 (relative to packages)."
  fi
  if [[ ${#kept[@]} -eq 0 ]]; then
    return 0
  fi
  sorted="$(printf '%s\n' "${kept[@]}" | sort -u)"
  TARGETS=()
  while IFS= read -r rel; do
    if [[ -n "$rel" ]]; then
      TARGETS+=("$rel")
    fi
  done <<< "$sorted"
}

list_matrix_projects() {
  local tests_dir="$1"
  local f
  local base
  MATRIX_PROJECTS=()
  shopt -s nullglob
  for f in "${tests_dir}"/tyhp-php*.json; do
    base="$(basename "$f")"
    MATRIX_PROJECTS+=("$base")
  done
  shopt -u nullglob
}

list_fail_cases() {
  local tests_dir="$1"
  local f
  FAIL_CASES=()
  shopt -s nullglob
  for f in "${tests_dir}"/fail/*.tyhp; do
    FAIL_CASES+=("$(basename "$f")")
  done
  shopt -u nullglob
}

append_lint_args() {
  if [[ ${#LINT_ARGS[@]} -gt 0 ]]; then
    printf ' %q' "${LINT_ARGS[@]}"
  fi
}

# Drop a leftover Composer install in the package root. Lint loads dependency
# tyhpdefs from the tests/tyhp.json include list.
remove_package_composer_artifacts() {
  local pkg_root="$1"
  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "would: rm -f ${pkg_root}/composer.lock"
    echo "would: rm -rf ${pkg_root}/vendor"
    return 0
  fi
  rm -f "${pkg_root}/composer.lock"
  remove_tree "${pkg_root}/vendor"
  detail "    removed composer.lock and vendor/"
}

# Step output goes to DETAIL_FILE when the parent provided one.
detail() {
  if [[ -n "$DETAIL_FILE" ]]; then
    printf '%s\n' "$*" >> "$DETAIL_FILE"
  else
    printf '%s\n' "$*"
  fi
}

detail_stream() {
  if [[ -n "$DETAIL_FILE" ]]; then
    cat >> "$DETAIL_FILE"
  else
    cat
  fi
}

run_to_detail() {
  if [[ -n "$DETAIL_FILE" ]]; then
    "$@" >>"$DETAIL_FILE" 2>&1
  else
    "$@"
  fi
}

tyhp_exec() {
  if [[ -n "${TYHP_BIN:-}" ]]; then
    "$TYHP_BIN" "$@"
  else
    dotnet "$TYHP_DLL" "$@"
  fi
}

print_tyhp_prefix() {
  if [[ -n "${TYHP_BIN:-}" ]]; then
    printf '%q' "$TYHP_BIN"
  else
    printf 'dotnet %q' "$TYHP_DLL"
  fi
}

print_positive_command() {
  local tests_dir="$1"
  local project="$2"
  printf '(cd %q && ' "$tests_dir"
  print_tyhp_prefix
  printf ' lint'
  if [[ "$project" != "tyhp.json" ]]; then
    printf ' --tyhp-project=%q' "$project"
  fi
  append_lint_args
  printf ')\n'
}

print_fail_command() {
  local tests_dir="$1"
  local case_file="$2"
  local project="$3"
  printf '(cd %q && ' "$tests_dir"
  print_tyhp_prefix
  printf ' lint --file=%q --format=json --quiet' "fail/${case_file}"
  if [[ -n "$project" && "$project" != "tyhp.json" ]]; then
    printf ' --tyhp-project=%q' "$project"
  fi
  printf ')\n'
}

run_tyhp_lint() {
  local tests_dir="$1"
  shift
  # Cache dir last so it wins over a --cache-dir forwarded in lint args.
  # Parallel jobs each get their own directory (relative cache keys collide).
  if [[ -n "${PACKAGE_CACHE_DIR:-}" ]]; then
    (cd "$tests_dir" && tyhp_exec lint "$@" --cache-dir="$PACKAGE_CACHE_DIR")
  else
    (cd "$tests_dir" && tyhp_exec lint "$@")
  fi
}

run_positive_lint() {
  local tests_dir="$1"
  local project="$2"
  if [[ "$project" == "tyhp.json" ]]; then
    if [[ ${#LINT_ARGS[@]} -gt 0 ]]; then
      run_tyhp_lint "$tests_dir" "${LINT_ARGS[@]}"
    else
      run_tyhp_lint "$tests_dir"
    fi
  else
    if [[ ${#LINT_ARGS[@]} -gt 0 ]]; then
      run_tyhp_lint "$tests_dir" --tyhp-project="$project" "${LINT_ARGS[@]}"
    else
      run_tyhp_lint "$tests_dir" --tyhp-project="$project"
    fi
  fi
}

sidecar_php_version() {
  local expect_file="$1"
  python3 "$ASSERT_EXPECT" --php-version "$expect_file" 2>/dev/null || true
}

project_for_php_version() {
  local tests_dir="$1"
  local php_ver="$2"
  if [[ -z "$php_ver" ]]; then
    echo "tyhp.json"
    return
  fi
  local candidate="tyhp-php${php_ver}.json"
  if [[ -f "${tests_dir}/${candidate}" ]]; then
    echo "$candidate"
    return
  fi
  echo "tyhp.json"
}

classify_positive_exit() {
  local code="$1"
  # 0 = success, 5 = success with warnings (Tyhp ExitCode.CompileWarning)
  if [[ "$code" -eq 0 || "$code" -eq 5 ]]; then
    return 0
  fi
  return 1
}

# --no-color wins over --color. NO_COLOR and non-TTY disable auto color;
# --color / --color=always still enable color when stdout is not a TTY.
init_colors() {
  USE_COLOR=0
  if [[ "$NO_COLOR_FLAG" -eq 0 ]]; then
    case "$COLOR_WANT" in
      always)
        USE_COLOR=1
        ;;
      never)
        USE_COLOR=0
        ;;
      auto)
        if [[ -z "${NO_COLOR-}" && -t 1 ]]; then
          USE_COLOR=1
        fi
        ;;
    esac
  fi
  if [[ "$USE_COLOR" -eq 1 ]]; then
    C_RESET=$'\033[0m'
    C_BOLD=$'\033[1m'
    C_RED=$'\033[31m'
    C_GREEN=$'\033[32m'
    C_YELLOW=$'\033[33m'
    C_CYAN=$'\033[36m'
    C_DIM=$'\033[2m'
  else
    C_RESET=""
    C_BOLD=""
    C_RED=""
    C_GREEN=""
    C_YELLOW=""
    C_CYAN=""
    C_DIM=""
  fi
}

tok_pass() { printf '%sPASS%s' "${C_GREEN}${C_BOLD}" "$C_RESET"; }
tok_fail() { printf '%sFAILED%s' "${C_RED}${C_BOLD}" "$C_RESET"; }
tok_warn() { printf '%sPASS (warnings)%s' "${C_YELLOW}${C_BOLD}" "$C_RESET"; }

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
    -v|--verbose)
      VERBOSE=1
      shift
      ;;
    --issues-file)
      if [[ $# -lt 2 || -z "$2" ]]; then
        die "--issues-file requires a file path"
      fi
      ISSUES_FILE="$2"
      shift 2
      ;;
    --issues-file=*)
      if [[ -z "${1#*=}" ]]; then
        die "--issues-file requires a file path"
      fi
      ISSUES_FILE="${1#*=}"
      shift
      ;;
    --fail-fast)
      FAIL_FAST=1
      shift
      ;;
    -j|--jobs)
      if [[ $# -lt 2 ]]; then
        die "--jobs requires a positive integer"
      fi
      JOBS="$2"
      shift 2
      ;;
    --jobs=*)
      JOBS="${1#*=}"
      shift
      ;;
    -j*)
      JOBS="${1#-j}"
      shift
      ;;
    --timeout)
      if [[ $# -lt 2 ]]; then
        die "--timeout requires a positive integer (seconds)"
      fi
      TIMEOUT_SECONDS="$2"
      shift 2
      ;;
    --timeout=*)
      TIMEOUT_SECONDS="${1#*=}"
      shift
      ;;
    --package)
      if [[ $# -lt 2 || -z "$2" ]]; then
        die "--package requires a package path (for example php-ext-curl)"
      fi
      PACKAGE_FILTERS+=("$2")
      shift 2
      ;;
    --package=*)
      if [[ -z "${1#*=}" ]]; then
        die "--package requires a package path (for example php-ext-curl)"
      fi
      PACKAGE_FILTERS+=("${1#*=}")
      shift
      ;;
    --no-color)
      NO_COLOR_FLAG=1
      shift
      ;;
    --color)
      COLOR_WANT=always
      shift
      ;;
    --color=*)
      case "${1#*=}" in
        always) COLOR_WANT=always ;;
        never) COLOR_WANT=never ;;
        auto) COLOR_WANT=auto ;;
        *) die "--color must be always, never, or auto" ;;
      esac
      shift
      ;;
    --)
      shift
      LINT_ARGS+=("$@")
      break
      ;;
    *)
      LINT_ARGS+=("$1")
      shift
      ;;
  esac
done

if [[ -n "${TYHP_BIN:-}" ]]; then
  if [[ ! -f "$TYHP_BIN" ]]; then
    die "tyhp CLI not found at: ${TYHP_BIN}"
  fi
elif [[ -z "${TYHP_DLL:-}" || -z "$TYHP_CSPROJ" || ! -f "$TYHP_CSPROJ" ]]; then
  die "tyhp.csproj not found at: ${TYHP_CSPROJ:-<unset>}
Set TYHP_DLL to a compiler build or TYHP_BIN to a released tyhp CLI."
fi
if [[ ! -f "$ASSERT_EXPECT" ]]; then
  die "fail-case helper not found at: ${ASSERT_EXPECT}"
fi
if ! command -v python3 >/dev/null 2>&1; then
  die "python3 is required to assert fail-case sidecars"
fi

if [[ -z "$JOBS" ]]; then
  JOBS="$(default_job_count)"
fi
if [[ ! "$JOBS" =~ ^[1-9][0-9]*$ ]]; then
  die "--jobs must be a positive integer (got: ${JOBS})"
fi
if [[ -z "$TIMEOUT_SECONDS" ]]; then
  TIMEOUT_SECONDS=90
fi
if [[ ! "$TIMEOUT_SECONDS" =~ ^[1-9][0-9]*$ ]]; then
  die "--timeout must be a positive integer (seconds; got: ${TIMEOUT_SECONDS})"
fi

# A worker re-exec skips discovery and the compiler build. It runs one package
# after the functions below are defined.
if [[ "${TYHPDEF_INTERNAL:-}" != 1 ]]; then
init_colors
discover_targets
apply_package_filters

total=${#TARGETS[@]}
if [[ "$total" -eq 0 ]]; then
  echo "No testable tyhpdef packages found in ${PACKAGES_DIR}."
  echo "Expected tests/tyhp.json under php, php-ext-*, or versioned Composer-lib trees."
  exit 0
fi

echo "Repo:          ${REPO_ROOT}"
echo "Compiler:      dotnet ${TYHP_DLL} lint"
echo "Packages:      ${total} (cwd = each tests/ so tyhp.json is used)"
if [[ ${#PACKAGE_FILTERS[@]} -gt 0 ]]; then
  echo "Filter:        ${PACKAGE_FILTERS[*]}"
fi
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Jobs:          ${JOBS} (dry-run prints sequentially)"
elif [[ "$JOBS" -le 1 ]]; then
  echo "Jobs:          1"
else
  echo "Jobs:          ${JOBS} (one package/version per job)"
fi
echo "Timeout:       ${TIMEOUT_SECONDS}s per package/version (lint)"
echo "Skipped:       core, async, lambda, decimal, compiler"
if [[ ${#LINT_ARGS[@]} -gt 0 ]]; then
  echo "Lint args:     ${LINT_ARGS[*]}"
fi
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Mode:          dry-run (no cleanup / lint)"
  ISSUES_FILE=""
fi
if [[ -n "$ISSUES_FILE" ]]; then
  issues_dir="$(dirname "$ISSUES_FILE")"
  if [[ ! -d "$issues_dir" ]]; then
    die "--issues-file directory does not exist: ${issues_dir}"
  fi
  ISSUES_FILE="$(cd "$issues_dir" && pwd)/$(basename "$ISSUES_FILE")"
  if ! : > "$ISSUES_FILE" 2>/dev/null; then
    die "cannot write --issues-file: ${ISSUES_FILE}"
  fi
  echo "Issues file:   ${ISSUES_FILE} (failed and warned packages)"
fi

if [[ "$DRY_RUN" -eq 0 && -z "${TYHP_BIN:-}" ]]; then
  echo
  echo "Building compiler..."
  dotnet build "$TYHP_CSPROJ" --nologo -v q
  if [[ ! -f "$TYHP_DLL" ]]; then
    die "tyhp compiler not found at: ${TYHP_DLL}
Build the compiler first: dotnet build ${TYHP_CSPROJ}"
  fi
fi
fi

# Line 1: "<kind> [reason]". Line 2 (optional): counts shown on the result line.
write_package_status() {
  printf '%s\n%s\n' "$1" "${2:-}" > "$STATUS_FILE"
}

read_status_line() {
  local line=""
  if [[ -s "$1" ]]; then
    IFS= read -r line < "$1" || true
  fi
  printf '%s' "$line"
}

record_package_status() {
  local rel="$1"
  local line kind reason
  if [[ ! -s "$STATUS_FILE" ]]; then
    FAILED+=("$rel")
    FAILED_REASONS+=("${rel}:missing-status")
    return
  fi
  line="$(read_status_line "$STATUS_FILE")"
  kind="${line%% *}"
  reason="${line#* }"
  if [[ "$kind" == "$line" ]]; then
    reason=""
  fi
  case "$kind" in
    dry) ;;
    pass) PASSED+=("$rel") ;;
    warn) WARNED+=("$rel") ;;
    fail)
      FAILED+=("$rel")
      FAILED_REASONS+=("${rel}:${reason}")
      ;;
    *)
      FAILED+=("$rel")
      FAILED_REASONS+=("${rel}:missing-status")
      ;;
  esac
}

# One package/version. Human output on stdout/stderr. One status line in STATUS_FILE.
test_one_target() {
  local rel="$1"
  local n="$2"
  local pkg_root="${PACKAGES_DIR}/${rel}"
  local tests_dir="${pkg_root}/tests"
  local project case_file stem expect_file php_ver tmpdir lint_json
  local fail_code assert_code
  local -a fail_args=()

  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo
    echo "${C_CYAN}${C_BOLD}==> [${n}/${total}] ${rel}${C_RESET}"
  fi
  detail "    ${tests_dir}"

  # Extra PHP-version matrix files are run for tyhpdef/php so gated overlay
  # contracts (8.3 gc_status, 8.4 HashContext::__debugInfo / array_find) are
  # actually checked. php-ext-* already keep tyhp-php*.json for manual lint
  # (`./tyhp.sh lint --tyhp-project=tyhp-php8.5.json`); this harness lints
  # tests/tyhp.json there.
  if [[ "$rel" == "php" ]]; then
    list_matrix_projects "$tests_dir"
  else
    MATRIX_PROJECTS=()
  fi
  list_fail_cases "$tests_dir"

  package_failed=0
  package_warned=0
  positive_ok=0
  positive_warn=0
  positive_fail=0
  fail_ok=0
  fail_skip=${#FAIL_CASES[@]}
  fail_bad=0

  if [[ "$DRY_RUN" -eq 1 ]]; then
    remove_package_composer_artifacts "$pkg_root"
    echo "would: $(print_positive_command "$tests_dir" "tyhp.json")"
    for project in "${MATRIX_PROJECTS[@]+"${MATRIX_PROJECTS[@]}"}"; do
      echo "would: $(print_positive_command "$tests_dir" "$project")"
    done
    if [[ ${#FAIL_CASES[@]} -eq 0 ]]; then
      echo "would: skip fail/ (none)"
    else
      for case_file in "${FAIL_CASES[@]}"; do
        stem="${case_file%.tyhp}"
        expect_file="${tests_dir}/fail/${stem}.expect.json"
        php_ver=""
        if [[ -f "$expect_file" ]]; then
          php_ver="$(sidecar_php_version "$expect_file")"
        fi
        project="$(project_for_php_version "$tests_dir" "$php_ver")"
        echo "would: $(print_fail_command "$tests_dir" "$case_file" "$project")"
      done
    fi
    write_package_status "dry"
    return 0
  fi

  remove_package_composer_artifacts "$pkg_root"

  run_one_positive() {
    local project="$1"
    local code=0
    set +e
    run_to_detail run_positive_lint "$tests_dir" "$project"
    code=$?
    set -e
    if [[ "$code" -eq 0 ]]; then
      detail "    positives (${project}): $(tok_pass)"
      positive_ok=$((positive_ok + 1))
      return 0
    fi
    if [[ "$code" -eq 5 ]]; then
      detail "    positives (${project}): $(tok_warn)"
      positive_ok=$((positive_ok + 1))
      positive_warn=$((positive_warn + 1))
      package_warned=1
      return 0
    fi
    detail "    positives (${project}): $(tok_fail) (exit ${code})"
    positive_fail=$((positive_fail + 1))
    package_failed=1
    return 1
  }

  run_one_positive "tyhp.json" || true
  if [[ "$package_failed" -eq 1 && "$FAIL_FAST" -eq 1 ]]; then
    write_package_status "fail positives" "positives fail=${positive_fail} ok=${positive_ok}"
    return 0
  fi

  for project in "${MATRIX_PROJECTS[@]+"${MATRIX_PROJECTS[@]}"}"; do
    run_one_positive "$project" || true
    if [[ "$package_failed" -eq 1 && "$FAIL_FAST" -eq 1 ]]; then
      break
    fi
  done
  if [[ "$package_failed" -eq 1 && "$FAIL_FAST" -eq 1 ]]; then
    write_package_status "fail positives" "positives fail=${positive_fail} ok=${positive_ok}"
    return 0
  fi

  if [[ ${#FAIL_CASES[@]} -eq 0 ]]; then
    detail "    fail/: skip (none)"
  else
    tmpdir="$(mktemp -d "${TMPDIR:-/tmp}/tyhpdef-fail.XXXXXX")"
    for case_file in "${FAIL_CASES[@]}"; do
      stem="${case_file%.tyhp}"
      expect_file="${tests_dir}/fail/${stem}.expect.json"
      if [[ ! -f "$expect_file" ]]; then
        detail "    fail ${case_file}: $(tok_fail) (missing ${stem}.expect.json)"
        fail_bad=$((fail_bad + 1))
        package_failed=1
        if [[ "$FAIL_FAST" -eq 1 ]]; then
          break
        fi
        continue
      fi
      php_ver="$(sidecar_php_version "$expect_file")"
      project="$(project_for_php_version "$tests_dir" "$php_ver")"
      lint_json="${tmpdir}/${stem}.json"
      fail_args=(--file="fail/${case_file}" --format=json --quiet)
      if [[ "$project" != "tyhp.json" ]]; then
        fail_args+=(--tyhp-project="$project")
      fi
      set +e
      run_tyhp_lint "$tests_dir" "${fail_args[@]}" >"$lint_json" 2>"${tmpdir}/${stem}.err"
      fail_code=$?
      set -e
      # Compile errors (4) are expected for fail cases; 0 would mean no diagnostics.
      if [[ "$fail_code" -ne 0 && "$fail_code" -ne 4 && "$fail_code" -ne 5 ]]; then
        detail "    fail ${case_file}: $(tok_fail) (tyhp exit ${fail_code})"
        if [[ -s "${tmpdir}/${stem}.err" ]]; then
          sed 's/^/      /' "${tmpdir}/${stem}.err" | detail_stream || true
        fi
        fail_bad=$((fail_bad + 1))
        package_failed=1
        if [[ "$FAIL_FAST" -eq 1 ]]; then
          break
        fi
        continue
      fi
      set +e
      run_to_detail python3 "$ASSERT_EXPECT" "$expect_file" "$lint_json"
      assert_code=$?
      set -e
      if [[ "$assert_code" -eq 0 ]]; then
        detail "    fail ${case_file}: $(tok_pass)"
        fail_ok=$((fail_ok + 1))
      else
        detail "    fail ${case_file}: $(tok_fail)"
        fail_bad=$((fail_bad + 1))
        package_failed=1
        if [[ "$FAIL_FAST" -eq 1 ]]; then
          break
        fi
      fi
    done
    rm -rf "$tmpdir"
    if [[ "$package_failed" -eq 1 && "$FAIL_FAST" -eq 1 ]]; then
      write_package_status "fail fail-cases" \
        "positives fail=${positive_fail} ok=${positive_ok}; fail-cases fail=${fail_bad} ok=${fail_ok}"
      return 0
    fi
  fi

  if [[ "$package_failed" -eq 1 ]]; then
    write_package_status "fail positives=${positive_fail},fail=${fail_bad}" \
      "positives fail=${positive_fail} ok=${positive_ok}; fail-cases fail=${fail_bad} ok=${fail_ok}"
  elif [[ "$package_warned" -eq 1 ]]; then
    write_package_status "warn" "positives ${positive_ok}, fail-cases ${fail_ok}"
  else
    write_package_status "pass" "positives ${positive_ok}, fail-cases ${fail_ok}"
  fi
}

# Wall-clock seconds since the epoch. Comparable across processes.
now_epoch() {
  python3 -c 'import time; print("%.6f" % time.time())'
}

elapsed_since() {
  awk -v start="$1" -v end="$2" 'BEGIN {
    d = end - start
    if (d < 0) d = 0
    printf "%.3f", d
  }'
}

format_duration() {
  awk -v s="$1" 'BEGIN { printf "%s", fmt_duration(s) }
    function fmt_duration(s,    h, m) {
      if (s < 0) s = 0
      if (s >= 3600) {
        h = int(s / 3600)
        m = int((s - h * 3600) / 60)
        return sprintf("%dh %dm %.1fs", h, m, s - h * 3600 - m * 60)
      }
      if (s >= 60) {
        m = int(s / 60)
        return sprintf("%dm %.1fs", m, s - m * 60)
      }
      return sprintf("%.1fs", s)
    }'
}

TIMED_RELS=()
TIMED_SECS=()

note_package_time() {
  local rel="$1"
  local seconds="$2"
  TIMED_RELS+=("$rel")
  TIMED_SECS+=("$seconds")
}

# One result line per package; step details and stray worker output follow
# only on failure (or always with --verbose).
report_package() {
  local rel="$1"
  local idx="$2"
  local status_file="$3"
  local detail_file="$4"
  local log_file="$5"
  local seconds="$6"
  local line kind reason counts="" token label timing
  line="$(read_status_line "$status_file")"
  kind="${line%% *}"
  reason=""
  if [[ "$kind" != "$line" ]]; then
    reason="${line#* }"
  fi
  if [[ -s "$status_file" ]]; then
    counts="$(sed -n '2p' "$status_file")"
  fi
  case "$kind" in
    pass) token="$(tok_pass)"; label="$rel" ;;
    warn) token="$(tok_warn)"; label="$rel" ;;
    *)
      token="$(tok_fail)"
      label="${C_RED}${rel}${C_RESET}"
      if [[ -z "$counts" ]]; then
        counts="${reason:-missing status}"
      fi
      ;;
  esac
  timing="$(format_duration "$seconds")"
  echo "${token} [${idx}/${total}] ${label} (${counts}) ${C_DIM}${timing}${C_RESET}"
  if [[ "$kind" != pass && "$kind" != warn ]] || [[ "$VERBOSE" -eq 1 ]]; then
    if [[ -s "$detail_file" ]]; then
      cat "$detail_file"
    fi
  fi
  if [[ -s "$log_file" ]]; then
    cat "$log_file"
  fi
  if [[ -n "$ISSUES_FILE" && "$kind" != pass ]]; then
    {
      echo "==> ${token} ${rel} (${counts}) ${timing}"
      if [[ -s "$detail_file" ]]; then
        cat "$detail_file"
      fi
      if [[ -s "$log_file" ]]; then
        cat "$log_file"
      fi
      echo
    } | strip_ansi >> "$ISSUES_FILE"
    ISSUE_COUNT=$((ISSUE_COUNT + 1))
  fi
}

strip_ansi() {
  sed $'s/\033\\[[0-9;]*m//g'
}

print_timing_summary() {
  local count=${#TIMED_RELS[@]}
  local overall i
  if [[ "$count" -eq 0 ]]; then
    return 0
  fi
  overall="$(elapsed_since "$OVERALL_START" "$OVERALL_END")"
  {
    printf '%s\n' "$overall"
    for i in "${!TIMED_RELS[@]}"; do
      printf '%s\t%s\n' "${TIMED_SECS[$i]}" "${TIMED_RELS[$i]}"
    done
  } | awk '
    function fmt(s,    h, m) {
      if (s < 0) s = 0
      if (s >= 3600) {
        h = int(s / 3600)
        m = int((s - h * 3600) / 60)
        return sprintf("%dh %dm %.1fs", h, m, s - h * 3600 - m * 60)
      }
      if (s >= 60) {
        m = int(s / 60)
        return sprintf("%dm %.1fs", m, s - m * 60)
      }
      return sprintf("%.1fs", s)
    }
    NR == 1 { overall = $1 + 0; next }
    {
      sec = $1 + 0
      name = substr($0, index($0, "\t") + 1)
      sum += sec
      n++
      if (n == 1 || sec < fastest) { fastest = sec; fastest_name = name }
      if (n == 1 || sec > slowest) { slowest = sec; slowest_name = name }
    }
    END {
      if (n == 0) exit
      printf "\n%sTiming%s\n", "'"${C_BOLD}"'", "'"${C_RESET}"'"
      printf "  overall:  %s\n", fmt(overall)
      printf "  average:  %s\n", fmt(sum / n)
      printf "  fastest:  %s  %s\n", fmt(fastest), fastest_name
      printf "  slowest:  %s  %s\n", fmt(slowest), slowest_name
    }
  '
}

abort_fail_fast() {
  if [[ "$JOBS" -le 1 ]]; then
    echo "${C_RED}${C_BOLD}Aborting (--fail-fast).${C_RESET}" >&2
  else
    echo "${C_RED}${C_BOLD}No further packages will start (--fail-fast).${C_RESET}" >&2
  fi
}

# A timed-out lint can still be creating cache files when this runs. Retry so
# "Directory not empty" from that race is not reported as a script error.
remove_tree() {
  local path="$1"
  local attempt=0
  if [[ -z "$path" || ! -e "$path" ]]; then
    return 0
  fi
  while [[ "$attempt" -lt 5 ]]; do
    if rm -rf "$path" 2>/dev/null; then
      return 0
    fi
    attempt=$((attempt + 1))
    sleep 0.2
  done
  rm -rf "$path" 2>/dev/null || true
}

# Re-exec this script as a new session so a timeout can stop the lints.
# The parent reads STATUS_FILE; this process does not return a package result
# on its exit code.
enter_internal_package() {
  STATUS_FILE="${TYHPDEF_STATUS:?}"
  DETAIL_FILE="${TYHPDEF_DETAIL:-}"
  PACKAGE_CACHE_DIR="${TYHPDEF_CACHE:-}"
  FAIL_FAST="${TYHPDEF_FAIL_FAST:-0}"
  if [[ -n "${TYHPDEF_LINT_ARGS:-}" ]]; then
    eval "LINT_ARGS=(${TYHPDEF_LINT_ARGS})"
  fi
  if [[ "${TYHPDEF_USE_COLOR:-0}" == 1 ]]; then
    USE_COLOR=1
    C_RESET=$'\033[0m'
    C_BOLD=$'\033[1m'
    C_RED=$'\033[31m'
    C_GREEN=$'\033[32m'
    C_YELLOW=$'\033[33m'
    C_CYAN=$'\033[36m'
    C_DIM=$'\033[2m'
  fi
  if [[ -n "$PACKAGE_CACHE_DIR" ]]; then
    mkdir -p "$PACKAGE_CACHE_DIR"
  fi
  total="${TYHPDEF_TOTAL:?}"
  test_one_target "${TYHPDEF_REL:?}" "${TYHPDEF_N:?}"
}

# Run one package/version with a wall-clock limit covering every lint.
run_target_bounded() {
  local rel="$1"
  local n="$2"
  local lint_quoted=""
  if [[ ${#LINT_ARGS[@]} -gt 0 ]]; then
    lint_quoted="$(printf '%q ' "${LINT_ARGS[@]}")"
  fi
  TYHPDEF_INTERNAL=1 \
  TYHPDEF_REL="$rel" \
  TYHPDEF_N="$n" \
  TYHPDEF_TOTAL="$total" \
  TYHPDEF_STATUS="$STATUS_FILE" \
  TYHPDEF_DETAIL="${DETAIL_FILE:-}" \
  TYHPDEF_CACHE="${PACKAGE_CACHE_DIR:-}" \
  TYHPDEF_FAIL_FAST="$FAIL_FAST" \
  TYHPDEF_USE_COLOR="$USE_COLOR" \
  TYHPDEF_LINT_ARGS="$lint_quoted" \
  TYHP_DLL="${TYHP_DLL:-}" \
  TYHP_BIN="${TYHP_BIN:-}" \
  python3 -c '
import os, signal, subprocess, sys
timeout = float(sys.argv[1])
script = sys.argv[2]
proc = subprocess.Popen(["bash", script], start_new_session=True)
try:
    proc.wait(timeout=timeout)
except subprocess.TimeoutExpired:
    try:
        os.killpg(proc.pid, signal.SIGTERM)
    except OSError:
        pass
    try:
        proc.wait(timeout=5)
    except subprocess.TimeoutExpired:
        try:
            os.killpg(proc.pid, signal.SIGKILL)
        except OSError:
            pass
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            pass
    status = os.environ.get("TYHPDEF_STATUS", "")
    if status:
        with open(status, "w", encoding="utf-8") as fh:
            fh.write("fail timeout\n")
    sys.stderr.write(
        "    timeout: lint exceeded %ss; aborted this package\n" % int(timeout)
    )
' "$TIMEOUT_SECONDS" "$0"
}

run_targets_sequential() {
  local rel line started seconds log_file
  for rel in "${TARGETS[@]}"; do
    n=$((n + 1))
    STATUS_FILE="$(mktemp "${TMPDIR:-/tmp}/tyhpdef-status.XXXXXX")"
    PACKAGE_CACHE_DIR=""
    started="$(now_epoch)"
    if [[ "$DRY_RUN" -eq 1 ]]; then
      test_one_target "$rel" "$n"
      note_package_time "$rel" "$(elapsed_since "$started" "$(now_epoch)")"
      rm -f "$STATUS_FILE"
      continue
    fi
    DETAIL_FILE="$(mktemp "${TMPDIR:-/tmp}/tyhpdef-detail.XXXXXX")"
    log_file="$(mktemp "${TMPDIR:-/tmp}/tyhpdef-log.XXXXXX")"
    echo "${C_DIM}start [${n}/${total}] ${rel}${C_RESET}"
    run_target_bounded "$rel" "$n" >"$log_file" 2>&1 || true
    seconds="$(elapsed_since "$started" "$(now_epoch)")"
    note_package_time "$rel" "$seconds"
    report_package "$rel" "$n" "$STATUS_FILE" "$DETAIL_FILE" "$log_file" "$seconds"
    line="$(read_status_line "$STATUS_FILE")"
    record_package_status "$rel"
    rm -f "$STATUS_FILE" "$DETAIL_FILE" "$log_file"
    DETAIL_FILE=""
    if [[ "$FAIL_FAST" -eq 1 && "$line" == fail* ]]; then
      abort_fail_fast
      break
    fi
  done
}

run_targets_parallel() {
  local job_tmp next running stop i rel line ended seconds
  local -a slot_pid=() slot_rel=() slot_idx=() slot_status=() slot_detail=() slot_log=() slot_start=() slot_time=()
  job_tmp="$(mktemp -d "${TMPDIR:-/tmp}/tyhpdef-jobs.XXXXXX")"
  next=0
  running=0
  stop=0

  while [[ "$running" -gt 0 || ( "$stop" -eq 0 && "$next" -lt "$total" ) ]]; do
    i=0
    while [[ "$i" -lt "$JOBS" ]]; do
      if [[ -n "${slot_pid[$i]:-}" && -f "${slot_status[$i]}.done" ]]; then
        wait "${slot_pid[$i]}" || true
        ended="$(cat "${slot_time[$i]}" 2>/dev/null || now_epoch)"
        seconds="$(elapsed_since "${slot_start[$i]}" "$ended")"
        note_package_time "${slot_rel[$i]}" "$seconds"
        STATUS_FILE="${slot_status[$i]}"
        report_package "${slot_rel[$i]}" "${slot_idx[$i]}" "$STATUS_FILE" \
          "${slot_detail[$i]}" "${slot_log[$i]}" "$seconds"
        line="$(read_status_line "$STATUS_FILE")"
        record_package_status "${slot_rel[$i]}"
        n=$((n + 1))
        rm -f "$STATUS_FILE" "${STATUS_FILE}.done" "${slot_detail[$i]}" "${slot_log[$i]}"
        slot_pid[$i]=""
        running=$((running - 1))
        if [[ "$FAIL_FAST" -eq 1 && "$stop" -eq 0 && "$line" == fail* ]]; then
          stop=1
          abort_fail_fast
        fi
      fi
      if [[ -z "${slot_pid[$i]:-}" && "$stop" -eq 0 && "$next" -lt "$total" ]]; then
        rel="${TARGETS[$next]}"
        next=$((next + 1))
        slot_rel[$i]="$rel"
        slot_idx[$i]="$next"
        slot_status[$i]="${job_tmp}/status-${next}"
        slot_detail[$i]="${job_tmp}/detail-${next}"
        slot_log[$i]="${job_tmp}/log-${next}"
        slot_time[$i]="${job_tmp}/time-${next}"
        slot_start[$i]="$(now_epoch)"
        echo "${C_DIM}start [${next}/${total}] ${rel}${C_RESET}"
        (
          STATUS_FILE="${slot_status[$i]}"
          DETAIL_FILE="${slot_detail[$i]}"
          PACKAGE_CACHE_DIR="${job_tmp}/cache-${next}"
          time_file="${slot_time[$i]}"
          mkdir -p "$PACKAGE_CACHE_DIR"
          trap '[[ -s "$STATUS_FILE" ]] || printf "%s\n" "fail worker" > "$STATUS_FILE"; touch "${STATUS_FILE}.done"; remove_tree "$PACKAGE_CACHE_DIR"' EXIT
          run_target_bounded "$rel" "$next"
          now_epoch > "$time_file"
        ) >"${slot_log[$i]}" 2>&1 &
        slot_pid[$i]=$!
        running=$((running + 1))
      fi
      i=$((i + 1))
    done
    if [[ "$running" -gt 0 ]]; then
      sleep 0.2
    fi
  done
  remove_tree "$job_tmp"
}

PASSED=()
WARNED=()
FAILED=()
FAILED_REASONS=()

if [[ "${TYHPDEF_INTERNAL:-}" == 1 ]]; then
  enter_internal_package
  exit 0
fi

n=0
OVERALL_START="$(now_epoch)"
if [[ "$DRY_RUN" -eq 1 || "$JOBS" -le 1 ]]; then
  run_targets_sequential
else
  run_targets_parallel
fi
OVERALL_END="$(now_epoch)"

echo
echo "${C_BOLD}Summary${C_RESET}"
echo "  considered: ${n}/${total}"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "  dry-run:    printed cleanup / lint / fail-case commands for ${n} package(s); nothing ran"
else
  if [[ ${#PASSED[@]} -gt 0 ]]; then
    echo "  passed:     ${C_GREEN}${#PASSED[@]}${C_RESET}"
  else
    echo "  passed:     ${#PASSED[@]}"
  fi
  if [[ ${#WARNED[@]} -gt 0 ]]; then
    echo "  warned:     ${C_YELLOW}${#WARNED[@]}${C_RESET} (exit 5 on a positive lint)"
  else
    echo "  warned:     ${#WARNED[@]} (exit 5 on a positive lint)"
  fi
  if [[ ${#FAILED[@]} -gt 0 ]]; then
    echo "  failed:     ${C_RED}${#FAILED[@]}${C_RESET}"
  else
    echo "  failed:     ${#FAILED[@]}"
  fi
  if [[ ${#PASSED[@]} -gt 0 ]]; then
    echo "  passed names: ${PASSED[*]}"
  fi
  if [[ ${#WARNED[@]} -gt 0 ]]; then
    echo "  warned names: ${WARNED[*]}"
  fi
  if [[ ${#FAILED[@]} -gt 0 ]]; then
    echo "  failed names: ${C_RED}${FAILED[*]}${C_RESET}"
    echo "  failed reasons: ${FAILED_REASONS[*]}"
  fi
  echo
  if [[ ${#FAILED[@]} -gt 0 ]]; then
    echo "${C_RED}${C_BOLD}FAILED${C_RESET}  ${#FAILED[@]} failed, ${#PASSED[@]} passed, ${#WARNED[@]} warned, ${n} considered"
  elif [[ ${#WARNED[@]} -gt 0 ]]; then
    echo "${C_YELLOW}${C_BOLD}PASSED (warnings)${C_RESET}  ${#PASSED[@]} passed, ${#WARNED[@]} warned, ${n} considered"
  else
    echo "${C_GREEN}${C_BOLD}PASSED${C_RESET}  ${#PASSED[@]} passed, ${n} considered"
  fi
  if [[ -n "$ISSUES_FILE" ]]; then
    if [[ "$ISSUE_COUNT" -gt 0 ]]; then
      echo "Issues:  ${ISSUE_COUNT} package(s) written to ${ISSUES_FILE}"
    else
      echo "Issues:  none (${ISSUES_FILE} is empty)"
    fi
  fi
fi

print_timing_summary

if [[ ${#FAILED[@]} -gt 0 ]]; then
  exit 1
fi
exit 0
