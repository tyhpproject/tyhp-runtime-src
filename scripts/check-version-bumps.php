<?php

// List packages whose publish tree differs from the GitHub tag that already
// exists for their current version. Run through check-version-bumps.sh, which
// sets CHECK_DIST_PACKAGES and CHECK_PHP_MAJORS from packages/build-common.sh.
//
// Tag trees come from batched GitHub GraphQL requests. Local trees are hashed
// the way git write-tree would hash them, so nothing is copied, built, cloned,
// tagged, or pushed.

declare(strict_types=1);

require_once __DIR__ . '/publish-helpers.php';
require_once \dirname(__DIR__) . '/generate-meta-package.php';

const CHECK_ORG = 'tyhpproject-packages';
const CHECK_BATCH_SIZE = 50;
const CHECK_FETCH_ATTEMPTS = 4;
const CHECK_VERSION_PATTERN = '/^(\d+)\.(\d+)(?:\.(\d+)(?:-([0-9A-Za-z.-]+))?)?$/D';

final class Row
{
    public string $status = '';
    public string $tree = '';
    public ?string $blob = null;
    public string $result = '';
    public string $detail = '';

    public function __construct(
        public readonly string $repo,
        public readonly string $tag,
        public readonly string $kind,
        public readonly string $pkg,
        public readonly string $name,
    ) {
    }
}

function check_usage(): string
{
    return <<<'TXT'
        Usage: scripts/check-version-bumps.sh [options]

        List packages whose publish tree differs from the GitHub tag that already
        exists for their current version. Those need a version bump before
        publish-runtime-packages.sh will release the change.

        Compiled packages (core, decimal, async, lambda, parallel, io) are compared
        from packages/dist. Run packages/base-build-all.sh first when tyhp_src or the
        compiler changed since the last build.

        Options:
          -h, --help           Show this help
          --package NAME       Check one package (same names as publish --package)
          --only-file PATH     Check only the package names listed in PATH
          --jobs N             Parallel GitHub requests (default 8)
          --format text|tsv    text is the report below; tsv is one row per tag
                               (result, package, kind, repo, tag, detail)

        Exit status:
          0  nothing needs a version bump
          1  at least one package needs a version bump, or a check failed
          2  usage error

        For the full diff of one package: scripts/diff-published-tag.sh PACKAGE

        TXT;
}

function usage_error(string $message): never
{
    \fwrite(\STDERR, "error: {$message}\n");
    exit(2);
}

/** @return array{string, int, string, string} package filter, jobs, format, only-file */
function check_args(array $argv): array
{
    $package = '';
    $jobs = 8;
    $format = 'text';
    $onlyFile = '';
    $args = \array_slice($argv, 1);
    while ($args !== []) {
        $arg = \array_shift($args);
        if ($arg === '-h' || $arg === '--help') {
            echo check_usage();
            exit(0);
        }
        if ($arg === '--package' || $arg === '--jobs' || $arg === '--format' || $arg === '--only-file') {
            if ($args === []) {
                usage_error("{$arg} requires a value.");
            }
            $value = \array_shift($args);
        } elseif (
            \str_starts_with($arg, '--package=')
            || \str_starts_with($arg, '--jobs=')
            || \str_starts_with($arg, '--format=')
            || \str_starts_with($arg, '--only-file=')
        ) {
            [$arg, $value] = \explode('=', $arg, 2);
        } else {
            usage_error("unknown argument: {$arg} (see --help)");
        }
        if ($arg === '--package') {
            $package = $value;
        } elseif ($arg === '--format') {
            if ($value !== 'text' && $value !== 'tsv') {
                usage_error('--format must be text or tsv.');
            }
            $format = $value;
        } elseif ($arg === '--only-file') {
            $onlyFile = $value;
        } elseif (\preg_match('/^[1-9]\d*$/', $value) === 1) {
            $jobs = (int) $value;
        } else {
            usage_error('--jobs must be a positive number.');
        }
    }
    return [$package, $jobs, $format, $onlyFile];
}

