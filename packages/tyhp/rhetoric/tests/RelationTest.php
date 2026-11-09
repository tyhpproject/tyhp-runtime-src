<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\Graph\Blog;
use Tyhp\Rhetoric\Fixtures\Graph\BlogContext;
use Tyhp\Rhetoric\Fixtures\Graph\Post;
use Tyhp\Rhetoric\Fixtures\Graph\PostTag;
use Tyhp\Rhetoric\Fixtures\Graph\RelScenarios;
use Tyhp\Rhetoric\Fixtures\Graph\Tag;
use Tyhp\Rhetoric\Mapping\RelationKind;
use Tyhp\Rhetoric\OnDelete;
use Tyhp\Rhetoric\Tracking\TrackingState;

final class RelationTest extends TestCase
{
    private \Illuminate\Database\Connection $connection;

    private BlogContext $context;

    protected function setUp(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ], 'graph');
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
        $capsule->bootEloquent();
        $this->connection = $capsule->getConnection('graph');
        $this->connection->statement('pragma foreign_keys = on');
        $this->connection->statement('create table blogs (id integer primary key, name text not null)');
        $this->connection->statement('create table posts (id integer primary key, blog_id integer not null references blogs(id), title text not null)');
        $this->connection->statement('create table tags (id integer primary key, name text not null)');
        $this->connection->statement('create table post_tag (post_id integer not null references posts(id), tag_id integer not null references tags(id), primary key (post_id, tag_id))');
        $this->connection->statement("insert into blogs (id, name) values (1, 'News'), (2, 'Quiet')");
        $this->connection->statement("insert into posts (id, blog_id, title) values (1, 1, 'Alpha'), (2, 1, 'Beta'), (3, 2, 'Gamma')");
        $this->connection->statement("insert into tags (id, name) values (1, 'Laravel'), (2, 'Tyhp')");
        $this->connection->statement('insert into post_tag (post_id, tag_id) values (1, 1), (1, 2), (2, 1)');
        $this->context = new BlogContext('graph');
    }

    public function testReferenceNavigationsJoinAndCollectionsBecomeSubqueries(): void
    {
        foreach (['sqlite', 'mysql', 'pgsql'] as $driver) {
            $context = new BlogContext($driver);
            $quote = $driver === 'mysql' ? '`' : '"';
            $q = static fn (string $name): string => $quote . $name . $quote;
            $posts = $q('posts');
            $blogs = $q('blogs');
            $alias = $q('rhetoric_0');
            $columns = $posts . '.' . $q('id') . ' as ' . $q('id')
                . ', ' . $posts . '.' . $q('blog_id') . ' as ' . $q('blog_id')
                . ', ' . $posts . '.' . $q('title') . ' as ' . $q('title');
            $join = 'inner join ' . $blogs . ' as ' . $alias
                . ' on ' . $posts . '.' . $q('blog_id') . ' = ' . $alias . '.' . $q('id');

            $named = RelScenarios::blogName($context, 'News');
            self::assertSame(
                'select ' . $columns . ' from ' . $posts . ' ' . $join . ' where ' . $alias . '.' . $q('name') . ' = ?',
                $named->sql,
            );
            self::assertSame(['News'], $named->bindings);

            $ordered = RelScenarios::orderByBlog($context);
            self::assertSame(
                'select ' . $columns . ' from ' . $posts . ' ' . $join . ' order by ' . $alias . '.' . $q('name') . ' asc',
                $ordered->sql,
            );

            $selected = RelScenarios::selectBlogName($context);
            self::assertSame(
                'select ' . $alias . '.' . $q('name') . ' as ' . $q('scalar') . ' from ' . $posts . ' ' . $join,
                $selected->sql,
            );

            $sq = $q('rhetoric_sq');
            $exists = RelScenarios::postsExist($context);
            self::assertSame(
                'select * from ' . $blogs
                . ' where exists (select 1 from ' . $posts . ' as ' . $sq
                . ' where ' . $sq . '.' . $q('blog_id') . ' = ' . $blogs . '.' . $q('id') . ')',
                $exists->sql,
            );

            $counted = RelScenarios::postsCounted($context);
            self::assertSame(
                'select * from ' . $blogs
                . ' where (select count(*) from ' . $posts . ' as ' . $sq
                . ' where ' . $sq . '.' . $q('blog_id') . ' = ' . $blogs . '.' . $q('id') . ') > ?',
                $counted->sql,
            );
            self::assertSame([0], $counted->bindings);

            $tags = RelScenarios::tagsExist($context);
            self::assertSame(
                'select * from ' . $posts
                . ' where exists (select 1 from ' . $q('post_tag') . ' as ' . $sq
                . ' where ' . $sq . '.' . $q('post_id') . ' = ' . $posts . '.' . $q('id') . ')',
                $tags->sql,
            );
        }
    }

    public function testQueriesReturnTheJoinedAndFilteredRows(): void
    {
        $named = RelScenarios::postsNamed($this->context, 'News');
        $titles = $named->pluck('title')->all();
        \sort($titles);
        self::assertSame(['Alpha', 'Beta'], $titles);

        $withPosts = RelScenarios::blogsWithPosts($this->context)->pluck('name')->all();
        \sort($withPosts);
        self::assertSame(['News', 'Quiet'], $withPosts);

        $counted = RelScenarios::blogsWithCountedPosts($this->context)->pluck('name')->all();
        \sort($counted);
        self::assertSame(['News', 'Quiet'], $counted);
    }

    public function testEagerLoadAndExplicitLoadFixUpTheGraph(): void
    {
        $blogs = RelScenarios::loadGraph($this->context);
        $news = null;
        foreach ($blogs as $blog) {
            if ($blog->name === 'News') {
                $news = $blog;
            }
        }
        self::assertInstanceOf(Blog::class, $news);
        self::assertCount(2, $news->posts);
        $alpha = null;
        foreach ($news->posts as $post) {
            self::assertSame($news, $post->blog);
            if ($post->title === 'Alpha') {
                $alpha = $post;
            }
        }
        self::assertInstanceOf(Post::class, $alpha);
        self::assertCount(2, $alpha->tags);
        $tag = null;
        foreach ($alpha->tags as $candidate) {
            if ($candidate->name === 'Tyhp') {
                $tag = $candidate;
            }
        }
        self::assertInstanceOf(Tag::class, $tag);
        self::assertInstanceOf(PostTag::class, $tag->pivot);
        self::assertSame($alpha->id, $tag->pivot->postId);
        self::assertSame($tag->id, $tag->pivot->tagId);

        $again = $this->context->tags->find($tag->id);
        self::assertSame($tag, $again);

        $loose = RelScenarios::untrackedPost($this->context);
        self::assertInstanceOf(Post::class, $loose);
        self::assertInstanceOf(Blog::class, $loose->blog);
        self::assertSame(TrackingState::Untracked, $this->context->entry($loose)->state);
        self::assertSame(TrackingState::Untracked, $this->context->entry($loose->blog)->state);

        $loaded = RelScenarios::loadOne($this->context, 1);
        self::assertInstanceOf(Post::class, $loaded);
        self::assertCount(2, $loaded->tags);
        $this->connection->flushQueryLog();
        $this->connection->enableQueryLog();
        RelScenarios::loadMissingTags($this->context, $loaded);
        self::assertSame([], $this->connection->getQueryLog());
        RelScenarios::loadTags($this->context, $loaded);
        self::assertNotSame([], $this->connection->getQueryLog());
    }

    public function testSaveWritesForeignKeysPivotRowsAndDeletesRemovedDependents(): void
    {
        $relation = $this->context->mapping()->model(Post::class)->relation('tags');
        self::assertSame(RelationKind::BelongsToMany, $relation->kind);
        self::assertSame(PostTag::class, $relation->pivotClass);
        self::assertSame('post_tag', $relation->pivotTable);
        self::assertSame('post_id', $relation->pivotForeignKey);
        self::assertSame('tag_id', $relation->pivotRelatedKey);
        self::assertSame(OnDelete::Restrict, $relation->onDelete);

        $news = $this->context->blogs->find(1);
        self::assertInstanceOf(Blog::class, $news);
        $added = new Post();
        $added->title = 'Delta';
        $news->posts = [$added];
        $this->context->save();
        self::assertSame(1, $added->blogId);
        self::assertSame(4, (int) $this->connection->table('posts')->count());

        $fresh = new Post();
        $fresh->title = 'Linked';
        $fresh->blog = $news;
        $this->context->posts->add($fresh);
        $this->context->save();
        self::assertSame(1, $fresh->blogId);

        $alpha = $this->context->posts->find(1);
        self::assertInstanceOf(Post::class, $alpha);
        $other = new Blog();
        $other->name = 'Other';
        $this->context->blogs->add($other);
        $this->context->save();
        $alpha->blog = $other;
        $this->context->tracker->detectChanges();
        self::assertSame(TrackingState::Dirty, $this->context->entry($alpha)->state);
        self::assertSame($other->id, $alpha->blogId);
        $this->context->save();
        $stored = $this->connection->table('posts')->where('id', '=', 1)->first();
        self::assertNotNull($stored);
        self::assertSame($other->id, (int) $stored->blog_id);

        $post = $this->context->posts->find(2);
        self::assertInstanceOf(Post::class, $post);
        RelScenarios::loadTags($this->context, $post);
        $tag = $this->context->tags->find(2);
        self::assertInstanceOf(Tag::class, $tag);
        $post->tags = [$tag];
        $this->context->save();
        self::assertSame(1, (int) $this->connection->table('post_tag')->where('post_id', '=', 2)->count());
        self::assertSame(2, (int) $this->connection->table('post_tag')->where('post_id', '=', 2)->value('tag_id'));
        $post->tags = [];
        $this->context->save();
        self::assertSame(0, (int) $this->connection->table('post_tag')->where('post_id', '=', 2)->count());
        self::assertNotNull($this->context->tags->find(2));

        $graph = RelScenarios::loadGraph($this->context);
        $quiet = null;
        foreach ($graph as $blog) {
            if ($blog->name === 'Quiet') {
                $quiet = $blog;
            }
        }
        self::assertInstanceOf(Blog::class, $quiet);
        self::assertNotSame([], $quiet->posts);
        $quiet->posts = [];
        $this->context->save();
        self::assertSame(0, (int) $this->connection->table('posts')->where('blog_id', '=', $quiet->id)->count());

        $doomed = $this->context->blogs->find(1);
        self::assertInstanceOf(Blog::class, $doomed);
        $this->context->blogs->remove($doomed);
        try {
            $this->context->save();
            self::fail('A principal with dependents is not cascaded.');
        } catch (\Throwable $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertGreaterThan(0, (int) $this->connection->table('posts')->where('blog_id', '=', 1)->count());
    }

    public function testNewPostWithTagsAssignedBeforeAddWritesPivotRows(): void
    {
        $news = $this->context->blogs->find(1);
        self::assertInstanceOf(Blog::class, $news);
        $tag = $this->context->tags->find(2);
        self::assertInstanceOf(Tag::class, $tag);

        $post = new Post();
        $post->title = 'Fresh';
        $post->blog = $news;
        $post->tags = [$tag];
        $this->context->posts->add($post);
        $this->context->save();

        self::assertGreaterThan(0, $post->id);
        self::assertSame(
            1,
            (int) $this->connection->table('post_tag')->where('post_id', '=', $post->id)->where('tag_id', '=', 2)->count(),
        );

        $nested = new Post();
        $nested->title = 'Nested';
        $nested->tags = [$tag];
        $news->posts = [$nested];
        $this->context->save();
        self::assertSame(
            1,
            (int) $this->connection->table('post_tag')->where('post_id', '=', $nested->id)->where('tag_id', '=', 2)->count(),
        );
    }

    public function testMovingALoadedPostBetweenBlogsKeepsTheRow(): void
    {
        $graph = RelScenarios::loadGraph($this->context);
        $news = null;
        $quiet = null;
        foreach ($graph as $blog) {
            if ($blog->name === 'News') {
                $news = $blog;
            }
            if ($blog->name === 'Quiet') {
                $quiet = $blog;
            }
        }
        self::assertInstanceOf(Blog::class, $news);
        self::assertInstanceOf(Blog::class, $quiet);
        $alpha = null;
        $beta = null;
        foreach ($news->posts as $post) {
            if ($post->title === 'Alpha') {
                $alpha = $post;
            }
            if ($post->title === 'Beta') {
                $beta = $post;
            }
        }
        $gamma = $quiet->posts[0];
        self::assertInstanceOf(Post::class, $alpha);
        self::assertInstanceOf(Post::class, $beta);

        // News is tracked before Quiet. Alpha leaves News first, then Quiet takes it.
        $news->posts = [$beta];
        $quiet->posts = [$gamma, $alpha];
        $this->context->save();
        self::assertSame(3, (int) $this->connection->table('posts')->count());
        self::assertSame($quiet->id, (int) $this->connection->table('posts')->where('id', '=', $alpha->id)->value('blog_id'));
        self::assertSame($quiet, $alpha->blog);

        // Quiet is tracked after News. Gamma leaves Quiet, News takes it first.
        $quiet->posts = [$alpha];
        $news->posts = [$beta, $gamma];
        $this->context->save();
        self::assertSame(3, (int) $this->connection->table('posts')->count());
        self::assertSame($news->id, (int) $this->connection->table('posts')->where('id', '=', $gamma->id)->value('blog_id'));
        self::assertSame(1, (int) $this->connection->table('post_tag')->where('post_id', '=', $alpha->id)->where('tag_id', '=', 1)->count());
    }

    public function testMovedPostTrackedBeforeBothBlogsStillUpdatesItsForeignKey(): void
    {
        $alpha = $this->context->posts->find(1);
        self::assertInstanceOf(Post::class, $alpha);
        $gamma = $this->context->posts->find(3);
        self::assertInstanceOf(Post::class, $gamma);
        $graph = RelScenarios::loadGraph($this->context);
        $news = null;
        $quiet = null;
        foreach ($graph as $blog) {
            if ($blog->name === 'News') {
                $news = $blog;
            }
            if ($blog->name === 'Quiet') {
                $quiet = $blog;
            }
        }
        self::assertInstanceOf(Blog::class, $news);
        self::assertInstanceOf(Blog::class, $quiet);
        self::assertSame($alpha, $news->posts[0]);
        $beta = $news->posts[1];

        // Alpha and Gamma are tracked before either blog, so they are compared before a blog writes their key.
        $news->posts = [$beta, $gamma];
        $quiet->posts = [$alpha];
        $this->context->save();
        self::assertSame(3, (int) $this->connection->table('posts')->count());
        self::assertSame($quiet->id, (int) $this->connection->table('posts')->where('id', '=', 1)->value('blog_id'));
        self::assertSame($news->id, (int) $this->connection->table('posts')->where('id', '=', 3)->value('blog_id'));
    }

    public function testMovedPostTrackedBeforeBothBlogsInReverseOrderLeavesUnrelatedModelsClean(): void
    {
        $quiet = $this->context->blogs->find(2);
        self::assertInstanceOf(Blog::class, $quiet);
        $alpha = $this->context->posts->find(1);
        self::assertInstanceOf(Post::class, $alpha);
        $gamma = $this->context->posts->find(3);
        self::assertInstanceOf(Post::class, $gamma);
        $graph = RelScenarios::loadGraph($this->context);
        $news = null;
        foreach ($graph as $blog) {
            if ($blog->name === 'News') {
                $news = $blog;
            }
        }
        self::assertInstanceOf(Blog::class, $news);
        self::assertSame($quiet, $this->context->blogs->find(2));
        $beta = $news->posts[1];

        // Quiet is tracked before News, so it takes Alpha before News takes Gamma.
        $quiet->posts = [$alpha];
        $news->posts = [$beta, $gamma];
        $this->context->tracker->detectChanges();
        $this->context->tracker->detectChanges();

        self::assertSame(TrackingState::Dirty, $this->context->entry($alpha)->state);
        self::assertSame(TrackingState::Dirty, $this->context->entry($gamma)->state);
        self::assertSame(TrackingState::Clean, $this->context->entry($beta)->state);
        self::assertSame(TrackingState::Clean, $this->context->entry($news)->state);
        self::assertSame(TrackingState::Clean, $this->context->entry($quiet)->state);

        $this->context->save();
        self::assertSame(2, (int) $this->connection->table('posts')->where('id', '=', 1)->value('blog_id'));
        self::assertSame(1, (int) $this->connection->table('posts')->where('id', '=', 3)->value('blog_id'));
        self::assertSame(1, (int) $this->connection->table('posts')->where('id', '=', 2)->value('blog_id'));
    }

    public function testCleanPostWithLoadedTagsDoesNotDuplicatePivotRows(): void
    {
        $alpha = RelScenarios::loadOne($this->context, 1);
        self::assertInstanceOf(Post::class, $alpha);
        self::assertCount(2, $alpha->tags);
        $alpha->title = 'Alpha renamed';
        $this->context->save();
        self::assertSame(2, (int) $this->connection->table('post_tag')->where('post_id', '=', 1)->count());
        $this->context->save();
        self::assertSame(2, (int) $this->connection->table('post_tag')->where('post_id', '=', 1)->count());
    }

    public function testNewPostWithTagsSavedTwiceWritesPivotRowsOnce(): void
    {
        $news = $this->context->blogs->find(1);
        $tag = $this->context->tags->find(2);
        self::assertInstanceOf(Blog::class, $news);
        self::assertInstanceOf(Tag::class, $tag);
        $post = new Post();
        $post->title = 'Fresh';
        $post->blog = $news;
        $post->tags = [$tag];
        $this->context->posts->add($post);
        $this->context->tracker->detectChanges();
        $this->context->save();
        $this->context->save();
        self::assertSame(1, (int) $this->connection->table('post_tag')->where('post_id', '=', $post->id)->count());
    }

    public function testUnloadedTagsAreNotWipedAndDeletedEndpointsDropTheirPivotRows(): void
    {
        $alpha = $this->context->posts->find(1);
        self::assertInstanceOf(Post::class, $alpha);
        $alpha->title = 'Alpha renamed';
        $this->context->save();
        self::assertSame(2, (int) $this->connection->table('post_tag')->where('post_id', '=', 1)->count());

        $tag = $this->context->tags->find(1);
        self::assertInstanceOf(Tag::class, $tag);
        $this->context->tags->remove($tag);
        $this->context->save();
        self::assertSame(0, (int) $this->connection->table('post_tag')->where('tag_id', '=', 1)->count());
        self::assertSame(1, (int) $this->connection->table('post_tag')->where('post_id', '=', 1)->count());

        $this->context->posts->remove($alpha);
        $this->context->save();
        self::assertSame(0, (int) $this->connection->table('post_tag')->where('post_id', '=', 1)->count());
        self::assertNotNull($this->context->tags->find(2));
    }

    public function testRepointingAnExistingPostAtANewBlogInTheSameSave(): void
    {
        $alpha = $this->context->posts->find(1);
        self::assertInstanceOf(Post::class, $alpha);
        $fresh = new Blog();
        $fresh->name = 'Fresh';
        $alpha->blog = $fresh;
        $this->context->blogs->add($fresh);
        $this->context->save();

        self::assertGreaterThan(0, $fresh->id);
        self::assertSame($fresh->id, $alpha->blogId);
        self::assertSame($fresh->id, (int) $this->connection->table('posts')->where('id', '=', 1)->value('blog_id'));
    }

    public function testNewBlogAddedBeforeTheLoadedPostItTakesStillUpdatesThePostKey(): void
    {
        $fresh = new Blog();
        $fresh->name = 'Fresh';
        $this->context->blogs->add($fresh);
        $alpha = $this->context->posts->find(1);
        self::assertInstanceOf(Post::class, $alpha);
        $fresh->posts = [$alpha];
        $this->context->save();

        self::assertGreaterThan(0, $fresh->id);
        self::assertSame($fresh->id, (int) $this->connection->table('posts')->where('id', '=', 1)->value('blog_id'));
    }

    public function testRepeatedChangeDetectionKeepsAPostWaitingForANewBlogKeyDirty(): void
    {
        $fresh = new Blog();
        $fresh->name = 'Fresh';
        $this->context->blogs->add($fresh);
        $alpha = $this->context->posts->find(1);
        self::assertInstanceOf(Post::class, $alpha);
        $fresh->posts = [$alpha];
        $this->context->tracker->detectChanges();
        self::assertSame(TrackingState::Dirty, $this->context->entry($alpha)->state);
        $this->context->tracker->detectChanges();
        self::assertSame(TrackingState::Dirty, $this->context->entry($alpha)->state);
        $this->context->save();

        self::assertSame($fresh->id, (int) $this->connection->table('posts')->where('id', '=', 1)->value('blog_id'));
    }

    public function testRepeatedChangeDetectionLeavesUnrelatedRowsCleanAndSettlesAfterSave(): void
    {
        $fresh = new Blog();
        $fresh->name = 'Fresh';
        $this->context->blogs->add($fresh);
        $alpha = $this->context->posts->find(1);
        $gamma = $this->context->posts->find(3);
        self::assertInstanceOf(Post::class, $alpha);
        self::assertInstanceOf(Post::class, $gamma);
        $fresh->posts = [$alpha];
        $this->context->tracker->detectChanges();
        $this->context->tracker->detectChanges();
        $this->context->tracker->detectChanges();

        self::assertSame(TrackingState::Dirty, $this->context->entry($alpha)->state);
        self::assertSame(TrackingState::Clean, $this->context->entry($gamma)->state);

        $this->context->save();
        $this->context->tracker->detectChanges();
        $this->context->tracker->detectChanges();

        self::assertSame(TrackingState::Clean, $this->context->entry($alpha)->state);
        self::assertSame(TrackingState::Clean, $this->context->entry($gamma)->state);
        self::assertSame($fresh->id, (int) $this->connection->table('posts')->where('id', '=', 1)->value('blog_id'));
    }

    public function testJoinAliasesFollowTheConnectionTablePrefix(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'app_',
        ], 'prefixed');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $connection = $capsule->getConnection('prefixed');
        $connection->statement('create table app_blogs (id integer primary key, name text not null)');
        $connection->statement('create table app_posts (id integer primary key, blog_id integer not null, title text not null)');
        $connection->statement("insert into app_blogs (id, name) values (1, 'News'), (2, 'Quiet')");
        $connection->statement("insert into app_posts (id, blog_id, title) values (1, 1, 'Alpha'), (2, 2, 'Gamma')");

        $context = new BlogContext('prefixed');
        $titles = RelScenarios::postsNamed($context, 'Quiet')->pluck('title')->all();
        self::assertSame(['Gamma'], $titles);
        $withPosts = RelScenarios::blogsWithPosts($context)->pluck('name')->all();
        \sort($withPosts);
        self::assertSame(['News', 'Quiet'], $withPosts);
    }
}
