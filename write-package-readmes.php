<?php

// Body of write-package-readmes.sh. Reads PACKAGES_DIR, DRY_RUN, ONLY_PACKAGE,
// ONLY_VERSION, EMIT, OUT_DIR, OWNERSHIP_ISSUE_URL, and OWNERSHIP_DOC_URL from
// the environment set by that script.

declare(strict_types=1);

const START = '<!-- tyhp-readme:start -->';
const END = '<!-- tyhp-readme:end -->';

function fail(string $message): never
{
    \fwrite(\STDERR, "error: {$message}\n");
    exit(2);
}

function env(string $name): string
{
    $value = \getenv($name);
    return \is_string($value) ? $value : '';
}

function text(mixed $value): string
{
    if (\is_string($value)) {
        return $value;
    }
    if (\is_int($value) || \is_float($value)) {
        return (string) $value;
    }
    return '';
}

function dict(mixed $value): array
{
    return \is_array($value) && !\array_is_list($value) ? $value : [];
}

$packages = env('PACKAGES_DIR');
$dry = env('DRY_RUN') === '1';
$only = \trim(\trim(env('ONLY_PACKAGE')), '/');
$onlyVersion = \trim(env('ONLY_VERSION'));
$emit = env('EMIT') !== '' ? env('EMIT') : 'all';
$outDir = env('OUT_DIR');
$issueUrl = env('OWNERSHIP_ISSUE_URL');
$docUrl = env('OWNERSHIP_DOC_URL');

function folder_name(string $name, string $packages): string
{
    $name = \trim(\trim($name), '/');
    if (\str_starts_with($name, 'packages/')) {
        $name = \substr($name, \strlen('packages/'));
    }
    if (\str_starts_with($name, 'tyhpdef/')) {
        $name = \substr($name, \strlen('tyhpdef/'));
    }
    $first = \explode('/', $name)[0];
    if (\str_contains($name, '/') && !\is_dir($packages . '/' . $first)) {
        [$vendor, $project] = \explode('/', $name, 2);
        return "{$vendor}-{$project}";
    }
    return $first;
}

function load_json(string $path): array
{
    $raw = @\file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $doc = \json_decode($raw, true);
    return \is_array($doc) ? $doc : [];
}

function upstream_from(array $doc, string $folder): string
{
    foreach (\array_keys(dict($doc['require'] ?? null)) as $key) {
        $key = (string) $key;
        $low = \strtolower($key);
        if ($low === 'php' || \str_starts_with($low, 'ext-') || \str_starts_with($low, 'tyhp/') || \str_starts_with($low, 'tyhpdef/')) {
            continue;
        }
        if (\str_contains($key, '/')) {
            return $key;
        }
    }
    return (string) \preg_replace('/-/', '/', $folder, 1);
}

/** @return array{string, string, array} public name, impl name, ownership */
function names_for(array $doc, string $folder): array
{
    $raw = text($doc['name'] ?? null);
    $raw = $raw !== '' ? $raw : "tyhpdef/{$folder}";
    $tyhp = dict(dict($doc['extra'] ?? null)['tyhp'] ?? null);
    $public = text($tyhp['public'] ?? null);
    if (\str_ends_with($raw, '-impl')) {
        $impl = $raw;
        $public = $public !== '' ? $public : \substr($raw, 0, -\strlen('-impl'));
    } else {
        $public = $public !== '' ? $public : $raw;
        $impl = $public . '-impl';
    }
    return [$public, $impl, dict($tyhp['ownership'] ?? null)];
}

function splice(string $existing, string $block): string
{
    $start = \strpos($existing, START);
    $end = \strpos($existing, END);
    if ($start !== false && $end !== false && $end > $start) {
        $endLine = $end + \strlen(END);
        if ($endLine < \strlen($existing) && $existing[$endLine] === "\n") {
            $endLine++;
        }
        return \substr($existing, 0, $start) . $block . \substr($existing, $endLine);
    }
    if (\trim($existing) !== '') {
        if (\str_ends_with($existing, "\n\n")) {
            $separator = '';
        } elseif (\str_ends_with($existing, "\n")) {
            $separator = "\n";
        } else {
            $separator = "\n\n";
        }
        return $block . $separator . $existing;
    }
    return $block;
}

function write_readme(string $path, string $block, bool $dry): void
{
    $existing = \is_file($path) ? (string) \file_get_contents($path) : '';
    $merged = splice($existing, \str_ends_with($block, "\n") ? $block : $block . "\n");
    if ($dry) {
        echo "# {$path}\n", $merged, \str_ends_with($merged, "\n") ? '' : "\n";
        return;
    }
    $dir = \dirname($path);
    if (!\is_dir($dir)) {
        \mkdir($dir, 0777, true);
    }
    \file_put_contents($path, $merged);
    echo "wrote {$path}\n";
}

