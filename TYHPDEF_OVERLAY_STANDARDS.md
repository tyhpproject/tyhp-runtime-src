# Tyhpdef overlay standards

How to write **hand-written Layer 3** overlays for runtime packages (`tyhpdef/php` and `tyhpdef/php-ext-*`).

Compiler mechanics (load order, `partial`, `omit`, stamps, diagnostics) are specified in [Tyhpdef Overlays](../../docs/content/tyhpdef_overlays.md). Declaration order inside a file is [TYHPDEF_ORDERING.md](TYHPDEF_ORDERING.md). This page is the authoring contract: what belongs in Layer 3, which overlay form to use, and how to type it.

## Layers

`extra.tyhp.package` lists Layer 1 in `"include"`, then overlays in array order (last Tyhp name wins). Stub globs first, hand-written last. Overlay globs must not also appear in `"include"`.

| Layer | Path | Who writes it |
|-------|------|----------------|
| 1 baseline | `_tyhpdef/*.tyhpdef` | `tyhp generate_tyhpdef` (Reflection / parsed PHP). `AUTO-GENERATED`. |
| 2 stub harvest | `_tyhpdef/overlays/stubs/*.tyhpdef` | Generator from Psalm / PHPStan / Phan / PhpStorm. Safe to regenerate. |
| 3 hand | `_tyhpdef/overlays/*.tyhpdef` (not under `stubs/`) | Humans and `tyhp overlay create`. Never regenerated. |

Identity is the **Tyhp name**, not the PHP name. Within one glob, paths expand lexicographically.

Do not hand-edit Layer 1 or Layer 2 to “fix” types. Regen replaces those trees (`--overwrite` replaces Layer 1 and `overlays/stubs/`; it does not touch `_tyhpdef/overlays/*.tyhpdef`). After Layer 1 regen, restamp: `tyhp overlay stamp` (see [CLI: Overlay](../../docs/content/cli_overlay.md) and each package’s `ADDING_A_PHP_VERSION.md`).

Tyhp-owned array-shape structs belong in Layer 3, not in stub harvest (`overlays/stubs/SOURCES.md`).

## ALWAYS

- Put lasting signature work in Layer 3. Leave Layer 1 as the generated baseline.
- Put `// @overlay-against:` on every replace / `partial` / `omit` that targets a Layer 1 symbol. Compact Layer 1 form: one line for a function; types stamp the **header only**, members stamp separately. Compact stamps omit `public` / `protected` / `private` / `final` / `abstract` / `static`. When a declaration has a docblock or attributes, the stamp is the last line before the keyword (docblock, then attributes, then stamp). `tyhp overlay stamp` rewrites these from current Layer 1. Mismatch is `TYHP8021` (still applied; `--strict` / `build.strictMode` elevates). An `as` alias stamps the **PHP original**, not the alias name. Name-only stamps (`function foo`) are valid on `partial function`. `extern` is not stamped.
- Prefer header-only `partial class Foo<T = mixed> implements …;` (also interface / trait / enum) when only generics / `extends` / `implements` / `as` change. Prefer a second brace `partial class Foo { … }` for member edits. A full `class Foo { … }` overlay replaces the **whole** type, including members you did not restate. Header `;` is overlay-only (`TYHP8034` in include).
- A full overlay `function` of a name **replaces the entire overload set**. Restate every overload that should remain. Later same-name functions in that overlay file append as overloads. `function phpName as tyhpName(...)` **adds** the alias and leaves the original Tyhp name.
- `partial function name;` is name-only: it merges attributes onto the live callable and does not change parameters, return type, or generics. That is the `Pure.*.tyhpdef` pattern (`#[\Tyhp\Optimize\Pure]` then `partial function strlen;`). A parameter list on `partial function` is a parse error, not a full replace. Match the **current** Tyhp name (after any earlier rename).
- Apply generics when they make the declaration better (keys/values, class defaults, callable shape).
- Use the most specific types the checker can consume, for example:
  - `__ClassName<T>` / `__InterfaceName` / `__TraitName` / `__EnumName` / `__FunctionName` / `__ConstName` where the value is a symbol name, not a bare `string` (`class_exists`, `get_class`, …).
  - `array<TKey, TValue>` (or a `struct`) instead of `array`.
  - `callable(…): R` instead of bare `callable`, including intersection of arities for optional parameters (`ErrorHandlerCallback` in `Ext.Core.tyhpdef`).
  - `__CallableReturnType` / `__CallableParametersStruct` / `__CallableParametersTuple` / `__CallableParametersRest` for callback-forwarding APIs (`Ext.Standard.Callables.tyhpdef`).
