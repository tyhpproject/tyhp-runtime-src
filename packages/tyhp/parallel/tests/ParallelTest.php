<?php

declare(strict_types=1);

namespace Tyhp\Tests\Parallel;

use PHPUnit\Framework\TestCase;
use Tyhp\CancellationTokenSource;
use Tyhp\EventLoop;
use Tyhp\Exceptions\OperationCancelledException;
use Tyhp\Exceptions\ParallelException;
use Tyhp\Parallel;

final class ParallelTest extends TestCase
{
    protected function setUp(): void
    {
        \putenv('TYHP_PARALLEL_SOCKET');
    }

    protected function tearDown(): void
    {
        Parallel::shutdown();
        \putenv('TYHP_PARALLEL_SOCKET');
        EventLoop::resetInstance();
    }

    public function testRunReturnsTheTaskResult(): void
    {
        Parallel::start(1);
        $result = EventLoop::getInstance()->runUntilSettled(
            Parallel::runAsync(new DoubleTask(21))
        );

        $this->assertSame(42, $result);
    }

    public function testInvokeUsesDistinctWorkerProcesses(): void
    {
        Parallel::start(2);
        $promise = Parallel::invokeAsync([
            new PidTask(),
            new PidTask(),
        ]);
        $pids = EventLoop::getInstance()->runUntilSettled($promise);

        $this->assertCount(2, $pids);
        $this->assertNotSame(\getmypid(), $pids[0]);
        $this->assertNotSame(\getmypid(), $pids[1]);
        $this->assertNotSame($pids[0], $pids[1]);
    }

    public function testForEachPreservesOrder(): void
    {
        Parallel::start(2);
        $promise = Parallel::forEachAsync([10, 20, 30], static fn (int $n): DoubleTask => new DoubleTask($n));
        $result = EventLoop::getInstance()->runUntilSettled($promise);

        $this->assertSame([20, 40, 60], $result);
    }

    public function testForEachKeepsStringKeys(): void
    {
        Parallel::start(2);
        $promise = Parallel::forEachAsync(['a' => 1, 'b' => 2], static fn (int $n): DoubleTask => new DoubleTask($n));
        $result = EventLoop::getInstance()->runUntilSettled($promise);

        $this->assertSame(['a' => 2, 'b' => 4], $result);
    }

    public function testForUsesAnExclusiveEnd(): void
    {
        Parallel::start(2);
        $promise = Parallel::forAsync(1, 4, static fn (int $i): DoubleTask => new DoubleTask($i));
        $result = EventLoop::getInstance()->runUntilSettled($promise);

        $this->assertSame([1 => 2, 2 => 4, 3 => 6], $result);
    }

