<?php

declare(strict_types=1);

$chirper = json_decode(
    (string) file_get_contents('/Users/anthonyrainer/repos/tyhp-examples/chirper/composer.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$root = '/Users/anthonyrainer/repos/tyhp-runtime-src/packages/tyhpdef';
$require = [];
$repos = [];
$missing = [];

foreach ($chirper['require-dev'] as $name => $version) {
    if (!str_starts_with($name, 'tyhpdef/')) {
        continue;
    }
    $folder = substr($name, strlen('tyhpdef/'));
    $dir = $root . '/' . $folder . '/' . $version;
    if (!is_file($dir . '/composer.json')) {
        $dir = $root . '/' . $folder;
    }
    if (!is_file($dir . '/composer.json')) {
        $missing[] = $name . ' ' . $version . ' missing ' . $dir;
        continue;
    }
    $require[$name] = '@dev';
    $repos[] = ['type' => 'path', 'url' => $dir];
}

$forced = [
    'tyhpdef/php-ext-imagick' => $root . '/php-ext-imagick',
    'tyhpdef/php-ext-memcached' => $root . '/php-ext-memcached',
    'tyhpdef/php-ext-redis' => $root . '/php-ext-redis',
    'tyhpdef/symfony-expression-language' => $root . '/symfony-expression-language/7.4.18',
];
foreach ($forced as $name => $dir) {
    $require[$name] = '@dev';
    $repos[] = ['type' => 'path', 'url' => $dir];
}

$repos[] = [
    'type' => 'path',
    'url' => '/Users/anthonyrainer/repos/tyhp-runtime-src/packages/dist/tyhp-core/805.latest',
];

$composer = [
    'name' => 'rhetoric/spikes-illuminate',
    'description' => 'Throwaway Phase 0 Illuminate tyhpdef spike. Not a package.',
    'type' => 'project',
    'require' => [
        'php' => '>=8.5',
        'tyhp/core' => '@dev',
    ],
    'require-dev' => $require,
    'extra' => [
        'tyhp' => [
            'require' => $require,
        ],
    ],
    'repositories' => $repos,
    'minimum-stability' => 'dev',
    'prefer-stable' => true,
    'config' => [
        'allow-plugins' => ['tyhp/core' => true],
        'policy' => [
            'advisories' => [
                'block' => false,
            ],
        ],
    ],
];

$out = '/Users/anthonyrainer/repos/tyhp-runtime-src/dev-docs/rhetoric-spikes/illuminate';
if (!is_dir($out)) {
    mkdir($out, 0777, true);
}
file_put_contents(
    $out . '/composer.json',
    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);
file_put_contents($out . '/missing.txt', $missing === [] ? "none\n" : implode("\n", $missing) . "\n");
echo 'packages ' . count($require) . ' missing ' . count($missing) . "\n";
