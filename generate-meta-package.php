<?php

// Body of generate-meta-package.sh. Reads IMPL_DIR, OUT_DIR, EXISTING_PUBLIC,
// DRY_RUN, and REPO_ROOT from the environment set by that script.
// scripts/check-version-bumps.php includes this file for public_metapackage().

declare(strict_types=1);

function meta_text(mixed $value): string
{
    if (\is_string($value)) {
        return $value;
    }
    if (\is_int($value) || \is_float($value)) {
        return (string) $value;
    }
    return '';
}

function meta_dict(mixed $value): array
{
    return \is_array($value) && !\array_is_list($value) ? $value : [];
}

function meta_load_json(string $path, string $what): array
{
    $raw = @\file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("cannot read {$what}: {$path}");
    }
    try {
        $doc = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        throw new \RuntimeException("cannot read {$what}: {$e->getMessage()}");
    }
    return \is_array($doc) ? $doc : [];
}

/** @return array{string, string} numeric core, prerelease */
function meta_split_pre(string $version): array
{
    [$core, $build] = \array_pad(\explode('+', $version, 2), 2, null);
    if ($build === '') {
        throw new \RuntimeException("empty +build on {$version}");
    }
    if (\strlen($core) > 1 && \strtolower($core[0]) === 'v' && \ctype_digit($core[1])) {
        $core = \substr($core, 1);
    }
    [$numeric, $pre] = \array_pad(\explode('-', $core, 2), 2, '');
    return [$numeric, $pre];
}

function meta_caret_minor(string $version): string
{
    [$numeric] = meta_split_pre($version);
    $parts = \explode('.', $numeric);
    $minor = $parts[1] ?? '0';
    return "^{$parts[0]}.{$minor}";
}

function meta_sorted(array $value): array
{
    \ksort($value, \SORT_STRING);
    return $value;
}

/**
 * Public metapackage for an impl version folder.
 *
 * @return array{composer: array, text: string, handed: bool, upstream: string}
 */
