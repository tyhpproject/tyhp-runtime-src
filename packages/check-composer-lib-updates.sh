#!/usr/bin/env bash
# Report Composer-lib tyhpdef packages that are outdated vs Packagist.
# Does not scaffold, bump constraints, call new-composer-lib.sh, or publish.
#
# Composer-lib packages use versioned trees:
#   packages/<vendor>-<name>/<upstream>/
# where <upstream> is the snapshot version string (3.0.2, 3.0.0-alpha.1), not
# the Tyhp four-part version (3.0.2.0) and not 80N.X.Y (that scheme is for
# compiled tyhp/* helpers only; tyhpdef/* including php-ext and php use the
# composer.json version as the tag).
#
# A package is listed only when:
#   - the local require constraint does not cover the latest stable upstream, or
#   - the tyhpdef-relevant public API would change versus the current Layer 1
# Constraint covers latest AND no tyhpdef-relevant diff → omitted.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" && pwd)"
TYHP_DLL="${TYHP_DLL:-$COMPILER_ROOT/bin/Debug/net9.0/tyhp.dll}"
USER_AGENT="tyhp-check-composer-lib-updates (+https://github.com/tyhpproject/tyhp-runtime-src)"

DRY_RUN=0
JSON_OUT=0
QUIET_SUCCESS=0
ONLY_PACKAGES=()
# auto | always | never — --no-color always wins over --color
COLOR_WANT=auto
NO_COLOR_FLAG=0
USE_DIFF_COLOR=0
# 1 = dump diffs to stdout (git --no-pager). Alias flag: --no-less
NO_PAGER=0

WORK_ROOT=""

