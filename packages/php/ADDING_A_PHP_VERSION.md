# Adding a PHP minor to `tyhpdef/php`

This is the maintainer runbook for adding a newly released PHP minor (example: **8.6**) to the single `tyhpdef/php` tree and every `tyhpdef/php-ext-*` package. There are no `php-8.x` / `php-8.6` package forks. json, hash, and libxml stay in `tyhpdef/php`.

Version-specific APIs live in one gated tree: `declare(php=…)` on whole declarations and `#[\Tyhp\Php]` on members. `generate_tyhpdef --php-targets` reflects each listed minor with Tyhp-managed PHP and merges those gates. Do not point `--php` at several Homebrew binaries and merge by hand.

## 1. Compiler and managed PHP

`generate_tyhpdef --php-targets` only provisions minors listed in the compiler’s managed-PHP manifest.

1. Add `"8.6"` to `Tyhp/Domain/Services/PhpRuntimeManifest.json` (`minors`).
2. Add `"8.6"` to `PhpRuntimeInfo.SupportedMinors` (`Tyhp/Domain/Services/PhpRuntimeInfo.cs`).
3. Add `"8.6"` to `OutputConfig` supported `output.phpVersion` values (`Tyhp/Config/OutputConfig.cs`) so lint/build can target it.
4. Rebuild the compiler (`dotnet build tyhp.csproj`).

Until managed 8.6 exists, `--php-targets=…,8.6` fails and writes nothing merged. Do not invent 8.6 APIs by hand to paper over that.

## 2. Snapshots

`generate_tyhpdef --ext-name` writes Reflection JSON under `{project root}/tyhpdef_gen/snapshots/{minor}/` (gitignored). Later runs reuse those files unless you pass `--refresh-snapshots`.

- Run every regen from the **repo root** so the snapshot directory is `tyhpdef_gen/snapshots/`.
- After 8.6 is provisioned once, `tyhpdef_gen/snapshots/8.6/*.json` is the cache for that minor.
- Snapshots are Reflection JSON, not tyhpdef sources. Do not copy APIs from elsewhere into Layer 1.

## 3. Regenerate `tyhpdef/php` (always-present only)

Always-present extensions, each into `runtime/packages/php/_tyhpdef/` with `--output-file=Ext.<Name>.tyhpdef`:

| `--ext-name` | `--output-file` |
|---|---|
| `Core` | `Ext.Core.tyhpdef` |
| `date` | `Ext.Date.tyhpdef` |
| `filter` | `Ext.Filter.tyhpdef` |
| `hash` | `Ext.Hash.tyhpdef` |
| `json` | `Ext.Json.tyhpdef` |
| `libxml` | `Ext.Libxml.tyhpdef` |
| `pcre` | `Ext.Pcre.tyhpdef` |
| `random` | `Ext.Random.tyhpdef` |
| `Reflection` | `Ext.Reflection.tyhpdef` |
| `SPL` | `Ext.SPL.tyhpdef` |
| `standard` | `Ext.Standard.tyhpdef` |

Example (json). Repeat for the other ten. `--overwrite` replaces Layer 1 and `overlays/stubs/`; it does not touch hand-written `overlays/*.tyhpdef`.

```bash
TYHP_DLL="${TYHP_DLL:-./bin/Debug/net9.0/tyhp.dll}"

dotnet "$TYHP_DLL" generate_tyhpdef \
  --ext-name=json \
  --php-targets=8.2,8.3,8.4,8.5,8.6 \
  --output=runtime/packages/php/_tyhpdef/ \
  --output-file=Ext.Json.tyhpdef \
  --overwrite
```

Do **not** generate calendar, ctype, mbstring, tokenizer, Phar, curl, PDO, or any other optional extension into `tyhpdef/php`. Those are `tyhpdef/php-ext-*`. Do **not** create `tyhpdef/php-ext-json`, `tyhpdef/php-ext-hash`, or `tyhpdef/php-ext-libxml`.

Identical APIs across every listed minor stay ungated. Symbols that appear at 8.6 land in `declare(php=">=8.6") { … }` or `#[\Tyhp\Php(">=8.6")]` on members of a shared type.

## 4. Regenerate every `php-ext-*`

Same `--php-targets` list, package-local `--output` and `--output-file=Ext.<Stem>.tyhpdef` (see an existing package such as `php-ext-bz2` / `php-ext-pdo_mysql`). From the repo root:

```bash
dotnet "$TYHP_DLL" generate_tyhpdef \
  --ext-name=bz2 \
  --php-targets=8.2,8.3,8.4,8.5,8.6 \
  --output=runtime/packages/php-ext-bz2/_tyhpdef/ \
  --output-file=Ext.Bz2.tyhpdef \
  --overwrite
```

