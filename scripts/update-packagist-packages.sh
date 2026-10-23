#!/usr/bin/env bash
# Queue Packagist's Update button for published packages whose git tags are
# not on Packagist yet. Does not build, clone, tag, or push.
#
# Reads scripts/packagist.credentials (gitignored): PACKAGIST_USERNAME and
# PACKAGIST_API_TOKEN (MAIN or SAFE token). POST /api/update-package is the
# same crawl as the Update button on a package page.
#
# A package needs an update when a tag on its tyhpproject-packages repo is
# missing from https://repo.packagist.org/p2/. Packages that are not
# registered yet are listed and left alone; register them with
# scripts/publish-runtime-packages.sh --create-packagist-packages.

set -euo pipefail

readonly HELPER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=publish-runtime-packages.sh
source "${HELPER_DIR}/publish-runtime-packages.sh"

ONLY_PACKAGE=""
PACKAGE_FILTER_SET=0
PACKAGIST_PROGRESS_ACTIVE=0

# One status line on the terminal. The next update clears it and reprints.
packagist_progress_show() {
  if [[ -t 2 ]]; then
    printf '\r\033[K%s' "$1" >&2
    PACKAGIST_PROGRESS_ACTIVE=1
    return 0
  fi
  printf '%s\n' "$1" >&2
  PACKAGIST_PROGRESS_ACTIVE=0
}

packagist_progress_break() {
  if [[ "$PACKAGIST_PROGRESS_ACTIVE" -eq 1 ]]; then
    printf '\r\033[K' >&2
    PACKAGIST_PROGRESS_ACTIVE=0
  fi
}

helper_usage() {
  cat <<'EOF'
Usage: scripts/update-packagist-packages.sh [options]

Queue a Packagist update for each published package that has a git tag
Packagist has not crawled yet.

Options:
  -h, --help        Show this help
  --dry-run         Print the packages that would be updated. Does not POST.
  --package NAME    Limit the check to one package. NAME is a folder name
                    (core, php, psr-log/3.0.2) or a Composer name
                    (tyhp/core, tyhpdef/php).

Requires scripts/packagist.credentials. Packagist crawls after it accepts
the request, the same way the Update button does.

Examples:
  scripts/update-packagist-packages.sh --dry-run
  scripts/update-packagist-packages.sh --package tyhp/core
  scripts/update-packagist-packages.sh
EOF
}

parse_helper_args() {
  ONLY_PACKAGE=""
  PACKAGE_FILTER_SET=0
  PACKAGIST_UPDATE_DRY_RUN=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
      -h|--help)
        helper_usage
        exit 0
        ;;
      --dry-run)
        PACKAGIST_UPDATE_DRY_RUN=1
        shift
        ;;
      --package)
        if [[ $# -lt 2 ]]; then
          echo "error: --package requires a package name." >&2
          exit 1
        fi
        if [[ "$PACKAGE_FILTER_SET" -eq 1 ]]; then
          echo "error: --package may be specified only once." >&2
          exit 1
        fi
        PACKAGE_FILTER_SET=1
        ONLY_PACKAGE="$2"
        shift 2
        ;;
      --package=*)
        if [[ "$PACKAGE_FILTER_SET" -eq 1 ]]; then
          echo "error: --package may be specified only once." >&2
          exit 1
        fi
        PACKAGE_FILTER_SET=1
        ONLY_PACKAGE="${1#--package=}"
        shift
        ;;
      *)
        echo "error: unknown argument: $1" >&2
        helper_usage >&2
        exit 1
        ;;
    esac
  done
}

helper_main() {
  parse_helper_args "$@"
  require_tool php
  require_tool git
  require_tool gh

  if [[ ! -f "$PACKAGIST_CREDENTIALS_FILE" ]]; then
    echo "error: ${PACKAGIST_CREDENTIALS_FILE} is missing." >&2
    echo "Add PACKAGIST_USERNAME and PACKAGIST_API_TOKEN from https://packagist.org/profile/" >&2
    exit 1
  fi
  validate_packagist_credentials
  PACKAGIST_CREDENTIALS_READY=1

  cd "$PROJECT_ROOT"
  discover_packages
  load_github_repo_cache
  if [[ ${#GITHUB_KNOWN_REPOS[@]} -eq 0 ]]; then
    seed_github_repo_cache_from_org || true
  fi

  if [[ "$PACKAGE_FILTER_SET" -eq 1 ]]; then
    filter_to_requested_package "$ONLY_PACKAGE"
    echo "Checking only ${ALL_PACKAGES[0]}"
  fi

  collect_publish_matches
  if [[ ${#MATCHED_PACKAGES[@]} -eq 0 ]]; then
    echo "No published packages to check."
    return 0
  fi

  local total="${#MATCHED_PACKAGES[@]}"
  local index=0
  local pkg
  echo "Checking Packagist for ${total} package(s)..."
  for pkg in "${MATCHED_PACKAGES[@]}"; do
    index=$((index + 1))
    packagist_progress_show "${index} of ${total}  ${pkg}"
    refresh_packagist_package "$pkg" || true
  done
  packagist_progress_break

  if [[ "$PACKAGIST_UPDATE_DRY_RUN" -eq 1 && ${#PACKAGIST_UPDATED_NAMES[@]} -gt 0 ]]; then
    echo "Would queue Packagist updates: ${PACKAGIST_UPDATED_NAMES[*]}"
  elif [[ ${#PACKAGIST_UPDATED_NAMES[@]} -gt 0 ]]; then
    echo "Packagist update queued: ${PACKAGIST_UPDATED_NAMES[*]}"
  else
    echo "No Packagist updates needed."
  fi
  if [[ ${#PACKAGIST_CURRENT_NAMES[@]} -gt 0 ]]; then
    echo "Packagist already current: ${#PACKAGIST_CURRENT_NAMES[@]} package(s)"
  fi
  if [[ ${#PACKAGIST_UNREGISTERED_NAMES[@]} -gt 0 ]]; then
    echo "Not on Packagist: ${PACKAGIST_UNREGISTERED_NAMES[*]}" >&2
  fi
  if [[ ${#PACKAGIST_UPDATE_FAILED_NAMES[@]} -gt 0 ]]; then
    echo "Failed Packagist updates: ${PACKAGIST_UPDATE_FAILED_NAMES[*]}" >&2
    return 1
  fi
}

helper_main "$@"
