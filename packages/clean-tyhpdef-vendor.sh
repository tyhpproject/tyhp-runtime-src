#!/usr/bin/env bash
# Remove Composer install leftovers from tyhpdef runtime package trees.
#
# Deletes only:
#   vendor/ directories
#   composer.lock files
# under packages/<name>/<version>/ (a version directory that contains
# composer.json). One path argument cleans that package directory instead.
#
#   clean-tyhpdef-vendor.sh
#       Clean every packages/<name>/<version>/ that has composer.json.
#       Does not touch flat packages (php, php-ext-*, core, compiler, …).
#
#   clean-tyhpdef-vendor.sh <package-dir>
#       Clean vendor/ and composer.lock in that one directory. The directory
#       must be packages/<name> or packages/<name>/<version>
#       and must not be packages/compiler.
#
# Never deletes docs/vendor, packages/compiler/vendor, a repo-root
# vendor/, anything outside packages/, overlay files, Layer 1
# tyhpdefs, or src/.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PACKAGES_DIR="$SCRIPT_DIR"

usage() {
  cat <<'EOF'
Usage: clean-tyhpdef-vendor.sh [<package-dir>]

With no argument, remove vendor/ and composer.lock from every
packages/<name>/<version>/ directory that contains composer.json.

With a package directory, remove vendor/ and composer.lock only in that
directory. The directory must sit under packages/ and must not be
packages/compiler.

Does not remove docs/vendor, packages/compiler/vendor, a repository
root vendor/, or anything outside packages/. Overlay files, Layer 1
tyhpdefs, and src/ are left in place.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ $# -gt 1 ]]; then
  usage >&2
  exit 2
fi

# Refuse paths that would wipe unrelated Composer trees.
assert_safe_package_dir() {
  local dir="$1"
  local real packages_real

  if [[ ! -d "$dir" ]]; then
    echo "Not a directory: $dir" >&2
    exit 1
  fi

  real="$(cd "$dir" && pwd)"
  packages_real="$(cd "$PACKAGES_DIR" && pwd)"

  case "$real" in
    "$packages_real")
      echo "Refusing to clean the packages directory itself: $real" >&2
      exit 1
      ;;
    "$packages_real"/*) ;;
    *)
      echo "Refusing to clean outside packages: $real" >&2
      exit 1
      ;;
  esac

  case "$real" in
    "$packages_real"/compiler|"$packages_real"/compiler/*)
      echo "Refusing to clean packages/compiler: $real" >&2
      exit 1
      ;;
  esac

  # Only the package directory's own vendor/ and composer.lock.
  # Never walk upward or into a nested checkout outside this path.
  if [[ -L "$real/vendor" ]]; then
    rm -f "$real/vendor"
  elif [[ -d "$real/vendor" ]]; then
    rm -rf "$real/vendor"
  fi
  if [[ -e "$real/composer.lock" || -L "$real/composer.lock" ]]; then
    rm -f "$real/composer.lock"
  fi
  echo "Cleaned $real"
}

if [[ $# -eq 1 ]]; then
  assert_safe_package_dir "$1"
  exit 0
fi

# All version directories: packages/<name>/<version>/composer.json.
# Flat trees (packages/php/composer.json) are not selected.
shopt -s nullglob
cleaned=0
for composer_json in "$PACKAGES_DIR"/*/*/composer.json; do
  assert_safe_package_dir "$(dirname "$composer_json")"
  cleaned=$((cleaned + 1))
done
shopt -u nullglob

echo "Cleaned ${cleaned} package version directories."
