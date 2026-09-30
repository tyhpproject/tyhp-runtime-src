#!/usr/bin/env bash
# Write the public metapackage (composer.json, README.md, LICENSE) from an
# implementation version folder. Publish tags the public repo from this output.
#
# Public require of the impl is ~{numeric-core}.0 on a stable upstream version.
# Prerelease upstream versions require the exact impl version.
# Handed-off source folders emit the post-handoff metapackage (no impl require).
#
# Skip a new public tag when that public version already exists and the
# generated impl require range is unchanged (impl-only revision).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$SCRIPT_DIR"
PACKAGES_DIR="$REPO_ROOT/packages"

DRY_RUN=0
OUT_DIR=""
EXISTING_PUBLIC=""
FOLDER=""

usage() {
  cat <<'EOF'
Usage: generate-meta-package.sh [options] <vendor-name>/<upstream>

Write the public tyhpdef metapackage from an impl version folder.

  ./generate-meta-package.sh nesbot-carbon/3.14.0
  ./generate-meta-package.sh --dry-run nesbot-carbon/3.14.0

The argument is packages/<vendor>-<name>/<upstream>/ (the packages/ prefix
is optional). Public version is that upstream folder name. Public require of
the impl is ~{numeric-core}.0 when the upstream version is stable, and the
exact impl version when it is a prerelease.

Options:
  -h, --help                 Show this help
  -n, --dry-run              Print the public composer.json; do not write files
  --out DIR                  Write composer.json, README.md, and LICENSE here
  --existing-public FILE     If this composer.json is already the same public
                             version and the generated impl require is unchanged,
                             print "public-tag: skip" and do not write files

Exit status:
  0  wrote the metapackage, or skipped an unchanged public tag
  2  usage or missing impl folder
EOF
}

die() {
  echo "error: $*" >&2
  exit 2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    -n|--dry-run) DRY_RUN=1; shift ;;
    --out)
      [[ $# -ge 2 ]] || die "--out requires a directory"
      OUT_DIR="$2"
      shift 2
      ;;
    --out=*) OUT_DIR="${1#*=}"; shift ;;
    --existing-public)
      [[ $# -ge 2 ]] || die "--existing-public requires a composer.json path"
      EXISTING_PUBLIC="$2"
      shift 2
      ;;
    --existing-public=*) EXISTING_PUBLIC="${1#*=}"; shift ;;
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *)
      [[ -z "$FOLDER" ]] || die "unexpected argument: $1"
      FOLDER="$1"
      shift
      ;;
  esac
done

[[ -n "$FOLDER" ]] || die "missing impl version folder (example: nesbot-carbon/3.14.0)
$(usage)"

FOLDER="${FOLDER%/}"
FOLDER="${FOLDER#packages/}"
IMPL_DIR="$PACKAGES_DIR/$FOLDER"
[[ -f "$IMPL_DIR/composer.json" ]] || die "impl composer.json not found: $IMPL_DIR/composer.json"

if [[ -z "$OUT_DIR" ]]; then
  OUT_DIR="$REPO_ROOT/.public-meta/$FOLDER"
fi

export IMPL_DIR OUT_DIR EXISTING_PUBLIC DRY_RUN REPO_ROOT
python3 - <<'PY'
import json, os, shutil, sys

impl_dir = os.environ["IMPL_DIR"]
out_dir = os.environ["OUT_DIR"]
existing_path = os.environ.get("EXISTING_PUBLIC") or ""
dry = os.environ.get("DRY_RUN") == "1"
repo_root = os.environ["REPO_ROOT"]
folder = os.path.basename(impl_dir.rstrip("/"))

def die(msg):
    print(f"error: {msg}", file=sys.stderr)
    sys.exit(2)

try:
    src = json.load(open(os.path.join(impl_dir, "composer.json"), encoding="utf-8"))
except (OSError, json.JSONDecodeError) as exc:
    die(f"cannot read impl composer.json: {exc}")

extra = src.get("extra") if isinstance(src.get("extra"), dict) else {}
tyhp = extra.get("tyhp") if isinstance(extra.get("tyhp"), dict) else {}
ownership = tyhp.get("ownership") if isinstance(tyhp.get("ownership"), dict) else {}
raw_name = str(src.get("name") or "").strip()
if not raw_name.startswith("tyhpdef/"):
    die(f"impl name must be tyhpdef/* (got {raw_name!r})")

if raw_name.endswith("-impl"):
    impl_name = raw_name
    public_name = str(tyhp.get("public") or raw_name[: -len("-impl")]).strip()
