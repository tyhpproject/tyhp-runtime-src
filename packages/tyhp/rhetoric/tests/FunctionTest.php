<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\QueryContext;
use Tyhp\Rhetoric\Fixtures\QueryScenarios;
use Tyhp\Rhetoric\Query\MethodTranslatorRegistry;
use Tyhp\Rhetoric\Query\QueryTranslationException;
use Tyhp\Rhetoric\Query\TranslationCache;

final class FunctionTest extends TestCase
{
    protected function tearDown(): void
    {
        MethodTranslatorRegistry::clear();
        TranslationCache::clear();
    }

    public function testRegisteredFunctionUsesTheGrammarSql(): void
    {
        MethodTranslatorRegistry::shared()->registerFunction('phrase', [
            'sqlite' => 'phrase({0})',
            'mysql' => 'mysql_phrase({0})',
            'pgsql' => 'pgsql_phrase({0})',
            'mariadb' => 'mariadb_phrase({0})',
        ]);

        self::assertStringContainsString('phrase(', QueryScenarios::namedFunction($this->context('sqlite'), 'phrase')->sql);
        self::assertStringContainsString('mysql_phrase(', QueryScenarios::namedFunction($this->context('mysql'), 'phrase')->sql);
        self::assertStringContainsString('pgsql_phrase(', QueryScenarios::namedFunction($this->context('pgsql'), 'phrase')->sql);
        self::assertStringContainsString('mariadb_phrase(', QueryScenarios::namedFunction($this->context('mariadb'), 'phrase')->sql);
    }

    public function testEachFunctionNameKeepsItsOwnSql(): void
    {
        MethodTranslatorRegistry::shared()->registerFunction('phrase', [
            'sqlite' => 'phrase({0})',
        ]);
        MethodTranslatorRegistry::shared()->registerFunction('other', [
            'sqlite' => 'other_fn({0})',
        ]);

        $phrase = QueryScenarios::namedFunction($this->context('sqlite'), 'phrase')->sql;
        $other = QueryScenarios::namedFunction($this->context('sqlite'), 'other')->sql;

        self::assertStringContainsString('phrase(', $phrase);
        self::assertStringNotContainsString('other_fn(', $phrase);
        self::assertStringContainsString('other_fn(', $other);
    }

    public function testArgumentSqlIsNotReadAsASlot(): void
    {
        MethodTranslatorRegistry::shared()->registerFunction('pair', [
            'sqlite' => 'pair({0}, {1})',
        ]);

        $sql = QueryScenarios::namedFunctionWithRawSlot($this->context('sqlite'), 'pair')->sql;

        self::assertStringContainsString('pair({1}, "title")', $sql);
    }

    public function testRejectedRegistrationLeavesNothingBehind(): void
    {
        try {
            MethodTranslatorRegistry::shared()->registerFunction('phrase', [
                'sqlite' => 'phrase({0})',
                'mysql' => '',
            ]);
            self::fail('The empty SQL was accepted.');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(QueryTranslationException::class);
        QueryScenarios::namedFunction($this->context('sqlite'), 'phrase');
    }

    public function testUnregisteredFunctionDoesNotTranslate(): void
    {
        $this->expectException(QueryTranslationException::class);
        QueryScenarios::namedFunction($this->context('sqlite'), 'phrase');
    }

    private function context(string $name): QueryContext
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'sqlite');
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'rhetoric',
            'username' => 'root',
            'password' => '',
            'prefix' => '',
        ], 'mysql');
        $capsule->addConnection([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'rhetoric',
            'username' => 'root',
            'password' => '',
            'prefix' => '',
        ], 'pgsql');
        $capsule->addConnection([
            'driver' => 'mariadb',
            'host' => '127.0.0.1',
            'database' => 'rhetoric',
            'username' => 'root',
            'password' => '',
            'prefix' => '',
        ], 'mariadb');
        $capsule->setAsGlobal();

        return new QueryContext($name);
    }
}