/** @return list<string> */
function wanted_packages(string $path): array
{
    $text = @\file_get_contents($path);
    if ($text === false) {
        usage_error("cannot read {$path}");
    }
    $names = [];
    foreach (\preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
        $line = \trim($line);
        if ($line !== '' && !\str_starts_with($line, '#')) {
            $names[] = $line;
        }
    }
    return $names;
}

function words(string $list): array
{
    return \preg_split('/\s+/', \trim($list), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
}

function composer_doc(string $path): array
{
    $doc = \json_decode((string) @\file_get_contents($path), true);
    return \is_array($doc) ? $doc : [];
}

function doc_text(array $doc, string $key): string
{
    return \trim(meta_text($doc[$key] ?? null));
}

/** @return list<Row> every tag publish would create or check, in publish order */
function discover_rows(string $packagesDir, array $distPackages, array $phpMajors): array
{
    $rows = [];
    foreach (\glob($packagesDir . '/tyhp/*/composer.json') ?: [] as $path) {
        $pkg = \basename(\dirname($path));
        $doc = composer_doc($path);
        $version = doc_text($doc, 'version');
        if (\in_array($pkg, $distPackages, true)) {
            if (\preg_match(CHECK_VERSION_PATTERN, $version, $m) !== 1) {
                throw new \RuntimeException("invalid version in {$path}: {$version}");
            }
            $xy = ($m[3] ?? '') === '' ? "{$m[1]}.{$m[2]}" : "{$m[2]}.{$m[3]}";
            $suffix = ($m[4] ?? '') !== '' ? "-{$m[4]}" : '';
            foreach ($phpMajors as $major) {
                $rows[] = new Row($pkg, "{$major}.{$xy}{$suffix}", 'compiled', $pkg, doc_text($doc, 'name'));
            }
        } elseif ($pkg === 'compiler') {
            $rows[] = new Row($pkg, $version, 'compiler', $pkg, doc_text($doc, 'name'));
        }
    }
    foreach (\glob($packagesDir . '/tyhpdef/*/composer.json') ?: [] as $path) {
        $pkg = \basename(\dirname($path));
        if ($pkg === 'dist' || $pkg === 'vendor') {
            continue;
        }
        $doc = composer_doc($path);
        $name = doc_text($doc, 'name');
        $isTyhpdef = $name !== ''
            ? \str_starts_with($name, 'tyhpdef/')
            : ($pkg === 'php' || \str_starts_with($pkg, 'php-ext-'));
        if ($isTyhpdef) {
            $rows[] = new Row($pkg, doc_text($doc, 'version'), 'tyhpdef', $pkg, $name);
        }
    }
    foreach (\glob($packagesDir . '/tyhpdef/*/*/composer.json') ?: [] as $path) {
        $upstream = \basename(\dirname($path));
        $repo = \basename(\dirname($path, 2));
        if (\in_array($repo, ['dist', 'vendor'], true) || \in_array($upstream, ['dist', 'vendor'], true)) {
            continue;
        }
        $doc = composer_doc($path);
        $name = doc_text($doc, 'name');
        if ($name !== '' && !\str_starts_with($name, 'tyhpdef/')) {
            continue;
        }
        $pkg = "{$repo}/{$upstream}";
        $rows[] = new Row($repo, $upstream, 'public', $pkg, $name);
        if (($doc['extra']['tyhp']['ownership']['status'] ?? null) !== 'handed-off') {
            $rows[] = new Row("{$repo}-impl", doc_text($doc, 'version'), 'impl', $pkg, $name);
        }
    }
    return $rows;
}

/** Same names publish --package accepts: folder, repo slug, or Composer name. */
function filter_rows(array $rows, string $needle): array
{
    $needle = \rtrim($needle, '/');
    $tests = [
        static fn (Row $row): bool => $row->pkg === $needle,
        static fn (Row $row): bool => \explode('/', $row->pkg)[0] === $needle,
        static fn (Row $row): bool => $row->name === $needle,
    ];
    foreach ($tests as $test) {
        $matches = \array_values(\array_filter($rows, $test));
        if ($matches !== []) {
            return $matches;
        }
    }
    usage_error("unknown package {$needle}. Use a package folder name (php, core, psr-log/3.0.2) or a Composer name (tyhpdef/php).");
}

function graphql_string(string $value): string
{
    return (string) \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
}

/** One query for a slice of rows: each tag's tree id plus the file publish normalizes or compares. */
function batch_query(array $rows): string
{
    $owner = graphql_string(CHECK_ORG);
    $query = "query {\n";
    foreach ($rows as $i => $row) {
        $query .= "  r{$i}: repository(owner: {$owner}, name: " . graphql_string($row->repo) . ") {\n";
        $query .= '    ref(qualifiedName: ' . graphql_string("refs/tags/{$row->tag}") . ") { target { ...TagTree } }\n";
        $file = match ($row->kind) {
            'compiled' => 'package.tyhpdef',
            'public' => 'composer.json',
            default => null,
        };
        if ($file !== null) {
            $query .= '    blob: object(expression: ' . graphql_string("{$row->tag}:{$file}") . ") { ... on Blob { text } }\n";
        }
        $query .= "  }\n";
    }
    return $query . "}\n"
        . "fragment TagTree on GitObject {\n"
        . "  ... on Commit { tree { oid } }\n"
        . "  ... on Tag { target { ... on Commit { tree { oid } } } }\n"
        . "}\n";
}

/**
 * Run the GraphQL batches with up to $jobs gh processes at a time. Failed
 * batches are retried. A missing repo still returns data (NOT_FOUND), which counts as done.
 *
 * @param array<int, list<Row>> $batches
 */
function fetch_tags(array $batches, string $work, int $jobs): void
{
    $pending = \array_keys($batches);
    for ($attempt = 1; $attempt <= CHECK_FETCH_ATTEMPTS && $pending !== []; $attempt++) {
        if ($attempt > 1) {
            \fwrite(\STDERR, 'GitHub: retrying ' . \count($pending) . " request(s) (attempt {$attempt}/" . CHECK_FETCH_ATTEMPTS . ")\n");
            \sleep(($attempt - 1) * 5);
        }
        $failed = [];
        foreach (\array_chunk($pending, $jobs) as $wave) {
            $procs = [];
            foreach ($wave as $b) {
                \file_put_contents("{$work}/query-{$b}.graphql", batch_query($batches[$b]));
                $procs[$b] = \proc_open(
                    ['gh', 'api', 'graphql', '-F', "query=@{$work}/query-{$b}.graphql"],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$work}/response-{$b}.json", 'w'], 2 => ['file', "{$work}/response-{$b}.err", 'w']],
                    $pipes,
                );
            }
            foreach ($procs as $b => $proc) {
                if (\is_resource($proc)) {
                    \proc_close($proc);
                }
                $response = \json_decode((string) @\file_get_contents("{$work}/response-{$b}.json"), true);
                if (!\is_array($response) || !\is_array($response['data'] ?? null)) {
                    $failed[] = $b;
                    continue;
                }
                foreach ($batches[$b] as $i => $row) {
                    apply_tag($row, $response['data']["r{$i}"] ?? null);
                }
            }
        }
        $pending = $failed;
    }
    if ($pending !== []) {
        $b = $pending[0];
        $err = \trim((string) @\file_get_contents("{$work}/response-{$b}.err"));
        throw new \RuntimeException("GitHub GraphQL request failed: {$err}");
    }
}

