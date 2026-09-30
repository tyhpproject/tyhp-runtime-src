#!/usr/bin/env bash
# Idempotent README sections for published tyhpdef packages.
# Markers: <!-- tyhp-readme:start --> … <!-- tyhp-readme:end -->
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$SCRIPT_DIR"
PACKAGES_DIR="$REPO_ROOT/packages"
OWNERSHIP_ISSUE_URL="https://github.com/tyhpproject/tyhp-runtime-src/issues/new?template=tyhpdef-ownership.yml"
OWNERSHIP_DOC_URL="https://github.com/tyhpproject/tyhp-runtime-src"

DRY_RUN=0
ONLY_PACKAGE=""
ONLY_VERSION=""
EMIT="all"
OUT_DIR=""

usage() {
  cat <<'EOF'
Usage: write-package-readmes.sh [options]

Refresh README sections marked with <!-- tyhp-readme:start --> and
<!-- tyhp-readme:end -->.

  ./write-package-readmes.sh
  ./write-package-readmes.sh --package nesbot-carbon
  ./write-package-readmes.sh --dry-run

Skip core, async, decimal, lambda, and compiler. Composer-lib version folders
get the implementation README. php and php-ext-* get the first-party section.
Handed-off public metapackage text is written with --emit public or
--emit handed-off (used by generate-meta-package.sh).

Options:
  -h, --help              Show this help
  -n, --dry-run           Print README bodies; do not write files
  --package NAME          Limit to one folder (nesbot-carbon or nesbot/carbon)
  --version VER           With --emit, the upstream folder (default: every version)
  --emit MODE             all (default), impl, public, or handed-off
  --out DIR               With --emit public or handed-off, write README.md here
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
    --package)
      [[ $# -ge 2 ]] || die "--package requires a name"
      ONLY_PACKAGE="$2"
      shift 2
      ;;
    --package=*) ONLY_PACKAGE="${1#*=}"; shift ;;
    --version)
      [[ $# -ge 2 ]] || die "--version requires a value"
      ONLY_VERSION="$2"
      shift 2
      ;;
    --version=*) ONLY_VERSION="${1#*=}"; shift ;;
    --emit)
      [[ $# -ge 2 ]] || die "--emit requires a mode"
      EMIT="$2"
      shift 2
      ;;
    --emit=*) EMIT="${1#*=}"; shift ;;
    --out)
      [[ $# -ge 2 ]] || die "--out requires a directory"
      OUT_DIR="$2"
      shift 2
      ;;
    --out=*) OUT_DIR="${1#*=}"; shift ;;
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *) die "unexpected argument: $1" ;;
  esac
done

case "$EMIT" in
  all|impl|public|handed-off) ;;
  *) die "--emit must be all, impl, public, or handed-off" ;;
esac

export PACKAGES_DIR DRY_RUN ONLY_PACKAGE ONLY_VERSION EMIT OUT_DIR OWNERSHIP_ISSUE_URL OWNERSHIP_DOC_URL
python3 - <<'PY'
import json, os, sys

packages = os.environ["PACKAGES_DIR"]
dry = os.environ.get("DRY_RUN") == "1"
only = (os.environ.get("ONLY_PACKAGE") or "").strip().strip("/")
only_ver = (os.environ.get("ONLY_VERSION") or "").strip()
emit = os.environ.get("EMIT") or "all"
out_dir = os.environ.get("OUT_DIR") or ""
issue_url = os.environ["OWNERSHIP_ISSUE_URL"]
doc_url = os.environ["OWNERSHIP_DOC_URL"]
skip = {"core", "async", "decimal", "lambda", "compiler", "dist", "vendor"}

START = "<!-- tyhp-readme:start -->"
END = "<!-- tyhp-readme:end -->"

def die(msg):
    print(f"error: {msg}", file=sys.stderr)
    sys.exit(2)

def folder_name(name):
    name = name.strip().strip("/")
    if name.startswith("packages/"):
        name = name[len("packages/"):]
    if "/" in name and not os.path.isdir(os.path.join(packages, name.split("/")[0])):
        vendor, proj = name.split("/", 1)
        return f"{vendor}-{proj}"
    return name.split("/")[0]

