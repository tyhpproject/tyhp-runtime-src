#!/usr/bin/env bash
# Read Packagist extras for Composer-lib impl trees and classify owner signals.
# Does not hand off, commit, open a pull request, or publish.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$SCRIPT_DIR"
PACKAGES_DIR="$REPO_ROOT/packages"
USER_AGENT="tyhp-scan-upstream-tyhpdefs (+https://github.com/tyhpproject/tyhp-runtime-src)"
CACHE_DIR="${TYHP_SCAN_CACHE:-$REPO_ROOT/.packagist-scan-cache}"

JSON_OUT=""
MD_OUT=""
ONLY=""

usage() {
  cat <<'EOF'
Usage: scan-upstream-tyhpdefs.sh [options]

Classify Packagist extras for versioned Composer-lib impl trees.

  ./scan-upstream-tyhpdefs.sh --json report.json --markdown report.md
  ./scan-upstream-tyhpdefs.sh --only nesbot/carbon

Skips php, php-ext-*, and compiled tyhp/* (core, async, decimal, lambda,
compiler). Fetches https://packagist.org/packages/{vendor}/{name}.json
one package at a time. HTTP 200 bodies are cached under .packagist-scan-cache/
for this run (override with TYHP_SCAN_CACHE). The cache is not committed.

Classifications: bundled, sibling, replace-only, none, handed-off.
When both bundled and sibling signals exist, bundled wins and the sibling
name is still recorded.

Exit 0 when the scan finishes, including when signals exist.
Exit non-zero only on usage, HTTP, or JSON parse failures.

Options:
  -h, --help
  --json FILE         Write a JSON report
  --markdown FILE     Write a Markdown report (rolling issue body)
  --only PKG          Limit to one PHP package (vendor/name or vendor-name)
EOF
}

die() {
  echo "error: $*" >&2
  exit 2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --json)
      [[ $# -ge 2 ]] || die "--json requires a file"
      JSON_OUT="$2"
      shift 2
      ;;
    --json=*) JSON_OUT="${1#*=}"; shift ;;
    --markdown)
      [[ $# -ge 2 ]] || die "--markdown requires a file"
      MD_OUT="$2"
      shift 2
      ;;
    --markdown=*) MD_OUT="${1#*=}"; shift ;;
    --only)
      [[ $# -ge 2 ]] || die "--only requires a package"
      ONLY="$2"
      shift 2
      ;;
    --only=*) ONLY="${1#*=}"; shift ;;
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *) die "unexpected argument: $1" ;;
  esac
done

export PACKAGES_DIR USER_AGENT CACHE_DIR JSON_OUT MD_OUT ONLY
python3 - <<'PY'
import json, os, sys, urllib.error, urllib.parse, urllib.request

packages = os.environ["PACKAGES_DIR"]
ua = os.environ["USER_AGENT"]
cache_dir = os.environ["CACHE_DIR"]
json_out = os.environ.get("JSON_OUT") or ""
md_out = os.environ.get("MD_OUT") or ""
only = (os.environ.get("ONLY") or "").strip()
skip_names = {"core", "async", "decimal", "lambda", "compiler", "dist", "vendor", "php"}
failures = []

def die(msg, code=1):
    print(f"error: {msg}", file=sys.stderr)
    sys.exit(code)

def version_key(ver):
    core = ver.split("+", 1)[0]
    if core[:1].lower() == "v" and len(core) > 1 and core[1].isdigit():
        core = core[1:]
    numeric, _, pre = core.partition("-")
    parts = []
    for piece in numeric.split("."):
        if piece.isdigit():
            parts.append(int(piece))
        else:
            return None
    return parts, pre

def is_stable(ver):
    parsed = version_key(ver)
    if not parsed:
        return False
    _parts, pre = parsed
    return pre == "" and not ver.lower().startswith("dev-")

def folder_matches(folder, upstream, needle):
    if not needle:
        return True
    n = needle.strip().lower()
    if n == folder.lower() or n == upstream.lower():
        return True
    if "/" in n:
        vendor, proj = n.split("/", 1)
        return folder.lower() == f"{vendor}-{proj}"
    return False

def upstream_of(doc, folder):
    extra = doc.get("extra") if isinstance(doc.get("extra"), dict) else {}
    tyhp = extra.get("tyhp") if isinstance(extra.get("tyhp"), dict) else {}
    req = doc.get("require") if isinstance(doc.get("require"), dict) else {}
    for key, val in req.items():
        low = str(key).lower()
        if low == "php" or low.startswith("ext-") or low.startswith("tyhp/") or low.startswith("tyhpdef/"):
            continue
        if "/" in str(key):
            return str(key), str(val)
    return folder.replace("-", "/", 1), folder

def public_name(doc, folder):
    raw = str(doc.get("name") or f"tyhpdef/{folder}")
    extra = doc.get("extra") if isinstance(doc.get("extra"), dict) else {}
    tyhp = extra.get("tyhp") if isinstance(extra.get("tyhp"), dict) else {}
    if raw.endswith("-impl"):
        return str(tyhp.get("public") or raw[: -len("-impl")])
    return str(tyhp.get("public") or raw)

def classify(version_doc, public):
    if not isinstance(version_doc, dict):
        return "none", ""
    extra_root = version_doc.get("extra") if isinstance(version_doc.get("extra"), dict) else {}
    tyhp = extra_root.get("tyhp") if isinstance(extra_root.get("tyhp"), dict) else {}
    package = tyhp.get("package")
    sibling = tyhp.get("tyhpdef")
    bundled = isinstance(package, dict) and len(package) > 0
    sib = sibling.strip() if isinstance(sibling, str) else ""
    replace = version_doc.get("replace") if isinstance(version_doc.get("replace"), dict) else {}
    replaced = public in replace
    if bundled:
        return "bundled", sib
    if sib:
        return "sibling", sib
    if replaced:
        return "replace-only", ""
    return "none", ""

def cache_path(vendor, name):
    safe = f"{vendor}--{name}.json"
    return os.path.join(cache_dir, safe)

def fetch_package(vendor, name):
    path = cache_path(vendor, name)
    if os.path.isfile(path):
        try:
            return json.load(open(path, encoding="utf-8"))
        except json.JSONDecodeError as exc:
            failures.append(f"parse cache {vendor}/{name}: {exc}")
            return None
    url = "https://packagist.org/packages/{}/{}.json".format(
        urllib.parse.quote(vendor, safe=""),
        urllib.parse.quote(name, safe=""),
    )
    req = urllib.request.Request(url, headers={"User-Agent": ua, "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            code = resp.getcode()
            body = resp.read()
            if code != 200:
                failures.append(f"HTTP {code} for {vendor}/{name}")
                return None
    except urllib.error.HTTPError as err:
        failures.append(f"HTTP {err.code} for {vendor}/{name}")
        return None
    except urllib.error.URLError as err:
        failures.append(f"HTTP error for {vendor}/{name}: {err}")
        return None
    try:
        doc = json.loads(body.decode("utf-8"))
    except json.JSONDecodeError as exc:
        failures.append(f"parse {vendor}/{name}: {exc}")
        return None
    os.makedirs(cache_dir, exist_ok=True)
    with open(path, "wb") as fh:
        fh.write(body)
    return doc

def latest_stable(versions):
    best = None
    best_key = None
    for ver, doc in versions.items():
        if not is_stable(ver):
            continue
        key = version_key(ver)
        if key is None:
            continue
        if best_key is None or key > best_key:
            best_key = key
            best = ver
            _ = doc
    return best

rows = []
if not os.path.isdir(packages):
    die(f"packages directory not found: {packages}", 2)

for name in sorted(os.listdir(packages), key=str.lower):
    if name in skip_names or name.startswith("php-ext-") or name.startswith("."):
        continue
    parent = os.path.join(packages, name)
    if not os.path.isdir(parent) or os.path.isfile(os.path.join(parent, "composer.json")):
        continue
    try:
        children = sorted(os.listdir(parent))
    except OSError:
        continue
    for ver in children:
        vdir = os.path.join(parent, ver)
        cj = os.path.join(vdir, "composer.json")
        if not os.path.isfile(cj):
            continue
        try:
            doc = json.load(open(cj, encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            failures.append(f"parse {cj}: {exc}")
            continue
        upstream, pin = upstream_of(doc, name)
        if not folder_matches(name, upstream, only):
            continue
        extra = doc.get("extra") if isinstance(doc.get("extra"), dict) else {}
        tyhp = extra.get("tyhp") if isinstance(extra.get("tyhp"), dict) else {}
        ownership = tyhp.get("ownership") if isinstance(tyhp.get("ownership"), dict) else {}
        handed = str(ownership.get("status") or "") == "handed-off"
        public = public_name(doc, name)
        if "/" not in upstream:
            failures.append(f"upstream name {upstream!r} in {cj}")
            continue
        vendor, proj = upstream.split("/", 1)
        payload = fetch_package(vendor, proj)
        pinned_class, pinned_sib = "none", ""
        latest_name = ""
        latest_class, latest_sib = "none", ""
        if payload is None:
            # Fetch already recorded an HTTP or parse failure. Do not call that "none".
            if not handed:
                continue
        elif not isinstance(payload.get("package"), dict):
            failures.append(f"parse package object for {upstream}")
            if not handed:
                continue
        else:
            versions = payload["package"].get("versions") or {}
            if not isinstance(versions, dict):
                failures.append(f"parse versions for {upstream}")
                versions = {}
            pinned_doc = versions.get(ver) or versions.get(pin) or {}
            pinned_class, pinned_sib = classify(pinned_doc, public)
            latest_name = latest_stable(versions) or ""
            if latest_name:
                latest_class, latest_sib = classify(versions.get(latest_name) or {}, public)
        classification = "handed-off" if handed else pinned_class
        rows.append({
            "folder": name,
            "version": ver,
            "upstream": upstream,
            "pin": pin,
            "public": public,
            "classification": classification,
            "sibling": pinned_sib,
            "pinnedClassification": pinned_class,
            "latestStable": latest_name,
            "latestClassification": latest_class,
            "latestSibling": latest_sib,
        })

report = {"packages": rows, "errors": failures}
text = json.dumps(report, indent=2) + "\n"
if json_out:
    with open(json_out, "w", encoding="utf-8") as fh:
        fh.write(text)
else:
    print(text, end="")

def md_table(items):
    lines = [
        "# Upstream tyhpdef signals",
        "",
        "Scheduled scan of Packagist `extra.tyhp` on PHP packages wrapped by this tree.",
        "This report does not hand off packages.",
        "",
        "| Package | Upstream | Classification | Sibling | Latest stable | Latest signal |",
        "| --- | --- | --- | --- | --- | --- |",
    ]
    for row in items:
        sib = row["sibling"] or row["latestSibling"] or ""
        lines.append(
            "| `{folder}/{version}` | `{upstream}` | {classification} | {sib} | {latest} | {latest_c} |".format(
                folder=row["folder"],
                version=row["version"],
                upstream=row["upstream"],
                classification=row["classification"],
                sib=sib or "—",
                latest=row["latestStable"] or "—",
                latest_c=row["latestClassification"] or "—",
            )
        )
    if failures:
        lines.append("")
        lines.append("## Fetch errors")
        lines.append("")
        for item in failures:
            lines.append(f"- {item}")
    lines.append("")
    return "\n".join(lines)

md = md_table(rows)
if md_out:
    with open(md_out, "w", encoding="utf-8") as fh:
        fh.write(md)
elif not json_out:
    pass
else:
    sys.stderr.write(md)

if failures:
    for item in failures:
        print(f"error: {item}", file=sys.stderr)
    sys.exit(1)
sys.exit(0)
PY
