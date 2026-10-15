<?php

declare(strict_types=1);

namespace Tyhp\Tests\Parallel;

use Tyhp\ParallelTask;

final class DoubleTask implements ParallelTask
{
    public function __construct(private readonly int $n)
    {
    }

    public function run(): int
    {
        return $this->n * 2;
    }
}

final class PidTask implements ParallelTask
{
    public function run(): int
    {
        return \getmypid();
    }
}

final class SleepTask implements ParallelTask
{
    public function __construct(private readonly int $microseconds)
    {
    }

    public function run(): int
    {
        \usleep($this->microseconds);
        return \getmypid();
    }
}

final class BoomTask implements ParallelTask
{
    public function run(): int
    {
        throw new \RuntimeException('boom');
    }
}

final class CounterTask implements ParallelTask
{
    public function __construct(public int $value)
    {
    }

    public function run(): int
    {
        return $this->value;
    }
}

final class FailAfterTask implements ParallelTask
{
    public function __construct(private readonly int $microseconds)
    {
    }

    public function run(): int
    {
        \usleep($this->microseconds);
        throw new \RuntimeException('late boom', 7);
    }
}

final class ExitTask implements ParallelTask
{
    public function run(): int
    {
        exit(3);
    }
}

final class ClosureTask implements ParallelTask
{
    public function __construct(private readonly \Closure $fn)
    {
    }

    public function run(): int
    {
        return ($this->fn)();
    }
}