function impl_block(string $public, string $impl, string $upstream): string
{
    $start = START;
    $end = END;
    return <<<MD
        {$start}
        # {$impl}

        Implementation package for **`{$public}`**.

        Do not `composer require` this package in an application. Require
        `{$public}` (same version as `{$upstream}`). This package exists so
        tyhpproject can ship type-only revisions (`{$upstream}.1`, …) without
        changing the public version.

        Type files are under `_tyhpdef/`. Package maintainers of `{$upstream}`
        who want to ship their own types should copy that directory; see the
        `{$public}` README.
        {$end}

        MD;
}

function public_block(string $public, string $impl, string $upstream, string $version, string $issueUrl, string $docUrl): string
{
    $start = START;
    $end = END;
    return <<<MD
        {$start}
        # {$public}

        Tyhp type definitions for `{$upstream}` `{$version}`.

        ```bash
        composer require --dev {$public}:{$version}
        ```

        This is a metapackage. Composer also installs `{$impl}` (type files).
        Require **this** name, not `{$impl}`.

        See https://tyhplang.com.

        ## Maintain `{$upstream}`? Ship the types yourself

        If you are a Packagist maintainer of `{$upstream}`, you can take over these
        types.

        Copy `_tyhpdef/` from **`{$impl}`** (Apache-2.0; keep the `NOTICE`).
        Then either:

        1. **Bundle** the files in `{$upstream}` and set `extra.tyhp.package` on
           that `composer.json`, plus
           `"replace": { "{$public}": "self.version" }`, or
        2. **Publish a sibling** types package under your vendor, versioned with
           `{$upstream}` (same `X.Y.Z`). Set `extra.tyhp.package` there,
           `require` `{$upstream}` with a real constraint,
           `"replace": { "{$public}": "self.version" }`, and set
           `extra.tyhp.tyhpdef` on `{$upstream}` to your sibling’s Composer name.

        Ship that to Packagist first, then open an issue:

        {$issueUrl}

        We verify Packagist ownership and that the types parse and cover the PHP
        API, then stop publishing community tags for those versions. We do not
        transfer the `{$public}` Packagist name.

        Full process: `TYHPDEF_OWNERSHIP.md` in
        {$docUrl}
        {$end}

        MD;
}

function handed_block(string $public, string $upstream, string $version, string $target): string
{
    $start = START;
    $end = END;
    return <<<MD
        {$start}
        # {$public}

        Metapackage. Tyhp type definitions for `{$upstream}` `{$version}+`
        now ship in **{$target}**. This name exists so existing
        `require-dev {$public}` constraints keep resolving.

        You can drop `{$public}` from `require-dev` if `{$target}` is
        already installed and declares `extra.tyhp.package`.
        {$end}

        MD;
}

