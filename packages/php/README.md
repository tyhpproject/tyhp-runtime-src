# tyhpdef/php

Type definitions (`.tyhpdef`) for always-present PHP built-ins so the Tyhp compiler can type-check `\strlen`, `DateTime`, SPL, and similar APIs.

This package covers PHP 8.2+ **Core**, **date**, **filter**, **hash**, **json**, **libxml**, **pcre**, **random**, **Reflection**, **SPL**, and **standard**. Version-specific APIs are gated with `declare(php=…)` / `#[\Tyhp\Php]` against the project's `output.phpVersion`.

This package is **tyhpdef-only**. Scalar and callable extension methods (`$s->length()`, `$arr->mapped(...)`) ship in `tyhp/core`.

## Layout

| Path | Role |
|------|------|
| `_tyhpdef/*.tyhpdef` | Baseline signatures for the always-present extensions |
| `_tyhpdef/overlays/stubs/` | Generated stub overlays, loaded first |
| `_tyhpdef/overlays/` | Hand-written overlays, loaded last |
| `tests/` | Package tests |

`extra.tyhp.package` on `composer.json` lists baseline tyhpdefs in `include`, then overlays (stubs first, hand-written last). Overlay globs are not also listed in `include`.

Until Packagist lists this package, use a path repository that points at this directory inside a Tyhp compiler checkout.

## Adding a PHP minor

When a new PHP minor is released, follow [ADDING_A_PHP_VERSION.md](ADDING_A_PHP_VERSION.md) (snapshots, `--php-targets` regen, overlay stamp, `supported-minors`, tests matrix). Do not add a `php-8.x` package fork.
