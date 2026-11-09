<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\QueryContext;
use Tyhp\Rhetoric\Fixtures\QueryPrefixedContext;
use Tyhp\Rhetoric\Fixtures\QueryScenarios;
use Tyhp\Rhetoric\Query\SqlText;

final class QueryTranslationTest extends TestCase
{
    private \Illuminate\Database\Capsule\Manager $capsule;

    protected function setUp(): void
    {
        $this->capsule = new \Illuminate\Database\Capsule\Manager();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'sqlite');
        $this->capsule->addConnection([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'rhetoric',
            'username' => 'root',
            'password' => '',
            'prefix' => '',
        ], 'mysql');
        $this->capsule->addConnection([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'database' => 'rhetoric',
            'username' => 'root',
            'password' => '',
            'prefix' => '',
        ], 'pgsql');
        $this->capsule->addConnection([
            'driver' => 'mariadb',
            'host' => '127.0.0.1',
            'database' => 'rhetoric',
            'username' => 'root',
            'password' => '',
            'prefix' => '',
        ], 'mariadb');
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'p_',
        ], 'sqlite_prefixed');
        $this->capsule->setAsGlobal();
    }

    public function testComparisonAndBindings(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $sql = QueryScenarios::rating($this->context($driver), 3);
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts') . ' where ' . $this->column($driver, 'rating') . ' >= ?',
                $sql->sql,
            );
            self::assertSame([3], $sql->bindings);
        }
    }

    public function testNullAndNullableEquality(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $published = $this->column($driver, 'published_at');
            $deleted = $this->column($driver, 'deleted_at');
            $missing = QueryScenarios::missingPublished($this->context($driver));
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts') . ' where ' . $published . ' is null',
                $missing->sql,
            );
            self::assertSame([], $missing->bindings);

            $same = QueryScenarios::sameInstant($this->context($driver));
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts')
                . ' where ((' . $published . ' = ' . $deleted . ') or (' . $published . ' is null and ' . $deleted . ' is null))',
                $same->sql,
            );
            $different = QueryScenarios::differentInstant($this->context($driver));
            self::assertStringContainsString($published . ' <> ' . $deleted, $different->sql);
            self::assertStringContainsString($published . ' is null and ' . $deleted . ' is not null', $different->sql);
        }
    }

    public function testStringMethodsAndIn(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $title = $this->column($driver, 'title');
            $starts = QueryScenarios::titleStarts($this->context($driver), 'Ty');
            // MySQL reads a backslash in a string literal as an escape, so the literal is doubled.
            $escape = $driver === 'mysql' ? "'\\\\'" : "'\\'";
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts') . ' where ' . $title . ' like ? escape ' . $escape,
                $starts->sql,
            );
            self::assertSame(['Ty%'], $starts->bindings);

            $contains = QueryScenarios::titleContains($this->context($driver), 'a_b');
            self::assertSame(['%a\\_b%'], $contains->bindings);

            $ends = QueryScenarios::titleEnds($this->context($driver), 'hp');
            self::assertSame(['%hp'], $ends->bindings);

            $lower = QueryScenarios::lowerTitle($this->context($driver));
            self::assertStringContainsString('lower(trim(' . $title . '))', $lower->sql);
            self::assertSame(['tyhp'], $lower->bindings);

            $length = QueryScenarios::titleLength($this->context($driver));
            $function = $driver === 'sqlite' ? 'length' : 'char_length';
            self::assertStringContainsString($function . '(' . $title . ') > ?', $length->sql);
            self::assertSame([2], $length->bindings);

            $ids = QueryScenarios::ids($this->context($driver), [4, 9]);
            self::assertStringContainsString($this->column($driver, 'id') . ' in (?, ?)', $ids->sql);
            self::assertSame([4, 9], $ids->bindings);
            $empty = QueryScenarios::ids($this->context($driver), []);
            self::assertStringContainsString('0 = 1', $empty->sql);
            self::assertSame([], $empty->bindings);
        }
    }

    public function testOrderLimitLockTagAndFrom(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $ordered = QueryScenarios::ordered($this->context($driver));
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts')
                . ' order by ' . $this->column($driver, 'title') . ' asc, ' . $this->column($driver, 'id') . ' asc',
                $ordered->sql,
            );
            $latest = QueryScenarios::latest($this->context($driver));
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts') . ' order by ' . $this->column($driver, 'id') . ' desc',
                $latest->sql,
            );
            $page = QueryScenarios::page($this->context($driver));
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts') . ' limit 10 offset 20',
                $page->sql,
            );
            $locked = QueryScenarios::distinctLock($this->context($driver));
            $lock = $driver === 'sqlite' ? '' : ' for update';
            self::assertSame(
                'select distinct * from ' . $this->table($driver, 'posts') . $lock,
                $locked->sql,
            );
            $shared = QueryScenarios::shared($this->context($driver));
            $share = match ($driver) {
                'mysql' => ' lock in share mode',
                'pgsql' => ' for share',
                default => '',
            };
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts') . $share,
                $shared->sql,
            );
            $tagged = QueryScenarios::tagged($this->context($driver));
            self::assertSame(
                '/* listing */ select * from ' . $this->table($driver, 'posts'),
                $tagged->sql,
            );
            $from = QueryScenarios::fromPosts($this->context($driver), 'posts');
            self::assertStringContainsString('posts', $from->sql);
        }
    }

    public function testProjectionAggregatesExistsAndCache(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $context = $this->context($driver);
            $titles = QueryScenarios::titles($context);
            self::assertSame(
                'select ' . $this->column($driver, 'id') . ' as ' . $this->column($driver, 'c0')
                . ', ' . $this->column($driver, 'title') . ' as ' . $this->column($driver, 'c1')
                . ' from ' . $this->table($driver, 'posts'),
                $titles->sql,
            );
            $count = QueryScenarios::counted($context);
            self::assertSame(
                'select count(*) as aggregate from ' . $this->table($driver, 'posts')
                . ' where ' . $this->column($driver, 'rating') . ' >= ?',
                $count->sql,
            );
            self::assertSame([3], $count->bindings);
            $sum = QueryScenarios::summed($context);
            self::assertSame(
                'select sum(' . $this->column($driver, 'rating') . ') as aggregate from ' . $this->table($driver, 'posts'),
                $sum->sql,
            );
            $exists = QueryScenarios::existed($context);
            self::assertSame(
                'select exists(select * from ' . $this->table($driver, 'posts')
                . ' where ' . $this->column($driver, 'rating') . ' >= ?) as ' . $this->column($driver, 'exists'),
                $exists->sql,
            );
            $absent = QueryScenarios::absent($context);
            self::assertStringStartsWith('select not exists(', $absent->sql);
            $every = QueryScenarios::everyone($context);
            self::assertStringContainsString('not (' . $this->column($driver, 'rating') . ' >= ?)', $every->sql);
            self::assertStringStartsWith('select not exists(', $every->sql);
            $first = QueryScenarios::firstSql($context);
            self::assertSame('select * from ' . $this->table($driver, 'posts') . ' limit 1', $first->sql);
            $like = QueryScenarios::liked($context);
            self::assertStringContainsString($this->column($driver, 'title') . ' like ?', $like->sql);
            self::assertSame(['Ty%'], $like->bindings);
        }

        $raw = QueryScenarios::rawSql($this->context('sqlite'));
        self::assertStringContainsString('3', $raw);
        $message = QueryScenarios::untranslatable($this->context('sqlite'));
        self::assertStringContainsString('methodCall', $message);
        self::assertStringContainsString('reverse', $message);
        self::assertStringContainsString('fn ($post)', $message);
        self::assertSame(1, QueryScenarios::cacheMisses($this->context('sqlite')));
    }

    public function testMariaDbUsesTheMySqlSpellings(): void
    {
        $context = $this->context('mariadb');
        $starts = QueryScenarios::titleStarts($context, 'Ty');
        self::assertSame(
            "select * from `posts` where `title` like ? escape '\\\\'",
            $starts->sql,
        );
        $length = QueryScenarios::titleLength($context);
        self::assertStringContainsString('char_length(`title`) > ?', $length->sql);
    }

    public function testNegatedComparisonKeepsRowsWithNull(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $url = $this->column($driver, 'url');
            $not = QueryScenarios::urlNotSql($this->context($driver), 'a');
            self::assertSame(
                'select * from ' . $this->table($driver, 'blogs') . ' where ((' . $url . ' <> ?) is not false)',
                $not->sql,
            );
            self::assertSame(['a'], $not->bindings);

            $negated = QueryScenarios::urlNegatedSql($this->context($driver), 'a');
            self::assertSame(
                'select * from ' . $this->table($driver, 'blogs') . ' where (case when ' . $url . ' = ? then 0 else 1 end = 1)',
                $negated->sql,
            );
            self::assertSame(['a'], $negated->bindings);
        }
    }

    public function testEmptyInDropsTheValueBindings(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $empty = QueryScenarios::idsPlus($this->context($driver), [], 5);
            self::assertSame('select * from ' . $this->table($driver, 'posts') . ' where 0 = 1', $empty->sql);
            self::assertSame([], $empty->bindings);

            $full = QueryScenarios::idsPlus($this->context($driver), [4, 9], 5);
            self::assertSame(
                'select * from ' . $this->table($driver, 'posts') . ' where ' . $this->column($driver, 'id') . ' + ? in (?, ?)',
                $full->sql,
            );
            self::assertSame([5, 4, 9], $full->bindings);
        }
    }

    public function testSkipWithoutTakeGetsALimitWhereTheGrammarNeedsOne(): void
    {
        $limits = [
            'sqlite' => ' limit 9223372036854775807 offset 5',
            'mysql' => ' limit 9223372036854775807 offset 5',
            'mariadb' => ' limit 9223372036854775807 offset 5',
            'pgsql' => ' offset 5',
        ];
        foreach ($limits as $driver => $tail) {
            $skip = QueryScenarios::skipOnly($this->context($driver));
            self::assertSame('select * from ' . $this->table($driver, 'posts') . $tail, $skip->sql);
        }
    }

    public function testSkipAndTakeStayInsideAggregatesAndExistence(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $context = $this->context($driver);
            $posts = $this->table($driver, 'posts');
            $id = $this->column($driver, 'id');
            $rating = $this->column($driver, 'rating');
            $inner = 'select * from ' . $posts . ' order by ' . $id . ' asc limit 2';

            $count = QueryScenarios::pagedCount($context);
            self::assertSame(
                'select count(*) as aggregate from (' . $inner . ') as ' . $this->column($driver, 'aggregate_table'),
                $count->sql,
            );

            $sum = QueryScenarios::pagedSum($context);
            $offset = $driver === 'pgsql' ? ' offset 1' : ' limit 9223372036854775807 offset 1';
            self::assertSame(
                'select sum(' . $rating . ' + ?) as aggregate from (select * from ' . $posts . ' order by ' . $id . ' asc' . $offset
                . ') as ' . $this->column($driver, 'aggregate_table'),
                $sum->sql,
            );
            self::assertSame([1], $sum->bindings);

            $every = QueryScenarios::pagedEvery($context);
            self::assertSame(
                'select not exists(select * from (select * from ' . $posts . ' order by ' . $id . ' asc' . $offset . ') as '
                . $this->column($driver, 'every_table') . ' where ' . 'not (' . $rating . ' >= ?)) as ' . $this->column($driver, 'exists'),
                $every->sql,
            );
            self::assertSame([3], $every->bindings);

            $exists = QueryScenarios::pagedExists($context);
            self::assertSame(
                'select exists(select * from ' . $posts . ' order by ' . $id . ' asc' . $offset . ') as ' . $this->column($driver, 'exists'),
                $exists->sql,
            );
        }
    }

    public function testSelectBindingsPrecedeFromBindings(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $statement = QueryScenarios::suffixedFrom($this->context($driver), '!');
            self::assertSame(['!', 0], $statement->bindings);
        }
    }

    public function testCacheKeysSeparateTagsContextsAndPrefixes(): void
    {
        $context = $this->context('sqlite');
        self::assertSame('/* a b */ select * from "posts"', QueryScenarios::twoTags($context));
        self::assertSame('/* a,b */ select * from "posts"', QueryScenarios::oneTag($context, 'a,b'));
        self::assertSame('/* a b */ select * from "posts"', QueryScenarios::twoTags($context));

        self::assertSame('select * from "blogs"', QueryScenarios::blogsSql($context));
        self::assertSame('select * from "app_blogs"', QueryScenarios::prefixedBlogsSql(new QueryPrefixedContext('sqlite')));
        self::assertSame('select * from "blogs"', QueryScenarios::blogsSql($context));

        self::assertSame('select * from "posts"', QueryScenarios::postsSql($context));
        self::assertSame('select * from "p_posts"', QueryScenarios::postsSql($this->context('sqlite_prefixed')));
        self::assertSame('select * from "posts"', QueryScenarios::postsSql($context));
    }

    public function testTagCannotCloseItsComment(): void
    {
        $sql = QueryScenarios::oneTag($this->context('sqlite'), 'x**//; drop table posts; --');
        self::assertSame(1, \substr_count($sql, '*/'));
        self::assertStringStartsWith('/* x; drop table posts; -- */ select', $sql);
    }

    public function testTagCannotOpenANestedComment(): void
    {
        $sql = QueryScenarios::oneTag($this->context('sqlite'), 'a /* b /* c');
        self::assertSame(1, \substr_count($sql, '/*'));
        self::assertSame(1, \substr_count($sql, '*/'));
        self::assertStringStartsWith('/* a  b  c */ select', $sql);

        $joined = QueryScenarios::oneTag($this->context('sqlite'), 'x/*/*y');
        self::assertStringStartsWith('/* xy */ select', $joined);
    }

    public function testRawFragmentsDoNotCollideAcrossBoundaries(): void
    {
        $context = $this->context('sqlite');
        self::assertStringContainsString('(x|raw:y and z)', QueryScenarios::rawPair($context, 'x|raw:y', 'z'));
        self::assertStringContainsString('(x and y|raw:z)', QueryScenarios::rawPair($context, 'x', 'y|raw:z'));
    }

    public function testRawSqlSubstitutesBindingsAfterTheEscapeClause(): void
    {
        // Quoting a string binding needs a live connection, so only SQLite can run here. The
        // MySQL spelling goes through the same code path with the other clause.
        $raw = QueryScenarios::likeThenComparison($this->context('sqlite'));
        self::assertSame('select * from "posts" where ("title" like \'Ty%\' escape \'\\\' and "rating" > 2)', $raw);
        self::assertSame(
            ["escape '\\\\'", "escape '\\'"],
            \Tyhp\Rhetoric\Query\Translator::likeEscapeClauses(),
        );
    }

    public function testRawSqlKeepsTagTextAwayFromTheBindings(): void
    {
        $raw = QueryScenarios::taggedComparison($this->context('sqlite'), "it's ? here");
        self::assertSame('/* it\'s ? here */ select * from "posts" where "rating" > 2', $raw);
    }

    public function testFirstAndSoleNeverReadMoreThanTakeAllows(): void
    {
        $context = $this->context('sqlite');
        self::assertSame('select * from "posts" limit 1', QueryScenarios::firstStatementWithTake($context, 5)->sql);
        self::assertSame('select * from "posts" limit 0', QueryScenarios::firstStatementWithTake($context, 0)->sql);
        self::assertSame('select * from "posts" limit 1', QueryScenarios::firstStatementWithTake($context, -1)->sql);
    }

    public function testRawFragmentsAreNotSharedBetweenQueries(): void
    {
        $context = $this->context('sqlite');
        self::assertStringContainsString('where rating > 1', QueryScenarios::rawFragment($context, 'rating > 1'));
        self::assertStringContainsString('where rating > 2', QueryScenarios::rawFragment($context, 'rating > 2'));
        self::assertStringContainsString('where rating > 1', QueryScenarios::rawFragment($context, 'rating > 1'));
    }

    public function testWhereAfterSelectDoesNotUseTheSourceModel(): void
    {
        $context = $this->context('sqlite');
        $message = QueryScenarios::whereAfterSelect($context);
        self::assertStringContainsString('PostTitle', $message);
        self::assertStringContainsString('not the source model', $message);

        $kept = QueryScenarios::whereThenSelect($context);
        self::assertStringContainsString('where "rating" >= ?', $kept->sql);
        self::assertSame([3], $kept->bindings);
    }

    private function context(string $driver): QueryContext
    {
        return new QueryContext($driver);
    }

    private function table(string $driver, string $name): string
    {
        return $this->column($driver, $name);
    }

    private function column(string $driver, string $name): string
    {
        $quote = $driver === 'mysql' || $driver === 'mariadb' ? '`' : '"';

        return $quote . $name . $quote;
    }
}