function rhetoric_block(): string
{
    $start = START;
    $end = END;
    return <<<MD
{$start}
# tyhp/rhetoric

Laravel database access for Tyhp and for PHP. A model is a plain class. A `Context` is the unit of work. `save()` writes the tracked changes in one transaction. Queries are expression trees, translated to SQL.

PHP 8.3–8.5. Require the artifact for the PHP you run (`803`, `804`, or `805`):

```bash
composer require tyhp/rhetoric:805.0.*
```

That also installs `tyhp/core` and `tyhp/lambda`, plus Laravel 13 (`illuminate/database`, `illuminate/support`, `illuminate/console`). The service provider is `Tyhp\Rhetoric\Laravel\RhetoricServiceProvider`.

Guides: [Tyhp](https://tyhplang.com/rhetoric_getting_started.html) and [PHP](https://tyhplang.com/rhetoric_php_getting_started.html).

## Tyhp

```tyhp
<?tyhp

namespace App\Data;

use Tyhp\Rhetoric\Attributes\MaxLength;
use Tyhp\Rhetoric\Attributes\Table;
use Tyhp\Rhetoric\Attributes\Timestamps;
use Tyhp\Rhetoric\Context;
use Tyhp\Rhetoric\Mapping\Mapper;
use Tyhp\Rhetoric\OnDelete;
use Tyhp\Rhetoric\Table as ModelTable;

#[Timestamps]
class Chirp {
    public int \$id;
    public int \$userId;
    #[MaxLength(255)]
    public string \$message;
    public ?Author \$user = null;
}

#[Table('users')]
class Author {
    public int \$id;
    public string \$name;
    public string \$email;
}

class ChirpContext extends Context {
    public ModelTable<Chirp> \$chirps { get => \$this->table<Chirp>(); }

    protected function map(Mapper \$map): void {
        \$map->model<Chirp>()
            ->belongsTo(fn (\$chirp) => \$chirp->user)
            ->foreignKey(fn (\$chirp) => \$chirp->userId)
            ->onDelete(OnDelete::Cascade);
    }
}
```

```tyhp
\$db = app(ChirpContext::class);
\$feed = \$db->chirps
    ->with(fn (\$chirp) => \$chirp->user)
    ->latest()
    ->take(50)
    ->all();
```

`all()` returns `array<T>`. `get()` returns `Collection<int, T>`. `where` takes a lambda (`fn (\$chirp) => \$chirp->message->startsWith('Hi')`). `first()` returns `?T`. `save()` returns the number of rows written. `rhetoric:migration`, `rhetoric:scaffold`, and `rhetoric:auto-migrate` are the Artisan commands. Register `Sql` functions with `MethodTranslatorRegistry::shared()->registerFunction`.

## PHP

This section stands on its own. Models and the context are the same classes. Generic methods are bound with `\Tyhp\Generic::bind` or `\_gnrc`. A `Table` is a method on your context. A collection relation names its model with `#[\Tyhp\PropertyType('array<App\\\\Data\\\\Chirp>')]`. Predicates are `\Tyhp\Expression::of`.

```php
namespace App\Data;

final class ChirpContext extends \Tyhp\Rhetoric\Context
{
    public function chirps(): \Tyhp\Rhetoric\Table
    {
        return (\Tyhp\Generic::bind(\$this->table(...), \Tyhp\Type::fromClassName(Chirp::class)))();
    }

    protected function map(\Tyhp\Rhetoric\Mapping\Mapper \$map): void
    {
        \$model = \Tyhp\Generic::bind(\$map->model(...), \Tyhp\Type::fromClassName(Chirp::class))();
        \$model
            ->belongsTo(\Tyhp\Expression::of(Chirp::class)->user)
            ->foreignKey(\Tyhp\Expression::of(Chirp::class)->userId);
    }
}

\$feed = \$db->chirps()
    ->where(\Tyhp\Expression::of(\App\Data\Chirp::class)->userId->same(\$userId))
    ->with(\Tyhp\Expression::of(\App\Data\Chirp::class)->user)
    ->latest()
    ->take(50)
    ->all();
```

`\_gnrc(...)` is the root function with the same signature as `\Tyhp\Generic::bind`. `\Tyhp\Type::int()`, `::string()`, `::fromClassName`, `::nullable`, `::union`, `::generic`, `::struct`, `::parse`, and `\Tyhp\NamedType` are the type factories. A class bind is `\Tyhp\Generic::bind(\Vendor\Box::class, \Tyhp\Type::int())->new(42)` or `(42)`. String methods use `\Tyhp\Expr::param(\$name, \App\Data\Chirp::class)->message->call('startsWith', 'Hi')`.

`EloquentQuery::query` binds the model, then takes no arguments. `on` binds the model and takes the connection:

```php
\$query = \Tyhp\Generic::bind(
    \Tyhp\Rhetoric\EloquentQuery::query(...),
    \Tyhp\Type::fromClassName(\App\Models\Article::class),
)();
\$onMysql = \Tyhp\Generic::bind(
    \Tyhp\Rhetoric\EloquentQuery::on(...),
    \Tyhp\Type::fromClassName(\App\Models\Article::class),
)('mysql');
```
{$end}

MD;
}

function maybe_write_rhetoric(string $packages, string $only, string $emit, bool $dry): bool
{
    $want = $only === '' || $only === 'rhetoric' || $only === 'tyhp/rhetoric';
    if (!$want || !\in_array($emit, ['all', 'impl'], true)) {
        return false;
    }
    $dir = \dirname($packages) . '/tyhp/rhetoric';
    if (!\is_dir($dir)) {
        if ($only !== '') {
            fail('rhetoric package not found: ' . $dir);
        }
        return false;
    }
    write_readme($dir . '/README.md', rhetoric_block(), $dry);
    return $only !== '';
}

function first_party_block(string $composerName, string $extName): string
{
    if ($composerName === 'tyhpdef/php' || $extName === '') {
        $title = 'tyhpdef/php';
        $body = "Tyhp type definitions for PHP's always-present built-ins.\n\n"
            . "Install in `require-dev`. See https://tyhplang.com.\n\n"
            . 'This package is maintained by tyhpproject. These built-in '
            . 'definitions are not transferred through the Composer-lib ownership process.';
    } else {
        $title = "tyhpdef/php-ext-{$extName}";
        $body = "Tyhp type definitions for the PHP extension `{$extName}`.\n\n"
            . "Install in `require-dev`. See https://tyhplang.com and `tyhpdef/php`.\n\n"
            . "This package is maintained by tyhpproject. PHP extension wrappers are\n"
            . 'not transferred through the Composer-lib ownership process.';
    }
    return START . "\n# {$title}\n\n{$body}\n" . END . "\n";
}

/** @return list<string> */
function list_dir(string $dir): array
{
    $entries = @\scandir($dir);
    if ($entries === false) {
        return [];
    }
    return \array_values(\array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..'));
}

/** @return list<array{string, string}> version, directory */
function version_dirs(string $parent): array
{
    $children = list_dir($parent);
    \sort($children, \SORT_STRING);
    $rows = [];
    foreach ($children as $version) {
        $dir = $parent . '/' . $version;
        if (\is_file($dir . '/composer.json')) {
            $rows[] = [$version, $dir];
        }
    }
    return $rows;
}

function handle_version(string $folder, string $version, string $dir, array $doc, array $opts): void
{
    if ($opts['onlyVersion'] !== '' && $version !== $opts['onlyVersion']) {
        return;
    }
    [$public, $impl, $ownership] = names_for($doc, $folder);
    $upstream = upstream_from($doc, $folder);
    $handed = text($ownership['status'] ?? null) === 'handed-off';
    $target = text($ownership['target'] ?? null);
    $target = $target !== '' ? $target : $upstream;
    $emit = $opts['emit'];
    $outDir = $opts['outDir'];
    if (\in_array($emit, ['all', 'impl'], true) && $outDir === '') {
        write_readme($dir . '/README.md', impl_block($public, $impl, $upstream), $opts['dry']);
    }
    if ($emit === 'public' || $emit === 'handed-off' || ($emit === 'all' && $outDir !== '')) {
        $block = ($handed || $emit === 'handed-off')
            ? handed_block($public, $upstream, $version, $target)
            : public_block($public, $impl, $upstream, $version, $opts['issueUrl'], $opts['docUrl']);
        $destDir = $outDir !== '' ? $outDir : $dir;
        if (\in_array($emit, ['public', 'handed-off'], true) && $outDir === '') {
            fail('--out is required with --emit public or --emit handed-off');
        }
        if ($emit === 'all' && $outDir !== '') {
            write_readme($outDir . '/README.md', $block, $opts['dry']);
        } elseif (\in_array($emit, ['public', 'handed-off'], true)) {
            write_readme($destDir . '/README.md', $block, $opts['dry']);
        }
    }
}

if (!\is_dir($packages)) {
    fail("packages directory not found: {$packages}");
}

if (maybe_write_rhetoric($packages, $only, $emit, $dry)) {
    exit(0);
}

if ($only !== '') {
    $only = folder_name($only, $packages);
}

$names = list_dir($packages);
\usort($names, static fn (string $a, string $b): int => \strcmp(\strtolower($a), \strtolower($b)));
if ($only !== '') {
    $names = \array_values(\array_filter($names, static fn (string $n): bool => $n === $only));
    if ($names === []) {
        fail("package folder not found: {$only}");
    }
}

$opts = [
    'dry' => $dry,
    'onlyVersion' => $onlyVersion,
    'emit' => $emit,
    'outDir' => $outDir,
    'issueUrl' => $issueUrl,
    'docUrl' => $docUrl,
];
$publicEmitOnly = \in_array($emit, ['public', 'handed-off'], true);

foreach ($names as $name) {
    if ($name === 'dist' || $name === 'vendor' || \str_starts_with($name, '.')) {
        continue;
    }
    $parent = $packages . '/' . $name;
    if (!\is_dir($parent)) {
        continue;
    }
    $flat = $parent . '/composer.json';
    if (\is_file($flat) && (\str_starts_with($name, 'php-ext-') || $name === 'php')) {
        if ($publicEmitOnly) {
            continue;
        }
        $doc = load_json($flat);
        $ext = $name === 'php' ? '' : \substr($name, \strlen('php-ext-'));
        $composerName = text($doc['name'] ?? null);
        $composerName = $composerName !== '' ? $composerName : "tyhpdef/{$name}";
        write_readme($parent . '/README.md', first_party_block($composerName, $ext), $dry);
        continue;
    }
    if (\is_file($flat)) {
        continue;
    }
    foreach (version_dirs($parent) as [$version, $dir]) {
        handle_version($name, $version, $dir, load_json($dir . '/composer.json'), $opts);
    }
}
