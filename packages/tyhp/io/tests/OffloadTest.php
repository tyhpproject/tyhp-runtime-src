<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\EventLoop;
use Tyhp\Exceptions\ParallelException;
use Tyhp\Io\Db\PdoConnection;
use Tyhp\Io\Dns\Dns;
use Tyhp\Io\Exceptions\DbException;
use Tyhp\Io\Offload;
use Tyhp\Parallel;
use Tyhp\Promise;

final class OffloadTest extends TestCase
{
    private string $dbPath = '';

    protected function setUp(): void
    {
        \putenv('TYHP_PARALLEL_SOCKET');
    }

    protected function tearDown(): void
    {
        Parallel::shutdown();
        EventLoop::resetInstance();
        if ($this->dbPath !== '' && \is_file($this->dbPath)) {
            \unlink($this->dbPath);
        }
    }

    public function testRunUsesAWorkerProcessWhenParallelIsAvailable(): void
    {
        $this->assertTrue(Offload::usesWorkers());
        $pid = $this->await(Offload::runAsync(new PidJob()));

        $this->assertNotSame(\getmypid(), $pid);
    }

    public function testRunReturnsTheJobResult(): void
    {
        $results = $this->await(Promise::all([
            Offload::runAsync(new SquareJob(3)),
            Offload::runAsync(new SquareJob(4)),
        ]));

        $this->assertSame([9, 16], $results);
    }

    public function testJobExceptionsRejectWithParallelException(): void
    {
        try {
            $this->await(Offload::runAsync(new ThrowingJob()));
            $this->fail('Expected ParallelException.');
        } catch (ParallelException $e) {
            $this->assertStringContainsString('job failed', $e->getMessage());
        }
    }

    public function testDnsResolvesLocalhostAndIpLiterals(): void
    {
        $this->assertSame(['127.0.0.1'], $this->await(Dns::resolveAsync('127.0.0.1')));
        $this->assertContains('127.0.0.1', $this->await(Dns::resolveAsync('localhost')));
    }

    public function testPdoQueriesAndTransactionsThroughWorkers(): void
    {
        $this->runPdoScenario();
    }

    public function testWithoutWorkersJobsRunInline(): void
    {
        $process = \proc_open(
            [\PHP_BINARY, '-d', 'disable_functions=pcntl_fork', __DIR__ . '/offload-inline.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        $exitCode = \proc_close($process);

        $this->assertSame(0, $exitCode, $stdout . $stderr);
        $this->assertSame(
            ['usesWorkers' => false, 'sameProcess' => true, 'count' => 2],
            \json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR)
        );
    }

    private function runPdoScenario(): void
    {
        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not loaded.');
        }
        $this->dbPath = \sys_get_temp_dir() . '/tyhp-io-' . \bin2hex(\random_bytes(4)) . '.sqlite';
        $db = new PdoConnection([
            'dsn' => 'sqlite:' . $this->dbPath,
            'username' => null,
            'password' => null,
            'attributes' => [],
        ]);

        $this->await($db->queryAsync('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT, price REAL, active INTEGER)'));
        $insert = $this->await($db->queryAsync('INSERT INTO items (name, price, active) VALUES (?, ?, ?)', ['apple', 1.5, true]));
        $this->assertSame(1, $insert->affectedRows);
        $this->assertSame('1', $insert->lastInsertId);

        $results = $this->await($db->transactionAsync([
            ['sql' => 'INSERT INTO items (name, price, active) VALUES (?, ?, ?)', 'params' => ['pear', 2.0, false]],
            ['sql' => 'SELECT COUNT(*) AS n FROM items', 'params' => []],
        ]));
        $this->assertCount(2, $results);
        $this->assertSame(2, $results[1]->scalar());

        $rows = $this->await($db->queryAsync('SELECT name, price FROM items WHERE name = ?', ['pear']));
        $this->assertSame([['name' => 'pear', 'price' => 2.0]], $rows->rows);
        $this->assertSame('pear', $rows->first()['name']);

        try {
            $this->await($db->transactionAsync([
                ['sql' => 'INSERT INTO items (name) VALUES (?)', 'params' => ['plum']],
                ['sql' => 'INSERT INTO missing_table VALUES (1)', 'params' => []],
            ]));
            $this->fail('Expected DbException.');
        } catch (DbException $e) {
            $this->assertStringContainsString('missing_table', $e->getMessage());
            $this->assertSame('HY000', $e->getSqlState());
        }
        $count = $this->await($db->queryAsync('SELECT COUNT(*) FROM items'));
        $this->assertSame(2, $count->scalar());

        $db->close();
    }

    private function await(Promise $promise): mixed
    {
        return EventLoop::getInstance()->runUntilSettled($promise);
    }
}
