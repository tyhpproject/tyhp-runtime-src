<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\AmbiguousInverseContext;
use Tyhp\Rhetoric\Fixtures\AttributeBlog;
use Tyhp\Rhetoric\Fixtures\AttributeBlogContext;
use Tyhp\Rhetoric\Fixtures\AttributeCascadeContext;
use Tyhp\Rhetoric\Fixtures\AttributePost;
use Tyhp\Rhetoric\Fixtures\Author;
use Tyhp\Rhetoric\Fixtures\Blog;
use Tyhp\Rhetoric\Fixtures\Book;
use Tyhp\Rhetoric\Fixtures\CascadeBlogContext;
use Tyhp\Rhetoric\Fixtures\ConventionBlogContext;
use Tyhp\Rhetoric\Fixtures\ConventionTopicContext;
use Tyhp\Rhetoric\Fixtures\FluentBlog;
use Tyhp\Rhetoric\Fixtures\FluentBlogContext;
use Tyhp\Rhetoric\Fixtures\FluentPost;
use Tyhp\Rhetoric\Fixtures\Label;
use Tyhp\Rhetoric\Fixtures\Ledger;
use Tyhp\Rhetoric\Fixtures\LedgerContext;
use Tyhp\Rhetoric\Fixtures\Post;
use Tyhp\Rhetoric\Fixtures\PrefixedBlogContext;
use Tyhp\Rhetoric\Fixtures\Stamp;
use Tyhp\Rhetoric\Fixtures\Topic;
use Tyhp\Rhetoric\Mapping\ContextMap;
use Tyhp\Rhetoric\Mapping\MapCache;
use Tyhp\Rhetoric\Mapping\ModelMap;
use Tyhp\Rhetoric\Mapping\RelationKind;
use Tyhp\Rhetoric\OnDelete;

final class MappingTest extends TestCase
{
    protected function setUp(): void
    {
        MapCache::clear();
    }

    public function testConventionMapsTablesColumnsKeysForeignKeysAndRelations(): void
    {
        $context = new ConventionBlogContext();
        $map = $context->mapping();

        self::assertSame(Blog::class, $context->blogs->modelType()->getName());
        self::assertSame(Post::class, $context->posts->modelType()->getName());

        $blogs = $map->model(Blog::class);
        $posts = $map->model(Post::class);
        self::assertSame('blogs', $blogs->table);
        self::assertSame('posts', $posts->table);
        self::assertEqualsCanonicalizing(['id', 'name', 'url', 'backed'], \array_keys($blogs->columns));
        self::assertFalse($blogs->hasColumn('virtual'));
        self::assertFalse($blogs->hasColumn('posts'));

        $id = $blogs->column('id');
        self::assertSame('id', $id->name);
        self::assertSame('int', $id->type);
        self::assertFalse($id->nullable);
        self::assertTrue($id->generated);
        self::assertNotNull($blogs->key);
        self::assertSame(['id'], $blogs->key->properties);
        self::assertSame(['id'], $blogs->key->columns);
        self::assertTrue($blogs->key->primary);
        self::assertSame('string', $blogs->column('name')->type);
        self::assertFalse($blogs->column('name')->nullable);
        self::assertSame('string', $blogs->column('url')->type);
        self::assertTrue($blogs->column('url')->nullable);
        self::assertSame('backed', $blogs->column('backed')->name);
        self::assertSame([], $blogs->foreignKeys);

        self::assertEqualsCanonicalizing(
            ['id', 'blogId', 'title', 'slug', 'rating', 'publishedAt', 'deletedAt'],
            \array_keys($posts->columns),
        );
        self::assertFalse($posts->hasColumn('blog'));
        self::assertSame('blog_id', $posts->column('blogId')->name);
        self::assertSame('int', $posts->column('blogId')->type);
        self::assertFalse($posts->column('blogId')->nullable);
        self::assertSame('published_at', $posts->column('publishedAt')->name);
        self::assertTrue($posts->column('publishedAt')->nullable);
        self::assertStringContainsString('DateTimeImmutable', $posts->column('publishedAt')->type);
        self::assertSame('deleted_at', $posts->column('deletedAt')->name);
        self::assertTrue($posts->column('deletedAt')->nullable);

        self::assertCount(1, $posts->foreignKeys);
        $foreignKey = $posts->foreignKeys[0];
        self::assertSame(['blogId'], $foreignKey->properties);
        self::assertSame(['blog_id'], $foreignKey->columns);
        self::assertSame(Blog::class, $foreignKey->referencedClass);
        self::assertSame('blogs', $foreignKey->referencedTable);
        self::assertSame(['id'], $foreignKey->referencedColumns);
        self::assertSame(OnDelete::Restrict, $foreignKey->onDelete);

        $postsRelation = $blogs->relation('posts');
        self::assertSame(RelationKind::HasMany, $postsRelation->kind);
        self::assertSame(Post::class, $postsRelation->relatedClass);
        self::assertSame('blog', $postsRelation->inverseProperty);
        self::assertSame('blogId', $postsRelation->foreignKeyProperty);
        self::assertSame('blog_id', $postsRelation->foreignKeyColumn);

        $blogRelation = $posts->relation('blog');
        self::assertSame(RelationKind::BelongsTo, $blogRelation->kind);
        self::assertSame(Blog::class, $blogRelation->relatedClass);
        self::assertSame('posts', $blogRelation->inverseProperty);
        self::assertSame('blogId', $blogRelation->foreignKeyProperty);
        self::assertSame('blog_id', $blogRelation->foreignKeyColumn);
        self::assertSame(OnDelete::Restrict, $blogRelation->onDelete);
    }

