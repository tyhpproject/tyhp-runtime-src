# tyhp-runtime-src

Source for Tyhp's Composer packages: compiled helpers (`tyhp/core`, `tyhp/async`,
`tyhp/decimal`, `tyhp/lambda`, `tyhp/parallel`, `tyhp/io`, `tyhp/compiler`) and type-definition packages
(`tyhpdef/php`, `tyhpdef/php-ext-*`, and Composer-lib companions).

The compiler lives in [tyhp](https://github.com/tyhpproject/tyhp). Language docs
are at [tyhplang.com](https://tyhplang.com). This repository is Apache 2.0.

Published package repositories are on
[tyhpproject-packages](https://github.com/tyhpproject-packages). This repository
is the source tree. It is not itself a Composer package.

## Layout

Package trees are under `packages/`:

| Folder | Contents |
| ------ | -------- |
| `packages/tyhp/<pkg>/` | Compiled runtime packages (`core`, `async`, `decimal`, `lambda`, `parallel`, `io`, `compiler`), built from `tyhp_src/` |
| `packages/tyhpdef/<pkg>/` | Type-definition packages: `php`, `php-ext-*`, and Composer-lib companions (`<vendor>-<name>/<upstream-version>/`) |
| `packages/dist/` | Generated per-PHP-version output of `base-build-all.sh` (gitignored) |

Build and scaffold scripts live in `packages/` itself
(`base-build-all.sh`, `test-all-tyhpdef.sh`, `new-composer-lib.sh`,
`new-php-ext.sh`). Ownership scripts and `TYHPDEF_OWNERSHIP.md` are at the
repository root. `scripts/publish-runtime-packages.sh` publishes to
`tyhpproject-packages`.

Clone this repo next to the compiler:

```bash
git clone https://github.com/tyhpproject/tyhp-runtime-src.git
git clone https://github.com/tyhpproject/tyhp.git
```

The compiler looks for `TYHP_RUNTIME_SRC`, then a sibling
`../tyhp-runtime-src/packages`.

## Build and test

Build the compiler first (`dotnet build` in the `tyhp` checkout). Scripts
default `TYHP_DLL` to `../tyhp/bin/Debug/net9.0/tyhp.dll`. Set `TYHP_DLL` to
override that path. A released CLI can be passed as `TYHP_BIN` to
`packages/test-all-tyhpdef.sh`.

```bash
export TYHP_DLL="$PWD/../tyhp/bin/Debug/net9.0/tyhp.dll"
./packages/base-build-all.sh decimal
./packages/test-all-tyhpdef.sh --package php-ext-bz2
```

`base-build-all.sh` emits compiled packages for PHP 8.2–8.5. Do not hand-edit
generated PHP under `packages/tyhp/*/src/`. Fix the Tyhp compiler or the package's
`tyhp_src` / tyhpdefs and re-emit.

Lint every tyhpdef package:

```bash
TYHP_DLL=../tyhp/bin/Debug/net9.0/tyhp.dll ./packages/test-all-tyhpdef.sh
```

## Owner-maintained tyhpdefs

Composer-lib companions are an implementation package
(`tyhpdef/<vendor>-<name>-impl`, four-part version) and a public metapackage
(`tyhpdef/<vendor>-<name>`, the upstream version). The process for handing
types to the PHP package maintainer is [TYHPDEF_OWNERSHIP.md](TYHPDEF_OWNERSHIP.md).

Packagist credentials stay in gitignored `scripts/packagist.credentials`.
See `scripts/packagist.credentials.example`. When that file is present,
`scripts/publish-runtime-packages.sh` queues a Packagist update for each
published repo that has git tags Packagist has not crawled yet.
`--create-packagist-packages` registers packages that are not on Packagist.
`scripts/update-packagist-packages.sh` runs that same update without building
or pushing.

## Versioning

Compiler versioning stays in the compiler repository. Runtime package versions
are described in [VERSIONING.md](VERSIONING.md).

## License

[Apache License 2.0](LICENSE.txt). Contributions follow
[CONTRIBUTING.md](CONTRIBUTING.md).
