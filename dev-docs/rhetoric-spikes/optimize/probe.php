<?php

declare(strict_types=1);

require '/Users/anthonyrainer/repos/tyhp-runtime-src/dev-docs/rhetoric-spikes/illuminate/vendor/autoload.php';
require '/Users/anthonyrainer/repos/tyhp-runtime-src/dev-docs/rhetoric-spikes/illuminate/build/Rhetoric/Spikes/Illuminate/ProbeProvider.php';

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\OptimizeClearCommand;
use Illuminate\Foundation\Console\OptimizeCommand;
use Illuminate\Support\ServiceProvider;
use Rhetoric\Spikes\Illuminate\ProbeProvider;

$app = new Application('/tmp/rhetoric-spike-boot');
$provider = new ProbeProvider($app);
$provider->register();

$optimize = new \ReflectionMethod(OptimizeCommand::class, 'getOptimizeTasks');
$optimize->setAccessible(true);
$tasks = $optimize->invoke(new OptimizeCommand());
$clear = (new OptimizeClearCommand())->getOptimizeClearTasks();

echo 'optimize has rhetoric:cache=' . (in_array('rhetoric:cache', $tasks, true) ? 'yes' : 'no') . "\n";
echo 'clear has rhetoric:clear=' . (in_array('rhetoric:clear', $clear, true) ? 'yes' : 'no') . "\n";
echo 'optimizeCommands=' . \json_encode(ServiceProvider::$optimizeCommands) . "\n";
echo 'optimizeClearCommands=' . \json_encode(ServiceProvider::$optimizeClearCommands) . "\n";
