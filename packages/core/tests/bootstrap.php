<?php

declare(strict_types=1);

$pluginDir = \dirname(__DIR__) . '/plugin';
$testsDir = __DIR__;
$runtimeSrcDirs = [
    \dirname(__DIR__) . '/src/Tyhp',
    \dirname(__DIR__, 2) . '/dist/tyhp-core/802.latest/src/Tyhp',
];

// Prepend so source plugin/ wins when PHPUnit was launched via another package's
// Composer autoload (which may already map Tyhp\Composer to dist tyhp/core).
\spl_autoload_register(static function (string $class) use ($pluginDir, $testsDir, $runtimeSrcDirs): void {
    $map = [
        'Tyhp\\Composer\\' => $pluginDir . '/',
        'Tyhp\\Tests\\' => $testsDir . '/',
    ];
    foreach ($map as $prefix => $base) {
        if (!\str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = \str_replace('\\', '/', \substr($class, \strlen($prefix)));
        $file = $base . $relative . '.php';
        if (\is_file($file)) {
            require $file;
        }

        return;
    }

    if (!\str_starts_with($class, 'Tyhp\\')) {
        return;
    }

    $relative = \str_replace('\\', '/', \substr($class, \strlen('Tyhp\\'))) . '.php';
    foreach ($runtimeSrcDirs as $base) {
        $file = $base . '/' . $relative;
        if (\is_file($file)) {
            require $file;
            return;
        }
    }
}, true, true);

if (!\interface_exists(\Composer\Plugin\PluginInterface::class, false)) {
    require __DIR__ . '/Composer/stubs/ComposerPluginApi.php';
}
