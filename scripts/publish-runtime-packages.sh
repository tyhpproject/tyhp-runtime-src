#!/usr/bin/env bash
# Build runtime packages in this repo and publish installable trees to
# tyhpproject-packages/{package} repositories. Discovers packages from
# packages/tyhp/*/composer.json (compiled runtimes and the compiler),
# packages/tyhpdef/*/composer.json (tyhpdef/php, php-ext-*) and
# packages/tyhpdef/*/*/composer.json (versioned Composer-lib wrappers).
# Ignores packages/dist and any path named vendor. If a GitHub repo
# does not exist yet, that package is skipped with a warning unless
# --create-github-repos is passed, in which case an empty public repo is
# created (same gh repo create as ensure-existing-github-repos.sh: no README,
# license, or .gitignore; no local remote add). After the first create, each
# later create waits GITHUB_CREATE_INTER_DELAY seconds. Other GitHub calls wait
# GITHUB_REQUEST_DELAY seconds and retry timeouts and rate limits. Repos
# already confirmed are recorded in .github-repo-cache (gitignored) and are
# not looked up again. If a later clone or push finds one of those repos
# missing, the script creates it and retries that operation. Not a
# submodule workflow.
#
# Pass --package NAME to publish one package (and leave the other GitHub repos
# untouched). NAME is a package folder name (php, php-ext-bz2, core,
# compiler, psr-log/3.0.2) or a Composer name (tyhpdef/php, tyhp/core,
# tyhp/compiler). Without --package, every discovered package with a GitHub
# repo is published.
#
# Pass --list to discover packages, apply --package if given, and check that
# each GitHub repo exists — the same match set as a real publish — then print
# that set and exit without building, cloning, tagging, or pushing.
#
# Versioned Composer-libs publish two GitHub repos on tyhpproject-packages:
# <vendor>-<name> (public metapackage, tag = upstream version) and
# <vendor>-<name>-impl (implementation, tag = four-part composer.json version).
# generate-meta-package.sh writes the public tree. The source folder is
# packages/tyhpdef/<vendor>-<name>/<upstream>/. A handed-off folder does not retag a
# public version that already exists and does not publish an impl tag. A
# public version that has never been tagged is published once from the
# handed-off metapackage (no impl require). Before cloning, the script runs
# the same tag-tree comparison as scripts/check-version-bumps.sh (batched
# GitHub requests, no checkout). A tag that already exists is not cloned; a
# red warning is printed when that tree differs from the source (a change
# without a version bump). A repo is cloned only to publish a tag that does
# not exist yet, and then only with a shallow clone. First-party
# php, php-ext-*, and compiled tyhp/* stay one repository each.
#
# tyhpdef/* packages (php, php-ext-*, Composer-lib wrappers) use that
# composer.json version as the Packagist / git tag (for example 1.0.0 or 0.1).
# They are one tree across PHP 8.2–8.5; they do not get 80N.X.Y tags.
#
# Compiled tyhp/* packages (DIST_PACKAGES in packages/build-common.sh: core,
# decimal, async, lambda, parallel, io) live under packages/tyhp/ and are tagged once per PHP
# target (802 / 803 / 804 / 805). Package MAJOR is the emit
# PHP. X.Y comes from that package's own composer.json (independent of the
# compiler version). Libraries require 80N.X.* across PHP majors for a given
# compiled package. See VERSIONING.md and packages/build-common.sh.
#
# tyhp/compiler is a single-version PHP shim (not a per-PHP dist tree). It is
# built in-place under packages/tyhp/compiler and tagged from that
# composer.json version (for example 805.0.0), same tag style as tyhpdef/*.
#
# Do not run this until after the compiler history wipe and the package repos are public.
# When scripts/packagist.credentials exists, each published GitHub repo whose
# tags Packagist has not crawled yet is refreshed with POST /api/update-package
# (the same action as the Update button). --create-packagist-packages still
# creates packages that are not registered yet. Without the credentials file,
# that refresh stays a separate step: scripts/update-packagist-packages.sh, or
# the Update button. Git remotes use HTTPS (not SSH).

set -euo pipefail

readonly PUBLISH_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_ROOT="$(cd "${PUBLISH_SCRIPT_DIR}/.." && pwd)"
readonly PACKAGES_DIR="${PROJECT_ROOT}/packages"
readonly GITHUB_ORG="tyhpproject-packages"
readonly PACKAGIST_CREDENTIALS_FILE="${PROJECT_ROOT}/scripts/packagist.credentials"
readonly PUBLISH_HELPERS="${PUBLISH_SCRIPT_DIR}/publish-helpers.php"
readonly GITHUB_REPO_CACHE_FILE="${PROJECT_ROOT}/.github-repo-cache"
readonly PACKAGIST_USER_AGENT="tyhp-publish-runtime-packages (+https://github.com/tyhpproject/tyhp-runtime-src; mailto=email@tyhplang.com)"

ALL_PACKAGES=()
MATCHED_PACKAGES=()
SKIPPED_PACKAGES=()
GITHUB_CREATED_REPOS=()
GITHUB_WOULD_CREATE_REPOS=()
GITHUB_CREATE_FAILED_REPOS=()
# 1 after the first gh repo create in this run, so later creates wait.
GITHUB_CREATE_STARTED=0
readonly GITHUB_CREATE_INTER_DELAY=60
readonly GITHUB_CREATE_RATE_LIMIT_MAX_ATTEMPTS=30
readonly GITHUB_CREATE_RATE_LIMIT_INITIAL_SLEEP=60
readonly GITHUB_CREATE_RATE_LIMIT_MAX_SLEEP=60
# Short gap between GitHub reads, clones, and pushes. Repo creates keep the
# longer GITHUB_CREATE_INTER_DELAY above.
readonly GITHUB_REQUEST_DELAY=1
readonly GITHUB_REQUEST_MAX_ATTEMPTS=6
readonly GITHUB_REQUEST_RETRY_INITIAL_SLEEP=15
readonly GITHUB_REQUEST_RETRY_MAX_SLEEP=120
GITHUB_REQUEST_COUNT=0
GITHUB_KNOWN_REPOS=()
VERSION_BUMP_NEEDED=()
PACKAGIST_CREATED_NAMES=()
PACKAGIST_EXISTED_NAMES=()
PACKAGIST_WOULD_CREATE_NAMES=()
PACKAGIST_FAILED_NAMES=()
PACKAGIST_UPDATED_NAMES=()
PACKAGIST_UPDATE_FAILED_NAMES=()
PACKAGIST_UNREGISTERED_NAMES=()
PACKAGIST_CURRENT_NAMES=()
PACKAGIST_UPDATE_SEEN_URLS=()
PACKAGIST_CREDENTIALS_READY=0
PACKAGIST_UPDATE_DRY_RUN=0
# Gap between Packagist update-package calls.
readonly PACKAGIST_UPDATE_DELAY=1
ONLY_PACKAGE=""
PACKAGE_FILTER_SET=0
LIST_ONLY=0
CREATE_GITHUB=0
CREATE_PACKAGIST=0

# build-common.sh expects SCRIPT_DIR = packages and REPO_ROOT = this repo.
SCRIPT_DIR="${PACKAGES_DIR}"
REPO_ROOT="${PROJECT_ROOT}"
# shellcheck source=../packages/build-common.sh
source "${PACKAGES_DIR}/build-common.sh"

PUBLISH_WORKDIR=""

usage() {
  cat <<'EOF'
Usage: scripts/publish-runtime-packages.sh [options]

Build runtime packages in this repo and publish installable trees to
tyhpproject-packages/{package} repositories.

Options:
  -h, --help                      Show this help
  --list                          Discover packages, check GitHub repos, and
                                  print what would be published. Does not
                                  build, clone, tag, or push. Combine with the
                                  create flags to preview those actions too.
  --package NAME                  Publish only this package. NAME is a package
                                  folder name (php, php-ext-bz2, core,
                                  compiler, psr-log/3.0.2) or a
                                  Composer name (tyhpdef/php, tyhp/core,
                                  tyhp/compiler).
  --create-github-repos           If a tyhpproject-packages/<folder> repo is missing,
                                  create an empty public GitHub repo (no
                                  README / license / .gitignore) instead of
                                  skipping. Requires gh. No-op when the repo
                                  already exists.
  --create-packagist-packages     After a successful publish (including
                                  already-tagged skips), POST Packagist's
                                  create-package API for packages that are not
                                  on Packagist yet. Requires
                                  scripts/packagist.credentials (gitignored)
                                  with PACKAGIST_USERNAME and PACKAGIST_API_TOKEN
                                  (MAIN token). No-op when the package exists.
                                  The same credentials file also refreshes
                                  packages Packagist has not crawled yet.

Without --package, every discovered package with a GitHub repo is published.
Without the create flags, missing GitHub repos are skipped. When
scripts/packagist.credentials exists, published repos with tags Packagist has
not crawled are refreshed via POST /api/update-package. Without that file,
run scripts/update-packagist-packages.sh or use the Update button.
Compiled packages (core, decimal, async,
lambda, parallel, io) still run base-build-all.sh so dist trees exist. tyhp/compiler is
built in-place by that script and tagged from its composer.json version (not
80N.X.Y). tyhpdef packages skip that build and tag the version in that
package's composer.json. --list uses that same match set and skips the build
and publish.

Examples:
  scripts/publish-runtime-packages.sh
  scripts/publish-runtime-packages.sh --list
  scripts/publish-runtime-packages.sh --package php
  scripts/publish-runtime-packages.sh --list --package php
  scripts/publish-runtime-packages.sh --package=tyhp/compiler
  scripts/publish-runtime-packages.sh --package=tyhpdef/php
  scripts/publish-runtime-packages.sh --package psr-log/3.0.2
  scripts/publish-runtime-packages.sh --create-github-repos
  scripts/publish-runtime-packages.sh --create-packagist-packages
  scripts/publish-runtime-packages.sh --list --create-github-repos --create-packagist-packages
EOF
}

