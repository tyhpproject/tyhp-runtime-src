# Owner-maintained tyhpdefs

Process for handing a community `tyhpdef/<vendor>-<name>` companion to the
maintainer of the PHP package it wraps (`vendor/name`).

This file lives at the repository root of
[`tyhpproject/tyhp-runtime-src`](https://github.com/tyhpproject/tyhp-runtime-src).
GitHub issue templates, labels, and the scheduled scan workflow described here
are only for this repo — not the compiler repo.

Published package repositories (`tyhp/*` and `tyhpdef/*`, including each
public metapackage and its `*-impl`) live in
[`tyhpproject-packages`](https://github.com/tyhpproject-packages). The
compiler and this source tree stay on `tyhpproject`. Package repos that
already exist under `tyhpproject` are re-created on `tyhpproject-packages`
with the same names, and Packagist repository URLs are updated to that org.

Related: [TYHPDEF_OVERLAY_STANDARDS.md](TYHPDEF_OVERLAY_STANDARDS.md),
[TYHPDEF_ORDERING.md](TYHPDEF_ORDERING.md), Composer-lib scaffolding in
`packages/new-composer-lib.sh`.

## Status

| Piece | Status |
|-------|--------|
| Tyhp loads `extra.tyhp.package` from any installed Composer package | Shipped |
| `--vendor` skips stub generate when the PHP package already has `extra.tyhp.package` | Shipped |
| Public companion name `tyhpdef/<vendor>-<name>` | Shipped (this tree; versioning/layout below is to build) |
| Public package is a metapackage; files live on `tyhpdef/<vendor>-<name>-impl` | Shipped (`packages/new-composer-lib.sh`, `scripts/publish-runtime-packages.sh`) |
| `extra.tyhp.tyhpdef` on the PHP package (pointer to an owner sibling) | Shipped in the Tyhp compiler (`--vendor`) |
| `--vendor` must not auto-install the public companion when bundled types or `extra.tyhp.tyhpdef` are present | Shipped in the Tyhp compiler |
| `--vendor` must not auto-require `*-impl`; only the public name | Shipped in the Tyhp compiler |
| Prefer the PHP package’s `extra.tyhp.package` when both that and `*-impl` would load | Shipped in the Tyhp compiler |
| README on every published package with owner instructions | Shipped (`write-package-readmes.sh`) |
| Generate public metapackage from the impl tree | Shipped (`generate-meta-package.sh`) |
| Handoff scripts (bundled / sibling / reclaim) | Shipped (`handoff-tyhpdef.sh`) |
| Quality check against an owner-published tree | Shipped (`verify-owner-tyhpdef.sh`) |
| Scheduled Packagist scan + report | Shipped (workflow + `scan-upstream-tyhpdefs.sh`) |

Copy the [canonical package shapes](#canonical-package-shapes) exactly.

---

## Policy

1. **Keep the public name.** Consumers, `extra.tyhp.require`, `--vendor`, and
   `// @provided-by:` use `tyhpdef/<vendor>-<name>`. Do not transfer that
   Packagist name to a third party.
2. **Two packages per Composer-lib companion** (not `tyhpdef/php`, not
   `tyhpdef/php-ext-*`):
   - **Public metapackage** `tyhpdef/<vendor>-<name>`, versioned **exactly**
     as the PHP package (`nesbot/carbon` `3.14.0` → `tyhpdef/nesbot-carbon`
     `3.14.0`).
   - **Implementation** `tyhpdef/<vendor>-<name>-impl`, four-part version,
     holds `_tyhpdef/`, tests, and `extra.tyhp.package`.
3. **Two supported owner destinations**
   - **Bundled (preferred).** Types live in the PHP package via
     `extra.tyhp.package`.
   - **Sibling.** Types live in a package the owner publishes. The PHP
     package points at it with `extra.tyhp.tyhpdef`.
4. **Owners `replace` the public name with `self.version`.** That line does
   not change when they tag a new release.
5. **Identity is Packagist ownership of the PHP package.** See
   [Verification](#verification).
6. **Quality gate before any cutover.** The scan may *detect*; it must not
   rewrite packages. A human runs `verify-owner-tyhpdef.sh`, then
   `handoff-tyhpdef.sh`.
7. **Out of scope.** `tyhpdef/php`, `tyhpdef/php-ext-*`, and compiled
   `tyhp/*` helpers (`core`, `async`, `decimal`, `lambda`, `compiler`) stay
   single first-party packages.
8. **Reclaim.** If the owner stops shipping types for a major we still wrap,
   tyhpproject publishes that major again. See [Reclaim](#scenario-reclaim).

Do not use Composer `provide` for this. `provide` is for virtual packages.
Owners use `replace`. The public package uses `require` of the impl.

---

## Public metapackage and implementation

Example: `nesbot/carbon` `3.14.0`.

| | Public | Implementation |
|--|--------|----------------|
| Composer name | `tyhpdef/nesbot-carbon` | `tyhpdef/nesbot-carbon-impl` |
| GitHub repo | `tyhpproject-packages/nesbot-carbon` | `tyhpproject-packages/nesbot-carbon-impl` |
| Version | `3.14.0` (same string as Carbon) | `3.14.0.0`, then `3.14.0.1`, … |
| `type` | `metapackage` | `library` |
| Files | `composer.json`, `README.md`, `LICENSE` | `_tyhpdef/`, `tests/`, `LICENSE`, `README.md` |
| Who requires it | Apps, `--vendor`, `extra.tyhp.require` | Only the public metapackage |

The source tree in this repo is the **implementation** (version folder
`<vendor>-<name>/<upstream>/`, same as today). The public metapackage is
**generated at publish time** from that tree. Do not hand-maintain a second
source tree.

### Invariant: the public `require` of the impl is a range

```json
{
    "name": "tyhpdef/nesbot-carbon",
    "description": "Tyhp type definitions for nesbot/carbon 3.14.0.",
    "type": "metapackage",
    "license": "Apache-2.0",
    "version": "3.14.0",
    "keywords": ["dev", "static analysis"],
    "require": {
        "tyhpdef/nesbot-carbon-impl": "~3.14.0.0"
    },
    "extra": {
        "tyhp": {
            "impl": "tyhpdef/nesbot-carbon-impl"
        }
    }
}
```

`~3.14.0.0` is `>=3.14.0.0 <3.14.1.0`: every tyhpdef-only revision of this
Carbon snapshot, and nothing else.

Never pin an exact four-part impl version on the public package. A pin
would force a new **public** version in order to ship `3.14.0.1`, and the
public version must stay `3.14.0`.

When Carbon `3.14.1` exists, publish public `3.14.1` requiring
`~3.14.1.0`. Do not retag public `3.14.0` when only the impl revision
moves.

Prerelease upstream (example `3.14.0-alpha.1`): public version is that
same string. Impl is `{numeric-core}.{revision}-{prerelease}`
(`3.14.0.0-alpha.1`). The public package requires that **exact** impl
version (Composer tilde plus a prerelease suffix is not this contract).

### Implementation package

```json
{
    "name": "tyhpdef/nesbot-carbon-impl",
    "description": "Implementation package for tyhpdef/nesbot-carbon. Require tyhpdef/nesbot-carbon, not this package.",
    "type": "library",
    "license": "Apache-2.0",
    "version": "3.14.0.0",
    "keywords": ["dev", "static analysis", "internal"],
    "require": {
        "php": ">=8.2",
        "nesbot/carbon": "3.14.0"
    },
    "require-dev": {
        "tyhpdef/php": "@dev"
    },
    "extra": {
        "tyhp": {
            "public": "tyhpdef/nesbot-carbon",
            "interopContractVersion": 1,
            "php-version": ">=8.2",
            "require": {
                "tyhpdef/php": "@dev"
            },
            "package": {
                "include": [
                    "./_tyhpdef/*.tyhpdef",
                    "./_tyhpdef/extensions/*.tyhpdef"
                ],
                "overlay": [
                    "./_tyhpdef/overlays/stubs/*.tyhpdef",
                    "./_tyhpdef/overlays/*.tyhpdef"
                ]
            }
        }
    }
}
```

`extra.tyhp.package` and `extra.tyhp.require` live **only** on the impl.
The public metapackage has no tyhpdef files. The `tyhp/core` Composer
plugin already walks `require` edges, so installing the public name still
collects the impl’s `extra.tyhp.require`.

No Composer `scripts`. Not `composer-plugin`.

### Who should require what

| Audience | Require |
|----------|---------|
| Application / `--vendor` | `tyhpdef/nesbot-carbon` at the installed Carbon version (`3.14.0`) |
| Other community wrappers’ `extra.tyhp.require` | Public name, `@dev` in-tree / published constraint as today |
| Package owner `replace` | Public name, `self.version` |
| Humans | Never `tyhpdef/nesbot-carbon-impl` |

`composer.lock` listing `tyhpdef/nesbot-carbon-impl` as a **transitive**
dependency is expected. Requiring it from an app `composer.json` is not.

---

## How Tyhp finds types

For each installed library `vendor/name`, the companion Composer name is
`tyhpdef/<vendor>-<name>` (`/` → `-`). `--vendor` then:

| Situation | What happens |
|-----------|----------------|
| Install path already has `extra.tyhp.package` on `composer.json` | Skip generate; binder loads that package from `vendor/` |
| Public companion already installed (pulls impl) | Skip generate; delete any previously generated stub |
| Published public companion exists but is not required | Add the **public** name to root `require-dev` at the installed upstream version, install, then skip generate |
| Otherwise | Generate stubs into `vendor-tyhpdef/` |

After compiler work lands, `--vendor` should become:

1. Installed PHP package has `extra.tyhp.package` → use it; **do not**
   auto-install `tyhpdef/<vendor>-<name>`. If both that and `*-impl` would
   bind, use the PHP package and warn.
2. Installed PHP package has `extra.tyhp.tyhpdef` → require *that* name
   (if missing); **do not** auto-install the community companion.
3. Else require the public companion at the installed PHP package version
   (exact), never `*-impl` as a root require.

---

## `extra.tyhp` contract owners set

Read these keys from the **PHP package as published on Packagist** (the
installed tarball). Do not treat the same keys on a fork, a gist, or a
comment as proof.

### Bundled types (scenario A)

On `vendor/name` (example `nesbot/carbon`):

```json
{
    "extra": {
        "tyhp": {
            "interopContractVersion": 1,
            "package": {
                "include": [
                    "./_tyhpdef/*.tyhpdef",
                    "./_tyhpdef/extensions/*.tyhpdef"
                ],
                "overlay": [
                    "./_tyhpdef/overlays/stubs/*.tyhpdef",
                    "./_tyhpdef/overlays/*.tyhpdef"
                ]
            }
        }
    },
    "replace": {
        "tyhpdef/nesbot-carbon": "self.version"
    }
}
```

`extra.tyhp.package` must be a JSON **object** with `include` and/or
`overlay` globs that resolve to `.tyhpdef` files inside that package.

`replace` with **`self.version`** means this Carbon tag satisfies anyone
who `require`s `tyhpdef/nesbot-carbon` at the same version. Leave that
line unchanged on future tags. Do not `replace` `tyhpdef/nesbot-carbon-impl`
(owners should not know that name). Do not use `*`.

Because the public companion version **is** Carbon’s version, `self.version`
matches every Carbon release that ships types. Composer then does not
install the public metapackage, so the impl is not pulled either.

### Sibling types (scenario B)

On `vendor/name`:

```json
{
    "extra": {
        "tyhp": {
            "tyhpdef": "nesbot/carbon-tyhpdef"
        }
    }
}
```

`extra.tyhp.tyhpdef` is a Composer package name (string). It is **not** a
file glob. The sibling package is what carries `extra.tyhp.package`.

On `nesbot/carbon-tyhpdef`:

```json
{
    "name": "nesbot/carbon-tyhpdef",
    "type": "library",
    "require": {
        "php": ">=8.2",
        "nesbot/carbon": "^3.14"
    },
    "replace": {
        "tyhpdef/nesbot-carbon": "self.version"
    },
    "extra": {
        "tyhp": {
            "interopContractVersion": 1,
            "package": {
                "include": [
                    "./_tyhpdef/*.tyhpdef",
                    "./_tyhpdef/extensions/*.tyhpdef"
                ],
                "overlay": [
                    "./_tyhpdef/overlays/stubs/*.tyhpdef",
                    "./_tyhpdef/overlays/*.tyhpdef"
                ]
            }
        }
    }
}
```

Version the sibling with the PHP package (same `X.Y.Z` as the Carbon
release these types describe) so `self.version` hits `tyhpdef/nesbot-carbon`.

Require the PHP library with a real constraint (`^3.14` is fine: one types
tag can cover several Carbon patches in that minor). Never `@dev` as the
product contract.

The sibling must be `type: library`, not `composer-plugin`, and must not
define Composer `scripts` that run on install/update.

### What is not a signal

- `"keywords": ["tyhp"]`
- A README sentence
- `extra.tyhp.require` (ambient *dependencies*, not “I own these types”)
- `replace` of `tyhpdef/<vendor>-<name>-impl` only
- Types in a path that is not listed in `extra.tyhp.package`

---

## Versions

| Package | Version |
|---------|---------|
| PHP library | `3.14.0` |
| Public `tyhpdef/nesbot-carbon` | `3.14.0` |
| Impl `tyhpdef/nesbot-carbon-impl` | `3.14.0.0` (revision `0`), `3.14.0.1`, … |
| Source folder | `nesbot-carbon/3.14.0/` (upstream string, not the four-part version) |

Handoff is **per upstream version folder**. Carbon 3.14 may bundle types
while Carbon 2.x does not: stop publishing `3.14.0/` (and later 3.x) and
keep publishing `2.x`.

After a successful handoff of a major, **stop tagging** public and impl
for those versions. New PHP releases in that major are the owner’s.

Do not retag a public version that is already on Packagist in order to
change its `require` or `abandoned` field. Carbon’s `replace` is the
cutover for installed apps. Already-published public `3.14.0` that still
requires the impl is unused when Carbon `3.14.0` is installed with
`replace`. Mark `extra.tyhp.ownership` in **source** so we do not
regenerate. Set Packagist package-level abandoned only when **every**
major we wrap is owner-maintained.

---

## Verification

Proof, strongest first:

1. The requester is a **Packagist maintainer** of `vendor/name`. Check
   `https://packagist.org/packages/vendor/name.json` → `package.maintainers`.
2. They can post from a GitHub account that is admin on the canonical
   source listed in that package’s `support.source` / `homepage`.
3. The signal (`extra.tyhp.package` and/or `extra.tyhp.tyhpdef`, plus
   `replace` of the **public** companion with `self.version`) is **already
   on Packagist** for the versions under discussion.

A field only in a PR to this repo is not identity.

---

## GitHub setup (`tyhpproject/tyhp-runtime-src` only)

Do this on **`tyhpproject/tyhp-runtime-src`** after that repo exists. Do
not add these templates or the scan workflow to the compiler repository.

### Repository settings

- Issues: enabled.
- Discussions: optional; issues remain the audit trail.
- Actions: enabled for GitHub-hosted runners.
- Actions permissions: read repository contents; **Allow GitHub Actions to
  create and approve pull requests** is **not** required (the scan does not
  open PRs).
- Workflow permissions (repo setting): default to **read**, so the scan
  workflow must set `permissions:` itself (`contents: read`, `issues: write`).
- No Packagist token is required for the scan (public API). Use a
  descriptive `User-Agent` (same family as `check-composer-lib-updates.sh`:
  `tyhp-scan-upstream-tyhpdefs (+https://github.com/tyhpproject/tyhp-runtime-src)`).

Published per-package GitHub repos live under
[`tyhpproject-packages`](https://github.com/tyhpproject-packages)
(`nesbot-carbon` and `nesbot-carbon-impl`). Creating those is the publish
script’s job, not this source repo’s workflows. Repos already created under
`tyhpproject` are re-created on `tyhpproject-packages` with the same names;
Packagist is then pointed at `https://github.com/tyhpproject-packages/<repo>`.
This source repo stays `tyhpproject/tyhp-runtime-src`.

### Labels

| Label | Color | Use |
|-------|-------|-----|
| `tyhpdef-ownership` | `0E8A16` | Human-filed claim / handoff request |
| `tyhpdef-ownership-scan` | `FBCA04` | Opened or updated by the scheduled scan |
| `tyhpdef-handoff-bundled` | `1D76DB` | Scenario A in progress / done |
| `tyhpdef-handoff-sibling` | `5319E7` | Scenario B in progress / done |
| `tyhpdef-reclaim` | `D93F0B` | Owner went stale; publish again |
| `tyhpdef-quality-failed` | `B60205` | `verify-owner-tyhpdef.sh` failed |

### Issue templates

Add `.github/ISSUE_TEMPLATE/tyhpdef-ownership.yml` (claim) and
`.github/ISSUE_TEMPLATE/tyhpdef-reclaim.yml` (reclaim). Also add
`.github/ISSUE_TEMPLATE/config.yml` with `blank_issues_enabled: true`.

**Claim template** — title prefix `[tyhpdef-ownership] `, labels
`tyhpdef-ownership`, required fields:

- PHP Composer name (`vendor/name`)
- Scenario: bundled / sibling
- Sibling Composer name (if sibling)
- Packagist URL of the PHP package
- Versions / majors to hand off (example: `3.14+`)
- GitHub source repo
- Confirmation that `extra.tyhp.package` and/or `extra.tyhp.tyhpdef` plus
  `"replace": { "tyhpdef/<vendor>-<name>": "self.version" }` already
  shipped on Packagist
- Packagist username that is a maintainer of `vendor/name`

**Reclaim template** — title prefix `[tyhpdef-reclaim] `, label
`tyhpdef-reclaim`: PHP Composer name, majors to take back, reason (new
major with no types, owner request, quality failure).

### CODEOWNERS

`.github/CODEOWNERS` should require a tyhpproject maintainer on:

- `.github/workflows/scan-upstream-tyhpdefs.yml`
- `handoff-tyhpdef.sh`
- `scan-upstream-tyhpdefs.sh`
- `verify-owner-tyhpdef.sh`
- `write-package-readmes.sh`
- `generate-meta-package.sh`
- `TYHPDEF_OWNERSHIP.md`

### Rolling report issue

Create **one** issue by hand and pin it:

- Title: `Upstream tyhpdef signals (rolling report)`
- Label: `tyhpdef-ownership-scan`
- Body: placeholder table. The workflow **replaces the body** so there is
  a single current table.

Put that issue number in the workflow as a repository variable
`UPSTREAM_TYHPDEF_REPORT_ISSUE` (Settings → Secrets and variables →
Actions → Variables).

### Scheduled workflow

Add `.github/workflows/scan-upstream-tyhpdefs.yml`:

```yaml
name: Scan upstream tyhpdef signals

on:
  schedule:
    - cron: "17 11 * * 1"
  workflow_dispatch:

permissions:
  contents: read
  issues: write

concurrency:
  group: scan-upstream-tyhpdefs
  cancel-in-progress: true

jobs:
  scan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.2"
          coverage: none
          tools: composer
      - name: Scan Packagist extras
        run: ./scan-upstream-tyhpdefs.sh --json report.json --markdown report.md
      - uses: actions/upload-artifact@v4
        with:
          name: upstream-tyhpdef-report
          path: |
            report.json
            report.md
      - name: Update rolling report issue
        env:
          GH_TOKEN: ${{ github.token }}
          ISSUE: ${{ vars.UPSTREAM_TYHPDEF_REPORT_ISSUE }}
        run: |
          gh issue edit "$ISSUE" --body-file report.md
```

Monday 11:17 UTC is an arbitrary quiet slot; `workflow_dispatch` is the
manual path. Sequential Packagist fetches; reuse the curl/`User-Agent`
pattern in `check-composer-lib-updates.sh`. Cache HTTP 200 bodies in the
job workspace for the run; do not commit the cache.

The job must not run `handoff-tyhpdef.sh`, commit, open a PR, or publish.

Once `scan-upstream-tyhpdefs.sh` supports it:

- Update the rolling report issue body.
- Open **one new issue per newly detected package** (title
  `[tyhpdef-ownership-scan] vendor/name`) if none exists. Dedup by that
  title. Skip packages already handed off, in progress, or with no new
  signal versus last week.
- Comment on an existing scan issue when the classification **changes**.

---

## Scripts

Do not create these in an ad-hoc copy-paste per package. `new-composer-lib.sh`
scaffolds the **impl** tree and calls `write-package-readmes.sh`.

### `generate-meta-package.sh`

Writes the public metapackage `composer.json` (+ README/LICENSE) from an
impl version folder. Used by publish.

```bash
./generate-meta-package.sh nesbot-carbon/3.14.0
./generate-meta-package.sh --dry-run nesbot-carbon/3.14.0
```

- Public `name`: strip `-impl` from the impl `name`.
- Public `version`: the upstream folder name (`3.14.0`), including
  prerelease. Not the impl four-part version.
- Public `require` of the impl: `~{numeric-core}.0` on stable; exact
  four-part impl version on prerelease.
- `extra.tyhp.impl`: the impl Composer name.
- Skip emitting a new public tag when that public version already exists
  and the generated `require` range is unchanged (impl-only revision).

### `write-package-readmes.sh`

```bash
./write-package-readmes.sh
./write-package-readmes.sh --package nesbot-carbon
./write-package-readmes.sh --dry-run
```

- Impl tree: [implementation README](#implementation-readme-template).
- Generated public meta: [public metapackage README](#public-metapackage-readme-template).
- After handoff: [handed-off public README](#handed-off-public-readme).
- `php/` and `php-ext-*`: [first-party README](#first-party-php--php-ext-readme-section).
- Skip `core`, `async`, `decimal`, `lambda`, `compiler`.
- Idempotent markers `<!-- tyhp-readme:start -->` / `<!-- tyhp-readme:end -->`.

### `scan-upstream-tyhpdefs.sh`

```bash
./scan-upstream-tyhpdefs.sh --json report.json --markdown report.md
./scan-upstream-tyhpdefs.sh --only nesbot/carbon
```

Discover versioned Composer-lib **impl** trees (skip `php`, `php-ext-*`,
compiled `tyhp/*`). For each folder:

1. Read impl `composer.json` (`extra.tyhp.public`, upstream pin).
2. If `extra.tyhp.ownership.status` is `handed-off`, classify as
   `handed-off` (still list in the report).
3. `GET https://packagist.org/packages/{vendor}/{name}.json` for the PHP
   package.
4. Inspect the pinned upstream version and latest stable.
5. Classify:

| Signal | Classification |
|--------|----------------|
| `extra.tyhp.package` is a non-empty object | `bundled` |
| `extra.tyhp.tyhpdef` is a non-empty string | `sibling` |
| Both | `bundled` wins; still record the sibling string |
| `replace` of the public companion without either extra | `replace-only` (incomplete — do not hand off) |
| None | `none` |

Exit 0 when signals exist. Exit non-zero only on usage / HTTP / parse
failures.

### `verify-owner-tyhpdef.sh`

Quality gate. **Required** before `handoff-tyhpdef.sh`. Never called from
the scheduled workflow.

```bash
./verify-owner-tyhpdef.sh --package nesbot/carbon --scenario bundled
./verify-owner-tyhpdef.sh --package nesbot/carbon --scenario sibling --target nesbot/carbon-tyhpdef
```

Blocking checks:

1. Packagist maintainers of `vendor/name` (print them; the human confirms
   the issue author).
2. Signal on the versions to hand off, including `replace` of the
   **public** companion with `self.version`.
3. Download the owner tree with Composer `--no-scripts --no-plugins`.
4. Not `composer-plugin`; no install/update scripts; `extra.tyhp.package`
   globs match at least one `.tyhpdef`.
5. `tyhp lint` against that tree (path-repo `tyhpdef/php` as needed).
6. `tyhp generate_tyhpdef --verify` against the pinned PHP API. Overlay
   `omit` is not a verify failure.
7. `interopContractVersion` present and ≥ the compiler’s current contract,
   or warn and the human decides.

Advisory: run this tree’s `tests/fail/*.tyhp` against the owner types.
Print diffs; do not auto-block.

### `handoff-tyhpdef.sh`

Updates **source** ownership stamps and README. Does not rewrite
already-published Packagist tarballs of the same public version.

```bash
./handoff-tyhpdef.sh --scenario bundled \
  --package nesbot/carbon \
  --from-version 3.14.0

./handoff-tyhpdef.sh --scenario sibling \
  --package nesbot/carbon \
  --target nesbot/carbon-tyhpdef \
  --from-version 3.14.0

./handoff-tyhpdef.sh --scenario reclaim \
  --package nesbot/carbon \
  --version 3.14.0
```

`--from-version` is the lowest upstream folder; later folders of the
**same major** convert together unless `--versions` lists them.

`--dry-run` prints the composer.json and README that would be written.

`check-composer-lib-updates.sh` skips `handed-off` folders.

Publish after source change still runs `generate-meta-package.sh`. For a
handed-off folder the generated public meta has no impl `require` (see
below). Only **new** public versions (a Carbon tag we have not published
yet) pick that up. Existing public tags stay as they were.

---

## Canonical package shapes

### Community (before handoff)

Impl as in [Implementation package](#implementation-package). Public meta
as in [Invariant](#invariant-the-public-require-of-the-impl-is-a-range).

### After handoff — bundled (source + any *new* public tag)

Public:

```json
{
    "name": "tyhpdef/nesbot-carbon",
    "description": "Metapackage: Tyhp types for nesbot/carbon 3.14+ ship in nesbot/carbon.",
    "type": "metapackage",
    "license": "Apache-2.0",
    "version": "3.14.0",
    "abandoned": "nesbot/carbon",
    "require": {
        "php": ">=8.2"
    },
    "extra": {
        "tyhp": {
            "ownership": {
                "status": "handed-off",
                "scenario": "bundled",
                "target": "nesbot/carbon",
                "fromUpstream": "3.14.0"
            }
        }
    }
}
```

No impl `require`. Types load from Carbon. Carbon’s `replace` keeps the
public name satisfied.

Impl source keeps `_tyhpdef/` for history and for reclaim; publish of
handed-off impl tags **stops**. Optional: mark impl `abandoned` toward
`tyhpdef/nesbot-carbon` on any *new* impl tag we do not ship.

### After handoff — sibling (source + any *new* public tag)

Same as bundled, except `"abandoned": "nesbot/carbon-tyhpdef"` and:

```json
"require": {
    "php": ">=8.2",
    "nesbot/carbon-tyhpdef": "^3.14"
}
```

The owner sibling has `extra.tyhp.package` and `replace` of the public
name. Do not path-repo the owner’s GitHub from this tree.

### Reclaim

Clear `extra.tyhp.ownership` and `abandoned`. Restore the impl `require`
range on the public meta. Scaffold/regenerate Layer 1 as
`new-composer-lib.sh` does. Publish a new impl revision and, if that
upstream version was never a public tag, a matching public tag.

---

## Scenario A — bundled types

### Package owner steps

1. Copy `_tyhpdef/` from **`tyhpdef/<vendor>-<name>-impl`**
   (`tyhpproject-packages/<vendor>-<name>-impl` on GitHub, or this source tree).
   Keep the Apache-2.0 `NOTICE` and copyright on those files. Then edit
   as you like.
2. Point `extra.tyhp.package` at those files in the PHP package.
3. Add `"replace": { "tyhpdef/<vendor>-<name>": "self.version" }`. Leave
   that line unchanged on future tags.
4. Ship a Packagist release of `vendor/name`. Do not wait for us to merge
   a PR first.
5. Open an issue on
   [tyhp-runtime-src](https://github.com/tyhpproject/tyhp-runtime-src)
   with the **tyhpdef ownership** template, from an account that matches a
   Packagist maintainer of `vendor/name`.
6. After cutover, users do not need `require-dev tyhpdef/<vendor>-<name>`
   if your package is installed and declares `extra.tyhp.package`. Leaving
   the require in place is harmless once `replace` is live.

### Maintainer steps

1. Confirm the issue author against Packagist maintainers.
2. Confirm the signal on Packagist (`extra.tyhp.package`, files,
   `replace` → `self.version` of the public name).
3. `./verify-owner-tyhpdef.sh --package vendor/name --scenario bundled`
4. Fail → label `tyhpdef-quality-failed`, comment the log, do not convert.
5. Pass → `./handoff-tyhpdef.sh --scenario bundled --package vendor/name --from-version <ver>`
6. `./write-package-readmes.sh --package <folder>`
7. Commit on `tyhp-runtime-src`.
8. Do not retag existing public versions. Stop publishing new tags for
   those folders.
9. Label `tyhpdef-handoff-bundled` and close when Packagist maintainers
   match and `replace` is live (source stamp is enough for our tree).

---

## Scenario B — sibling types package

### Package owner steps

1. Copy `_tyhpdef/` from `tyhpdef/<vendor>-<name>-impl` into a new
   Composer package under your vendor (`vendor/name-tyhpdef` or similar).
   Keep NOTICE / Apache attribution on the copied files.
2. Put `extra.tyhp.package` on **that** package.
3. Version the sibling with the PHP package (same `X.Y.Z`). `require` the
   PHP library with a real constraint (`^3.14`).
4. `"replace": { "tyhpdef/<vendor>-<name>": "self.version" }` on the
   sibling.
5. Publish it. No `composer-plugin`, no install scripts.
6. On the PHP package, set
   `"extra": { "tyhp": { "tyhpdef": "vendor/name-tyhpdef" } }` and release.
7. Open the ownership issue on `tyhp-runtime-src`.

### Maintainer steps

Same as scenario A, but:

- `verify-owner-tyhpdef.sh --scenario sibling --target vendor/name-tyhpdef`
- Confirm both Packagist packages.
- `handoff-tyhpdef.sh --scenario sibling --target …`
- Label `tyhpdef-handoff-sibling`.
- `abandoned` on any *new* public tag points at the sibling.

---

## Scenario reclaim

### Owner steps (optional)

Open the reclaim template, or comment on the original ownership issue.

### Maintainer steps

1. Confirm Packagist no longer has a usable signal for that major, or the
   owner requested reclaim.
2. `./handoff-tyhpdef.sh --scenario reclaim --package vendor/name --version <ver>`
3. `test-all-tyhpdef.sh` for that impl (and `--verify`).
4. `write-package-readmes.sh`.
5. Publish a new impl revision; publish the public meta if that upstream
   version has no public tag yet.
6. Label `tyhpdef-reclaim`.

---

## Maintainer checklist (every handoff)

```text
- [ ] Packagist maintainers match the requester
- [ ] extra.tyhp.package and/or extra.tyhp.tyhpdef live on Packagist
- [ ] replace of tyhpdef/<vendor>-<name> is self.version
- [ ] verify-owner-tyhpdef.sh passed (log attached)
- [ ] Not composer-plugin; no install scripts
- [ ] Source folders stamped handed-off; no new tags for those versions
- [ ] Did not retag an existing public Packagist version
- [ ] READMEs regenerated
- [ ] check-composer-lib-updates will skip these folders
- [ ] Catalog / @provided-by still name tyhpdef/<vendor>-<name>
```

---

## READMEs on every package

Every published public meta and every impl must have a `README.md`.

### Public metapackage README template

````markdown
<!-- tyhp-readme:start -->
# {composer_name}

Tyhp type definitions for `{upstream}` `{upstream_version}`.

```bash
composer require --dev {composer_name}:{upstream_version}
```

This is a metapackage. Composer also installs `{impl_name}` (type files).
Require **this** name, not `{impl_name}`.

See https://tyhplang.com.

## Maintain `{upstream}`? Ship the types yourself

If you are a Packagist maintainer of `{upstream}`, you can take over these
types.

Copy `_tyhpdef/` from **`{impl_name}`** (Apache-2.0; keep the `NOTICE`).
Then either:

1. **Bundle** the files in `{upstream}` and set `extra.tyhp.package` on
   that `composer.json`, plus
   `"replace": { "{composer_name}": "self.version" }`, or
2. **Publish a sibling** types package under your vendor, versioned with
   `{upstream}` (same `X.Y.Z`). Set `extra.tyhp.package` there,
   `require` `{upstream}` with a real constraint,
   `"replace": { "{composer_name}": "self.version" }`, and set
   `extra.tyhp.tyhpdef` on `{upstream}` to your sibling’s Composer name.

Ship that to Packagist first, then open an issue:

{ownership_issue_url}

We verify Packagist ownership and that the types parse and cover the PHP
API, then stop publishing community tags for those versions. We do not
transfer the `{composer_name}` Packagist name.

Full process: `TYHPDEF_OWNERSHIP.md` in
https://github.com/tyhpproject/tyhp-runtime-src
<!-- tyhp-readme:end -->
````

### Implementation README template

````markdown
<!-- tyhp-readme:start -->
# {impl_name}

Implementation package for **`{composer_name}`**.

Do not `composer require` this package in an application. Require
`{composer_name}` (same version as `{upstream}`). This package exists so
tyhpproject can ship type-only revisions (`{upstream}.1`, …) without
changing the public version.

Type files are under `_tyhpdef/`. Package maintainers of `{upstream}`
who want to ship their own types should copy that directory; see the
`{composer_name}` README.
<!-- tyhp-readme:end -->
````

### Handed-off public README

````markdown
<!-- tyhp-readme:start -->
# {composer_name}

Metapackage. Tyhp type definitions for `{upstream}` `{upstream_version}+`
now ship in **{target}**. This name exists so existing
`require-dev {composer_name}` constraints keep resolving.

You can drop `{composer_name}` from `require-dev` if `{target}` is
already installed and declares `extra.tyhp.package`.
<!-- tyhp-readme:end -->
````

### First-party (`php` / `php-ext-*`) README section

Keep the existing `php/README.md` body. These packages are not split and
are not handed to an external Composer owner.

````markdown
<!-- tyhp-readme:start -->
# tyhpdef/php-ext-{name}

Tyhp type definitions for the PHP extension `{name}`.

Install in `require-dev`. See https://tyhplang.com and `tyhpdef/php`.

This package is maintained by tyhpproject. PHP extension wrappers are
not transferred through the Composer-lib ownership process.
<!-- tyhp-readme:end -->
````

---

## Compiler follow-up (not this repo)

1. Honor `extra.tyhp.tyhpdef` on an installed PHP package: require that
   sibling if missing; load *its* `extra.tyhp.package`.
2. When bundled `extra.tyhp.package` is present on the PHP package, do
   **not** auto-install `tyhpdef/<vendor>-<name>`. If `*-impl` is also
   installed, bind the PHP package and warn.
3. `--vendor` adds the **public** companion at the **exact** installed
   upstream version. It never adds `*-impl` as a root require.
4. Walk `extra.tyhp.impl` on a public metapackage only as documentation;
   loading stays `extra.tyhp.package` on whatever is installed (the impl).

---

## Security

A pointer to a malicious types package is a Composer install-time risk
(scripts, plugins). Verification is Packagist maintainers of the **PHP**
package. The owner tree is installed with `--no-scripts --no-plugins`
during verify. Refuse `composer-plugin` / install scripts.

Do not add third parties as Packagist maintainers on `tyhpdef/*`.

---

## Layer 3 extras

Hand-written overlays that tyhpproject wants to keep **after** an owner
takes Layer 1+2 belong in a separate package or in the consumer’s
`tyhp.json` `"overlay"`. Do not keep harvesting into an owner-owned
baseline.

---

## Google API client services

`google/apiclient-services` 0.459.0 stays one upstream Composer package.
Declarations use the public metapackage and implementation split in
[Public metapackage and implementation](#public-metapackage-and-implementation).
The version directory in this repo is the implementation. Public wrappers
are generated at publish time. There is no second source tree.

Each service can be claimed on its own. A vendor claims the public name
`tyhpdef/google-apiclient-services-<service>` (service directory lowercased),
not `tyhpdef/google-apiclient-services-<service>-impl`. Common is claimed as
`tyhpdef/google-apiclient-services-common`, not its impl. The all-services
public package `tyhpdef/google-apiclient-services` is the aggregate. Its
implementation is `tyhpdef/google-apiclient-services-impl`. That impl depends
on each service’s public name and on common’s public name. It does not
require `*-impl` packages, and it does not redeclare the service classes.

| Declaration | Package |
|-------------|---------|
| `\Google\Exception`, `\Google\Model`, `\Google\Collection`, `\Google\Service`, `\Google\Service\Exception`, `\Google\Service\Resource` | `tyhpdef/google-apiclient-services-common` |
| `\Google\Client`, `\Google\Http\Batch`, and the rest of `google/apiclient` 2.19.4 | `tyhpdef/google-apiclient` |
| `\Google\Service\<Service>` and the classes harvested from that service’s `Something.php` plus `Something/` directory | `tyhpdef/google-apiclient-services-<service>` |

`\Google\Service::getClient()` returns `\Google\Client` and `createBatch()` returns `\Google\Http\Batch`. Those two types stay on `tyhpdef/google-apiclient`.

`tyhpdef/google-apiclient` requires `tyhpdef/google-apiclient-services-common`, not the common impl and not `tyhpdef/google-apiclient-services`. Require the all-services public package for every API. Require one service’s public package for that API.
