<?php

declare(strict_types=1);

namespace Tyhp\Tests\Parallel;

use PHPUnit\Framework\TestCase;
use Tyhp\EventLoop;
use Tyhp\Exceptions\ParallelException;
use Tyhp\Exceptions\ParallelUnavailableException;
use Tyhp\Parallel;

final class ParallelServiceTest extends TestCase
{
    private mixed $daemon = null;
    private string $socket = '';
    private string $daemonStdout = '';
    private string $daemonStderr = '';

    protected function tearDown(): void
    {
        Parallel::shutdown();
        \putenv('TYHP_PARALLEL_SOCKET');
        EventLoop::resetInstance();
        $this->stopDaemon();
    }

    public function testMissingServiceFailsInTheClient(): void
    {
        \putenv('TYHP_PARALLEL_SOCKET=/tmp/tyhp-parallel-missing.sock');

        $this->expectException(ParallelUnavailableException::class);
        Parallel::runAsync(new DoubleTask(1));
    }

    public function testOneConnectionRunsSeveralTasks(): void
    {
        $socket = $this->startDaemon(2);
        \putenv('TYHP_PARALLEL_SOCKET=' . $socket);

        $result = EventLoop::getInstance()->runUntilSettled(Parallel::invokeAsync([
            new DoubleTask(10),
            new DoubleTask(20),
        ]), 5000);

        $this->assertSame([20, 40], $result);
    }