parse_args() {
  ONLY_PACKAGE=""
  PACKAGE_FILTER_SET=0
  LIST_ONLY=0
  CREATE_GITHUB=0
  CREATE_PACKAGIST=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
      -h|--help)
        usage
        exit 0
        ;;
      --list)
        LIST_ONLY=1
        shift
        ;;
      --create-github-repos)
        CREATE_GITHUB=1
        shift
        ;;
      --create-packagist-packages)
        CREATE_PACKAGIST=1
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
        echo "error: unknown argument: $1 (see --help)" >&2
        exit 1
        ;;
    esac
  done
  if [[ "$PACKAGE_FILTER_SET" -eq 1 && -z "$ONLY_PACKAGE" ]]; then
    echo "error: --package requires a package name." >&2
    exit 1
  fi
}

cleanup_publish_workdir() {
  if [[ -n "${PUBLISH_WORKDIR}" && -d "${PUBLISH_WORKDIR}" ]]; then
    rm -rf "${PUBLISH_WORKDIR}"
  fi
}

require_tool() {
  local tool_name="$1"
  if ! command -v "$tool_name" >/dev/null 2>&1; then
    echo "Missing required tool: ${tool_name}" >&2
    exit 1
  fi
}

is_compiled_package() {
  local candidate
  for candidate in "${DIST_PACKAGES[@]}"; do
    if [[ "$candidate" == "$1" ]]; then
      return 0
    fi
  done
  return 1
}

# True for the single-version tyhp/compiler PHP shim (not a per-PHP dist tree).
is_compiler_package() {
  [[ "$1" == "compiler" ]]
}

is_publishable_package() {
  is_compiled_package "$1" || is_compiler_package "$1" || is_tyhpdef_package "$1"
}

