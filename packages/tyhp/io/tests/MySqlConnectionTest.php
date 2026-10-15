<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\CancellationTokenSource;
use Tyhp\EventLoop;
use Tyhp\Exceptions\OperationCancelledException;
use Tyhp\Io\Db\MySqlConnection;
use Tyhp\Io\Exceptions\DbException;
use Tyhp\Promise;

/**
 * Set TYHP_IO_TEST_MYSQL=host:port:user:password:database for a disposable
 * MySQL or MariaDB database.
 */
final class MySqlConnectionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var list<MySqlConnection> */
    private array $connections = [];

    protected function setUp(): void
    {
        if (!\extension_loaded('mysqli')) {
            $this->markTestSkipped('ext-mysqli is not loaded.');
        }
        $target = (string) \getenv('TYHP_IO_TEST_MYSQL');
        if ($target === '') {
            $this->markTestSkipped('Set TYHP_IO_TEST_MYSQL=host:port:user:password:database to run MySQL tests.');
        }
        [$host, $port, $user, $password, $database] = \explode(':', $target, 5) + ['', '3306', 'root', '', ''];
        $this->options = [
            'host' => $host,
            'port' => (int) $port,
            'user' => $user,
            'password' => $password,
            'database' => $database,
            'charset' => 'utf8mb4',
            'socket' => null,
            'connectTimeoutSeconds' => 5,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        EventLoop::resetInstance();
    }

    public function testQueryReturnsNativeTypes(): void
    {
        $db = $this->connect();
        $result = $this->await($db->queryAsync('SELECT ? AS i, ? AS f, ? AS s, NULL AS n', [42, 1.5, "it's"]));

        $row = $result->first();
        $this->assertSame(42, $row['i']);
        $this->assertEqualsWithDelta(1.5, $row['f'], 0.0001);
        $this->assertSame("it's", $row['s']);
        $this->assertNull($row['n']);
    }

    public function testInsertReportsAffectedRowsAndLastInsertId(): void
    {
        $db = $this->connect();
        $this->await($db->queryAsync('CREATE TEMPORARY TABLE items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20))'));
        $insert = $this->await($db->queryAsync('INSERT INTO items (name) VALUES (?), (?)', ['a', 'b']));

        $this->assertSame(2, $insert->affectedRows);
        $this->assertSame('1', $insert->lastInsertId);
    }

    public function testFailedTransactionRollsBack(): void
    {
        $db = $this->connect();
        $this->await($db->queryAsync('CREATE TEMPORARY TABLE t (v INT PRIMARY KEY) ENGINE=InnoDB'));
        try {
            $this->await($db->transactionAsync([
                ['sql' => 'INSERT INTO t VALUES (?)', 'params' => [1]],
                ['sql' => 'INSERT INTO t VALUES (?)', 'params' => [1]],
            ]));
            $this->fail('Expected DbException.');
        } catch (DbException $e) {
            $this->assertSame('23000', $e->getSqlState());
        }

        $this->assertSame(0, $this->await($db->queryAsync('SELECT COUNT(*) FROM t'))->scalar());
    }

    public function testQueriesOnSeparateConnectionsRunConcurrently(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $started = \microtime(true);
        $this->await(Promise::all([
            $a->queryAsync('SELECT SLEEP(0.5)'),
            $b->queryAsync('SELECT SLEEP(0.5)'),
        ]));

        $this->assertLessThan(0.9, \microtime(true) - $started);
    }

    public function testCancellationKillsTheQuery(): void
    {
        $db = $this->connect();
        $source = new CancellationTokenSource();
        $promise = $db->queryAsync('SELECT SLEEP(5)', [], $source->getToken());
        EventLoop::getInstance()->delay(200, static function () use ($source): void {
            $source->cancel();
        });

        $started = \microtime(true);
        try {
            $this->await($promise);
            $this->fail('Expected OperationCancelledException.');
        } catch (OperationCancelledException) {
            $this->assertLessThan(2.0, \microtime(true) - $started);
        }
        $this->assertSame(1, $this->await($db->queryAsync('SELECT 1'))->scalar());
    }

    private function connect(): MySqlConnection
    {
        $connection = $this->await(MySqlConnection::connectAsync($this->options));
        $this->connections[] = $connection;
        return $connection;
    }

    private function await(Promise $promise): mixed
    {
        return EventLoop::getInstance()->runUntilSettled($promise);
    }
}
