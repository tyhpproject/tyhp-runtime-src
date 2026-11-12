# Changelog

Notable changes to the Tyhp runtime packages (`tyhp/*` and `tyhpdef/*`) built from this repository. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Each package has its own version; see [VERSIONING.md](VERSIONING.md).

## [Unreleased]

### Added

- `tyhpdef/php-ext-tyhp`: type definitions for the tyhp extension. The overlay types `\Tyhp\Ext`: the parse graph, bind type arguments, closure opcode trees, and DNS results. `typeIs` takes any object and `bindGet` / `bindPut` take any target. `typeParse('decimal')`, `typeParse('?decimal')`, `typeParse('null|decimal')`, and `typeParse('decimal|null')` return the known decimal node.
- `tyhp/lambda`: `_expr($sourceType, ...$segments)` and `_path($sourceType)` are the root-function spellings of `Expression::of` and `PropertyPath::on`. Each is skipped when a global function of that name already exists.
- `tyhp/async`: `Tyhp\async(callable $fn): Promise` and `Tyhp\await(Promise $promise, ?int $timeoutMs = null, ?CancellationToken $token = null): mixed`. Inside a loop fiber, `await` suspends. From synchronous PHP it runs the event loop until the promise has settled and no immediate work is left. A timeout uses `Promise::timeout`. A cancelled token throws `OperationCancelledException`. Inside the running loop but outside a loop fiber, it throws the same re-entrancy `\LogicException` as `EventLoop::run`. `$promise->wait(?int $timeoutMs = null, ?CancellationToken $token = null)` is that same call. Null or a negative timeout, including `-1`, waits with no deadline.
- `tyhp/core`: `#[\Tyhp\InferGeneric]` marks a generic function or method whose type parameters all come from its arguments. The declared name keeps the value signature.
- `tyhp/rhetoric`: a new package for typed Laravel database access: models, a unit-of-work context, queries, save, mapping, migrations, and scaffolding.
- `tyhp/core`: `tyhpPropertyHooks()` returns an object's hooked-property bag. `hookedProperties()`, `isBackedProperty()`, and `propertyType()` on the bag list the hooked properties, say whether each one stores a value, and give its type.
- `tyhp/core`: `\Tyhp\Lazy` creates a PHP 8.4 lazy ghost or proxy (`ghost`, `proxy`, `isLazy`, `initialize`, `reset`, `preset`). `ghost` and `proxy` do not bind a generic class's type arguments; the initializer or factory constructs the instance. An exception thrown by a ghost initializer propagates and leaves the object lazy. The PHP 8.4 and 8.5 builds call the native reflection APIs. The PHP 8.2 and 8.3 builds construct eagerly: the initializer or factory runs before the call returns, and the object is not lazy.
- `tyhp/core`: `Generic::bind()` on a class-string returns a binder with `ghost(...)`. The PHP 8.4 and 8.5 builds return a lazy ghost and bind that binder's type arguments on that instance before the constructor. The PHP 8.2 and 8.3 builds match `new()`. A class with no instance properties (which PHP cannot make lazy) still has its constructor run, and a class with no constructor accepts no arguments, as with `new()`. `new()` and `__invoke()` stay eager. Binding a function or method still returns that closure.
- `tyhp/core`: `Type::int` and the other builtin names are `BuiltIn` constants, usable as parameter defaults and attribute arguments. `Type::int()` is still the type factory. `TypeLike` is `toType()` and `or()`. `Type::arg()` accepts a `Type`, a `BuiltIn`, or a loaded class, interface, or enum name. `Type::list()`, `Type::map()`, an array-first `Type::struct()`, and `Type::optional()` take a `TypeLike` or class name. `Type::generic()` takes type arguments by position, by declared parameter name, or mixed, and reads the names from a generic class's `new()` binder, ignoring a trailing variadic that a child with fewer type parameters adds to override it. `nullable()` is not a `TypeLike` method; `Type::nullable($type)` and `$type->nullable()` stay the factory pair, and `Type::optional($type)` and `$struct->optional($name, $type)` are the same pair.
- `tyhp/lambda`: `Expression::fromClosure` and `PropertyPath::fromClosure` build a tree from a closure. The tyhp extension's `closureTree` is used when it is loaded and at least 0.1.0; otherwise the closure's source is tokenized, and both accept and reject the same closures: a return type PHP does not check (`mixed`, or a literal the type contains) is accepted, a default parameter value is rejected, an array of literals is one constant, and `PropertyPath::fromClosure` needs a single parameter. Without the extension, two closures on one line with the same parameters cannot be told apart and are rejected. The package suggests `ext-tyhp` and does not require it.

### Changed

