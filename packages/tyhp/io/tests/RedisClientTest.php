<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\EventLoop;
use Tyhp\Io\Exceptions\RedisException;
use Tyhp\Io\Redis\RedisClient;
use Tyhp\Promise;

/**
 * Set TYHP_IO_TEST_REDIS=host:port to run against a disposable Redis server.
 * The tests use database 15 and flush it.
 */
final class RedisClientTest extends TestCase
{
    private ?RedisClient $redis = null;

    protected function setUp(): void
    {
        $target = (string) \getenv('TYHP_IO_TEST_REDIS');
        if ($target === '') {
            $this->markTestSkipped('Set TYHP_IO_TEST_REDIS=host:port to run Redis tests.');
        }
        [$host, $port] = \explode(':', $target, 2) + [1 => '6379'];
        $this->redis = $this->await(RedisClient::connectAsync([
            'host' => $host,
            'port' => (int) $port,
            'tls' => false,
            'username' => null,
            'password' => null,
            'database' => 15,
            'connectTimeoutMs' => 5000,
        ]));
        $this->await($this->redis->commandAsync('FLUSHDB'));
    }

    protected function tearDown(): void
    {
        $this->redis?->close();
        EventLoop::resetInstance();
    }

    public function testStringCommands(): void
    {
        $redis = $this->redis;
        $this->assertSame('PONG', $this->await($redis->pingAsync()));
        $this->assertNull($this->await($redis->getAsync('missing')));
        $this->await($redis->setAsync('k', 'v'));
        $this->assertSame('v', $this->await($redis->getAsync('k')));
        $this->assertTrue($this->await($redis->existsAsync('k')));
        $this->assertSame(5, $this->await($redis->incrAsync('n', 5)));
        $this->assertSame(6, $this->await($redis->incrAsync('n')));
        $this->assertSame(2, $this->await($redis->delAsync('k', 'n')));
    }

    public function testTtlExpiresKeys(): void
    {
        $this->await($this->redis->setAsync('short', 'x', 100));
        $this->await(Promise::delay(250));
        $this->assertNull($this->await($this->redis->getAsync('short')));
    }

    public function testHashesAndLists(): void
    {
        $redis = $this->redis;
        $this->assertSame(2, $this->await($redis->hSetAsync('h', ['a' => '1', 'b' => '2'])));
        $this->assertSame('1', $this->await($redis->hGetAsync('h', 'a')));
        $this->assertSame(['a' => '1', 'b' => '2'], $this->await($redis->hGetAllAsync('h')));

        $this->await($redis->rPushAsync('l', 'b', 'c'));
        $this->await($redis->lPushAsync('l', 'a'));
        $this->assertSame(['a', 'b', 'c'], $this->await($redis->lRangeAsync('l')));
    }

    public function testPipelinedCommandsResolveInOrder(): void
    {
        $promises = [];
        for ($i = 0; $i < 200; $i++) {
            $promises[] = $this->redis->commandAsync('ECHO', 'v' . $i);
        }
        $results = $this->await(Promise::all($promises));

        $this->assertSame(\array_map(static fn (int $i): string => 'v' . $i, \range(0, 199)), $results);
    }

    public function testLargeValuesRoundTrip(): void
    {
        $value = \random_bytes(2_000_000);
        $this->await($this->redis->setAsync('big', $value));
        $this->assertSame($value, $this->await($this->redis->getAsync('big')));
    }

    public function testErrorRepliesRejectOnlyThatCommand(): void
    {
        $this->await($this->redis->setAsync('s', 'text'));
        $bad = $this->redis->commandAsync('INCR', 's');
        $good = $this->redis->getAsync('s');

        try {
            $this->await($bad);
            $this->fail('Expected RedisException.');
        } catch (RedisException $e) {
            $this->assertStringContainsString('not an integer', $e->getMessage());
        }
        $this->assertSame('text', $this->await($good));
    }

    private function await(Promise $promise): mixed
    {
        return EventLoop::getInstance()->runUntilSettled($promise);
    }
}