function apply_tag(Row $row, mixed $repo): void
{
    if (!\is_array($repo)) {
        $row->status = 'norepo';
        return;
    }
    $target = $repo['ref']['target'] ?? null;
    $tree = $target['tree']['oid'] ?? $target['target']['tree']['oid'] ?? null;
    if (!\is_string($tree)) {
        $row->status = 'notag';
        return;
    }
    $row->status = 'tag';
    $row->tree = $tree;
    $blob = $repo['blob']['text'] ?? null;
    $row->blob = \is_string($blob) ? $blob : null;
}

/**
 * Add a directory the way rsync -a copies it and git add -A stages it.
 *
 * @param array<string, array{string, string}> $files path => [mode, content]
 */
function add_dir(array &$files, string $dir, string $prefix, array $skipDirs = [], array $skipFiles = []): void
{
    $entries = @\scandir($dir);
    if ($entries === false) {
        throw new \RuntimeException("cannot read {$dir}");
    }
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = "{$dir}/{$name}";
        if (\is_link($path)) {
            $files[$prefix . $name] = ['120000', (string) \readlink($path)];
        } elseif (\is_dir($path)) {
            if ($name !== '.git' && !\in_array($name, $skipDirs, true)) {
                add_dir($files, $path, "{$prefix}{$name}/", $skipDirs, $skipFiles);
            }
        } elseif (!\in_array($name, $skipFiles, true)) {
            add_file($files, $path, $prefix . $name);
        }
    }
}

