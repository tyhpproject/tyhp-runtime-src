#!/bin/bash
# Dry-run every first-party dist package (all PHP versions) plus single-version packages.
# Pass a package name to dry-run only that one: core, decimal, async, lambda, or compiler.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" && pwd)"
TYHP_DLL="${TYHP_DLL:-$COMPILER_ROOT/bin/Debug/net9.0/tyhp.dll}"

# shellcheck source=build-common.sh
source "$SCRIPT_DIR/build-common.sh"
require_package_selection "$@"

if [[ ! -f "$TYHP_DLL" ]]; then
  echo "tyhp compiler not found at: $TYHP_DLL" >&2
  echo "Build the compiler first: dotnet build $COMPILER_ROOT/tyhp.csproj" >&2
  exit 1
fi

FAILED=()

build_package_target() {
  local pkg="$1"
  local label="$2"
  local php_version="${3:-}"
  local code

  echo "==> Building (DRY RUN) $pkg ($label)"
  set +e
  if [[ -n "$php_version" ]]; then
    (cd "$SCRIPT_DIR/$pkg" && dotnet "$TYHP_DLL" build --dry-run --output:phpVersion="$php_version")
  else
    (cd "$SCRIPT_DIR/$pkg" && dotnet "$TYHP_DLL" build --dry-run)
  fi
  code=$?
  set -e

  echo "Build (DRY RUN) returned: $code"

  # Exit code 5 (ExitCode.CompileWarning) is a clean build with warnings; only 0 and 5 are OK.
  # Every package is attempted even after a failure, so one broken package still reports the
  # state of the rest.
  if [[ $code -ne 0 && $code -ne 5 ]]; then
    FAILED+=("$pkg ($label)")
  fi
}

build_package() {
  local pkg="$1"
  local php_version
  local label
  local rest

  run_composer_update "$pkg" || { FAILED+=("$pkg"); return 0; }

  for entry in "${DIST_BUILDS[@]}"; do
    rest="${entry#*:}"
    php_version="${rest%%:*}"
    label="${rest#*:}"
    build_package_target "$pkg" "$label" "$php_version"
  done
}

for pkg in "${DIST_PACKAGES[@]}"; do
  should_build_package "$pkg" || continue
  build_package "$pkg"
done

for pkg in "${SINGLE_VERSION_PACKAGES[@]}"; do
  should_build_package "$pkg" || continue
  run_composer_update "$pkg" || { FAILED+=("$pkg"); continue; }
  build_package_target "$pkg" "tyhp.json"
done

if [[ ${#FAILED[@]} -gt 0 ]]; then
  echo "Build (DRY RUN) FAILED for: ${FAILED[*]}" >&2
  exit 1
fi

if [[ -n "$SELECTED_PACKAGE" ]]; then
  echo "Package ${SELECTED_PACKAGE} built (DRY RUN) successfully."
else
  echo "All packages built (DRY RUN) successfully."
fi