else:
    public_name = str(tyhp.get("public") or raw_name).strip()
    impl_name = public_name + "-impl"

upstream_ver = folder
if not upstream_ver:
    die("upstream folder name is empty")

def split_pre(ver):
    core, plus, _build = ver.partition("+")
    if plus and not _build:
        die(f"empty +build on {ver}")
    if core.lower().startswith("v") and len(core) > 1 and core[1].isdigit():
        core = core[1:]
    numeric, sep, pre = core.partition("-")
    return numeric, pre if sep else ""

numeric, pre = split_pre(upstream_ver)
impl_version = str(src.get("version") or "").strip()
if not impl_version:
    die("impl composer.json is missing version")

req = src.get("require") if isinstance(src.get("require"), dict) else {}
php_constraint = str(req.get("php") or ">=8.2")
upstream_pkg = None
for key in req:
    low = str(key).lower()
    if low in {"php"} or low.startswith("ext-") or low.startswith("tyhp/") or low.startswith("tyhpdef/"):
        continue
    if "/" in str(key):
        upstream_pkg = str(key)
        break
if not upstream_pkg:
    upstream_pkg = public_name[len("tyhpdef/"):].replace("-", "/", 1)

handed = str(ownership.get("status") or "") == "handed-off"
scenario = str(ownership.get("scenario") or "")
target = str(ownership.get("target") or upstream_pkg)
from_up = str(ownership.get("fromUpstream") or upstream_ver)

def caret_minor(ver):
    numeric_v, _pre = split_pre(ver)
    parts = numeric_v.split(".")
    major = parts[0] if parts else "0"
    minor = parts[1] if len(parts) > 1 else "0"
    return f"^{major}.{minor}"

if handed and scenario == "sibling":
    public_require = {"php": php_constraint, target: caret_minor(from_up)}
    abandoned = target
    description = f"Metapackage: Tyhp types for {upstream_pkg} {from_up}+ ship in {target}."
elif handed:
    public_require = {"php": php_constraint}
    abandoned = target
    description = f"Metapackage: Tyhp types for {upstream_pkg} {from_up}+ ship in {target}."
else:
    if pre:
        impl_require = impl_version
    else:
        impl_require = f"~{numeric}.0"
    public_require = {impl_name: impl_require}
    abandoned = None
    description = f"Tyhp type definitions for {upstream_pkg} {upstream_ver}."

public = {
    "name": public_name,
    "description": description,
    "type": "metapackage",
    "license": "Apache-2.0",
    "version": upstream_ver,
}
if not handed:
    public["keywords"] = ["dev", "static analysis"]
if abandoned:
    public["abandoned"] = abandoned
public["require"] = public_require
if handed:
    public["extra"] = {
        "tyhp": {
            "ownership": {
                "status": "handed-off",
                "scenario": scenario or "bundled",
                "target": target,
                "fromUpstream": from_up,
            }
        }
    }
else:
    public["extra"] = {"tyhp": {"impl": impl_name}}

text = json.dumps(public, indent=4, ensure_ascii=True) + "\n"

skip = False
if existing_path:
    try:
        old = json.load(open(existing_path, encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        die(f"cannot read --existing-public: {exc}")
    old_ver = str(old.get("version") or "")
    old_req = old.get("require") if isinstance(old.get("require"), dict) else {}
    same_ver = old_ver == upstream_ver
    same_range = old_req == public_require
    if same_ver and same_range:
        skip = True

if skip:
    print("public-tag: skip")
    print(text, end="")
    sys.exit(0)

print("public-tag: emit")
if dry:
    print(text, end="")
    sys.exit(0)

os.makedirs(out_dir, exist_ok=True)
with open(os.path.join(out_dir, "composer.json"), "w", encoding="utf-8") as fh:
    fh.write(text)

license_src = os.path.join(impl_dir, "LICENSE")
if not os.path.isfile(license_src):
    license_src = os.path.join(repo_root, "LICENSE.txt")
if os.path.isfile(license_src):
    shutil.copyfile(license_src, os.path.join(out_dir, "LICENSE"))

readme_cmd = [
    os.path.join(repo_root, "write-package-readmes.sh"),
    "--emit",
    "handed-off" if handed else "public",
    "--package",
    os.path.basename(os.path.dirname(impl_dir)),
    "--version",
    upstream_ver,
    "--out",
    out_dir,
]
import subprocess
rc = subprocess.call(readme_cmd)
if rc != 0:
    sys.exit(rc)
print(f"wrote {out_dir}")
PY
