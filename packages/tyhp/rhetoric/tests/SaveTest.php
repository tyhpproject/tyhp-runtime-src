<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\Employee;
use Tyhp\Rhetoric\Fixtures\Folder;
use Tyhp\Rhetoric\Fixtures\KeyedNote;
use Tyhp\Rhetoric\Fixtures\Ring;
use Tyhp\Rhetoric\Fixtures\SaveBlog;
use Tyhp\Rhetoric\Fixtures\SaveContext;
use Tyhp\Rhetoric\Fixtures\SavePost;
use Tyhp\Rhetoric\Fixtures\SaveScenarios;
use Tyhp\Rhetoric\Fixtures\StampNote;
use Tyhp\Rhetoric\Mapping\CastSlot;
use Tyhp\Rhetoric\Mapping\ColumnMap;
use Tyhp\Rhetoric\Mapping\KeyMap;
use Tyhp\Rhetoric\Mapping\ModelMap;
use Tyhp\Rhetoric\Query\Hydrator;
use Tyhp\Rhetoric\Tracking\TrackingState;

final class SaveBlogRow extends Model
{
    protected $table = 'save_blogs';

    public $timestamps = false;

    protected $guarded = [];
}

final class ReadableNote
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $extra = null,
    ) {}
}

final class SaveTest extends TestCase
{
    private \Illuminate\Database\Connection $connection;

    private SaveContext $context;

