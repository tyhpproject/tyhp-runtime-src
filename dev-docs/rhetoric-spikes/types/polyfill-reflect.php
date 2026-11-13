<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/build83/Rhetoric/Spikes/Types/HookModel.php';

$model = new \Rhetoric\Spikes\Types\HookModel();
$bagProperty = new \ReflectionProperty($model, '__tyhpPropertyHook');
$bag = $bagProperty->getValue($model);
$liveProperty = new \ReflectionProperty($bag, 'live');
$live = $liveProperty->getValue($bag);

echo "polyfill reflection on PHP " . PHP_VERSION . "\n";
echo "class doc:\n" . ((string) (new \ReflectionClass($model))->getDocComment()) . "\n";
foreach ($live as $name => $accessor) {
    echo $name
        . ' isBacked=' . ($accessor->isBacked() ? 'true' : 'false')
        . ' hasGet=' . ($accessor->hasGet() ? 'true' : 'false')
        . ' hasSet=' . ($accessor->hasSet() ? 'true' : 'false')
        . "\n";
}

try {
    (new \ReflectionClass($model))->getProperty('backed');
    echo "getProperty(backed) unexpectedly ok\n";
} catch (\Throwable $e) {
    echo 'getProperty(backed) ' . $e::class . ' ' . $e->getMessage() . "\n";
}
