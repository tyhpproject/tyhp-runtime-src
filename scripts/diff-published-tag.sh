#!/usr/bin/env bash
# Diff one package's on-disk publish tree against an existing GitHub tag.
# The on-disk side is the tree scripts/publish-runtime-packages.sh would tag
# (path repositories stripped, impl composer.json rewritten, dist tree for
# compiled packages, generated public metapackage for Composer-libs).
# If a remote tag is missing, this exits before cloning or printing a diff.
set -euo pipefail

readonly DIFF_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=publish-runtime-packages.sh
source "${DIFF_SCRIPT_DIR}/publish-runtime-packages.sh"

TAG_OVERRIDE=""
SIDE=""
PACKAGE_ARG=""
DIFF_WORKDIR=""

usage() {
  cat <<'EOF'
Usage: scripts/diff-published-tag.sh [options] PACKAGE

Show what changed between the tree this repo would publish and the tag
already on GitHub. PACKAGE is the same name publish-runtime-packages.sh
--package accepts (php, php-ext-bz2, core, compiler, psr-log/3.0.2,
tyhpdef/php, tyhp/core).

Stops before cloning when the remote tag does not exist.

Compiled packages (core, decimal, async, lambda, parallel, io) compare
each 80N tag. Pass --tag to compare one of them. Composer-lib packages
compare the public tag and, unless the folder is handed off, the impl
tag. Pass --side public or --side impl to compare one of those.

Options:
  -h, --help           Show this help
  --tag TAG            Compare only this tag
  --side public|impl   Composer-lib only. Compare one published repo

Exit status:
  0  every compared tag matches the source tree
  1  at least one tag differs
  2  usage error, unknown package, or a remote tag does not exist

Examples:
  scripts/diff-published-tag.sh php
  scripts/diff-published-tag.sh --tag 805.0.2 core
  scripts/diff-published-tag.sh psr-log/3.0.2
  scripts/diff-published-tag.sh --side impl psr-log/3.0.2
EOF
}

cleanup_diff_workdir() {
  if [[ -n "$DIFF_WORKDIR" && -d "$DIFF_WORKDIR" ]]; then
    rm -rf "$DIFF_WORKDIR"
  fi
}

