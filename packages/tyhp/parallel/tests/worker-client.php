<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Tyhp\CancellationTokenSource;
use Tyhp\EventLoop;
use Tyhp\Exceptions\OperationCancelledException;
use Tyhp\Parallel;
use Tyhp\Tests\Parallel\DoubleTask;
use Tyhp\Tests\Parallel\SleepTask;

$socket = $argv[1] ?? '';
$kind = $argv[2] ?? '';
$payload = $argv[3] ?? '';

\putenv('TYHP_PARALLEL_SOCKET=' . $socket);

try {
    if ($kind === 'abandon') {
        try {
            EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new SleepTask((int) $payload)), 200);
        } catch (\Throwable $e) {
            echo 'abandoned';
            exit(0);
        }
        throw new \RuntimeException('The task finished before the client gave up.');
    }

    if ($kind === 'cancel') {
        $source = new CancellationTokenSource(200);
        try {
            EventLoop::getInstance()->runUntilSettled(
                Parallel::runAsync(new SleepTask((int) $payload), $source->getToken()),
                5000
            );
            throw new \RuntimeException('The task was not cancelled.');
        } catch (OperationCancelledException) {
        }
        $result = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new DoubleTask(4)), 5000);
        echo 'cancelled ' . (\is_int($result) ? (string) $result : '');
        exit(0);
    }

    $task = match ($kind) {
        'double' => new DoubleTask((int) $payload),
        'sleep' => new SleepTask((int) $payload),
        default => throw new \InvalidArgumentException('Unknown client task.'),
    };
    $result = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync($task), 5000);
    echo \is_int($result) ? (string) $result : '';
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(1);
}
