<?php

declare(strict_types=1);

namespace Tyhp\Tests\Async;

use PHPUnit\Framework\TestCase;
use Tyhp\CancellationTokenSource;
use Tyhp\EventLoop;
use Tyhp\Exceptions\OperationCancelledException;
use Tyhp\Promise;

/**
 * Promise::run() is the blocking entry point: it must return the settled value for every shape
 * of callback, and rethrow failures. The `async` callback shapes are what the Tyhp emitter
 * produces: a Promise-returning closure (declared `: \Tyhp\Promise`) wrapping `Promise::_async`.
 */
final class PromiseRunTest extends TestCase
{
    protected function tearDown(): void
    {
        EventLoop::resetInstance();
    }

    public function testPlainClosureReturnsItsValue(): void
    {
        $this->assertSame(5, Promise::run(static fn(): int => 5));
    }

    public function testPlainClosureReturningNullReturnsNull(): void
    {
        $this->assertNull(Promise::run(static function (): void {}));
    }

    public function testAsyncClosureReturnsSettledValueNotThePromise(): void
    {
        // Emitted shape of `Promise::run(async function(): int { await Promise::delay(5); return 42; })`.
        $result = Promise::run(
            static function (): Promise {
                return Promise::_async(static function (): int {
                    Promise::_await(Promise::delay(5));

                    return 42;
                });
            },
        );

        $this->assertSame(42, $result);
    }

    public function testAsyncArrowFunctionReturnsSettledValue(): void
    {
        // Emitted shape of `Promise::run(async fn(): int => await Promise::resolve(7))`.
        $result = Promise::run(
            static fn(): Promise => Promise::_async(static fn() => Promise::_await(Promise::resolve(7))),
        );

        $this->assertSame(7, $result);
    }

    public function testAsyncClosureReturningAnArray(): void
    {
        $result = Promise::run(
            static fn(): Promise => Promise::_async(static function (): array {
                $a = Promise::_async(static fn(): int => 1);
                $b = Promise::_async(static fn(): int => 2);

                return Promise::_await(Promise::all([$a, $b]));
            }),
        );

        $this->assertSame([1, 2], $result);
    }

    public function testAsyncClosureReturningAnObject(): void
    {
        $object = new \ArrayObject(['id' => 7]);

        $result = Promise::run(
            static fn(): Promise => Promise::_async(static function () use ($object): \ArrayObject {
                Promise::_await(Promise::delay(1));

                return $object;
            }),
        );

        $this->assertSame($object, $result);
    }

    public function testNestedPromiseIsAdoptedDownToTheValue(): void
    {
        $result = Promise::run(
            static fn(): Promise => Promise::_async(
                static fn(): Promise => Promise::_async(static fn(): int => 9),
            ),
        );

        $this->assertSame(9, $result);
    }

    public function testCallbackReturningFulfilledPromiseReturnsItsValue(): void
    {
        $this->assertSame('ready', Promise::run(static fn(): Promise => Promise::resolve('ready')));
    }

    public function testCallbackReturningPendingPromiseWaitsForIt(): void
    {
        $deferred = Deferreds::mixed();
        EventLoop::getInstance()->delay(20, static function () use ($deferred): void {
            $deferred->resolve('late');
        });

        $this->assertSame('late', Promise::run(static fn(): Promise => $deferred->getPromise()));
    }

    public function testRunWaitsForTimersAwaitedByTheCallback(): void
    {
        $start = \hrtime(true);

        $result = Promise::run(
            static fn(): Promise => Promise::_async(static function (): string {
                Promise::_await(Promise::delay(60));

                return 'waited';
            }),
        );

        $elapsedMs = (\hrtime(true) - $start) / 1_000_000;

        $this->assertSame('waited', $result);
        $this->assertGreaterThanOrEqual(50, $elapsedMs);
    }