If managed PHP cannot load the extension, the CLI errors **TYHP7511** and writes no Layer 1. Leave the existing file (or empty tree). Do not invent signatures.

## 5. Stamp overlays

Regen overwrites Layer 1 and generated stub overlays. Hand-written `_tyhpdef/overlays/*.tyhpdef` stay. After regen, rewrite `// @overlay-against:` from the new Layer 1:

```bash
# tyhpdef/php overlays (tests project includes this package only)
(cd runtime/packages/php/tests && dotnet "$TYHP_DLL" overlay stamp)

# one php-ext package (includes that package + tyhpdef/php)
(cd runtime/packages/php-ext-bz2/tests && dotnet "$TYHP_DLL" overlay stamp)
```

**TYHP8027:** these `tests/` projects do not include `tyhp/core`, `tyhp/decimal`, `tyhp/async`, or `tyhp/lambda`. Overlay stamp then warns TYHP8027 and the process exits **5** (`CompileWarning`). The stamps still applied. Treat exit **0** or **5** as success for this command.

Do not add those four packages to the tests project just to silence 8027 unless you intend to restamp *their* overlays in the same run.

Review **TYHP8021** (stamp mismatch) on hand overlays after a Layer 1 change; `--strict` / `build.strictMode` promotes that warning to an error.

## 6. `supported-minors` and Composer

In `runtime/packages/php/composer.json` and every `runtime/packages/php-ext-*/composer.json`, append `"8.6"` to `extra.tyhp.supported-minors`. Leave `"php": ">=8.2"` (do not add mutually exclusive per-minor constraints). Do not add `runtime/packages/php-8.6/` or `tyhpdef/php-8.6-ext-*`.

## 7. Tests matrix

`output.phpVersion` is how you see 8.6-gated symbols. You do not install four (or five) PHPs for that check.

- Add `runtime/packages/php/tests/tyhp-php8.6.json`: copy `tests/tyhp.json` and set `"output"."phpVersion"` to `"8.6"`.
- For each `php-ext-*` that already has `tests/tyhp-php8.3.json` (and 8.4 / 8.5), add `tests/tyhp-php8.6.json` the same way. Packages that only ship `tests/tyhp.json` (8.2) can keep that default and add 8.6 when you start asserting 8.6-only APIs.
- If you emit compiled runtime packages with `base-build-all.sh`, add a `806:8.6:PHP 8.6` entry to `DIST_BUILDS` in `runtime/packages/build-common.sh` (`tyhpdef/php` itself is tyhpdefs only and is not in that list). The build uses each package's `tyhp.json` plus `--output:phpVersion=8.6`.

Lint/build the **same** tree at 8.2, 8.3, 8.4, 8.5, and 8.6. Confirm 8.6-only symbols are absent at 8.5 and visible at 8.6.

## 8. Hand overlays and scalar extensions

Hand overlays (`_tyhpdef/overlays/*.tyhpdef`, not `stubs/`) are not regenerated. After a gated Layer 1 refresh:

- Stamp (step 5).
- If 8.6 adds a native helper a scalar method should call, gate that arm with `declare(php=">=8.6")` in `tyhp/core` (`runtime/packages/core/_tyhpdef/extensions/`; attributes are not allowed on `extension` declarations).

## 9. Docs and user-facing strings

Update user docs that list the 8.2–8.5 matrix (`docs/content/tyhp_0320_phpVersionGating.md`, Composer package pages, `generate_tyhpdef` help examples) so 8.6 is part of the current contract. New CLI help strings go in **both** `Resources/CLI.TyhpHostedService.resx` and `Resources/CLI.TyhpHostedService.en-US.resx`.

## Checklist

- [ ] Managed PHP 8.6 is in the compiler manifest and `SupportedMinors`
- [ ] `output.phpVersion` `"8.6"` is a supported compiler target
- [ ] `tyhpdef/php` Layer 1 regenerated with `--php-targets=8.2,8.3,8.4,8.5,8.6` for the 11 always-present extensions only
- [ ] json, hash, and libxml still exist only under `runtime/packages/php/`
- [ ] Every `php-ext-*` regenerated the same way, or left unchanged on TYHP7511
- [ ] Overlay stamp run from each package’s `tests/` (exit 0 or 5)
- [ ] `extra.tyhp.supported-minors` includes `"8.6"`; no `php-8.6` directories
- [ ] `tests/tyhp-php8.6.json` added where a per-minor matrix already exists
- [ ] Lint/build matrix covers 8.2–8.6 on the single trees
