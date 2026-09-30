#!/usr/bin/env bash
# Build runtime packages in this repo and publish installable trees to
# tyhpproject-packages/{package} repositories. Discovers packages from
# packages/*/composer.json (flat: compiled runtimes, tyhpdef/php, php-ext-*)
# and packages/*/*/composer.json (versioned Composer-lib wrappers).
# Ignores packages/dist and any path named vendor. If a GitHub repo
# does not exist yet, that package is skipped with a warning unless
# --create-github-repos is passed, in which case an empty public repo is
# created (same gh repo create as ensure-existing-github-repos.sh: no README,
# license, or .gitignore; no local remote add). After the first create, each
# later create waits 30 seconds. If GitHub rejects a create for going too
# quickly, the script waits and retries that repo. Not a submodule workflow.
#
# Pass --package NAME to publish one package (and leave the other GitHub repos
# untouched). NAME is a path relative to packages (php, php-ext-bz2,
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
# packages/<vendor>-<name>/<upstream>/. A handed-off folder does not retag a
# public version that already exists and does not publish an impl tag. A
# public version that has never been tagged is published once from the
# handed-off metapackage (no impl require). First-party php, php-ext-*, and
# compiled tyhp/* stay one repository each.
#
# tyhpdef/* packages (php, php-ext-*, Composer-lib wrappers) use that
# composer.json version as the Packagist / git tag (for example 1.0.0 or 0.1).
# They are one tree across PHP 8.2–8.5; they do not get 80N.X.Y tags.
#
# Compiled tyhp/* packages (core, async, decimal, lambda) stay flat and are
# tagged once per PHP target (802 / 803 / 804 / 805). Package MAJOR is the emit
# PHP. X.Y comes from that package's own composer.json (independent of the
# compiler version). Libraries require 80N.X.* across PHP majors for a given
# compiled package. See VERSIONING.md and packages/build-common.sh.
#
# tyhp/compiler is a single-version PHP shim (not a per-PHP dist tree). It is
# built in-place under packages/compiler and tagged from that
# composer.json version (for example 805.0.0), same tag style as tyhpdef/*.
#
# Do not run this until after the compiler history wipe and the package repos are public.
# Packagist submit is a separate HUMAN step after this push unless
# --create-packagist-packages is passed (reads scripts/packagist.credentials).
# Git remotes use HTTPS (not SSH).

set -euo pipefail

readonly PUBLISH_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_ROOT="$(cd "${PUBLISH_SCRIPT_DIR}/.." && pwd)"
readonly PACKAGES_DIR="${PROJECT_ROOT}/packages"
readonly GITHUB_ORG="tyhpproject-packages"
readonly PACKAGIST_CREDENTIALS_FILE="${PROJECT_ROOT}/scripts/packagist.credentials"
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
readonly GITHUB_CREATE_RATE_LIMIT_MAX_ATTEMPTS=10
readonly GITHUB_CREATE_RATE_LIMIT_INITIAL_SLEEP=60
readonly GITHUB_CREATE_RATE_LIMIT_MAX_SLEEP=1800
PACKAGIST_CREATED_NAMES=()
PACKAGIST_EXISTED_NAMES=()
PACKAGIST_WOULD_CREATE_NAMES=()
PACKAGIST_FAILED_NAMES=()
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
  --package NAME                  Publish only this package. NAME is a path
                                  relative to packages (php,
                                  php-ext-bz2, compiler, psr-log/3.0.2) or a
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

Without --package, every discovered package with a GitHub repo is published.
Without the create flags, missing GitHub repos are skipped and Packagist
submit stays a separate human step. Compiled packages (core, async, decimal,
lambda) still run base-build-all.sh so dist trees exist. tyhp/compiler is
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
  case "$1" in
    core|async|decimal|lambda) return 0 ;;
    *) return 1 ;;
  esac
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

