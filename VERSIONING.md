# Versioning

The Tyhp compiler's `VERSIONING.md` is the source of truth for compiler
releases. This file is the runtime-package slice: how `tyhp/*` and `tyhpdef/*`
versions relate to PHP and to upstream Composer libraries.

## Runtime Composer packages

The compiler version (for example `805.1.0-beta.1`) means the compiler can emit
for PHP up through 8.5. It still emits for 8.2, 8.3, and 8.4 when
`output.phpVersion` says so. That number is not the runtime package version.

Each of `tyhp/core`, `tyhp/async`, `tyhp/decimal`, `tyhp/lambda`, and
`tyhpdef/php` has its own version in that package's `composer.json`. Bump a
package without bumping the compiler.

**Compiled** `tyhp/*` helpers (`core`, `async`, `decimal`, `lambda`) publish as
`80N.X.Y` where `80N` is the PHP target (`802` … `805`). Example: `tyhp/core`
source `1.4` → Packagist `802.1.4`, `803.1.4`, `804.1.4`, `805.1.4`. Source
`0.0` → `802.0.0` … `805.0.0`. A library that supports several PHP versions ORs
majors and keeps that package's X: `803.1.* || 804.1.* || 805.1.*` for core
`1.y`.

**First-party `tyhpdef/*`** (`tyhpdef/php`, `tyhpdef/php-ext-*`) publish the
version in that package's `composer.json` as the Packagist / git tag. They are
one tree across PHP 8.2–8.5 (`"php": ">=8.2"`). They do not use `80N.X.Y`.

## Composer-lib companions

Community wrappers (not `tyhpdef/php`, not `php-ext-*`, not compiled `tyhp/*`)
are two packages:

| Package | Version | Git tag |
| --- | --- | --- |
| Public metapackage `tyhpdef/<vendor>-<name>` | Exact upstream string (`3.14.0`) | Upstream string |
| Implementation `tyhpdef/<vendor>-<name>-impl` | Four-part (`3.14.0.0`, then `3.14.0.1`) | Four-part version |

The public package requires the impl with `~{numeric-core}.0` on a stable
upstream version (`~3.14.0.0` is `>=3.14.0.0 <3.14.1.0`). A prerelease upstream
version requires the exact impl version. Source folders are named with the
upstream string (`packages/nesbot-carbon/3.14.0/`), not the four-part version.

`tyhp/compiler` is a single-version PHP shim tagged from its `composer.json`
version, not `80N.X.Y`.
