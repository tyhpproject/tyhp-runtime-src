<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\AdvancedRegressions;
use Tyhp\Rhetoric\Fixtures\Graph\BlogContext;
use Tyhp\Rhetoric\Fixtures\Graph\RelScenarios;
use Tyhp\Rhetoric\Fixtures\QueryContext;

final class AdvancedRegressionTest extends TestCase
{
    private \Illuminate\Database\Connection $connection;

    private QueryContext $context;

    protected function setUp(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'rhetoric');
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'sqlite');
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
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'graph');
        $capsule->setAsGlobal();
        $this->connection = $capsule->getConnection('rhetoric');
        $this->connection->statement(
            'create table posts (id integer primary key, blog_id integer not null, title text not null, slug text not null, rating integer not null, published_at text null, deleted_at text null)',
        );
        $this->connection->statement(
            "insert into posts (id, blog_id, title, slug, rating, published_at, deleted_at) values "
            . "(1, 1, 'Alpha', 'alpha', 1, '2024-01-01 23:00:00', '2024-01-02 01:00:00'), "
            . "(2, 1, 'Tyhp', 'tyhp', 5, '2024-01-01', '2024-01-04'), "
            . "(3, 1, 'Beta', 'beta', 4, null, null), "
            . "(4, 2, 'Gamma', 'gamma', 2, null, null)",
        );
        $this->connection->statement('create table blogs (id integer primary key, name text not null, url text null, backed text null)');
        $this->connection->statement("insert into blogs (id, name, url) values (1, 'one', 'a'), (2, 'two', null), (3, 'three', 'b')");
        $this->context = new QueryContext('rhetoric');
    }

    public function testLeftJoinKeepsUnmatchedRowsWhenTheOtherQueryFilters(): void
    {
        $none = AdvancedRegressions::leftJoinedRows($this->context, 'nobody');
        self::assertSame(['Alpha', 'Beta', 'Gamma', 'Tyhp'], \array_map(static fn ($row) => $row->title, $none));
        self::assertSame([null, null, null, null], \array_map(static fn ($row) => $row->name, $none));

        $two = AdvancedRegressions::leftJoinedRows(new QueryContext('rhetoric'), 'two');
        self::assertSame([null, null, 'two', null], \array_map(static fn ($row) => $row->name, $two));

        $one = AdvancedRegressions::leftJoinedRows(new QueryContext('rhetoric'), 'one');
        self::assertSame(['one', 'one', null, 'one'], \array_map(static fn ($row) => $row->name, $one));
    }

    public function testGroupedQueryCountsGroups(): void
    {
        self::assertSame(2, AdvancedRegressions::groupCount($this->context));
    }

    public function testJoinRejectsOtherQueryFeaturesThatWouldBeDropped(): void
    {
        $this->expectException(\LogicException::class);
        AdvancedRegressions::joinedLimited($this->context);
    }

    public function testGroupedCountAndExistsKeepTheGroupingOnEveryGrammar(): void
    {
        $mysql = AdvancedRegressions::groupCountSql(new QueryContext('mysql'))->sql;
        self::assertSame(
            'select count(*) as aggregate from (select 1 as `rhetoric_group` from `posts` group by `blog_id`) as `aggregate_table`',
            $mysql,
        );
        $pgsql = AdvancedRegressions::groupsExistSql(new QueryContext('pgsql'))->sql;
        self::assertSame(
            'select exists(select 1 as "rhetoric_group" from "posts" group by "blog_id" having count(*) >= ?) as "exists"',
            $pgsql,
        );
    }

    public function testGroupedExistsReadsTheGroups(): void
    {
        self::assertTrue(AdvancedRegressions::groupsExist($this->context, 3));
        self::assertFalse(AdvancedRegressions::groupsExist($this->context, 4));
        self::assertFalse(AdvancedRegressions::groupsExist($this->context, 5));
    }

    public function testSetOperationsAcceptCountExistsOrderingAndLimits(): void
    {
        self::assertSame(3, AdvancedRegressions::unitedCount($this->context));
        self::assertTrue(AdvancedRegressions::unitedExists($this->context));
        self::assertSame([3, 2], AdvancedRegressions::unitedTopIds($this->context, 2));
        self::assertSame([3, 2, 1], AdvancedRegressions::unitedTopIds($this->context, 5));
        self::assertSame(3, AdvancedRegressions::unitedFirstId($this->context));
        self::assertSame(10, AdvancedRegressions::unitedRatingSum($this->context));
        self::assertSame([2, 3], AdvancedRegressions::unitedSkippedIds($this->context));
        self::assertSame([1, 3], AdvancedRegressions::chainedSetsIds($this->context));
    }

    public function testChainedSetOperationsKeepLeftToRightOrderOnEveryGrammar(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $sql = AdvancedRegressions::chainedSetsSql(new QueryContext($driver))->sql;
            self::assertStringStartsNotWith('(', $sql);
            self::assertStringContainsString('rhetoric_acc2', $sql);
            // INTERSECT binds tighter than UNION on MySQL and PostgreSQL, so the union is a derived table.
            self::assertMatchesRegularExpression('/^select \* from \(select \* from \(.+\) as \S+ union select .+\) as \S+ intersect select /s', $sql, $driver);
        }
    }

    public function testBulkWritesRefuseQueriesThatNarrowWithPaging(): void
    {
        try {
            AdvancedRegressions::deleteFirst($this->context);
            self::fail('A take() before delete() must not delete every row.');
        } catch (\LogicException) {
            self::assertSame(4, (int)$this->connection->table('posts')->count());
        }
        try {
            AdvancedRegressions::updateSkipped(new QueryContext('rhetoric'));
            self::fail('A skip() before update() must not update every row.');
        } catch (\LogicException) {
            self::assertSame(
                [1, 5, 4, 2],
                \array_map(static fn ($row) => (int)$row->rating, $this->connection->table('posts')->orderBy('id')->get()->all()),
            );
        }
    }

    public function testSingleQueryLoadsEveryChildEvenWithFirstOrTake(): void
    {
        $first = AdvancedRegressions::firstSingle($this->context);
        self::assertNotNull($first);
        self::assertSame(1, $first->id);
        self::assertCount(3, $first->posts);

        $taken = AdvancedRegressions::takeSingle(new QueryContext('rhetoric'));
        self::assertCount(1, $taken);
        self::assertCount(3, $taken[0]->posts);
    }

    public function testSingleQueryQualifiesRootColumns(): void
    {
        $blogs = AdvancedRegressions::filteredSingle($this->context);
        self::assertSame([1, 2, 3], \array_map(static fn ($blog) => $blog->id, $blogs));
        self::assertCount(3, $blogs[0]->posts);
        self::assertCount(1, $blogs[1]->posts);
        self::assertCount(0, $blogs[2]->posts);
    }

    public function testSingleQueryConstraintStaysInTheJoinCondition(): void
    {
        $blogs = AdvancedRegressions::constrainedSingle($this->context, 'Alpha');
        self::assertCount(3, $blogs);
        self::assertSame(['Alpha'], \array_map(static fn ($post) => $post->title, $blogs[0]->posts));
        self::assertCount(0, $blogs[1]->posts);
    }

    public function testListsBindEachItem(): void
    {
        self::assertSame([1, 3], AdvancedRegressions::whereIn($this->context, [1, 3]));
        self::assertSame([2], AdvancedRegressions::whereIn($this->context, [2]));
        self::assertSame([], AdvancedRegressions::whereIn($this->context, []));
        self::assertSame([1, 2, 3, 4], AdvancedRegressions::whereIn($this->context, [4, 3, 2, 1, 9]));
    }

    public function testNestedArraysAreBuiltAfterTheRowsLoad(): void
    {
        $rows = AdvancedRegressions::nestedLists($this->context);
        self::assertCount(4, $rows);
        self::assertSame(['id' => 1, 'detail' => ['title' => 'Alpha', 'rating' => 1]], $rows[0]);
        self::assertSame(['id' => 4, 'detail' => ['title' => 'Gamma', 'rating' => 2]], $rows[3]);
    }

    public function testDateDifferenceCountsCalendarDays(): void
    {
        self::assertSame([1, 2], AdvancedRegressions::dayGap($this->context, 1));
        self::assertSame([2], AdvancedRegressions::dayGap(new QueryContext('rhetoric'), 2));
        self::assertSame([], AdvancedRegressions::dayGap(new QueryContext('rhetoric'), 4));
    }

    public function testRoundWithPrecisionCastsToNumericOnPostgres(): void
    {
        $sql = AdvancedRegressions::roundedIds(new QueryContext('pgsql'))->sql;
        self::assertStringContainsString('round(cast(', $sql);
        self::assertStringContainsString('as numeric)', $sql);
        self::assertStringContainsString('round(', AdvancedRegressions::roundedIds(new QueryContext('mysql'))->sql);
        self::assertStringNotContainsString('numeric', AdvancedRegressions::roundedIds(new QueryContext('mysql'))->sql);
    }

    public function testNestedCollectionPredicatesCorrelateToTheirOwnOuterRow(): void
    {
        $graph = $this->graph();
        self::assertSame([1], $this->blogIds(RelScenarios::blogsWithTaggedPosts($graph, 'Tyhp')->all()));
        self::assertSame([1, 2], $this->blogIds(RelScenarios::blogsWithTaggedPosts(new BlogContext('graph'), 'Laravel')->all()));
        self::assertSame([], $this->blogIds(RelScenarios::blogsWithTaggedPosts(new BlogContext('graph'), 'Nothing')->all()));
        $sql = RelScenarios::blogsWithTaggedPostsSql(new BlogContext('sqlite'), 'Tyhp')->sql;
        self::assertStringContainsString('rhetoric_sq1', $sql);
    }

    public function testSetOperationsReturnTheCombinedRowsAndAggregates(): void
    {
        self::assertSame([1, 2, 3], AdvancedRegressions::unitedRows($this->context));
        self::assertSame([1, 2, 3], AdvancedRegressions::unitedUnordered($this->context));
        self::assertSame(5, (int)AdvancedRegressions::unitedMax($this->context));
        self::assertSame(1, (int)AdvancedRegressions::unitedMin($this->context));
        self::assertEqualsWithDelta(10 / 3, AdvancedRegressions::unitedAvg($this->context), 0.0001);
        self::assertFalse(AdvancedRegressions::unitedNotExists($this->context));
        self::assertSame(1, AdvancedRegressions::unitedSoleId($this->context));
        self::assertSame(4, AdvancedRegressions::unionAllCount($this->context));
        self::assertSame([1, 3], AdvancedRegressions::exceptIds($this->context));
    }

    public function testEveryDoesNotApplyToASetOperation(): void
    {
        $this->expectException(\LogicException::class);
        AdvancedRegressions::unitedEvery($this->context);
    }

    public function testBulkWritesRefuseEveryQueryShapeThatNarrowsRows(): void
    {
        foreach (['updateUnited', 'deleteDistinct', 'deleteGrouped', 'incrementTaken', 'decrementSkipped'] as $scenario) {
            try {
                AdvancedRegressions::$scenario(new QueryContext('rhetoric'));
                self::fail($scenario . ' must refuse to write.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('act on the rows where() selects', $error->getMessage(), $scenario);
                self::assertSame([1, 5, 4, 2], \array_map(static fn ($row) => (int)$row->rating, $this->connection->table('posts')->orderBy('id')->get()->all()), $scenario);
                self::assertSame(4, (int)$this->connection->table('posts')->count(), $scenario);
            }
        }
    }

    public function testFilteredWritesTouchOnlyTheMatchingRows(): void
    {
        self::assertSame(1, AdvancedRegressions::filteredUpdate($this->context));
        self::assertSame([1, 5, 14, 2], \array_map(static fn ($row) => (int)$row->rating, $this->connection->table('posts')->orderBy('id')->get()->all()));
        self::assertSame(1, AdvancedRegressions::filteredDelete(new QueryContext('rhetoric')));
        self::assertSame([1, 2, 3], \array_map(static fn ($row) => (int)$row->id, $this->connection->table('posts')->orderBy('id')->get()->all()));
    }

    public function testJoinRejectsEveryOtherQueryFeatureThatWouldBeDropped(): void
    {
        foreach (['joinedSelected', 'joinedGrouped', 'joinedUnion', 'joinedDistinct', 'joinedSkipped', 'joinedFromRaw', 'joinedLimited'] as $scenario) {
            try {
                AdvancedRegressions::$scenario(new QueryContext('rhetoric'));
                self::fail($scenario . ' must refuse to join.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('can only be filtered with where()', $error->getMessage(), $scenario);
            }
        }
    }

    public function testJoinFilterStaysInTheJoinCondition(): void
    {
        $rows = AdvancedRegressions::filteredJoin($this->context);
        self::assertSame(['Alpha', 'Beta', 'Tyhp'], \array_map(static fn ($row) => $row->title, $rows));
        self::assertSame(['one', 'one', 'one'], \array_map(static fn ($row) => $row->name, $rows));
    }

    public function testSingleQueryWithSoleOrSkipLoadsChildrenInTheirOwnStatements(): void
    {
        $sole = AdvancedRegressions::soleSingle($this->context);
        self::assertSame(1, $sole->id);
        self::assertCount(3, $sole->posts);

        $skipped = AdvancedRegressions::skipSingle(new QueryContext('rhetoric'));
        self::assertSame([2, 3], \array_map(static fn ($blog) => $blog->id, $skipped));
        self::assertCount(1, $skipped[0]->posts);
        self::assertCount(0, $skipped[1]->posts);

        self::assertStringContainsString('left join', AdvancedRegressions::plainSingleSql(new QueryContext('rhetoric'))->sql);
        self::assertStringNotContainsString('left join', AdvancedRegressions::firstSingleSql(new QueryContext('rhetoric'))->sql);
    }

    public function testDateDifferenceBindsStartAndEndInTheOrderTheSqlReadsThem(): void
    {
        self::assertSame([1, 2, 3, 4], AdvancedRegressions::dayGapBetween($this->context, '2024-01-01', '2024-01-05', 4));
        self::assertSame([], AdvancedRegressions::dayGapBetween(new QueryContext('rhetoric'), '2024-01-05', '2024-01-01', 4));
        self::assertSame([1, 2, 3, 4], AdvancedRegressions::dayGapBetween(new QueryContext('rhetoric'), '2024-01-05', '2024-01-01', -4));
    }

    public function testDateDifferenceKeepsTheOtherBindingsInTheirOwnOrder(): void
    {
        // Ratings are 1, 5, 4, 2 for posts 1, 2, 3, 4; the gap holds for every row, so the other two bindings decide the result.
        self::assertSame([2, 3], AdvancedRegressions::dayGapAmongBindings($this->context, 4, '2024-01-01', '2024-01-05', 4, 'Alpha'));
        self::assertSame([3], AdvancedRegressions::dayGapAmongBindings(new QueryContext('rhetoric'), 4, '2024-01-01', '2024-01-05', 4, 'Tyhp'));
        self::assertSame([], AdvancedRegressions::dayGapAmongBindings(new QueryContext('rhetoric'), 4, '2024-01-05', '2024-01-01', 4, 'Beta'));
        self::assertSame([2], AdvancedRegressions::dayGapAmongBindings(new QueryContext('rhetoric'), 5, '2024-01-05', '2024-01-01', -4, 'Beta'));
    }

    public function testDateDifferenceBindsTheEndBeforeTheStartOnEveryGrammar(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $text = AdvancedRegressions::dayGapBetweenSql(new QueryContext($driver), '2024-01-01', '2024-01-05', 4);
            self::assertSame(['2024-01-05', '2024-01-01', 4], $text->bindings, $driver);
        }
        self::assertStringContainsString('datediff(?, ?)', AdvancedRegressions::dayGapBetweenSql(new QueryContext('mysql'), 'a', 'b', 1)->sql);
        self::assertStringContainsString('(cast(? as date) - cast(? as date))', AdvancedRegressions::dayGapBetweenSql(new QueryContext('pgsql'), 'a', 'b', 1)->sql);
    }

    public function testSingleQueryHonoursATablePrefix(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'p_'], 'prefixed');
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'unprefixed');
        $capsule->setAsGlobal();
        $connection = $capsule->getConnection('prefixed');
        $connection->statement('create table p_blogs (id integer primary key, name text not null, url text null, backed text null)');
        $connection->statement('create table p_posts (id integer primary key, blog_id integer not null, title text not null, slug text not null, rating integer not null, published_at text null, deleted_at text null)');
        $connection->statement("insert into p_blogs (id, name) values (1, 'one'), (2, 'two')");
        $connection->statement("insert into p_posts (id, blog_id, title, slug, rating) values (1, 1, 'Alpha', 'alpha', 1), (2, 1, 'Tyhp', 'tyhp', 5)");

        $blogs = AdvancedRegressions::filteredSingle(new QueryContext('prefixed'));
        self::assertSame([1, 2], \array_map(static fn ($blog) => $blog->id, $blogs));
        self::assertCount(2, $blogs[0]->posts);

        $sql = AdvancedRegressions::plainSingleSql(new QueryContext('prefixed'))->sql;
        self::assertStringContainsString('"p_blogs"."id" as "id"', $sql);
        self::assertStringContainsString('from "p_blogs" left join "p_posts" as "rhetoric_e0"', $sql);
        self::assertStringContainsString('"rhetoric_e0"."id" as "rhetoric_e0__id"', $sql);
        self::assertStringNotContainsString('"blogs"', $sql);
        self::assertStringNotContainsString('"p_rhetoric_e0"', $sql);
        self::assertStringContainsString('"blogs"."id" as "id"', AdvancedRegressions::plainSingleSql(new QueryContext('unprefixed'))->sql);
    }

    private function graph(): BlogContext
    {
        $connection = \Illuminate\Database\Capsule\Manager::connection('graph');
        $connection->statement('create table blogs (id integer primary key, name text not null)');
        $connection->statement('create table posts (id integer primary key, blog_id integer not null, title text not null)');
        $connection->statement('create table tags (id integer primary key, name text not null)');
        $connection->statement('create table post_tag (post_id integer not null, tag_id integer not null, primary key (post_id, tag_id))');
        $connection->statement("insert into blogs (id, name) values (1, 'News'), (2, 'Quiet')");
        $connection->statement("insert into posts (id, blog_id, title) values (1, 1, 'Alpha'), (2, 1, 'Beta'), (3, 2, 'Gamma')");
        $connection->statement("insert into tags (id, name) values (1, 'Laravel'), (2, 'Tyhp')");
        $connection->statement('insert into post_tag (post_id, tag_id) values (1, 2), (3, 1), (2, 1)');

        return new BlogContext('graph');
    }

    /**
     * @param array<int, object> $blogs
     * @return array<int, int>
     */
    private function blogIds(array $blogs): array
    {
        return \array_map(static fn ($blog) => $blog->id, $blogs);
    }
}
