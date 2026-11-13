<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\ConcurrencyException;
use Tyhp\Rhetoric\Fixtures\AdvancedContext;
use Tyhp\Rhetoric\Fixtures\MappingScenarios;
use Tyhp\Rhetoric\Fixtures\Animal;
use Tyhp\Rhetoric\Fixtures\ArchiveObserver;
use Tyhp\Rhetoric\Fixtures\ArchivedPost;
use Tyhp\Rhetoric\Fixtures\AuditView;
use Tyhp\Rhetoric\Fixtures\Car;
use Tyhp\Rhetoric\Fixtures\Cat;
use Tyhp\Rhetoric\Fixtures\Dog;
use Tyhp\Rhetoric\Fixtures\Kitten;
use Tyhp\Rhetoric\Fixtures\Listing;
use Tyhp\Rhetoric\Fixtures\PostObserver;
use Tyhp\Rhetoric\Fixtures\Resident;
use Tyhp\Rhetoric\Fixtures\ScopedPost;
use Tyhp\Rhetoric\Fixtures\SqlLog;
use Tyhp\Rhetoric\Fixtures\VersionedPost;
use Tyhp\Rhetoric\Mapping\MapCache;

final class AdvancedMappingTest extends TestCase
{
    private AdvancedContext $context;

    protected function setUp(): void
    {
        MapCache::clear();
        PostObserver::$creating = 0;
        PostObserver::$created = 0;
        ArchiveObserver::$log = [];
        SqlLog::$queries = 0;
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ], 'rhetoric');
        $capsule->setAsGlobal();
        $capsule->getConnection('rhetoric');
        $this->context = new AdvancedContext('rhetoric', 1);
        $this->context->createSchema();
        $this->context->connection()->statement('create view audit_view as select id, title from listings');
        SqlLog::$queries = 0;
    }

    public function testSchemaCarriesEmbeddedJsonExtraAndHierarchyColumns(): void
    {
        $names = $this->columnNames('residents');
        self::assertContains('block_city', $names);
        self::assertContains('block_street', $names);
        self::assertContains('purse_cents', $names);
        self::assertContains('purse_currency', $names);
        self::assertContains('tags', $names);
        self::assertContains('lives', $this->columnNames('animals'));
        self::assertContains('trained', $this->columnNames('animals'));
        self::assertContains('kind', $this->columnNames('animals'));
        self::assertContains('rank', $this->columnNames('listings'));
        self::assertContains('deleted_at', $this->columnNames('archived_posts'));
        self::assertContains('version', $this->columnNames('versioned_posts'));
        self::assertFalse($this->context->connection()->getSchemaBuilder()->hasTable('cats'));
        self::assertFalse($this->context->connection()->getSchemaBuilder()->hasTable('audit_view'));
    }

    public function testGlobalScopeUsesTheContextThatRunsTheQuery(): void
    {
        $mine = new ScopedPost();
        $mine->tenantId = 1;
        $mine->title = 'mine';
        $this->context->scopedPosts->add($mine);
        self::assertSame(1, $this->context->save());
        self::assertSame(1, PostObserver::$creating);
        self::assertSame(1, PostObserver::$created);
        $this->context->connection()->table('scoped_posts')->insert([
            'tenant_id' => 2,
            'title' => 'theirs',
        ]);

        self::assertStringContainsString('tenant_id', MappingScenarios::tenantSql($this->context));
        self::assertStringNotContainsString('tenant_id', MappingScenarios::unscopedSql($this->context));
        self::assertSame([1], MappingScenarios::tenantBindings($this->context));
        self::assertSame(1, MappingScenarios::scopedCount($this->context));
        self::assertSame(2, $this->context->scopedPosts->withoutGlobalScopes()->count());

        $other = new AdvancedContext('rhetoric', 2);
        self::assertSame([2], MappingScenarios::tenantBindings($other));
        self::assertSame(1, MappingScenarios::scopedCount($other));
        self::assertGreaterThan(0, SqlLog::$queries);
    }

    public function testSoftDeletesHideRestoreAndKeepTheRow(): void
    {
        $post = new ArchivedPost();
        $post->title = 'gone';
        $this->context->archivedPosts->add($post);
        $this->context->save();
        $this->context->archivedPosts->remove($post);
        $this->context->save();

        self::assertSame(['deleting', 'deleted'], ArchiveObserver::$log);
        self::assertStringContainsString('deleted_at', MappingScenarios::archivedSql($this->context));
        self::assertStringContainsString('is null', MappingScenarios::archivedSql($this->context));
        self::assertStringNotContainsString('deleted_at', MappingScenarios::withTrashedSql($this->context));
        self::assertStringContainsString('is not null', MappingScenarios::onlyTrashedSql($this->context));
        self::assertSame(0, MappingScenarios::archivedCount($this->context));
        self::assertSame(1, MappingScenarios::withTrashedCount($this->context));
        self::assertSame(1, MappingScenarios::onlyTrashedCount($this->context));
        self::assertNotNull($this->context->connection()->table('archived_posts')->value('deleted_at'));

        $this->context->restore($post);
        self::assertSame(['deleting', 'deleted', 'restoring', 'restored'], ArchiveObserver::$log);
        self::assertNull($post->deletedAt);
        self::assertSame(1, MappingScenarios::archivedCount($this->context));
        self::assertNull($this->context->connection()->table('archived_posts')->value('deleted_at'));
    }

    public function testRowVersionRejectsAStaleUpdate(): void
    {
        $post = new VersionedPost();
        $post->title = 'one';
        $this->context->versionedPosts->add($post);
        $this->context->save();
        self::assertSame(1, $post->version);

        $first = $this->context->versionedPosts->all()[0];
        $secondContext = new AdvancedContext('rhetoric', 1);
        $second = $secondContext->versionedPosts->all()[0];
        $first->title = 'first';
        $second->title = 'second';
        self::assertSame(1, $this->context->save());
        self::assertSame(2, $first->version);

        $this->expectException(ConcurrencyException::class);
        $secondContext->save();
    }

    public function testSingleTableInheritanceFiltersAndHydratesTheConcreteClass(): void
    {
        $cat = new Cat();
        $cat->name = 'Ada';
        $cat->lives = 9;
        $dog = new Dog();
        $dog->name = 'Gus';
        $dog->trained = true;
        $this->context->cats->add($cat);
        $this->context->dogs->add($dog);
        self::assertSame(2, $this->context->save());

        self::assertStringContainsString('kind', MappingScenarios::catSql($this->context));
        $cats = MappingScenarios::cats($this->context);
        self::assertCount(1, $cats);
        self::assertInstanceOf(Cat::class, $cats[0]);
        self::assertSame(9, $cats[0]->lives);
        $animals = MappingScenarios::animals($this->context);
        self::assertCount(2, $animals);
        $classes = [];
        foreach ($animals as $animal) {
            $classes[] = $animal::class;
        }
        self::assertEqualsCanonicalizing([Cat::class, Dog::class], $classes);
        self::assertSame('cat', $this->context->connection()->table('animals')->where('name', '=', 'Ada')->value('kind'));
    }

    public function testEmbeddedValuesAndJsonRoundTrip(): void
    {
        $resident = new Resident();
        $resident->name = 'Ada';
        $resident->block = ['city' => 'Paris', 'street' => 'Rue'];
        $resident->purse->cents = 250;
        $resident->purse->currency = 'EUR';
        $resident->tags = ['php', 'sql'];
        $this->context->residents->add($resident);
        $this->context->save();

        $loaded = MappingScenarios::residents($this->context);
        self::assertCount(1, $loaded);
        self::assertSame('Paris', $loaded[0]->block['city']);
        self::assertSame('Rue', $loaded[0]->block['street']);
        self::assertSame(250, $loaded[0]->purse->cents);
        self::assertSame('EUR', $loaded[0]->purse->currency);
        self::assertSame(['php', 'sql'], $loaded[0]->tags);
        self::assertStringContainsString('json_each', MappingScenarios::taggedSql($this->context));
        $tagged = MappingScenarios::tagged($this->context);
        self::assertCount(1, $tagged);
        self::assertSame('Ada', $tagged[0]->name);
    }

    public function testKeylessViewIsQueryableAndReadOnly(): void
    {
        $listing = new Listing();
        $listing->title = 'shelf';
        $this->context->listings->add($listing);
        $this->context->save();
        self::assertSame(0, (int) $this->context->connection()->table('listings')->value('rank'));

        $audits = MappingScenarios::audits($this->context);
        self::assertCount(1, $audits);
        self::assertSame('shelf', $audits[0]->title);

        $fresh = new AuditView();
        $fresh->title = 'nope';
        $this->context->audits->add($fresh);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is read-only');
        $this->context->save();
    }

    public function testModelWithAnExtraColumnIsNotSavedAgainWhenUnchanged(): void
    {
        $listing = new Listing();
        $listing->title = 'shelf';
        $this->context->listings->add($listing);
        self::assertSame(1, $this->context->save());
        self::assertSame(0, $this->context->save());

        $this->context->connection()->table('listings')->update(['rank' => 7]);
        $listing->title = 'renamed';
        self::assertSame(1, $this->context->save());
        self::assertSame(7, (int) $this->context->connection()->table('listings')->value('rank'));
    }

    public function testRootTableStoresTheDerivedClassDiscriminator(): void
    {
        $cat = new Cat();
        $cat->name = 'Ada';
        $this->context->animals->add($cat);
        $this->context->save();

        self::assertSame('cat', $this->context->connection()->table('animals')->value('kind'));
        self::assertCount(1, MappingScenarios::catTable($this->context));
    }

    public function testSiblingTypesWithExplicitKeysInsertTogether(): void
    {
        $cat = new Cat();
        $cat->id = 10;
        $cat->name = 'Ada';
        $cat->lives = 9;
        $dog = new Dog();
        $dog->id = 11;
        $dog->name = 'Gus';
        $dog->trained = true;
        $this->context->cats->add($cat);
        $this->context->dogs->add($dog);

        self::assertSame(2, $this->context->save());
        self::assertSame(2, $this->context->connection()->table('animals')->count());
        self::assertSame('dog', $this->context->connection()->table('animals')->where('id', '=', 11)->value('kind'));
    }

    public function testFindAppliesTheDiscriminatorSoftDeleteAndScopeFilters(): void
    {
        $dog = new Dog();
        $dog->name = 'Gus';
        $this->context->dogs->add($dog);
        $post = new ArchivedPost();
        $post->title = 'gone';
        $this->context->archivedPosts->add($post);
        $this->context->save();
        $this->context->archivedPosts->remove($post);
        $this->context->save();
        $this->context->connection()->table('scoped_posts')->insert(['tenant_id' => 2, 'title' => 'theirs']);
        $theirs = (int) $this->context->connection()->table('scoped_posts')->value('id');

        $fresh = new AdvancedContext('rhetoric', 1);
        self::assertNull($fresh->cats->find($dog->id));
        self::assertInstanceOf(Dog::class, $fresh->animals->find($dog->id));
        self::assertNull($fresh->archivedPosts->find($post->id));
        self::assertNull($fresh->scopedPosts->find($theirs));
        self::assertNotNull((new AdvancedContext('rhetoric', 2))->scopedPosts->find($theirs));
    }

    public function testDerivedTypeQueriesAndInstanceofIncludeTheirDescendants(): void
    {
        $cat = new Cat();
        $cat->name = 'Ada';
        $kitten = new Kitten();
        $kitten->name = 'Bit';
        $dog = new Dog();
        $dog->name = 'Gus';
        $this->context->cats->add($cat);
        $this->context->kittens->add($kitten);
        $this->context->dogs->add($dog);
        $this->context->save();

        $fresh = new AdvancedContext('rhetoric', 1);
        $viaRoot = MappingScenarios::cats($fresh);
        self::assertCount(2, $viaRoot);
        $classes = [];
        foreach ($viaRoot as $animal) {
            $classes[] = $animal::class;
        }
        self::assertEqualsCanonicalizing([Cat::class, Kitten::class], $classes);

        $viaTable = MappingScenarios::catTable(new AdvancedContext('rhetoric', 1));
        $tableClasses = [];
        foreach ($viaTable as $animal) {
            $tableClasses[] = $animal::class;
        }
        self::assertEqualsCanonicalizing([Cat::class, Kitten::class], $tableClasses);
        self::assertSame(1, (new AdvancedContext('rhetoric', 1))->kittens->count());
    }

    public function testDerivedModelInheritsTheRootScopeAndObservers(): void
    {
        $mine = new Car();
        $mine->tenantId = 1;
        $mine->name = 'mine';
        $this->context->cars->add($mine);
        $this->context->save();
        self::assertSame(1, PostObserver::$creating);
        $this->context->connection()->table('vehicles')->insert(['tenant_id' => 2, 'kind' => 'car', 'name' => 'theirs', 'doors' => 2]);

        self::assertSame(1, MappingScenarios::carCount($this->context));
        self::assertSame(1, MappingScenarios::carCount(new AdvancedContext('rhetoric', 2)));
    }

    public function testDerivedRowAddedThroughTheRootTableIsOnlyThatType(): void
    {
        $dog = new Dog();
        $dog->name = 'Gus';
        $cat = new Cat();
        $cat->name = 'Ada';
        $this->context->animals->add($dog);
        $this->context->animals->add($cat);
        $this->context->save();

        $fresh = new AdvancedContext('rhetoric', 1);
        self::assertSame(1, $fresh->cats->count());
        self::assertSame(1, $fresh->dogs->count());
        self::assertCount(1, MappingScenarios::cats($fresh));
        self::assertSame('Ada', MappingScenarios::cats($fresh)[0]->name);
    }

    public function testRowsOfOneTypeShareAnInsertWhileSiblingTypesDoNot(): void
    {
        $first = new Dog();
        $first->id = 20;
        $first->name = 'A';
        $this->context->dogs->add($first);
        SqlLog::$queries = 0;
        $this->context->save();
        $single = SqlLog::$queries;

        $second = new Dog();
        $second->id = 21;
        $second->name = 'B';
        $third = new Dog();
        $third->id = 22;
        $third->name = 'C';
        $this->context->dogs->add($second);
        $this->context->dogs->add($third);
        SqlLog::$queries = 0;
        self::assertSame(2, $this->context->save());
        self::assertSame($single, SqlLog::$queries);
        self::assertSame(3, $this->context->connection()->table('animals')->count());
    }

    public function testKittenIsHydratedAndSavedAsAKittenThroughTheCatTable(): void
    {
        $kitten = new Kitten();
        $kitten->name = 'Bit';
        $kitten->mews = 3;
        $dog = new Dog();
        $dog->name = 'Gus';
        $this->context->kittens->add($kitten);
        $this->context->dogs->add($dog);
        $this->context->save();

        $fresh = new AdvancedContext('rhetoric', 1);
        $found = $fresh->cats->find($kitten->id);
        self::assertInstanceOf(Kitten::class, $found);
        self::assertSame(3, $found->mews);
        self::assertNull($fresh->cats->find($dog->id));
        self::assertInstanceOf(Dog::class, $fresh->dogs->find($dog->id));

        $found->mews = 4;
        self::assertSame(1, $fresh->save());
        self::assertSame(4, (int) $this->context->connection()->table('animals')->where('id', '=', $kitten->id)->value('mews'));
    }

    public function testKittenIsHydratedAndSavedThroughTheRootAnimalsTable(): void
    {
        $kitten = new Kitten();
        $kitten->name = 'Bit';
        $kitten->mews = 3;
        $this->context->kittens->add($kitten);
        $this->context->save();

        $fresh = new AdvancedContext('rhetoric', 1);
        $found = $fresh->animals->find($kitten->id);
        self::assertInstanceOf(Kitten::class, $found);
        self::assertSame(3, $found->mews);
        self::assertSame(0, $fresh->save());

        $found->mews = 5;
        self::assertSame(1, $fresh->save());
        self::assertSame(5, (int) $this->context->connection()->table('animals')->where('id', '=', $kitten->id)->value('mews'));
    }

    public function testCatThatIsNotAKittenDoesNotNeedTheDerivedColumn(): void
    {
        $cat = new Cat();
        $cat->name = 'Ada';
        $cat->lives = 9;
        $this->context->cats->add($cat);
        self::assertSame(1, $this->context->save());
        self::assertNull($this->context->connection()->table('animals')->where('id', '=', $cat->id)->value('mews'));

        $fresh = new AdvancedContext('rhetoric', 1);
        $found = $fresh->cats->find($cat->id);
        self::assertInstanceOf(Cat::class, $found);
        self::assertNotInstanceOf(Kitten::class, $found);
        self::assertSame(9, $found->lives);
        self::assertSame(0, $fresh->save());

        $found->lives = 8;
        self::assertSame(1, $fresh->save());
        self::assertSame(8, (int) $this->context->connection()->table('animals')->where('id', '=', $cat->id)->value('lives'));
        self::assertNull($this->context->connection()->table('animals')->where('id', '=', $cat->id)->value('mews'));
    }

    public function testDerivedColumnsAreSharedUpTheChainWithoutLeakingAcrossBranches(): void
    {
        $map = $this->context->mapping();
        $animal = $map->model(Animal::class);
        $cat = $map->model(Cat::class);
        $dog = $map->model(Dog::class);
        $kitten = $map->model(Kitten::class);

        foreach (['lives', 'trained', 'mews'] as $property) {
            self::assertTrue($animal->hasColumn($property));
            self::assertTrue($animal->column($property)->nullable);
        }
        self::assertTrue($cat->hasColumn('lives'));
        self::assertTrue($cat->hasColumn('mews'));
        self::assertTrue($cat->column('mews')->nullable);
        self::assertFalse($cat->hasColumn('trained'));
        self::assertTrue($dog->hasColumn('trained'));
        self::assertFalse($dog->hasColumn('lives'));
        self::assertFalse($dog->hasColumn('mews'));
        self::assertTrue($kitten->hasColumn('lives'));
        self::assertTrue($kitten->hasColumn('mews'));
        self::assertFalse($kitten->hasColumn('trained'));
        self::assertFalse($cat->column('lives')->nullable);
        self::assertFalse($kitten->column('lives')->nullable);
        self::assertFalse($kitten->column('mews')->nullable);
        self::assertSame(1, \count(\array_keys($this->columnNames('animals'), 'mews', true)));
        self::assertSame(1, \count(\array_keys($this->columnNames('animals'), 'lives', true)));
        self::assertSame(1, \count(\array_keys($this->columnNames('animals'), 'trained', true)));
    }

    public function testColumnsDeclaredOnTheRootAreOnEveryDerivedModel(): void
    {
        $map = $this->context->mapping();
        foreach ([Animal::class, Cat::class, Dog::class, Kitten::class] as $class) {
            $model = $map->model($class);
            foreach (['id', 'kind', 'name'] as $property) {
                self::assertTrue($model->hasColumn($property), $class . '.' . $property);
                self::assertFalse($model->column($property)->nullable, $class . '.' . $property);
            }
        }
    }

    public function testFindIgnoresARowOutsideTheDerivedType(): void
    {
        $dog = new Dog();
        $dog->name = 'Gus';
        $this->context->dogs->add($dog);
        $this->context->save();

        $fresh = new AdvancedContext('rhetoric', 1);
        self::assertNull($fresh->kittens->find($dog->id));
        self::assertNull($fresh->cats->find($dog->id));
    }

    public function testRefreshStillReadsATrashedRowByKey(): void
    {
        $post = new ArchivedPost();
        $post->title = 'gone';
        $this->context->archivedPosts->add($post);
        $this->context->save();
        $this->context->archivedPosts->remove($post);
        $this->context->save();
        $this->context->connection()->table('archived_posts')->update(['title' => 'edited']);

        $this->context->entry($post)->refresh();
        self::assertSame('edited', $post->title);
    }

    public function testScopeOnlyOnTheDerivedModelStaysOffTheRoot(): void
    {
        $this->context->connection()->table('vehicles')->insert(['tenant_id' => 1, 'kind' => 'car', 'name' => 'hidden', 'doors' => 2]);
        $this->context->connection()->table('vehicles')->insert(['tenant_id' => 1, 'kind' => 'car', 'name' => 'shown', 'doors' => 2]);

        self::assertSame(1, MappingScenarios::carCount($this->context));
        self::assertSame(2, $this->context->vehicles->count());
    }

    public function testQueryDeleteSoftDeletesAndRestoreClearsOnlyTrashedRows(): void
    {
        $first = new ArchivedPost();
        $first->title = 'one';
        $second = new ArchivedPost();
        $second->title = 'two';
        $this->context->archivedPosts->add($first);
        $this->context->archivedPosts->add($second);
        $this->context->save();

        self::assertSame(1, MappingScenarios::deleteTitled($this->context, 'one'));
        self::assertSame(2, $this->context->connection()->table('archived_posts')->count());
        self::assertSame(1, MappingScenarios::onlyTrashedCount($this->context));
        self::assertSame(1, MappingScenarios::onlyTrashedUnscopedCount($this->context));
        self::assertSame(1, MappingScenarios::restoreTrashed($this->context));
        self::assertSame(2, MappingScenarios::archivedCount($this->context));
        self::assertSame(2, $this->context->connection()->table('archived_posts')->count());
    }

    /**
     * @return array<int, string>
     */
    private function columnNames(string $table): array
    {
        $names = [];
        foreach ($this->context->connection()->select('pragma table_info(' . $table . ')') as $column) {
            if (\is_object($column) && isset($column->name) && \is_string($column->name)) {
                $names[] = $column->name;
            }
        }

        return $names;
    }
}