- Prefer a `struct` (and/or `type` alias) for a fixed array shape (`GcStatus`, `DateParseResult`, `LastError`, `ImageSizeInfo`). Keep those structs in Layer 3.
- Add overloads that narrow on argument literals (`true` / `false`, integer literals) when PHP’s result depends on those arguments (`json_decode` `$associative`, `filter_var` filter ids, `parse_url` component). Tyhpdef `const int NAME ?? N` with a known start value is literal `N` at overload selection; bitwise `|` of those constants is not folded, so keep an `int` catch-all for those calls (`Ext.Filter.tyhpdef`, `Ext.Json.tyhpdef`).
- Use type-guard returns for `is_*` and existence checks: `$value is int`, `$class is \__ClassName<T>`, `$constant_name is __ConstName`. Overlay aliases the same way (`is_integer`, `is_long`, `is_double`). The subject of a type guard is often `mixed`; that is expected.
- Gate PHP-version differences with `declare(php="…")` and/or `#[\Tyhp\Php("…")]`. `#[\Tyhp\Php]` is illegal on `struct` and on `extension { }` — wrap those in `declare(php=…)`. An unsatisfied gate on an overlay member is skip-before-bind (same as an inactive `declare` block).
- Organize the file according to [TYHPDEF_ORDERING.md](TYHPDEF_ORDERING.md). Header-only `partial Type;` plus a following body for the same type stay together: header, then body. Overloads that share one identifier stay together in existing relative order.
- Keep purity (`#[\Tyhp\Optimize\Pure]`) in `Pure.*.tyhpdef` when the package splits that way (`Ext.Standard.tyhpdef` vs `Pure.Standard.tyhpdef`). Do not mark impure I/O as Pure.

## NEVER

- Do not edit generated Layer 1, `overlays/stubs/`, or `vendor-tyhpdef/`. Do not list overlay globs in `"include"`.
- Do not use `omit` or overlay last-wins replace in an include / baseline file (`omit` is overlay-only, `TYHP8017`). Do not combine `partial`, `omit`, `deprecated`, `obsolete`, and `extern` on the **same** declaration (`TYHP8018`). `omit` of a member inside an overlay `partial` type is allowed.
- Do not overlay a hollow `class \Foreign\Type {}` for a type this package does not own. `tyhp overlay create` refuses `extern` (`TYHP7904`).
- Do not write `partial function foo as bar` (or `partial class Foo as Bar`) in a later overlay after an earlier overlay already renamed that name. Match the live Tyhp name.
- Do not use a full type overlay when a header `;` plus brace `partial` would keep unlisted members.
- Do not drop overloads you still need: the first full overlay of a function name replaces the whole set.
- Do not use a generic-capable type in its bare form when a parameterized form is accurate:
  - `array` → `array<TKey, TValue>` or a `struct`
  - `callable` → `callable(Args ...): TReturn`
  - `__ClassName` → `__ClassName<T>` when `T` is known
  - Generic bounds: `TArray extends array<K, V> = array<K, V>`, not `TArray extends array = array`; `TSubject extends array<int|string, string>|string` rather than `TSubject extends array|string` when keys/values are known
- Bare `array` is still required in some contracts (PHP’s array type in `$value is array`, some catch-alls). Use it there; do not use it as a shortcut for an unmodeled list or shape.
- Do not use `mixed` as a stand-in for a list, shape, or symbol name. `mixed` is appropriate for type-guard subjects, truly untyped PHP values, and APIs that are mixed in PHP (`Throwable::getCode()` on the interface).
- Do not put Tyhp-owned array-shape structs in Layer 2.

## Overlay forms (short)

| Form | Effect |
|------|--------|
| Ordinary `function` / `class` / … | Replace that Tyhp name (functions: whole overload set) or add if missing |
| `partial Type { members }` | Listed members replace; new members add; written header ignored |
| `partial Type<…> implements …;` | Written header clauses replace; omitted clauses and members stay |
| `partial function name;` | Merge attributes; signature unchanged |
| `function php as alias(…)` | Add alias; original Tyhp name remains |
| `omit` | Remove that Tyhp name (skeleton signature is enough) |

Missing overlay `partial` target: warning `TYHP8019` (type) / `TYHP8031` (function), skip. Overlay `partial` of `extern`: `TYHP8030`.

## File header

Hand overlays in `tyhpdef/php` start with `<?tyhpdef` and a file comment that says they are Layer 3, live under `_tyhpdef/overlays/*.tyhpdef` (not `stubs/`), and are never regenerated. Keep `Pure.*` vs `Ext.*` (and `Ext.Standard.Callables.tyhpdef`) as separate files when the package already splits that way.

## Commands

```
tyhp overlay create \Iterator
tyhp overlay stamp
tyhp overlay stamp \Iterator
tyhp symbol_tree --filter=Iterator
```

`create` copies the current declaration into a hand overlay (after overlays already in the package). `stamp` rewrites `@overlay-against` from Layer 1 (include; overlays not applied). `symbol_tree` dumps bound names after overlays.

For PHP minor regen, follow the package `ADDING_A_PHP_VERSION.md`: regenerate Layer 1 / Layer 2, then stamp hand overlays. Review `TYHP8021` on those files.
