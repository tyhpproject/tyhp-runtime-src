#!/usr/bin/env bash
# One-time helper: ensure empty public GitHub repos exist for already-scaffolded
# local packages (php-ext-*, psr-log, and other local psr-* trees, monolog-monolog).
# New Composer-lib packages should use new-proposed-composer-libs.sh instead.
#
# Does not scaffold, does not run generate_tyhpdef or new-composer-lib.sh, does
# not git push, and does not add or change local git remotes. Publish later with
# scripts/publish-runtime-packages.sh (optionally --create-github-repos /
# --create-packagist-packages).
#
# GitHub org is tyhpproject-packages. php-ext-* is one repo. Versioned
# Composer-libs (psr-log, monolog-monolog) get <folder> and <folder>-impl.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" && pwd)"
PACKAGES_DIR="$SCRIPT_DIR"
GITHUB_ORG="tyhpproject-packages"

DRY_RUN=0
FAIL_FAST=0

usage() {
  cat <<'EOF'
Usage: ensure-existing-github-repos.sh [options]

Create empty public GitHub repos for local runtime packages that are already
scaffolded: php-ext-* (flat), psr-* (currently psr-log; any other local psr-*
tree is included), and monolog-monolog. php-ext-* is one repo. Versioned
Composer-libs get tyhpproject-packages/<folder> and
tyhpproject-packages/<folder>-impl.

  gh repo view tyhpproject-packages/<folder>     — skip if it already exists
  gh repo create tyhpproject-packages/<folder> --public --description "..."
                                        — empty repo (no README / license /
                                          gitignore). No git push. No local
                                          git remote add.

Does not run new-composer-lib.sh or generate_tyhpdef. Does not publish or
submit to Packagist. For newly proposed Composer-libs, use
new-proposed-composer-libs.sh.

Options:
  -h, --help        Show this help
  -n, --dry-run     Print gh commands; do not create repos
  --fail-fast       Abort on the first per-repo failure (default: continue)

Examples:
  ./ensure-existing-github-repos.sh --dry-run
  ./ensure-existing-github-repos.sh --fail-fast
EOF
}

die() {
  echo "error: $*" >&2
  exit 1
}

require_tool() {
  local tool_name="$1"
  if ! command -v "$tool_name" >/dev/null 2>&1; then
    die "Missing required tool: ${tool_name}"
  fi
}

is_skipped_package_name() {
  case "$1" in
    dist|vendor) return 0 ;;
    *) return 1 ;;
  esac
}

# True when this top-level folder is in scope for this one-time helper.
is_in_scope_package() {
  case "$1" in
    php-ext-*) return 0 ;;
    psr-*) return 0 ;;
    monolog-monolog) return 0 ;;
    *) return 1 ;;
  esac
}

