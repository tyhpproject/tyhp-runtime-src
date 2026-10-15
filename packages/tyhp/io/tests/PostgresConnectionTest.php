<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\CancellationTokenSource;
use Tyhp\EventLoop;
use Tyhp\Exceptions\OperationCancelledException;
use Tyhp\Io\Db\PostgresConnection;
use Tyhp\Io\Exceptions\DbException;
use Tyhp\Promise;

/**
 * Set TYHP_IO_TEST_PG to a libpq connection string for a disposable database.
 */
final class PostgresConnectionTest extends TestCase
{
    private string $dsn = '';

    /** @var list<PostgresConnection> */
    private array $connections = [];

    protected function setUp(): void
    {
        if (!\extension_loaded('pgsql')) {
            $this->markTestSkipped('ext-pgsql is not loaded.');
        }
        $this->dsn = (string) \getenv('TYHP_IO_TEST_PG');
        if ($this->dsn === '') {
            $this->markTestSkipped('Set TYHP_IO_TEST_PG to a connection string to run PostgreSQL tests.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        EventLoop::resetInstance();
    }

    public function testQueryConvertsColumnTypes(): void
    {
        $db = $this->connect();
        $result = $this->await($db->queryAsync(
            'SELECT $1::int AS i, $2::float8 AS f, $3::bool AS b, $4::text AS t, NULL::int AS n',
            [42, 1.5, true, 'hi']
        ));

        $this->assertSame([['i' => 42, 'f' => 1.5, 'b' => true, 't' => 'hi', 'n' => null]], $result->rows);
    }

    public function testInsertReportsAffectedRows(): void
    {
        $db = $this->connect();
        $this->await($db->queryAsync('CREATE TEMP TABLE items (id serial PRIMARY KEY, name text NOT NULL)'));
        $insert = $this->await($db->queryAsync('INSERT INTO items (name) VALUES ($1), ($2)', ['a', 'b']));

        $this->assertSame(2, $insert->affectedRows);
        $this->assertSame(2, $this->await($db->queryAsync('SELECT COUNT(*)::int FROM items'))->scalar());
    }

    public function testFailedTransactionRollsBack(): void
    {
        $db = $this->connect();
        $this->await($db->queryAsync('CREATE TEMP TABLE t (v int PRIMARY KEY)'));
        try {
            $this->await($db->transactionAsync([
                ['sql' => 'INSERT INTO t VALUES ($1)', 'params' => [1]],
                ['sql' => 'INSERT INTO t VALUES ($1)', 'params' => [1]],
            ]));
            $this->fail('Expected DbException.');
        } catch (DbException $e) {
            $this->assertSame('23505', $e->getSqlState());
        }

        $this->assertSame(0, $this->await($db->queryAsync('SELECT COUNT(*)::int FROM t'))->scalar());
    }

    public function testQueriesOnSeparateConnectionsRunConcurrently(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $started = \microtime(true);
        $this->await(Promise::all([
            $a->queryAsync('SELECT pg_sleep(0.5)'),
            $b->queryAsync('SELECT pg_sleep(0.5)'),
        ]));

        $this->assertLessThan(0.9, \microtime(true) - $started);
    }

    public function testCancellationStopsTheQuery(): void
    {
        $db = $this->connect();
        $source = new CancellationTokenSource();
        $promise = $db->queryAsync('SELECT pg_sleep(5)', [], $source->getToken());
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
        $this->assertSame(1, $this->await($db->queryAsync('SELECT 1::int'))->scalar());
    }

    public function testSyntaxErrorThrowsDbException(): void
    {
        $db = $this->connect();
        try {
            $this->await($db->queryAsync('SELEC 1'));
            $this->fail('Expected DbException.');
        } catch (DbException $e) {
            $this->assertSame('42601', $e->getSqlState());
        }
    }

    private function connect(): PostgresConnection
    {
        $connection = $this->await(PostgresConnection::connectAsync($this->dsn));
        $this->connections[] = $connection;
        return $connection;
    }

    private function await(Promise $promise): mixed
    {
        return EventLoop::getInstance()->runUntilSettled($promise);
    }
}