    public function testPlainClosureThrowingIsRethrown(): void
    {
        $error = new \RuntimeException('plain failure');

        try {
            Promise::run(static function () use ($error): int {
                throw $error;
            });
            $this->fail('Expected the callback exception to be rethrown');
        } catch (\Throwable $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testAsyncClosureThrowingAfterAwaitIsRethrown(): void
    {
        $error = new \LogicException('async failure');

        try {
            Promise::run(
                static fn(): Promise => Promise::_async(static function () use ($error): int {
                    Promise::_await(Promise::delay(1));

                    throw $error;
                }),
            );
            $this->fail('Expected the async callback exception to be rethrown');
        } catch (\Throwable $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testCallbackReturningRejectedPromiseRethrowsTheReason(): void
    {
        $error = new \DomainException('rejected');

        try {
            Promise::run(static fn(): Promise => Promise::reject($error));
            $this->fail('Expected the rejection reason to be rethrown');
        } catch (\Throwable $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testLoopIsNotRunningAfterAFailure(): void
    {
        try {
            Promise::run(static function (): int {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertFalse(EventLoop::getInstance()->isRunning());
        $this->assertSame(3, Promise::run(static fn(): int => 3), 'the loop can be used again');
    }

    public function testCancelledTokenSkipsTheCallback(): void
    {
        $source = new CancellationTokenSource();
        $source->cancel();
        $called = false;

        try {
            Promise::run(static function () use (&$called): int {
                $called = true;

                return 1;
            }, $source->getToken());
            $this->fail('Expected OperationCancelledException');
        } catch (OperationCancelledException) {
            $this->assertFalse($called);
        }
    }

    // ── Cancellation while the loop runs ─────────────────────────────
    //
    // Every wait below is bounded (a short delay, a loop with an iteration cap and a wall-clock
    // cap), so a regression ends in a failed assertion within a few seconds, never a hang.

    public function testTokenCancelledByTimerStopsARunThatIsStillWaiting(): void
    {
        $source = new CancellationTokenSource();
        EventLoop::getInstance()->delay(20, static function () use ($source): void {
            $source->cancel();
        });

        $start = \hrtime(true);
        $state = new \stdClass();
        $state->completed = false;

        try {
            Promise::run(
                static fn(): Promise => Promise::_async(static function () use ($state): int {
                    Promise::_await(Promise::delay(1500));
                    $state->completed = true;

                    return 1;
                }),
                $source->getToken(),
            );
            $this->fail('Expected OperationCancelledException');
        } catch (OperationCancelledException $e) {
            $this->assertSame($source->getToken(), $e->getToken());
        }

        $elapsedMs = (\hrtime(true) - $start) / 1_000_000;
        $this->assertLessThan(1000, $elapsedMs, 'cancellation must not wait for the 1500ms delay');
        $this->assertFalse($state->completed);
        $this->assertFalse(EventLoop::getInstance()->isRunning());
    }

    public function testBusyLoopThatYieldsToTheLoopIsStoppedByCancellation(): void
    {
        $source = new CancellationTokenSource();
        EventLoop::getInstance()->delay(20, static function () use ($source): void {
            $source->cancel();
        });

        $counter = new \stdClass();
        $counter->iterations = 0;
        $start = \hrtime(true);

        try {
            Promise::run(
                static fn(): Promise => Promise::_async(static function () use ($counter, $start): int {
                    // yieldTick() defers to the timer phase, so each pass is one event-loop iteration.
                    while ($counter->iterations < 5_000_000) {
                        Promise::_await(Promise::yieldTick());
                        $counter->iterations++;
                        if ((\hrtime(true) - $start) / 1_000_000 > 3000) {
                            break; // watchdog: cancellation did not stop the loop
                        }
                    }

                    return $counter->iterations;
                }),
                $source->getToken(),
            );
            $this->fail('Expected OperationCancelledException');
        } catch (OperationCancelledException) {
            $this->assertGreaterThan(0, $counter->iterations, 'the loop ran before the token was cancelled');
        }

        $elapsedMs = (\hrtime(true) - $start) / 1_000_000;
        $this->assertLessThan(2500, $elapsedMs, 'the watchdog must not have been needed');
        $this->assertLessThan(5_000_000, $counter->iterations);
    }

    public function testCancelRequestedInTheIterationTheRootSucceedsReturnsTheResult(): void
    {
        $source = new CancellationTokenSource();

        $result = Promise::run(
            static fn(): Promise => Promise::_async(static function () use ($source): string {
                Promise::_await(Promise::delay(1));
                $source->cancel();

                return 'finished';
            }),
            $source->getToken(),
        );

        $this->assertSame('finished', $result);
        $this->assertTrue($source->getToken()->isCancellationRequested());
    }

    public function testCancelRequestedInTheIterationTheRootFailsRethrowsItsOwnError(): void
    {
        $source = new CancellationTokenSource();
        $error = new \DomainException('own failure');

        try {
            Promise::run(
                static fn(): Promise => Promise::_async(static function () use ($source, $error): int {
                    Promise::_await(Promise::delay(1));
                    $source->cancel();

                    throw $error;
                }),
                $source->getToken(),
            );
            $this->fail('Expected the root failure to be rethrown');
        } catch (\Throwable $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testNeverCancelledTokenBehavesLikeNoToken(): void
    {
        $source = new CancellationTokenSource();

        $result = Promise::run(
            static fn(): Promise => Promise::_async(static function (): string {
                Promise::_await(Promise::delay(15));

                return 'waited';
            }),
            $source->getToken(),
        );

        $this->assertSame('waited', $result);
        $this->assertSame(4, Promise::run(static fn(): int => 4, $source->getToken()));
    }

    // ── Cancelling a run shuts the whole loop down ───────────────────

    private function cancelAfter(CancellationTokenSource $source, int $ms): void
    {
        EventLoop::getInstance()->delay($ms, static function () use ($source): void {
            $source->cancel();
        });
    }

    /** Runs `$fn` with the token and returns the cancellation it ends with (waits are all bounded). */
    private function runUntilCancelled(CancellationTokenSource $source, callable $fn): OperationCancelledException
    {
        try {
            Promise::run($fn, $source->getToken());
        } catch (OperationCancelledException $e) {
            return $e;
        }

        $this->fail('Expected OperationCancelledException');
    }

    /** A root that outlives the cancellation by far: it only ends because the token was cancelled. */
    private static function longRoot(): \Closure
    {
        return static fn(): Promise => Promise::_async(static function (): int {
            Promise::_await(Promise::delay(1500));

            return 1;
        });
    }

    private function runLater(int $ms): void
    {
        $this->assertSame('later', Promise::run(
            static fn(): Promise => Promise::_async(static function () use ($ms): string {
                Promise::_await(Promise::delay($ms));

                return 'later';
            }),
        ));
    }

    public function testCancelledRunStopsEveryTimerAndInterval(): void
    {
        $source = new CancellationTokenSource();
        $loop = EventLoop::getInstance();
        $state = new \stdClass();
        $state->timerFired = false;
        $state->ticks = 0;

        $loop->delay(45, static function () use ($state): void {
            $state->timerFired = true;
        });
        $loop->interval(8, static function () use ($state): void {
            $state->ticks++;
        });
        $this->cancelAfter($source, 25);

        $this->runUntilCancelled($source, self::longRoot());
        $ticksAtCancel = $state->ticks;

        $this->runLater(80);

        $this->assertFalse($state->timerFired, 'a timer registered before the cancel must not fire later');
        $this->assertSame($ticksAtCancel, $state->ticks, 'an interval must stop ticking');
        $this->assertFalse($loop->isRunning());
    }

    public function testCancelledRunRemovesStreamWatchers(): void
    {
        $source = new CancellationTokenSource();
        $loop = EventLoop::getInstance();
        $pair = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$reader, $writer] = $pair;
        $reads = new \stdClass();
        $reads->count = 0;

        $loop->addReadStream($reader, static function ($stream) use ($reads): void {
            $reads->count++;
            \fread($stream, 8192);
        });
        $this->cancelAfter($source, 20);

        $this->runUntilCancelled($source, self::longRoot());

        \fwrite($writer, 'data'); // the reader is readable now; a surviving watcher would fire
        $this->runLater(30);

        $this->assertSame(0, $reads->count);
        \fclose($reader);
        \fclose($writer);
    }

    public function testCancelledRunDropsQueuedMicrotasksAndRejectsFibersThatNeverStarted(): void
    {
        $source = new CancellationTokenSource();
        $loop = EventLoop::getInstance();
        $state = new \stdClass();
        $state->microtaskRan = false;
        $state->lateStarted = false;
        $state->late = null;

        // Both are queued in the same iteration as the cancellation, after the microtask phase.
        $loop->delay(20, static function () use ($source, $loop, $state): void {
            $source->cancel();
            $loop->queueMicrotask(static function () use ($state): void {
                $state->microtaskRan = true;
            });
            $state->late = Promise::_async(static function () use ($state): int {
                $state->lateStarted = true;

                return 1;
            });
        });

        $this->runUntilCancelled($source, self::longRoot());
        $this->runLater(20);

        $this->assertFalse($state->microtaskRan);
        $this->assertFalse($state->lateStarted);
        $this->assertInstanceOf(Promise::class, $state->late);
        $this->assertTrue($state->late->isFaulted(), 'the promise of a fiber that never started is rejected');
        $this->assertInstanceOf(OperationCancelledException::class, $state->late->getError());
    }

    public function testCancelledRunUnwindsSuspendedFibersAndTheyNeverResumeLater(): void
    {
        $source = new CancellationTokenSource();
        $awaited = Deferreds::mixed();
        $state = new \stdClass();
        $state->cleaned = false;
        $state->resumed = false;
        $state->child = null;

        $this->cancelAfter($source, 25);
        $this->runUntilCancelled(
            $source,
            static fn(): Promise => Promise::_async(static function () use ($awaited, $state): int {
                $state->child = Promise::_async(static function () use ($awaited, $state): string {
                    try {
                        Promise::_await($awaited->getPromise());
                        $state->resumed = true;

                        return 'value';
                    } finally {
                        $state->cleaned = true;
                    }
                });
                Promise::_await(Promise::delay(1500));

                return 1;
            }),
        );

        $this->assertTrue($state->cleaned, 'finally blocks of a suspended fiber run');
        $this->assertFalse($state->resumed);
        $this->assertInstanceOf(Promise::class, $state->child);
        $this->assertTrue($state->child->isFaulted());
        $this->assertInstanceOf(OperationCancelledException::class, $state->child->getError());

        // The promise it was waiting on settles after the run: nothing may resume.
        $awaited->resolve('late');
        $this->runLater(20);

        $this->assertFalse($state->resumed);
        $this->assertFalse(EventLoop::getInstance()->isRunning());
    }

    public function testFiberThatSwallowsTheCancellationIsRejectedAndNeverResumed(): void
    {
        $source = new CancellationTokenSource();
        $first = Deferreds::mixed();
        $second = Deferreds::mixed();
        $state = new \stdClass();
        $state->swallowed = false;
        $state->resumedAfterSwallow = false;
        $state->child = null;

        $this->cancelAfter($source, 25);
        $this->runUntilCancelled(
            $source,
            static fn(): Promise => Promise::_async(static function () use ($first, $second, $state): int {
                $state->child = Promise::_async(static function () use ($first, $second, $state): int {
                    try {
                        Promise::_await($first->getPromise());
                    } catch (\Throwable) {
                        $state->swallowed = true;
                        Promise::_await($second->getPromise()); // suspends again
                        $state->resumedAfterSwallow = true;
                    }

                    return 1;
                });
                Promise::_await(Promise::delay(1500));

                return 1;
            }),
        );

        $this->assertTrue($state->swallowed);
        $this->assertTrue($state->child->isFaulted(), 'a fiber that went back to sleep is rejected');
        $this->assertInstanceOf(OperationCancelledException::class, $state->child->getError());

        $second->resolve('late');
        $this->runLater(20);

        $this->assertFalse($state->resumedAfterSwallow);
    }

    public function testRawFiberIsUnwoundAndCleanupErrorBecomesPrevious(): void
    {
        $source = new CancellationTokenSource();
        $state = new \stdClass();
        $state->unwound = false;
        $fiber = new \Fiber(static function () use ($state): void {
            try {
                \Fiber::suspend();
            } finally {
                $state->unwound = true;

                throw new \LogicException('cleanup failed');
            }
        });
        EventLoop::getInstance()->scheduleFiber($fiber);

        $this->cancelAfter($source, 20);
        $e = $this->runUntilCancelled($source, self::longRoot());

        $this->assertTrue($state->unwound);
        $this->assertTrue($fiber->isTerminated());
        $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        $this->assertSame('cleanup failed', $e->getPrevious()->getMessage());
        $this->assertFalse(EventLoop::getInstance()->isRunning());
    }

    public function testLoopIsReusableAfterACancelledRun(): void
    {
        $source = new CancellationTokenSource();
        $this->cancelAfter($source, 20);
        $this->runUntilCancelled($source, self::longRoot());

        $this->assertSame(11, Promise::run(static fn(): int => 11));
        $this->runLater(15);

        $fresh = new CancellationTokenSource();
        $this->assertSame('ok', Promise::run(
            static fn(): Promise => Promise::_async(static function (): string {
                Promise::_await(Promise::delay(10));

                return 'ok';
            }),
            $fresh->getToken(),
        ));
    }

    public function testRunThatIsNotCancelledLeavesTimersAndSuspendedFibersAlone(): void
    {
        $source = new CancellationTokenSource(); // never cancelled
        $loop = EventLoop::getInstance();
        $awaited = Deferreds::mixed();
        $state = new \stdClass();
        $state->timerFired = false;
        $state->resumed = false;

        $loop->delay(50, static function () use ($state): void {
            $state->timerFired = true;
        });

        $result = Promise::run(
            static fn(): Promise => Promise::_async(static function () use ($awaited, $state): string {
                Promise::_async(static function () use ($awaited, $state): void {
                    Promise::_await($awaited->getPromise());
                    $state->resumed = true;
                });
                Promise::_await(Promise::delay(5));

                return 'done';
            }),
            $source->getToken(),
        );

        $this->assertSame('done', $result);
        $this->assertFalse($state->timerFired, 'the run ended before the timer was due');

        $awaited->resolve('value');
        $this->runLater(80);

        $this->assertTrue($state->timerFired, 'a normal run does not cancel timers');
        $this->assertTrue($state->resumed, 'a normal run does not abandon suspended fibers');
    }

    public function testNestedRunThrowsLogicException(): void
    {
        $this->expectException(\LogicException::class);

        Promise::run(static fn(): int => Promise::run(static fn(): int => 1));
    }
}