    public function testTaskExceptionComesBackFromTheService(): void
    {
        $socket = $this->startDaemon(1);
        \putenv('TYHP_PARALLEL_SOCKET=' . $socket);

        try {
            EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new BoomTask()), 5000);
            $this->fail('The failing task should reject the promise.');
        } catch (ParallelException $e) {
            $this->assertSame(\RuntimeException::class, $e->getRemoteClass());
            $this->assertStringContainsString('boom', $e->getMessage());
        }
    }

    public function testClientShutdownLeavesTheServiceRunning(): void
    {
        $socket = $this->startDaemon(1);
        \putenv('TYHP_PARALLEL_SOCKET=' . $socket);

        $first = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new DoubleTask(3)), 5000);
        $this->assertSame(6, $first);

        Parallel::shutdown();
        EventLoop::resetInstance();

        $second = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new DoubleTask(4)), 5000);
        $this->assertSame(8, $second);
    }

    public function testSeveralProcessesShareTheService(): void
    {
        $socket = $this->startDaemon(2);
        $first = $this->openClient($socket, 'sleep', '400000');
        $second = $this->openClient($socket, 'sleep', '400000');

        $started = \hrtime(true);
        $pidA = $this->finishClient($first);
        $pidB = $this->finishClient($second);
        $elapsedMs = (\hrtime(true) - $started) / 1_000_000;

        $this->assertNotSame($pidA, $pidB);
        $this->assertLessThan(
            750,
            $elapsedMs,
            'The two clients did not overlap on the service. Elapsed ' . (string) $elapsedMs . 'ms.'
        );
    }

    public function testExitInATaskKeepsTheServiceRunning(): void
    {
        $socket = $this->startDaemon(1);
        \putenv('TYHP_PARALLEL_SOCKET=' . $socket);

        try {
            EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new ExitTask()), 5000);
            $this->fail('A task that exits should reject the promise.');
        } catch (ParallelException $e) {
            $this->assertNull($e->getRemoteError());
        }

        $this->assertFileExists($socket);
        $result = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new DoubleTask(4)), 5000);
        $this->assertSame(8, $result);
    }

    public function testCancelledClientTaskFreesItsWorker(): void
    {
        $socket = $this->startDaemon(1);
        $started = \hrtime(true);
        $output = $this->finishClient($this->openClient($socket, 'cancel', '5000000'));
        $elapsedMs = (\hrtime(true) - $started) / 1_000_000;

        $this->assertSame('cancelled 8', $output);
        $this->assertLessThan(2500, $elapsedMs);
    }

    public function testDisconnectedClientFreesItsWorker(): void
    {
        $socket = $this->startDaemon(1);
        $this->assertSame('abandoned', $this->finishClient($this->openClient($socket, 'abandon', '5000000')));

        \putenv('TYHP_PARALLEL_SOCKET=' . $socket);
        $started = \hrtime(true);
        $result = EventLoop::getInstance()->runUntilSettled(Parallel::runAsync(new DoubleTask(4)), 4000);
        $elapsedMs = (\hrtime(true) - $started) / 1_000_000;

        $this->assertSame(8, $result);
        $this->assertLessThan(2000, $elapsedMs);
    }

    public function testCliUsage(): void
    {
        $bin = \dirname(__DIR__) . '/bin/tyhp-parallel';
        $stderr = \tempnam(\sys_get_temp_dir(), 'tyhp-parallel-usage');
        $this->assertIsString($stderr);
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, $bin],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', $stderr, 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);
        $exit = \proc_close($process);
        $text = \file_get_contents($stderr);
        \unlink($stderr);

        $this->assertSame(1, $exit);
        $this->assertIsString($text);
        $this->assertStringContainsString('TYHP_PARALLEL_SOCKET', $text);
    }

    private function startDaemon(int $workers): string
    {
        $this->socket = \sys_get_temp_dir()
            . '/tyhp-p-' . (string) \getmypid()
            . '-' . \bin2hex(\random_bytes(4))
            . '.sock';
        $this->daemonStdout = $this->socket . '.out';
        $this->daemonStderr = $this->socket . '.err';
        $pipes = [];
        $process = \proc_open(
            [
                \PHP_BINARY,
                __DIR__ . '/worker-service.php',
                $this->socket,
                (string) $workers,
            ],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->daemonStdout, 'w'],
                2 => ['file', $this->daemonStderr, 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);
        $this->daemon = $process;

        $deadline = \microtime(true) + 5;
        while (\microtime(true) < $deadline) {
            if (\file_exists($this->socket)) {
                $errorCode = 0;
                $errorMessage = '';
                $probe = @\stream_socket_client(
                    'unix://' . $this->socket,
                    $errorCode,
                    $errorMessage,
                    0.1
                );
                if (\is_resource($probe)) {
                    \fclose($probe);
                    return $this->socket;
                }
            }
            \usleep(20000);
        }

        $detail = '';
        if (\is_file($this->daemonStderr)) {
            $logged = \file_get_contents($this->daemonStderr);
            if (\is_string($logged)) {
                $detail = $logged;
            }
        }
        $this->fail('Worker service did not start. ' . $detail);
    }

    /**
     * @return array{process: resource, stdout: string, stderr: string}
     */
    private function openClient(string $socket, string $kind, string $payload): array
    {
        $stdout = $socket . '.' . $kind . '.' . \bin2hex(\random_bytes(3)) . '.out';
        $stderr = $stdout . '.err';
        $pipes = [];
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/worker-client.php', $socket, $kind, $payload],
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ],
            $pipes
        );
        $this->assertIsResource($process);
        return [
            'process' => $process,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    /**
     * @param array{process: resource, stdout: string, stderr: string} $client
     */
    private function finishClient(array $client): string
    {
        $exit = \proc_close($client['process']);
        $stdout = \file_get_contents($client['stdout']);
        $stderr = \file_get_contents($client['stderr']);
        \unlink($client['stdout']);
        \unlink($client['stderr']);
        $this->assertSame(0, $exit, \is_string($stderr) ? $stderr : '');
        $this->assertIsString($stdout);
        return \trim($stdout);
    }

    private function stopDaemon(): void
    {
        if (!\is_resource($this->daemon)) {
            $this->cleanupDaemonFiles();
            return;
        }
        $status = \proc_get_status($this->daemon);
        if (\is_array($status) && isset($status['pid'], $status['running'])) {
            $pid = $status['pid'];
            $running = $status['running'];
            if ($running === true && \is_int($pid)) {
                \posix_kill($pid, \SIGTERM);
            }
        }
        \proc_close($this->daemon);
        $this->daemon = null;
        $this->cleanupDaemonFiles();
    }

    private function cleanupDaemonFiles(): void
    {
        if ($this->socket !== '' && \file_exists($this->socket)) {
            \unlink($this->socket);
        }
        if ($this->daemonStdout !== '' && \is_file($this->daemonStdout)) {
            \unlink($this->daemonStdout);
        }
        if ($this->daemonStderr !== '' && \is_file($this->daemonStderr)) {
            \unlink($this->daemonStderr);
        }
    }
}