# Flat packages: packages/<name>/composer.json
# Versioned Composer-libs: packages/<vendor>-<name>/<upstream>/composer.json
# Stored as a path relative to PACKAGES_DIR (e.g. php-ext-bz2 or psr-log/3.0.2).
# Dies if the same <vendor>-<name> has both a flat composer.json and a versioned
# tree — that ambiguous state would publish the same GitHub repo twice.
discover_packages() {
  local composer_json
  local pkg
  local parent
  local ver
  local flat_names=()

  ALL_PACKAGES=()

  for composer_json in "${PACKAGES_DIR}"/*/composer.json; do
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

  for composer_json in "${PACKAGES_DIR}"/*/*/composer.json; do
    if [[ ! -f "$composer_json" ]]; then
      continue
    fi
    ver="$(basename "$(dirname "$composer_json")")"
    parent="$(basename "$(dirname "$(dirname "$composer_json")")")"
    if is_skipped_package_name "$parent" || is_skipped_package_name "$ver"; then
      continue
    fi
    if is_flat_package_name "$parent" "${flat_names[@]}"; then
      echo "error: ${parent} has both a flat composer.json (${PACKAGES_DIR}/${parent}/composer.json)" >&2
      echo "and a versioned tree (${composer_json}). Remove the leftover flat layout" >&2
      echo "before publishing; otherwise ${GITHUB_ORG}/${parent} would be published twice." >&2
      exit 1
    fi
    ALL_PACKAGES+=("${parent}/${ver}")
  done

  if [[ ${#ALL_PACKAGES[@]} -eq 0 ]]; then
    echo "No packages found in ${PACKAGES_DIR} (expected */composer.json or */*/composer.json)." >&2
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
  python3 - "$1" <<'PY'
import json, sys
doc = json.load(open(sys.argv[1], encoding="utf-8"))
extra = doc.get("extra") if isinstance(doc.get("extra"), dict) else {}
tyhp = extra.get("tyhp") if isinstance(extra.get("tyhp"), dict) else {}
ownership = tyhp.get("ownership") if isinstance(tyhp.get("ownership"), dict) else {}
sys.exit(0 if ownership.get("status") == "handed-off" else 1)
PY
}

is_versioned_composer_lib() {
  [[ "$1" == */* ]]
}

read_composer_name() {
  local pkg="$1"
  python3 -c "import json,sys; print(json.load(open(sys.argv[1])).get('name',''))" \
    "${PACKAGES_DIR}/${pkg}/composer.json" 2>/dev/null || true
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
  echo "Use a path relative to packages (php, php-ext-bz2, compiler, psr-log/3.0.2) or a Composer name (tyhpdef/php, tyhp/compiler)." >&2
  exit 1
}

read_tyhp_package_version() {
  local composer_json="$1"
  python3 - "$composer_json" <<'PY'
import json, sys
path = sys.argv[1]
raw = str(json.load(open(path)).get("version", "")).strip()
if not raw:
    print(f"Missing version in {path}", file=sys.stderr)
    sys.exit(1)
print(raw)
PY
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

# Return 0 if tyhpproject-packages/<repo> exists (including empty repos). Return 1 if GitHub
# reports the repo is missing. Any other gh failure aborts the script.
github_repo_exists() {
  local repo="$1"
  local output
  local rc=0

  output="$(gh repo view "${GITHUB_ORG}/${repo}" --json name 2>&1)" || rc=$?
  if [[ "$rc" -eq 0 ]]; then
    return 0
  fi
  if [[ "$output" == *"Could not resolve to a Repository"* || "$output" == *"Not Found"* || "$output" == *"HTTP 404"* ]]; then
    return 1
  fi
  echo "$output" >&2
  echo "Failed to check GitHub repo ${GITHUB_ORG}/${repo}." >&2
  exit 1
}

# GitHub About text: composer.json description (≤350 chars), else a short fallback.
github_repo_description() {
  local pkg="$1"
  python3 - "${PACKAGES_DIR}/${pkg}/composer.json" "$pkg" <<'PY'
import json, sys

path, pkg = sys.argv[1], sys.argv[2]
try:
    data = json.load(open(path, encoding="utf-8"))
except (OSError, json.JSONDecodeError):
    data = {}
desc = " ".join(str(data.get("description") or "").split())
if not desc:
    name = str(data.get("name") or "").strip() or pkg
    desc = f"Tyhp package {name}"
if len(desc) > 350:
    desc = desc[:347] + "..."
print(desc)
PY
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
      return 0
    fi
    if [[ "$output" == *"already exists"* || "$output" == *"Name already exists"* ]]; then
      echo "GitHub: ${GITHUB_ORG}/${repo} already exists (skip create)"
      return 0
    fi
    if github_repo_create_rate_limited "$output" && [[ "$attempt" -lt "$GITHUB_CREATE_RATE_LIMIT_MAX_ATTEMPTS" ]]; then
      echo "GitHub: rate limited creating ${GITHUB_ORG}/${repo}; waiting ${sleep_secs}s then retrying (attempt ${attempt}/$((GITHUB_CREATE_RATE_LIMIT_MAX_ATTEMPTS - 1)))" >&2
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
  python3 - "$name" "$PACKAGIST_USER_AGENT" <<'PY'
import sys
import urllib.error
import urllib.parse
import urllib.request

name, ua = sys.argv[1], sys.argv[2]
vendor, _, proj = name.partition("/")
if not vendor or not proj:
    print(f"error: invalid Composer name {name!r}", file=sys.stderr)
    sys.exit(2)
url = "https://packagist.org/packages/{}/{}.json".format(
    urllib.parse.quote(vendor, safe=""),
    urllib.parse.quote(proj, safe=""),
)
req = urllib.request.Request(url, headers={"User-Agent": ua})
try:
    with urllib.request.urlopen(req, timeout=60) as resp:
        sys.exit(0 if 200 <= resp.getcode() < 300 else 2)
except urllib.error.HTTPError as err:
    if err.code == 404:
        sys.exit(1)
    print(f"error: Packagist lookup {name} failed: HTTP {err.code}", file=sys.stderr)
    sys.exit(2)
except urllib.error.URLError as err:
    print(f"error: Packagist lookup {name} failed: {err}", file=sys.stderr)
    sys.exit(2)
PY
}

load_packagist_credentials() {
  python3 - "$PACKAGIST_CREDENTIALS_FILE" <<'PY'
import sys

path = sys.argv[1]
username = None
token = None
try:
    lines = open(path, encoding="utf-8").read().splitlines()
except OSError as err:
    print(f"error: cannot read {path}: {err}", file=sys.stderr)
    sys.exit(1)
for raw in lines:
    line = raw.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, _, val = line.partition("=")
    key = key.strip()
    val = val.strip().strip("'").strip('"')
    if key == "PACKAGIST_USERNAME":
        username = val
    elif key == "PACKAGIST_API_TOKEN":
        token = val
if not username:
    print(f"error: PACKAGIST_USERNAME is empty in {path}", file=sys.stderr)
    sys.exit(1)
if not token:
    print(f"error: PACKAGIST_API_TOKEN is empty in {path}", file=sys.stderr)
    sys.exit(1)
print(username)
PY
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

# POST /api/create-package. Python reads the gitignored credentials file so the
# MAIN token is not interpolated into the process argv.
create_packagist_package() {
  local repo_url="$1"
  python3 - "$PACKAGIST_CREDENTIALS_FILE" "$repo_url" "$PACKAGIST_USER_AGENT" <<'PY'
import json
import sys
import urllib.error
import urllib.request

path, repo_url, ua = sys.argv[1], sys.argv[2], sys.argv[3]
username = None
token = None
try:
    lines = open(path, encoding="utf-8").read().splitlines()
except OSError as err:
    print(f"error: cannot read {path}: {err}", file=sys.stderr)
    sys.exit(1)
for raw in lines:
    line = raw.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, _, val = line.partition("=")
    key = key.strip()
    val = val.strip().strip("'").strip('"')
    if key == "PACKAGIST_USERNAME":
        username = val
    elif key == "PACKAGIST_API_TOKEN":
        token = val
if not username or not token:
    print(f"error: PACKAGIST_USERNAME / PACKAGIST_API_TOKEN missing in {path}", file=sys.stderr)
    sys.exit(1)

body = json.dumps({"repository": repo_url}).encode("utf-8")
req = urllib.request.Request(
    "https://packagist.org/api/create-package",
    data=body,
    headers={
        "Content-Type": "application/json",
        "Authorization": f"Bearer {username}:{token}",
        "User-Agent": ua,
    },
    method="POST",
)
try:
    with urllib.request.urlopen(req, timeout=60) as resp:
        raw = resp.read().decode("utf-8", errors="replace")
        status = resp.getcode()
except urllib.error.HTTPError as err:
    raw = err.read().decode("utf-8", errors="replace")
    lowered = raw.lower()
    if err.code in (400, 409) and ("already" in lowered or "exists" in lowered):
        print(raw)
        sys.exit(0)
    print(f"error: Packagist create-package HTTP {err.code} for {repo_url}", file=sys.stderr)
    if raw:
        print(raw, file=sys.stderr)
    sys.exit(1)
except urllib.error.URLError as err:
    print(f"error: Packagist create-package failed for {repo_url}: {err}", file=sys.stderr)
    sys.exit(1)

try:
    payload = json.loads(raw) if raw else {}
except json.JSONDecodeError:
    payload = {}
msg = str(payload.get("status") or "").lower()
if 200 <= status < 300 and msg in ("", "success"):
    sys.exit(0)
text = str(payload.get("message") or raw or "").lower()
if "already" in text or "exists" in text:
    sys.exit(0)
print(f"error: Packagist create-package rejected {repo_url}: {raw}", file=sys.stderr)
sys.exit(1)
PY
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
  for pkg in "${ALL_PACKAGES[@]}"; do
    if ! is_publishable_package "$pkg"; then
      continue
    fi
    while IFS= read -r repo; do
      [[ -n "$repo" ]] || continue
      ensure_github_repo "$repo" "$pkg" || true
    done < <(package_github_repos "$pkg")
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

  for pkg in "${ALL_PACKAGES[@]}"; do
    if ! is_publishable_package "$pkg"; then
      echo "warning: skipping ${pkg} (not a compiled tyhp/* helper, tyhp/compiler, or tyhpdef/* package)." >&2
      SKIPPED_PACKAGES+=("$pkg")
      continue
    fi
    local missing=0
    while IFS= read -r repo; do
      [[ -n "$repo" ]] || continue
      if ! ensure_github_repo "$repo" "$pkg"; then
        missing=1
        if [[ "$CREATE_GITHUB" -eq 1 ]]; then
          echo "warning: GitHub repo ${GITHUB_ORG}/${repo} could not be created; skipping ${pkg}." >&2
        else
          echo "warning: GitHub repo ${GITHUB_ORG}/${repo} does not exist; skipping ${pkg} (pass --create-github-repos to create it)." >&2
        fi
      fi
    done < <(package_github_repos "$pkg")
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
  local src="${PACKAGES_DIR}/${pkg}"

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
  local src="$1"
  local dest="$2"
  python3 - "$src" "$dest" <<'PY'
import json, sys
src, dest = sys.argv[1], sys.argv[2]
data = json.load(open(src, encoding="utf-8"))
data.pop("repositories", None)
with open(dest, "w", encoding="utf-8") as out:
    json.dump(data, out, indent=4)
    out.write("\n")
PY
}

# Copy the in-place tyhp/compiler tree (emitted src/, bin/tyhp, README, LICENSE).
# One Packagist tag from composer.json; not rewritten to 80N.X.Y.
sync_compiler_dist() {
  local pkg="$1"
  local dest="$2"
  local src="${PACKAGES_DIR}/${pkg}"

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
  local pkg="$1"
  local dest="$2"

  git clone "https://github.com/${GITHUB_ORG}/${pkg}.git" "$dest"
  cd "$dest"

  if git rev-parse --verify HEAD >/dev/null 2>&1; then
    git checkout main 2>/dev/null || git checkout -B main
  else
    git checkout -B main
  fi
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
  local src="$1"
  local dest="$2"
  python3 - "$src" "$dest" <<'PY'
import json, sys
src, dest = sys.argv[1], sys.argv[2]
data = json.load(open(src, encoding="utf-8"))
data.pop("repositories", None)
name = str(data.get("name") or "")
extra = data.setdefault("extra", {})
tyhp = extra.setdefault("tyhp", {})
if name.endswith("-impl"):
    public = str(tyhp.get("public") or name[: -len("-impl")])
    impl = name
else:
    public = str(tyhp.get("public") or name)
    impl = public + "-impl"
data["name"] = impl
tyhp["public"] = public
desc = str(data.get("description") or "")
if "Implementation package" not in desc:
    data["description"] = f"Implementation package for {public}. Require {public}, not this package."
keywords = data.get("keywords")
if not isinstance(keywords, list):
    keywords = ["dev", "static analysis"]
if "internal" not in keywords:
    keywords.append("internal")
data["keywords"] = keywords
data["type"] = "library"
with open(dest, "w", encoding="utf-8") as out:
    json.dump(data, out, indent=4)
    out.write("\n")
PY
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
  git -C "$public_dest" push -u origin main
  git -C "$public_dest" push origin "$upstream"
}

# Public metapackage (upstream tag) plus impl (four-part tag) on tyhpproject-packages.
# Handed-off folders publish a public tag only when that upstream version has
# never been tagged, then stop. Existing public tags are not moved. Impl tags
# for a handed-off folder are not published.
publish_composer_lib_pair() {
  local pkg="$1"
  local dest="$2"
  local parent upstream impl_tag meta_dir gen_out public_dest impl_dest existing
  parent="${pkg%%/*}"
  upstream="${pkg#*/}"
  impl_tag="$(read_tyhp_package_version "${PACKAGES_DIR}/${pkg}/composer.json")" || exit 1
  meta_dir="${dest}-public-meta"
  mkdir -p "$meta_dir"

  public_dest="${dest}-public"
  prepare_clone "$parent" "$public_dest"
  existing=""
  if git -C "$public_dest" rev-parse "${upstream}" >/dev/null 2>&1; then
    existing="${meta_dir}/existing-public.json"
    git -C "$public_dest" show "${upstream}:composer.json" > "$existing"
  fi
  if [[ -n "$existing" ]]; then
    gen_out="$("$PROJECT_ROOT/generate-meta-package.sh" --out "$meta_dir" --existing-public "$existing" "$pkg")"
  else
    gen_out="$("$PROJECT_ROOT/generate-meta-package.sh" --out "$meta_dir" "$pkg")"
  fi
  publish_public_meta_tag "$public_dest" "$upstream" "$parent" "$meta_dir" "$gen_out"

  if ownership_is_handed_off "${PACKAGES_DIR}/${pkg}/composer.json"; then
    echo "    ${pkg} is handed-off; impl tag ${impl_tag} is not published"
    cd "$PROJECT_ROOT"
    return 0
  fi

  impl_dest="${dest}-impl"
  prepare_clone "${parent}-impl" "$impl_dest"
  if git -C "$impl_dest" rev-parse "${impl_tag}" >/dev/null 2>&1; then
    echo "    impl already tagged ${impl_tag}; skipping"
    cd "$PROJECT_ROOT"
    return 0
  fi
  echo "    impl ${parent}-impl tag ${impl_tag}"
  sync_composer_lib_dist "$pkg" "$impl_dest"
  write_published_impl_composer "${PACKAGES_DIR}/${pkg}/composer.json" "${impl_dest}/composer.json"
  commit_and_tag "$impl_dest" "$impl_tag"
  git -C "$impl_dest" push -u origin main
  git -C "$impl_dest" push origin "$impl_tag"
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
  tag="$(read_tyhp_package_version "${PACKAGES_DIR}/${pkg}/composer.json")" || exit 1
  composer_name="$(read_composer_name "$pkg")"
  if [[ -z "$composer_name" ]]; then
    composer_name="tyhpdef/${repo}"
  fi

  echo "==> Publishing ${composer_name} ${tag} from ${pkg} to ${GITHUB_ORG}/${repo}"

  prepare_clone "$repo" "$dest"

  if git -C "$dest" rev-parse "${tag}" >/dev/null 2>&1; then
    echo "    already tagged ${tag}; skipping"
    cd "$PROJECT_ROOT"
    return 0
  fi

  echo "    ${pkg} ${tag}"
  sync_composer_lib_dist "$pkg" "$dest"
  commit_and_tag "$dest" "$tag"

  git -C "$dest" push -u origin main
  git -C "$dest" push origin "$tag"
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
  tag="$(read_tyhp_package_version "${PACKAGES_DIR}/${pkg}/composer.json")" || exit 1
  composer_name="$(read_composer_name "$pkg")"
  if [[ -z "$composer_name" ]]; then
    composer_name="tyhp/${repo}"
  fi

  echo "==> Publishing ${composer_name} ${tag} from ${pkg} to ${GITHUB_ORG}/${repo}"

  prepare_clone "$repo" "$dest"

  if git -C "$dest" rev-parse "${tag}" >/dev/null 2>&1; then
    echo "    already tagged ${tag}; skipping"
    cd "$PROJECT_ROOT"
    return 0
  fi

  echo "    ${pkg} ${tag}"
  sync_compiler_dist "$pkg" "$dest"
  commit_and_tag "$dest" "$tag"

  git -C "$dest" push -u origin main
  git -C "$dest" push origin "$tag"
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
  prepare_clone "$pkg" "$dest"

  local existing=0
  local needed=0
  for entry in "${DIST_BUILDS[@]}"; do
    php_major="${entry%%:*}"
    dist_version="$(package_version "$php_major")"
    needed=$((needed + 1))
    if git -C "$dest" rev-parse "${dist_version}" >/dev/null 2>&1; then
      existing=$((existing + 1))
    fi
  done
  if [[ "$existing" -eq "$needed" ]]; then
    echo "    already tagged ${needed} PHP targets; skipping"
    cd "$PROJECT_ROOT"
    return 0
  fi
  if [[ "$existing" -gt 0 ]]; then
    echo "Partial publish in ${GITHUB_ORG}/${pkg}: ${existing}/${needed} tags already exist." >&2
    echo "Finish or delete the incomplete tags, then re-run." >&2
    exit 1
  fi

  for entry in "${DIST_BUILDS[@]}"; do
    php_major="${entry%%:*}"
    dist_version="$(package_version "$php_major")"
    tags+=("$dist_version")

    echo "    ${pkg} ${dist_version}"
    sync_compile_dist "$pkg" "$dest" "$dist_version"
    commit_and_tag "$dest" "$dist_version"
  done

  git -C "$dest" push -u origin main
  git -C "$dest" push origin "${tags[@]}"
  cd "$PROJECT_ROOT"
}

main() {
  parse_args "$@"

  require_tool python3
  require_tool gh
  if [[ "$LIST_ONLY" -eq 0 ]]; then
    require_tool git
    require_tool rsync
  fi

  cd "$PROJECT_ROOT"
  discover_packages

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

  if [[ "$CREATE_PACKAGIST" -eq 1 ]]; then
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
      compiler_ver="$(read_tyhp_package_version "${PACKAGES_DIR}/${pkg}/composer.json")" || exit 1
      compiler_repo="$(github_repo_for_package "$pkg")"
      echo "  ${pkg}: ${compiler_ver} → ${GITHUB_ORG}/${compiler_repo} tag ${compiler_ver}"
    done
  fi

  if [[ ${#tyhpdef_packages[@]} -gt 0 ]]; then
    echo "tyhpdef package versions (Packagist tag = composer.json version):"
    local def_ver
    local def_repo
    for pkg in "${tyhpdef_packages[@]}"; do
      def_ver="$(read_tyhp_package_version "${PACKAGES_DIR}/${pkg}/composer.json")" || exit 1
      def_repo="$(github_repo_for_package "$pkg")"
      echo "  ${pkg}: ${def_ver} → ${GITHUB_ORG}/${def_repo} tag ${def_ver}"
    done
  fi

  if [[ "$CREATE_GITHUB" -eq 1 ]]; then
    ensure_missing_github_repos
  fi

  if [[ "$needs_compiled_build" -eq 1 ]]; then
    require_tool php
    require_tool dotnet
    echo "Building runtime packages for PHP 8.2–8.5..."
    "${PACKAGES_DIR}/base-build-all.sh"
  elif [[ "$needs_compiler_build" -eq 1 ]]; then
    require_tool php
    require_tool dotnet
    echo "Building tyhp/compiler..."
    "${PACKAGES_DIR}/base-build-all.sh" compiler
  else
    echo "Skipping compiled-package build (no core/async/decimal/lambda/compiler in this run)."
  fi

  collect_publish_matches

  PUBLISH_WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/tyhp-pkg-publish.XXXXXX")"
  trap cleanup_publish_workdir EXIT

  local published=()
  local dest_name
  if [[ ${#MATCHED_PACKAGES[@]} -gt 0 ]]; then
    for pkg in "${MATCHED_PACKAGES[@]}"; do
      dest_name="${pkg//\//--}"
      publish_package "$pkg" "${PUBLISH_WORKDIR}/${dest_name}"
      published+=("$pkg")
      if [[ "$CREATE_PACKAGIST" -eq 1 ]]; then
        ensure_packagist_package "$pkg" || true
      fi
    done
  fi

  if [[ ${#published[@]} -gt 0 ]]; then
    echo "Published ${published[*]}."
  else
    echo "No packages published."
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
  else
    echo "Next HUMAN step: submit each https://github.com/${GITHUB_ORG}/{package} URL on Packagist."
  fi
  if [[ ${#GITHUB_CREATE_FAILED_REPOS[@]} -gt 0 || ${#PACKAGIST_FAILED_NAMES[@]} -gt 0 ]]; then
    return 1
  fi
}

main "$@"
