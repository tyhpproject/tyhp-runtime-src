#!/bin/bash
# Rebuild every first-party dist package (all PHP versions) plus single-version packages.
# Pass a package name to rebuild only that one: core, decimal, async, lambda, or compiler.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" && pwd)"
TYHP_DLL="${TYHP_DLL:-$COMPILER_ROOT/bin/Debug/net9.0/tyhp.dll}"

cd "$SCRIPT_DIR"

# shellcheck source=build-common.sh
source "$SCRIPT_DIR/build-common.sh"
require_package_selection "$@"

if [[ ! -f "$TYHP_DLL" ]]; then
  echo "tyhp compiler not found at: $TYHP_DLL" >&2
  echo "Build the compiler first: dotnet build $COMPILER_ROOT/tyhp.csproj" >&2
  exit 1
fi

build_package() {
  local pkg="$1"
  local php_major
  local php_version
  local label
  local rest

  load_package_release_version "$pkg" || return $?

  clear_package_cache "$pkg" || return $?
  run_composer_update "$pkg" || return $?

  for entry in "${DIST_BUILDS[@]}"; do
    php_major="${entry%%:*}"
    rest="${entry#*:}"
    php_version="${rest%%:*}"
    label="${rest#*:}"

    run_tyhp_build "$pkg" "$php_major" "$php_version" "$label" || return $?
  done

  return 0
}

assert_selected_dist_package_versions

for pkg in "${DIST_PACKAGES[@]}"; do
  should_build_package "$pkg" || continue
  build_package "$pkg"
done

for pkg in "${SINGLE_VERSION_PACKAGES[@]}"; do
  should_build_package "$pkg" || continue
  clear_package_cache "$pkg"
  run_single_version_build "$pkg"
done

if [[ -n "$SELECTED_PACKAGE" ]]; then
  echo "Package ${SELECTED_PACKAGE} built successfully."
else
  echo "All packages built successfully."
fi

echo "Verifying source maps..."
python3 "$SCRIPT_DIR/verify-sourcemaps.py"

echo "Checking PER-CS 3.0 (PHP-CS-Fixer @PER-CS3x0)..."
if command -v php-cs-fixer >/dev/null 2>&1; then
  php-cs-fixer fix --dry-run --diff --config="$SCRIPT_DIR/.php-cs-fixer.php"
elif [[ -x "$SCRIPT_DIR/vendor/bin/php-cs-fixer" ]]; then
  "$SCRIPT_DIR/vendor/bin/php-cs-fixer" fix --dry-run --diff --config="$SCRIPT_DIR/.php-cs-fixer.php"
else
  echo "php-cs-fixer not found. Install friendsofphp/php-cs-fixer to run the PER-CS 3.0 gate." >&2
  exit 1
fi
