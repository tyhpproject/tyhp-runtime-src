<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\EventLoop;
use Tyhp\Io\Exceptions\IoException;
use Tyhp\Io\Socket\Socket;
use Tyhp\Promise;

final class SocketTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            \proc_terminate($this->server);
            \proc_close($this->server);
        }
        EventLoop::resetInstance();
    }

    public function testWriteAndReadLines(): void
    {
        $port = $this->startEchoServer();
        $connection = $this->await(Socket::connectAsync('tcp://127.0.0.1:' . $port));

        $this->await($connection->writeAsync("hello\n"));
        $this->await($connection->writeAsync("world\n"));
        $this->assertSame('echo:hello', $this->await($connection->readLineAsync()));
        $this->assertSame('echo:world', $this->await($connection->readLineAsync()));

        $this->await($connection->writeAsync("abc\n"));
        $this->assertSame('echo:', $this->await($connection->readExactlyAsync(5)));
        $this->assertSame("abc\n", $this->await($connection->readExactlyAsync(4)));

        $this->await($connection->writeAsync("QUIT\n"));
        $this->assertNull($this->await($connection->readLineAsync()));
        $connection->close();
        $this->assertTrue($connection->isClosed());
    }

    public function testConnectionRefusedThrows(): void
    {
        $this->expectException(IoException::class);
        $this->await(Socket::connectAsync('tcp://127.0.0.1:' . HttpTestServer::freePort(), 2000));
    }

    public function testUnixSocketAddressConnects(): void
    {
        $path = \sys_get_temp_dir() . '/tyhp-io-' . \bin2hex(\random_bytes(6)) . '.sock';
        $server = \stream_socket_server('unix://' . $path, $errno, $errstr);
        if (!\is_resource($server)) {
            $this->markTestSkipped('Unix domain sockets are unavailable: ' . $errstr);
        }

        try {
            $connection = $this->await(Socket::connectAsync('unix://' . $path, 2000));
            $peer = \stream_socket_accept($server, 2);
            $this->assertIsResource($peer);
            \fwrite($peer, "hello\n");
            $this->assertSame('hello', $this->await($connection->readLineAsync()));
            $connection->close();
            \fclose($peer);
        } finally {
            \fclose($server);
            if (\is_file($path)) {
                \unlink($path);
            }
        }
    }

    public function testUnsupportedSchemeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->await(Socket::connectAsync('udp://127.0.0.1:9'));
    }

    private function startEchoServer(): int
    {
        $process = \proc_open(
            [\PHP_BINARY, __DIR__ . '/echo-server.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the echo server.');
        }
        $this->server = $process;
        $port = (int) \trim((string) \fgets($pipes[1]));
        if ($port <= 0) {
            throw new \RuntimeException('The echo server did not report a port.');
        }
        return $port;
    }

    private function await(Promise $promise): mixed
    {
        return EventLoop::getInstance()->runUntilSettled($promise);
    }
}
