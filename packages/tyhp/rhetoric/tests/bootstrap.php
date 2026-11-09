<?php

declare(strict_types=1);

$packageRoot = \dirname(__DIR__);
$vendorAutoload = $packageRoot . '/vendor/autoload.php';
$rhetoricSrc = \dirname($packageRoot, 2) . '/dist/tyhp-rhetoric/803.latest/src/Tyhp';

if (!\is_file($vendorAutoload)) {
    \fwrite(\STDERR, "Missing {$vendorAutoload}. Run packages/base-build-all.sh rhetoric first.\n");
    exit(1);
}
if (!\is_dir($rhetoricSrc)) {
    \fwrite(\STDERR, "Missing {$rhetoricSrc}. Run packages/base-build-all.sh rhetoric first.\n");
    exit(1);
}

$loader = require $vendorAutoload;
$loader->addPsr4('Tyhp\\', $rhetoricSrc);
$functions = $rhetoricSrc . '/Rhetoric/Fixtures/Migrations/_functions.php';
if (\is_file($functions)) {
    require_once $functions;
}
$declarations = $rhetoricSrc . '/Rhetoric/Fixtures/_functions.php';
if (\is_file($declarations)) {
    require_once $declarations;
}