    public function testMappingIsCachedPerContextClass(): void
    {
        $first = new ConventionBlogContext();
        $second = new ConventionBlogContext();
        $prefixed = new PrefixedBlogContext();

        self::assertSame($first->mapping(), $second->mapping());
        self::assertNotSame($first->mapping(), $prefixed->mapping());
        self::assertSame('app_blogs', $prefixed->mapping()->model(Blog::class)->table);
    }

    public function testCachedMappingIsReadonly(): void
    {
        $blogs = (new ConventionBlogContext())->mapping()->model(Blog::class);

        $this->expectException(\Error::class);
        $blogs->table = 'changed';
    }

    public function testConventionMapsManyToManyWithoutAModelForeignKey(): void
    {
        $map = (new ConventionTopicContext())->mapping();
        $topics = $map->model(Topic::class);
        $labels = $map->model(Label::class);

        self::assertSame('topics', $topics->table);
        self::assertSame('labels', $labels->table);
        self::assertEqualsCanonicalizing(['id', 'name'], \array_keys($topics->columns));
        self::assertEqualsCanonicalizing(['id', 'name'], \array_keys($labels->columns));
        self::assertSame([], $topics->foreignKeys);
        self::assertSame([], $labels->foreignKeys);

        $labelsRelation = $topics->relation('labels');
        self::assertSame(RelationKind::BelongsToMany, $labelsRelation->kind);
        self::assertSame(Label::class, $labelsRelation->relatedClass);
        self::assertSame('topics', $labelsRelation->inverseProperty);
        self::assertNull($labelsRelation->foreignKeyProperty);
        self::assertSame('label_topic', $labelsRelation->pivotTable);
        self::assertSame('topic_id', $labelsRelation->pivotForeignKey);
        self::assertSame('label_id', $labelsRelation->pivotRelatedKey);

        $topicsRelation = $labels->relation('topics');
        self::assertSame(RelationKind::BelongsToMany, $topicsRelation->kind);
        self::assertSame(Topic::class, $topicsRelation->relatedClass);
        self::assertSame('labels', $topicsRelation->inverseProperty);
        self::assertSame('label_topic', $topicsRelation->pivotTable);
        self::assertSame('label_id', $topicsRelation->pivotForeignKey);
        self::assertSame('topic_id', $topicsRelation->pivotRelatedKey);
    }

