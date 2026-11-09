<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\QueryContext;
use Tyhp\Rhetoric\Fixtures\QueryScenarios;

final class QueryExecutionTest extends TestCase
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
        $capsule->setAsGlobal();
        $connection = $capsule->getConnection('rhetoric');
        $connection->statement(
            'create table posts (id integer primary key, blog_id integer not null, title text not null, slug text not null, rating integer not null, published_at text null, deleted_at text null)',
        );
        $connection->statement(
            "insert into posts (id, blog_id, title, slug, rating, published_at, deleted_at) values (1, 1, 'Alpha', 'alpha', 1, null, null), (2, 1, 'Tyhp', 'tyhp', 5, null, null), (3, 1, 'Beta', 'beta', 4, null, null)",
        );
        $connection->statement('create table memos (id integer primary key, body text not null)');
        $connection->statement("insert into memos (id, body) values (7, 'hello')");
        $connection->statement('create table blogs (id integer primary key, name text not null, url text null)');
        $connection->statement("insert into blogs (id, name, url) values (1, 'one', 'a'), (2, 'two', null), (3, 'three', 'b')");
        $this->context = new QueryContext('rhetoric');
    }

    public function testNegatedAndNullableComparisonsKeepRowsWithNull(): void
    {
        self::assertSame([2, 3], QueryScenarios::urlNotIds($this->context, 'a'));
        self::assertSame([2, 3], QueryScenarios::urlNegatedIds($this->context, 'a'));
        self::assertFalse(QueryScenarios::everyUrlIs($this->context, 'a'));
    }

    public function testCapturedNullAndNonNullShareNoPlan(): void
    {
        self::assertSame([1], QueryScenarios::urlIs($this->context, 'a'));
        self::assertSame([2], QueryScenarios::urlIs($this->context, null));
        self::assertSame([1], QueryScenarios::urlIs($this->context, 'a'));
    }

    public function testSkipAndTakeApplyBeforeAggregatesAndExistence(): void
    {
        self::assertSame(3, QueryScenarios::pagedCountValue($this->context, 3));
        self::assertSame(2, QueryScenarios::pagedCountValue($this->context, 2));
        self::assertSame(2, QueryScenarios::skippedCountValue($this->context));
        self::assertSame(6, QueryScenarios::pagedSumValue($this->context));
        self::assertTrue(QueryScenarios::pagedExistsValue($this->context, 2));
        self::assertFalse(QueryScenarios::pagedExistsValue($this->context, 3));
        self::assertTrue(QueryScenarios::pagedEveryValue($this->context));
        self::assertFalse(QueryScenarios::notAll($this->context));
        self::assertSame([2, 3], \array_map(static fn ($post) => $post->id, QueryScenarios::skippedRows($this->context)));
    }

    public function testFirstAndSoleHonourTake(): void
    {
        self::assertSame(1, QueryScenarios::firstWithTake($this->context, 2)?->id);
        self::assertNull(QueryScenarios::firstWithTake($this->context, 0));
        self::assertSame(1, QueryScenarios::soleWithTake($this->context, 1)->id);
        $this->expectException(\Illuminate\Database\MultipleRecordsFoundException::class);
        QueryScenarios::soleWithTake($this->context, 5);
    }

    public function testSoleWithTakeZeroFindsNothing(): void
    {
        $this->expectException(\Illuminate\Database\RecordsNotFoundException::class);
        QueryScenarios::soleWithTake($this->context, 0);
    }

    public function testSkipWithNegativeTakeStillRuns(): void
    {
        self::assertSame([2, 3], \array_map(static fn ($post) => $post->id, QueryScenarios::skippedWithNegativeTake($this->context)));
    }

    public function testSelectBindingsPrecedeFromBindings(): void
    {
        $titles = QueryScenarios::suffixedRows($this->context, '!');
        self::assertSame(['Alpha!', 'Tyhp!', 'Beta!'], \array_map(static fn ($title) => $title->title, $titles));
    }

    public function testRowsMatchThePredicate(): void
    {
        $rows = QueryScenarios::highRated($this->context);

        self::assertSame([2, 3], \array_map(static fn ($post) => $post->id, $rows));
        self::assertSame(['Tyhp', 'Beta'], \array_map(static fn ($post) => $post->title, $rows));
        self::assertNull(QueryScenarios::firstHigh($this->context)?->publishedAt);
        self::assertSame(2, QueryScenarios::firstHigh($this->context)?->id);
    }

    public function testFirstSoleAndExistence(): void
    {
        self::assertSame(1, QueryScenarios::only($this->context)->id);
        self::assertTrue(QueryScenarios::anyRated($this->context));
        self::assertTrue(QueryScenarios::noneLow($this->context));
        self::assertTrue(QueryScenarios::allHigh($this->context));
        self::assertFalse(QueryScenarios::notAll($this->context));
        $this->expectException(\Illuminate\Database\MultipleRecordsFoundException::class);
        QueryScenarios::tooMany($this->context);
    }

    public function testMissingRowThrows(): void
    {
        $this->expectException(\Illuminate\Database\RecordsNotFoundException::class);
        QueryScenarios::missing($this->context);
    }

    public function testAggregatesProjectionAndReadonlyHydration(): void
    {
        self::assertSame(2, QueryScenarios::howMany($this->context));
        self::assertSame(10, QueryScenarios::totalRating($this->context));
        self::assertSame(1, QueryScenarios::lowest($this->context));
        self::assertSame(5, QueryScenarios::highest($this->context));
        self::assertEqualsWithDelta(3.333, (float) QueryScenarios::average($this->context), 0.01);
        $titles = QueryScenarios::projected($this->context);
        self::assertSame('Alpha', $titles[0]->title);
        self::assertSame(1, $titles[0]->id);
        self::assertSame(['ahplA', 'phyT', 'ateB'], QueryScenarios::reversed($this->context));
        $memo = QueryScenarios::memo($this->context);
        self::assertNotNull($memo);
        self::assertSame(7, $memo->id);
        self::assertSame('hello', $memo->body);
        self::assertSame(3, QueryScenarios::collected($this->context));
    }
}
