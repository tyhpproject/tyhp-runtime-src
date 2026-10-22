<?php

declare(strict_types=1);

// The package's own composer.json points at src/Tyhp, which only exists in the built
// dist tree. Register the built lambda and core sources directly instead of going
// through vendor/autoload.php.
require_once \dirname(__DIR__) . '/php/EvalClosure.php';

$packagesRoot = \dirname(__DIR__, 3);
$sourceRoots = [
    $packagesRoot . '/dist/tyhp-lambda/802.latest/src/Tyhp',
    $packagesRoot . '/dist/tyhp-core/802.latest/src/Tyhp',
];

foreach ($sourceRoots as $sourceRoot) {
    if (!\is_dir($sourceRoot)) {
        \fwrite(\STDERR, "Missing {$sourceRoot}. Run packages/base-build-all.sh core lambda first.\n");
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

foreach ($sourceRoots as $sourceRoot) {
    if (\is_file($sourceRoot . '/_functions.php')) {
        require_once $sourceRoot . '/_functions.php';
    }
}