function public_metapackage(string $implDir): array
{
    $folder = \basename(\rtrim($implDir, '/'));
    $src = meta_load_json($implDir . '/composer.json', 'impl composer.json');
    $tyhp = meta_dict(meta_dict($src['extra'] ?? null)['tyhp'] ?? null);
    $ownership = meta_dict($tyhp['ownership'] ?? null);
    $rawName = \trim(meta_text($src['name'] ?? null));
    if (!\str_starts_with($rawName, 'tyhpdef/')) {
        throw new \RuntimeException('impl name must be tyhpdef/* (got ' . \json_encode($rawName, \JSON_UNESCAPED_SLASHES) . ')');
    }

    $publicName = meta_text($tyhp['public'] ?? null);
    if (\str_ends_with($rawName, '-impl')) {
        $implName = $rawName;
        $publicName = \trim($publicName !== '' ? $publicName : \substr($rawName, 0, -\strlen('-impl')));
    } else {
        $publicName = \trim($publicName !== '' ? $publicName : $rawName);
        $implName = $publicName . '-impl';
    }

    $upstreamVer = $folder;
    if ($upstreamVer === '') {
        throw new \RuntimeException('upstream folder name is empty');
    }

    [$numeric, $pre] = meta_split_pre($upstreamVer);
    $implVersion = \trim(meta_text($src['version'] ?? null));
    if ($implVersion === '') {
        throw new \RuntimeException('impl composer.json is missing version');
    }

    $require = meta_dict($src['require'] ?? null);
    $phpConstraint = meta_text($require['php'] ?? null);
    $phpConstraint = $phpConstraint !== '' ? $phpConstraint : '>=8.2';
    $upstreamPkg = null;
    foreach (\array_keys($require) as $key) {
        $key = (string) $key;
        $low = \strtolower($key);
        if ($low === 'php' || \str_starts_with($low, 'ext-') || \str_starts_with($low, 'tyhp/') || \str_starts_with($low, 'tyhpdef/')) {
            continue;
        }
        if (\str_contains($key, '/')) {
            $upstreamPkg = $key;
            break;
        }
    }
    if ($upstreamPkg === null) {
        $upstreamPkg = (string) \preg_replace('/-/', '/', \substr($publicName, \strlen('tyhpdef/')), 1);
    }

    $handed = meta_text($ownership['status'] ?? null) === 'handed-off';
    $scenario = meta_text($ownership['scenario'] ?? null);
    $target = meta_text($ownership['target'] ?? null);
    $target = $target !== '' ? $target : $upstreamPkg;
    $fromUp = meta_text($ownership['fromUpstream'] ?? null);
    $fromUp = $fromUp !== '' ? $fromUp : $upstreamVer;

    if ($handed && $scenario === 'sibling') {
        $publicRequire = ['php' => $phpConstraint, $target => meta_caret_minor($fromUp)];
        $abandoned = $target;
        $description = "Metapackage: Tyhp types for {$upstreamPkg} {$fromUp}+ ship in {$target}.";
    } elseif ($handed) {
        $publicRequire = ['php' => $phpConstraint];
        $abandoned = $target;
        $description = "Metapackage: Tyhp types for {$upstreamPkg} {$fromUp}+ ship in {$target}.";
    } else {
        $implRequire = $pre !== '' ? $implVersion : "~{$numeric}.0";
        $publicRequire = [$implName => $implRequire];
        $abandoned = null;
        $description = "Tyhp type definitions for {$upstreamPkg} {$upstreamVer}.";
    }

    $public = [
        'name' => $publicName,
        'description' => $description,
        'type' => 'metapackage',
        'license' => 'Apache-2.0',
        'version' => $upstreamVer,
    ];
    if (!$handed) {
        $public['keywords'] = ['dev', 'static analysis'];
    }
    if ($abandoned !== null && $abandoned !== '') {
        $public['abandoned'] = $abandoned;
    }
    $public['require'] = $publicRequire;
    if ($handed) {
        $public['extra'] = [
            'tyhp' => [
                'ownership' => [
                    'status' => 'handed-off',
                    'scenario' => $scenario !== '' ? $scenario : 'bundled',
                    'target' => $target,
                    'fromUpstream' => $fromUp,
                ],
            ],
        ];
    } else {
        $public['extra'] = ['tyhp' => ['impl' => $implName]];
    }

    return [
        'composer' => $public,
        'text' => \json_encode($public, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n",
        'handed' => $handed,
        'upstream' => $upstreamVer,
    ];
}

/**
 * True when an existing public tag has the same version and impl require, so
 * publish does not retag it.
 */
function public_tag_unchanged(array $meta, array $existing): bool
{
    return meta_text($existing['version'] ?? null) === $meta['upstream']
        && meta_sorted(meta_dict($existing['require'] ?? null)) === meta_sorted($meta['composer']['require']);
}

function generate_meta_package_main(): int
{
    $env = static fn (string $name): string => \is_string($value = \getenv($name)) ? $value : '';
    $implDir = $env('IMPL_DIR');
    $outDir = $env('OUT_DIR');
    $existingPath = $env('EXISTING_PUBLIC');
    $dry = $env('DRY_RUN') === '1';
    $repoRoot = $env('REPO_ROOT');

    $meta = public_metapackage($implDir);

    if ($existingPath !== '' && public_tag_unchanged($meta, meta_load_json($existingPath, '--existing-public'))) {
        echo "public-tag: skip\n", $meta['text'];
        return 0;
    }

    echo "public-tag: emit\n";
    if ($dry) {
        echo $meta['text'];
        return 0;
    }

    if (!\is_dir($outDir) && !\mkdir($outDir, 0777, true) && !\is_dir($outDir)) {
        throw new \RuntimeException("cannot create {$outDir}");
    }
    \file_put_contents($outDir . '/composer.json', $meta['text']);

    $licenseSrc = $implDir . '/LICENSE';
    if (!\is_file($licenseSrc)) {
        $licenseSrc = $repoRoot . '/LICENSE.txt';
    }
    if (\is_file($licenseSrc)) {
        \copy($licenseSrc, $outDir . '/LICENSE');
    }

    $readme = \proc_open(
        [
            $repoRoot . '/write-package-readmes.sh',
            '--emit',
            $meta['handed'] ? 'handed-off' : 'public',
            '--package',
            \basename(\dirname($implDir)),
            '--version',
            $meta['upstream'],
            '--out',
            $outDir,
        ],
        [0 => \STDIN, 1 => \STDOUT, 2 => \STDERR],
        $pipes,
    );
    if (!\is_resource($readme)) {
        throw new \RuntimeException('cannot run write-package-readmes.sh');
    }
    $code = \proc_close($readme);
    if ($code !== 0) {
        return $code;
    }
    echo "wrote {$outDir}\n";
    return 0;
}

if (\get_included_files()[0] === __FILE__) {
    try {
        exit(generate_meta_package_main());
    } catch (\Throwable $e) {
        \fwrite(\STDERR, "error: {$e->getMessage()}\n");
        exit(2);
    }
}
