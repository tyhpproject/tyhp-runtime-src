<?php

declare(strict_types=1);

namespace Tyhp\Tests\Async;

use PHPUnit\Framework\TestCase;
use Tyhp\CancellationTokenSource;
use Tyhp\Deferred;
use Tyhp\EventLoop;
use Tyhp\Exceptions\OperationCancelledException;
use Tyhp\Exceptions\TimeoutException;
use Tyhp\Promise;
use Tyhp\Type;

use function Tyhp\async;
use function Tyhp\await;

final class AsyncAwaitFunctionsTest extends TestCase
{
    protected function tearDown(): void
    {
        EventLoop::resetInstance();
    }

    public function testAwaitAsyncFromSynchronousPhpReturnsTheValueAndRunsQueuedWork(): void
    {
        $ran = false;
        $result = await(async(function () use (&$ran): int {
            EventLoop::getInstance()->defer(static function () use (&$ran): void {
                $ran = true;
            });

            return 42;
        }));

        $this->assertSame(42, $result);
        $this->assertTrue($ran);
    }

    public function testAwaitInsideAsyncSuspendsAndResumes(): void
    {
        $result = await(async(function (): int {
            $value = await(async(static fn (): int => 7));

            return $value + 1;
        }));

        $this->assertSame(8, $result);
    }

    public function testConcurrentAwaitsInterleave(): void
    {
        $order = [];
        $slow = async(function () use (&$order): string {
            $order[] = 'a-start';
            await(Promise::delay(60));
            $order[] = 'a-end';

            return 'a';
        });
        $fast = async(function () use (&$order): string {
            $order[] = 'b-start';
            await(Promise::delay(15));
            $order[] = 'b-end';

            return 'b';
        });

        $this->assertSame('a', await($slow));
        $this->assertSame(['a-start', 'b-start', 'b-end', 'a-end'], $order);
        $this->assertSame('b', await($fast));
    }

    public function testAwaitTimeoutFromSynchronousPhp(): void
    {
        $this->expectException(TimeoutException::class);

        $deferred = new Deferred();
        await($deferred->getPromise(), 10);
    }

    public function testAwaitTimeoutInsideAFiber(): void
    {
        $this->expectException(TimeoutException::class);

        await(async(function (): void {
            $deferred = new Deferred();
            await($deferred->getPromise(), 10);
        }));
    }

    public function testCancelledTokenFromSynchronousPhp(): void
    {
        $this->expectException(OperationCancelledException::class);

        $source = new CancellationTokenSource();
        $source->cancel();
        $deferred = new Deferred();
        await($deferred->getPromise(), null, $source->getToken());
    }

    public function testCancelledTokenInsideAFiber(): void
    {
        $this->expectException(OperationCancelledException::class);

        await(async(function (): void {
            $source = new CancellationTokenSource();
            $source->cancel();
            $deferred = new Deferred();
            await($deferred->getPromise(), null, $source->getToken());
        }));
    }

    public function testTokenCancelledWhileTheLoopRuns(): void
    {
        $this->expectException(OperationCancelledException::class);

        $source = new CancellationTokenSource(10);
        $deferred = new Deferred();
        await($deferred->getPromise(), null, $source->getToken());
    }

    public function testAwaitFromALoopCallbackOutsideAFiberThrows(): void
    {
        $this->expectException(\LogicException::class);

        await(async(function (): void {
            EventLoop::getInstance()->defer(static function (): void {
                await(Promise::resolved(1));
            });
        }));
    }

    public function testAwaitFromAUserFiberRunsTheLoop(): void
    {
        $fiber = new \Fiber(static function (): int {
            return await(async(static fn (): int => 42));
        });
        $fiber->start();

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame(42, $fiber->getReturn());
    }

    public function testPlainCallsInferPromiseResultTypes(): void
    {
        $numbers = Promise::resolved(1);
        $words = Promise::resolved('x');

        $mapped = $numbers->then(static fn (int $value): string => 'n' . $value);
        $all = Promise::all([$numbers, $words]);
        $race = Promise::race([$numbers, $words]);

        $this->assertSame('string|int', (string) Type::of($mapped)->typeArgument(0));
        $this->assertSame('array<int|string>', (string) Type::of($all)->typeArgument(0));
        $this->assertSame('int|string', (string) Type::of($race)->typeArgument(0));
    }

    public function testPlainCallsWithSameResultTypesInferThatType(): void
    {
        $first = Promise::resolved(1);
        $second = Promise::resolved(2);

        $this->assertSame('array<int>', (string) Type::of(Promise::all([$first, $second]))->typeArgument(0));
        $this->assertSame('int', (string) Type::of(Promise::race([$first, $second]))->typeArgument(0));
        $this->assertSame('array<int>', (string) Type::of(Promise::whenAll($first, $second))->typeArgument(0));
        $this->assertSame('int', (string) Type::of(Promise::whenAny($first, $second))->typeArgument(0));
    }

    public function testPlainResolveInfersFromAValueOrAPromise(): void
    {
        $promise = Promise::resolved('x');

        $this->assertSame('int', (string) Type::of(Promise::resolve(5))->typeArgument(0));
        $this->assertSame('string', (string) Type::of(Promise::resolve($promise))->typeArgument(0));
        $this->assertSame($promise, Promise::resolve($promise));
        $this->assertSame(5, Promise::resolve(5)->wait());
    }
}