parse_diff_args() {
  TAG_OVERRIDE=""
  SIDE=""
  PACKAGE_ARG=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
      -h|--help)
        usage
        exit 0
        ;;
      --tag)
        [[ $# -ge 2 ]] || { echo "error: --tag requires a value." >&2; exit 2; }
        TAG_OVERRIDE="$2"
        shift 2
        ;;
      --tag=*)
        TAG_OVERRIDE="${1#*=}"
        [[ -n "$TAG_OVERRIDE" ]] || { echo "error: --tag requires a value." >&2; exit 2; }
        shift
        ;;
      --side)
        [[ $# -ge 2 ]] || { echo "error: --side requires public or impl." >&2; exit 2; }
        SIDE="$2"
        shift 2
        ;;
      --side=*)
        SIDE="${1#*=}"
        shift
        ;;
      --)
        shift
        break
        ;;
      -*)
        echo "error: unknown option $1" >&2
        usage >&2
        exit 2
        ;;
      *)
        if [[ -n "$PACKAGE_ARG" ]]; then
          echo "error: only one package may be given." >&2
          exit 2
        fi
        PACKAGE_ARG="$1"
        shift
        ;;
    esac
  done
  if [[ $# -gt 0 ]]; then
    if [[ -n "$PACKAGE_ARG" || $# -gt 1 ]]; then
      echo "error: only one package may be given." >&2
      exit 2
    fi
    PACKAGE_ARG="$1"
  fi
  if [[ -z "$PACKAGE_ARG" ]]; then
    echo "error: PACKAGE is required." >&2
    usage >&2
    exit 2
  fi
  if [[ -n "$SIDE" && "$SIDE" != "public" && "$SIDE" != "impl" ]]; then
    echo "error: --side must be public or impl." >&2
    exit 2
  fi
}

# Print "repo<TAB>tag<TAB>kind" lines for the tags this package publishes.
expected_tag_rows() {
  local pkg="$1"
  local repo upstream impl_tag php_major dist_version
  repo="$(github_repo_for_package "$pkg")"

  if is_compiled_package "$pkg"; then
    if [[ -n "$SIDE" ]]; then
      echo "error: --side is only for Composer-lib packages." >&2
      exit 2
    fi
    load_package_release_version "$pkg" || exit 2
    if [[ -n "$TAG_OVERRIDE" ]]; then
      printf '%s\t%s\tcompiled\n' "$repo" "$TAG_OVERRIDE"
      return 0
    fi
    for entry in "${DIST_BUILDS[@]}"; do
      php_major="${entry%%:*}"
      dist_version="$(package_version "$php_major")"
      printf '%s\t%s\tcompiled\n' "$repo" "$dist_version"
    done
    return 0
  fi

  if is_versioned_composer_lib "$pkg"; then
    upstream="${pkg#*/}"
    impl_tag="$(read_tyhp_package_version "$(package_dir "$pkg")/composer.json")" || exit 2
    if [[ -n "$TAG_OVERRIDE" ]]; then
      if [[ "$TAG_OVERRIDE" == "$upstream" && ( -z "$SIDE" || "$SIDE" == "public" ) ]]; then
        printf '%s\t%s\tpublic\n' "$repo" "$upstream"
      fi
      if [[ "$TAG_OVERRIDE" == "$impl_tag" && ( -z "$SIDE" || "$SIDE" == "impl" ) ]]; then
        if ownership_is_handed_off "$(package_dir "$pkg")/composer.json"; then
          echo "error: ${pkg} is handed-off; impl tag ${impl_tag} is not published." >&2
          exit 2
        fi
        printf '%s\t%s\timpl\n' "${repo}-impl" "$impl_tag"
      fi
      if [[ "$TAG_OVERRIDE" != "$upstream" && "$TAG_OVERRIDE" != "$impl_tag" ]]; then
        echo "error: --tag ${TAG_OVERRIDE} is neither the public tag ${upstream} nor the impl tag ${impl_tag}." >&2
        exit 2
      fi
      if [[ "$TAG_OVERRIDE" == "$upstream" && "$SIDE" == "impl" ]]; then
        echo "error: --tag ${TAG_OVERRIDE} is the public tag; --side impl does not apply." >&2
        exit 2
      fi
      if [[ "$TAG_OVERRIDE" == "$impl_tag" && "$SIDE" == "public" && "$TAG_OVERRIDE" != "$upstream" ]]; then
        echo "error: --tag ${TAG_OVERRIDE} is the impl tag; --side public does not apply." >&2
        exit 2
      fi
      return 0
    fi
    if [[ -z "$SIDE" || "$SIDE" == "public" ]]; then
      printf '%s\t%s\tpublic\n' "$repo" "$upstream"
    fi
    if [[ -z "$SIDE" || "$SIDE" == "impl" ]]; then
      if ownership_is_handed_off "$(package_dir "$pkg")/composer.json"; then
        if [[ "$SIDE" == "impl" ]]; then
          echo "error: ${pkg} is handed-off; impl tag ${impl_tag} is not published." >&2
          exit 2
        fi
      else
        printf '%s\t%s\timpl\n' "${repo}-impl" "$impl_tag"
      fi
    fi
    return 0
  fi

  if [[ -n "$SIDE" ]]; then
    echo "error: --side is only for Composer-lib packages." >&2
    exit 2
  fi
  local tag
  tag="${TAG_OVERRIDE:-$(read_tyhp_package_version "$(package_dir "$pkg")/composer.json")}" || exit 2
  if is_compiler_package "$pkg"; then
    printf '%s\t%s\tcompiler\n' "$repo" "$tag"
  else
    printf '%s\t%s\ttyhpdef\n' "$repo" "$tag"
  fi
}

remote_has_tag() {
  local repo="$1"
  local tag="$2"
  local url="https://github.com/${GITHUB_ORG}/${repo}.git"
  local refs
  local code
  set +e
  refs="$(git ls-remote --tags "$url" "refs/tags/${tag}" "refs/tags/${tag}^{}" 2>&1)"
  code=$?
  set -e
  if [[ "$code" -ne 0 ]]; then
    echo "error: could not read tags from ${url}" >&2
    printf '%s\n' "$refs" >&2
    exit 2
  fi
  [[ -n "$refs" ]]
}

ensure_remote_tags() {
  local row repo tag missing=()
  while IFS=$'\t' read -r repo tag _kind; do
    [[ -n "$repo" ]] || continue
    if ! remote_has_tag "$repo" "$tag"; then
      missing+=("${GITHUB_ORG}/${repo} tag ${tag}")
    fi
  done
  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "error: remote tag does not exist; not diffing." >&2
    local item
    for item in "${missing[@]}"; do
      echo "  ${item}" >&2
    done
    exit 2
  fi
}

prepare_tag_repo() {
  local repo="$1"
  local dest="$2"
  if [[ -d "${dest}/.git" ]]; then
    return 0
  fi
  mkdir -p "$dest"
  git -C "$dest" init -q
  git -C "$dest" remote add origin "https://github.com/${GITHUB_ORG}/${repo}.git"
}

fetch_tag() {
  local dest="$1"
  local tag="$2"
  git -C "$dest" fetch --depth 1 origin "refs/tags/${tag}:refs/tags/${tag}"
}

materialize_source_tree() {
  local pkg="$1"
  local dest="$2"
  local tag="$3"
  local kind="$4"
  local meta_dir
  case "$kind" in
    compiled)
      sync_compile_dist "$pkg" "$dest" "$tag"
      ;;
    compiler)
      sync_compiler_dist "$pkg" "$dest"
      ;;
    tyhpdef)
      sync_composer_lib_dist "$pkg" "$dest"
      ;;
    impl)
      sync_composer_lib_dist "$pkg" "$dest"
      write_published_impl_composer "$(package_dir "$pkg")/composer.json" "${dest}/composer.json"
      ;;
    public)
      meta_dir="${dest}-meta"
      rm -rf "$meta_dir"
      mkdir -p "$meta_dir"
      "$PROJECT_ROOT/generate-meta-package.sh" --out "$meta_dir" "$pkg" >/dev/null
      wipe_published_tree "$dest"
      cp "${meta_dir}/composer.json" "${dest}/composer.json"
      if [[ -f "${meta_dir}/README.md" ]]; then
        cp "${meta_dir}/README.md" "${dest}/README.md"
      fi
      if [[ -f "${meta_dir}/LICENSE" ]]; then
        cp "${meta_dir}/LICENSE" "${dest}/LICENSE"
      fi
      ;;
    *)
      echo "error: unknown publish kind ${kind}" >&2
      exit 2
      ;;
  esac
}