    protected function setUp(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ], 'rhetoric');
        $capsule->bootEloquent();
        $capsule->setAsGlobal();
        $this->connection = $capsule->getConnection('rhetoric');
        $this->connection->statement('pragma foreign_keys = on');
        $this->connection->statement('create table save_blogs (id integer primary key, name text not null unique, url text null)');
        $this->connection->statement('create table save_posts (id integer primary key, blog_id integer not null references save_blogs(id), title text not null, slug text not null, rating integer not null)');
        $this->connection->statement('create table employees (id integer primary key, name text not null, manager_id integer null references employees(id))');
        $this->connection->statement('create table keyed_notes (id text primary key, body text not null)');
        $this->connection->statement('create table stamp_notes (id integer primary key, name text not null, created_at text null, updated_at text null)');
        $this->connection->statement('create table folders (id text primary key, name text not null, parent_id text null references folders(id))');
        $this->connection->statement('create table rings (id integer primary key, next_id integer not null references rings(id))');
        $this->connection->statement("insert into save_blogs (id, name, url) values (1, 'one', null)");
        $this->connection->statement("insert into save_posts (id, blog_id, title, slug, rating) values (1, 1, 'Alpha', 'alpha', 1), (2, 1, 'Beta', 'beta', 2), (3, 1, 'Gamma', 'gamma', 3)");
        $this->context = new SaveContext('rhetoric');
    }

    public function testInsertUpdateAndDeleteOneModel(): void
    {
        $blog = new SaveBlog();
        $blog->name = 'Second';
        $this->context->blogs->add($blog);
        self::assertSame(TrackingState::Added, $this->context->entry($blog)->state);
        self::assertSame(1, $this->context->save());
        self::assertGreaterThan(0, $blog->id);
        self::assertSame(TrackingState::Clean, $this->context->entry($blog)->state);
        self::assertFalse($this->context->entry($blog)->isDirty());

        $blog->url = 'https://example.test';
        $this->context->tracker->detectChanges();
        self::assertSame(TrackingState::Dirty, $this->context->entry($blog)->state);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        self::assertSame(1, $this->context->save());
        $sql = $this->queries();
        self::assertStringContainsString('url', $sql);
        self::assertStringNotContainsString('name', $sql);
        self::assertSame('https://example.test', $this->connection->table('save_blogs')->where('id', $blog->id)->value('url'));
        self::assertSame(TrackingState::Clean, $this->context->entry($blog)->state);

        $this->context->blogs->remove($blog);
        self::assertSame(TrackingState::Deleted, $this->context->entry($blog)->state);
        self::assertSame(1, $this->context->save());
        self::assertSame(TrackingState::Untracked, $this->context->entry($blog)->state);
        self::assertNull($this->connection->table('save_blogs')->where('id', $blog->id)->value('id'));
    }

    public function testGraphInsertPropagatesKeysAndOrdersByForeignKey(): void
    {
        $blog = new SaveBlog();
        $blog->name = 'Main';
        $post = new SavePost();
        $post->title = 'Hello';
        $post->slug = 'hello';
        $post->blog = $blog;
        $this->context->posts->add($post);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();

        self::assertSame($blog->id, $post->blogId);
        self::assertGreaterThan(0, $blog->id);
        self::assertSame($blog->id, (int) $this->connection->table('save_posts')->where('id', $post->id)->value('blog_id'));
        self::assertSame(TrackingState::Clean, $this->context->entry($blog)->state);
        self::assertSame(TrackingState::Clean, $this->context->entry($post)->state);
        $inserts = [];
        foreach ($this->connection->getQueryLog() as $entry) {
            $sql = \strtolower((string) ($entry['query'] ?? ''));
            if (!\str_starts_with($sql, 'insert')) {
                continue;
            }
            if (\str_contains($sql, 'save_blogs')) {
                $inserts[] = 'blogs';
            }
            if (\str_contains($sql, 'save_posts')) {
                $inserts[] = 'posts';
            }
        }
        self::assertSame(['blogs', 'posts'], $inserts);
    }

    public function testGraphDeleteRemovesChildrenFirst(): void
    {
        $blog = $this->context->blogs->find(1);
        self::assertNotNull($blog);
        $this->connection->table('save_posts')->whereIn('id', [2, 3])->delete();
        $post = $this->context->posts->find(1);
        self::assertNotNull($post);
        $this->context->posts->remove($post);
        $this->context->blogs->remove($blog);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();
        $deletes = [];
        foreach ($this->connection->getQueryLog() as $entry) {
            $sql = \strtolower((string) ($entry['query'] ?? ''));
            if (!\str_starts_with($sql, 'delete')) {
                continue;
            }
            if (\str_contains($sql, 'save_posts')) {
                $deletes[] = 'posts';
            }
            if (\str_contains($sql, 'save_blogs')) {
                $deletes[] = 'blogs';
            }
        }
        self::assertSame(['posts', 'blogs'], $deletes);
        self::assertSame(0, (int) $this->connection->table('save_posts')->where('id', 1)->count());
        self::assertSame(0, (int) $this->connection->table('save_blogs')->where('id', 1)->count());
    }

    public function testNullableForeignKeyCycleInsertsThenUpdates(): void
    {
        $left = new Employee();
        $left->name = 'left';
        $right = new Employee();
        $right->name = 'right';
        $left->manager = $right;
        $right->manager = $left;
        $this->context->employees->addMany([$left, $right]);
        $this->context->save();

        self::assertGreaterThan(0, $left->id);
        self::assertGreaterThan(0, $right->id);
        self::assertSame($right->id, $left->managerId);
        self::assertSame($left->id, $right->managerId);
        self::assertSame($right->id, (int) $this->connection->table('employees')->where('id', $left->id)->value('manager_id'));
        self::assertSame($left->id, (int) $this->connection->table('employees')->where('id', $right->id)->value('manager_id'));
    }

    public function testSelfReferenceStoresTheGeneratedKeyOfItsOwnRow(): void
    {
        $solo = new Employee();
        $solo->name = 'solo';
        $solo->manager = $solo;
        $this->context->employees->add($solo);
        $this->context->save();

        self::assertGreaterThan(0, $solo->id);
        self::assertSame($solo->id, $solo->managerId);
        self::assertSame($solo->id, (int) $this->connection->table('employees')->where('id', $solo->id)->value('manager_id'));
        self::assertSame(TrackingState::Clean, $this->context->entry($solo)->state);
    }

    public function testNonNullableForeignKeyCycleThrowsAndWritesNothing(): void
    {
        $first = new Ring();
        $second = new Ring();
        $first->next = $second;
        $second->next = $first;
        $this->context->rings->addMany([$first, $second]);
        try {
            $this->context->save();
            self::fail('A cycle without a nullable foreign key cannot be saved.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('cycle', $error->getMessage());
        }
        self::assertSame(0, (int) $this->connection->table('rings')->count());
        self::assertSame(0, $first->id);
        self::assertSame(0, $second->id);
        self::assertSame(TrackingState::Added, $this->context->entry($first)->state);
        self::assertSame(TrackingState::Added, $this->context->entry($second)->state);
    }

    public function testNonNullableSelfReferenceWithAGeneratedKeyThrowsAndWritesNothing(): void
    {
        $this->connection->statement('pragma foreign_keys = off');
        $ring = new Ring();
        $ring->next = $ring;
        $this->context->rings->add($ring);
        try {
            $this->context->save();
            self::fail('A non-nullable self reference cannot get its own generated key.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('cycle', $error->getMessage());
        }
        self::assertSame(0, (int) $this->connection->table('rings')->count());
        self::assertSame(0, $ring->id);
        self::assertSame(TrackingState::Added, $this->context->entry($ring)->state);
    }

    public function testSelfReferenceWithAClientKeyCopiesItsOwnKey(): void
    {
        $folder = new Folder();
        $folder->id = (string) \Illuminate\Support\Str::uuid();
        $folder->name = 'root';
        $folder->parent = $folder;
        $this->context->folders->add($folder);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();

        self::assertSame($folder->id, $folder->parentId);
        self::assertSame($folder->id, $this->connection->table('folders')->where('id', $folder->id)->value('parent_id'));
        self::assertStringNotContainsString('update', $this->queries());
    }

    public function testClientKeyedChildIsNotBatchedWithItsPrincipal(): void
    {
        $parent = new Folder();
        $parent->id = (string) \Illuminate\Support\Str::uuid();
        $parent->name = 'parent';
        $child = new Folder();
        $child->id = (string) \Illuminate\Support\Str::uuid();
        $child->name = 'child';
        $child->parent = $parent;
        $this->context->folders->addMany([$child, $parent]);
        $this->context->save();

        self::assertSame($parent->id, $child->parentId);
        self::assertSame($parent->id, $this->connection->table('folders')->where('id', $child->id)->value('parent_id'));
    }

    public function testNewDependentTakesTheKeyOfATrackedExistingPrincipal(): void
    {
        $blog = $this->context->blogs->findOrFail(1);
        $post = new SavePost();
        $post->title = 'Fresh';
        $post->slug = 'fresh';
        $post->blog = $blog;
        $this->context->posts->add($post);
        $this->context->save();

        self::assertSame(1, $post->blogId);
        self::assertSame(1, (int) $this->connection->table('save_posts')->where('id', $post->id)->value('blog_id'));
    }

    public function testFindReadsTheIdentityMapBeforeTheDatabase(): void
    {
        $first = $this->context->posts->findOrFail(1);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $again = $this->context->posts->find(1);
        $viaString = $this->context->posts->find('1');
        self::assertSame($first, $again);
        self::assertSame($first, $viaString);
        self::assertSame([], $this->connection->getQueryLog());
    }

    public function testFindIgnoresAModelThatWasRemoved(): void
    {
        $post = $this->context->posts->findOrFail(1);
        $this->context->posts->remove($post);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        self::assertNull($this->context->posts->find(1));
        self::assertNull($this->context->posts->find('1'));
        try {
            $this->context->posts->findOrFail(1);
            self::fail('findOrFail must throw for a removed model.');
        } catch (\Illuminate\Database\RecordsNotFoundException $error) {
            self::assertInstanceOf(\Illuminate\Database\RecordsNotFoundException::class, $error);
        }
        self::assertSame([], $this->connection->getQueryLog());
        $this->context->save();
        self::assertNull($this->context->posts->find(1));
    }

    public function testNonIntegerStringDoesNotMatchAnIntegerKey(): void
    {
        $this->context->posts->findOrFail(1);
        self::assertNull($this->context->posts->find('1.5'));
    }

    public function testOnlyAnExactIntegerStringMatchesATrackedIntegerKey(): void
    {
        $post = $this->context->posts->findOrFail(1);
        $map = $this->context->mapping()->model(SavePost::class);
        $tracker = $this->context->tracker;

        self::assertSame($post, $tracker->stored($map, [1]));
        self::assertSame($post, $tracker->stored($map, ['1']));
        self::assertSame($post, $tracker->stored($map, ['01']));
        self::assertNull($tracker->stored($map, ["1\n"]));
        self::assertNull($tracker->stored($map, ['1.5']));
        self::assertNull($tracker->stored($map, ['1.0']));
        self::assertNull($tracker->stored($map, [' 1']));
        self::assertNull($tracker->stored($map, ['-1']));
        self::assertNull($tracker->stored($map, ['']));
        self::assertNull($tracker->stored($map, ['18446744073709551617']));
    }

    public function testAnOversizedIntegerStringDoesNotMatchTheLargestIntegerKey(): void
    {
        $this->connection->statement("insert into save_blogs (id, name, url) values (" . \PHP_INT_MAX . ", 'largest', null)");
        $blog = $this->context->blogs->findOrFail(\PHP_INT_MAX);
        $map = $this->context->mapping()->model(SaveBlog::class);
        $tracker = $this->context->tracker;

        self::assertSame($blog, $tracker->stored($map, [(string) \PHP_INT_MAX]));
        self::assertNull($tracker->stored($map, ['99999999999999999999']));
    }

    public function testANegativeIntegerStringMatchesATrackedNegativeIntegerKey(): void
    {
        $this->connection->statement("insert into save_blogs (id, name, url) values (-1, 'minus', null)");
        $this->connection->statement("insert into save_blogs (id, name, url) values (" . \PHP_INT_MIN . ", 'smallest', null)");
        $minus = $this->context->blogs->findOrFail(-1);
        $smallest = $this->context->blogs->findOrFail(\PHP_INT_MIN);
        $one = $this->context->blogs->findOrFail(1);
        $map = $this->context->mapping()->model(SaveBlog::class);
        $tracker = $this->context->tracker;

        self::assertSame($minus, $tracker->stored($map, [-1]));
        self::assertSame($minus, $tracker->stored($map, ['-1']));
        self::assertSame($minus, $tracker->stored($map, ['-001']));
        self::assertSame($one, $tracker->stored($map, ['1']));
        self::assertNull($tracker->stored($map, ['--1']));
        self::assertNull($tracker->stored($map, ['+1']));
        self::assertNull($tracker->stored($map, ['-1.0']));
        self::assertNull($tracker->stored($map, ["-1\n"]));
        self::assertSame($smallest, $tracker->stored($map, [(string) \PHP_INT_MIN]));
        self::assertNull($tracker->stored($map, ['-9223372036854775809']));
        self::assertNull($tracker->stored($map, ['-99999999999999999999']));
    }

    public function testStringKeysThatLookNumericStayDistinct(): void
    {
        $this->connection->table('keyed_notes')->insert([
            ['id' => '007', 'body' => 'bond'],
            ['id' => '7', 'body' => 'seven'],
        ]);
        $bond = $this->context->notes->findOrFail('007');
        $seven = $this->context->notes->findOrFail('7');
        self::assertNotSame($bond, $seven);
        self::assertSame('bond', $bond->body);
        self::assertSame('seven', $seven->body);
        self::assertSame($bond, $this->context->notes->findOrFail('007'));
    }

    public function testSaveSucceedsAfterAFailedAttemptIsCorrected(): void
    {
        $first = new SaveBlog();
        $first->name = 'again';
        $second = new SaveBlog();
        $second->name = 'again';
        $this->context->blogs->addMany([$first, $second]);
        try {
            $this->context->save();
            self::fail('The unique name should fail the save.');
        } catch (\Throwable $error) {
            self::assertNotSame('', $error->getMessage());
        }
        $second->name = 'again-two';
        self::assertSame(2, $this->context->save());
        self::assertGreaterThan(0, $first->id);
        self::assertGreaterThan(0, $second->id);
        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, (int) $this->connection->table('save_blogs')->whereIn('name', ['again', 'again-two'])->count());
    }

    public function testClientGeneratedKeysBatchIntoOneInsert(): void
    {
        $first = new KeyedNote();
        $first->id = (string) \Illuminate\Support\Str::uuid();
        $first->body = 'one';
        $second = new KeyedNote();
        $second->id = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $second->body = 'two';
        $this->context->notes->addMany([$first, $second]);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();

        $inserts = 0;
        foreach ($this->connection->getQueryLog() as $entry) {
            $sql = \strtolower((string) ($entry['query'] ?? ''));
            if (\str_starts_with($sql, 'insert') && \str_contains($sql, 'keyed_notes')) {
                $inserts++;
                self::assertCount(4, $entry['bindings'] ?? []);
            }
        }
        self::assertSame(1, $inserts);
        self::assertSame(2, (int) $this->connection->table('keyed_notes')->count());
    }

    public function testFailureRollsBackAndLeavesTrackingState(): void
    {
        $first = new SaveBlog();
        $first->name = 'dup';
        $second = new SaveBlog();
        $second->name = 'dup';
        $this->context->blogs->addMany([$first, $second]);
        try {
            $this->context->save();
            self::fail('The unique name should fail the save.');
        } catch (\Throwable $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertSame(TrackingState::Added, $this->context->entry($first)->state);
        self::assertSame(TrackingState::Added, $this->context->entry($second)->state);
        self::assertSame(0, $first->id);
        self::assertSame(0, $second->id);
        self::assertSame(0, (int) $this->connection->table('save_blogs')->where('name', 'dup')->count());
    }

    public function testSaveJoinsAnOuterTransactionWithTheQueryBuilderAndEloquent(): void
    {
        $this->connection->beginTransaction();
        $level = $this->connection->transactionLevel();
        $blog = new SaveBlog();
        $blog->name = 'joined';
        $this->context->blogs->add($blog);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();
        self::assertSame($level, $this->connection->transactionLevel());
        foreach ($this->connection->getQueryLog() as $entry) {
            $sql = \strtolower((string) ($entry['query'] ?? ''));
            self::assertStringNotContainsString('savepoint', $sql);
            self::assertStringNotContainsString('begin', $sql);
        }
        $this->connection->table('save_blogs')->insert(['name' => 'from-qb', 'url' => null]);
        $eloquent = new SaveBlogRow();
        $eloquent->setConnection('rhetoric');
        $eloquent->name = 'from-eloquent';
        $eloquent->save();
        $this->connection->rollBack();
        self::assertSame(0, (int) $this->connection->table('save_blogs')->whereIn('name', ['joined', 'from-qb', 'from-eloquent'])->count());

        $this->connection->transaction(function (): void {
            $this->connection->table('save_blogs')->insert(['name' => 'from-qb', 'url' => null]);
            $blog = new SaveBlog();
            $blog->name = 'from-rhetoric';
            $this->context->blogs->add($blog);
            $this->context->save();
            $eloquent = new SaveBlogRow();
            $eloquent->setConnection('rhetoric');
            $eloquent->name = 'from-eloquent';
            $eloquent->save();
        });
        self::assertSame(3, (int) $this->connection->table('save_blogs')->whereIn('name', ['from-qb', 'from-rhetoric', 'from-eloquent'])->count());

        try {
            $this->connection->transaction(function (): void {
                $this->connection->table('save_blogs')->insert(['name' => 'rolled-qb', 'url' => null]);
                $blog = new SaveBlog();
                $blog->name = 'rolled-rhetoric';
                $this->context->blogs->add($blog);
                $this->context->save();
                throw new \RuntimeException('rollback');
            });
            self::fail('The outer transaction should roll back.');
        } catch (\RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }
        self::assertSame(0, (int) $this->connection->table('save_blogs')->whereIn('name', ['rolled-qb', 'rolled-rhetoric'])->count());
    }

    public function testTimestampsUseEloquentColumnNames(): void
    {
        self::assertTrue($this->context->mapping()->model(StampNote::class)->timestamps);
        $note = new StampNote();
        $note->name = 'first';
        $this->context->stampNotes->add($note);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();
        self::assertNotSame('', (string) $note->createdAt);
        self::assertSame($note->createdAt, $note->updatedAt);
        $insert = $this->queries();
        self::assertStringContainsString('created_at', $insert);
        self::assertStringContainsString('updated_at', $insert);
        $created = $note->createdAt;
        $note->name = 'second';
        $this->connection->flushQueryLog();
        $this->context->save();
        $update = $this->queries();
        self::assertStringContainsString('updated_at', $update);
        self::assertStringContainsString('name', $update);
        self::assertStringNotContainsString('created_at', $update);
        self::assertSame($created, $note->createdAt);
        self::assertSame($note->updatedAt, $this->connection->table('stamp_notes')->where('id', $note->id)->value('updated_at'));
    }

    public function testIdentityMapFindTrackingAndStreaming(): void
    {
        self::assertTrue(SaveScenarios::sameTracked($this->context, 1));
        $tracked = $this->context->posts->find(1);
        self::assertNotNull($tracked);
        $tracked->title = 'changed';
        $again = $this->context->posts->find(1);
        self::assertSame($tracked, $again);
        self::assertSame('changed', $again->title);
        self::assertSame('Alpha', SaveScenarios::originalTitle($this->context, $tracked));
        self::assertTrue(SaveScenarios::titleDirty($this->context, $tracked));
        self::assertFalse(SaveScenarios::slugDirty($this->context, $tracked));

        $fresh = SaveScenarios::untrackedFirst($this->context);
        self::assertNotNull($fresh);
        self::assertNotSame($tracked, $fresh);
        self::assertSame(TrackingState::Untracked, $this->context->entry($fresh)->state);

        $copies = SaveScenarios::duplicated($this->context);
        self::assertCount(2, $copies);
        self::assertSame($copies[0], $copies[1]);
        self::assertSame(TrackingState::Untracked, $this->context->entry($copies[0])->state);

        $ids = [];
        $continued = $this->context->posts->untracked()->chunk(2, function (array $posts) use (&$ids): bool {
            foreach ($posts as $post) {
                $ids[] = $post->id;
                self::assertSame(TrackingState::Untracked, $this->context->entry($post)->state);
            }

            return true;
        });
        self::assertTrue($continued);
        self::assertEqualsCanonicalizing([1, 2, 3], $ids);

        $byId = [];
        $this->context->posts->chunkById(2, function (array $posts) use (&$byId): bool {
            foreach ($posts as $post) {
                $byId[] = $post->id;
                self::assertSame(TrackingState::Clean, $this->context->entry($post)->state);
            }

            return true;
        });
        self::assertSame([1, 2, 3], $byId);

        $cursor = [];
        foreach ($this->context->posts->cursor() as $post) {
            $cursor[] = $post->id;
        }
        self::assertEqualsCanonicalizing([1, 2, 3], $cursor);

        $lazy = [];
        foreach ($this->context->posts->lazy(2) as $post) {
            $lazy[] = $post->id;
        }
        self::assertEqualsCanonicalizing([1, 2, 3], $lazy);

        $this->context->entry($tracked)->refresh();
        self::assertSame('Alpha', $tracked->title);
        self::assertSame(TrackingState::Clean, $this->context->entry($tracked)->state);

        $this->context->tracker->clear();
        self::assertSame(TrackingState::Untracked, $this->context->entry($tracked)->state);
        $reloaded = $this->context->posts->findOrFail(2);
        self::assertSame(2, $reloaded->id);
        $this->context->tracker->clear();
        $detached = SaveScenarios::untrackedFirst($this->context);
        self::assertNotNull($detached);
        $this->context->posts->trackAsDirty($detached);
        self::assertSame(TrackingState::Dirty, $this->context->entry($detached)->state);
        self::assertTrue(SaveScenarios::slugDirty($this->context, $detached));
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();
        $forced = $this->queries();
        self::assertStringContainsString('slug', $forced);
        self::assertStringContainsString('title', $forced);
    }

    public function testUpdateWritesOnlyChangedColumns(): void
    {
        $post = $this->context->posts->findOrFail(2);
        $post->title = 'Updated';
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();
        $sql = $this->queries();
        self::assertStringContainsString('title', $sql);
        self::assertStringNotContainsString('slug', $sql);
        self::assertStringNotContainsString('rating', $sql);
        self::assertSame('Updated', $this->connection->table('save_posts')->where('id', 2)->value('title'));
    }

    public function testRawSqlEscapeHatchesAndTransactionReturn(): void
    {
        self::assertSame(4, SaveScenarios::transactionValue($this->context));
        self::assertTrue($this->context->statement('update save_blogs set url = ? where id = ?', ['https://tyhp.test', 1]));
        self::assertSame(1, $this->context->affectingStatement('update save_blogs set url = ? where id = ?', ['https://tyhp.test/2', 1]));
        $rows = SaveScenarios::rawBlogs($this->context);
        self::assertSame('one', $rows[0]->name);
    }

    public function testConstructorBindingAndCastSlot(): void
    {
        $slot = new CastSlot(
            static fn (mixed $value): mixed => 'in:' . $value,
            static fn (mixed $value): mixed => 'out:' . $value,
        );
        $columns = [
            'id' => new ColumnMap('id', 'id', 'int', false, null, null, null, true, null),
            'name' => new ColumnMap('name', 'name', 'string', false, null, null, null, false, null, $slot),
        ];
        $map = new ModelMap(
            ReadableNote::class,
            'readable_notes',
            null,
            $columns,
            new KeyMap(['id'], ['id'], true),
            [],
            [],
            [],
            [],
        );
        $model = Hydrator::model($map, (object) ['id' => '4', 'name' => 'Ada']);
        self::assertInstanceOf(ReadableNote::class, $model);
        self::assertSame(4, $model->id);
        self::assertSame('in:Ada', $model->name);
        self::assertNull($model->extra);
        self::assertSame('out:Ada', \Tyhp\Rhetoric\Tracking\PropertyBag::database($columns['name'], 'Ada'));
    }

    public function testDeletedRowsOfOneTableShareAStatement(): void
    {
        $first = new SaveBlog();
        $first->name = 'batch-one';
        $second = new SaveBlog();
        $second->name = 'batch-two';
        $this->context->blogs->addMany([$first, $second]);
        $this->context->save();
        $this->context->blogs->remove($first);
        $this->context->blogs->remove($second);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();

        $deletes = 0;
        foreach ($this->connection->getQueryLog() as $entry) {
            $sql = \strtolower((string) ($entry['query'] ?? ''));
            if (\str_starts_with($sql, 'delete') && \str_contains($sql, 'save_blogs')) {
                $deletes++;
                self::assertCount(2, $entry['bindings'] ?? []);
            }
        }
        self::assertSame(1, $deletes);
        self::assertSame(0, (int) $this->connection->table('save_blogs')->whereIn('name', ['batch-one', 'batch-two'])->count());
    }

    public function testLargeInsertsStayWithinTheParameterLimit(): void
    {
        $notes = [];
        for ($i = 0; $i < 451; $i++) {
            $note = new KeyedNote();
            $note->id = 'n' . $i;
            $note->body = 'body';
            $notes[] = $note;
        }
        $this->context->notes->addMany($notes);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();

        $inserts = 0;
        foreach ($this->connection->getQueryLog() as $entry) {
            $sql = \strtolower((string) ($entry['query'] ?? ''));
            if (!\str_starts_with($sql, 'insert') || !\str_contains($sql, 'keyed_notes')) {
                continue;
            }
            $inserts++;
            self::assertLessThanOrEqual(900, \count($entry['bindings'] ?? []));
        }
        self::assertSame(2, $inserts);
        self::assertSame(451, (int) $this->connection->table('keyed_notes')->count());
    }

    public function testLargeDeletesStayWithinTheParameterLimitAndRemoveEveryRow(): void
    {
        $notes = [];
        for ($i = 0; $i < 1000; $i++) {
            $note = new KeyedNote();
            $note->id = 'n' . $i;
            $note->body = 'body';
            $notes[] = $note;
        }
        $this->context->notes->addMany($notes);
        $this->context->save();
        $this->connection->table('keyed_notes')->insert(['id' => 'kept', 'body' => 'kept']);
        $this->context->notes->removeMany($notes);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        $this->context->save();

        $deletes = 0;
        $bound = 0;
        foreach ($this->connection->getQueryLog() as $entry) {
            $sql = \strtolower((string) ($entry['query'] ?? ''));
            if (!\str_starts_with($sql, 'delete') || !\str_contains($sql, 'keyed_notes')) {
                continue;
            }
            $deletes++;
            $bound += \count($entry['bindings'] ?? []);
            self::assertLessThanOrEqual(900, \count($entry['bindings'] ?? []));
        }
        self::assertSame(2, $deletes);
        self::assertSame(1000, $bound);
        self::assertSame(['kept'], $this->connection->table('keyed_notes')->pluck('id')->all());
    }

    private function queries(): string
    {
        $sql = '';
        foreach ($this->connection->getQueryLog() as $entry) {
            $sql .= ' ' . \strtolower((string) ($entry['query'] ?? ''));
        }

        return $sql;
    }
}
