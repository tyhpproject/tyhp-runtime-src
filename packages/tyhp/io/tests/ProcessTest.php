<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\CancellationTokenSource;
use Tyhp\EventLoop;
use Tyhp\Exceptions\OperationCancelledException;
use Tyhp\Exceptions\TimeoutException;
use Tyhp\Io\Exceptions\ProcessFailedException;
use Tyhp\Io\Process\Process;
use Tyhp\Promise;

final class ProcessTest extends TestCase
{
    protected function tearDown(): void
    {
        EventLoop::resetInstance();
    }

    public function testRunCollectsOutputAndExitCode(): void
    {
        $result = $this->await(Process::runAsync([
            \PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(3);',
        ]));

        $this->assertSame(3, $result['exitCode']);
        $this->assertSame(0, $result['signal']);
        $this->assertSame('out', $result['stdout']);
        $this->assertSame('err', $result['stderr']);
    }

    public function testArgumentsAreNotInterpretedByAShell(): void
    {
        $result = $this->await(Process::runAsync([\PHP_BINARY, '-r', 'echo $argv[1];', '$HOME; echo nope']));

        $this->assertSame('$HOME; echo nope', $result['stdout']);
    }

    public function testInputCwdAndEnvArePassed(): void
    {
        $dir = \realpath(\sys_get_temp_dir());
        $result = $this->await(Process::runAsync(
            [\PHP_BINARY, '-r', 'echo strtoupper(stream_get_contents(STDIN)), "|", getcwd(), "|", getenv("TYHP_IO_TEST");'],
            ['cwd' => $dir, 'env' => ['TYHP_IO_TEST' => 'yes'], 'input' => 'abc', 'timeoutMs' => 0]
        ));

        $this->assertSame('ABC|' . $dir . '|yes', $result['stdout']);
    }

    public function testLargeOutputDoesNotDeadlock(): void
    {
        $result = $this->await(Process::runAsync(
            [\PHP_BINARY, '-r', 'echo stream_get_contents(STDIN); fwrite(STDERR, str_repeat("e", 300000));'],
            ['cwd' => null, 'env' => null, 'input' => \str_repeat('x', 500000), 'timeoutMs' => 0]
        ));

        $this->assertSame(500000, \strlen($result['stdout']));
        $this->assertSame(300000, \strlen($result['stderr']));
    }

    public function testCommandsRunConcurrently(): void
    {
        $started = \microtime(true);
        $results = $this->await(Promise::all([
            Process::runAsync([\PHP_BINARY, '-r', 'usleep(400000); echo 1;']),
            Process::runAsync([\PHP_BINARY, '-r', 'usleep(400000); echo 2;']),
            Process::runAsync([\PHP_BINARY, '-r', 'usleep(400000); echo 3;']),
        ]));

        $this->assertSame(['1', '2', '3'], \array_column($results, 'stdout'));
        $this->assertLessThan(1.1, \microtime(true) - $started);
    }

    public function testTimeoutKillsTheCommand(): void
    {
        $started = \microtime(true);
        try {
            $this->await(Process::runAsync(
                [\PHP_BINARY, '-r', 'sleep(10);'],
                ['cwd' => null, 'env' => null, 'input' => '', 'timeoutMs' => 200]
            ));
            $this->fail('Expected TimeoutException.');
        } catch (TimeoutException) {
            $this->assertLessThan(2.0, \microtime(true) - $started);
        }
    }

    public function testCancellationKillsTheCommand(): void
    {
        $source = new CancellationTokenSource();
        $promise = Process::runAsync([\PHP_BINARY, '-r', 'sleep(10);'], null, $source->getToken());
        EventLoop::getInstance()->delay(100, static function () use ($source): void {
            $source->cancel();
        });

        $started = \microtime(true);
        try {
            $this->await($promise);
            $this->fail('Expected OperationCancelledException.');
        } catch (OperationCancelledException) {
            $this->assertLessThan(2.0, \microtime(true) - $started);
        }
    }

    public function testMustRunThrowsForNonZeroExit(): void
    {
        try {
            $this->await(Process::mustRunAsync([\PHP_BINARY, '-r', 'fwrite(STDERR, "bad"); exit(2);']));
            $this->fail('Expected ProcessFailedException.');
        } catch (ProcessFailedException $e) {
            $this->assertSame(2, $e->getResult()['exitCode']);
            $this->assertSame('bad', $e->getResult()['stderr']);
        }
    }

    public function testEmptyCommandIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->await(Process::runAsync([]));
    }

    private function await(Promise $promise): mixed
    {
        return EventLoop::getInstance()->runUntilSettled($promise);
    }
}
