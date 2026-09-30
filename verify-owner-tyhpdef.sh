#!/usr/bin/env bash
# Quality gate required before handoff-tyhpdef.sh.
# Not invoked by the scheduled Packagist scan.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$SCRIPT_DIR"
PACKAGES_DIR="$REPO_ROOT/packages"
COMPILER_ROOT="$(cd "$REPO_ROOT/../tyhp" 2>/dev/null && pwd || true)"
TYHP_DLL="${TYHP_DLL:-${COMPILER_ROOT:+$COMPILER_ROOT/bin/Debug/net9.0/tyhp.dll}}"
USER_AGENT="tyhp-verify-owner-tyhpdef (+https://github.com/tyhpproject/tyhp-runtime-src)"

PACKAGE=""
SCENARIO=""
TARGET=""
FROM_VERSION=""

usage() {
  cat <<'EOF'
Usage: verify-owner-tyhpdef.sh --package vendor/name --scenario bundled
       verify-owner-tyhpdef.sh --package vendor/name --scenario sibling --target vendor/name-tyhpdef

Required before handoff-tyhpdef.sh. Downloads the owner tree with
Composer --no-scripts --no-plugins. Refuses composer-plugin packages and
install/update scripts. Runs tyhp lint and generate_tyhpdef --verify.

Options:
  --package vendor/name     PHP package on Packagist
  --scenario bundled|sibling
  --target NAME             Sibling Composer name (scenario sibling)
  --from-version VER        Upstream version to download (default: newest stable on Packagist)
  -h, --help

TYHP_DLL overrides the compiler used for lint and --verify.
Overlay omit is not a verify failure (the compiler treats omit as compatible).
tests/fail from the local impl version are run against the owner tree.
Those diffs are advisory and do not change the exit status.
EOF
}

die() {
  echo "error: $*" >&2
  exit 2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --package)
      [[ $# -ge 2 ]] || die "--package requires vendor/name"
      PACKAGE="$2"
      shift 2
      ;;
    --package=*) PACKAGE="${1#*=}"; shift ;;
    --scenario)
      [[ $# -ge 2 ]] || die "--scenario requires bundled or sibling"
      SCENARIO="$2"
      shift 2
      ;;
    --scenario=*) SCENARIO="${1#*=}"; shift ;;
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
    --) shift; break ;;
    -*) die "unknown option: $1" ;;
    *) die "unexpected argument: $1" ;;
  esac
done

