<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\Eloquent\Article;
use Tyhp\Rhetoric\Fixtures\Eloquent\EloquentScenarios;
use Tyhp\Rhetoric\Query\QueryTranslationException;

final class EloquentQueryTest extends TestCase
{
    private ?\Illuminate\Database\Capsule\Manager $capsule = null;

    protected function setUp(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Typed queries over Eloquent models require PHP 8.4 or newer.');
        }
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'rhetoric');
        $capsule->bootEloquent();
        $capsule->setAsGlobal();
        $this->capsule = $capsule;
        $connection = $capsule->getConnection('rhetoric');
        $connection->statement(
            'create table articles (id integer primary key, title text not null, rating integer not null, published_at text null, headline text not null)',
        );
        $connection->statement(
            "insert into articles (id, title, rating, published_at, headline) values (1, 'Alpha', 1, null, 'A'), (2, 'Tyhp', 5, '2024-01-01 00:00:00', 'Hello'), (3, 'Beta', 4, null, 'B'), (4, 'Topic', 4, '2024-06-01 00:00:00', 'Hello')",
        );
    }

    protected function tearDown(): void
    {
        if ($this->capsule !== null) {
            $this->capsule->getDatabaseManager()->purge('rhetoric');
        }
        $this->capsule = null;
    }

    public function testWhereReturnsTheEloquentModel(): void
    {
        self::assertSame([2], EloquentScenarios::titledIds('rhetoric', 'Tyhp'));
        $found = EloquentScenarios::firstTitled('rhetoric', 'Tyhp');
        self::assertInstanceOf(Article::class, $found);
        self::assertSame('Tyhp', $found->getAttribute('title'));
        self::assertNull(EloquentScenarios::firstTitled('rhetoric', 'Missing'));
    }

    public function testBooleanOrderAndNullFollowTheLambda(): void
    {
        self::assertSame([3], EloquentScenarios::highRatedIds('rhetoric'));
        self::assertSame([1, 3], EloquentScenarios::missingPublishedIds('rhetoric'));
        self::assertSame([1, 3, 4], EloquentScenarios::notInstantIds('rhetoric', '2024-01-01 00:00:00'));
        self::assertSame([2, 4], EloquentScenarios::titlePrefixIds('rhetoric', 'T'));
        self::assertSame([1, 4], EloquentScenarios::amongIds('rhetoric', [1, 4]));
        self::assertSame([2, 3, 4, 1], EloquentScenarios::byRatingIds('rhetoric'));
    }

    public function testLikeWildcardsInTheNeedleMatchLiterally(): void
    {
        $this->capsule->getConnection('rhetoric')->statement(
            "insert into articles (id, title, rating, published_at, headline) values (5, '50% off', 1, null, 'x'), (6, 'a_b', 1, null, 'x'), (7, 'axb', 1, null, 'x'), (8, 'a\\b', 1, null, 'x')",
        );
        self::assertSame([5], EloquentScenarios::titleContainsIds('rhetoric', '%'));
        self::assertSame([6], EloquentScenarios::titleContainsIds('rhetoric', '_'));
        self::assertSame([8], EloquentScenarios::titleContainsIds('rhetoric', '\\'));
        self::assertSame([1, 2, 3, 4, 5, 7, 8], EloquentScenarios::titleLacksIds('rhetoric', '_'));
    }

    public function testColumnAttributeAndSnakeCaseNameTheSql(): void
    {
        $headline = EloquentScenarios::headlineSql('rhetoric', 'Hello');
        self::assertStringContainsString('headline', $headline);
        self::assertStringNotContainsString('custom_headline', $headline);
        self::assertStringContainsString('published_at', EloquentScenarios::publishedSql('rhetoric'));
        [$open, $filtered] = EloquentScenarios::untouchedSql('rhetoric');
        self::assertStringNotContainsString('where', \strtolower($open));
        self::assertStringContainsString('title', \strtolower($filtered));
    }

    public function testAnUntranslatableWhereIsRejected(): void
    {
        $this->expectException(QueryTranslationException::class);
        EloquentScenarios::bad('rhetoric');
    }

    public function testHookedPropertiesDelegateToAttributes(): void
    {
        require_once __DIR__ . '/Support/EloquentHookArticle.php';
        $article = new \Tyhp\Tests\Rhetoric\Support\EloquentHookArticle();
        $article->setConnection('rhetoric');
        $article->title = 'Hooked';
        $article->rating = 9;
        $article->setAttribute('headline', 'Hook');
        self::assertSame('Hooked', $article->getAttribute('title'));
        self::assertSame(9, $article->getAttribute('rating'));
        $article->save();
        $loaded = \Tyhp\Tests\Rhetoric\Support\EloquentHookArticle::on('rhetoric')->where('title', 'Hooked')->first();
        self::assertInstanceOf(\Tyhp\Tests\Rhetoric\Support\EloquentHookArticle::class, $loaded);
        self::assertSame('Hooked', $loaded->title);
        self::assertSame(9, $loaded->rating);
    }
}
