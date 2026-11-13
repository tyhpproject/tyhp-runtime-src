<?php

declare(strict_types=1);

require '/Users/anthonyrainer/repos/tyhp-runtime-src/dev-docs/rhetoric-spikes/illuminate/vendor/autoload.php';

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;

$migrator = (new \ReflectionClass(Migrator::class))->newInstanceWithoutConstructor();
$files = new \ReflectionProperty(Migrator::class, 'files');
$files->setAccessible(true);
$files->setValue($migrator, new Filesystem());
$resolve = new \ReflectionMethod(Migrator::class, 'resolvePath');
$resolve->setAccessible(true);

$root = __DIR__ . '/migrations';
foreach ([
    'php' => $root . '/2026_11_04_000000_create_posts_php.php',
    'tyhp' => $root . '/2026_11_04_000001_create_posts_tyhp.php',
] as $label => $path) {
    try {
        $instance = $resolve->invoke($migrator, $path);
        $class = \get_class($instance);
        $deferred = \property_exists($instance, 'deferred') ? \var_export($instance->deferred, true) : 'missing';
        $independent = \property_exists($instance, 'independent') ? \var_export($instance->independent, true) : 'missing';
        echo $label . ' class=' . $class . ' deferred=' . $deferred . ' independent=' . $independent . "\n";
    } catch (\Throwable $e) {
        echo $label . ' ' . $e::class . ' ' . $e->getMessage() . "\n";
    }
}
