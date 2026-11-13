<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Context;

final class ContextConnectionTest extends TestCase
{
    private \Illuminate\Database\Capsule\Manager $capsule;

    protected function setUp(): void
    {
        $this->capsule = new \Illuminate\Database\Capsule\Manager();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'tenant');
        $this->capsule->setAsGlobal();
    }

    public function testEmptyContextResolvesTheDefaultSqliteConnection(): void
    {
        $connection = (new EmptyContext())->connection();

        self::assertSame($this->capsule->getConnection(), $connection);
        self::assertSame('default', $connection->getName());
        self::assertSame('sqlite', $connection->getDriverName());
        self::assertSame(1, (int) $connection->select('select 1 as ok')[0]->ok);
    }

    public function testNamedConnectionResolvesThatLaravelConnection(): void
    {
        $connection = (new NamedContext())->connection();

        self::assertSame($this->capsule->getConnection('tenant'), $connection);
        self::assertSame('tenant', $connection->getName());
        self::assertSame('sqlite', $connection->getDriverName());
        self::assertNotSame((new EmptyContext())->connection(), $connection);
    }

    public function testNamedAndDefaultConnectionsAreSeparateDatabases(): void
    {
        $default = (new EmptyContext())->connection();
        $tenant = (new NamedContext())->connection();

        $tenant->statement('create table only_in_tenant (id integer primary key)');

        self::assertSame(
            1,
            (int) $tenant->selectOne("select count(*) as n from sqlite_master where name = 'only_in_tenant'")->n,
        );
        self::assertSame(
            0,
            (int) $default->selectOne("select count(*) as n from sqlite_master where name = 'only_in_tenant'")->n,
        );
    }

    public function testUnknownConnectionNameFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new MissingContext())->connection();
    }
}

final class LaravelManagerConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        \Illuminate\Container\Container::setInstance(null);
    }

    public function testConnectionUsesTheApplicationDatabaseManagerWhenCapsuleIsNotGlobal(): void
    {
        $reflection = new \ReflectionClass(\Illuminate\Database\Capsule\Manager::class);
        $reflection->setStaticPropertyValue('instance', null);

        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $manager = $capsule->getDatabaseManager();
        $app = new \Illuminate\Container\Container();
        $app->instance('db', $manager);
        \Illuminate\Container\Container::setInstance($app);

        $connection = (new EmptyContext())->connection();

        self::assertNull((new \ReflectionClass(\Illuminate\Database\Capsule\Manager::class))->getStaticPropertyValue('instance'));
        self::assertSame($manager->connection(), $connection);
        self::assertSame('sqlite', $connection->getDriverName());
        self::assertSame(1, (int) $connection->select('select 1 as ok')[0]->ok);
    }
}

final class EmptyContext extends Context
{
}

final class NamedContext extends Context
{
    protected ?string $connection = 'tenant';
}

final class MissingContext extends Context
{
    protected ?string $connection = 'does-not-exist';
}
