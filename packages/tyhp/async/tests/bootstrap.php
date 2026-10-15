<?php

declare(strict_types=1);

// The package's own composer.json points at src/Tyhp, which only exists in the built
// dist tree. Register the built async and core sources for the running PHP version
// (falling back to the 8.2 build) instead of going through vendor/autoload.php.
$packagesRoot = \dirname(__DIR__, 3);
$phpMajor = (string) (\PHP_MAJOR_VERSION * 100 + \PHP_MINOR_VERSION);
$sourceRoots = [];

foreach (['tyhp-async', 'tyhp-core'] as $dist) {
    $sourceRoot = $packagesRoot . '/dist/' . $dist . '/' . $phpMajor . '.latest/src/Tyhp';
    if (!\is_dir($sourceRoot)) {
        $sourceRoot = $packagesRoot . '/dist/' . $dist . '/802.latest/src/Tyhp';
    }
    if (!\is_dir($sourceRoot)) {
        \fwrite(\STDERR, "Missing {$sourceRoot}. Run packages/base-build-all.sh core async first.\n");
        exit(1);
    }
    $sourceRoots[] = $sourceRoot;
}

// Suite watchdog: a broken event loop can wait forever for work that never arrives. End the
// run with a clear message instead of hanging.
if (\function_exists('pcntl_alarm')) {
    \pcntl_async_signals(true);
    $watchdogSeconds = (int) (\getenv('TYHP_TEST_WATCHDOG') ?: 120);
    \pcntl_signal(\SIGALRM, static function () use ($watchdogSeconds): void {
        \fwrite(\STDERR, "\nasync test suite exceeded its {$watchdogSeconds} second watchdog; the event loop is probably waiting for work that never arrives.\n");
        exit(2);
    });
    \pcntl_alarm($watchdogSeconds);
}

\spl_autoload_register(static function (string $class) use ($sourceRoots): void {
    if (!\str_starts_with($class, 'Tyhp\\') || \str_starts_with($class, 'Tyhp\\Tests\\')) {
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
    $rootFunctions = \dirname($sourceRoot) . '/_functions.php';
    if (\is_file($rootFunctions)) {
        require_once $rootFunctions;
    }
}

require_once __DIR__ . '/Deferreds.php';
