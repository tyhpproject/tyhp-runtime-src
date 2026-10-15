<?php

declare(strict_types=1);

$packageRoot = \dirname(__DIR__);
$vendorAutoload = $packageRoot . '/vendor/autoload.php';
$ioSrc = \dirname($packageRoot, 2) . '/dist/tyhp-io/802.latest/src/Tyhp';

if (!\is_file($vendorAutoload)) {
    \fwrite(\STDERR, "Missing {$vendorAutoload}. Run packages/base-build-all.sh io first.\n");
    exit(1);
}
if (!\is_dir($ioSrc)) {
    \fwrite(\STDERR, "Missing {$ioSrc}. Run packages/base-build-all.sh io first.\n");
    exit(1);
}

$loader = require $vendorAutoload;
$loader->addPsr4('Tyhp\\', $ioSrc);
require __DIR__ . '/Fixtures.php';
