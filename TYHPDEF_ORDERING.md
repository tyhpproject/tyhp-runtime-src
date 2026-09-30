# Tyhpdef declaration order

Use this when reordering items inside a `.tyhpdef` file under `packages/`. Follow it for every cleanup so files stay consistent.

Reorder **within the same file only**. Do not move declarations between files. Keep every declaration, attached comment, and docblock. Delete only dissolved thematic function-group comments (see below).

## Rules that apply everywhere

- A docblock or comment immediately above a declaration moves with that declaration (including `// @overlay-against:` stamps).
- Leading file-header comments (copyright, layer banner, file description) stay at the very top, after `<?tyhpdef`.
- Kind-section divider comments (for example `// Constants`) stay at the start of that section after reorder.
- Thematic function-group comments inside a section (`// --- string ---`, `// --- arrays ---`, and similar `// --- … ---` labels) are dissolved by alphabetical order. Delete those comments; do not leave them orphaned at the start of the functions list.
- Alphabetical means case-insensitive, by the declared identifier. Ignore a leading `\`. Ignore a leading `$` on variables and properties. Identifiers sort as strings, not by embedded numbers: `CallableArgs10` before `CallableArgs2`.
- `function X as Y` is sorted by `X` (the name before `as`), not `Y`.
- `deprecated` / `obsolete`, `partial`, `omit`, `final`, `readonly`, and attributes do not change which section an item belongs in. There is no separate omit section.
- `omit` of a function, type, or extension is that kind, sorted by the omitted identifier. An `omit final class Foo` sorts with classes/enums/interfaces/traits by the type name `Foo`.
- Overloads and split `partial` pieces that share one identifier stay together in their existing relative order. Place the group by that identifier.
- Header-only `partial Type;` plus a following body for the same type stay together: header, then body.
- `extension operator` is an operator overload (class/extension member), not a file-level `extension { }` declaration.
- Do not reformat declaration bodies. Only change order (and split mixed `declare(php=…)` wrappers as specified below).
- Preserve attributes, indentation, and existing blank-line style. A blank line between major sections is fine.
- If a construct is not in this list and it is not obvious where it belongs, stop and decide with a human before guessing.

## Version-gated groups

Gates use `declare(php="…")` blocks and `#[\Tyhp\Php("…")]` attributes (Composer version-constraint strings). **They use the same version-sort buckets.** Do not make a separate attribute-gated section versus a declare-wrapped section. Group by the version constraint only.

For each kind of declaration:

1. Ungated items of that kind first, alphabetically.
2. Then gated groups of that kind, by **version constraint**: lowest PHP version, then highest as tiebreaker. Treat a missing lower bound as unbounded below, and a missing upper bound as unbounded above.

Examples:

- `php >= 8.3` before `php >= 8.4`
- `<8.3` before `>=8.3`
- `>=8.3 <8.4` before `>=8.3` (same lower bound; finite upper bound before unbounded)
- `>=8.3` before `>=8.3 <8.4` is wrong

