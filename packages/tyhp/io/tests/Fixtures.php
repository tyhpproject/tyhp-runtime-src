<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use Tyhp\Io\OffloadJob;

final class HttpTestServer
{
    private static ?HttpTestServer $instance = null;

    /** @var resource */
    private $process;

    private function __construct(public readonly string $baseUrl, $process)
    {
        $this->process = $process;
    }

    public static function get(): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $port = self::freePort();
        $env = \getenv();
        $env['PHP_CLI_SERVER_WORKERS'] = '4';
        $process = \proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/http-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            __DIR__,
            $env
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the PHP built-in server.');
        }

        $deadline = \microtime(true) + 10;
        while (\microtime(true) < $deadline) {
            $socket = @\stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.2);
            if (\is_resource($socket)) {
                \fclose($socket);
                self::$instance = new self('http://127.0.0.1:' . $port, $process);
                \register_shutdown_function(static function () use ($process): void {
                    self::stop($process);
                });
                return self::$instance;
            }
            \usleep(50_000);
        }

        self::stop($process);
        throw new \RuntimeException('The PHP built-in server did not start.');
    }

    /**
     * The built-in server's master does not stop its PHP_CLI_SERVER_WORKERS children
     * when it is terminated, so they are signalled directly.
     *
     * @param resource $process
     */
    private static function stop($process): void
    {
        $status = \proc_get_status($process);
        if ($status['running'] && \function_exists('posix_kill')) {
            $children = (string) \shell_exec('pgrep -P ' . (int) $status['pid']);
            foreach (\preg_split('/\s+/', \trim($children), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $pid) {
                \posix_kill((int) $pid, 15);
            }
        }
        \proc_terminate($process);
        \proc_close($process);
    }

    public static function freePort(): int
    {
        $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!\is_resource($server)) {
            throw new \RuntimeException('Could not reserve a port: ' . $errstr);
        }
        $name = (string) \stream_socket_get_name($server, false);
        \fclose($server);
        return (int) \substr($name, (int) \strrpos($name, ':') + 1);
    }
}

final class PidJob implements OffloadJob
{
    public function run(): int
    {
        return \getmypid();
    }
}

final class SquareJob implements OffloadJob
{
    public function __construct(private readonly int $value)
    {
    }

    public function run(): int
    {
        return $this->value * $this->value;
    }
}

final class ThrowingJob implements OffloadJob
{
    public function run(): int
    {
        throw new \DomainException('job failed');
    }
}
