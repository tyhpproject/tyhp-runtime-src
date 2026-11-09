<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\AdvancedScenarios;
use Tyhp\Rhetoric\Fixtures\QueryContext;

final class AdvancedQueryTest extends TestCase
{
    private QueryContext $context;

    protected function setUp(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'rhetoric');
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
        $capsule->setAsGlobal();
        $connection = $capsule->getConnection('rhetoric');
        $connection->statement(
            'create table posts (id integer primary key, blog_id integer not null, title text not null, slug text not null, rating integer not null, published_at text null, deleted_at text null)',
        );
        $connection->statement(
            "insert into posts (id, blog_id, title, slug, rating, published_at, deleted_at) values (1, 1, 'Alpha', 'alpha', 1, null, null), (2, 1, 'Tyhp', 'tyhp', 5, null, null), (3, 1, 'Beta', 'beta', 4, null, null)",
        );
        $connection->statement('create table blogs (id integer primary key, name text not null, url text null, backed text null)');
        $connection->statement("insert into blogs (id, name, url) values (1, 'one', 'a'), (2, 'two', null), (3, 'three', 'b')");
        $this->context = new QueryContext('rhetoric');
    }

    public function testJoinProjectsBothSides(): void
    {
        $rows = AdvancedScenarios::joinedTitles($this->context);

        $titles = \array_map(static fn ($row) => $row->title, $rows);
        \sort($titles);
        self::assertSame(['Alpha', 'Beta', 'Tyhp'], $titles);
        self::assertSame(['one', 'one', 'one'], \array_map(static fn ($row) => $row->name, $rows));
        self::assertStringContainsString('join', AdvancedScenarios::joinedSql($this->grammar('sqlite'))->sql);
        self::assertStringContainsString('left join', AdvancedScenarios::leftJoinedSql($this->grammar('sqlite'))->sql);
    }

    public function testGroupHavingAndSum(): void
    {
        $grouped = AdvancedScenarios::grouped($this->context);

        self::assertCount(1, $grouped);
        self::assertSame(1, (int)$grouped[0]->key);
        self::assertSame(3, $grouped[0]->total);
        $sql = AdvancedScenarios::groupedSql($this->grammar('sqlite'))->sql;
        self::assertStringContainsString('group by', $sql);
        self::assertStringContainsString('having', $sql);
        self::assertSame(10, (int)AdvancedScenarios::ratingTotal($this->context)[0]->total);
    }

    public function testSetOperators(): void
    {
        $united = \array_map(static fn ($post) => $post->id, AdvancedScenarios::united($this->context));
        \sort($united);
        self::assertSame([1, 2, 3], $united);
        $union = AdvancedScenarios::unitedSql($this->grammar('sqlite'))->sql;
        self::assertStringContainsString('union', $union);
        self::assertStringNotContainsString('union all', $union);
        self::assertStringContainsString('union all', AdvancedScenarios::unitedAllSql($this->grammar('mysql'))->sql);
        self::assertStringContainsString('intersect', AdvancedScenarios::sharedSql($this->grammar('pgsql'))->sql);
        self::assertStringContainsString('except', AdvancedScenarios::excludedSql($this->grammar('sqlite'))->sql);
        self::assertSame([2], \array_map(static fn ($post) => $post->id, AdvancedScenarios::shared($this->context)));
        self::assertSame([3], \array_map(static fn ($post) => $post->id, AdvancedScenarios::excluded($this->context)));
    }

    public function testBulkUpdate(): void
    {
        self::assertSame(3, AdvancedScenarios::raiseRatings($this->context));
        self::assertSame([2, 6, 5], AdvancedScenarios::ratings(new QueryContext('rhetoric')));
    }

    public function testBulkDelete(): void
    {
        self::assertSame(1, AdvancedScenarios::removeLow($this->context));
        self::assertSame(['Tyhp', 'Beta'], AdvancedScenarios::titles(new QueryContext('rhetoric')));
    }

    public function testBulkIncrement(): void
    {
        self::assertSame(1, AdvancedScenarios::bump($this->context));
        self::assertSame([3, 5, 4], AdvancedScenarios::ratings(new QueryContext('rhetoric')));
    }

    public function testConstrainedEagerLoadAndSingleQuery(): void
    {
        self::assertSame(['Alpha'], AdvancedScenarios::blogsWithAlpha($this->context));
        $loaded = AdvancedScenarios::blogsWithPosts(new QueryContext('rhetoric'));
        self::assertCount(3, $loaded);
        self::assertCount(3, $loaded[0]->posts);
        $titles = \array_map(static fn ($post) => $post->title, $loaded[0]->posts);
        \sort($titles);
        self::assertSame(['Alpha', 'Beta', 'Tyhp'], $titles);
        self::assertCount(0, $loaded[1]->posts);
        $single = AdvancedScenarios::singleSql($this->grammar('sqlite'))->sql;
        self::assertStringContainsString('left join', $single);
        self::assertStringContainsString('rhetoric_e0', $single);
    }

    public function testCollectionPredicatesAndArrayProjection(): void
    {
        self::assertSame([1], AdvancedScenarios::hasAlpha($this->context));
        $exists = AdvancedScenarios::hasAlphaSql($this->grammar('sqlite'))->sql;
        self::assertStringContainsString('exists', $exists);
        self::assertStringContainsString('rhetoric_sq', $exists);
        $counts = AdvancedScenarios::alphaCounts(new QueryContext('rhetoric'));
        self::assertSame(1, $counts[0]->total);
        self::assertSame(0, $counts[1]->total);
        $rows = AdvancedScenarios::listed(new QueryContext('rhetoric'));
        self::assertSame('Alpha', $rows[0]['title']);
        self::assertSame('Tyhp', $rows[1]['title']);
        self::assertSame('Beta', $rows[2]['title']);
        $listed = AdvancedScenarios::listedSql($this->grammar('mysql'));
        self::assertStringContainsString(' as ', $listed->sql);
        $in = AdvancedScenarios::idsIn($this->grammar('pgsql'));
        self::assertStringContainsString('in (?, ?)', $in->sql);
        self::assertSame([4, 9], $in->bindings);
    }

    public function testDateAndMathHelpers(): void
    {
        self::assertSame([2, 3], AdvancedScenarios::loud($this->context));
        $sqlite = AdvancedScenarios::dateSql($this->grammar('sqlite'))->sql;
        self::assertStringContainsString('julianday', $sqlite);
        $mysql = AdvancedScenarios::dateSql($this->grammar('mysql'))->sql;
        self::assertStringContainsString('datediff', $mysql);
        $pgsql = AdvancedScenarios::dateSql($this->grammar('pgsql'))->sql;
        self::assertStringContainsString('extract', AdvancedScenarios::yearSql($this->grammar('pgsql'))->sql);
        self::assertStringContainsString('cast', $pgsql);
        self::assertStringContainsString('year(', AdvancedScenarios::yearSql($this->grammar('mysql'))->sql);
        self::assertStringContainsString('month(', AdvancedScenarios::monthSql($this->grammar('mysql'))->sql);
        self::assertStringContainsString('day(', AdvancedScenarios::daySql($this->grammar('mysql'))->sql);
        self::assertStringContainsString("strftime('%Y'", AdvancedScenarios::yearSql($this->grammar('sqlite'))->sql);
        self::assertStringContainsString("strftime('%m'", AdvancedScenarios::monthSql($this->grammar('sqlite'))->sql);
        self::assertStringContainsString("strftime('%d'", AdvancedScenarios::daySql($this->grammar('sqlite'))->sql);
        self::assertStringContainsString('round(', AdvancedScenarios::roundSql($this->grammar('sqlite'))->sql);
        self::assertStringContainsString('floor(', AdvancedScenarios::floorSql($this->grammar('sqlite'))->sql);
        self::assertStringContainsString('ceil(', AdvancedScenarios::ceilingSql($this->grammar('sqlite'))->sql);
    }

    private function grammar(string $name): QueryContext
    {
        return new QueryContext($name);
    }
}