    public function testAttributesOverrideConventions(): void
    {
        $map = (new AttributeBlogContext())->mapping();
        $blogs = $map->model(AttributeBlog::class);
        $posts = $map->model(AttributePost::class);

        self::assertSame('desks', $blogs->table);
        self::assertEqualsCanonicalizing(['deskId', 'name'], \array_keys($blogs->columns));
        self::assertFalse($blogs->hasColumn('summary'));
        $desk = $blogs->column('deskId');
        self::assertSame('desk_id', $desk->name);
        self::assertTrue($desk->generated);
        self::assertNotNull($blogs->key);
        self::assertSame(['deskId'], $blogs->key->properties);
        self::assertSame(['desk_id'], $blogs->key->columns);
        self::assertSame('label', $blogs->column('name')->name);
        self::assertSame(40, $blogs->column('name')->maxLength);
        self::assertSame('Public name', $blogs->column('name')->comment);

        $entries = $blogs->relation('entries');
        self::assertSame(RelationKind::HasMany, $entries->kind);
        self::assertSame(AttributePost::class, $entries->relatedClass);
        self::assertSame('blog', $entries->inverseProperty);
        $drafts = $blogs->relation('drafts');
        self::assertSame(RelationKind::HasMany, $drafts->kind);
        self::assertNull($drafts->inverseProperty);

        self::assertSame('attribute_posts', $posts->table);
        self::assertEqualsCanonicalizing(['entryId', 'title', 'slug', 'rating', 'blogId'], \array_keys($posts->columns));
        self::assertFalse($posts->hasColumn('blurb'));
        self::assertFalse($posts->hasColumn('blog'));
        self::assertNotNull($posts->key);
        self::assertSame(['entryId'], $posts->key->properties);
        self::assertSame(['entry_id'], $posts->key->columns);
        self::assertTrue($posts->column('entryId')->generated);
        self::assertSame('headline', $posts->column('title')->name);
        self::assertSame(120, $posts->column('title')->maxLength);
        self::assertSame(8, $posts->column('rating')->precision);
        self::assertSame(2, $posts->column('rating')->scale);
        self::assertCount(2, $posts->indexes);
        self::assertSame(['slug'], $posts->indexes[0]->properties);
        self::assertSame(['slug'], $posts->indexes[0]->columns);
        self::assertTrue($posts->indexes[0]->unique);
        self::assertSame(['title', 'slug'], $posts->indexes[1]->properties);
        self::assertSame(['headline', 'slug'], $posts->indexes[1]->columns);
        self::assertFalse($posts->indexes[1]->unique);
        self::assertSame('title_slug', $posts->indexes[1]->name);

        $blog = $posts->relation('blog');
        self::assertSame(RelationKind::BelongsTo, $blog->kind);
        self::assertSame(AttributeBlog::class, $blog->relatedClass);
        self::assertSame('entries', $blog->inverseProperty);
        self::assertSame('blogId', $blog->foreignKeyProperty);
        self::assertSame('blog_id', $blog->foreignKeyColumn);
        self::assertSame(OnDelete::SetNull, $blog->onDelete);

        self::assertCount(1, $posts->foreignKeys);
        $foreignKey = $posts->foreignKeys[0];
        self::assertSame(['blog_id'], $foreignKey->columns);
        self::assertSame(['blogId'], $foreignKey->properties);
        self::assertSame('desks', $foreignKey->referencedTable);
        self::assertSame(['desk_id'], $foreignKey->referencedColumns);
        self::assertSame(AttributeBlog::class, $foreignKey->referencedClass);
        self::assertSame(OnDelete::SetNull, $foreignKey->onDelete);
        self::assertSame([], $blogs->foreignKeys);
    }