# Print the diff of the would-be publish tree against the tag. Returns 1
# when they differ.
show_tag_diff() {
  local dest="$1"
  local tag="$2"
  local repo="$3"
  normalize_generated_timestamps "$dest" "$tag"
  git -C "$dest" add -A
  if git -C "$dest" diff --cached --quiet "$tag"; then
    echo "  ${GITHUB_ORG}/${repo} tag ${tag}: no difference"
    return 0
  fi
  say_red "CHANGE WITHOUT VERSION BUMP: ${GITHUB_ORG}/${repo} tag ${tag} differs from the source tree"
  echo "--- tag ${tag}  →  source tree ---"
  git --no-pager -C "$dest" diff --cached "$tag"
  return 1
}

main_diff() {
  parse_diff_args "$@"
  require_tool python3
  require_tool git
  require_tool rsync

  cd "$PROJECT_ROOT"
  discover_packages
  filter_to_requested_package "$PACKAGE_ARG"
  local pkg="${ALL_PACKAGES[0]}"

  local rows
  rows="$(expected_tag_rows "$pkg")"
  if [[ -z "$rows" ]]; then
    echo "error: no tag to compare for ${pkg}." >&2
    exit 2
  fi

  echo "Checking remote tags for ${pkg}..."
  printf '%s\n' "$rows" | ensure_remote_tags

  DIFF_WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/tyhp-pkg-diff.XXXXXX")"
  trap cleanup_diff_workdir EXIT

  local differed=0
  local repo tag kind dest
  while IFS=$'\t' read -r repo tag kind; do
    [[ -n "$repo" ]] || continue
    dest="${DIFF_WORKDIR}/${repo}"
    prepare_tag_repo "$repo" "$dest"
    fetch_tag "$dest" "$tag"
    echo "==> ${pkg} vs ${GITHUB_ORG}/${repo} tag ${tag}"
    materialize_source_tree "$pkg" "$dest" "$tag" "$kind"
    if ! show_tag_diff "$dest" "$tag" "$repo"; then
      differed=1
    fi
  done <<< "$rows"

  if [[ "$differed" -ne 0 ]]; then
    return 1
  fi
}

main_diff "$@"