def load_json(path):
    try:
        return json.load(open(path, encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}

def upstream_from(doc, folder):
    req = doc.get("require") if isinstance(doc.get("require"), dict) else {}
    for key in req:
        low = str(key).lower()
        if low == "php" or low.startswith("ext-") or low.startswith("tyhp/") or low.startswith("tyhpdef/"):
            continue
        if "/" in str(key):
            return str(key)
    want = folder.replace("-", "/", 1)
    return want

def names_for(doc, folder):
    raw = str(doc.get("name") or f"tyhpdef/{folder}")
    extra = doc.get("extra") if isinstance(doc.get("extra"), dict) else {}
    tyhp = extra.get("tyhp") if isinstance(extra.get("tyhp"), dict) else {}
    if raw.endswith("-impl"):
        impl = raw
        public = str(tyhp.get("public") or raw[: -len("-impl")])
    else:
        public = str(tyhp.get("public") or raw)
        impl = public + "-impl"
    ownership = tyhp.get("ownership") if isinstance(tyhp.get("ownership"), dict) else {}
    return public, impl, ownership

def splice(existing, block):
    start = existing.find(START)
    end = existing.find(END)
    if start != -1 and end != -1 and end > start:
        end_line = end + len(END)
        if end_line < len(existing) and existing[end_line] == "\n":
            end_line += 1
        return existing[:start] + block + existing[end_line:]
    if existing.strip():
        sep = "" if existing.endswith("\n\n") else "\n" if existing.endswith("\n") else "\n\n"
        return block + sep + existing
    return block

def write_readme(path, block):
    existing = ""
    if os.path.isfile(path):
        existing = open(path, encoding="utf-8").read()
    merged = splice(existing, block if block.endswith("\n") else block + "\n")
    if dry:
        print(f"# {path}")
        print(merged, end="" if merged.endswith("\n") else "\n")
        return
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    with open(path, "w", encoding="utf-8") as fh:
        fh.write(merged)
    print(f"wrote {path}")

def impl_block(public, impl, upstream):
    return f"""{START}
# {impl}

Implementation package for **`{public}`**.

Do not `composer require` this package in an application. Require
`{public}` (same version as `{upstream}`). This package exists so
tyhpproject can ship type-only revisions (`{upstream}.1`, …) without
changing the public version.

Type files are under `_tyhpdef/`. Package maintainers of `{upstream}`
who want to ship their own types should copy that directory; see the
`{public}` README.
{END}
"""

def public_block(public, impl, upstream, version):
    return f"""{START}
# {public}

Tyhp type definitions for `{upstream}` `{version}`.

```bash
composer require --dev {public}:{version}
```

This is a metapackage. Composer also installs `{impl}` (type files).
Require **this** name, not `{impl}`.

See https://tyhplang.com.

## Maintain `{upstream}`? Ship the types yourself

If you are a Packagist maintainer of `{upstream}`, you can take over these
types.

Copy `_tyhpdef/` from **`{impl}`** (Apache-2.0; keep the `NOTICE`).
Then either:

1. **Bundle** the files in `{upstream}` and set `extra.tyhp.package` on
   that `composer.json`, plus
   `"replace": {{ "{public}": "self.version" }}`, or
2. **Publish a sibling** types package under your vendor, versioned with
   `{upstream}` (same `X.Y.Z`). Set `extra.tyhp.package` there,
   `require` `{upstream}` with a real constraint,
   `"replace": {{ "{public}": "self.version" }}`, and set
   `extra.tyhp.tyhpdef` on `{upstream}` to your sibling’s Composer name.

Ship that to Packagist first, then open an issue:

{issue_url}

We verify Packagist ownership and that the types parse and cover the PHP
API, then stop publishing community tags for those versions. We do not
transfer the `{public}` Packagist name.

Full process: `TYHPDEF_OWNERSHIP.md` in
{doc_url}
{END}
"""

def handed_block(public, upstream, version, target):
    return f"""{START}
# {public}

Metapackage. Tyhp type definitions for `{upstream}` `{version}+`
now ship in **{target}**. This name exists so existing
`require-dev {public}` constraints keep resolving.

You can drop `{public}` from `require-dev` if `{target}` is
already installed and declares `extra.tyhp.package`.
{END}
"""

def first_party_block(composer_name, ext_name):
    if composer_name == "tyhpdef/php" or not ext_name:
        title = "tyhpdef/php"
        body = (
            "Tyhp type definitions for PHP's always-present built-ins.\n\n"
            "Install in `require-dev`. See https://tyhplang.com.\n\n"
            "This package is maintained by tyhpproject. These built-in "
            "definitions are not transferred through the Composer-lib ownership process."
        )
    else:
        title = f"tyhpdef/php-ext-{ext_name}"
        body = (
            f"Tyhp type definitions for the PHP extension `{ext_name}`.\n\n"
            "Install in `require-dev`. See https://tyhplang.com and `tyhpdef/php`.\n\n"
            "This package is maintained by tyhpproject. PHP extension wrappers are\n"
            "not transferred through the Composer-lib ownership process."
        )
    return f"{START}\n# {title}\n\n{body}\n{END}\n"

if only:
    only = folder_name(only)

def version_dirs(parent):
    rows = []
    try:
        children = sorted(os.listdir(parent))
    except OSError:
        return rows
    for ver in children:
        vdir = os.path.join(parent, ver)
        if os.path.isfile(os.path.join(vdir, "composer.json")):
            rows.append((ver, vdir))
    return rows

def handle_version(folder, ver, vdir, doc):
    if only_ver and ver != only_ver:
        return
    public, impl, ownership = names_for(doc, folder)
    upstream = upstream_from(doc, folder)
    handed = str(ownership.get("status") or "") == "handed-off"
    target = str(ownership.get("target") or upstream)
    if emit in {"all", "impl"} and not out_dir:
        write_readme(os.path.join(vdir, "README.md"), impl_block(public, impl, upstream))
    if emit == "public" or (emit == "handed-off") or (emit == "all" and out_dir):
        block = handed_block(public, upstream, ver, target) if (handed or emit == "handed-off") else public_block(public, impl, upstream, ver)
        dest_dir = out_dir or vdir
        if emit in {"public", "handed-off"} and not out_dir:
            die("--out is required with --emit public or --emit handed-off")
        if emit == "all" and out_dir:
            write_readme(os.path.join(out_dir, "README.md"), block)
        elif emit in {"public", "handed-off"}:
            write_readme(os.path.join(dest_dir, "README.md"), block)

if not os.path.isdir(packages):
    die(f"packages directory not found: {packages}")

names = sorted(os.listdir(packages), key=str.lower)
if only:
    names = [n for n in names if n == only]
    if not names:
        die(f"package folder not found: {only}")

for name in names:
    if name in skip or name.startswith("."):
        continue
    parent = os.path.join(packages, name)
    if not os.path.isdir(parent):
        continue
    flat = os.path.join(parent, "composer.json")
    if os.path.isfile(flat) and name.startswith("php-ext-"):
        if emit not in {"all", "impl"} and emit != "public":
            if emit in {"public", "handed-off"}:
                continue
        if emit in {"public", "handed-off"}:
            continue
        ext = name[len("php-ext-"):]
        doc = load_json(flat)
        composer_name = str(doc.get("name") or f"tyhpdef/{name}")
        write_readme(os.path.join(parent, "README.md"), first_party_block(composer_name, ext))
        continue
    if os.path.isfile(flat) and name == "php":
        if emit in {"public", "handed-off"}:
            continue
        doc = load_json(flat)
        composer_name = str(doc.get("name") or "tyhpdef/php")
        write_readme(os.path.join(parent, "README.md"), first_party_block(composer_name, ""))
        continue
    if os.path.isfile(flat):
        continue
    for ver, vdir in version_dirs(parent):
        doc = load_json(os.path.join(vdir, "composer.json"))
        handle_version(name, ver, vdir, doc)
PY
