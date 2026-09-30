#!/usr/bin/env bash
# Stamp extra.tyhp.ownership on impl source folders and refresh READMEs.
# Does not retag or rewrite Packagist versions that are already published.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$SCRIPT_DIR"
PACKAGES_DIR="$REPO_ROOT/packages"

DRY_RUN=0
SCENARIO=""
PACKAGE=""
TARGET=""
FROM_VERSION=""
ONE_VERSION=""
VERSIONS=""

usage() {
  cat <<'EOF'
Usage:
  handoff-tyhpdef.sh --scenario bundled --package vendor/name --from-version 3.14.0
  handoff-tyhpdef.sh --scenario sibling --package vendor/name --target vendor/name-tyhpdef --from-version 3.14.0
  handoff-tyhpdef.sh --scenario reclaim --package vendor/name --version 3.14.0

--from-version is the lowest upstream folder. Later folders of the same major
convert together unless --versions lists them (comma-separated).

--dry-run prints the composer.json and README that would be written.
This script updates source only. It does not retag published Packagist versions.

Options:
  --scenario bundled|sibling|reclaim
  --package vendor/name
  --target NAME          Sibling Composer name (scenario sibling)
  --from-version VER     Lowest upstream folder for bundled or sibling
  --version VER          Single folder for reclaim
  --versions LIST        Comma-separated upstream folders (overrides same-major walk)
  -n, --dry-run
  -h, --help
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
    --scenario)
      [[ $# -ge 2 ]] || die "--scenario requires a value"
      SCENARIO="$2"
      shift 2
      ;;
    --scenario=*) SCENARIO="${1#*=}"; shift ;;
    --package)
      [[ $# -ge 2 ]] || die "--package requires vendor/name"
      PACKAGE="$2"
      shift 2
      ;;
    --package=*) PACKAGE="${1#*=}"; shift ;;
    --target)
      [[ $# -ge 2 ]] || die "--target requires a Composer name"
      TARGET="$2"
      shift 2
      ;;
    --target=*) TARGET="${1#*=}"; shift ;;
    --from-version)
      [[ $# -ge 2 ]] || die "--from-version requires a version"
      FROM_VERSION="$2"
      shift 2
      ;;
    --from-version=*) FROM_VERSION="${1#*=}"; shift ;;
    --version)
      [[ $# -ge 2 ]] || die "--version requires a version"
      ONE_VERSION="$2"
      shift 2
      ;;
    --version=*) ONE_VERSION="${1#*=}"; shift ;;
    --versions)
      [[ $# -ge 2 ]] || die "--versions requires a list"
      VERSIONS="$2"
      shift 2
      ;;
    --versions=*) VERSIONS="${1#*=}"; shift ;;
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *) die "unexpected argument: $1" ;;
  esac
done