function add_file(array &$files, string $path, string $as): void
{
    $content = @\file_get_contents($path);
    if ($content === false) {
        throw new \RuntimeException("cannot read {$path}");
    }
    $files[$as] = [(\fileperms($path) & 0o100) !== 0 ? '100755' : '100644', $content];
}

function add_license(array &$files, string $src, string $repoRoot): void
{
    if (\is_file("{$src}/LICENSE")) {
        add_file($files, "{$src}/LICENSE", 'LICENSE');
    } elseif (\is_file("{$repoRoot}/LICENSE.txt")) {
        add_file($files, "{$repoRoot}/LICENSE.txt", 'LICENSE');
    }
}

/**
 * Files publish would commit for a row. Mirrors sync_compile_dist,
 * sync_compiler_dist, sync_composer_lib_dist, and the public metapackage copy
 * in publish-runtime-packages.sh; keep them in step.
 *
 * @return array<string, array{string, string}>
 */
function publish_files(Row $row, string $repoRoot, string $work): array
{
    $packages = "{$repoRoot}/packages";
    $files = [];
    switch ($row->kind) {
        case 'compiled':
            $dist = "{$packages}/dist/tyhp-{$row->pkg}/{$row->tag}";
            if (!\is_dir("{$dist}/src")) {
                throw new \RuntimeException("packages/dist/tyhp-{$row->pkg}/{$row->tag} is missing. Run packages/base-build-all.sh.");
            }
            add_dir($files, $dist, '', [], ['tyhp-build-state.json']);
            if ($row->blob !== null && isset($files['package.tyhpdef'])) {
                $files['package.tyhpdef'][1] = with_tag_generated_line($files['package.tyhpdef'][1], $row->blob);
            }
            return $files;

        case 'compiler':
            $src = "{$packages}/tyhp/compiler";
            if (!\is_dir("{$src}/src/Tyhp/Compiler") || !\is_file("{$src}/bin/tyhp")) {
                throw new \RuntimeException('packages/tyhp/compiler is not built. Run packages/base-build-all.sh compiler.');
            }
            add_dir($files, "{$src}/src", 'src/', [], ['tyhp-build-state.json']);
            add_dir($files, "{$src}/bin", 'bin/');
            $files['composer.json'] = ['100644', published_composer_text("{$src}/composer.json")];
            if (\is_file("{$src}/README.md")) {
                add_file($files, "{$src}/README.md", 'README.md');
            }
            add_license($files, $src, $repoRoot);
            return $files;

        case 'tyhpdef':
        case 'impl':
            $src = "{$packages}/tyhpdef/{$row->pkg}";
            add_dir($files, "{$src}/_tyhpdef", '_tyhpdef/', ['support']);
            if (\is_dir("{$src}/tests")) {
                add_dir($files, "{$src}/tests", 'tests/', ['build', 'vendor']);
            }
            if (\is_file("{$src}/README.md")) {
                add_file($files, "{$src}/README.md", 'README.md');
            }
            add_license($files, $src, $repoRoot);
            $files['composer.json'] = ['100644', $row->kind === 'impl'
                ? published_impl_composer_text("{$src}/composer.json")
                : published_composer_text("{$src}/composer.json")];
            return $files;

        case 'public':
            $out = "{$work}/meta-" . \str_replace('/', '--', $row->pkg);
            $proc = \proc_open(
                ["{$repoRoot}/generate-meta-package.sh", '--out', $out, $row->pkg],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$out}.err", 'w']],
                $pipes,
            );
            if (!\is_resource($proc) || \proc_close($proc) !== 0) {
                throw new \RuntimeException(\trim((string) @\file_get_contents("{$out}.err")) ?: 'generate-meta-package.sh failed');
            }
            foreach (['composer.json', 'README.md', 'LICENSE'] as $name) {
                if (\is_file("{$out}/{$name}")) {
                    add_file($files, "{$out}/{$name}", $name);
                }
            }
            return $files;
    }
    throw new \RuntimeException("unknown publish kind {$row->kind}");
}

