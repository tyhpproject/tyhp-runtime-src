# Changelog

Notable changes to the Tyhp runtime packages (`tyhp/*` and `tyhpdef/*`) built from this repository. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Each package has its own version; see [VERSIONING.md](VERSIONING.md).

## [Unreleased]

## 2026-11-04

### Version Bumps
- `tyhp/core`: 0.4
- `tyhp/async`: 0.4
- `tyhp/decimal`: 0.4
- `tyhp/lambda`: 0.4
- `tyhp/parallel`: 0.4
- `tyhp/io`: 0.4
- `tyhp/compiler`: 805.1.2-beta.4
- `tyhpdef/php`: 0.0.3

### Added

- `tyhp/core`: `#[\Tyhp\PropertyType]`, the attribute the compiler stamps on properties and promoted constructor parameters whose PHP hint loses the Tyhp type.
- `tyhp/core`: `Type::parse()` reads a type spelling such as `array<App\Post>`, `?App\Table<App\Blog>`, or `struct App\Address`.
- `tyhp/core`: `Type::fromProperty()` returns a property's declared Tyhp type from its `#[\Tyhp\PropertyType]` stamp, or the reflected PHP type when there is none.
- `tyhp/core`: `Type` instance methods `typeArguments()`, `typeArgument()`, `elementType()`, `keyType()`, `fieldType()`, and `erased()`.
- `tyhp/core`: `GenericObject::propertyType()` returns the type registered for a property.
- `tyhp/lambda`: `LambdaExpression` (nested `fn`), `ArrayExpression` and `ArrayItem` (array literals), and `NullSafeMethodCallExpression` (`$x?->m()`) nodes, with serializer structs and `ExpressionVisitor` methods that default to `null`, so existing visitors keep working.
- `tyhp/lambda`: `Expr::closure()` and `Expr::arrayOf()` builders. `nullSafe()` now also applies to the next `call()`.
- `tyhp/lambda`: `ExpressionShape`, `ExpressionSerializer::shapeKey()`, and `ExpressionSerializer::shapeParameters()` key a tree's structure without its constant values, so a translation can be cached per shape.

### Changed

- `tyhp/compiler`: installs compiler v805.1.2-beta.4.
- `tyhp/lambda`: Property nodes built by `Expr` and `PropertyPath` carry the property's declared type from `Type::fromProperty()` instead of `mixed`, and a tree's return type keeps the full type (`array<App\Post>`) when the closure declares only its PHP form (`array`). This needs the new `tyhp/core` `Type` API.
- `tyhp/core`, `tyhp/async`, `tyhp/decimal`, `tyhp/lambda`, `tyhp/parallel`, and `tyhp/io`: source version `0.4` (`802.0.4` through `805.0.4`).
- `scripts/publish-runtime-packages.sh`: refuses a working tree with uncommitted changes (`--allow-dirty` overrides), and publishes `tyhp/compiler` only when the GitHub Release `v<version>` on `tyhpproject/tyhp` is public with `checksums.txt` and the five binaries the shim downloads.
- `scripts/publish-runtime-packages.sh`: `--package NAME` rebuilds only that package instead of running the full `base-build-all.sh`.
- `scripts/publish-runtime-packages.sh`: new `--dry-run` (with `--no-build`) builds and compares with the published tags, then prints the tags it would push without cloning, pushing, or calling Packagist write APIs.
- `scripts/publish-runtime-packages.sh`: new `--resume` finishes a compiled package whose `80N.X.Y` tags were only partly pushed, after checking that the existing tags hold the same trees as the build.

### Fixed

- `tyhp/lambda`: Running a tree without a backing closure now skips the rest of an access chain when a `?->` meets `null`, as PHP does.
- `test-tyhpdef.yml`: the workflow downloads the newest non-draft compiler release (prereleases included) and checks it against that release's `checksums.txt`, instead of failing because no stable release exists. Manual runs take a `tyhp_tag` input.

## Baseline - 2026-10-23

Tracking starts at commit `7e73f57`. At that point the compiled `tyhp/*` packages are at source version `0.3` (published as `802.0.3` through `805.0.3`), `tyhpdef/php` is at `0.0.3`, and `tyhp/compiler` is at `805.1.2-beta.3`. Other packages are at the version in their `composer.json`. Earlier changes are not listed.