[[ -n "$PACKAGE" && "$PACKAGE" == */* && "$PACKAGE" != */*/* ]] || die "--package must be vendor/name"
case "$SCENARIO" in
  bundled|sibling|reclaim) ;;
  *) die "--scenario must be bundled, sibling, or reclaim" ;;
esac
if [[ "$SCENARIO" == "sibling" && -z "$TARGET" ]]; then
  die "--scenario sibling requires --target"
fi
if [[ "$SCENARIO" == "reclaim" ]]; then
  [[ -n "$ONE_VERSION" || -n "$VERSIONS" ]] || die "--scenario reclaim requires --version"
else
  [[ -n "$FROM_VERSION" || -n "$VERSIONS" ]] || die "--from-version is required"
fi

export DRY_RUN SCENARIO PACKAGE TARGET FROM_VERSION ONE_VERSION VERSIONS PACKAGES_DIR REPO_ROOT
python3 - <<'PY'
import json, os, subprocess, sys

dry = os.environ.get("DRY_RUN") == "1"
scenario = os.environ["SCENARIO"]
package = os.environ["PACKAGE"]
target = os.environ.get("TARGET") or ""
from_version = os.environ.get("FROM_VERSION") or ""
one_version = os.environ.get("ONE_VERSION") or ""
versions_raw = os.environ.get("VERSIONS") or ""
packages = os.environ["PACKAGES_DIR"]
repo = os.environ["REPO_ROOT"]

def die(msg):
    print(f"error: {msg}", file=sys.stderr)
    sys.exit(2)

folder = package.replace("/", "-")
parent = os.path.join(packages, folder)
if not os.path.isdir(parent):
    die(f"package folder not found: {parent}")

def parse(ver):
    core = ver.split("+", 1)[0]
    if core[:1].lower() == "v" and len(core) > 1 and core[1].isdigit():
        core = core[1:]
    numeric, _, pre = core.partition("-")
    parts = []
    for piece in numeric.split("."):
        if piece.isdigit():
            parts.append(int(piece))
        else:
            parts.append(-1)
    return tuple(parts), pre

def major(ver):
    parsed, _pre = parse(ver)
    return parsed[0] if parsed else None

discovered = []
for name in sorted(os.listdir(parent)):
    cj = os.path.join(parent, name, "composer.json")
    if os.path.isfile(cj):
        discovered.append(name)

listed = [v.strip() for v in versions_raw.split(",") if v.strip()] if versions_raw else []
selected = []
if scenario == "reclaim":
    if listed:
        selected = listed
    else:
        selected = [one_version]
else:
    if listed:
        selected = listed
    else:
        floor, _ = parse(from_version)
        maj = major(from_version)
        for ver in discovered:
            key, _ = parse(ver)
            if major(ver) == maj and key >= floor:
                selected.append(ver)

if not selected:
    die("no version folders selected")

ownership_target = package if scenario == "bundled" else target

def stamp(doc, ver):
    extra = doc.get("extra")
    if not isinstance(extra, dict):
        extra = {}
        doc["extra"] = extra
    tyhp = extra.get("tyhp")
    if not isinstance(tyhp, dict):
        tyhp = {}
        extra["tyhp"] = tyhp
    if scenario == "reclaim":
        tyhp.pop("ownership", None)
        doc.pop("abandoned", None)
        return doc
    tyhp["ownership"] = {
        "status": "handed-off",
        "scenario": scenario,
        "target": ownership_target,
        "fromUpstream": from_version or ver,
    }
    return doc

for ver in selected:
    path = os.path.join(parent, ver, "composer.json")
    if not os.path.isfile(path):
        die(f"missing {path}")
    doc = json.load(open(path, encoding="utf-8"))
    stamp(doc, ver)
    text = json.dumps(doc, indent=4, ensure_ascii=True) + "\n"
    readme_args = [
        os.path.join(repo, "write-package-readmes.sh"),
        "--package", folder,
        "--version", ver,
        "--emit", "impl",
        "--dry-run",
    ]
    proc = subprocess.run(readme_args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if proc.returncode != 0:
        print(proc.stderr, file=sys.stderr)
        sys.exit(proc.returncode)
    if dry:
        print(f"# {path}")
        print(text, end="")
        print(proc.stdout, end="" if proc.stdout.endswith("\n") else "\n")
        continue
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(text)
    write = subprocess.run(
        [
            os.path.join(repo, "write-package-readmes.sh"),
            "--package", folder,
            "--version", ver,
        ],
        check=False,
    )
    if write.returncode != 0:
        sys.exit(write.returncode)
    print(f"stamped {folder}/{ver} ({scenario}); source only, no Packagist retag")

if scenario == "reclaim":
    print("Reclaim clears extra.tyhp.ownership in source. Publish a new impl revision separately. Existing public tags are not rewritten.")
else:
    print("Handoff stamped source. Do not retag public versions already on Packagist. A public version that has never been tagged picks up the handed-off metapackage on the next publish. Impl tags for these folders stop.")
PY