/**
 * Root tree id git write-tree would give these files.
 *
 * @param array<string, array{string, string}> $files
 */
function tree_oid(array $files): string
{
    $root = [];
    foreach ($files as $path => [$mode, $content]) {
        $node = &$root;
        $parts = \explode('/', $path);
        $leaf = \array_pop($parts);
        foreach ($parts as $dir) {
            $node[$dir] ??= [];
            $node = &$node[$dir];
        }
        $node[$leaf] = [$mode, \sha1('blob ' . \strlen($content) . "\0" . $content, true)];
        unset($node);
    }
    return \bin2hex(write_tree($root));
}

function write_tree(array $entries): string
{
    $sortKeys = [];
    foreach ($entries as $name => $entry) {
        $isBlob = \is_string($entry[0] ?? null);
        $sortKeys[(string) $name] = $isBlob ? (string) $name : "{$name}/";
    }
    \uksort($entries, static fn (int|string $a, int|string $b): int => \strcmp($sortKeys[(string) $a], $sortKeys[(string) $b]));
    $body = '';
    foreach ($entries as $name => $entry) {
        if (\is_string($entry[0] ?? null)) {
            $body .= "{$entry[0]} {$name}\0{$entry[1]}";
        } else {
            $body .= "40000 {$name}\0" . write_tree($entry);
        }
    }
    return \sha1('tree ' . \strlen($body) . "\0" . $body, true);
}

function check_row(Row $row, string $repoRoot, string $work): void
{
    if ($row->status === 'norepo') {
        $row->result = 'norepo';
        return;
    }
    if ($row->status === 'notag') {
        $row->result = 'new';
        return;
    }
    try {
        if ($row->kind === 'public' && $row->blob !== null) {
            $existing = \json_decode($row->blob, true);
            $meta = public_metapackage("{$repoRoot}/packages/tyhpdef/{$row->pkg}");
            if (\is_array($existing) && public_tag_unchanged($meta, $existing)) {
                $row->result = 'ok';
                return;
            }
        }
        $files = publish_files($row, $repoRoot, $work);
        foreach (\array_keys($files) as $path) {
            if (\basename($path) === '.gitignore') {
                throw new \RuntimeException("{$path} would filter the commit; check with scripts/diff-published-tag.sh");
            }
        }
        $row->result = tree_oid($files) === $row->tree ? 'ok' : 'bump';
    } catch (\Throwable $e) {
        $row->result = 'error';
        $row->detail = $e->getMessage();
    }
}

function red(string $line): string
{
    return \stream_isatty(\STDOUT) ? "\033[31m{$line}\033[0m" : $line;
}

function remove_tree(string $dir): void
{
    if (!\is_dir($dir) || \is_link($dir)) {
        @\unlink($dir);
        return;
    }
    foreach (\scandir($dir) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            remove_tree("{$dir}/{$name}");
        }
    }
    @\rmdir($dir);
}

function check_progress(string $format, string $line): void
{
    if ($format === 'tsv') {
        \fwrite(\STDERR, $line . "\n");
        return;
    }
    echo $line, "\n";
}