Within a gated group, items are alphabetical (string sort, case-insensitive; leading `\` ignored).

Keep `declare(php=…)` wrappers per kind after splitting mixed wrappers (see below). Attribute-gated items with the same constraint belong in that same version group:

- Items that were in a declare stay in a homogeneous declare wrapper for that kind+version.
- Attributed items of the same kind+version sit in that same bucket: inside the declare wrapper if one exists for that kind+version (keep `#[\Tyhp\Php]` on the declaration if it was there); otherwise keep `#[\Tyhp\Php]` on the item.
- If putting attributed items inside a declare is awkward, list them immediately with the declare group in the same bucket, items alphabetical.

Inside a type or extension, `#[\Tyhp\Php]` stays on the member and the member sorts with its kind. Do not split a `declare(php=…)` block inside a type; if that block mixes member kinds, stop.

## Mixed `declare(php="…")` — split

If a declare wrapper contains **more than one kind** (for example constants AND functions), **split into separate homogeneous declare blocks** with the **same php constraint**. Example: one `declare(php=">=8.5")` with 10 consts + 3 functions becomes `declare(php=">=8.5") { consts A–Z }` in the gated-constants section AND `declare(php=">=8.5") { functions A–Z }` in the gated-functions section.

Do not lose comments. Declaration comments stay with declarations. If the wrapper itself has a comment, put it on the first split block (constants, since they appear earlier in file order); do not duplicate it onto the function block.

Homogeneous declares stay intact; only sort contents inside.

## File-level order

After the file header (`<?tyhpdef`, LAYER/AUTO-GENERATED comments, copyright):

1. `namespace X { }` blocks, before anything else (before global use, use, externs, constants, functions, types, extensions). Namespaces ordered **alphabetically** by namespace name. Keep each wrapper intact. Reorder inner contents with the same rules as file-level (types inside use class/enum/interface/trait member rules; gated inner types by version; and so on).
2. `global use` statements (`global use`, `global use function`, `global use const`, `global use extension`). Keep existing relative order.
3. `use` statements (`use`, `use function`, `use const`, `use extension`). Keep existing relative order.
4. `extern` declarations, alphabetically.
5. Constants (`const …`), alphabetically (ungated).
6. PHP version-gated constants (see [Version-gated groups](#version-gated-groups)).
7. Variables (typed globals such as `bool $debugMode;`), alphabetically.
8. PHP version-gated variables.
9. Structs (`struct …`), alphabetically, then gated struct groups.
10. Type aliases (`type … = …;`), alphabetically, then gated type-alias groups.
11. Functions (`function …`), alphabetically (ungated).
12. PHP version-gated functions.
13. Classes, enums, interfaces, and traits, one mixed list alphabetically (ungated). `omit` types sort with these by type name (`omit final class Foo` sorts as `Foo`).
14. PHP version-gated classes / enums / interfaces / traits.
15. Extensions (`extension … { }`), alphabetically.
16. PHP version-gated extensions. `#[\Tyhp\Php]` is illegal on `extension { }`; wrap those with `declare(php=…)`. Order members inside each extension as in [Members of extensions](#members-of-extensions).

Apply version-gated grouping at file level for every kind above. Order members inside each type as in [Members of classes / enums / interfaces / traits](#members-of-classes--enums--interfaces--traits).

## Members of classes / enums / interfaces / traits

Inside each type, in this order:

1. Enum cases (if enum): keep existing relative order, at start of body, before member type aliases.
2. Type aliases.
3. Public constants, alphabetically.
4. Protected constants, alphabetically.
5. Private constants, alphabetically.
6. Public static properties (including hooked `{ get; set; }` / `{ get; }` / `{ &get; set; }`), alphabetically.
7. Protected static properties, alphabetically.
8. Private static properties, alphabetically.
9. Public instance properties (including hooked), alphabetically.
10. Protected instance properties, alphabetically.
11. Private instance properties, alphabetically.
12. `abstract static` methods, own section, alphabetically — **before** static methods.
13. Static methods, alphabetically (non-private first; **private static methods after** public/protected static methods, alphabetically within).
14. `__construct` if present — including `private function __construct` (this is the allowed private member; stays in this slot, not in a private-methods group).
15. `__destruct` if present.
16. Abstract methods (non-static), alphabetically.
17. Instance methods, alphabetically (non-private first; **private instance methods after** public/protected instance methods). `__invoke` is an instance method here, not the `()` operator. Class-body `extension fn` mappings go here.
18. Operator overloads (`operator` and `extension operator`), in [Operator overload order](#operator-overload-order).
19. `use extension` syntaxes, alphabetically.

Tyhpdef should not really declare private members except `private function __construct`, but when they exist they go after public/protected of the same kind as above.

`__construct` and `__destruct` are the only magic methods with their own slots: `__construct` once, then `__destruct`. Other magic methods (`__get`, `__toString`, `__call`, `__invoke`, `__clone`, …) are ordinary static or instance methods and go in those sections.

Class-body `use TraitName` (trait inclusion) is not in this list — stop if you encounter it.

## Members of extensions

Inside each standalone `extension { }`:

1. Functions (`fn` / `function`), alphabetically.
2. Operator overloads, in [Operator overload order](#operator-overload-order).

## Operator overload order

Use this order in every class, enum, interface, trait, and extension. Map whatever surface syntax the file uses (`operator +`, `extension operator +`, `operator convert`, named magic that is an operator overload) onto this list. Several overloads of the same operator stay together in their existing relative order.

1. Index/offset: `[]`, then `[]=` if distinct.
2. Unary prefix: `!`, `~`, unary `+`, unary `-`, `++`, `--`.
3. Arithmetic binary: `*`, `/`, `%`, `+`, `-`, `**`.
4. Concatenation: `.`.
5. Bitwise: `<<`, `>>`, `&`, `^`, `|`.
6. Comparison: `<=>`, `<`, `<=`, `>`, `>=`, `==`, `===`, `!=`, `!==`.
7. Logical: `&&`, `||`.
8. Assignment / compound if present (`+=`, `-=`, …) in the same relative operator order as the operators above.
9. Call/invoke: `()` (the `operator ()` spelling, not `__invoke`).
10. Cast / conversion operators (`operator convert`, other conversion spellings).
11. Any remaining named operator overloads, alphabetically by operator spelling.