[[ -n "$PACKAGE" && "$PACKAGE" == */* ]] || die "--package must be vendor/name"
case "$SCENARIO" in
  bundled|sibling) ;;
  *) die "--scenario must be bundled or sibling" ;;
esac
if [[ "$SCENARIO" == "sibling" && -z "$TARGET" ]]; then
  die "--scenario sibling requires --target"
fi

if [[ -z "${TYHP_DLL}" || ! -f "$TYHP_DLL" ]]; then
  die "tyhp compiler not found at: ${TYHP_DLL:-<unset>}
Set TYHP_DLL or build ../tyhp/bin/Debug/net9.0/tyhp.dll"
fi

export PACKAGE SCENARIO TARGET FROM_VERSION USER_AGENT PACKAGES_DIR TYHP_DLL REPO_ROOT
python3 - <<'PY'
import glob, json, os, shutil, subprocess, sys, tempfile, urllib.error, urllib.parse, urllib.request

package = os.environ["PACKAGE"]
scenario = os.environ["SCENARIO"]
target = os.environ.get("TARGET") or ""
from_version = os.environ.get("FROM_VERSION") or ""
ua = os.environ["USER_AGENT"]
packages_dir = os.environ["PACKAGES_DIR"]
tyhp_dll = os.environ["TYHP_DLL"]
repo_root = os.environ["REPO_ROOT"]
vendor, proj = package.split("/", 1)
public = "tyhpdef/" + package.replace("/", "-")
failed = False

def fail(msg):
    global failed
    failed = True
    print(f"FAIL: {msg}")

def note(msg):
    print(msg)

def run_advisory_fail(upstream_ver, owner_composer):
    """Lint local tests/fail against the owner package. Never changes the gate."""
    folder = package.replace("/", "-")
    tests_fail = os.path.join(packages_dir, folder, upstream_ver, "tests", "fail")
    if not os.path.isdir(tests_fail):
        note(f"Advisory tests/fail: none for {folder}/{upstream_ver}")
        return
    cases = sorted(name for name in os.listdir(tests_fail) if name.endswith(".tyhp"))
    if not cases:
        note(f"Advisory tests/fail: none for {folder}/{upstream_ver}")
        return
    note("Advisory tests/fail against the owner tree (not blocking):")
    adv = os.path.join(work, "advisory")
    fail_dir = os.path.join(adv, "fail")
    os.makedirs(fail_dir, exist_ok=True)
    php_manifest = os.path.join(packages_dir, "php", "composer.json")
    assert_py = os.path.join(packages_dir, "assert-tyhpdef-fail-expect.py")
    for case in cases:
        shutil.copy(os.path.join(tests_fail, case), os.path.join(fail_dir, case))
        expect = os.path.join(tests_fail, case[: -len(".tyhp")] + ".expect.json")
        php_version = "8.2"
        if os.path.isfile(expect):
            try:
                spec = json.load(open(expect, encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                spec = {}
            if isinstance(spec, dict) and isinstance(spec.get("php"), str) and spec["php"].strip():
                php_version = spec["php"].strip()
        project = {
            "quiet": True,
            "locale": "en-US",
            "type": "application",
            "suppressWarnings": ["TYHP8027"],
            "include": [os.path.abspath(owner_composer)],
            "exclude": ["./fail/**"],
            "output": {"path": "./build", "phpVersion": php_version},
        }
        if os.path.isfile(php_manifest):
            project["include"].append(os.path.abspath(php_manifest))
        with open(os.path.join(adv, "tyhp.json"), "w", encoding="utf-8") as fh:
            json.dump(project, fh, indent=4)
            fh.write("\n")
        lint_path = os.path.join(adv, case + ".lint.json")
        lint = subprocess.run(
            ["dotnet", tyhp_dll, "lint", f"--file=fail/{case}", "--format=json", "--quiet"],
            cwd=adv,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        with open(lint_path, "w", encoding="utf-8") as fh:
            fh.write(lint.stdout)
        if not os.path.isfile(expect):
            note(f"  {case}: no expect.json (lint exit {lint.returncode})")
            if lint.stderr.strip():
                print(lint.stderr, end="" if lint.stderr.endswith("\n") else "\n")
            continue
        diff = subprocess.run(
            [sys.executable, assert_py, expect, lint_path],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        if diff.returncode == 0:
            line = diff.stdout.strip() or "matches expect"
            note(f"  {case}: {line}")
        else:
            note(f"  {case}: differs from expect (not blocking)")
            if diff.stderr.strip():
                print(diff.stderr, end="" if diff.stderr.endswith("\n") else "\n")
            if diff.stdout.strip():
                print(diff.stdout, end="" if diff.stdout.endswith("\n") else "\n")
            if lint.returncode not in (0, 4, 5) and lint.stderr.strip():
                print(lint.stderr, end="" if lint.stderr.endswith("\n") else "\n")

def fetch(name):
    v, _, n = name.partition("/")
    url = "https://packagist.org/packages/{}/{}.json".format(
        urllib.parse.quote(v, safe=""), urllib.parse.quote(n, safe="")
    )
    req = urllib.request.Request(url, headers={"User-Agent": ua})
    try:
        with urllib.request.urlopen(req, timeout=60) as resp:
            body = resp.read()
            if resp.getcode() != 200:
                fail(f"HTTP {resp.getcode()} for {name}")
                return None
    except urllib.error.HTTPError as err:
        fail(f"HTTP {err.code} for {name}")
        return None
    except urllib.error.URLError as err:
        fail(f"HTTP error for {name}: {err}")
        return None
    try:
        return json.loads(body.decode("utf-8"))
    except json.JSONDecodeError as exc:
        fail(f"parse Packagist JSON for {name}: {exc}")
        return None

def version_key(ver):
    core = ver.split("+", 1)[0]
    if core[:1].lower() == "v" and len(core) > 1 and core[1].isdigit():
        core = core[1:]
    numeric, _, pre = core.partition("-")
    parts = []
    for piece in numeric.split("."):
        if not piece.isdigit():
            return None
        parts.append(int(piece))
    if pre:
        return None
    return tuple(parts)

def newest_stable(versions):
    best = None
    best_key = None
    for ver in versions:
        key = version_key(ver)
        if key is None:
            continue
        if best_key is None or key > best_key:
            best_key = key
            best = ver
    return best

payload = fetch(package)
if not payload or "package" not in (payload or {}):
    sys.exit(1)
pkg = payload["package"]
maintainers = pkg.get("maintainers") or []
note("Packagist maintainers of {}:".format(package))
if not maintainers:
    fail("no maintainers listed")
for person in maintainers:
    if isinstance(person, dict):
        note("  - {}".format(person.get("name") or person))
    else:
        note(f"  - {person}")
note("Confirm the issue author is one of those accounts.")

versions = pkg.get("versions") if isinstance(pkg.get("versions"), dict) else {}
ver = from_version or newest_stable(versions) or ""
if not ver or ver not in versions:
    fail(f"version {ver or '(none)'} is not on Packagist for {package}")
    sys.exit(1)
doc = versions[ver]
extra = doc.get("extra") if isinstance(doc.get("extra"), dict) else {}
tyhp = extra.get("tyhp") if isinstance(extra.get("tyhp"), dict) else {}
replace = doc.get("replace") if isinstance(doc.get("replace"), dict) else {}
note(f"Inspecting {package} {ver}")

if scenario == "bundled":
    package_extra = tyhp.get("package")
    if not isinstance(package_extra, dict) or not package_extra:
        fail("extra.tyhp.package is missing or empty on the PHP package")
    replaced = replace.get(public)
    if replaced != "self.version":
        fail(f'replace of {public} is {replaced!r}; expected "self.version"')
else:
    pointer = tyhp.get("tyhpdef")
    if not isinstance(pointer, str) or pointer.strip() != target:
        fail(f"extra.tyhp.tyhpdef is {pointer!r}; expected {target!r}")

download_name = package if scenario == "bundled" else target
download_ver = ver if scenario == "bundled" else None
if scenario == "sibling":
    sib_payload = fetch(target)
    if not sib_payload or "package" not in sib_payload:
        sys.exit(1)
    sib_versions = sib_payload["package"].get("versions") or {}
    if ver not in sib_versions:
        fail(f"sibling {target} has no version {ver}")
        sys.exit(1)
    download_ver = ver
    sib_doc = sib_versions[download_ver]
    sib_replace = sib_doc.get("replace") if isinstance(sib_doc.get("replace"), dict) else {}
    if sib_replace.get(public) != "self.version":
        fail(f'sibling replace of {public} is {sib_replace.get(public)!r}; expected "self.version"')

if failed:
    sys.exit(1)

work = tempfile.mkdtemp(prefix="tyhp-verify-owner-")
try:
    init = {
        "name": "tyhp/verify-owner-tyhpdef",
        "description": "Temporary verify workspace. Not published.",
        "require": {download_name: download_ver},
        "config": {"allow-plugins": False},
    }
    with open(os.path.join(work, "composer.json"), "w", encoding="utf-8") as fh:
        json.dump(init, fh, indent=4)
        fh.write("\n")
    cmd = [
        "composer", "update",
        "--no-scripts", "--no-plugins", "--no-dev",
        "--no-interaction", "--no-progress",
        f"--working-dir={work}",
    ]
    note("Downloading {} {} with Composer --no-scripts --no-plugins".format(download_name, download_ver))
    proc = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    if proc.returncode != 0:
        print(proc.stdout)
        fail("composer update --no-scripts --no-plugins failed")
        sys.exit(1)

    installed_dir = os.path.join(work, "vendor", *download_name.split("/", 1))
    installed_json = os.path.join(installed_dir, "composer.json")
    if not os.path.isfile(installed_json):
        fail(f"installed composer.json missing at {installed_json}")
        sys.exit(1)
    installed = json.load(open(installed_json, encoding="utf-8"))
    if str(installed.get("type") or "") == "composer-plugin":
        fail("owner package type is composer-plugin")
    scripts = installed.get("scripts") if isinstance(installed.get("scripts"), dict) else {}
    blocked_hooks = []
    for key in scripts:
        low = str(key).lower()
        if "install" in low or "update" in low or "autoload-dump" in low:
            blocked_hooks.append(str(key))
    if blocked_hooks:
        fail("owner package defines install/update scripts: " + ", ".join(blocked_hooks))

    iextra = installed.get("extra") if isinstance(installed.get("extra"), dict) else {}
    ityhp = iextra.get("tyhp") if isinstance(iextra.get("tyhp"), dict) else {}
    package_map = ityhp.get("package") if isinstance(ityhp.get("package"), dict) else {}
    matched = []
    for group in ("include", "overlay"):
        patterns = package_map.get(group) or []
        if isinstance(patterns, str):
            patterns = [patterns]
        for pattern in patterns:
            full = pattern
            if not os.path.isabs(full):
                full = os.path.join(installed_dir, pattern)
            matched.extend(glob.glob(full, recursive=True))
    tyhpdefs = [p for p in matched if p.endswith(".tyhpdef") and os.path.isfile(p)]
    if not tyhpdefs:
        fail("extra.tyhp.package globs matched no .tyhpdef files")

    contract = ityhp.get("interopContractVersion")
    compiler_contract = 1
    contract_cs = os.path.join(repo_root, "..", "tyhp", "Tyhp", "TyhpLang", "Interop", "InteropContract.cs")
    if os.path.isfile(contract_cs):
        text = open(contract_cs, encoding="utf-8").read()
        marker = "CurrentVersion = "
        idx = text.find(marker)
        if idx != -1:
            digits = []
            for ch in text[idx + len(marker):]:
                if ch.isdigit():
                    digits.append(ch)
                elif digits:
                    break
            if digits:
                compiler_contract = int("".join(digits))
    if not isinstance(contract, int) or contract < compiler_contract:
        note(f"WARN: interopContractVersion is {contract!r}; compiler contract is {compiler_contract}. A human decides whether to continue.")

    project = {
        "quiet": True,
        "locale": "en-US",
        "type": "application",
        "include": [installed_json],
        "exclude": [],
        "output": {"path": "./build", "phpVersion": "8.2"},
    }
    php_manifest = os.path.join(packages_dir, "php", "composer.json")
    if os.path.isfile(php_manifest):
        project["include"].append(os.path.abspath(php_manifest))
    project_dir = os.path.join(work, "lint")
    os.makedirs(project_dir, exist_ok=True)
    with open(os.path.join(project_dir, "tyhp.json"), "w", encoding="utf-8") as fh:
        json.dump(project, fh, indent=4)
        fh.write("\n")
    lint = subprocess.run(
        ["dotnet", tyhp_dll, "lint"],
        cwd=project_dir,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
    )
    if lint.returncode not in (0, 5):
        print(lint.stdout)
        fail(f"tyhp lint exited {lint.returncode}")
    else:
        note(f"tyhp lint exit {lint.returncode}")

    tyhpdef_dir = os.path.join(installed_dir, "_tyhpdef")
    if not os.path.isdir(tyhpdef_dir):
        fail("_tyhpdef directory is missing on the owner tree")
    else:
        verify = subprocess.run(
            [
                "dotnet", tyhp_dll, "generate_tyhpdef",
                f"--package-path={installed_dir}",
                f"--output={tyhpdef_dir}",
                "--verify",
            ],
            cwd=repo_root,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
        )
        if verify.returncode != 0:
            print(verify.stdout)
            fail(f"generate_tyhpdef --verify exited {verify.returncode}")
        else:
            note("generate_tyhpdef --verify passed")

    if not failed:
        run_advisory_fail(ver, installed_json)
finally:
    shutil.rmtree(work, ignore_errors=True)

if failed:
    sys.exit(1)
note("verify-owner-tyhpdef: passed blocking checks")
PY