- `tyhp/lambda`: struct construction now uses `new Name[...]`.
- `tyhp/io`: struct construction now uses `new Name[...]`.
- `tyhp/parallel`: struct construction now uses `new Name[...]`.
- `tyhp/compiler`: struct construction now uses `new Name[...]`.
- `tyhp/rhetoric`: struct construction now uses `new Name[...]`.
- `tyhp/io`: `Dns::resolveAsync` uses the tyhp extension when it is loaded and at least 0.1.0. The lookup runs on a thread and the event loop waits on its pipe. A missing or older extension keeps Offload. An unresolved host rejects the same way with or without the extension: `IoException` with `Could not resolve host {host}.`, or, when `Offload::usesWorkers()` is true, a `ParallelException` whose remote class is `IoException`. A cancelled token rejects with `OperationCancelledException`. The package suggests `ext-tyhp` (`pie install tyhp/ext-tyhp`) and does not require it.
- `tyhp/core`: `Type::is`, `Type::parse`, and `Generic::bind` call the `tyhp` extension when it is loaded and at least 0.1.0. A missing or older extension is ignored. The package suggests `ext-tyhp` (`pie install tyhp/ext-tyhp`) and does not require it.
- `tyhp/core`: `_gnrc` type arguments are `\Tyhp\TypeLike|string|null`, the same as `Generic::bind()`. `Type::arg()` normalizes a `Type`, a `BuiltIn` such as `Type::int`, a class-string, or `null`.
- `tyhp/async`: `#[\Tyhp\InferGeneric]` on `Tyhp\async`, `Tyhp\await`, `_async`, `_await`, `_asyncMain`, and `Promise::_async`, `_await`, `then`, `resolve`, `resolved`, `run`, `all`, `whenAll`, `race`, `whenAny`, `any`, and `timeout`. `Promise::allSettled` and `batch` stay binders: `allSettled`'s key is not inferable from `array<TKey, Promise<T>>`, and `batch`'s item and return type parameters are not inferable from `array<TItem>` and `callable(TItem): Promise<TBatchReturn>`. `reject`, `rejected`, and `withResolvers` stay binders because their type parameter appears only in the result (`Promise::reject()($e)`, `Promise::withResolvers(Type::int)()`). `EventLoop::run` stays as it is. Root-function docblocks name `_async`, `_await`, and `_asyncMain`.
- `tyhp/core`: `Lazy::proxy`, `Lazy::reset`, and `PropertyAccessorObject::register` are `#[\Tyhp\InferGeneric]`. `Lazy::ghost`, `Json::decode`, and `Json::tryDecode` stay binders (`Json::tryDecode($T)($json, $out)`); `tryDecode`'s type parameter appears only in its by-reference `$out`.
- `tyhp/lambda`: `Expression::of`, `PropertyPath::on`, and `PropertyPathBuilder::of` are `#[\Tyhp\InferGeneric]`. `Expression::lambda` and `PropertyPath::from` stay binders; their type parameter is the callable itself.
- `tyhp/rhetoric`: `Context::entry` and `Tracker::entry` are `#[\Tyhp\InferGeneric]`.
- `tyhp/async`: `Deferred`'s executor returns `T|Promise<T>`, the type `Promise`'s constructor requires. A resumed value that is neither is rejected, which is the same outcome `doResolve` produced for that value.
- `tyhp/parallel`: `Parallel::invokeAsync` rethrows from its per-task `catch` handler after recording the error. `catch` requires the handler to return the promise's type.
- `tyhp/core`: `ArrayAccessShape::offsetGet` and `offsetSet` no longer declare a type parameter. The key is `__IndexKeys<TStruct>` and the value is `__IndexValueTypes<TStruct>`, the same types as the `\ArrayAccess` methods they implement.
- `tyhp/core`: `Generic::bind()` on a function resolves a layout-2 binder written as the function's short name in that function's namespace.
- `tyhp/core`: `Generic::bind()` accepts callable layouts 1 and 2. A layout-2 binder is the declared function or method, and each of its parameters is `TypeLike|string|null`. Layout 1 is still a nullable `Type`. An unsupported layout reports the layouts this runtime accepts: `[1, 2]`.
- `tyhpdef/php`: `ReflectionClass<T>::newLazyGhost` and `resetAsLazyGhost` take `callable(T): void`. `newLazyProxy` and `resetAsLazyProxy` take `callable(T): T`.
- `tyhp/async`: `DisposableScope` and `DisposableHelper::dispose` skip an uninitialized lazy object instead of calling `dispose()` or `disposeAsync()` on it. Every build (802 through 805) makes the same test: false below PHP 8.4, and on PHP 8.4 or newer `ReflectionClass::isUninitializedLazyObject`, so a build for an older PHP still skips ghosts when it runs on 8.4 or newer. The check does not initialize the object.
- `tyhp/core`: `Type::struct($name, $fields)` with no third argument requires every field unless it is wrapped in `Type::optional(...)`. It required none before. Passing `$requiredKeys` (including an empty list) behaves as before.

### Fixed

- `tyhp/io`: the dist package autoloads the struct type registration files (`src/Tyhp/Io/Process/_functions.php`, `src/Tyhp/Io/Http/_functions.php`, `src/Tyhp/Io/Db/_functions.php`, and `src/Tyhp/Io/Redis/_functions.php`).
- `tyhp/io`: `Process::runAsync` reads `ProcessOptions` fields as array keys (`cwd`, `env`, `input`, `timeoutMs`).
- `tyhp/core`: `Type::or()` of two identical types returns that type instead of throwing `InvalidTypeException`, so `#[\Tyhp\InferGeneric]` calls whose arguments share a result type infer it.
- `tyhp/core`: the `_gnrc` root-function docblock names `_gnrc`, including the line about a global `_gnrc` already existing.
- `tyhp/core`: the `GenericRuntime` attribute docblock describes a non-erased binder under its declared name (`layouts: [2]`, `Json::decode($type)($json)`), an `#[\Tyhp\InferGeneric]` `name__tyhpGeneric` companion (`layouts: [1]`), an erased method at layout 1, and a class factory at layout 1 (`new_<Mangled>__tyhpGeneric`). Supported callable layouts are `[1, 2]`. `Generic::bind` accepts both.
- `tyhpdef/illuminate-database`: `PendingHasThroughRelationship` now passes the third `HasOneOrMany` type argument (`mixed`).

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
