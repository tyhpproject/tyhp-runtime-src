#!/usr/bin/env bash
# Batch-scaffold tyhpdef/<vendor>-<name> Composer-lib packages from
# dev-docs/PROPOSED_TYHPDEF_PACKAGES.md by calling new-composer-lib.sh for each
# row, then widening that package's upstream composer.json require to the
# proposed constraint range, requiring sibling tyhpdef/* wrappers for known
# dependencies, then ensuring an empty public GitHub repo exists.
#
# Per-upstream-version trees and Tyhp four-part versions stay exactly as
# new-composer-lib.sh creates them. Latest stable (Packagist, matching the
# proposed caret) is the --version pin / folder / generate_tyhpdef snapshot.
# The composer.json *dependency* on the upstream library is then set to the
# range from the markdown (e.g. ^11.0), not that single patch.
#
# tyhpdef/* sibling requires: Packagist production `require` of that resolved
# version, union markdown public-API prerequisites, intersected with wrappers
# we already have (existing composer-lib folders) or will have (every ranked
# proposed-list package). php / ext-* / composer-plugin-api /
# composer-runtime-api are skipped. Unlisted Packagist deps are not wrapped.
# Constraint is the dependency's markdown range (tyhp four-part version line,
# e.g. ^3.0 → 3.0.2.0), not the upstream package's own caret. --only / --limit
# do not shrink that known-wrapper set.
#
# GitHub: tyhpproject-packages/<vendor>-<name> and <vendor>-<name>-impl (same as
# scripts/publish-runtime-packages.sh). Empty public repos,
# no README / license / .gitignore, no git push, no Packagist submit.
# Inner script is invoked, not sourced.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" && pwd)"
INNER_SCRIPT="$SCRIPT_DIR/new-composer-lib.sh"
DEFAULT_LIST="$REPO_ROOT/dev-docs/PROPOSED_TYHPDEF_PACKAGES.md"
GITHUB_ORG="tyhpproject-packages"
USER_AGENT="tyhp-new-proposed-composer-libs (+https://github.com/tyhpproject/tyhp-runtime-src)"

DRY_RUN=0
FAIL_FAST=0
SKIP_EXISTING=0
SKIP_GITHUB=0
INCLUDE_ALREADY_HAVE=0
LIMIT=0
FORCE=0
INCLUDE_DEV=0
REVISION=""
LIST_PATH="$DEFAULT_LIST"
ONLY_PACKAGES=()
INNER_EXTRA=()

usage() {
  cat <<'EOF'
Usage: new-proposed-composer-libs.sh [options]

Read the ranked tables in dev-docs/PROPOSED_TYHPDEF_PACKAGES.md and, for each
candidate, resolve the latest stable Packagist version that satisfies the
suggested constraint, then:

  1. Call new-composer-lib.sh <vendor/package> --version <latest-stable>
     (version folder, Tyhp four-part version, generate_tyhpdef, overlays —
     identical to a manual run of that script)
  2. Patch that version directory's composer.json so require on the upstream
     library is the markdown range (^11.0, ^7.4, …), not the single patch
  3. Also require tyhpdef/<vendor>-<name> for each upstream production dependency
     that already has or will have a tyhp wrapper (proposed list ∪ existing
     composer-libs), plus markdown public-API prerequisites in that set.
     Do not invent wrappers for other Packagist deps. php / ext-* /
     composer-plugin-api / composer-runtime-api are skipped.
  4. If tyhpproject-packages/<vendor>-<name> or tyhpproject-packages/<vendor>-<name>-impl
     is missing, gh repo create --public (empty repo: no README, license, or
     .gitignore). Do not git push. Do not submit to Packagist.

Skipped by default
  psr/log and monolog/monolog (the markdown "Already have" table)
  The "Packages looked up but not version-verified" section (404 / wrong name)

GitHub repo names are <vendor>-<name> and <vendor>-<name>-impl (slash → dash),
e.g. tyhpproject-packages/psr-container, matching publish-runtime-packages.sh.
Not tyhp-psr-log.

Options:
  -h, --help                 Show this help
  -n, --dry-run              Print inner + gh commands; do not write or create
  --fail-fast                Abort the batch on the first package failure
  --limit N                  Process only the first N candidates after filters
  --only vendor/package      Only this Packagist name (repeatable). Explicit
                             --only includes an already-have package.
  --skip-existing            Skip local scaffold if packages/<vendor>-<name>
                             already exists (GitHub ensure still runs unless
                             --skip-github)
  --skip-github              Do not check or create GitHub repos
  --include-already-have     Also process the markdown already-have packages
  --list <path>              Proposed-package markdown (default: repo
                             dev-docs/PROPOSED_TYHPDEF_PACKAGES.md)

Pass-through to new-composer-lib.sh (applied to every scaffold):
  -f, --force                Replace an existing <upstream> version directory
  --include-dev              Pass --include-dev to generate_tyhpdef
  --revision <n>             Tyhp revision digit (default: inner script 0)
  --                         Remaining args are passed to new-composer-lib.sh

Examples:
  ./new-proposed-composer-libs.sh --dry-run --limit 5
  ./new-proposed-composer-libs.sh --only psr/container --skip-github
  ./new-proposed-composer-libs.sh --skip-existing --limit 20
  ./new-proposed-composer-libs.sh --only guzzlehttp/guzzle -- --include-dev
EOF
}