# Prefer a versioned tree's composer.json (skip dist/vendor children). Fall
# back to a flat composer.json on the package folder itself.
find_package_composer() {
  local dir="$1"
  local composer_json
  local ver
  for composer_json in "$dir"/*/composer.json; do
    if [[ ! -f "$composer_json" ]]; then
      continue
    fi
    ver="$(basename "$(dirname "$composer_json")")"
    if is_skipped_package_name "$ver"; then
      continue
    fi
    printf '%s\n' "$composer_json"
    return 0
  done
  if [[ -f "$dir/composer.json" ]]; then
    printf '%s\n' "$dir/composer.json"
    return 0
  fi
  return 1
}

# Local version folder names (not GitHub repos). Empty when the package is flat.
list_version_folders() {
  local dir="$1"
  local composer_json
  local ver
  local versions=()
  for composer_json in "$dir"/*/composer.json; do
    if [[ ! -f "$composer_json" ]]; then
      continue
    fi
    ver="$(basename "$(dirname "$composer_json")")"
    if is_skipped_package_name "$ver"; then
      continue
    fi
    versions+=("$ver")
  done
  if [[ ${#versions[@]} -gt 0 ]]; then
    printf '%s' "${versions[*]}"
  fi
}

# Short Packagist-ready GitHub description from composer.json, else the folder.
# php-ext-pdo → "Tyhp tyhpdefs for ext-pdo"
# psr-log     → "Tyhp tyhpdefs for psr/log"
repo_description() {
  local folder="$1"
  local composer="$2"
  if [[ -f "$composer" ]] && command -v python3 >/dev/null 2>&1; then
    python3 - "$composer" "$folder" <<'PY'
import json, sys

path, folder = sys.argv[1], sys.argv[2]
try:
    data = json.load(open(path, encoding="utf-8"))
except (OSError, json.JSONDecodeError):
    data = {}
req = data.get("require") or {}
name = str(data.get("name") or "").strip()
slug = name.split("/", 1)[-1] if name else folder

# php-ext: folder / composer name (tyhpdef/php-ext-pdo → ext-pdo), not ext-zend-opcache.
if folder.startswith("php-ext-"):
    print(f"Tyhp tyhpdefs for ext-{folder[len('php-ext-'):]}")
    raise SystemExit(0)
if slug.startswith("php-ext-"):
    print(f"Tyhp tyhpdefs for ext-{slug[len('php-ext-'):]}")
    raise SystemExit(0)

candidates = [
    str(key)
    for key in req
    if "/" in str(key) and str(key) != "php" and not str(key).startswith("tyhp/") and not str(key).startswith("tyhpdef/")
]
want = folder.replace("-", "/", 1)
if want in candidates:
    print(f"Tyhp tyhpdefs for {want}")
    raise SystemExit(0)
if len(candidates) == 1:
    print(f"Tyhp tyhpdefs for {candidates[0]}")
    raise SystemExit(0)
if candidates:
    print(f"Tyhp tyhpdefs for {candidates[0]}")
    raise SystemExit(0)

if "-" in slug:
    print(f"Tyhp tyhpdefs for {slug.replace('-', '/', 1)}")
    raise SystemExit(0)
print(f"Tyhp tyhpdefs for {slug}")
PY
    return
  fi
  if [[ "$folder" == php-ext-* ]]; then
    printf '%s\n' "Tyhp tyhpdefs for ext-${folder#php-ext-}"
  else
    printf '%s\n' "Tyhp tyhpdefs for ${folder/-//}"
  fi
}

# 0 = exists, 1 = missing, 2 = unexpected gh error
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
  echo "error: failed to check GitHub repo ${GITHUB_ORG}/${repo}" >&2
  return 2
}

# Create an empty remote-only repo. Run from a non-git directory so gh does not
# offer to add a remote on the compiler checkout or a local package tree.
create_empty_github_repo() {
  local repo="$1"
  local desc="$2"
  (
    cd "${TMPDIR:-/tmp}"
    GH_PROMPT_DISABLED=1 gh repo create "${GITHUB_ORG}/${repo}" --public --description "$desc"
  )
}

ensure_github_repo() {
  local repo="$1"
  local desc="$2"
  local exists_rc=0

  if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "would: gh repo view ${GITHUB_ORG}/${repo} --json name"
    echo "would: gh repo create ${GITHUB_ORG}/${repo} --public --description $(printf '%q' "$desc")  (if missing)"
    return 0
  fi

  github_repo_exists "$repo" || exists_rc=$?
  if [[ "$exists_rc" -eq 0 ]]; then
    echo "GitHub: ${GITHUB_ORG}/${repo} already exists (skip create)"
    LAST_ACTION="existed"
    return 0
  fi
  if [[ "$exists_rc" -eq 2 ]]; then
    return 1
  fi

  echo "GitHub: creating ${GITHUB_ORG}/${repo}"
  if ! create_empty_github_repo "$repo" "$desc"; then
    echo "error: gh repo create failed for ${GITHUB_ORG}/${repo}" >&2
    return 1
  fi
  echo "GitHub: created empty ${GITHUB_ORG}/${repo} (no push; add a remote yourself if needed — publish-runtime-packages.sh will push later)"
  LAST_ACTION="created"
  return 0
}

discover_packages() {
  PACKAGES=()
  local dir
  local name
  local sorted

  for dir in "${PACKAGES_DIR}"/php-ext-* "${PACKAGES_DIR}"/psr-* "${PACKAGES_DIR}"/monolog-monolog; do
    if [[ ! -d "$dir" ]]; then
      continue
    fi
    name="$(basename "$dir")"
    if is_skipped_package_name "$name"; then
      continue
    fi
    if ! is_in_scope_package "$name"; then
      continue
    fi
    if ! find_package_composer "$dir" >/dev/null; then
      echo "skip (no composer.json): ${name}" >&2
      continue
    fi
    PACKAGES+=("$name")
  done

  if [[ ${#PACKAGES[@]} -eq 0 ]]; then
    return 0
  fi
  sorted="$(printf '%s\n' "${PACKAGES[@]}" | sort)"
  PACKAGES=()
  while IFS= read -r name; do
    if [[ -n "$name" ]]; then
      PACKAGES+=("$name")
    fi
  done <<< "$sorted"
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
    --fail-fast)
      FAIL_FAST=1
      shift
      ;;
    *)
      die "unknown argument: $1 (see --help)"
      ;;
  esac
done

if [[ "$DRY_RUN" -ne 1 ]]; then
  require_tool gh
fi

discover_packages

total=${#PACKAGES[@]}
if [[ "$total" -eq 0 ]]; then
  echo "No in-scope packages found in ${PACKAGES_DIR}."
  echo "Expected php-ext-*, psr-*, and/or monolog-monolog with composer.json."
  exit 0
fi

echo "GitHub org:    ${GITHUB_ORG}/<folder>"
echo "Packages:      ${total} (one repo each; version folders share a repo)"
echo "Local git:     not modified (no remote add, no push)"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Mode:          dry-run (no gh create)"
fi

CREATED=()
EXISTED=()
FAILED=()
LAST_ACTION=""

n=0
for name in "${PACKAGES[@]}"; do
  n=$((n + 1))
  dir="${PACKAGES_DIR}/${name}"
  composer="$(find_package_composer "$dir")"
  desc="$(repo_description "$name" "$composer")"
  versions="$(list_version_folders "$dir")"
  echo
  if [[ -n "$versions" ]]; then
    echo "==> [${n}/${total}] ${name}  →  ${GITHUB_ORG}/${name} and ${GITHUB_ORG}/${name}-impl  (local versions: ${versions})"
  else
    echo "==> [${n}/${total}] ${name}  →  ${GITHUB_ORG}/${name}"
  fi
  echo "    description: ${desc}"

  LAST_ACTION=""
  repos_for_name=("$name")
  if [[ -n "$versions" ]]; then
    repos_for_name+=("${name}-impl")
  fi
  repo_ok=0
  for repo_name in "${repos_for_name[@]}"; do
    if ensure_github_repo "$repo_name" "$desc"; then
      repo_ok=1
    else
      repo_ok=0
      break
    fi
  done
  if [[ "$repo_ok" -eq 1 ]]; then
    if [[ "$DRY_RUN" -eq 1 ]]; then
      continue
    fi
    if [[ "$LAST_ACTION" == "created" ]]; then
      CREATED+=("$name")
    else
      EXISTED+=("$name")
    fi
    continue
  fi
  FAILED+=("$name")
  echo "FAILED: ${GITHUB_ORG}/${name}" >&2
  if [[ "$FAIL_FAST" -eq 1 ]]; then
    echo "Aborting (--fail-fast)." >&2
    break
  fi
done

echo
echo "Summary"
echo "  considered: ${n}/${total}"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "  dry-run:    printed gh view/create for ${n} repo(s); nothing created"
else
  echo "  created:    ${#CREATED[@]}"
  echo "  existed:    ${#EXISTED[@]}"
  echo "  failed:     ${#FAILED[@]}"
  if [[ ${#CREATED[@]} -gt 0 ]]; then
    echo "  created names: ${CREATED[*]}"
  fi
  if [[ ${#EXISTED[@]} -gt 0 ]]; then
    echo "  existed names: ${EXISTED[*]}"
  fi
  if [[ ${#FAILED[@]} -gt 0 ]]; then
    echo "  failed names:  ${FAILED[*]}"
  fi
fi
echo "No git push. No local git remote add. New packages: new-proposed-composer-libs.sh."
echo "Later publish: scripts/publish-runtime-packages.sh (that script pushes)."

if [[ ${#FAILED[@]} -gt 0 ]]; then
  exit 1
fi
exit 0