function write_plan_tsv(array $rows): void
{
    foreach ($rows as $row) {
        $detail = \str_replace(["\t", "\n", "\r"], ' ', $row->detail);
        echo $row->result, "\t", $row->pkg, "\t", $row->kind, "\t", $row->repo, "\t", $row->tag, "\t", $detail, "\n";
    }
}

function check_main(array $argv): int
{
    [$filter, $jobs, $format, $onlyFile] = check_args($argv);
    $repoRoot = \dirname(__DIR__);
    $distPackages = words((string) \getenv('CHECK_DIST_PACKAGES'));
    $phpMajors = words((string) \getenv('CHECK_PHP_MAJORS'));
    if ($distPackages === [] || $phpMajors === []) {
        usage_error('run this through scripts/check-version-bumps.sh');
    }

    $rows = discover_rows("{$repoRoot}/packages", $distPackages, $phpMajors);
    if ($filter !== '') {
        $rows = filter_rows($rows, $filter);
    }
    if ($onlyFile !== '') {
        $wanted = \array_fill_keys(wanted_packages($onlyFile), true);
        $rows = \array_values(\array_filter($rows, static fn (Row $row): bool => isset($wanted[$row->pkg])));
    }
    foreach ($rows as $row) {
        if ($row->tag === '') {
            throw new \RuntimeException("missing version for {$row->pkg}");
        }
    }

    $work = \rtrim(\sys_get_temp_dir(), '/') . '/tyhp-bump-check.' . \bin2hex(\random_bytes(4));
    \mkdir($work, 0700, true);
    try {
        $batches = \array_chunk($rows, CHECK_BATCH_SIZE);
        check_progress($format, 'Fetching ' . \count($rows) . ' tags from ' . CHECK_ORG . ' (' . \count($batches) . ' GitHub requests)...');
        fetch_tags($batches, $work, $jobs);
        check_progress($format, 'Comparing with the trees publish would push...');
        foreach ($rows as $row) {
            check_row($row, $repoRoot, $work);
        }
    } finally {
        remove_tree($work);
    }

    if ($format === 'tsv') {
        write_plan_tsv($rows);
        return 0;
    }

    $byResult = ['ok' => [], 'new' => [], 'bump' => [], 'norepo' => [], 'error' => []];
    foreach ($rows as $row) {
        $byResult[$row->result][] = $row;
    }
    $label = static fn (Row $row): string => "  {$row->pkg}  (" . ($row->kind === 'public' ? 'public metapackage' : $row->kind)
        . ', ' . CHECK_ORG . "/{$row->repo} tag {$row->tag})";

    echo "\n";
    if ($byResult['bump'] !== []) {
        echo red('Needs a version bump (' . \count($byResult['bump']) . '):'), "\n";
        foreach ($byResult['bump'] as $row) {
            echo red($label($row)), "\n";
        }
    } else {
        echo "Nothing needs a version bump.\n";
    }
    if ($byResult['new'] !== []) {
        echo "\nNew versions, not tagged on GitHub yet (" . \count($byResult['new']) . "):\n";
        foreach ($byResult['new'] as $row) {
            echo $label($row), "\n";
        }
    }
    $failed = \count($byResult['norepo']) + \count($byResult['error']);
    if ($failed > 0) {
        \fwrite(\STDERR, "\nCould not check ({$failed}):\n");
        foreach ($byResult['norepo'] as $row) {
            \fwrite(\STDERR, "  {$row->pkg}  " . CHECK_ORG . "/{$row->repo} does not exist\n");
        }
        foreach ($byResult['error'] as $row) {
            \fwrite(\STDERR, "  {$row->pkg}  ({$row->kind} tag {$row->tag}): {$row->detail}\n");
        }
    }
    echo "\nUp to date: " . \count($byResult['ok']) . '  New: ' . \count($byResult['new'])
        . '  Needs bump: ' . \count($byResult['bump']) . "  Could not check: {$failed}\n";

    return $byResult['bump'] !== [] || $failed > 0 ? 1 : 0;
}

try {
    exit(check_main($argv));
} catch (\Throwable $e) {
    \fwrite(\STDERR, "error: {$e->getMessage()}\n");
    exit(1);
}