    public function testFluentConfigurationOverridesAttributesAndConventions(): void
    {
        $map = (new FluentBlogContext())->mapping();
        $blogs = $map->model(FluentBlog::class);
        $posts = $map->model(FluentPost::class);

        self::assertSame('fluent_blogs', $blogs->table);
        self::assertSame('stored_posts', $posts->table);
        self::assertEqualsCanonicalizing(['id', 'blogId', 'title', 'slug', 'rating'], \array_keys($posts->columns));
        self::assertSame('post_title', $posts->column('title')->name);
        self::assertSame(80, $posts->column('title')->maxLength);
        self::assertTrue($this->hasIndex($posts, ['slug'], true));
        self::assertTrue($this->hasIndex($posts, ['rating'], false));

        $blog = $posts->relation('blog');
        self::assertSame(RelationKind::BelongsTo, $blog->kind);
        self::assertSame(FluentBlog::class, $blog->relatedClass);
        self::assertSame('posts', $blog->inverseProperty);
        self::assertSame('blogId', $blog->foreignKeyProperty);
        self::assertSame('blog_id', $blog->foreignKeyColumn);
        self::assertSame(OnDelete::Cascade, $blog->onDelete);

        $postsRelation = $blogs->relation('posts');
        self::assertSame(RelationKind::HasMany, $postsRelation->kind);
        self::assertSame(FluentPost::class, $postsRelation->relatedClass);
        self::assertSame('blog', $postsRelation->inverseProperty);

        self::assertCount(1, $posts->foreignKeys);
        self::assertSame(['blog_id'], $posts->foreignKeys[0]->columns);
        self::assertSame('fluent_blogs', $posts->foreignKeys[0]->referencedTable);
        self::assertSame(['id'], $posts->foreignKeys[0]->referencedColumns);
        self::assertSame(OnDelete::Cascade, $posts->foreignKeys[0]->onDelete);

        self::assertSame(OnDelete::Cascade, $postsRelation->onDelete);

        self::assertInstanceOf(ContextMap::class, $map);
    }

    public function testAttributeForeignKeyDeleteBehaviorShowsOnBothRelationSides(): void
    {
        $blogs = (new AttributeBlogContext())->mapping()->model(AttributeBlog::class);

        self::assertSame(OnDelete::SetNull, $blogs->relation('entries')->onDelete);
        self::assertSame(OnDelete::SetNull, $blogs->relation('drafts')->onDelete);
    }

    public function testFluentHasManyDeleteBehaviorReachesTheForeignKey(): void
    {
        $map = (new CascadeBlogContext())->mapping();
        $blogs = $map->model(Blog::class);
        $posts = $map->model(Post::class);

        self::assertSame(OnDelete::Cascade, $blogs->relation('posts')->onDelete);
        self::assertSame(OnDelete::Cascade, $posts->relation('blog')->onDelete);
        self::assertCount(1, $posts->foreignKeys);
        self::assertSame(OnDelete::Cascade, $posts->foreignKeys[0]->onDelete);
        self::assertSame('blog', $blogs->relation('posts')->inverseProperty);
    }

    public function testFluentHasManyDeleteBehaviorBeatsTheForeignKeyAttribute(): void
    {
        $map = (new AttributeCascadeContext())->mapping();
        $posts = $map->model(AttributePost::class);

        self::assertCount(1, $posts->foreignKeys);
        self::assertSame(OnDelete::Cascade, $posts->foreignKeys[0]->onDelete);
        self::assertSame(OnDelete::Cascade, $posts->relation('blog')->onDelete);
        self::assertSame(OnDelete::Cascade, $map->model(AttributeBlog::class)->relation('entries')->onDelete);
    }

    public function testIdIsGeneratedOnlyWhileItIsTheKeyAndNotSetExplicitly(): void
    {
        $map = (new LedgerContext())->mapping();

        $ledger = $map->model(Ledger::class);
        self::assertNotNull($ledger->key);
        self::assertSame(['code'], $ledger->key->properties);
        self::assertFalse($ledger->column('id')->generated);
        self::assertFalse($ledger->column('code')->generated);

        $stamp = $map->model(Stamp::class);
        self::assertNotNull($stamp->key);
        self::assertSame(['id'], $stamp->key->properties);
        self::assertFalse($stamp->column('id')->generated);
    }

    public function testAmbiguousInverseIsLeftUnset(): void
    {
        $map = (new AmbiguousInverseContext())->mapping();
        $authors = $map->model(Author::class);
        $books = $map->model(Book::class);

        self::assertNull($authors->relation('written')->inverseProperty);
        self::assertNull($authors->relation('edited')->inverseProperty);
        self::assertNull($books->relation('author')->inverseProperty);
        self::assertSame('author_id', $authors->relation('written')->foreignKeyColumn);
        self::assertCount(1, $books->foreignKeys);
    }

    /**
     * @param array<int, string> $properties
     */
    private function hasIndex(ModelMap $model, array $properties, bool $unique): bool
    {
        foreach ($model->indexes as $index) {
            if ($index->properties === $properties && $index->unique === $unique) {
                return true;
            }
        }

        return false;
    }
}