# True when this package's Composer name is tyhpdef/* (php, php-ext-*, wrappers).
is_tyhpdef_package() {
  local name
  name="$(read_composer_name "$1")"
  if [[ -n "$name" ]]; then
    [[ "$name" == tyhpdef/* ]]
    return
  fi
  if is_compiled_package "$1"; then
    return 1
  fi
  if is_versioned_composer_lib "$1"; then
    return 0
  fi
  case "$1" in
    php|php-ext-*) return 0 ;;
    *) return 1 ;;
  esac
}

# True when the first path component is dist or vendor (not a publishable package).
is_skipped_package_name() {
  case "$1" in
    dist|vendor) return 0 ;;
    *) return 1 ;;
  esac
}

# True when `needle` is present in the flat-package-name list built by the first
# discover_packages() loop. Portable to bash 3.2 (no associative arrays).
is_flat_package_name() {
  local needle="$1"
  shift
  local candidate
  for candidate in "$@"; do
    if [[ "$candidate" == "$needle" ]]; then
      return 0
    fi
  done
  return 1
}

# Compiled runtimes and the compiler: packages/tyhp/<name>/composer.json
# Flat tyhpdef packages: packages/tyhpdef/<name>/composer.json
# Versioned Composer-libs: packages/tyhpdef/<vendor>-<name>/<upstream>/composer.json
# Stored as a logical name (e.g. core, php-ext-bz2, or psr-log/3.0.2); use
# package_dir to resolve the directory.
# Dies if the same <vendor>-<name> has both a flat composer.json and a versioned
# tree — that ambiguous state would publish the same GitHub repo twice.
discover_packages() {
  local composer_json
  local pkg
  local parent
  local ver
  local flat_names=()

  ALL_PACKAGES=()

  for composer_json in "${PACKAGES_DIR}"/tyhp/*/composer.json "${PACKAGES_DIR}"/tyhpdef/*/composer.json; do
    if [[ ! -f "$composer_json" ]]; then
      continue
    fi
    pkg="$(basename "$(dirname "$composer_json")")"
    if is_skipped_package_name "$pkg"; then
      continue
    fi
    ALL_PACKAGES+=("$pkg")
    flat_names+=("$pkg")
  done

  for composer_json in "${PACKAGES_DIR}"/tyhpdef/*/*/composer.json; do
    if [[ ! -f "$composer_json" ]]; then
      continue
    fi
    ver="$(basename "$(dirname "$composer_json")")"
    parent="$(basename "$(dirname "$(dirname "$composer_json")")")"
    if is_skipped_package_name "$parent" || is_skipped_package_name "$ver"; then
      continue
    fi
    if is_flat_package_name "$parent" "${flat_names[@]}"; then
      echo "error: ${parent} has both a flat composer.json (${PACKAGES_DIR}/tyhpdef/${parent}/composer.json)" >&2
      echo "and a versioned tree (${composer_json}). Remove the leftover flat layout" >&2
      echo "before publishing; otherwise ${GITHUB_ORG}/${parent} would be published twice." >&2
      exit 1
    fi
    ALL_PACKAGES+=("${parent}/${ver}")
  done

  if [[ ${#ALL_PACKAGES[@]} -eq 0 ]]; then
    echo "No packages found in ${PACKAGES_DIR} (expected tyhp/*/composer.json, tyhpdef/*/composer.json, or tyhpdef/*/*/composer.json)." >&2
    exit 1
  fi
}

# GitHub repo is the first path component: psr-log/3.0.2 → psr-log; php-ext-bz2 → php-ext-bz2.
github_repo_for_package() {
  local pkg="$1"
  echo "${pkg%%/*}"
}

# Composer-libs publish a public repo and an impl repo. First-party packages publish one repo.
package_github_repos() {
  local pkg="$1"
  local repo
  repo="$(github_repo_for_package "$pkg")"
  if is_versioned_composer_lib "$pkg"; then
    printf '%s\n%s\n' "$repo" "${repo}-impl"
  else
    printf '%s\n' "$repo"
  fi
}

ownership_is_handed_off() {
  php "$PUBLISH_HELPERS" handed-off "$1"
}

is_versioned_composer_lib() {
  [[ "$1" == */* ]]
}

# Directory of a discovered package: compiled runtimes and the compiler live under
# packages/tyhp/<name>; every tyhpdef package (php, php-ext-*, Composer-lib
# wrappers such as psr-log/3.0.2) lives under packages/tyhpdef/<name>.
package_dir() {
  local pkg="$1"
  if is_compiled_package "$pkg" || is_compiler_package "$pkg"; then
    printf '%s\n' "${PACKAGES_DIR}/tyhp/${pkg}"
  else
    printf '%s\n' "${PACKAGES_DIR}/tyhpdef/${pkg}"
  fi
}

read_composer_name() {
  local pkg="$1"
  php "$PUBLISH_HELPERS" composer-name "$(package_dir "$pkg")/composer.json" 2>/dev/null || true
}

# Restrict ALL_PACKAGES to the single entry named by --package.
# Matches a packages-relative path, a unique GitHub repo slug, or a
# Composer name. Ambiguous slugs (psr-log with several version folders) must
# be spelled as parent/version.
filter_to_requested_package() {
  local needle="$1"
  local pkg
  local repo
  local cname
  local matches=()

  needle="${needle%/}"
  if [[ -z "$needle" ]]; then
    echo "error: --package requires a package name." >&2
    exit 1
  fi

  for pkg in "${ALL_PACKAGES[@]}"; do
    if [[ "$pkg" == "$needle" ]]; then
      ALL_PACKAGES=("$pkg")
      return 0
    fi
  done

  for pkg in "${ALL_PACKAGES[@]}"; do
    repo="$(github_repo_for_package "$pkg")"
    if [[ "$repo" == "$needle" ]]; then
      matches+=("$pkg")
    fi
  done
  if [[ ${#matches[@]} -eq 1 ]]; then
    ALL_PACKAGES=("${matches[0]}")
    return 0
  fi
  if [[ ${#matches[@]} -gt 1 ]]; then
    echo "error: --package ${needle} matches multiple version folders. Specify one of: ${matches[*]}" >&2
    exit 1
  fi

  matches=()
  for pkg in "${ALL_PACKAGES[@]}"; do
    cname="$(read_composer_name "$pkg")"
    if [[ "$cname" == "$needle" ]]; then
      matches+=("$pkg")
    fi
  done
  if [[ ${#matches[@]} -eq 1 ]]; then
    ALL_PACKAGES=("${matches[0]}")
    return 0
  fi
  if [[ ${#matches[@]} -gt 1 ]]; then
    echo "error: --package ${needle} matches multiple packages: ${matches[*]}" >&2
    exit 1
  fi

  echo "error: unknown package ${needle}." >&2
  echo "Use a package folder name (php, php-ext-bz2, core, compiler, psr-log/3.0.2) or a Composer name (tyhpdef/php, tyhp/compiler)." >&2
  exit 1
}

read_tyhp_package_version() {
  php "$PUBLISH_HELPERS" composer-version "$1"
}

# Return 0 if needle is one of the remaining arguments. Safe with set -u when
# called as `list_contains "$x" "${arr[@]}"` only after ${#arr[@]} -gt 0, or
# with no remaining args (empty list → not found).
list_contains() {
  local needle="$1"
  local candidate
  shift
  for candidate in "$@"; do
    if [[ "$candidate" == "$needle" ]]; then
      return 0
    fi
  done
  return 1
}

# True for GitHub/network failures worth retrying. A missing repo is not one of these.
github_failure_is_retryable() {
  local output="$1"
  if [[ -z "$output" ]]; then
    return 0
  fi
  [[ "$output" == *"i/o timeout"* \
    || "$output" == *"timed out"* \
    || "$output" == *"timeout"* \
    || "$output" == *"connection reset"* \
    || "$output" == *"Connection reset"* \
    || "$output" == *"TLS handshake"* \
    || "$output" == *"temporary failure"* \
    || "$output" == *"Temporary failure"* \
    || "$output" == *"Could not resolve host"* \
    || "$output" == *"network is unreachable"* \
    || "$output" == *"connection refused"* \
    || "$output" == *"Failed to connect"* \
    || "$output" == *"dial tcp"* \
    || "$output" == *"HTTP 429"* \
    || "$output" == *"HTTP 502"* \
    || "$output" == *"HTTP 503"* \
    || "$output" == *"HTTP 504"* \
    || "$output" == *"rate limit"* \
    || "$output" == *"too many repositories, too quickly"* \
    || "$output" == *"try again"* \
    || "$output" == *"RPC failed"* \
    || "$output" == *"early EOF"* \
    || "$output" == *"unable to access"* \
    || "$output" == *"The requested URL returned error: 5"* \
    || "$output" == *"SSL"* \
    || "$output" == *"recv failure"* ]]
}

# Wait between GitHub calls so a long pass does not hammer the API. The first
# call in a run starts immediately.
github_request_pause() {
  local why="$1"
  if [[ "$GITHUB_REQUEST_COUNT" -eq 0 ]]; then
    GITHUB_REQUEST_COUNT=1
    return 0
  fi
  echo "GitHub: waiting ${GITHUB_REQUEST_DELAY}s before ${why}"
  sleep "$GITHUB_REQUEST_DELAY"
  GITHUB_REQUEST_COUNT=$((GITHUB_REQUEST_COUNT + 1))
}

load_github_repo_cache() {
  GITHUB_KNOWN_REPOS=()
  [[ -f "$GITHUB_REPO_CACHE_FILE" ]] || return 0
  local line
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ -n "$line" && "$line" != \#* ]] || continue
    if [[ ${#GITHUB_KNOWN_REPOS[@]} -eq 0 ]] || ! list_contains "$line" "${GITHUB_KNOWN_REPOS[@]}"; then
      GITHUB_KNOWN_REPOS+=("$line")
    fi
  done < "$GITHUB_REPO_CACHE_FILE"
}

github_repo_cached() {
  local repo="$1"
  [[ ${#GITHUB_KNOWN_REPOS[@]} -gt 0 ]] || return 1
  list_contains "$repo" "${GITHUB_KNOWN_REPOS[@]}"
}

remember_github_repo() {
  local repo="$1"
  if github_repo_cached "$repo"; then
    return 0
  fi
  GITHUB_KNOWN_REPOS+=("$repo")
  printf '%s\n' "$repo" >> "$GITHUB_REPO_CACHE_FILE"
}

forget_github_repo() {
  local repo="$1"
  local kept=()
  local candidate
  [[ ${#GITHUB_KNOWN_REPOS[@]} -gt 0 ]] || return 0
  for candidate in "${GITHUB_KNOWN_REPOS[@]}"; do
    if [[ "$candidate" != "$repo" ]]; then
      kept+=("$candidate")
    fi
  done
  if [[ ${#kept[@]} -eq 0 ]]; then
    GITHUB_KNOWN_REPOS=()
    : > "$GITHUB_REPO_CACHE_FILE"
  else
    GITHUB_KNOWN_REPOS=("${kept[@]}")
    printf '%s\n' "${GITHUB_KNOWN_REPOS[@]}" > "$GITHUB_REPO_CACHE_FILE"
  fi
}

# One org listing fills the cache. Later runs trust the file and only ask
# GitHub about repos that are not in it.
seed_github_repo_cache_from_org() {
  local output
  local rc
  local attempt=1
  local sleep_secs="$GITHUB_REQUEST_RETRY_INITIAL_SLEEP"
  local log

  while true; do
    github_request_pause "listing ${GITHUB_ORG} repos"
    echo "GitHub: listing ${GITHUB_ORG} repositories into ${GITHUB_REPO_CACHE_FILE}"
    log="$(mktemp "${TMPDIR:-/tmp}/tyhp-github.XXXXXX")"
    local err
    err="$(mktemp "${TMPDIR:-/tmp}/tyhp-github.XXXXXX")"
    rc=0
    gh repo list "$GITHUB_ORG" --limit 5000 --json name --jq '.[].name' >"$log" 2>"$err" || rc=$?
    if [[ -s "$err" ]]; then
      cat "$err"
    fi
    output="$(cat "$err")"
    rm -f "$err"
    if [[ "$rc" -eq 0 ]]; then
      if awk 'NF && !seen[$0]++' "$log" > "$GITHUB_REPO_CACHE_FILE"; then
        rm -f "$log"
        load_github_repo_cache
        echo "GitHub: cached ${#GITHUB_KNOWN_REPOS[@]} existing repos"
        return 0
      fi
      rm -f "$log"
      echo "warning: could not read the ${GITHUB_ORG} repo list; each repo will be checked individually." >&2
      return 1
    fi
    rm -f "$log"
    if github_failure_is_retryable "$output" && [[ "$attempt" -lt "$GITHUB_REQUEST_MAX_ATTEMPTS" ]]; then
      echo "GitHub: listing ${GITHUB_ORG} failed; waiting ${sleep_secs}s then retrying (attempt ${attempt}/$((GITHUB_REQUEST_MAX_ATTEMPTS - 1)))" >&2
      echo "$output" >&2
      sleep "$sleep_secs"
      attempt=$((attempt + 1))
      sleep_secs=$((sleep_secs * 2))
      if [[ "$sleep_secs" -gt "$GITHUB_REQUEST_RETRY_MAX_SLEEP" ]]; then
        sleep_secs="$GITHUB_REQUEST_RETRY_MAX_SLEEP"
      fi
      continue
    fi
    echo "$output" >&2
    echo "warning: could not list ${GITHUB_ORG} repos; each repo will be checked individually." >&2
    return 1
  done
}

github_output_repo_missing() {
  local output="$1"
  [[ "$output" == *"Could not resolve to a Repository"* \
    || "$output" == *"Repository not found"* \
    || "$output" == *"repository not found"* \
    || "$output" == *"HTTP 404"* \
    || "$output" == *"Not Found"* ]]
}

# Run a git command that talks to GitHub. Output is shown as it happens.
# Transient failures are retried. If the remote repo is missing, create it
# once and retry the same command.
# Usage: run_github_git "clone org/repo" repo-slug git clone ...
run_github_git() {
  local desc="$1"
  local repo="$2"
  local output
  local rc
  local attempt=1
  local sleep_secs="$GITHUB_REQUEST_RETRY_INITIAL_SLEEP"
  local log
  local recreated=0
  local repo_desc
  shift 2

  while true; do
    if [[ "$attempt" -eq 1 ]]; then
      github_request_pause "$desc"
    fi
    echo "GitHub: ${desc}"
    log="$(mktemp "${TMPDIR:-/tmp}/tyhp-github.XXXXXX")"
    rc=0
    "$@" 2>&1 | tee "$log" || rc=$?
    output="$(cat "$log")"
    rm -f "$log"
    if [[ "$rc" -eq 0 ]]; then
      remember_github_repo "$repo"
      return 0
    fi
    if [[ "$recreated" -eq 0 ]] && github_output_repo_missing "$output"; then
      echo "GitHub: ${GITHUB_ORG}/${repo} is missing; creating it and retrying: ${desc}" >&2
      echo "$output" >&2
      forget_github_repo "$repo"
      repo_desc="$(github_repo_description_for_slug "$repo")"
      if create_empty_github_repo "$repo" "$repo_desc"; then
        remember_github_repo "$repo"
        recreated=1
        attempt=1
        continue
      fi
      echo "error: could not create ${GITHUB_ORG}/${repo} after ${desc} reported it missing" >&2
      return 1
    fi
    if github_failure_is_retryable "$output" && [[ "$attempt" -lt "$GITHUB_REQUEST_MAX_ATTEMPTS" ]]; then
      echo "GitHub: ${desc} failed; waiting ${sleep_secs}s then retrying (attempt ${attempt}/$((GITHUB_REQUEST_MAX_ATTEMPTS - 1)))" >&2
      sleep "$sleep_secs"
      attempt=$((attempt + 1))
      sleep_secs=$((sleep_secs * 2))
      if [[ "$sleep_secs" -gt "$GITHUB_REQUEST_RETRY_MAX_SLEEP" ]]; then
        sleep_secs="$GITHUB_REQUEST_RETRY_MAX_SLEEP"
      fi
      continue
    fi
    echo "error: ${desc} failed" >&2
    return "$rc"
  done
}

# Return 0 if tyhpproject-packages/<repo> exists (including empty repos). Return 1 if GitHub
# reports the repo is missing. Timeouts and rate limits are retried. Any other gh failure aborts.
github_repo_exists() {
  local repo="$1"
  local output
  local rc
  local attempt=1
  local sleep_secs="$GITHUB_REQUEST_RETRY_INITIAL_SLEEP"

  if github_repo_cached "$repo"; then
    echo "GitHub: ${GITHUB_ORG}/${repo} exists (cached)"
    return 0
  fi

  while true; do
    if [[ "$attempt" -eq 1 ]]; then
      github_request_pause "checking ${GITHUB_ORG}/${repo}"
    fi
    echo "GitHub: [${GITHUB_REQUEST_COUNT}] checking ${GITHUB_ORG}/${repo}"
    rc=0
    output="$(gh repo view "${GITHUB_ORG}/${repo}" --json name 2>&1)" || rc=$?
    if [[ "$rc" -eq 0 ]]; then
      remember_github_repo "$repo"
      echo "GitHub: [${GITHUB_REQUEST_COUNT}] ${GITHUB_ORG}/${repo} exists"
      return 0
    fi
    if github_output_repo_missing "$output"; then
      echo "GitHub: [${GITHUB_REQUEST_COUNT}] ${GITHUB_ORG}/${repo} does not exist"
      return 1
    fi
    if github_failure_is_retryable "$output" && [[ "$attempt" -lt "$GITHUB_REQUEST_MAX_ATTEMPTS" ]]; then
      echo "GitHub: check ${GITHUB_ORG}/${repo} failed; waiting ${sleep_secs}s then retrying (attempt ${attempt}/$((GITHUB_REQUEST_MAX_ATTEMPTS - 1)))" >&2
      echo "$output" >&2
      sleep "$sleep_secs"
      attempt=$((attempt + 1))
      sleep_secs=$((sleep_secs * 2))
      if [[ "$sleep_secs" -gt "$GITHUB_REQUEST_RETRY_MAX_SLEEP" ]]; then
        sleep_secs="$GITHUB_REQUEST_RETRY_MAX_SLEEP"
      fi
      continue
    fi
    echo "$output" >&2
    echo "Failed to check GitHub repo ${GITHUB_ORG}/${repo}." >&2
    exit 1
  done
}

# GitHub About text: composer.json description (≤350 chars), else a short fallback.
github_repo_description() {
  local pkg="$1"
  php "$PUBLISH_HELPERS" repo-description "$(package_dir "$pkg")/composer.json" "$pkg"
}

# Description for a repo slug, including "<name>-impl". Falls back when no
# package folder matches.
github_repo_description_for_slug() {
  local repo="$1"
  local pkg
  local base
  for pkg in "${ALL_PACKAGES[@]}"; do
    base="$(github_repo_for_package "$pkg")"
    if [[ "$base" == "$repo" || "${base}-impl" == "$repo" ]]; then
      github_repo_description "$pkg"
      return 0
    fi
  done
  printf 'Tyhp package %s\n' "$repo"
}

github_repo_create_rate_limited() {
  local output="$1"
  [[ "$output" == *"too many repositories, too quickly"* || "$output" == *"secondary rate limit"* ]]
}

# Create an empty remote-only public repo. Run from a non-git directory so gh
# does not offer to add a remote on this compiler checkout. GitHub's
# createRepository limit ("too many repositories, too quickly") is retried
# with a growing wait. gh does not print response headers, so Retry-After is
# not available here.
create_empty_github_repo() {
  local repo="$1"
  local desc="$2"
  local output
  local rc=0
  local attempt=1
  local sleep_secs="$GITHUB_CREATE_RATE_LIMIT_INITIAL_SLEEP"

  while true; do
    rc=0
    output="$(
      cd "${TMPDIR:-/tmp}"
      GH_PROMPT_DISABLED=1 gh repo create "${GITHUB_ORG}/${repo}" --public --description "$desc" 2>&1
    )" || rc=$?
    if [[ "$rc" -eq 0 ]]; then
      remember_github_repo "$repo"
      return 0
    fi
    if [[ "$output" == *"already exists"* || "$output" == *"Name already exists"* ]]; then
      echo "GitHub: ${GITHUB_ORG}/${repo} already exists (skip create)"
      remember_github_repo "$repo"
      return 0
    fi
    if [[ "$attempt" -lt "$GITHUB_CREATE_RATE_LIMIT_MAX_ATTEMPTS" ]] && { github_repo_create_rate_limited "$output" || github_failure_is_retryable "$output"; }; then
      if github_repo_create_rate_limited "$output"; then
        echo "GitHub: rate limited creating ${GITHUB_ORG}/${repo}; waiting ${sleep_secs}s then retrying (attempt ${attempt}/$((GITHUB_CREATE_RATE_LIMIT_MAX_ATTEMPTS - 1)))" >&2
      else
        echo "GitHub: create ${GITHUB_ORG}/${repo} failed; waiting ${sleep_secs}s then retrying (attempt ${attempt}/$((GITHUB_CREATE_RATE_LIMIT_MAX_ATTEMPTS - 1)))" >&2
        echo "$output" >&2
      fi
      sleep "$sleep_secs"
      attempt=$((attempt + 1))
      sleep_secs=$((sleep_secs * 2))
      if [[ "$sleep_secs" -gt "$GITHUB_CREATE_RATE_LIMIT_MAX_SLEEP" ]]; then
        sleep_secs="$GITHUB_CREATE_RATE_LIMIT_MAX_SLEEP"
      fi
      continue
    fi
    echo "$output" >&2
    return 1
  done
}

# 0 = exists, was created, or --list would create. 1 = missing and not creating
# (or create failed).
ensure_github_repo() {
  local repo="$1"
  local pkg="$2"
  local desc
  local exists_rc=0

  github_repo_exists "$repo" || exists_rc=$?
  if [[ "$exists_rc" -eq 0 ]]; then
    return 0
  fi

  if [[ "$CREATE_GITHUB" -ne 1 ]]; then
    return 1
  fi

  if [[ "$LIST_ONLY" -eq 1 ]]; then
    if [[ ${#GITHUB_WOULD_CREATE_REPOS[@]} -eq 0 ]] || ! list_contains "$repo" "${GITHUB_WOULD_CREATE_REPOS[@]}"; then
      GITHUB_WOULD_CREATE_REPOS+=("$repo")
      echo "would create GitHub repo ${GITHUB_ORG}/${repo} (empty public; no README / license / .gitignore)"
    fi
    return 0
  fi

  if [[ ${#GITHUB_CREATED_REPOS[@]} -gt 0 ]] && list_contains "$repo" "${GITHUB_CREATED_REPOS[@]}"; then
    return 0
  fi
  if [[ ${#GITHUB_CREATE_FAILED_REPOS[@]} -gt 0 ]] && list_contains "$repo" "${GITHUB_CREATE_FAILED_REPOS[@]}"; then
    return 1
  fi

  desc="$(github_repo_description "$pkg")"
  if [[ "$GITHUB_CREATE_STARTED" -eq 1 ]]; then
    echo "GitHub: waiting ${GITHUB_CREATE_INTER_DELAY}s before creating ${GITHUB_ORG}/${repo}"
    sleep "$GITHUB_CREATE_INTER_DELAY"
  fi
  GITHUB_CREATE_STARTED=1
  echo "GitHub: creating ${GITHUB_ORG}/${repo}"
  if ! create_empty_github_repo "$repo" "$desc"; then
    echo "error: gh repo create failed for ${GITHUB_ORG}/${repo}" >&2
    GITHUB_CREATE_FAILED_REPOS+=("$repo")
    return 1
  fi
  echo "GitHub: created empty ${GITHUB_ORG}/${repo} (publish will clone and push)"
  GITHUB_CREATED_REPOS+=("$repo")
  return 0
}

composer_name_for_package() {
  local pkg="$1"
  local repo
  local name
  name="$(read_composer_name "$pkg")"
  if [[ -n "$name" ]]; then
    printf '%s\n' "$name"
    return
  fi
  repo="$(github_repo_for_package "$pkg")"
  if is_compiler_package "$pkg" || is_compiled_package "$pkg"; then
    printf 'tyhp/%s\n' "$repo"
  else
    printf 'tyhpdef/%s\n' "$repo"
  fi
}

github_https_url() {
  printf 'https://github.com/%s/%s\n' "$GITHUB_ORG" "$1"
}

# 0 = on Packagist, 1 = missing, 2 = unexpected HTTP error.
packagist_package_exists() {
  local name="$1"
  php "$PUBLISH_HELPERS" packagist-exists "$name" "$PACKAGIST_USER_AGENT"
}

load_packagist_credentials() {
  php "$PUBLISH_HELPERS" packagist-username "$PACKAGIST_CREDENTIALS_FILE"
}

validate_packagist_credentials() {
  local username
  if [[ ! -f "$PACKAGIST_CREDENTIALS_FILE" ]]; then
    echo "error: --create-packagist-packages requires ${PACKAGIST_CREDENTIALS_FILE}" >&2
    echo "Add PACKAGIST_USERNAME and PACKAGIST_API_TOKEN (MAIN token) from https://packagist.org/profile/" >&2
    exit 1
  fi
  username="$(load_packagist_credentials)" || exit 1
  echo "Packagist credentials loaded for ${username} from ${PACKAGIST_CREDENTIALS_FILE}"
}

# POST /api/create-package. The helper reads the gitignored credentials file so
# the MAIN token is not interpolated into the process argv.
create_packagist_package() {
  local repo_url="$1"
  php "$PUBLISH_HELPERS" packagist-create "$PACKAGIST_CREDENTIALS_FILE" "$repo_url" "$PACKAGIST_USER_AGENT"
}

packagist_name_already_recorded() {
  local name="$1"
  if [[ ${#PACKAGIST_CREATED_NAMES[@]} -gt 0 ]] && list_contains "$name" "${PACKAGIST_CREATED_NAMES[@]}"; then
    return 0
  fi
  if [[ ${#PACKAGIST_EXISTED_NAMES[@]} -gt 0 ]] && list_contains "$name" "${PACKAGIST_EXISTED_NAMES[@]}"; then
    return 0
  fi
  if [[ ${#PACKAGIST_WOULD_CREATE_NAMES[@]} -gt 0 ]] && list_contains "$name" "${PACKAGIST_WOULD_CREATE_NAMES[@]}"; then
    return 0
  fi
  if [[ ${#PACKAGIST_FAILED_NAMES[@]} -gt 0 ]] && list_contains "$name" "${PACKAGIST_FAILED_NAMES[@]}"; then
    return 0
  fi
  return 1
}

# After a successful GitHub publish (or --list preview): create the Packagist
# package from the GitHub URL when it is not already registered.
ensure_packagist_package() {
  local pkg="$1"
  local repo
  local name
  local repo_url
  local exists_rc=0

  [[ "$CREATE_PACKAGIST" -eq 1 ]] || return 0

  if is_versioned_composer_lib "$pkg"; then
    local base
    base="$(github_repo_for_package "$pkg")"
    ensure_one_packagist_package "tyhpdef/${base}" "$(github_https_url "$base")" || exists_rc=1
    ensure_one_packagist_package "tyhpdef/${base}-impl" "$(github_https_url "${base}-impl")" || exists_rc=1
    return "$exists_rc"
  fi

  repo="$(github_repo_for_package "$pkg")"
  name="$(composer_name_for_package "$pkg")"
  repo_url="$(github_https_url "$repo")"
  ensure_one_packagist_package "$name" "$repo_url"
  return $?
}

ensure_one_packagist_package() {
  local name="$1"
  local repo_url="$2"
  local exists_rc=0

  if packagist_name_already_recorded "$name"; then
    return 0
  fi

  packagist_package_exists "$name" || exists_rc=$?
  if [[ "$exists_rc" -eq 0 ]]; then
    echo "Packagist: ${name} already exists (skip create)"
    PACKAGIST_EXISTED_NAMES+=("$name")
    return 0
  fi
  if [[ "$exists_rc" -eq 2 ]]; then
    PACKAGIST_FAILED_NAMES+=("$name")
    return 1
  fi

  if [[ "$LIST_ONLY" -eq 1 ]]; then
    echo "would create Packagist package ${name} from ${repo_url}"
    PACKAGIST_WOULD_CREATE_NAMES+=("$name")
    return 0
  fi

  echo "Packagist: creating ${name} from ${repo_url}"
  if ! create_packagist_package "$repo_url"; then
    echo "error: Packagist create-package failed for ${name}" >&2
    PACKAGIST_FAILED_NAMES+=("$name")
    return 1
  fi
  echo "Packagist: created ${name}"
  PACKAGIST_CREATED_NAMES+=("$name")
  return 0
}

# name|https://github.com/org/repo lines for the Packagist packages this publish owns.
packagist_targets_for_package() {
  local pkg="$1"
  local base
  if is_versioned_composer_lib "$pkg"; then
    base="$(github_repo_for_package "$pkg")"
    printf 'tyhpdef/%s|%s\n' "$base" "$(github_https_url "$base")"
    printf 'tyhpdef/%s-impl|%s\n' "$base" "$(github_https_url "${base}-impl")"
    return 0
  fi
  printf '%s|%s\n' "$(composer_name_for_package "$pkg")" "$(github_https_url "$(github_repo_for_package "$pkg")")"
}

# update-packagist-packages.sh replaces this so its status line is cleared
# before a message that should stay on screen.
packagist_progress_break() {
  :
}

# POST /api/update-package when a git tag is missing from Packagist's release list.
# Same crawl the Update button queues. Shared with update-packagist-packages.sh.
refresh_one_packagist_repo() {
  local name="$1"
  local repo_url="$2"
  local tags=""
  local missing=""
  local shown=""
  local rc=0

  if [[ ${#PACKAGIST_UPDATE_SEEN_URLS[@]} -gt 0 ]] && list_contains "$repo_url" "${PACKAGIST_UPDATE_SEEN_URLS[@]}"; then
    return 0
  fi
  PACKAGIST_UPDATE_SEEN_URLS+=("$repo_url")

  tags="$(git ls-remote --tags "$repo_url" 2>&1)" || rc=$?
  if [[ "$rc" -ne 0 ]]; then
    packagist_progress_break
    echo "warning: cannot list tags for ${repo_url}; skipping Packagist update" >&2
    return 0
  fi

  rc=0
  missing="$(printf '%s\n' "$tags" | php "$PUBLISH_HELPERS" packagist-missing-tags "$name" "$PACKAGIST_USER_AGENT")" || rc=$?
  if [[ "$rc" -eq 1 ]]; then
    packagist_progress_break
    echo "Packagist: ${name} is not registered"
    PACKAGIST_UNREGISTERED_NAMES+=("$name")
    return 0
  fi
  if [[ "$rc" -ne 0 ]]; then
    PACKAGIST_UPDATE_FAILED_NAMES+=("$name")
    return 0
  fi
  if [[ -z "$missing" ]]; then
    PACKAGIST_CURRENT_NAMES+=("$name")
    return 0
  fi

  shown="${missing//$'\n'/, }"
  packagist_progress_break
  echo "Packagist: ${name} is missing ${shown}"
  if [[ "$PACKAGIST_UPDATE_DRY_RUN" -eq 1 ]]; then
    echo "would update Packagist ${name} from ${repo_url}"
    PACKAGIST_UPDATED_NAMES+=("$name")
    return 0
  fi

  echo "Packagist: updating ${name} from ${repo_url}"
  rc=0
  php "$PUBLISH_HELPERS" packagist-update "$PACKAGIST_CREDENTIALS_FILE" "$repo_url" "$PACKAGIST_USER_AGENT" || rc=$?
  if [[ "$rc" -eq 2 ]]; then
    packagist_progress_break
    echo "Packagist: ${name} is not registered"
    PACKAGIST_UNREGISTERED_NAMES+=("$name")
    return 0
  fi
  if [[ "$rc" -ne 0 ]]; then
    PACKAGIST_UPDATE_FAILED_NAMES+=("$name")
    return 0
  fi
  PACKAGIST_UPDATED_NAMES+=("$name")
  sleep "$PACKAGIST_UPDATE_DELAY"
}

refresh_packagist_package() {
  local pkg="$1"
  local target
  local name
  local repo_url

  [[ "$PACKAGIST_CREDENTIALS_READY" -eq 1 ]] || return 0
  for target in $(packagist_targets_for_package "$pkg"); do
    name="${target%%|*}"
    repo_url="${target#*|}"
    refresh_one_packagist_repo "$name" "$repo_url"
  done
}

preview_packagist_creates() {
  local pkg
  [[ "$CREATE_PACKAGIST" -eq 1 ]] || return 0
  if [[ ${#MATCHED_PACKAGES[@]} -eq 0 ]]; then
    return 0
  fi
  for pkg in "${MATCHED_PACKAGES[@]}"; do
    ensure_packagist_package "$pkg" || true
  done
}

# Create missing GitHub repos for publishable packages when --create-github-repos
# is set. Does not fill MATCHED_PACKAGES (collect_publish_matches runs later).
ensure_missing_github_repos() {
  local pkg
  local repo
  [[ "$CREATE_GITHUB" -eq 1 ]] || return 0
  [[ "$LIST_ONLY" -eq 0 ]] || return 0
  echo "Checking which GitHub repos still need to be created (${#ALL_PACKAGES[@]} packages)..."
  for pkg in "${ALL_PACKAGES[@]}"; do
    if ! is_publishable_package "$pkg"; then
      continue
    fi
    # Command substitution, not `done < <(...)`. macOS bash 3.2 leaks a file
    # descriptor on each process substitution in a loop, and this runs once
    # per package.
    for repo in $(package_github_repos "$pkg"); do
      [[ -n "$repo" ]] || continue
      ensure_github_repo "$repo" "$pkg" || true
    done
  done
}

# Fill MATCHED_PACKAGES / SKIPPED_PACKAGES using the same rules as a real
# publish: compiled tyhp/* helpers, tyhp/compiler, and tyhpdef/* packages whose
# GitHub repo exists (or will be created when --create-github-repos is set).
# Skip warnings go to stderr so list and publish share one check.
collect_publish_matches() {
  local pkg
  local repo

  MATCHED_PACKAGES=()
  SKIPPED_PACKAGES=()

  echo "Checking GitHub repos before publish (${#ALL_PACKAGES[@]} packages)..."
  for pkg in "${ALL_PACKAGES[@]}"; do
    if ! is_publishable_package "$pkg"; then
      echo "warning: skipping ${pkg} (not a compiled tyhp/* helper, tyhp/compiler, or tyhpdef/* package)." >&2
      SKIPPED_PACKAGES+=("$pkg")
      continue
    fi
    local missing=0
    # Same as ensure_missing_github_repos: avoid process substitution here.
    for repo in $(package_github_repos "$pkg"); do
      [[ -n "$repo" ]] || continue
      if ! ensure_github_repo "$repo" "$pkg"; then
        missing=1
        if [[ "$CREATE_GITHUB" -eq 1 ]]; then
          echo "warning: GitHub repo ${GITHUB_ORG}/${repo} could not be created; skipping ${pkg}." >&2
        else
          echo "warning: GitHub repo ${GITHUB_ORG}/${repo} does not exist; skipping ${pkg} (pass --create-github-repos to create it)." >&2
        fi
      fi
    done
    if [[ "$missing" -ne 0 ]]; then
      SKIPPED_PACKAGES+=("$pkg")
      continue
    fi
    MATCHED_PACKAGES+=("$pkg")
  done
}

package_kind_label() {
  if is_compiled_package "$1"; then
    echo "compiled"
  elif is_compiler_package "$1"; then
    echo "compiler"
  else
    echo "tyhpdef"
  fi
}

print_publish_matches() {
  local pkg
  local repo
  local kind

  if [[ ${#MATCHED_PACKAGES[@]} -gt 0 ]]; then
    echo "Would publish:"
    for pkg in "${MATCHED_PACKAGES[@]}"; do
      kind="$(package_kind_label "$pkg")"
      repos="$(package_github_repos "$pkg" | paste -sd, -)"
      echo "  ${pkg} → ${GITHUB_ORG}/{${repos}} (${kind})"
    done
  else
    echo "No packages would be published."
  fi
  if [[ ${#GITHUB_WOULD_CREATE_REPOS[@]} -gt 0 ]]; then
    echo "Would create GitHub repos: ${GITHUB_WOULD_CREATE_REPOS[*]}"
  fi
  preview_packagist_creates
  if [[ ${#PACKAGIST_WOULD_CREATE_NAMES[@]} -gt 0 ]]; then
    echo "Would create Packagist packages: ${PACKAGIST_WOULD_CREATE_NAMES[*]}"
  fi
  if [[ ${#PACKAGIST_EXISTED_NAMES[@]} -gt 0 ]]; then
    echo "Already on Packagist: ${PACKAGIST_EXISTED_NAMES[*]}"
  fi
  if [[ ${#SKIPPED_PACKAGES[@]} -gt 0 ]]; then
    echo "Would skip: ${SKIPPED_PACKAGES[*]}" >&2
  fi
}

wipe_published_tree() {
  local dest="$1"
  if [[ -d "${dest}/.git" ]]; then
    find "$dest" -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} +
  fi
}

sync_compile_dist() {
  local pkg="$1"
  local dest="$2"
  local dist_version="$3"
  local dist_dir="${PACKAGES_DIR}/dist/tyhp-${pkg}/${dist_version}"

  if [[ ! -d "${dist_dir}/src" ]]; then
    echo "Missing emitted PHP at ${dist_dir}/src. Did packages/base-build-all.sh succeed?" >&2
    exit 1
  fi

  wipe_published_tree "$dest"
  rsync -a --delete \
    --exclude '.git/' \
    --exclude 'src/tyhp-build-state.json' \
    "${dist_dir}/" "${dest}/"
}

# Copy a tyhpdef tree as-is (php, php-ext-*, Composer-lib wrappers).
# composer.json version and php constraint are not rewritten to 80N.X.Y.
sync_composer_lib_dist() {
  local pkg="$1"
  local dest="$2"
  local src
  src="$(package_dir "$pkg")"

  wipe_published_tree "$dest"

  mkdir -p "${dest}/_tyhpdef"
  rsync -a --delete \
    --exclude '.git/' \
    --exclude 'support/' \
    "${src}/_tyhpdef/" "${dest}/_tyhpdef/"

  cp "${src}/composer.json" "${dest}/composer.json"
  if [[ -d "${src}/tests" ]]; then
    mkdir -p "${dest}/tests"
    rsync -a --delete \
      --exclude '.git/' \
      --exclude 'build/' \
      --exclude 'vendor/' \
      "${src}/tests/" "${dest}/tests/"
  fi
  if [[ -f "${src}/README.md" ]]; then
    cp "${src}/README.md" "${dest}/README.md"
  fi
  if [[ -f "${src}/LICENSE" ]]; then
    cp "${src}/LICENSE" "${dest}/LICENSE"
  elif [[ -f "${PROJECT_ROOT}/LICENSE.txt" ]]; then
    cp "${PROJECT_ROOT}/LICENSE.txt" "${dest}/LICENSE"
  fi
  # In-tree path repositories do not resolve from the published GitHub tree.
  write_published_composer_json "${dest}/composer.json" "${dest}/composer.json"
}

# Copy composer.json without in-tree path repositories (those URLs are invalid
# in the published GitHub tree).
write_published_composer_json() {
  php "$PUBLISH_HELPERS" published-composer "$1" "$2"
}

# Copy the in-place tyhp/compiler tree (emitted src/, bin/tyhp, README, LICENSE).
# One Packagist tag from composer.json; not rewritten to 80N.X.Y.
sync_compiler_dist() {
  local pkg="$1"
  local dest="$2"
  local src
  src="$(package_dir "$pkg")"

  if [[ ! -d "${src}/src/Tyhp/Compiler" ]]; then
    echo "Missing emitted PHP at ${src}/src. Did packages/base-build-all.sh succeed?" >&2
    exit 1
  fi
  if [[ ! -f "${src}/bin/tyhp" ]]; then
    echo "Missing ${src}/bin/tyhp." >&2
    exit 1
  fi

  wipe_published_tree "$dest"

  mkdir -p "${dest}/src" "${dest}/bin"
  rsync -a --delete \
    --exclude 'tyhp-build-state.json' \
    "${src}/src/" "${dest}/src/"
  rsync -a --delete "${src}/bin/" "${dest}/bin/"

  write_published_composer_json "${src}/composer.json" "${dest}/composer.json"
  if [[ -f "${src}/README.md" ]]; then
    cp "${src}/README.md" "${dest}/README.md"
  fi
  if [[ -f "${src}/LICENSE" ]]; then
    cp "${src}/LICENSE" "${dest}/LICENSE"
  elif [[ -f "${PROJECT_ROOT}/LICENSE.txt" ]]; then
    cp "${PROJECT_ROOT}/LICENSE.txt" "${dest}/LICENSE"
  fi
}

prepare_clone() {
  local repo="$1"
  local dest="$2"
  local url="https://github.com/${GITHUB_ORG}/${repo}.git"

  # One commit on main is enough to add a release commit. Empty repositories
  # have no branch for --depth 1, so fall back to a full clone.
  github_request_pause "clone --depth 1 ${GITHUB_ORG}/${repo}"
  echo "GitHub: clone --depth 1 ${GITHUB_ORG}/${repo}"
  if git clone --depth 1 --single-branch --branch main "$url" "$dest"; then
    remember_github_repo "$repo"
  else
    rm -rf "$dest"
    run_github_git "clone ${url}" "$repo" git clone "$url" "$dest"
  fi
  cd "$dest"

  if git rev-parse --verify HEAD >/dev/null 2>&1; then
    git checkout main 2>/dev/null || git checkout -B main
  else
    git checkout -B main
  fi
}

say_red() {
  if [[ -t 1 ]]; then
    printf '\033[31m%s\033[0m\n' "$*"
  else
    printf '%s\n' "$*"
  fi
}

# Fill PUBLISH_PLAN from the same comparison as scripts/check-version-bumps.sh.
# One row per tag: result, package, kind, repo, tag, detail.
load_publish_plan() {
  local list="${PUBLISH_WORKDIR}/matched-packages.txt"
  local majors=""
  local entry
  printf '%s\n' "${MATCHED_PACKAGES[@]}" > "$list"
  for entry in "${DIST_BUILDS[@]}"; do
    majors+="${entry%%:*} "
  done
  PUBLISH_PLAN="${PUBLISH_WORKDIR}/plan.tsv"
  echo "Comparing publish trees with GitHub tags..."
  CHECK_DIST_PACKAGES="${DIST_PACKAGES[*]}" CHECK_PHP_MAJORS="$majors" \
    php "${PROJECT_ROOT}/scripts/check-version-bumps.php" \
      --format=tsv --only-file "$list" > "$PUBLISH_PLAN"
}

# Read every plan row for one package into PLAN_*.
read_publish_plan() {
  local pkg="$1"
  local result
  local row_pkg
  local kind
  local repo
  local tag
  local detail
  local entry
  PLAN_NEW=0
  PLAN_TAGGED=0
  PLAN_SEEN=0
  PLAN_ERRORS=()
  PLAN_BUMPS=()
  PLAN_PUBLIC="absent"
  PLAN_IMPL="absent"
  PLAN_PUBLIC_TAG=""
  PLAN_IMPL_TAG=""
  while IFS=$'\t' read -r result row_pkg kind repo tag detail; do
    [[ "$row_pkg" == "$pkg" ]] || continue
    PLAN_SEEN=1
    case "$result" in
      new) PLAN_NEW=$((PLAN_NEW + 1)) ;;
      ok) PLAN_TAGGED=$((PLAN_TAGGED + 1)) ;;
      bump)
        PLAN_TAGGED=$((PLAN_TAGGED + 1))
        case "$kind" in
          public) entry="public ${repo} tag ${tag}" ;;
          impl) entry="impl ${repo} tag ${tag}" ;;
          *) entry="${pkg} tag ${tag}" ;;
        esac
        PLAN_BUMPS+=("$entry")
        ;;
      norepo|error)
        PLAN_ERRORS+=("${pkg} (${kind} ${repo} tag ${tag}): ${detail:-$result}")
        ;;
      *)
        PLAN_ERRORS+=("${pkg}: unexpected plan result ${result}")
        ;;
    esac
    if [[ "$kind" == "public" ]]; then
      PLAN_PUBLIC="$result"
      PLAN_PUBLIC_TAG="$tag"
    elif [[ "$kind" == "impl" ]]; then
      PLAN_IMPL="$result"
      PLAN_IMPL_TAG="$tag"
    fi
  done < "$PUBLISH_PLAN"
}

require_publish_plan() {
  local pkg="$1"
  if [[ "$PLAN_SEEN" -eq 0 ]]; then
    echo "error: no publish plan for ${pkg}" >&2
    exit 1
  fi
  if [[ ${#PLAN_ERRORS[@]} -gt 0 ]]; then
    printf '%s\n' "${PLAN_ERRORS[@]}" >&2
    exit 1
  fi
}

note_plan_bumps() {
  local entry
  local i
  local found
  [[ ${#PLAN_BUMPS[@]} -gt 0 ]] || return 0
  for entry in "${PLAN_BUMPS[@]}"; do
    found=0
    if [[ ${#VERSION_BUMP_NEEDED[@]} -gt 0 ]]; then
      for ((i = 0; i < ${#VERSION_BUMP_NEEDED[@]}; i++)); do
        if [[ "${VERSION_BUMP_NEEDED[$i]}" == "$entry" ]]; then
          found=1
          break
        fi
      done
    fi
    if [[ "$found" -eq 0 ]]; then
      VERSION_BUMP_NEEDED+=("$entry")
    fi
    say_red "    CHANGE WITHOUT VERSION BUMP: ${entry} differs from the source tree"
  done
}

commit_and_tag() {
  local dest="$1"
  local tag="$2"

  if git -C "$dest" rev-parse "${tag}" >/dev/null 2>&1; then
    echo "Tag already exists in ${dest}: ${tag}" >&2
    exit 1
  fi

  git -C "$dest" add -A
  if git -C "$dest" diff --cached --quiet; then
    echo "No file changes for tag ${tag}; creating tag on current tree."
  else
    git -C "$dest" commit -m "Release ${tag}"
  fi
  git -C "$dest" tag "$tag"
}

# Published impl composer.json: canonical -impl name and extra.tyhp.public,
# path repositories removed. Source globs and upstream pin are kept.
write_published_impl_composer() {
  php "$PUBLISH_HELPERS" published-impl-composer "$1" "$2"
}

# Publish one public tag when that upstream version has never been tagged.
# Leaves an existing tag in place, including when the generated require changed.
publish_public_meta_tag() {
  local public_dest="$1"
  local upstream="$2"
  local parent="$3"
  local meta_dir="$4"
  local gen_out="$5"
  local first_line
  first_line="${gen_out%%$'\n'*}"
  if [[ "$first_line" == "public-tag: skip" ]]; then
    echo "    public ${upstream} require unchanged; skip public tag"
    return 0
  fi
  if git -C "$public_dest" rev-parse "${upstream}" >/dev/null 2>&1; then
    echo "    public tag ${upstream} already exists; not retagging"
    return 0
  fi
  if [[ ! -f "${meta_dir}/composer.json" ]]; then
    echo "error: public metapackage was not written to ${meta_dir}/composer.json" >&2
    exit 1
  fi
  echo "    public ${parent} tag ${upstream}"
  wipe_published_tree "$public_dest"
  cp "${meta_dir}/composer.json" "${public_dest}/composer.json"
  if [[ -f "${meta_dir}/README.md" ]]; then
    cp "${meta_dir}/README.md" "${public_dest}/README.md"
  fi
  if [[ -f "${meta_dir}/LICENSE" ]]; then
    cp "${meta_dir}/LICENSE" "${public_dest}/LICENSE"
  fi
  commit_and_tag "$public_dest" "$upstream"
  run_github_git "push ${GITHUB_ORG}/${parent} main" "$parent" git -C "$public_dest" push -u origin main
  run_github_git "push ${GITHUB_ORG}/${parent} tag ${upstream}" "$parent" git -C "$public_dest" push origin "$upstream"
}

# Public metapackage (upstream tag) plus impl (four-part tag) on tyhpproject-packages.
# Handed-off folders publish a public tag only when that upstream version has
# never been tagged, then stop. Existing public tags are not moved. Impl tags
# for a handed-off folder are not published.
publish_composer_lib_pair() {
  local pkg="$1"
  local dest="$2"
  local parent upstream impl_tag meta_dir gen_out public_dest impl_dest
  local publish_public=0
  local publish_impl=0
  parent="${pkg%%/*}"
  upstream="${pkg#*/}"
  impl_tag="$(read_tyhp_package_version "$(package_dir "$pkg")/composer.json")" || exit 1
  read_publish_plan "$pkg"
  require_publish_plan "$pkg"

  if [[ "$PLAN_PUBLIC" == "new" ]]; then
    publish_public=1
  elif [[ "$PLAN_PUBLIC" == "ok" || "$PLAN_PUBLIC" == "bump" ]]; then
    echo "    public tag ${upstream} already exists; not retagging"
  else
    echo "error: unexpected public plan ${PLAN_PUBLIC} for ${pkg}" >&2
    exit 1
  fi
  if [[ "$PLAN_IMPL" == "new" ]]; then
    publish_impl=1
  elif [[ "$PLAN_IMPL" == "ok" || "$PLAN_IMPL" == "bump" ]]; then
    echo "    impl already tagged ${PLAN_IMPL_TAG}; skipping"
  elif [[ "$PLAN_IMPL" == "absent" ]]; then
    if ownership_is_handed_off "$(package_dir "$pkg")/composer.json"; then
      echo "    ${pkg} is handed-off; impl tag ${impl_tag} is not published"
    else
      echo "error: publish plan has no impl row for ${pkg}" >&2
      exit 1
    fi
  else
    echo "error: unexpected impl plan ${PLAN_IMPL} for ${pkg}" >&2
    exit 1
  fi
  note_plan_bumps
  if [[ "$publish_public" -eq 0 && "$publish_impl" -eq 0 ]]; then
    cd "$PROJECT_ROOT"
    return 0
  fi

  meta_dir="${dest}-public-meta"
  mkdir -p "$meta_dir"
  if [[ "$publish_public" -eq 1 ]]; then
    public_dest="${dest}-public"
    prepare_clone "$parent" "$public_dest"
    gen_out="$("$PROJECT_ROOT/generate-meta-package.sh" --out "$meta_dir" "$pkg")"
    publish_public_meta_tag "$public_dest" "$upstream" "$parent" "$meta_dir" "$gen_out"
  fi
  if [[ "$publish_impl" -eq 1 ]]; then
    impl_dest="${dest}-impl"
    prepare_clone "${parent}-impl" "$impl_dest"
    echo "    impl ${parent}-impl tag ${impl_tag}"
    sync_composer_lib_dist "$pkg" "$impl_dest"
    write_published_impl_composer "$(package_dir "$pkg")/composer.json" "${impl_dest}/composer.json"
    commit_and_tag "$impl_dest" "$impl_tag"
    run_github_git "push ${GITHUB_ORG}/${parent}-impl main" "${parent}-impl" git -C "$impl_dest" push -u origin main
    run_github_git "push ${GITHUB_ORG}/${parent}-impl tag ${impl_tag}" "${parent}-impl" git -C "$impl_dest" push origin "$impl_tag"
  fi
  cd "$PROJECT_ROOT"
}

# One Packagist / git tag from composer.json version. Used for tyhpdef/php
# and php-ext-*. Composer-lib version folders publish as a public/impl pair.
publish_tyhpdef_package() {
  local pkg="$1"
  local dest="$2"
  local repo="$3"
  if is_versioned_composer_lib "$pkg"; then
    publish_composer_lib_pair "$pkg" "$dest"
    return
  fi
  local tag
  local composer_name
  tag="$(read_tyhp_package_version "$(package_dir "$pkg")/composer.json")" || exit 1
  composer_name="$(read_composer_name "$pkg")"
  if [[ -z "$composer_name" ]]; then
    composer_name="tyhpdef/${repo}"
  fi

  echo "==> Publishing ${composer_name} ${tag} from ${pkg} to ${GITHUB_ORG}/${repo}"

  read_publish_plan "$pkg"
  require_publish_plan "$pkg"
  if [[ "$PLAN_NEW" -eq 0 ]]; then
    echo "    already tagged ${tag}; skipping"
    note_plan_bumps
    cd "$PROJECT_ROOT"
    return 0
  fi

  echo "    ${pkg} ${tag}"
  prepare_clone "$repo" "$dest"
  sync_composer_lib_dist "$pkg" "$dest"
  commit_and_tag "$dest" "$tag"

  run_github_git "push ${GITHUB_ORG}/${repo} main" "$repo" git -C "$dest" push -u origin main
  run_github_git "push ${GITHUB_ORG}/${repo} tag ${tag}" "$repo" git -C "$dest" push origin "$tag"
  cd "$PROJECT_ROOT"
}

# One Packagist / git tag from composer.json version. Used for tyhp/compiler.
# Built in-place (not dist/tyhp-compiler); does not rewrite version onto 80N.X.Y.
publish_compiler_package() {
  local pkg="$1"
  local dest="$2"
  local repo="$3"
  local tag
  local composer_name
  tag="$(read_tyhp_package_version "$(package_dir "$pkg")/composer.json")" || exit 1
  composer_name="$(read_composer_name "$pkg")"
  if [[ -z "$composer_name" ]]; then
    composer_name="tyhp/${repo}"
  fi

  echo "==> Publishing ${composer_name} ${tag} from ${pkg} to ${GITHUB_ORG}/${repo}"

  read_publish_plan "$pkg"
  require_publish_plan "$pkg"
  if [[ "$PLAN_NEW" -eq 0 ]]; then
    echo "    already tagged ${tag}; skipping"
    note_plan_bumps
    cd "$PROJECT_ROOT"
    return 0
  fi

  echo "    ${pkg} ${tag}"
  prepare_clone "$repo" "$dest"
  sync_compiler_dist "$pkg" "$dest"
  commit_and_tag "$dest" "$tag"

  run_github_git "push ${GITHUB_ORG}/${repo} main" "$repo" git -C "$dest" push -u origin main
  run_github_git "push ${GITHUB_ORG}/${repo} tag ${tag}" "$repo" git -C "$dest" push origin "$tag"
  cd "$PROJECT_ROOT"
}

publish_package() {
  local pkg="$1"
  local dest="$2"
  local repo
  local php_major
  local dist_version
  local tags=()

  repo="$(github_repo_for_package "$pkg")"
  if is_tyhpdef_package "$pkg"; then
    publish_tyhpdef_package "$pkg" "$dest" "$repo"
    return
  fi
  if is_compiler_package "$pkg"; then
    publish_compiler_package "$pkg" "$dest" "$repo"
    return
  fi
  if ! is_compiled_package "$pkg"; then
    echo "warning: skipping ${pkg} (not a compiled tyhp/* helper, tyhp/compiler, or tyhpdef/* package)." >&2
    return 0
  fi

  local composer_name
  composer_name="$(read_composer_name "$pkg")"
  if [[ -z "$composer_name" ]]; then
    composer_name="tyhp/${pkg}"
  fi
  echo "==> Publishing ${composer_name} to ${GITHUB_ORG}/${pkg} (PHP 8.2–8.5)"

  load_package_release_version "$pkg" || exit 1
  read_publish_plan "$pkg"
  require_publish_plan "$pkg"
  if [[ "$PLAN_NEW" -eq 0 ]]; then
    echo "    already tagged ${PLAN_TAGGED} PHP targets; skipping"
    note_plan_bumps
    cd "$PROJECT_ROOT"
    return 0
  fi
  if [[ "$PLAN_TAGGED" -gt 0 ]]; then
    note_plan_bumps
    echo "Partial publish in ${GITHUB_ORG}/${pkg}: ${PLAN_TAGGED}/$((PLAN_TAGGED + PLAN_NEW)) tags already exist." >&2
    echo "Finish or delete the incomplete tags, then re-run." >&2
    exit 1
  fi

  prepare_clone "$pkg" "$dest"

  for entry in "${DIST_BUILDS[@]}"; do
    php_major="${entry%%:*}"
    dist_version="$(package_version "$php_major")"
    tags+=("$dist_version")

    echo "    ${pkg} ${dist_version}"
    sync_compile_dist "$pkg" "$dest" "$dist_version"
    commit_and_tag "$dest" "$dist_version"
  done

  run_github_git "push ${GITHUB_ORG}/${pkg} main" "$pkg" git -C "$dest" push -u origin main
  run_github_git "push ${GITHUB_ORG}/${pkg} tags ${tags[*]}" "$pkg" git -C "$dest" push origin "${tags[@]}"
  cd "$PROJECT_ROOT"
}

main() {
  parse_args "$@"

  require_tool php
  require_tool gh
  if [[ "$LIST_ONLY" -eq 0 ]]; then
    require_tool git
    require_tool rsync
  fi

  cd "$PROJECT_ROOT"
  discover_packages
  load_github_repo_cache
  if [[ ${#GITHUB_KNOWN_REPOS[@]} -eq 0 ]]; then
    seed_github_repo_cache_from_org || true
  fi
  echo "GitHub: ${#GITHUB_KNOWN_REPOS[@]} repos in ${GITHUB_REPO_CACHE_FILE}"

  if [[ "$PACKAGE_FILTER_SET" -eq 1 ]]; then
    filter_to_requested_package "$ONLY_PACKAGE"
    if [[ "$LIST_ONLY" -eq 1 ]]; then
      echo "Listing only ${ALL_PACKAGES[0]}"
    else
      echo "Publishing only ${ALL_PACKAGES[0]}"
    fi
  fi

  if [[ "$LIST_ONLY" -eq 1 ]]; then
    collect_publish_matches
    print_publish_matches
    if [[ ${#PACKAGIST_FAILED_NAMES[@]} -gt 0 ]]; then
      return 1
    fi
    return 0
  fi

  if [[ -f "$PACKAGIST_CREDENTIALS_FILE" ]]; then
    validate_packagist_credentials
    PACKAGIST_CREDENTIALS_READY=1
  elif [[ "$CREATE_PACKAGIST" -eq 1 ]]; then
    validate_packagist_credentials
  fi

  local compiled_packages=()
  local compiler_packages=()
  local tyhpdef_packages=()
  local pkg
  local needs_compiled_build=0
  local needs_compiler_build=0
  for pkg in "${ALL_PACKAGES[@]}"; do
    if is_compiled_package "$pkg"; then
      compiled_packages+=("$pkg")
      needs_compiled_build=1
    elif is_compiler_package "$pkg"; then
      compiler_packages+=("$pkg")
      needs_compiler_build=1
    elif is_tyhpdef_package "$pkg"; then
      tyhpdef_packages+=("$pkg")
    fi
  done

  if [[ ${#compiled_packages[@]} -gt 0 ]]; then
    echo "Compiled runtime package versions (Packagist 80N.X.Y):"
    assert_valid_package_versions "${compiled_packages[@]}"
  fi

  if [[ ${#compiler_packages[@]} -gt 0 ]]; then
    echo "tyhp/compiler package version (Packagist tag = composer.json version):"
    local compiler_ver
    local compiler_repo
    for pkg in "${compiler_packages[@]}"; do
      compiler_ver="$(read_tyhp_package_version "$(package_dir "$pkg")/composer.json")" || exit 1
      compiler_repo="$(github_repo_for_package "$pkg")"
      echo "  ${pkg}: ${compiler_ver} → ${GITHUB_ORG}/${compiler_repo} tag ${compiler_ver}"
    done
  fi

  if [[ ${#tyhpdef_packages[@]} -gt 0 ]]; then
    echo "tyhpdef package versions (Packagist tag = composer.json version):"
    local def_ver
    local def_repo
    for pkg in "${tyhpdef_packages[@]}"; do
      def_ver="$(read_tyhp_package_version "$(package_dir "$pkg")/composer.json")" || exit 1
      def_repo="$(github_repo_for_package "$pkg")"
      echo "  ${pkg}: ${def_ver} → ${GITHUB_ORG}/${def_repo} tag ${def_ver}"
    done
  fi

  if [[ "$CREATE_GITHUB" -eq 1 ]]; then
    ensure_missing_github_repos
  fi

  if [[ "$needs_compiled_build" -eq 1 ]]; then
    require_tool dotnet
    echo "Building runtime packages for PHP 8.2–8.5..."
    "${PACKAGES_DIR}/base-build-all.sh"
  elif [[ "$needs_compiler_build" -eq 1 ]]; then
    require_tool dotnet
    echo "Building tyhp/compiler..."
    "${PACKAGES_DIR}/base-build-all.sh" compiler
  else
    echo "Skipping compiled-package build (no ${DIST_PACKAGES[*]} or compiler package in this run)."
  fi

  collect_publish_matches

  PUBLISH_WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/tyhp-pkg-publish.XXXXXX")"
  trap cleanup_publish_workdir EXIT

  if [[ ${#MATCHED_PACKAGES[@]} -gt 0 ]]; then
    load_publish_plan
  fi

  local published=()
  local dest_name
  echo "Publishing ${#MATCHED_PACKAGES[@]} package(s)..."
  if [[ ${#MATCHED_PACKAGES[@]} -gt 0 ]]; then
    for pkg in "${MATCHED_PACKAGES[@]}"; do
      dest_name="${pkg//\//--}"
      publish_package "$pkg" "${PUBLISH_WORKDIR}/${dest_name}"
      published+=("$pkg")
      if [[ "$CREATE_PACKAGIST" -eq 1 ]]; then
        ensure_packagist_package "$pkg" || true
      fi
      refresh_packagist_package "$pkg" || true
    done
  fi

  if [[ ${#published[@]} -gt 0 ]]; then
    echo "Published ${published[*]}."
  else
    echo "No packages published."
  fi
  if [[ ${#VERSION_BUMP_NEEDED[@]} -gt 0 ]]; then
    say_red "Version bump needed (${#VERSION_BUMP_NEEDED[@]}):"
    local bump
    for bump in "${VERSION_BUMP_NEEDED[@]}"; do
      say_red "  ${bump}"
    done
  fi
  if [[ ${#GITHUB_CREATED_REPOS[@]} -gt 0 ]]; then
    echo "Created GitHub repos: ${GITHUB_CREATED_REPOS[*]}"
  fi
  if [[ ${#GITHUB_CREATE_FAILED_REPOS[@]} -gt 0 ]]; then
    echo "Failed GitHub creates: ${GITHUB_CREATE_FAILED_REPOS[*]}" >&2
  fi
  if [[ ${#SKIPPED_PACKAGES[@]} -gt 0 ]]; then
    echo "Skipped: ${SKIPPED_PACKAGES[*]}" >&2
  fi
  if [[ "$CREATE_PACKAGIST" -eq 1 ]]; then
    if [[ ${#PACKAGIST_CREATED_NAMES[@]} -gt 0 ]]; then
      echo "Created Packagist packages: ${PACKAGIST_CREATED_NAMES[*]}"
    fi
    if [[ ${#PACKAGIST_EXISTED_NAMES[@]} -gt 0 ]]; then
      echo "Already on Packagist: ${PACKAGIST_EXISTED_NAMES[*]}"
    fi
    if [[ ${#PACKAGIST_FAILED_NAMES[@]} -gt 0 ]]; then
      echo "Failed Packagist creates: ${PACKAGIST_FAILED_NAMES[*]}" >&2
    fi
  fi
  if [[ ${#PACKAGIST_UPDATED_NAMES[@]} -gt 0 ]]; then
    echo "Packagist update queued: ${PACKAGIST_UPDATED_NAMES[*]}"
  fi
  if [[ ${#PACKAGIST_CURRENT_NAMES[@]} -gt 0 ]]; then
    echo "Packagist already current: ${#PACKAGIST_CURRENT_NAMES[@]} package(s)"
  fi
  if [[ ${#PACKAGIST_UNREGISTERED_NAMES[@]} -gt 0 ]]; then
    echo "Not on Packagist: ${PACKAGIST_UNREGISTERED_NAMES[*]}" >&2
    echo "Pass --create-packagist-packages to register them." >&2
  fi
  if [[ ${#PACKAGIST_UPDATE_FAILED_NAMES[@]} -gt 0 ]]; then
    echo "Failed Packagist updates: ${PACKAGIST_UPDATE_FAILED_NAMES[*]}" >&2
  fi
  if [[ "$PACKAGIST_CREDENTIALS_READY" -eq 0 && "$CREATE_PACKAGIST" -eq 0 ]]; then
    echo "Next HUMAN step: submit each https://github.com/${GITHUB_ORG}/{package} URL on Packagist."
    echo "Or add ${PACKAGIST_CREDENTIALS_FILE} and run scripts/update-packagist-packages.sh."
  fi
  if [[ ${#GITHUB_CREATE_FAILED_REPOS[@]} -gt 0 || ${#PACKAGIST_FAILED_NAMES[@]} -gt 0 || ${#PACKAGIST_UPDATE_FAILED_NAMES[@]} -gt 0 ]]; then
    return 1
  fi
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
