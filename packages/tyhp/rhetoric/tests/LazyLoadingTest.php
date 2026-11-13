<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\LazyLoad\Article;
use Tyhp\Rhetoric\Fixtures\LazyLoad\Author;
use Tyhp\Rhetoric\Fixtures\LazyLoad\Label;
use Tyhp\Rhetoric\Fixtures\LazyLoad\LazyScenarios;
use Tyhp\Rhetoric\Fixtures\LazyLoad\LibraryContext;
use Tyhp\Rhetoric\Fixtures\LazyLoad\Profile;
use Tyhp\Rhetoric\LazyCollection;
use Tyhp\Rhetoric\Mapping\RelationKind;
use Tyhp\Rhetoric\Tracking\TrackingState;

final class LazyLoadingTest extends TestCase
{
    private \Illuminate\Database\Connection $connection;

    private LibraryContext $context;

    protected function setUp(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ], 'library');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->connection = $capsule->getConnection('library');
        $this->context = new LibraryContext('library');
        $this->schema();
        $this->connection->enableQueryLog();
    }

    public function testDeferredColumnsAreMarkedOnTheMap(): void
    {
        $articles = $this->context->mapping()->model(Article::class);

        self::assertTrue($articles->column('body')->deferred);
        self::assertFalse($articles->column('title')->deferred);
    }

    public function testLazyLoadingIsOffUntilAsked(): void
    {
        $article = $this->one($this->context->articles->all(), 1);
        self::assertSame('Hello', $article->body);

        $this->connection->flushQueryLog();
        $author = $this->one($this->context->authors->all(), 1);
        self::assertInstanceOf(LazyCollection::class, $author->articles);
        self::assertTrue($author->articles->isLoaded());
        self::assertCount(0, $author->articles);
        foreach ($this->queries() as $query) {
            self::assertStringNotContainsString('articles', \strtolower($query));
        }

        $loaded = $this->one(LazyScenarios::authorsWithArticles($this->context), 1);
        self::assertCount(2, $loaded->articles);
        self::assertSame('First', $loaded->articles->item(0)->title);
    }

    public function testReferenceReturnsTheSameInstance(): void
    {
        $this->connection->flushQueryLog();
        $first = $this->context->articles->reference(1);
        $second = $this->context->articles->reference(2);
        if (\Tyhp\Lazy::isLazy($first)) {
            self::assertSame([], $this->queries());
            $this->context->tracker->detectChanges();
            self::assertTrue(\Tyhp\Lazy::isLazy($first));
            self::assertSame(TrackingState::Clean, $this->context->entry($first)->state);
            self::assertSame(1, $first->id);
            self::assertTrue(\Tyhp\Lazy::isLazy($first));
            self::assertSame('First', $first->title);
            self::assertCount(1, $this->queries());
            self::assertStringContainsString('in (', \strtolower($this->queries()[0]));
            self::assertFalse(\Tyhp\Lazy::isLazy($second));
            self::assertSame('Second', $second->title);
        } else {
            self::assertSame('First', $first->title);
            self::assertSame('Second', $second->title);
        }
        $found = $this->context->articles->find(1);
        self::assertNotNull($found);
        self::assertSame(\spl_object_id($first), \spl_object_id($found));

        $this->context->tracker->detectChanges();
        self::assertSame(TrackingState::Clean, $this->context->entry($first)->state);
    }

    public function testAQueryFillsTheReferencedInstance(): void
    {
        $this->context->lazyLoading();
        $referenced = $this->context->articles->reference(1);
        $lazy = \Tyhp\Lazy::isLazy($referenced);
        $loaded = $this->one($this->context->articles->all(), 1);

        self::assertSame(\spl_object_id($referenced), \spl_object_id($loaded));
        self::assertSame('First', $loaded->title);
        if ($lazy) {
            // The query left the deferred column out, so the instance keeps that column for later.
            self::assertTrue(\Tyhp\Lazy::isLazy($loaded));
        }
        self::assertSame('Hello', $loaded->body);
        self::assertFalse(\Tyhp\Lazy::isLazy($loaded));
    }

    public function testAssigningAReferenceCopiesTheKeyWithoutLoadingIt(): void
    {
        $author = $this->context->authors->reference(1);
        $lazy = \Tyhp\Lazy::isLazy($author);
        $article = new Article();
        $article->title = 'Draft';
        $article->body = 'Notes';
        $this->context->articles->add($article);
        $article->author = $author;
        $this->context->tracker->detectChanges();

        self::assertSame(1, $article->authorId);
        if ($lazy) {
            self::assertTrue(\Tyhp\Lazy::isLazy($author));
        }
    }

    public function testPreventLazyLoadingThrowsAndLeavesTheCollectionUnloaded(): void
    {
        $this->context->lazyLoading();
        $this->context->preventLazyLoading();
        $author = $this->one($this->context->authors->all(), 1);
        self::assertFalse($author->articles->isLoaded());
        $this->connection->flushQueryLog();

        try {
            \count($author->articles);
            self::fail('A prevented collection should throw.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('not allowed', $error->getMessage());
        }
        self::assertFalse($author->articles->isLoaded());
        self::assertSame([], $this->queries());
    }

    public function testPreventLazyLoadingRejectsAReferenceLoad(): void
    {
        $this->context->preventLazyLoading();
        $article = null;
        try {
            $article = $this->context->articles->reference(1);
            $article->title;
            self::fail('A prevented reference should throw.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('not allowed', $error->getMessage());
        }
        if ($article !== null) {
            self::assertTrue(\Tyhp\Lazy::isLazy($article));
        }
    }

    public function testAClosedContextRefusesALoad(): void
    {
        $this->context->lazyLoading();
        $author = $this->one($this->context->authors->all(), 1);
        $this->context->close();
        $this->connection->flushQueryLog();

        try {
            \count($author->articles);
            self::fail('A closed context should throw.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('closed', $error->getMessage());
        }
        self::assertFalse($author->articles->isLoaded());
        self::assertSame([], $this->queries());
    }

    public function testDeferredColumnsLoadOnAccessWhenTheModelStaysLazy(): void
    {
        $this->context->lazyLoading();
        $this->connection->flushQueryLog();
        $article = $this->context->articles->find(1);
        self::assertNotNull($article);
        if (\Tyhp\Lazy::isLazy($article)) {
            self::assertSame('First', $article->title);
            self::assertTrue(\Tyhp\Lazy::isLazy($article));
            self::assertSame('Hello', $article->body);
            self::assertFalse(\Tyhp\Lazy::isLazy($article));

            return;
        }

        self::assertSame('Hello', $article->body);
    }

    public function testDeferredColumnComesFromTheDatabaseOnFirstAccess(): void
    {
        $this->context->lazyLoading();
        $article = $this->context->articles->find(1);
        self::assertNotNull($article);
        if (!\Tyhp\Lazy::isLazy($article)) {
            self::assertSame('Hello', $article->body);

            return;
        }

        $this->connection->update('update articles set body = ? where id = ?', ['Changed', 1]);
        self::assertSame('First', $article->title);
        self::assertTrue(\Tyhp\Lazy::isLazy($article));
        $this->connection->flushQueryLog();
        self::assertSame('Changed', $article->body);
        self::assertCount(1, $this->queries());
        self::assertFalse(\Tyhp\Lazy::isLazy($article));
    }

    public function testListedModelsDeferTheirColumnsToo(): void
    {
        $this->connection->flushQueryLog();
        $this->context->lazyLoading();
        $articles = $this->context->articles->all();
        self::assertCount(3, $articles);
        if (!\Tyhp\Lazy::isLazy($articles[0])) {
            return;
        }

        self::assertCount(1, $this->queries());
        self::assertStringNotContainsString('body', \strtolower($this->queries()[0]));
        $this->connection->flushQueryLog();
        self::assertSame('Hello', $articles[0]->body);
        self::assertCount(1, $this->queries());
        self::assertStringContainsString('in (', \strtolower($this->queries()[0]));
        foreach ($articles as $article) {
            self::assertFalse(\Tyhp\Lazy::isLazy($article));
        }
        self::assertSame('Later', $articles[2]->body);
        self::assertCount(1, $this->queries());
    }

    public function testEagerLoadedModelsDeferTheirColumnsToo(): void
    {
        $this->context->lazyLoading();
        $this->context->authors->all();
        $this->connection->flushQueryLog();
        $loaded = $this->one(LazyScenarios::authorsWithArticles($this->context), 1);
        $first = $loaded->articles->item(0);
        if (!\Tyhp\Lazy::isLazy($first)) {
            self::assertSame('Hello', $first->body);

            return;
        }

        foreach ($this->queries() as $query) {
            self::assertStringNotContainsString('body', \strtolower($query));
        }
        self::assertSame('Hello', $first->body);
    }

    public function testChangingALoadedColumnSurvivesTheDeferredLoad(): void
    {
        $this->context->lazyLoading();
        $article = $this->context->articles->find(1);
        self::assertNotNull($article);
        $article->title = 'Mine';
        self::assertSame('Hello', $article->body);
        self::assertSame('Mine', $article->title);

        $this->context->tracker->detectChanges();
        self::assertSame(TrackingState::Dirty, $this->context->entry($article)->state);
        self::assertSame(1, $this->context->save());
        self::assertSame(
            'Mine',
            $this->connection->selectOne('select title from articles where id = ?', [1])->title,
        );
    }

    public function testChangingAColumnOfAGhostIsSavedWithoutLoadingTheDeferredOnes(): void
    {
        $this->context->lazyLoading();
        $article = $this->context->articles->find(1);
        self::assertNotNull($article);
        $lazy = \Tyhp\Lazy::isLazy($article);
        $article->title = 'Mine';

        $this->context->tracker->detectChanges();
        self::assertSame(TrackingState::Dirty, $this->context->entry($article)->state);
        self::assertSame(1, $this->context->save());
        self::assertSame(
            'Mine',
            $this->connection->selectOne('select title from articles where id = ?', [1])->title,
        );
        self::assertSame('Hello', $this->connection->selectOne('select body from articles where id = ?', [1])->body);
        if ($lazy) {
            self::assertTrue(\Tyhp\Lazy::isLazy($article));
            $this->context->tracker->detectChanges();
            self::assertSame(TrackingState::Clean, $this->context->entry($article)->state);
        }
    }

    public function testAMissingReferenceRowDoesNotBlockItsNeighbours(): void
    {
        $this->context->lazyLoading();
        $first = $this->context->articles->reference(1);
        $this->connection->flushQueryLog();
        try {
            $missing = $this->context->articles->reference(99);
        } catch (\Illuminate\Database\RecordsNotFoundException) {
            // An eager core build loads a reference at once.
            self::assertFalse(\Tyhp\Lazy::isLazy($first));

            return;
        }
        $third = $this->context->articles->reference(3);

        self::assertSame('First', $first->title);
        self::assertSame('Third', $third->title);
        try {
            $missing->title;
            self::fail('A missing row should throw.');
        } catch (\Illuminate\Database\RecordsNotFoundException) {
            self::assertTrue(\Tyhp\Lazy::isLazy($missing));
        }
    }

    public function testAFailedCollectionLoadCanBeRetried(): void
    {
        $this->context->lazyLoading();
        $ada = $this->one($this->context->authors->all(), 1);
        $this->connection->statement('alter table articles rename to articles_away');
        try {
            \count($ada->articles);
            self::fail('A missing table should throw.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertFalse($ada->articles->isLoaded());
        }

        $this->connection->statement('alter table articles_away rename to articles');
        self::assertCount(2, $ada->articles);
    }

    public function testRefreshingAnAuthorKeepsItsNavigations(): void
    {
        $this->context->lazyLoading();
        $ada = $this->one($this->context->authors->all(), 1);
        $this->context->entry($ada)->refresh();

        self::assertCount(2, $ada->articles);
        self::assertSame('First', $ada->articles->item(0)->title);
        self::assertNotNull($ada->profile);
        self::assertSame('Mathematician', $ada->profile->bio);
    }

    public function testExpiringAnAuthorKeepsItsNavigations(): void
    {
        $this->context->lazyLoading();
        $ada = $this->one($this->context->authors->all(), 1);
        $this->context->entry($ada)->expire();

        self::assertSame('Ada', $ada->name);
        self::assertCount(2, $ada->articles);
        self::assertNotNull($ada->profile);
        self::assertSame('Mathematician', $ada->profile->bio);
    }

    public function testTheFirstCollectionReadLoadsPendingCollectionsOfThatRelation(): void
    {
        $this->context->lazyLoading();
        $ada = $this->one($this->context->authors->all(), 1);
        $grace = $this->one($this->context->authors->all(), 2);
        self::assertFalse($ada->articles->isLoaded());
        self::assertFalse($grace->articles->isLoaded());
        $this->connection->flushQueryLog();

        $titles = [];
        foreach ($ada->articles as $article) {
            $titles[] = $article->title;
        }

        self::assertSame(['First', 'Second'], $titles);
        self::assertCount(1, $this->queries(), \json_encode($this->queries()));
        self::assertTrue($grace->articles->isLoaded());
        self::assertSame('Third', $grace->articles->item(0)->title);
        self::assertCount(1, $this->queries());
    }

    public function testBelongsToManyCollectionsBatchTheSameWay(): void
    {
        $this->context->lazyLoading();
        $relation = $this->context->mapping()->model(Author::class)->relation('labels');
        self::assertSame(RelationKind::BelongsToMany, $relation->kind);
        $ada = $this->one($this->context->authors->all(), 1);
        $grace = $this->one($this->context->authors->all(), 2);
        $this->connection->flushQueryLog();

        $names = [];
        foreach ($ada->labels as $label) {
            $names[] = $label->name;
        }

        self::assertSame(['Tyhp'], $names);
        self::assertTrue($grace->labels->isLoaded());
        self::assertSame('PHP', $grace->labels->item(0)->name);
    }

    public function testHasOneLoadsTheRelatedRow(): void
    {
        $this->context->lazyLoading();
        $author = $this->one($this->context->authors->all(), 1);
        self::assertNotNull($author->profile);
        if (\Tyhp\Lazy::isLazy($author->profile)) {
            $this->connection->flushQueryLog();
            self::assertSame('Mathematician', $author->profile->bio);
            self::assertCount(1, $this->queries());

            return;
        }

        self::assertSame('Mathematician', $author->profile->bio);
    }

    public function testRefreshUpdatesTheSameInstance(): void
    {
        $article = $this->context->articles->find(1);
        self::assertNotNull($article);
        $id = \spl_object_id($article);
        $this->connection->update('update articles set title = ? where id = ?', ['Revised', 1]);
        $this->context->entry($article)->refresh();

        self::assertSame($id, \spl_object_id($article));
        self::assertSame('Revised', $article->title);
        self::assertSame(TrackingState::Clean, $this->context->entry($article)->state);
    }

    public function testExpireReloadsTheSameInstance(): void
    {
        $article = $this->context->articles->find(1);
        self::assertNotNull($article);
        $id = \spl_object_id($article);
        $this->connection->update('update articles set title = ? where id = ?', ['Expired', 1]);
        $this->connection->flushQueryLog();
        $this->context->entry($article)->expire();
        if (\Tyhp\Lazy::isLazy($article)) {
            self::assertSame([], $this->queries());
            self::assertSame('Expired', $article->title);
            self::assertNotSame([], $this->queries());
        } else {
            self::assertSame('Expired', $article->title);
        }
        self::assertSame($id, \spl_object_id($article));
        self::assertSame(TrackingState::Clean, $this->context->entry($article)->state);
    }

    private function schema(): void
    {
        $authors = $this->context->mapping()->model(Author::class);
        $articles = $this->context->mapping()->model(Article::class);
        $labels = $this->context->mapping()->model(Label::class);
        $profiles = $this->context->mapping()->model(Profile::class);
        $articleLabels = $articles->relation('labels');
        $authorLabels = $authors->relation('labels');
        self::assertSame(RelationKind::HasMany, $authors->relation('articles')->kind);
        self::assertSame(RelationKind::HasOne, $authors->relation('profile')->kind);
        self::assertSame(RelationKind::BelongsTo, $articles->relation('author')->kind);
        self::assertNotNull($articleLabels->pivotTable);
        self::assertNotNull($articleLabels->pivotForeignKey);
        self::assertNotNull($articleLabels->pivotRelatedKey);
        self::assertNotNull($authorLabels->pivotTable);

        $authorKey = $authors->column('id')->name;
        $articleKey = $articles->column('id')->name;
        $labelKey = $labels->column('id')->name;
        $profileKey = $profiles->column('id')->name;
        $authorId = $articles->column('authorId')->name;
        $profileAuthor = $profiles->column('authorId')->name;

        $this->connection->statement('create table ' . $authors->table . ' (' . $authorKey . ' integer primary key, name text not null)');
        $this->connection->statement(
            'create table ' . $articles->table . ' ('
            . $articleKey . ' integer primary key, '
            . $authorId . ' integer not null, title text not null, body text not null)',
        );
        $this->connection->statement('create table ' . $labels->table . ' (' . $labelKey . ' integer primary key, name text not null)');
        $this->connection->statement(
            'create table ' . $profiles->table . ' ('
            . $profileKey . ' integer primary key, '
            . $profileAuthor . ' integer not null, bio text not null)',
        );
        $this->connection->statement(
            'create table ' . $articleLabels->pivotTable . ' ('
            . $articleLabels->pivotForeignKey . ' integer not null, '
            . $articleLabels->pivotRelatedKey . ' integer not null)',
        );
        $this->connection->statement(
            'create table ' . $authorLabels->pivotTable . ' ('
            . $authorLabels->pivotForeignKey . ' integer not null, '
            . $authorLabels->pivotRelatedKey . ' integer not null)',
        );
        $this->connection->statement("insert into " . $authors->table . " (" . $authorKey . ", name) values (1, 'Ada'), (2, 'Grace')");
        $this->connection->statement(
            "insert into " . $articles->table . " (" . $articleKey . ", " . $authorId . ", title, body) values "
            . "(1, 1, 'First', 'Hello'), (2, 1, 'Second', 'World'), (3, 2, 'Third', 'Later')",
        );
        $this->connection->statement("insert into " . $labels->table . " (" . $labelKey . ", name) values (1, 'Tyhp'), (2, 'PHP')");
        $this->connection->statement(
            "insert into " . $profiles->table . " (" . $profileKey . ", " . $profileAuthor . ", bio) values "
            . "(1, 1, 'Mathematician'), (2, 2, 'Admiral')",
        );
        $this->connection->statement(
            'insert into ' . $articleLabels->pivotTable . ' ('
            . $articleLabels->pivotForeignKey . ', ' . $articleLabels->pivotRelatedKey
            . ') values (1, 1), (1, 2), (2, 1)',
        );
        $this->connection->statement(
            'insert into ' . $authorLabels->pivotTable . ' ('
            . $authorLabels->pivotForeignKey . ', ' . $authorLabels->pivotRelatedKey
            . ') values (1, 1), (2, 2)',
        );
    }

    /**
     * @param array<int, object> $models
     */
    private function one(array $models, int $id): object
    {
        foreach ($models as $model) {
            if ($model->id === $id) {
                return $model;
            }
        }
        self::fail('Missing model ' . $id);
    }

    /**
     * @return array<int, string>
     */
    private function queries(): array
    {
        $queries = [];
        foreach ($this->connection->getQueryLog() as $entry) {
            if (\is_array($entry) && isset($entry['query']) && \is_string($entry['query'])) {
                $queries[] = $entry['query'];
            }
        }

        return $queries;
    }
}
