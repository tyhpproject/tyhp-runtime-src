<?php

declare(strict_types=1);

$packageRoot = \dirname(__DIR__);
$vendorAutoload = $packageRoot . '/vendor/autoload.php';
$parallelSrc = \dirname($packageRoot, 2) . '/dist/tyhp-parallel/802.latest/src/Tyhp';

if (!\is_file($vendorAutoload)) {
    \fwrite(\STDERR, "Missing {$vendorAutoload}. Run packages/base-build-all.sh parallel first.\n");
    exit(1);
}
if (!\is_dir($parallelSrc)) {
    \fwrite(\STDERR, "Missing {$parallelSrc}. Run packages/base-build-all.sh parallel first.\n");
    exit(1);
}

$loader = require $vendorAutoload;
$loader->addPsr4('Tyhp\\', $parallelSrc);
require __DIR__ . '/Fixtures.php';