usage() {
  cat <<'EOF'
Usage: check-composer-lib-updates.sh [options]

Scan packages/*/<upstream>/ Composer-lib tyhpdef wrappers, query
Packagist for the latest stable upstream version, and list packages that need
attention. This script never writes under packages, never calls
new-composer-lib.sh, and never publishes.

A package is listed when either:
  - the composer.json require constraint does not cover the latest stable, or
  - generating Layer 1 tyhpdefs from that latest version differs from the
    package's current _tyhpdef/ (tyhpdef-relevant public API)

If the constraint already covers latest and the tyhpdef surface is unchanged,
the package is omitted.

Human-mode tyhpdef/php diffs are printed in full. When stdout is a TTY,
TERM is not dumb, less is on PATH, and paging is not disabled, each
package's diff is shown with less -FRX (quit if one screen, keep ANSI
colors, leave text on the terminal after quit — same idea as git diff).
Package headers print before the pager; several outdated packages mean
one less session per diff. Use --no-pager to print every diff in one go.
The pager is skipped when stdout is not a TTY, TERM is dumb, less is
missing, --no-pager/--no-less is set, or GIT_PAGER/PAGER is cat or empty.

Composer-lib detection
  Versioned layout packages/<folder>/<upstream-version>/composer.json
  with no composer.json at <folder>/ (flat trees are php-ext / core / php /
  async / decimal / lambda). php-ext-* and those runtimes are skipped.

Options:
  -h, --help              Show this help
  -n, --dry-run           Resolve Packagist versions only; do not download
                          or run generate_tyhpdef. Packages where latest is
                          newer than the snapshot are listed with a note that
                          the API diff was skipped (a full run may omit them
                          if the tyhpdef surface is unchanged).
  --only <id>             Limit to this package (repeatable). <id> may be
                          vendor/package, the folder name (vendor-name), or
                          tyhp/vendor-name.
  --json                  Print a JSON summary on stdout instead of the
                          human report (progress still goes to stderr).
  --quiet-success         Exit 0 even when outdated packages are listed
                          (still exit 1 on usage / per-package errors).
  --color[=WHEN]          Colorize printed tyhpdef/php diffs. WHEN is always,
                          never, or auto (default auto: color when stdout is
                          a TTY and NO_COLOR is unset). --color means always.
  --no-color              Do not colorize diffs (overrides --color)
  --no-pager              Print full tyhpdef/php diffs to stdout without
                          invoking less (git --no-pager). Alias: --no-less.

Exit status
  0  none listed, or --quiet-success after a successful scan
  1  one or more packages listed as outdated, or a package check failed
  2  usage error

Examples:
  ./check-composer-lib-updates.sh
  ./check-composer-lib-updates.sh --dry-run
  ./check-composer-lib-updates.sh --only psr/log --only monolog-monolog
  ./check-composer-lib-updates.sh --json
  ./check-composer-lib-updates.sh --no-color
  ./check-composer-lib-updates.sh --no-pager --only monolog/monolog
EOF
}

die() {
  echo "error: $*" >&2
  exit 2
}

cleanup() {
  if [[ -n "$WORK_ROOT" && -d "$WORK_ROOT" ]]; then
    rm -rf "$WORK_ROOT"
  fi
}

to_lower() {
  printf '%s' "$1" | tr '[:upper:]' '[:lower:]'
}

# Cheap --only prefilter (folder / vendor-name / vendor/package / tyhp/vendor-name)
# so we do not hit Packagist for every other package.
folder_matches_only() {
  local folder_l
  local candidate
  local want
  folder_l="$(to_lower "$1")"
  if [[ ${#ONLY_PACKAGES[@]} -eq 0 ]]; then
    return 0
  fi
  for candidate in "${ONLY_PACKAGES[@]}"; do
    want="$(to_lower "$candidate")"
    if [[ "$want" == "$folder_l" ]]; then
      return 0
    fi
    want="${want#tyhp/}"
    want="${want//\//-}"
    if [[ "$want" == "$folder_l" ]]; then
      return 0
    fi
  done
  return 1
}

# Python helper. Subcommands: discover | meta | packagist | shadow-php | unzip-find
run_py() {
  python3 - "$@" <<'PY'
import json
import os
import re
import shutil
import sys
import zipfile

SKIP_NAMES = {"core", "php", "async", "decimal", "lambda", "dist", "vendor"}
VERSION_RE = re.compile(r"^[0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.-]+)?$")
PKG_RE = re.compile(r"^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$")


def strip_v(raw: str) -> str:
    raw = (raw or "").strip()
    if re.match(r"v[0-9]", raw, re.I):
        return raw[1:]
    return raw


def split_core_pre(ver: str):
    ver = strip_v(ver).split("+", 1)[0]
    if not ver:
        return (), None
    if "-" in ver:
        core, pre = ver.split("-", 1)
    else:
        core, pre = ver, None
    parts = tuple(int(p) for p in core.split(".") if p != "")
    return parts, pre


def pre_key(pre):
    if pre is None:
        return (1, ())
    bits = []
    for tok in re.split(r"[.-]", pre):
        if not tok:
            continue
        low = tok.lower()
        if low in {"dev"}:
            bits.append((0, 0, ""))
        elif low in {"alpha", "a"}:
            bits.append((1, 0, ""))
        elif low in {"beta", "b"}:
            bits.append((2, 0, ""))
        elif low in {"rc"}:
            bits.append((3, 0, ""))
        elif tok.isdigit():
            bits.append((4, int(tok), ""))
        else:
            bits.append((4, 0, low))
    return (0, tuple(bits))


def version_key(ver: str):
    parts, pre = split_core_pre(ver)
    padded = parts + (0,) * (3 - len(parts))
    return (padded, pre_key(pre), parts)


def is_dev(ver: str) -> bool:
    v = strip_v(ver).lower()
    return v.startswith("dev-") or v.endswith("-dev") or v in {
        "main", "master", "head", "trunk", "develop", "default",
    }


def is_stable(ver: str) -> bool:
    if not ver or is_dev(ver):
        return False
    parts, pre = split_core_pre(ver)
    return bool(parts) and pre is None


def cmp_ver(a: str, b: str) -> int:
    x, y = version_key(a), version_key(b)
    return (x > y) - (x < y)


def pad_parts(a, b, n=3):
    m = max(len(a), len(b), n)
    return a + (0,) * (m - len(a)), b + (0,) * (m - len(b))


def cmp_parts(a, b) -> int:
    x, y = pad_parts(a, b)
    return (x > y) - (x < y)


def strip_stability(spec: str) -> str:
    spec = spec.strip()
    if "@" in spec:
        spec = spec.split("@", 1)[0].strip()
    return spec


def caret_upper(parts):
    padded = parts + (0,) * (3 - len(parts))
    if padded[0] != 0:
        return (padded[0] + 1,) + (0,) * (len(padded) - 1)
    if padded[1] != 0:
        return (0, padded[1] + 1) + (0,) * (len(padded) - 2)
    return (0, 0, padded[2] + 1) + (0,) * (max(0, len(padded) - 3))


def tilde_upper(parts):
    if len(parts) <= 1:
        major = parts[0] if parts else 0
        return (major + 1,)
    return (parts[0], parts[1] + 1)


def wildcard_bounds(spec: str):
    spec = strip_v(spec)
    if spec in {"*", "x", "X"}:
        return ((), None)
    bits = spec.split(".")
    prefix = []
    saw_wild = False
    for bit in bits:
        if bit in {"*", "x", "X"}:
            saw_wild = True
            break
        if not bit.isdigit():
            return None
        prefix.append(int(bit))
    if not saw_wild:
        return None
    lower = tuple(prefix)
    if not lower:
        return ((), None)
    # 1.2.* → >=1.2.0 <1.3.0; 1.* → >=1.0.0 <2.0.0
    return (lower, lower[:-1] + (lower[-1] + 1,))


def hyphen_range(expr: str):
    m = re.fullmatch(r"(.+?)\s+-\s+(.+)", expr.strip())
    if not m:
        return None
    return m.group(1).strip(), m.group(2).strip()


def parse_op_tokens(expr: str):
    expr = expr.strip()
    tokens = []
    pos = 0
    pat = re.compile(r"(>=|<=|!=|>|<|=)?\s*(v?[0-9][0-9A-Za-z.+:*-]*|\*|x|X)", re.I)
    while pos < len(expr):
        while pos < len(expr) and expr[pos].isspace():
            pos += 1
        if pos >= len(expr):
            break
        m = pat.match(expr, pos)
        if not m:
            return None
        op = m.group(1) or "="
        spec = m.group(2)
        tokens.append((op, spec))
        pos = m.end()
    return tokens or None


def satisfies(ver: str, expr: str) -> bool:
    expr = (expr or "").strip()
    if not expr:
        return False
    return any(satisfies_clause(ver, c.strip()) for c in expr.split("||") if c.strip())


def in_range(ver_parts, lower, upper_exclusive, upper_inclusive=None):
    if lower is not None and cmp_parts(ver_parts, lower) < 0:
        return False
    if upper_exclusive is not None and cmp_parts(ver_parts, upper_exclusive) >= 0:
        return False
    if upper_inclusive is not None and cmp_parts(ver_parts, upper_inclusive) > 0:
        return False
    return True


def satisfies_clause(ver: str, expr: str) -> bool:
    expr = strip_stability(expr)
    if not expr:
        return False
    vparts, vpre = split_core_pre(ver)
    if not vparts:
        return False

    if expr in {"*", "*.*", "*.*.*"}:
        return not is_dev(ver)

    if expr.startswith("^"):
        spec = strip_v(strip_stability(expr[1:]))
        parts, pre = split_core_pre(spec)
        if not parts:
            return False
        if pre is not None and cmp_ver(ver, spec) == 0:
            return True
        return in_range(vparts, parts, caret_upper(parts)) and vpre is None

    if expr.startswith("~"):
        spec = strip_v(strip_stability(expr[1:]))
        parts, pre = split_core_pre(spec)
        if not parts:
            return False
        return in_range(vparts, parts, tilde_upper(parts)) and vpre is None

    hr = hyphen_range(expr)
    if hr:
        lo_spec, hi_spec = hr
        lo, _ = split_core_pre(lo_spec)
        hi_raw = strip_v(hi_spec)
        hi, _ = split_core_pre(hi_raw)
        # Partial right side: 1.0 - 2.0 → >=1.0.0 <2.1.0
        hi_bits = [b for b in hi_raw.split(".") if b != ""]
        if hi and len(hi_bits) < 3:
            return in_range(vparts, lo, hi[:-1] + (hi[-1] + 1,)) and vpre is None
        return in_range(vparts, lo, None, hi) and vpre is None

    if "*" in expr or expr.lower() in {"x"} or ".x" in expr.lower():
        bounds = wildcard_bounds(expr.replace("x", "*").replace("X", "*"))
        if bounds is None:
            return False
        lower, upper = bounds
        if upper is None and not lower:
            return not is_dev(ver)
        return in_range(vparts, lower, upper) and vpre is None

    tokens = parse_op_tokens(expr)
    if not tokens:
        # Bare exact version
        spec = strip_v(expr)
        return cmp_ver(ver, spec) == 0

    for op, spec in tokens:
        spec = strip_v(spec)
        if spec in {"*", "x", "X"}:
            if is_dev(ver):
                return False
            continue
        sparts, spre = split_core_pre(spec)
        c = cmp_ver(ver, spec)
        # Prefer numeric compare when both stable
        if vpre is None and spre is None:
            c = cmp_parts(vparts, sparts)
        if op == "=":
            if c != 0:
                return False
        elif op == ">=":
            if c < 0:
                return False
        elif op == ">":
            if c <= 0:
                return False
        elif op == "<=":
            if c > 0:
                return False
        elif op == "<":
            if c >= 0:
                return False
        elif op == "!=":
            if c == 0:
                return False
        else:
            return False
    return True


def cmd_discover(packages_dir: str):
    rows = []
    try:
        names = os.listdir(packages_dir)
    except OSError as exc:
        print(f"error: cannot list {packages_dir}: {exc}", file=sys.stderr)
        sys.exit(1)
    for name in sorted(names, key=lambda s: s.lower()):
        parent = os.path.join(packages_dir, name)
        if not os.path.isdir(parent):
            continue
        if name.startswith("php-ext-") or name in SKIP_NAMES:
            continue
        if os.path.isfile(os.path.join(parent, "composer.json")):
            continue
        version_dirs = []
        try:
            children = os.listdir(parent)
        except OSError:
            continue
        for ver in children:
            vdir = os.path.join(parent, ver)
            cj = os.path.join(vdir, "composer.json")
            if os.path.isdir(vdir) and os.path.isfile(cj) and VERSION_RE.match(ver):
                version_dirs.append(ver)
        if not version_dirs:
            continue
        latest = max(version_dirs, key=version_key)
        rows.append((name, latest, os.path.join(parent, latest, "composer.json")))
    for name, ver, path in rows:
        print(f"{name}\t{ver}\t{path}")


def parse_sources(path: str):
    try:
        text = open(path, encoding="utf-8").read()
    except OSError:
        return None, None
    m = re.search(
        r"(?im)^-\s*Composer package:\s*([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)\s+(\S+)",
        text,
    )
    if not m:
        return None, None
    return m.group(1), m.group(2)


def parse_description(desc: str):
    m = re.search(
        r"Composer package\s+([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)\s+\(([^)]+)\)",
        desc or "",
    )
    if not m:
        return None, None
    return m.group(1), m.group(2).strip()


def require_candidates(req: dict):
    out = []
    for key, val in (req or {}).items():
        if not isinstance(key, str) or not PKG_RE.match(key):
            continue
        low = key.lower()
        if low == "php" or low.startswith("ext-") or low.startswith("tyhp/") or low.startswith("tyhpdef/"):
            continue
        if low in {"composer-plugin-api", "composer-runtime-api"}:
            continue
        out.append((key, str(val)))
    return out


def folder_to_upstream(folder: str, candidates):
    want = folder.lower()
    for key, val in candidates:
        vendor, proj = key.split("/", 1)
        if f"{vendor}-{proj}".lower() == want:
            return key, val
    return None


def cmd_meta(composer_json: str, folder: str, ver_dir: str):
    try:
        doc = json.load(open(composer_json, encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"error: cannot read {composer_json}: {exc}", file=sys.stderr)
        sys.exit(1)

    pkg_dir = os.path.dirname(composer_json)
    sources = os.path.join(pkg_dir, "_tyhpdef", "SOURCES.md")
    src_up, src_ver = parse_sources(sources)
    desc_up, desc_ver = parse_description(str(doc.get("description") or ""))
    cands = require_candidates(doc.get("require") or {})
    mapped = folder_to_upstream(folder, cands)

    upstream = None
    constraint = None
    if src_up:
        upstream = src_up
    elif mapped:
        upstream = mapped[0]
    elif desc_up:
        upstream = desc_up
    elif len(cands) == 1:
        upstream = cands[0][0]

    if not upstream:
        print(
            f"error: cannot identify upstream Packagist name in {composer_json}",
            file=sys.stderr,
        )
        sys.exit(1)

    for key, val in cands:
        if key.lower() == upstream.lower():
            constraint = val
            upstream = key
            break
    if constraint is None:
        print(
            f"error: {composer_json} require is missing {upstream}",
            file=sys.stderr,
        )
        sys.exit(1)

    snapshot = src_ver or desc_ver or ver_dir
    snapshot = strip_v(snapshot).split("+", 1)[0]
    vendor, proj = upstream.split("/", 1)
    layer1 = f"{vendor}.{proj}.tyhpdef"
    existing = []
    layer1_dir = os.path.join(pkg_dir, "_tyhpdef")
    if os.path.isdir(layer1_dir):
        for name in sorted(os.listdir(layer1_dir)):
            if name.endswith(".tyhpdef"):
                existing.append(name)
    if existing and layer1 not in existing:
        layer1 = existing[0]

    print(json.dumps({
        "folder": folder,
        "dir": ver_dir,
        "pkg_dir": pkg_dir,
        "tyhp_name": str(doc.get("name") or ""),
        "tyhp_version": str(doc.get("version") or ""),
        "upstream": upstream,
        "constraint": constraint,
        "snapshot": snapshot,
        "layer1_file": layer1,
        "vendor": vendor,
        "proj": proj,
        "layer1_files": existing,
        "ownership_status": str((((doc.get("extra") or {}).get("tyhp") or {}).get("ownership") or {}).get("status") or "") if isinstance((doc.get("extra") or {}).get("tyhp"), dict) else "",
    }, ensure_ascii=True))


def collect_packagist_versions(doc: dict):
    versions = (doc.get("package") or {}).get("versions") or {}
    items = []
    for key, meta in versions.items():
        if not isinstance(meta, dict):
            continue
        ver = str(meta.get("version") or key).strip()
        if is_dev(ver):
            continue
        pin = strip_v(ver).split("+", 1)[0]
        dist = meta.get("dist") or {}
        source = meta.get("source") or {}
        items.append({
            "version": pin,
            "raw": ver,
            "stable": is_stable(pin),
            "dist_url": str(dist.get("url") or ""),
            "dist_type": str(dist.get("type") or ""),
            "source_url": str(source.get("url") or ""),
            "source_ref": str(source.get("reference") or ""),
        })
    return items


def pick_latest(items, local_snapshot: str):
    stables = [i for i in items if i["stable"]]
    if stables:
        return max(stables, key=lambda i: version_key(i["version"]))
    local_pre = split_core_pre(local_snapshot)[1] is not None
    if local_pre:
        pres = [i for i in items if not i["stable"]]
        if pres:
            return max(pres, key=lambda i: version_key(i["version"]))
    return None


def cmd_packagist(json_path: str, pkg: str, snapshot: str, constraint: str):
    try:
        doc = json.load(open(json_path, encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"error: invalid Packagist JSON for {pkg}: {exc}", file=sys.stderr)
        sys.exit(1)
    items = collect_packagist_versions(doc)
    if not items:
        print(f"error: Packagist returned no versions for {pkg}", file=sys.stderr)
        sys.exit(1)
    latest = pick_latest(items, snapshot)
    if latest is None:
        print(
            f"error: no usable Packagist version for {pkg} "
            f"(no stable release; local snapshot {snapshot!r} is not a prerelease)",
            file=sys.stderr,
        )
        sys.exit(1)
    pin = latest["version"]
    covers = satisfies(pin, constraint)
    newer = cmp_ver(pin, snapshot) > 0
    same = cmp_ver(pin, snapshot) == 0
    print(json.dumps({
        "latest": pin,
        "latest_raw": latest["raw"],
        "latest_stable": latest["stable"],
        "covers": covers,
        "newer_than_snapshot": newer,
        "same_as_snapshot": same,
        "dist_url": latest["dist_url"],
        "dist_type": latest["dist_type"],
        "source_url": latest["source_url"],
        "source_ref": latest["source_ref"],
    }, ensure_ascii=True))


def autoload_php_files(package_root: str):
    cj = os.path.join(package_root, "composer.json")
    try:
        doc = json.load(open(cj, encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        doc = {}
    roots = []
    auto = doc.get("autoload") or {}
    for mapping in (auto.get("psr-4") or {}).values():
        if isinstance(mapping, list):
            roots.extend(mapping)
        elif isinstance(mapping, str):
            roots.append(mapping)
    for mapping in (auto.get("psr-0") or {}).values():
        if isinstance(mapping, list):
            roots.extend(mapping)
        elif isinstance(mapping, str):
            roots.append(mapping)
    for item in (auto.get("classmap") or []):
        if isinstance(item, str):
            roots.append(item)
    for item in (auto.get("files") or []):
        if isinstance(item, str):
            roots.append(item)
    if not roots:
        for guess in ("src", "lib", "library"):
            if os.path.isdir(os.path.join(package_root, guess)):
                roots.append(guess)

    skip_parts = {".git", "vendor", "tests", "test", "docs", "doc", "examples"}
    files = []
    seen = set()
    for rel in roots:
        rel = rel.strip("/.")
        abs_path = os.path.join(package_root, rel) if rel else package_root
        if os.path.isfile(abs_path) and abs_path.endswith(".php"):
            key = os.path.relpath(abs_path, package_root)
            if key not in seen:
                seen.add(key)
                files.append(key)
            continue
        if not os.path.isdir(abs_path):
            continue
        for dirpath, dirnames, filenames in os.walk(abs_path):
            dirnames[:] = [d for d in dirnames if d.lower() not in skip_parts]
            for fn in filenames:
                if not fn.endswith(".php"):
                    continue
                full = os.path.join(dirpath, fn)
                key = os.path.relpath(full, package_root)
                if key not in seen:
                    seen.add(key)
                    files.append(key)
    files.sort()
    return files


def cmd_shadow_php(src_root: str, dest_root: str):
    files = autoload_php_files(src_root)
    if not files:
        print("0")
        return
    for rel in files:
        src = os.path.join(src_root, rel)
        dest = os.path.join(dest_root, rel)
        os.makedirs(os.path.dirname(dest), exist_ok=True)
        shutil.copy2(src, dest)
    print(str(len(files)))


def cmd_unzip_find(zip_path: str, dest_dir: str):
    os.makedirs(dest_dir, exist_ok=True)
    with zipfile.ZipFile(zip_path) as zf:
        zf.extractall(dest_dir)
    found = []
    for dirpath, dirnames, filenames in os.walk(dest_dir):
        dirnames[:] = [d for d in dirnames if d not in {".git", "vendor"}]
        if "composer.json" in filenames:
            found.append(dirpath)
    if not found:
        print(f"error: unzipped archive has no composer.json: {zip_path}", file=sys.stderr)
        sys.exit(1)
    found.sort(key=lambda p: (p.count(os.sep), len(p)))
    print(found[0])


def cmd_only_match(needle: str, folder: str, upstream: str, tyhp_name: str):
    n = needle.lower()
    names = {folder.lower(), upstream.lower()}
    if tyhp_name:
        names.add(tyhp_name.lower())
    names.add(folder.lower().replace("-", "/"))
    sys.exit(0 if n in names else 1)


argv = sys.argv[1:]
if not argv:
    print("error: python helper needs a subcommand", file=sys.stderr)
    sys.exit(1)
cmd = argv[0]
try:
    if cmd == "discover":
        cmd_discover(argv[1])
    elif cmd == "meta":
        cmd_meta(argv[1], argv[2], argv[3])
    elif cmd == "packagist":
        cmd_packagist(argv[1], argv[2], argv[3], argv[4])
    elif cmd == "shadow-php":
        cmd_shadow_php(argv[1], argv[2])
    elif cmd == "unzip-find":
        cmd_unzip_find(argv[1], argv[2])
    elif cmd == "only-match":
        cmd_only_match(argv[1], argv[2], argv[3], argv[4])
    else:
        print(f"error: unknown python helper command: {cmd}", file=sys.stderr)
        sys.exit(1)
except BrokenPipeError:
    sys.exit(0)
PY
}

only_requested() {
  local folder="$1"
  local upstream="$2"
  local tyhp_name="$3"
  local candidate
  if [[ ${#ONLY_PACKAGES[@]} -eq 0 ]]; then
    return 0
  fi
  for candidate in "${ONLY_PACKAGES[@]}"; do
    if run_py only-match "$(to_lower "$candidate")" "$folder" "$upstream" "$tyhp_name"; then
      return 0
    fi
  done
  return 1
}

fetch_packagist() {
  local pkg="$1"
  local out="$2"
  local url="https://packagist.org/packages/${pkg}.json"
  local code
  code="$(curl -sS -L -o "$out" -w '%{http_code}' -A "$USER_AGENT" --max-time 90 "$url")" || {
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
  return 0
}

download_upstream() {
  local pkg="$1"
  local ver="$2"
  local dest="$3"
  local dist_url="$4"
  local source_url="$5"
  local zip_path="$WORK_ROOT/dist.zip"
  local unpack="$WORK_ROOT/unpack"
  local root=""

  rm -rf "$dest" "$unpack" "$zip_path"
  mkdir -p "$unpack"

  if [[ -n "$dist_url" ]]; then
    if curl -sS -L -A "$USER_AGENT" --max-time 120 -o "$zip_path" "$dist_url"; then
      if root="$(run_py unzip-find "$zip_path" "$unpack" 2>/dev/null)"; then
        mkdir -p "$(dirname "$dest")"
        mv "$root" "$dest"
        rm -rf "$unpack" "$zip_path"
        return 0
      fi
    fi
  fi

  rm -rf "$unpack" "$zip_path"
  mkdir -p "$unpack"

  if [[ -n "$source_url" ]] && command -v git >/dev/null 2>&1; then
    local tag
    for tag in "$ver" "v$ver"; do
      if git clone --depth 1 --branch "$tag" --quiet "$source_url" "$dest" 2>/dev/null; then
        rm -rf "$dest/.git"
        return 0
      fi
      rm -rf "$dest"
    done
  fi

  if command -v composer >/dev/null 2>&1; then
    if composer create-project \
      --no-install --no-dev --no-scripts --no-plugins \
      --ignore-platform-reqs --no-interaction --prefer-dist \
      "${pkg}:${ver}" "$dest" >/dev/null 2>&1; then
      return 0
    fi
    rm -rf "$dest"
  fi

  echo "error: failed to download ${pkg} ${ver}" >&2
  return 1
}

copy_layer1_tree() {
  local src="$1"
  local dest="$2"
  local name
  rm -rf "$dest"
  mkdir -p "$dest"
  if [[ -d "$src" ]]; then
    for name in "$src"/*.tyhpdef; do
      [[ -f "$name" ]] || continue
      cp "$name" "$dest/"
    done
    if [[ -d "$src/extensions" ]]; then
      mkdir -p "$dest/extensions"
      for name in "$src/extensions"/*.tyhpdef; do
        [[ -f "$name" ]] || continue
        cp "$name" "$dest/extensions/"
      done
      rmdir "$dest/extensions" 2>/dev/null || true
    fi
  fi
}

diff_trees() {
  local old_dir="$1"
  local new_dir="$2"
  local out_file="$3"
  local rc=0
  : > "$out_file"
  if [[ ! -d "$old_dir" && ! -d "$new_dir" ]]; then
    return 1
  fi
  mkdir -p "$old_dir" "$new_dir"
  set +e
  (cd "$(dirname "$old_dir")" && diff -ruN "$(basename "$old_dir")" "$(basename "$new_dir")") > "$out_file"
  rc=$?
  set -e
  if [[ "$rc" -eq 0 ]]; then
    : > "$out_file"
    return 1
  fi
  if [[ "$rc" -eq 1 ]]; then
    return 0
  fi
  return 2
}

run_generate_tyhpdef() {
  local package_path="$1"
  local output_dir="$2"
  local layer1_file="$3"
  if [[ ! -f "$TYHP_DLL" ]]; then
    return 1
  fi
  if ! command -v dotnet >/dev/null 2>&1; then
    return 1
  fi
  rm -rf "$output_dir"
  mkdir -p "$output_dir"
  set +e
  (cd "$REPO_ROOT" && dotnet "$TYHP_DLL" generate_tyhpdef \
    "--package-path=${package_path}" \
    "--output=${output_dir}" \
    "--output-file=${layer1_file}" \
    --overwrite) >/dev/null 2>&1
  local rc=$?
  set -e
  return "$rc"
}

# Git-like: page when stdout is a TTY, TERM is not dumb, less exists, and
# paging was not disabled (--no-pager, or GIT_PAGER/PAGER is cat / empty).
want_diff_pager() {
  if [[ "$NO_PAGER" -eq 1 ]]; then
    return 1
  fi
  if [[ ! -t 1 ]]; then
    return 1
  fi
  if [[ "${TERM:-}" == "dumb" ]]; then
    return 1
  fi
  if [[ -n "${GIT_PAGER+x}" ]]; then
    case "$GIT_PAGER" in
      ""|cat) return 1 ;;
    esac
  elif [[ -n "${PAGER+x}" ]]; then
    case "$PAGER" in
      ""|cat) return 1 ;;
    esac
  fi
  command -v less >/dev/null 2>&1 || return 1
  return 0
}

# Colorize a stored (plain) diff onto stdout. JSON / on-disk diffs stay uncolored.
print_colorized_diff() {
  local path="$1"
  python3 - "$path" "$USE_DIFF_COLOR" <<'PY'
import sys
path, colorize = sys.argv[1], sys.argv[2] == "1"
RESET = "\033[0m"
HEADER = "\033[36m"
ADD = "\033[32m"
REMOVE = "\033[31m"

def paint(line):
    if not colorize:
        return line
    had_nl = line.endswith("\n")
    raw = line[:-1] if had_nl else line
    if raw.startswith(("diff ", "@@", "---", "+++")):
        color = HEADER
    elif raw.startswith("+"):
        color = ADD
    elif raw.startswith("-"):
        color = REMOVE
    else:
        return line
    return color + raw + RESET + ("\n" if had_nl else "")

with open(path, encoding="utf-8", errors="replace") as fh:
    for line in fh:
        sys.stdout.write(paint(line))
PY
}

preview_diff() {
  local diff_file="$1"
  local kind="$2"
  local lines
  if [[ ! -s "$diff_file" ]]; then
    echo "(no ${kind} diff)"
    return 0
  fi
  lines="$(python3 - "$diff_file" <<'PY'
import sys
path = sys.argv[1]
n = 0
with open(path, encoding="utf-8", errors="replace") as fh:
    for _ in fh:
        n += 1
print(n)
PY
)"
  echo "--- ${kind} diff (${lines} lines) ---"
  if want_diff_pager; then
    # less -F dumps short diffs; -R keeps ANSI; -X leaves text after quit.
    # Quitting less can SIGPIPE python (141); that is not a script failure.
    set +e
    set +o pipefail
    print_colorized_diff "$diff_file" | command less -FRX
    set -o pipefail
    set -e
  else
    print_colorized_diff "$diff_file"
  fi
}

emit_human() {
  local folder="$1"
  local pkg_dir="$2"
  local upstream="$3"
  local snapshot="$4"
  local tyhp_version="$5"
  local latest="$6"
  local constraint="$7"
  local covers="$8"
  local reason="$9"
  local diff_file="${10}"
  local diff_kind="${11}"

  echo "========== ${folder} =========="
  echo "folder:     ${folder}"
  echo "path:       ${pkg_dir}"
  echo "upstream:   ${upstream}"
  echo "snapshot:   ${snapshot}  (tyhp ${tyhp_version}, dir $(basename "$pkg_dir"))"
  echo "latest:     ${latest}"
  echo "constraint: ${constraint}"
  echo "covers:     ${covers}"
  echo "reason:     ${reason}"
  if [[ -n "$diff_file" ]]; then
    preview_diff "$diff_file" "$diff_kind"
  fi
  echo
}

emit_json_row() {
  local folder="$1"
  local pkg_dir="$2"
  local upstream="$3"
  local snapshot="$4"
  local tyhp_version="$5"
  local latest="$6"
  local constraint="$7"
  local covers="$8"
  local tyhpdef_changed="$9"
  local diff_kind="${10}"
  local reason="${11}"
  local diff_file="${12}"
  local rel="${pkg_dir#"$REPO_ROOT"/}"
  python3 - "$folder" "$rel" "$upstream" "$snapshot" "$tyhp_version" "$latest" \
    "$constraint" "$covers" "$tyhpdef_changed" "$diff_kind" "$reason" \
    "${diff_file:-}" "$JSON_ROWS" <<'PY'
import json, sys
folder, rel, upstream, snapshot, tyhp_ver, latest, constraint, covers, changed, kind, reason, diff_file, out = sys.argv[1:]
diff_text = None
if diff_file:
    try:
        text = open(diff_file, encoding="utf-8", errors="replace").read()
        if text:
            diff_text = text
    except OSError:
        diff_text = None
row = {
    "folder": folder,
    "path": rel,
    "upstream": upstream,
    "snapshot": snapshot,
    "tyhp_version": tyhp_ver,
    "latest": latest,
    "constraint": constraint,
    "covers": covers == "yes",
    "tyhpdef_changed": None if changed == "null" else changed == "yes",
    "diff_kind": kind or None,
    "reason": reason,
    "diff": diff_text,
}
with open(out, "a", encoding="utf-8") as fh:
    fh.write(json.dumps(row, ensure_ascii=True) + "\n")
PY
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
    --json)
      JSON_OUT=1
      shift
      ;;
    --quiet-success)
      QUIET_SUCCESS=1
      shift
      ;;
    --no-color)
      NO_COLOR_FLAG=1
      shift
      ;;
    --no-pager|--no-less)
      NO_PAGER=1
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
    --only=*)
      ONLY_PACKAGES+=("${1#*=}")
      shift
      ;;
    --only)
      [[ $# -ge 2 ]] || die "--only requires a value"
      ONLY_PACKAGES+=("$2")
      shift 2
      ;;
    --)
      shift
      break
      ;;
    -*)
      die "unknown option: $1
$(usage)"
      ;;
    *)
      die "unexpected argument: $1 (use --only vendor/package)
$(usage)"
      ;;
  esac
done

# Color printed diffs only. JSON diffs stay plain (uncolored).
# --no-color wins over --color. NO_COLOR and non-TTY disable auto color;
# --color / --color=always still enable color when stdout is not a TTY.
USE_DIFF_COLOR=0
if [[ "$NO_COLOR_FLAG" -eq 0 ]]; then
  case "$COLOR_WANT" in
    always)
      USE_DIFF_COLOR=1
      ;;
    never)
      USE_DIFF_COLOR=0
      ;;
    auto)
      if [[ -z "${NO_COLOR-}" && -t 1 ]]; then
        USE_DIFF_COLOR=1
      fi
      ;;
  esac
fi

WORK_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/tyhp-check-composer-lib.XXXXXX")"
trap cleanup EXIT

JSON_ROWS="$WORK_ROOT/rows.jsonl"
: > "$JSON_ROWS"
PACKAGIST_TMP="$WORK_ROOT/packagist.json"
DISCOVER_TMP="$WORK_ROOT/discover.tsv"
ERRORS_TMP="$WORK_ROOT/errors.txt"
: > "$ERRORS_TMP"

run_py discover "$SCRIPT_DIR" > "$DISCOVER_TMP"

if [[ ! -s "$DISCOVER_TMP" ]]; then
  if [[ "$JSON_OUT" -eq 1 ]]; then
    echo '{"outdated":[],"checked":0,"errors":[]}'
  else
    echo "No Composer-lib tyhpdef packages found under $SCRIPT_DIR."
  fi
  exit 0
fi

CHECKED=0
LISTED=0

while IFS=$'\t' read -r FOLDER VER_DIR COMPOSER_JSON; do
  [[ -n "$FOLDER" ]] || continue

  if ! folder_matches_only "$FOLDER"; then
    continue
  fi

  META_JSON=""
  set +e
  META_JSON="$(run_py meta "$COMPOSER_JSON" "$FOLDER" "$VER_DIR")"
  meta_rc=$?
  set -e
  if [[ "$meta_rc" -ne 0 ]]; then
    echo "error: skipping $FOLDER (cannot read package metadata)" >&2
    echo "$FOLDER: metadata" >> "$ERRORS_TMP"
    continue
  fi

  eval "$(python3 - "$META_JSON" <<'PY'
import json, sys
doc = json.loads(sys.argv[1])
def export(key, env):
    val = str(doc.get(key) or "")
    print(f"{env}={json.dumps(val)}")
export("pkg_dir", "PKG_DIR")
export("tyhp_name", "TYHP_NAME")
export("tyhp_version", "TYHP_VERSION")
export("upstream", "UPSTREAM")
export("constraint", "CONSTRAINT")
export("snapshot", "SNAPSHOT")
export("layer1_file", "LAYER1_FILE")
export("vendor", "VENDOR")
export("proj", "PROJ")
export("ownership_status", "OWNERSHIP_STATUS")
PY
)"

  if [[ "$OWNERSHIP_STATUS" == "handed-off" ]]; then
    echo "Skipping handed-off ${FOLDER}/${VER_DIR}" >&2
    continue
  fi

  if ! only_requested "$FOLDER" "$UPSTREAM" "$TYHP_NAME"; then
    continue
  fi

  CHECKED=$((CHECKED + 1))
  echo "Checking ${FOLDER} (${UPSTREAM})…" >&2

  if ! fetch_packagist "$UPSTREAM" "$PACKAGIST_TMP"; then
    echo "$FOLDER: packagist ${UPSTREAM}" >> "$ERRORS_TMP"
    continue
  fi

  set +e
  PACK_JSON="$(run_py packagist "$PACKAGIST_TMP" "$UPSTREAM" "$SNAPSHOT" "$CONSTRAINT")"
  pack_rc=$?
  set -e
  if [[ "$pack_rc" -ne 0 ]]; then
    echo "$FOLDER: resolve ${UPSTREAM}" >> "$ERRORS_TMP"
    continue
  fi

  eval "$(python3 - "$PACK_JSON" <<'PY'
import json, sys
doc = json.loads(sys.argv[1])
def export(key, env, default=""):
    val = doc.get(key)
    if isinstance(val, bool):
        print(f"{env}={'yes' if val else 'no'}")
        return
    if val is None:
        val = default
    print(f"{env}={json.dumps(str(val))}")
export("latest", "LATEST")
export("covers", "COVERS")
export("newer_than_snapshot", "NEWER")
export("same_as_snapshot", "SAME")
export("dist_url", "DIST_URL")
export("source_url", "SOURCE_URL")
PY
)"

  if [[ "$SAME" == "yes" && "$COVERS" == "yes" ]]; then
    continue
  fi
  if [[ "$NEWER" != "yes" && "$COVERS" == "yes" ]]; then
    continue
  fi

  DIFF_FILE=""
  DIFF_KIND=""
  TYHPDEF_CHANGED="null"
  REASON=""

  if [[ "$DRY_RUN" -eq 1 ]]; then
    if [[ "$COVERS" == "yes" ]]; then
      REASON="latest ${LATEST} is newer than snapshot ${SNAPSHOT}; API diff skipped (--dry-run). A full run omits this package if the tyhpdef surface is unchanged."
    else
      REASON="constraint does not include ${LATEST} (API diff skipped, --dry-run)"
    fi
    LISTED=$((LISTED + 1))
    if [[ "$JSON_OUT" -eq 1 ]]; then
      emit_json_row "$FOLDER" "$PKG_DIR" "$UPSTREAM" "$SNAPSHOT" "$TYHP_VERSION" \
        "$LATEST" "$CONSTRAINT" "$COVERS" "$TYHPDEF_CHANGED" "skipped" "$REASON" ""
    else
      emit_human "$FOLDER" "$PKG_DIR" "$UPSTREAM" "$SNAPSHOT" "$TYHP_VERSION" \
        "$LATEST" "$CONSTRAINT" "$COVERS" "$REASON" "" ""
    fi
    continue
  fi

  # Same snapshot but constraint does not cover latest (latest not newer — odd).
  # Still list when the constraint misses latest.
  NEED_DIFF=0
  if [[ "$NEWER" == "yes" || "$COVERS" != "yes" ]]; then
    NEED_DIFF=1
  fi

  if [[ "$NEED_DIFF" -eq 1 && "$NEWER" == "yes" ]]; then
    UP_DIR="$WORK_ROOT/upstream/${FOLDER}"
    GEN_DIR="$WORK_ROOT/gen/${FOLDER}"
    OLD_TREE="$WORK_ROOT/cmp/${FOLDER}/old"
    NEW_TREE="$WORK_ROOT/cmp/${FOLDER}/new"
    DIFF_OUT="$WORK_ROOT/cmp/${FOLDER}.diff"
    rm -rf "$UP_DIR" "$GEN_DIR" "$OLD_TREE" "$NEW_TREE"

    if download_upstream "$UPSTREAM" "$LATEST" "$UP_DIR" "$DIST_URL" "$SOURCE_URL"; then
      if run_generate_tyhpdef "$UP_DIR" "$GEN_DIR" "$LAYER1_FILE"; then
        copy_layer1_tree "$PKG_DIR/_tyhpdef" "$OLD_TREE"
        copy_layer1_tree "$GEN_DIR" "$NEW_TREE"
        set +e
        diff_trees "$OLD_TREE" "$NEW_TREE" "$DIFF_OUT"
        diff_rc=$?
        set -e
        if [[ "$diff_rc" -eq 0 ]]; then
          DIFF_FILE="$DIFF_OUT"
          DIFF_KIND="tyhpdef"
          TYHPDEF_CHANGED="yes"
        elif [[ "$diff_rc" -eq 1 ]]; then
          TYHPDEF_CHANGED="no"
        else
          echo "warning: diff failed for ${FOLDER} tyhpdefs" >&2
        fi
      else
        # Fallback: public PHP autoload sources
        LOCAL_PHP="$PKG_DIR/vendor/${VENDOR}/${PROJ}"
        if [[ ! -d "$LOCAL_PHP" ]]; then
          SNAP_DIR="$WORK_ROOT/snapshot/${FOLDER}"
          if download_upstream "$UPSTREAM" "$SNAPSHOT" "$SNAP_DIR" "" ""; then
            LOCAL_PHP="$SNAP_DIR"
          fi
        fi
        if [[ -d "$LOCAL_PHP" ]]; then
          PHP_OLD="$WORK_ROOT/php/${FOLDER}/old"
          PHP_NEW="$WORK_ROOT/php/${FOLDER}/new"
          rm -rf "$PHP_OLD" "$PHP_NEW"
          mkdir -p "$PHP_OLD" "$PHP_NEW"
          run_py shadow-php "$LOCAL_PHP" "$PHP_OLD" >/dev/null
          run_py shadow-php "$UP_DIR" "$PHP_NEW" >/dev/null
          set +e
          diff_trees "$PHP_OLD" "$PHP_NEW" "$DIFF_OUT"
          diff_rc=$?
          set -e
          if [[ "$diff_rc" -eq 0 ]]; then
            DIFF_FILE="$DIFF_OUT"
            DIFF_KIND="php"
            TYHPDEF_CHANGED="yes"
          elif [[ "$diff_rc" -eq 1 ]]; then
            TYHPDEF_CHANGED="no"
          fi
        else
          echo "warning: ${FOLDER}: generate_tyhpdef unavailable and no local vendor/${VENDOR}/${PROJ} for PHP fallback" >&2
        fi
      fi
    else
      echo "$FOLDER: download ${UPSTREAM} ${LATEST}" >> "$ERRORS_TMP"
      continue
    fi
    rm -rf "$UP_DIR" "$GEN_DIR"
  elif [[ "$NEED_DIFF" -eq 1 ]]; then
    # Constraint misses latest but latest is not newer than snapshot — no API
    # checkout to compare. Treat as constraint-only.
    TYHPDEF_CHANGED="no"
  fi

  if [[ "$COVERS" == "yes" && "$TYHPDEF_CHANGED" != "yes" ]]; then
    # Constraint covers and no tyhpdef-relevant change (or we could not prove
    # a change). Omit only when we positively saw no API diff.
    if [[ "$TYHPDEF_CHANGED" == "no" ]]; then
      continue
    fi
    # Unknown diff + covers: list so the user can inspect; do not silently omit.
    REASON="latest ${LATEST} is newer than snapshot ${SNAPSHOT}; could not produce a tyhpdef diff (constraint covers ${LATEST})"
  elif [[ "$COVERS" != "yes" && "$TYHPDEF_CHANGED" == "yes" ]]; then
    REASON="constraint does not include ${LATEST}; tyhpdef-relevant API changed"
  elif [[ "$COVERS" != "yes" && "$TYHPDEF_CHANGED" == "no" ]]; then
    REASON="no API diff but constraint does not include ${LATEST}"
  elif [[ "$COVERS" != "yes" ]]; then
    REASON="constraint does not include ${LATEST}"
  else
    REASON="tyhpdef-relevant API changed for ${LATEST} (constraint still covers it)"
  fi

  LISTED=$((LISTED + 1))
  if [[ "$JSON_OUT" -eq 1 ]]; then
    emit_json_row "$FOLDER" "$PKG_DIR" "$UPSTREAM" "$SNAPSHOT" "$TYHP_VERSION" \
      "$LATEST" "$CONSTRAINT" "$COVERS" "$TYHPDEF_CHANGED" "$DIFF_KIND" "$REASON" "$DIFF_FILE"
  else
    emit_human "$FOLDER" "$PKG_DIR" "$UPSTREAM" "$SNAPSHOT" "$TYHP_VERSION" \
      "$LATEST" "$CONSTRAINT" "$COVERS" "$REASON" "$DIFF_FILE" "$DIFF_KIND"
  fi
done < "$DISCOVER_TMP"

if [[ ${#ONLY_PACKAGES[@]} -gt 0 && "$CHECKED" -eq 0 ]]; then
  die "no Composer-lib package matched --only (${ONLY_PACKAGES[*]})"
fi

ERROR_COUNT=0
if [[ -s "$ERRORS_TMP" ]]; then
  ERROR_COUNT="$(python3 - "$ERRORS_TMP" <<'PY'
import sys
print(sum(1 for _ in open(sys.argv[1], encoding="utf-8")))
PY
)"
fi

if [[ "$JSON_OUT" -eq 1 ]]; then
  python3 - "$JSON_ROWS" "$CHECKED" "$ERRORS_TMP" <<'PY'
import json, sys
rows_path, checked, err_path = sys.argv[1], int(sys.argv[2]), sys.argv[3]
rows = []
with open(rows_path, encoding="utf-8") as fh:
    for line in fh:
        line = line.strip()
        if line:
            rows.append(json.loads(line))
errors = []
try:
    errors = [ln.strip() for ln in open(err_path, encoding="utf-8") if ln.strip()]
except OSError:
    pass
print(json.dumps({"outdated": rows, "checked": checked, "errors": errors}, indent=2, ensure_ascii=True))
print()
PY
else
  echo "Checked ${CHECKED} Composer-lib package(s); ${LISTED} outdated."
  if [[ "$ERROR_COUNT" -gt 0 ]]; then
    echo "Failed packages:" >&2
    cat "$ERRORS_TMP" >&2
  fi
fi

if [[ "$ERROR_COUNT" -gt 0 ]]; then
  exit 1
fi
if [[ "$LISTED" -gt 0 && "$QUIET_SUCCESS" -ne 1 ]]; then
  exit 1
fi
exit 0
