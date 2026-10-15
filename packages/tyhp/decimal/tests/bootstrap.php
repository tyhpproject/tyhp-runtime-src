<?php

declare(strict_types=1);

// The package's own composer.json points at src/Tyhp, which only exists in the built
// dist tree. Register the built decimal and core sources directly instead of going
// through vendor/autoload.php.
$packagesRoot = \dirname(__DIR__, 3);
$sourceRoots = [
    $packagesRoot . '/dist/tyhp-decimal/802.latest/src/Tyhp',
    $packagesRoot . '/dist/tyhp-core/802.latest/src/Tyhp',
];

foreach ($sourceRoots as $sourceRoot) {
    if (!\is_dir($sourceRoot)) {
        \fwrite(\STDERR, "Missing {$sourceRoot}. Run packages/base-build-all.sh core decimal first.\n");
        exit(1);
    }
}

\spl_autoload_register(static function (string $class) use ($sourceRoots): void {
    if (!\str_starts_with($class, 'Tyhp\\')) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, 5)) . '.php';
    foreach ($sourceRoots as $sourceRoot) {
        if (\is_file($sourceRoot . '/' . $relative)) {
            require_once $sourceRoot . '/' . $relative;

            return;
        }
    }
});

require_once $sourceRoots[0] . '/_functions.php';