    public function testTaskExceptionIsReportedAndTheWorkerStaysUsable(): void
    {
        Parallel::start(1);
        $failed = Parallel::runAsync(new BoomTask());
        try {
            EventLoop::getInstance()->runUntilSettled($failed);
            $this->fail('The failing task should reject the promise.');
        } catch (ParallelException $e) {
            $this->assertSame(\RuntimeException::class, $e->getRemoteClass());
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $result = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new DoubleTask(2)));
        $this->assertSame(4, $result);
    }

    public function testRemoteErrorCarriesWorkerDetails(): void
    {
        Parallel::start(1);
        try {
            EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new FailAfterTask(0)));
            $this->fail('The failing task should reject the promise.');
        } catch (ParallelException $e) {
            $remote = $e->getRemoteError();
            $this->assertIsArray($remote);
            $this->assertSame(\RuntimeException::class, $remote['class']);
            $this->assertSame('late boom', $remote['message']);
            $this->assertSame(7, $remote['code']);
            $this->assertStringEndsWith('Fixtures.php', $remote['file']);
            $this->assertGreaterThan(0, $remote['line']);
            $this->assertStringContainsString('FailAfterTask->run()', $remote['trace']);
        }
    }

    public function testTaskIsSnapshotWhenQueued(): void
    {
        Parallel::start(1);
        $busy = Parallel::runAsync(new SleepTask(200000));
        $task = new CounterTask(1);
        $queued = Parallel::runAsync($task);
        $task->value = 99;

        $this->assertIsInt(EventLoop::getInstance()->runUntilSettled($busy));
        $this->assertSame(1, EventLoop::getInstance()->runUntilSettled($queued));
    }

    public function testCancellingAQueuedTaskRejectsIt(): void
    {
        Parallel::start(1);
        $busy = Parallel::runAsync(new SleepTask(200000));
        $source = new CancellationTokenSource();
        $queued = Parallel::runAsync(new DoubleTask(5), $source->getToken());
        $source->cancel();

        try {
            EventLoop::getInstance()->runUntilSettled($queued);
            $this->fail('The cancelled task should reject the promise.');
        } catch (OperationCancelledException) {
        }
        $this->assertIsInt(EventLoop::getInstance()->runUntilSettled($busy));
    }

    public function testCancellingARunningTaskReplacesItsWorker(): void
    {
        Parallel::start(1);
        $firstPid = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new PidTask()));
        $this->assertIsInt($firstPid);

        $source = new CancellationTokenSource(100);
        $started = \hrtime(true);
        try {
            EventLoop::getInstance()->runUntilSettled(
                Parallel::runAsync(new SleepTask(5000000), $source->getToken()),
                3000
            );
            $this->fail('The cancelled task should reject the promise.');
        } catch (OperationCancelledException) {
        }
        $elapsedMs = (\hrtime(true) - $started) / 1_000_000;

        $this->assertLessThan(2000, $elapsedMs);
        $this->assertFalse(@\posix_kill($firstPid, 0));
        $secondPid = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new PidTask()));
        $this->assertIsInt($secondPid);
        $this->assertNotSame($firstPid, $secondPid);
    }

    public function testInvokeFailsFastAndCancelsTheOtherTasks(): void
    {
        Parallel::start(2);
        $started = \hrtime(true);
        try {
            EventLoop::getInstance()->runUntilSettled(
                Parallel::invokeAsync([new SleepTask(5000000), new FailAfterTask(50000)]),
                4000
            );
            $this->fail('invokeAsync() should reject when one task fails.');
        } catch (ParallelException $e) {
            $remote = $e->getRemoteError();
            $this->assertIsArray($remote);
            $this->assertSame('late boom', $remote['message']);
        }
        $elapsedMs = (\hrtime(true) - $started) / 1_000_000;

        $this->assertLessThan(2000, $elapsedMs);
    }

    public function testCancellingInvokeThrowsOperationCancelled(): void
    {
        Parallel::start(2);
        $source = new CancellationTokenSource(100);

        $this->expectException(OperationCancelledException::class);
        EventLoop::getInstance()->runUntilSettled(
            Parallel::invokeAsync([new SleepTask(5000000), new SleepTask(5000000)], $source->getToken()),
            3000
        );
    }

    public function testUnserializableTaskFailsInTheParent(): void
    {
        $this->expectException(ParallelException::class);
        Parallel::runAsync(new ClosureTask(static fn (): int => 1));
    }

    public function testShutdownReapsTheWorker(): void
    {
        Parallel::start(1);
        $pid = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new PidTask()));
        $this->assertIsInt($pid);
        $this->assertTrue(@\posix_kill($pid, 0));

        Parallel::shutdown();

        $this->assertFalse(@\posix_kill($pid, 0));
    }

    public function testStartRejectsASecondSize(): void
    {
        Parallel::start(1);
        $this->expectException(\LogicException::class);
        Parallel::start(2);
    }

    public function testServeRejectsAnInvalidSocketMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Parallel::serve(\sys_get_temp_dir() . '/tyhp-parallel-mode.sock', 1, 01000);
    }
}