die() {
  echo "error: $*" >&2
  exit 1
}

to_lower() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

dir_name_for() {
  local pkg="$1"
  local vendor="${pkg%%/*}"
  local proj="${pkg#*/}"
  printf '%s' "${vendor}-${proj}"
}

only_requested() {
  local needle
  local candidate
  local want
  needle="$(to_lower "$1")"
  if [[ ${#ONLY_PACKAGES[@]} -eq 0 ]]; then
    return 0
  fi
  for candidate in "${ONLY_PACKAGES[@]}"; do
    want="$(to_lower "$candidate")"
    if [[ "$want" == "$needle" ]]; then
      return 0
    fi
  done
  return 1
}

# Ranked-table rows from the markdown. Skips the unverified (404) section.
# Prints TSV: rank, vendor/package, constraint, already (0|1), prereqs (comma-separated)
parse_proposed_list() {
  local path="$1"
  python3 - "$path" <<'PY'
import re
import sys

path = sys.argv[1]
try:
    text = open(path, encoding="utf-8").read()
except OSError as exc:
    print(f"error: cannot read proposed list: {exc}", file=sys.stderr)
    sys.exit(1)

pkg_re = r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"
pkg_finder = re.compile(pkg_re)

unverified = set()
m = re.search(r"^## Packages looked up but not version-verified\s*$", text, re.M)
if m:
    rest = text[m.end() :]
    nxt = re.search(r"^## ", rest, re.M)
    section = rest if nxt is None else rest[: nxt.start()]
    for row in re.finditer(r"^\|\s*`(" + pkg_re + r")`\s*\|", section, re.M):
        unverified.add(row.group(1).lower())
    text = text[: m.start()]

already = set()
m = re.search(r"^## Already have\s*$", text, re.M)
if m:
    rest = text[m.end() :]
    nxt = re.search(r"^## ", rest, re.M)
    section = rest if nxt is None else rest[: nxt.start()]
    for row in re.finditer(r"^\|\s*`(" + pkg_re + r")`\s*\|", section, re.M):
        already.add(row.group(1).lower())

row_re = re.compile(
    r"^\|\s*(\d+)\s*\|\s*`(" + pkg_re + r")`\s*\|\s*`([^`]+)`\s*\|",
    re.M,
)
seen = set()
for match in row_re.finditer(text):
    rank, pkg, constraint = match.group(1), match.group(2), match.group(3).strip()
    key = pkg.lower()
    if key in unverified or key in seen:
        continue
    seen.add(key)
    line_start = text.rfind("\n", 0, match.start()) + 1
    line_end = text.find("\n", match.end())
    if line_end < 0:
        line_end = len(text)
    line = text[line_start:line_end]
    marks_already = "already have" in line.lower()
    flag = "1" if (key in already or marks_already) else "0"
    cols = [c.strip() for c in line.split("|")]
    # | rank | package | constraint | patch | why | prereqs | marks | notes |
    prereq_cell = cols[6] if len(cols) > 6 else ""
    prereqs = []
    if prereq_cell.lower() not in ("", "none", "—", "-", "n/a"):
        for found in pkg_finder.finditer(prereq_cell):
            prereqs.append(found.group(0))
    print(f"{rank}\t{pkg}\t{constraint}\t{flag}\t{','.join(prereqs)}")
PY
}

# Latest stable Packagist version that satisfies a Composer caret/range.
# Writes the version (no leading v, no +build) to stdout.
resolve_latest_stable() {
  local pkg="$1"
  local constraint="$2"
  local tmp="$3"
  local url="https://packagist.org/packages/${pkg}.json"
  local code

  code="$(curl -sS -o "$tmp" -w '%{http_code}' -A "$USER_AGENT" --max-time 90 "$url")" || {
    echo "error: curl failed for Packagist ${pkg}" >&2
    return 1
  }
  if [[ "$code" == "404" ]]; then
    echo "error: Packagist 404 for ${pkg}" >&2
    return 1
  fi
  if [[ "$code" != "200" ]]; then
    echo "error: Packagist HTTP ${code} for ${pkg}" >&2
    return 1
  fi

  python3 - "$tmp" "$pkg" "$constraint" <<'PY'
import json
import re
import sys

path, pkg, constraint = sys.argv[1], sys.argv[2], sys.argv[3]

try:
    doc = json.load(open(path, encoding="utf-8"))
except (OSError, json.JSONDecodeError) as exc:
    print(f"error: invalid Packagist JSON for {pkg}: {exc}", file=sys.stderr)
    sys.exit(1)

versions = (doc.get("package") or {}).get("versions") or {}
if not versions:
    print(f"error: Packagist returned no versions for {pkg}", file=sys.stderr)
    sys.exit(1)


def strip_v(raw: str) -> str:
    raw = raw.strip()
    if re.match(r"v[0-9]", raw, re.I):
        return raw[1:]
    return raw


def is_stable(ver: str) -> bool:
    v = strip_v(ver)
    if not v:
        return False
    low = v.lower()
    if low.startswith("dev-") or low.endswith("-dev"):
        return False
    core = v.split("+", 1)[0]
    if "-" in core:
        return False
    return bool(re.fullmatch(r"[0-9]+(?:\.[0-9]+)*", core))


def core_parts(ver: str):
    core = strip_v(ver).split("+", 1)[0].split("-", 1)[0]
    return tuple(int(p) for p in core.split(".") if p != "")


def pad(a, b):
    n = max(len(a), len(b), 3)
    return a + (0,) * (n - len(a)), b + (0,) * (n - len(b))


def caret_bounds(spec: str):
    parts = core_parts(spec)
    if not parts:
        raise ValueError(f"empty version in constraint {spec!r}")
    padded = parts + (0,) * (3 - len(parts))
    if padded[0] != 0:
        upper = (padded[0] + 1,) + (0,) * (len(padded) - 1)
    elif padded[1] != 0:
        upper = (0, padded[1] + 1) + (0,) * (len(padded) - 2)
    else:
        upper = (0, 0, padded[2] + 1) + (0,) * (max(0, len(padded) - 3))
    return parts, upper


def cmp_tuple(a, b) -> int:
    x, y = pad(a, b)
    return (x > y) - (x < y)


def satisfies(ver: str, expr: str) -> bool:
    expr = expr.strip()
    if not expr:
        return False
    clauses = [c.strip() for c in expr.split("||")]
    return any(satisfies_clause(ver, c) for c in clauses)


def satisfies_clause(ver: str, expr: str) -> bool:
    expr = expr.strip()
    if expr.startswith("^"):
        spec = expr[1:].strip()
        lower, upper = caret_bounds(spec)
        v = core_parts(ver)
        return cmp_tuple(v, lower) >= 0 and cmp_tuple(v, upper) < 0
    if expr.startswith("~"):
        spec = expr[1:].strip()
        parts = core_parts(spec)
        if len(parts) == 1:
            lower = parts
            upper = (parts[0] + 1,)
        else:
            lower = parts
            upper = (parts[0], parts[1] + 1)
        v = core_parts(ver)
        return cmp_tuple(v, lower) >= 0 and cmp_tuple(v, upper) < 0
    m = re.fullmatch(r"(>=|>|<=|<|!=)?\s*(.+)", expr)
    if not m:
        return False
    op, spec = m.group(1) or "=", m.group(2).strip()
    v = core_parts(ver)
    s = core_parts(spec)
    c = cmp_tuple(v, s)
    if op == "=":
        return c == 0
    if op == ">=":
        return c >= 0
    if op == ">":
        return c > 0
    if op == "<=":
        return c <= 0
    if op == "<":
        return c < 0
    if op == "!=":
        return c != 0
    return False


candidates = []
for key, meta in versions.items():
    if not isinstance(meta, dict):
        continue
    ver = str(meta.get("version") or key).strip()
    if not is_stable(ver):
        continue
    pin = strip_v(ver).split("+", 1)[0]
    if not satisfies(pin, constraint):
        continue
    candidates.append(core_parts(pin) + (pin,))

if not candidates:
    print(
        f"error: no stable Packagist version of {pkg} satisfies {constraint!r}",
        file=sys.stderr,
    )
    sys.exit(1)

candidates.sort()
print(candidates[-1][-1])
PY
}

patch_upstream_require() {
  local composer_json="$1"
  local upstream="$2"
  local constraint="$3"
  python3 - "$composer_json" "$upstream" "$constraint" <<'PY'
import json
import sys

path, upstream, constraint = sys.argv[1], sys.argv[2], sys.argv[3]
try:
    data = json.load(open(path, encoding="utf-8"))
except (OSError, json.JSONDecodeError) as exc:
    print(f"error: cannot read {path}: {exc}", file=sys.stderr)
    sys.exit(1)

req = data.setdefault("require", {})
if upstream not in req:
    print(
        f"error: {path} require is missing {upstream} (keys: {', '.join(req)})",
        file=sys.stderr,
    )
    sys.exit(1)
req[upstream] = constraint
with open(path, "w", encoding="utf-8") as fh:
    json.dump(data, fh, indent=4, ensure_ascii=True)
    fh.write("\n")
PY
}

# Known wrappers: ranked proposed-list packages (will-have, including already-have
# rows and packages this run will not create because of --only / --limit) plus
# existing versioned composer-lib folders. Writes JSON catalog.
build_wrapper_catalog() {
  local parsed_tsv="$1"
  local packages_dir="$2"
  local out_json="$3"
  python3 - "$parsed_tsv" "$packages_dir" "$out_json" <<'PY'
import json
import os
import re
import sys

parsed_tsv, packages_dir, out_json = sys.argv[1], sys.argv[2], sys.argv[3]
SKIP_PARENTS = {"dist", "vendor"}


def tyhp_name(packagist: str) -> str:
    vendor, _, proj = packagist.partition("/")
    return f"tyhpdef/{vendor}-{proj}"


def core_parts(ver: str):
    core = ver.strip()
    if re.match(r"v[0-9]", core, re.I):
        core = core[1:]
    core = core.split("+", 1)[0].split("-", 1)[0]
    return tuple(int(p) for p in core.split(".") if p != "")


def caret_from_tyhp_version(ver: str) -> str:
    parts = core_parts(ver)
    if not parts:
        return "*"
    if parts[0] != 0:
        return f"^{parts[0]}.0"
    if len(parts) >= 2:
        return f"^0.{parts[1]}"
    return "^0.0"


def is_skipped_dep(name: str) -> bool:
    low = name.lower().strip()
    if not low or low in {
        "php",
        "hhvm",
        "composer-plugin-api",
        "composer-runtime-api",
        "composer-replace",
    }:
        return True
    if low.startswith("ext-") or low.startswith("lib-"):
        return True
    if low.startswith("tyhp/") or low.startswith("tyhpdef/"):
        return True
    if "/" not in name or name.count("/") != 1:
        return True
    return False


def upstream_from_require(req: dict):
    if not isinstance(req, dict):
        return None
    for key in req:
        if not is_skipped_dep(str(key)):
            return str(key)
    return None


catalog = {}

try:
    with open(parsed_tsv, encoding="utf-8") as fh:
        for raw in fh:
            line = raw.rstrip("\n")
            if not line.strip():
                continue
            parts = line.split("\t")
            if len(parts) < 3:
                continue
            pkg = parts[1].strip()
            constraint = parts[2].strip()
            prereq_s = parts[4].strip() if len(parts) > 4 else ""
            prereqs = [p.strip() for p in prereq_s.split(",") if p.strip()]
            key = pkg.lower()
            catalog[key] = {
                "packagist": pkg,
                "tyhp": tyhp_name(pkg),
                "constraint": constraint,
                "prereqs": prereqs,
                "local": None,
            }
except OSError as exc:
    print(f"error: cannot read parsed list: {exc}", file=sys.stderr)
    sys.exit(1)

# Existing versioned composer-libs: packages/<vendor>-<name>/<upstream>/
local_best = {}
if os.path.isdir(packages_dir):
    for parent in sorted(os.listdir(packages_dir)):
        if parent in SKIP_PARENTS:
            continue
        parent_dir = os.path.join(packages_dir, parent)
        if not os.path.isdir(parent_dir):
            continue
        for ver in os.listdir(parent_dir):
            if ver in SKIP_PARENTS:
                continue
            composer_path = os.path.join(parent_dir, ver, "composer.json")
            if not os.path.isfile(composer_path):
                continue
            try:
                data = json.load(open(composer_path, encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            name = str(data.get("name") or "")
            if not name.startswith("tyhpdef/"):
                continue
            upstream = upstream_from_require(data.get("require") or {})
            if not upstream:
                continue
            key = upstream.lower()
            tyhp_ver = str(data.get("version") or ver)
            sort_key = core_parts(ver)
            prev = local_best.get(key)
            if prev is None or sort_key > prev[0]:
                local_best[key] = (sort_key, f"{parent}/{ver}", tyhp_ver, name, upstream)

for key, (_sort, rel, tyhp_ver, tyhp_pkg, upstream) in local_best.items():
    if key in catalog:
        catalog[key]["local"] = rel
        continue
    catalog[key] = {
        "packagist": upstream,
        "tyhp": tyhp_pkg,
        "constraint": caret_from_tyhp_version(tyhp_ver),
        "prereqs": [],
        "local": rel,
    }

with open(out_json, "w", encoding="utf-8") as fh:
    json.dump(catalog, fh, indent=2, ensure_ascii=True)
    fh.write("\n")
PY
}

# Union Packagist production require ∩ known wrappers with markdown public-API
# prereqs that are in that set. Adds tyhpdef/<vendor>-<name> requires using the
# wrapper's markdown (or locally derived) constraint. Adds a Composer path
# repository when the sibling already exists on disk (same pattern as
# monolog-monolog → psr-log). apply=0 prints the mapping without writing.
patch_tyhp_requires() {
  local composer_json="$1"
  local packagist_json="$2"
  local latest="$3"
  local upstream="$4"
  local catalog_json="$5"
  local apply="$6"
  python3 - "$composer_json" "$packagist_json" "$latest" "$upstream" "$catalog_json" "$apply" <<'PY'
import json
import re
import sys

composer_path, packagist_path, latest, upstream, catalog_path, apply = sys.argv[1:7]
apply = apply == "1"


def strip_v(raw: str) -> str:
    raw = raw.strip()
    if re.match(r"v[0-9]", raw, re.I):
        return raw[1:]
    return raw


def is_skipped_dep(name: str) -> bool:
    low = name.lower().strip()
    if not low or low in {
        "php",
        "hhvm",
        "composer-plugin-api",
        "composer-runtime-api",
        "composer-replace",
    }:
        return True
    if low.startswith("ext-") or low.startswith("lib-"):
        return True
    if low.startswith("tyhp/") or low.startswith("tyhpdef/"):
        return True
    if "/" not in name or name.count("/") != 1:
        return True
    return False


try:
    catalog = json.load(open(catalog_path, encoding="utf-8"))
except (OSError, json.JSONDecodeError) as exc:
    print(f"error: cannot read wrapper catalog: {exc}", file=sys.stderr)
    sys.exit(1)

try:
    packagist = json.load(open(packagist_path, encoding="utf-8"))
except (OSError, json.JSONDecodeError) as exc:
    print(f"error: cannot read Packagist JSON: {exc}", file=sys.stderr)
    sys.exit(1)

pin = strip_v(latest).split("+", 1)[0]
packagist_require = {}
versions = (packagist.get("package") or {}).get("versions") or {}
for key, meta in versions.items():
    if not isinstance(meta, dict):
        continue
    ver = str(meta.get("version") or key).strip()
    if strip_v(ver).split("+", 1)[0] != pin:
        continue
    req = meta.get("require") or {}
    if isinstance(req, dict):
        packagist_require = req
        break

self_key = upstream.lower()
entry = catalog.get(self_key) or {}
prereqs = entry.get("prereqs") or []

wanted = []
seen = set()


def add_wanted(packagist_name: str) -> None:
    key = packagist_name.lower().strip()
    if not key or key in seen or key == self_key or is_skipped_dep(packagist_name):
        return
    info = catalog.get(key)
    if not info:
        return
    seen.add(key)
    wanted.append(info)


for dep_name in packagist_require:
    add_wanted(str(dep_name))
for dep_name in prereqs:
    add_wanted(str(dep_name))

wanted.sort(key=lambda info: str(info.get("tyhp") or ""))

if apply:
    try:
        data = json.load(open(composer_path, encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"error: cannot read {composer_path}: {exc}", file=sys.stderr)
        sys.exit(1)
    req = data.setdefault("require", {})
    repos = data.setdefault("repositories", [])
    if not isinstance(repos, list):
        repos = []
        data["repositories"] = repos
    existing_urls = {
        str(item.get("url"))
        for item in repos
        if isinstance(item, dict)
    }
    for info in wanted:
        tyhp_pkg = info.get("tyhp")
        constraint = info.get("constraint")
        if not tyhp_pkg or not constraint:
            continue
        req[tyhp_pkg] = constraint
        local = info.get("local")
        if local:
            url = f"../../{local}"
            if url not in existing_urls:
                repos.append({"type": "path", "url": url})
                existing_urls.add(url)
    with open(composer_path, "w", encoding="utf-8") as fh:
        json.dump(data, fh, indent=4, ensure_ascii=True)
        fh.write("\n")

if not wanted:
    print("(none)")
else:
    for info in wanted:
        local = info.get("local") or ""
        print(f"{info.get('tyhp')}\t{info.get('constraint')}\t{local}")
PY
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

ensure_one_repo() {
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
    return 0
  fi
  if [[ "$exists_rc" -eq 2 ]]; then
    return 1
  fi
  echo "GitHub: creating ${GITHUB_ORG}/${repo}"
  (
    cd "${TMPDIR:-/tmp}"
    GH_PROMPT_DISABLED=1 gh repo create "${GITHUB_ORG}/${repo}" --public --description "$desc"
  ) || return 1
}

ensure_github_repo() {
  local pkg="$1"
  local repo
  local desc
  repo="$(dir_name_for "$pkg")"
  desc="Tyhp tyhpdefs for ${pkg}"
  ensure_one_repo "$repo" "$desc" || return 1
  ensure_one_repo "${repo}-impl" "Implementation package for tyhpdef/${repo}. Require tyhpdef/${repo}, not this package." || return 1
}

print_cmd() {
  local prefix="$1"
  shift
  printf '%s' "$prefix"
  local arg
  for arg in "$@"; do
    printf ' %q' "$arg"
  done
  printf '\n'
}

describe_tyhp_requires() {
  local tyhp_pkg
  local tyhp_constraint
  local local_rel
  local first=1
  while IFS=$'\t' read -r tyhp_pkg tyhp_constraint local_rel; do
    [[ -n "${tyhp_pkg:-}" ]] || continue
    if [[ "$tyhp_pkg" == "(none)" ]]; then
      printf '(none)\n'
      return 0
    fi
    if [[ "$first" -eq 1 ]]; then
      first=0
    else
      printf ', '
    fi
    printf '%s %s' "$tyhp_pkg" "$tyhp_constraint"
    if [[ -n "${local_rel:-}" ]]; then
      printf ' (path %s)' "$local_rel"
    fi
  done <<< "$1"
  if [[ "$first" -eq 1 ]]; then
    printf '(none)'
  fi
  printf '\n'
}

process_one() {
  local pkg="$1"
  local constraint="$2"
  local dir_name
  local parent
  local latest
  local ver_dir
  local composer_json
  local cmd
  local skipped_local=0
  local tyhp_map

  dir_name="$(dir_name_for "$pkg")"
  parent="$SCRIPT_DIR/$dir_name"

  if [[ "$SKIP_EXISTING" -eq 1 && -e "$parent" ]]; then
    echo "skip existing local package: $parent"
    skipped_local=1
  else
    if ! latest="$(resolve_latest_stable "$pkg" "$constraint" "$PACKAGIST_TMP")"; then
      return 1
    fi
    echo "Latest stable for ${constraint}: ${latest}"

    ver_dir="$parent/$latest"
    if [[ -e "$ver_dir" && "$FORCE" -ne 1 ]]; then
      echo "error: version directory already exists: $ver_dir" >&2
      echo "Re-run with --force to replace this upstream version, or --skip-existing to skip the package." >&2
      return 1
    fi

    cmd=("$INNER_SCRIPT")
    if [[ "$FORCE" -eq 1 ]]; then
      cmd+=(-f)
    fi
    if [[ "$INCLUDE_DEV" -eq 1 ]]; then
      cmd+=(--include-dev)
    fi
    if [[ -n "$REVISION" ]]; then
      cmd+=(--revision "$REVISION")
    fi
    if [[ ${#INNER_EXTRA[@]} -gt 0 ]]; then
      cmd+=("${INNER_EXTRA[@]}")
    fi
    cmd+=(--version "$latest" "$pkg")

    if [[ "$DRY_RUN" -eq 1 ]]; then
      print_cmd "would:" bash "${cmd[@]}"
      echo "would: patch ${ver_dir}/composer.json require ${pkg} -> ${constraint}"
      tyhp_map="$(patch_tyhp_requires "" "$PACKAGIST_TMP" "$latest" "$pkg" "$WRAPPER_CATALOG_JSON" 0)" || return 1
      printf 'would: patch %s tyhp requires: ' "$ver_dir/composer.json"
      describe_tyhp_requires "$tyhp_map"
    else
      bash "${cmd[@]}" || return 1
      composer_json="$ver_dir/composer.json"
      if [[ ! -f "$composer_json" ]]; then
        echo "error: missing ${composer_json} after new-composer-lib.sh" >&2
        return 1
      fi
      patch_upstream_require "$composer_json" "$pkg" "$constraint" || return 1
      echo "Patched require ${pkg}: ${constraint} (snapshot folder ${latest})"
      tyhp_map="$(patch_tyhp_requires "$composer_json" "$PACKAGIST_TMP" "$latest" "$pkg" "$WRAPPER_CATALOG_JSON" 1)" || return 1
      printf 'Patched tyhp requires: '
      describe_tyhp_requires "$tyhp_map"
    fi
  fi

  if [[ "$SKIP_GITHUB" -eq 1 ]]; then
    echo "GitHub: skipped (--skip-github)"
  else
    ensure_github_repo "$pkg" || return 1
  fi

  if [[ "$skipped_local" -eq 1 ]]; then
    SKIPPED_EXISTING+=("$pkg")
  else
    CREATED+=("$pkg")
  fi
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
    --skip-existing)
      SKIP_EXISTING=1
      shift
      ;;
    --skip-github)
      SKIP_GITHUB=1
      shift
      ;;
    --include-already-have)
      INCLUDE_ALREADY_HAVE=1
      shift
      ;;
    --limit=*)
      LIMIT="${1#*=}"
      shift
      ;;
    --limit)
      [[ $# -ge 2 ]] || die "--limit requires a value"
      LIMIT="$2"
      shift 2
      ;;
    --only=*)
      ONLY_PACKAGES+=("${1#*=}")
      shift
      ;;
    --only)
      [[ $# -ge 2 ]] || die "--only requires vendor/package"
      ONLY_PACKAGES+=("$2")
      shift 2
      ;;
    --list=*)
      LIST_PATH="${1#*=}"
      shift
      ;;
    --list)
      [[ $# -ge 2 ]] || die "--list requires a path"
      LIST_PATH="$2"
      shift 2
      ;;
    -f|--force)
      FORCE=1
      shift
      ;;
    --include-dev)
      INCLUDE_DEV=1
      shift
      ;;
    --revision=*)
      REVISION="${1#*=}"
      shift
      ;;
    --revision)
      [[ $# -ge 2 ]] || die "--revision requires a value"
      REVISION="$2"
      shift 2
      ;;
    --)
      shift
      INNER_EXTRA+=("$@")
      break
      ;;
    -*)
      die "unknown option: $1
$(usage)"
      ;;
    *)
      die "unexpected argument: $1 (Packagist names go on --only)
$(usage)"
      ;;
  esac
done

[[ -f "$INNER_SCRIPT" ]] || die "missing inner script: $INNER_SCRIPT"
[[ -f "$LIST_PATH" ]] || die "proposed-package list not found: $LIST_PATH"

if [[ ! "$LIMIT" =~ ^[0-9]+$ ]]; then
  die "--limit must be a non-negative integer: $LIMIT"
fi
if [[ -n "$REVISION" && ! "$REVISION" =~ ^[0-9]+$ ]]; then
  die "--revision must be a non-negative integer: $REVISION"
fi

if [[ ${#ONLY_PACKAGES[@]} -gt 0 ]]; then
  for spec in "${ONLY_PACKAGES[@]}"; do
    if [[ "$spec" != */* || "$spec" == */*/* ]]; then
      die "--only must be vendor/package (one slash): $spec"
    fi
  done
fi

command -v python3 >/dev/null 2>&1 || die "python3 not found on PATH"
command -v curl >/dev/null 2>&1 || die "curl not found on PATH"
if [[ "$DRY_RUN" -ne 1 && "$SKIP_GITHUB" -ne 1 ]]; then
  command -v gh >/dev/null 2>&1 || die "gh not found on PATH (or pass --skip-github / --dry-run)"
fi

PARSED_TSV="$(mktemp "${TMPDIR:-/tmp}/tyhp-proposed-parsed.XXXXXX")"
WRAPPER_CATALOG_JSON="$(mktemp "${TMPDIR:-/tmp}/tyhp-proposed-wrappers.XXXXXX")"
PACKAGIST_TMP="$(mktemp "${TMPDIR:-/tmp}/tyhp-proposed-packagist.XXXXXX")"
cleanup() {
  rm -f "$PARSED_TSV" "$WRAPPER_CATALOG_JSON" "$PACKAGIST_TMP"
}
trap cleanup EXIT

parse_proposed_list "$LIST_PATH" > "$PARSED_TSV"
build_wrapper_catalog "$PARSED_TSV" "$SCRIPT_DIR" "$WRAPPER_CATALOG_JSON" || die "failed to build tyhp wrapper catalog"

CANDIDATES_RANK=()
CANDIDATES_PKG=()
CANDIDATES_CONSTRAINT=()
CANDIDATES_ALREADY=()

while IFS=$'\t' read -r rank pkg constraint already _prereqs; do
  [[ -n "${pkg:-}" ]] || continue
  CANDIDATES_RANK+=("$rank")
  CANDIDATES_PKG+=("$pkg")
  CANDIDATES_CONSTRAINT+=("$constraint")
  CANDIDATES_ALREADY+=("$already")
done < "$PARSED_TSV"

if [[ ${#CANDIDATES_PKG[@]} -eq 0 ]]; then
  die "no ranked packages parsed from $LIST_PATH"
fi

if [[ ${#ONLY_PACKAGES[@]} -gt 0 ]]; then
  for spec in "${ONLY_PACKAGES[@]}"; do
    found=0
    for pkg in "${CANDIDATES_PKG[@]}"; do
      if [[ "$(to_lower "$pkg")" == "$(to_lower "$spec")" ]]; then
        found=1
        break
      fi
    done
    if [[ "$found" -eq 0 ]]; then
      die "--only ${spec} is not in the ranked proposed list (or is in the unverified section)"
    fi
  done
fi

QUEUE_PKG=()
QUEUE_CONSTRAINT=()
skipped_already=0

i=0
while [[ "$i" -lt ${#CANDIDATES_PKG[@]} ]]; do
  pkg="${CANDIDATES_PKG[$i]}"
  constraint="${CANDIDATES_CONSTRAINT[$i]}"
  already="${CANDIDATES_ALREADY[$i]}"
  i=$((i + 1))

  if ! only_requested "$pkg"; then
    continue
  fi
  if [[ "$already" == "1" && "$INCLUDE_ALREADY_HAVE" -ne 1 && ${#ONLY_PACKAGES[@]} -eq 0 ]]; then
    skipped_already=$((skipped_already + 1))
    continue
  fi
  QUEUE_PKG+=("$pkg")
  QUEUE_CONSTRAINT+=("$constraint")
done

if [[ "$LIMIT" -gt 0 && ${#QUEUE_PKG[@]} -gt "$LIMIT" ]]; then
  QUEUE_PKG=("${QUEUE_PKG[@]:0:$LIMIT}")
  QUEUE_CONSTRAINT=("${QUEUE_CONSTRAINT[@]:0:$LIMIT}")
fi

total=${#QUEUE_PKG[@]}
if [[ "$total" -eq 0 ]]; then
  echo "No packages to process (filters removed every candidate)."
  exit 0
fi

echo "Proposed list: $LIST_PATH"
echo "Inner script:  $INNER_SCRIPT"
echo "GitHub org:    ${GITHUB_ORG}/<vendor>-<name>"
echo "Packages:      ${total}"
echo "Known wrappers: $(python3 -c 'import json,sys; print(len(json.load(open(sys.argv[1], encoding="utf-8"))))' "$WRAPPER_CATALOG_JSON") (proposed list ∪ existing composer-libs)"
if [[ "$DRY_RUN" -eq 1 ]]; then
  echo "Mode:          dry-run (no writes, no gh create)"
fi

CREATED=()
SKIPPED_EXISTING=()
FAILED=()

n=0
while [[ "$n" -lt "$total" ]]; do
  pkg="${QUEUE_PKG[$n]}"
  constraint="${QUEUE_CONSTRAINT[$n]}"
  n=$((n + 1))
  echo
  echo "==> [${n}/${total}] ${pkg}  constraint ${constraint}  repo ${GITHUB_ORG}/$(dir_name_for "$pkg")"

  if process_one "$pkg" "$constraint"; then
    continue
  fi
  FAILED+=("$pkg")
  echo "FAILED: ${pkg}" >&2
  if [[ "$FAIL_FAST" -eq 1 ]]; then
    echo "Aborting (--fail-fast)." >&2
    break
  fi
done

echo
echo "Summary"
echo "  processed:       ${n}/${total}"
echo "  scaffolded:      ${#CREATED[@]}"
echo "  skipped existing:${#SKIPPED_EXISTING[@]}"
echo "  skipped already-have (not queued): ${skipped_already}"
echo "  failed:          ${#FAILED[@]}"
if [[ ${#FAILED[@]} -gt 0 ]]; then
  echo "  failed names:    ${FAILED[*]}"
fi
echo "No git push. No Packagist submit."

if [[ ${#FAILED[@]} -gt 0 ]]; then
  exit 1
fi
exit 0
