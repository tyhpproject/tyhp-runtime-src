<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Migrations\DatabaseMigrationRepository;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tyhp\Rhetoric\Context;
use Tyhp\Rhetoric\Fixtures\Graph\BlogContext;
use Tyhp\Rhetoric\Fixtures\Migrations\ColumnContext;
use Tyhp\Rhetoric\Fixtures\Migrations\ForeignKeyContext;
use Tyhp\Rhetoric\Fixtures\Migrations\PivotContext;
use Tyhp\Rhetoric\Fixtures\Migrations\PriceContext;
use Tyhp\Rhetoric\Fixtures\Migrations\Priced;
use Tyhp\Rhetoric\Fixtures\Migrations\SeedBlogContext;
use Tyhp\Rhetoric\Fixtures\Migrations\SeedPaintedContext;
use Tyhp\Rhetoric\Fixtures\Migrations\TableContext;
use Tyhp\Rhetoric\Laravel\Commands\MigrationCommand;
use Tyhp\Rhetoric\Laravel\Commands\PendingCommand;
use Tyhp\Rhetoric\Laravel\Commands\RemoveMigrationCommand;
use Tyhp\Rhetoric\Mapping\MapCache;
use Tyhp\Rhetoric\Migrations\LaravelMigrationWriter;
use Tyhp\Rhetoric\Migrations\MapDiffer;
use Tyhp\Rhetoric\Migrations\MigrationPlan;
use Tyhp\Rhetoric\Migrations\MigrationWorkspace;
use Tyhp\Rhetoric\Migrations\Operation;
use Tyhp\Rhetoric\Migrations\OperationKind;
use Tyhp\Rhetoric\Migrations\KeySpec;
use Tyhp\Rhetoric\Migrations\SchemaExtractor;
use Tyhp\Rhetoric\Migrations\SnapshotStore;

final class MigrationTest extends TestCase
{
    private ?Manager $capsule = null;

    protected function setUp(): void
    {
        MapCache::clear();
        ColumnContext::$shape = 'plain';
        TableContext::$shape = 'posts';
        ForeignKeyContext::$shape = 'loose';
        PivotContext::$shape = 'plain';
    }

    protected function tearDown(): void
    {
        MapCache::clear();
    }

    public function testAddColumnRoundTrips(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(ColumnContext::class, 'plain', 'rated', 'add_post_rating');
        $source = $this->source($result->path);
        self::assertStringContainsString("integer('rating')", $source);
        self::assertStringContainsString('default(0)', $source);
        self::assertStringContainsString("comment('stars')", $source);
        self::assertFalse($connection->getSchemaBuilder()->hasColumn('posts', 'rating'));
        $this->runMigration($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'rating'));
        $this->rollback($migrator, $path);
        self::assertFalse($connection->getSchemaBuilder()->hasColumn('posts', 'rating'));
    }

    public function testDropColumnRoundTripsAndWarns(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(ColumnContext::class, 'rated', 'plain', 'drop_post_rating');
        self::assertNotSame([], $result->plan->warnings);
        self::assertStringContainsString('loses data', \implode("\n", $result->plan->warnings));
        $this->runMigration($migrator, $path);
        self::assertFalse($connection->getSchemaBuilder()->hasColumn('posts', 'rating'));
        $this->rollback($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'rating'));
    }

    public function testAlterColumnRoundTripsAndFlagsNarrowing(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(ColumnContext::class, 'long', 'short', 'narrow_post_title');
        $source = $this->source($result->path);
        self::assertStringContainsString("string('title', 40)", $source);
        self::assertStringContainsString('->change()', $source);
        self::assertStringContainsString("string('title', 255)", $source);
        self::assertStringContainsString('may lose data', \implode("\n", $result->plan->warnings));
        $this->runMigration($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'title'));
        $this->rollback($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'title'));
    }

    public function testAddTableRoundTrips(): void
    {
        [$connection, $migrator, $path] = $this->delta(TableContext::class, 'none', 'posts', 'create_posts');
        $this->runMigration($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasTable('posts'));
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'title'));
        $this->rollback($migrator, $path);
        self::assertFalse($connection->getSchemaBuilder()->hasTable('posts'));
    }

    public function testDropTableRoundTripsAndWarns(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(TableContext::class, 'posts', 'none', 'drop_posts');
        self::assertStringContainsString('Dropping table posts loses data.', \implode("\n", $result->plan->warnings));
        $this->runMigration($migrator, $path);
        self::assertFalse($connection->getSchemaBuilder()->hasTable('posts'));
        $this->rollback($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasTable('posts'));
    }

    public function testAddForeignKeyRoundTripsAfterBothTablesExist(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(ForeignKeyContext::class, 'loose', 'linked', 'add_post_blog_foreign');
        $source = $this->source($result->path);
        self::assertStringContainsString("foreign(['blog_id'])", $source);
        self::assertStringContainsString("dropForeign(['blog_id'])", $source);
        self::assertStringContainsString("->on('blogs')", $source);
        self::assertStringNotContainsString('onDelete', $source);
        self::assertStringNotContainsString('cascade', $source);
        self::assertSame([], $connection->select('pragma foreign_key_list(posts)'));
        $this->runMigration($migrator, $path);
        $keys = $connection->select('pragma foreign_key_list(posts)');
        self::assertNotSame([], $keys);
        self::assertSame('blogs', $keys[0]->table);
        self::assertSame('blog_id', $keys[0]->from);
        $this->rollback($migrator, $path);
        self::assertSame([], $connection->select('pragma foreign_key_list(posts)'));
    }

    public function testAddUniqueIndexRoundTrips(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(ColumnContext::class, 'slug', 'unique', 'add_post_slug_unique');
        self::assertStringContainsString('posts_slug_unique', $this->source($result->path));
        $this->runMigration($migrator, $path);
        self::assertTrue($this->uniqueIndex($connection, 'posts', 'posts_slug_unique'));
        $this->rollback($migrator, $path);
        self::assertFalse($this->uniqueIndex($connection, 'posts', 'posts_slug_unique'));
    }

    public function testRemovedColumnOfTheSameTypeWarnsUntilRenamed(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(ColumnContext::class, 'title', 'name', 'replace_post_title');
        $warning = \implode("\n", $result->plan->warnings);
        self::assertStringContainsString('--rename=title:name', $warning);
        self::assertStringContainsString("dropColumn('title')", $this->source($result->path));
        self::assertStringContainsString("string('name', 255)", $this->source($result->path));
        $this->runMigration($migrator, $path);
        self::assertFalse($connection->getSchemaBuilder()->hasColumn('posts', 'title'));
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'name'));
        $this->rollback($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'title'));
        self::assertFalse($connection->getSchemaBuilder()->hasColumn('posts', 'name'));
    }

    public function testRenameColumnRoundTrips(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(ColumnContext::class, 'title', 'name', 'rename_post_title', ['title:name']);
        $source = $this->source($result->path);
        self::assertStringContainsString("renameColumn('title', 'name')", $source);
        self::assertStringNotContainsString('dropColumn', $source);
        $this->runMigration($migrator, $path);
        self::assertFalse($connection->getSchemaBuilder()->hasColumn('posts', 'title'));
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'name'));
        $this->rollback($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasColumn('posts', 'title'));
        self::assertFalse($connection->getSchemaBuilder()->hasColumn('posts', 'name'));
    }

    public function testManyToManyPivotRoundTrips(): void
    {
        [$connection, $migrator, $path, $result] = $this->delta(PivotContext::class, 'plain', 'linked', 'create_post_tag');
        $source = $this->source($result->path);
        self::assertStringContainsString("'post_tag'", $source);
        self::assertStringContainsString("'post_id'", $source);
        self::assertStringContainsString("'tag_id'", $source);
        self::assertStringContainsString("foreign(['post_id'])", $source);
        self::assertStringContainsString("foreign(['tag_id'])", $source);
        self::assertStringNotContainsString('onDelete', $source);
        $this->runMigration($migrator, $path);
        self::assertTrue($connection->getSchemaBuilder()->hasTable('post_tag'));
        self::assertSame(['post_id', 'tag_id'], $connection->getSchemaBuilder()->getColumnListing('post_tag'));
        $this->rollback($migrator, $path);
        self::assertFalse($connection->getSchemaBuilder()->hasTable('post_tag'));
    }

    public function testGraphPivotMigrationUsesTheMappedNames(): void
    {
        $this->sqlite();
        $context = new BlogContext('default');
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $workspace = new MigrationWorkspace(new SnapshotStore($dir . '/snapshots'), $migrations);
        $result = $workspace->add($context, 'create_graph', [], '2026_11_04_120000');
        self::assertNotNull($result->path);
        $source = $this->source($result->path);
        self::assertStringContainsString("'post_tag'", $source);
        self::assertStringContainsString("'post_id'", $source);
        self::assertStringContainsString("'tag_id'", $source);
        $migrator = $this->migrator();
        $this->runMigration($migrator, $migrations);
        $schema = $context->connection()->getSchemaBuilder();
        self::assertTrue($schema->hasTable('post_tag'));
        self::assertSame(['post_id', 'tag_id'], $schema->getColumnListing('post_tag'));
        $again = MapDiffer::diff(
            $workspace->snapshots()->load(BlogContext::class),
            SchemaExtractor::extract($context->mapping()),
            [],
        );
        self::assertSame([], $again->operations);
        $this->rollback($migrator, $migrations);
        self::assertFalse($schema->hasTable('post_tag'));
    }

    public function testSeedInsertsAndCreateSchemaUseTheSameOperations(): void
    {
        $this->sqlite();
        $context = new SeedBlogContext();
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $workspace = new MigrationWorkspace(new SnapshotStore($dir . '/snapshots'), $migrations);
        $result = $workspace->add($context, 'seed_blogs', [], '2026_11_04_120000');
        self::assertNotNull($result->path);
        self::assertStringContainsString("'rhetoric'", $this->source($result->path));
        $migrator = $this->migrator();
        $this->runMigration($migrator, $migrations);
        self::assertSame('rhetoric', $context->connection()->table('blogs')->value('name'));
        $this->rollback($migrator, $migrations);
        self::assertFalse($context->connection()->getSchemaBuilder()->hasTable('blogs'));

        $context->createSchema();
        self::assertSame('rhetoric', $context->connection()->table('blogs')->value('name'));
        $context->dropSchema();
        self::assertFalse($context->connection()->getSchemaBuilder()->hasTable('blogs'));
    }

    public function testIrreversibleDownThrows(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'rated';
        MapCache::clear();
        $context = new ColumnContext();
        $context->createSchema();
        $column = SchemaExtractor::extract($context->mapping())->table('posts')?->column('rating');
        self::assertNotNull($column);
        $plan = new MigrationPlan([
            new Operation(OperationKind::DropColumn, 'posts', false, '', $column),
        ], []);
        $source = LaravelMigrationWriter::source(ColumnContext::class, 'from', 'to', $plan);
        self::assertStringContainsString('This Rhetoric migration cannot be reversed.', $source);
        $migrations = $this->temp() . '/migrations';
        \mkdir($migrations, 0777, true);
        $path = $migrations . '/2026_11_04_120000_drop_rating.php';
        \file_put_contents($path, $source);
        $migrator = $this->migrator();
        $this->runMigration($migrator, $migrations);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This Rhetoric migration cannot be reversed.');
        $migrator->rollback([$migrations]);
    }

    public function testCommandsExistAndPendingExitsNonZeroUntilTheSnapshotMatches(): void
    {
        self::assertSame('rhetoric:migration', (new MigrationCommand())->getName());
        self::assertSame('rhetoric:remove-migration', (new RemoveMigrationCommand())->getName());
        self::assertSame('rhetoric:pending', (new PendingCommand())->getName());

        $this->sqlite();
        ColumnContext::$shape = 'rated';
        MapCache::clear();
        $dir = $this->temp();
        $snapshots = $dir . '/snapshots';
        $migrations = $dir . '/migrations';
        [$code, $text] = $this->artisan('rhetoric:pending', [
            '--context' => ColumnContext::class,
            '--snapshot' => $snapshots,
            '--path' => $migrations,
        ]);
        self::assertSame(1, $code);
        self::assertStringContainsString('differs from the snapshot', $text);

        [$code, $text] = $this->artisan('rhetoric:migration', [
            'name' => 'create_rated_posts',
            '--context' => ColumnContext::class,
            '--snapshot' => $snapshots,
            '--path' => $migrations,
        ]);
        self::assertSame(0, $code);
        self::assertStringContainsString('create_rated_posts.php', $text);

        [$code, $text] = $this->artisan('rhetoric:pending', [
            '--context' => ColumnContext::class,
            '--snapshot' => $snapshots,
            '--path' => $migrations,
        ]);
        self::assertSame(0, $code);
        self::assertStringContainsString('Mapping matches the snapshot.', $text);

        $workspace = new MigrationWorkspace(new SnapshotStore($snapshots), $migrations);
        self::assertInstanceOf(Manager::class, $this->capsule);
        $resolver = $this->capsule->getDatabaseManager();
        $repository = new DatabaseMigrationRepository($resolver, 'migrations');
        $repository->createRepository();
        $migrator = new Migrator($repository, $resolver, new Filesystem());
        $this->runMigration($migrator, $migrations);
        $context = new ColumnContext();
        try {
            $workspace->remove($context, $repository);
            self::fail('An applied migration was removed.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('has been applied', $exception->getMessage());
        }
        $this->rollback($migrator, $migrations);
        $removed = $workspace->remove($context, $repository);
        self::assertFileDoesNotExist($removed);
        self::assertFalse($workspace->pending($context)->clean());
    }

    public function testMysqlAndPostgresGrammarsAcceptTheGeneratedSql(): void
    {
        $this->sqlite();
        ForeignKeyContext::$shape = 'linked';
        MapCache::clear();
        $context = new ForeignKeyContext();
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $result = (new MigrationWorkspace(new SnapshotStore($dir . '/snapshots'), $migrations))
            ->add($context, 'create_linked_posts', [], '2026_11_04_120000');
        self::assertNotNull($result->path);
        $migration = require $result->path;
        self::assertIsObject($migration);

        foreach (['mysql' => \Illuminate\Database\MySqlConnection::class, 'pgsql' => \Illuminate\Database\PostgresConnection::class] as $driver => $class) {
            $pdo = new \PDO('sqlite::memory:');
            $connection = new $class($pdo, 'rhetoric', '', ['driver' => $driver]);
            $app = new Container();
            Facade::setFacadeApplication($app);
            Facade::clearResolvedInstance('db.schema');
            $app->instance('db.schema', $connection->getSchemaBuilder());
            $queries = $connection->pretend(static function () use ($migration): void {
                $migration->up();
            });
            $sql = '';
            foreach ($queries as $query) {
                if (\is_array($query) && isset($query['query']) && \is_string($query['query'])) {
                    $sql .= ' ' . $query['query'];
                }
            }
            self::assertNotSame('', \trim($sql));
            self::assertStringContainsString('posts', \strtolower($sql));
            self::assertStringContainsString('blogs', \strtolower($sql));
        }

        $mysql = \getenv('RHETORIC_TEST_MYSQL_DSN');
        if (\is_string($mysql) && $mysql !== '') {
            $this->live($mysql, 'mysql');
        }
        $pgsql = \getenv('RHETORIC_TEST_PGSQL_DSN');
        if (\is_string($pgsql) && $pgsql !== '') {
            $this->live($pgsql, 'pgsql');
        }
    }

    public function testPendingStaysCleanAfterASnapshotOnlyUpdate(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $dir = $this->temp();
        $workspace = new MigrationWorkspace(new SnapshotStore($dir . '/snapshots'), $dir . '/migrations');
        $context = new ColumnContext();
        $first = $workspace->add($context, 'create_posts', [], '2026_11_04_120000');
        self::assertNotNull($first->path);
        self::assertTrue($workspace->pending($context)->clean());

        // Same table and columns on a different model class: the hash changes, the schema does not.
        ColumnContext::$shape = 'long';
        MapCache::clear();
        self::assertFalse($workspace->pending($context)->clean());
        $second = $workspace->add($context, 'rename_model', [], '2026_11_04_120001');
        self::assertNull($second->path);
        self::assertTrue($second->snapshotUpdated);
        self::assertSame([], $workspace->pending($context)->reasons);
    }

    public function testPendingStillFlagsALatestMigrationWhoseSchemaDiffersFromTheSnapshot(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $dir = $this->temp();
        $store = new SnapshotStore($dir . '/snapshots');
        $workspace = new MigrationWorkspace($store, $dir . '/migrations');
        $context = new ColumnContext();
        $workspace->add($context, 'create_posts', [], '2026_11_04_120000');

        // A snapshot from another branch that has no migration here.
        ColumnContext::$shape = 'rated';
        MapCache::clear();
        $store->save(SchemaExtractor::extract($context->mapping()));
        $report = $workspace->pending($context);
        self::assertSame(['The latest migration does not match the snapshot.'], $report->reasons);
    }

    public function testPendingDoesNotFailForeverOverChangesThatAreNeverMigrated(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $dir = $this->temp();
        $store = new SnapshotStore($dir . '/snapshots');
        $workspace = new MigrationWorkspace($store, $dir . '/migrations');
        $context = new ColumnContext();
        $first = $workspace->add($context, 'create_posts', [], '2026_11_04_120000');
        self::assertNotNull($first->path);

        // The migration was written before the table gained a comment; the mapping has it now and the snapshot was updated.
        $older = SchemaExtractor::extract($context->mapping());
        $older->tables[0]->comment = 'Older comment';
        $store->remember($older);
        $source = \file_get_contents($first->path);
        self::assertIsString($source);
        $header = MigrationWorkspace::header($first->path);
        self::assertNotNull($header);
        \file_put_contents($first->path, \str_replace(' * Snapshot: ' . $header->snapshot, ' * Snapshot: ' . $older->hash(), $source));

        $report = $workspace->pending($context);
        self::assertSame([], $report->reasons);
        self::assertStringContainsString('comment on posts changed', \implode("\n", $report->warnings));
    }

    public function testPendingWarnsAboutAPrimaryKeyChangeThatIsNeverMigratedWithoutFailing(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $dir = $this->temp();
        $store = new SnapshotStore($dir . '/snapshots');
        $workspace = new MigrationWorkspace($store, $dir . '/migrations');
        $context = new ColumnContext();
        $first = $workspace->add($context, 'create_posts', [], '2026_11_04_120000');
        self::assertNotNull($first->path);

        $older = SchemaExtractor::extract($context->mapping());
        $older->tables[0]->key = new \Tyhp\Rhetoric\Migrations\KeySpec(['title'], false);
        $store->remember($older);
        $source = \file_get_contents($first->path);
        self::assertIsString($source);
        $header = MigrationWorkspace::header($first->path);
        self::assertNotNull($header);
        \file_put_contents($first->path, \str_replace(' * Snapshot: ' . $header->snapshot, ' * Snapshot: ' . $older->hash(), $source));

        $report = $workspace->pending($context);
        self::assertSame([], $report->reasons);
        self::assertStringContainsString('primary key of posts changed', \implode("\n", $report->warnings));
    }

    public function testPendingIgnoresAnOnDeleteOnlyDifferenceInTheLatestMigration(): void
    {
        $this->sqlite();
        ForeignKeyContext::$shape = 'linked';
        MapCache::clear();
        $dir = $this->temp();
        $store = new SnapshotStore($dir . '/snapshots');
        $workspace = new MigrationWorkspace($store, $dir . '/migrations');
        $context = new ForeignKeyContext();
        $first = $workspace->add($context, 'create_linked_posts', [], '2026_11_04_120000');
        self::assertNotNull($first->path);

        $older = SchemaExtractor::extract($context->mapping());
        $changed = false;
        foreach ($older->tables as $table) {
            foreach ($table->foreignKeys as $foreignKey) {
                $foreignKey->onDelete = $foreignKey->onDelete === 'cascade' ? 'restrict' : 'cascade';
                $changed = true;
            }
        }
        self::assertTrue($changed);
        self::assertNotSame($older->hash(), $header = MigrationWorkspace::header($first->path)?->snapshot);
        $store->remember($older);
        $source = \file_get_contents($first->path);
        self::assertIsString($source);
        \file_put_contents($first->path, \str_replace(' * Snapshot: ' . $header, ' * Snapshot: ' . $older->hash(), $source));

        $report = $workspace->pending($context);
        self::assertSame([], $report->reasons);
        self::assertSame([], $report->warnings);
    }

    public function testForeignKeyDropUsesTheNameTheTableCreatedUnderAPrefix(): void
    {
        $this->sqlite();
        ForeignKeyContext::$shape = 'linked';
        MapCache::clear();
        $dir = $this->temp();
        $result = (new MigrationWorkspace(new SnapshotStore($dir . '/snapshots'), $dir . '/migrations'))
            ->add(new ForeignKeyContext(), 'create_linked_posts', [], '2026_11_04_120000');
        self::assertNotNull($result->path);
        $migration = require $result->path;
        self::assertIsObject($migration);

        foreach ([
            'mysql' => \Illuminate\Database\MySqlConnection::class,
            'pgsql' => \Illuminate\Database\PostgresConnection::class,
        ] as $driver => $class) {
            foreach (['', 'app_'] as $prefix) {
                $connection = new $class(new \PDO('sqlite::memory:'), 'rhetoric', $prefix, [
                    'driver' => $driver,
                    'prefix' => $prefix,
                    'prefix_indexes' => true,
                ]);
                $app = new Container();
                Facade::setFacadeApplication($app);
                Facade::clearResolvedInstance('db.schema');
                $app->instance('db.schema', $connection->getSchemaBuilder());
                $up = $this->pretended($connection, $migration, 'up');
                $down = $this->pretended($connection, $migration, 'down');
                self::assertSame(1, \preg_match('/add constraint [`"]([^`"]+)[`"] foreign key/', $up, $created), $driver . ' ' . $prefix . ': ' . $up);
                self::assertStringContainsString('"' . $created[1] . '"', \str_replace('`', '"', $down), $driver . ' ' . $prefix . ': ' . $down);
            }
        }
    }

    public function testAForeignKeyWithAnotherNameIsCreatedAndDroppedByThatName(): void
    {
        $foreignKey = new \Tyhp\Rhetoric\Migrations\ForeignKeySpec('fk_owner', ['blog_id'], 'blogs', ['id'], 'restrict');
        self::assertSame(
            "\$table->foreign(['blog_id'], 'fk_owner')->references('id')->on('blogs')",
            \Tyhp\Rhetoric\Migrations\ColumnBlueprint::foreign('posts', $foreignKey),
        );
        self::assertSame(
            "\$table->dropForeign('fk_owner');",
            \Tyhp\Rhetoric\Migrations\ColumnBlueprint::dropForeign('posts', $foreignKey),
        );
    }

    public function testRenameForATableThatIsNotInBothSnapshotsIsRejected(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'title';
        MapCache::clear();
        $dir = $this->temp();
        $store = new SnapshotStore($dir . '/snapshots');
        $context = new ColumnContext();
        $store->save(SchemaExtractor::extract($context->mapping()));
        ColumnContext::$shape = 'name';
        MapCache::clear();
        $workspace = new MigrationWorkspace($store, $dir . '/migrations');
        try {
            $workspace->add($context, 'rename_title', ['postz.title:name'], '2026_11_04_120000');
            self::fail('A rename for an unknown table was ignored.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('postz', $exception->getMessage());
        }
        self::assertSame([], \glob($dir . '/migrations/*.php') ?: []);
    }

    public function testCastColumnTypeComesFromTheCast(): void
    {
        $this->sqlite();
        MapCache::clear();
        $context = new PriceContext();
        $priced = $context->mapping()->model(Priced::class);
        self::assertTrue($priced->hasColumn('price'));
        self::assertSame([], \array_keys($priced->relations));
        self::assertNotNull($priced->column('price')->cast);
        $column = SchemaExtractor::extract($context->mapping())->table('prices')?->column('price');
        self::assertNotNull($column);
        self::assertSame('integer', $column->blueprint);

        $context->createSchema();
        $stored = $context->connection()->getSchemaBuilder()->getColumns('prices');
        $types = [];
        foreach ($stored as $info) {
            $types[$info['name']] = $info['type_name'];
        }
        self::assertSame('integer', $types['price']);
    }

    public function testExplicitBuiltInCastsKeepTheColumnTypeOfTheirProperty(): void
    {
        $this->sqlite();
        MapCache::clear();
        $table = SchemaExtractor::extract((new \Tyhp\Rhetoric\Fixtures\Migrations\FlagContext())->mapping())->table('flagged');
        self::assertNotNull($table);
        self::assertSame('boolean', $table->column('active')?->blueprint);
        self::assertSame('dateTime', $table->column('seen_at')?->blueprint);
    }

    public function testOnlyTheFourBuiltInCastsKeepThePropertyColumnType(): void
    {
        $alias = 'Tyhp\\Rhetoric\\Casts\\AliasedMoneyCast';
        if (!\class_exists($alias, false)) {
            \class_alias(\Tyhp\Rhetoric\Fixtures\Migrations\MoneyCast::class, $alias);
        }
        $storage = new \ReflectionMethod(SchemaExtractor::class, 'castStorage');
        $stored = static fn (string $type, string $cast): ?string => $storage->invoke(
            null,
            new \Tyhp\Rhetoric\Mapping\ColumnMap('value', 'value', $type, false, null, null, null, false, null, null, false, null, false, null, $cast),
        );

        self::assertNull($stored('bool', \Tyhp\Rhetoric\Casts\BoolCast::class));
        self::assertNull($stored(\DateTimeImmutable::class, \Tyhp\Rhetoric\Casts\DateTimeImmutableCast::class));
        self::assertNull($stored('int', \Tyhp\Rhetoric\Casts\IntCast::class));
        self::assertNull($stored(\BackedEnum::class, \Tyhp\Rhetoric\Casts\BackedEnumCast::class));
        self::assertSame('int', $stored(\Tyhp\Rhetoric\Fixtures\Migrations\Money::class, \Tyhp\Rhetoric\Fixtures\Migrations\MoneyCast::class));
        self::assertSame('int', $stored(\Tyhp\Rhetoric\Fixtures\Migrations\Money::class, $alias));
    }

    public function testAFluentCastOnAClassTypedPropertyMakesAColumnAndNoExtraTable(): void
    {
        $this->sqlite();
        MapCache::clear();
        $context = new \Tyhp\Rhetoric\Fixtures\Migrations\BilledContext();
        $map = $context->mapping();
        $billed = $map->model(\Tyhp\Rhetoric\Fixtures\Migrations\Billed::class);
        self::assertTrue($billed->hasColumn('price'));
        self::assertSame([], \array_keys($billed->relations));
        self::assertSame([], $billed->foreignKeys);
        self::assertSame([\Tyhp\Rhetoric\Fixtures\Migrations\Billed::class], \array_keys($map->models));

        $document = SchemaExtractor::extract($map);
        self::assertSame(['billed'], \array_map(static fn ($table) => $table->table, $document->tables));
        self::assertSame('integer', $document->table('billed')?->column('price')?->blueprint);
    }

    public function testSeedRowsStoreDatabaseValues(): void
    {
        $this->sqlite();
        MapCache::clear();
        $context = new SeedPaintedContext();
        $document = SchemaExtractor::extract($context->mapping());
        self::assertSame(
            [['id' => 1, 'color' => 'blue', 'painted_at' => '2020-01-02 03:04:05', 'active' => 1]],
            $document->tables[0]->seeds,
        );

        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $workspace = new MigrationWorkspace(new SnapshotStore($dir . '/snapshots'), $migrations);
        $result = $workspace->add($context, 'seed_painted', [], '2026_11_04_120000');
        self::assertNotNull($result->path);
        $source = $this->source($result->path);
        self::assertStringNotContainsString('Color::', $source);
        self::assertStringNotContainsString('__set_state', $source);
        $again = MapDiffer::diff(
            $workspace->snapshots()->load(SeedPaintedContext::class),
            SchemaExtractor::extract($context->mapping()),
            [],
        );
        self::assertSame([], $again->operations);

        $migrator = $this->migrator();
        $this->runMigration($migrator, $migrations);
        $row = $context->connection()->table($document->tables[0]->table)->first();
        self::assertNotNull($row);
        self::assertSame('blue', $row->color);
        self::assertSame('2020-01-02 03:04:05', $row->painted_at);
        self::assertSame(1, (int) $row->active);
        $this->rollback($migrator, $migrations);
        self::assertFalse($context->connection()->getSchemaBuilder()->hasTable($document->tables[0]->table));
    }

    public function testEnumDefaultIsStoredAsTheColumnValueAndSurvivesTheSnapshot(): void
    {
        $this->sqlite();
        MapCache::clear();
        $context = new \Tyhp\Rhetoric\Fixtures\Migrations\CastContext();
        $painted = $context->mapping()->model(\Tyhp\Rhetoric\Fixtures\Migrations\Painted::class);
        $color = SchemaExtractor::extract($context->mapping())->table($painted->table)?->column('color');
        self::assertNotNull($color);
        self::assertTrue($color->hasDefault);
        self::assertSame('red', $color->default);

        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $workspace = new MigrationWorkspace(new SnapshotStore($dir . '/snapshots'), $migrations);
        $result = $workspace->add($context, 'create_painted', [], '2026_11_04_120000');
        self::assertNotNull($result->path);
        $source = $this->source($result->path);
        self::assertStringContainsString("->default('red')", $source);
        self::assertStringNotContainsString('Color::', $source);
        $again = MapDiffer::diff(
            $workspace->snapshots()->load(\Tyhp\Rhetoric\Fixtures\Migrations\CastContext::class),
            SchemaExtractor::extract($context->mapping()),
            [],
        );
        self::assertSame([], $again->operations);
        self::assertSame([], $workspace->pending($context)->reasons);
    }

    public function testDroppingTimestampsWarns(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $context = new ColumnContext();
        $before = SchemaExtractor::extract($context->mapping());
        $after = SchemaExtractor::extract($context->mapping());
        $before->tables[0]->timestamps = true;
        $plan = MapDiffer::diff($before, $after, []);
        self::assertSame('Dropping the timestamps of posts loses data.', $plan->warnings[0] ?? '');
        self::assertStringContainsString('Dropping the timestamps of posts loses data.', LaravelMigrationWriter::source(ColumnContext::class, 'a', 'b', $plan));
    }

    public function testChangedSeedValuesAreNotMistakenForUnchangedOnes(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $context = new ColumnContext();
        $before = SchemaExtractor::extract($context->mapping());
        $after = SchemaExtractor::extract($context->mapping());
        $before->tables[0]->seeds = [['id' => 1, 'title' => null]];
        $after->tables[0]->seeds = [['id' => 1, 'title' => '']];
        $plan = MapDiffer::diff($before, $after, []);
        $kinds = [];
        foreach ($plan->operations as $operation) {
            $kinds[] = $operation->kind;
        }
        self::assertSame([OperationKind::SeedDelete, OperationKind::SeedInsert], $kinds);
    }

    public function testKeyAndTableCommentChangesOnAnExistingTableWarn(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $context = new ColumnContext();
        $before = SchemaExtractor::extract($context->mapping());
        $after = SchemaExtractor::extract($context->mapping());
        $after->tables[0]->key = new KeySpec(['id', 'title'], false);
        $after->tables[0]->comment = 'All posts';
        $plan = MapDiffer::diff($before, $after, []);
        $warnings = \implode("\n", $plan->warnings);
        self::assertStringContainsString('primary key of posts changed', $warnings);
        self::assertStringContainsString('comment on posts changed', $warnings);
    }

    public function testCommandsDefaultToTheApplicationDatabasePath(): void
    {
        $this->sqlite();
        ColumnContext::$shape = 'rated';
        MapCache::clear();
        $dir = $this->temp();
        [$code, $text] = $this->artisan('rhetoric:migration', [
            'name' => 'create_rated_posts',
            '--context' => ColumnContext::class,
        ], ['path.database' => $dir]);
        self::assertSame(0, $code, $text);
        self::assertCount(1, \glob($dir . '/migrations/*_create_rated_posts.php') ?: []);
        self::assertFileExists($dir . '/rhetoric/ColumnContext.snapshot.json');
    }

    public function testRemoveUsesTheApplicationsMigrationRepository(): void
    {
        $capsule = $this->sqlite();
        ColumnContext::$shape = 'rated';
        MapCache::clear();
        $dir = $this->temp();
        $snapshots = $dir . '/snapshots';
        $migrations = $dir . '/migrations';
        $this->artisan('rhetoric:migration', [
            'name' => 'create_rated_posts',
            '--context' => ColumnContext::class,
            '--snapshot' => $snapshots,
            '--path' => $migrations,
        ]);
        $resolver = $capsule->getDatabaseManager();
        $repository = new DatabaseMigrationRepository($resolver, 'custom_migrations');
        $repository->createRepository();
        $migrator = new Migrator($repository, $resolver, new Filesystem());
        $migrator->setConnection('default');
        $this->runMigration($migrator, $migrations);

        [$code, $text] = $this->artisan('rhetoric:remove-migration', [
            '--context' => ColumnContext::class,
            '--snapshot' => $snapshots,
            '--path' => $migrations,
        ], ['migration.repository' => $repository]);
        self::assertSame(1, $code);
        self::assertStringContainsString('has been applied', $text);
        self::assertCount(1, \glob($migrations . '/*.php') ?: []);
    }

    private function pretended(\Illuminate\Database\Connection $connection, object $migration, string $method): string
    {
        $queries = $connection->pretend(static function () use ($migration, $method): void {
            $migration->{$method}();
        });
        $sql = '';
        foreach ($queries as $query) {
            if (\is_array($query) && isset($query['query']) && \is_string($query['query'])) {
                $sql .= ' ' . $query['query'];
            }
        }

        return $sql;
    }

    private function live(string $dsn, string $expectedDriver): void
    {
        $parts = \parse_url($dsn);
        self::assertIsArray($parts);
        $scheme = $parts['scheme'] ?? '';
        if ($scheme === 'postgres' || $scheme === 'postgresql') {
            $scheme = 'pgsql';
        }
        self::assertSame($expectedDriver, $scheme);
        $prefix = 'rhetoric_' . \bin2hex(\random_bytes(4)) . '_';
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => $scheme,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => $parts['port'] ?? ($scheme === 'mysql' ? 3306 : 5432),
            'database' => \ltrim((string) ($parts['path'] ?? ''), '/'),
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
            'prefix' => $prefix,
            'foreign_key_constraints' => true,
        ], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
        $this->bindFacades($capsule);
        $this->capsule = $capsule;
        ColumnContext::$shape = 'plain';
        MapCache::clear();
        $context = new ColumnContext();
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $store = new SnapshotStore($dir . '/snapshots');
        $context->createSchema();
        $store->save(SchemaExtractor::extract($context->mapping()));
        ColumnContext::$shape = 'rated';
        MapCache::clear();
        $result = (new MigrationWorkspace($store, $migrations))->add($context, 'add_post_rating', [], '2026_11_04_120000');
        self::assertNotNull($result->path);
        $migrator = $this->migrator();
        $this->runMigration($migrator, $migrations);
        self::assertTrue($context->connection()->getSchemaBuilder()->hasColumn('posts', 'rating'));
        $this->rollback($migrator, $migrations);
        $context->connection()->getSchemaBuilder()->dropAllTables();
    }

    /**
     * @param class-string<Context> $class
     * @param array<int, string> $renames
     * @return array{0: \Illuminate\Database\Connection, 1: Migrator, 2: string, 3: \Tyhp\Rhetoric\Migrations\MigrationResult}
     */
    private function delta(string $class, string $before, string $after, string $name, array $renames = []): array
    {
        $class::$shape = $before;
        MapCache::clear();
        $this->sqlite();
        $context = new $class();
        $dir = $this->temp();
        $migrations = $dir . '/migrations';
        $store = new SnapshotStore($dir . '/snapshots');
        $context->createSchema();
        $store->save(SchemaExtractor::extract($context->mapping()));
        $class::$shape = $after;
        MapCache::clear();
        $workspace = new MigrationWorkspace($store, $migrations);
        $result = $workspace->add($context, $name, $renames, '2026_11_04_120000');
        self::assertNotNull($result->path);
        self::assertNotSame([], $result->plan->operations);
        $again = MapDiffer::diff(
            $workspace->snapshots()->load($class),
            SchemaExtractor::extract($context->mapping()),
            [],
        );
        self::assertSame([], $again->operations);
        $migrator = $this->migrator();

        return [$context->connection(), $migrator, $migrations, $result];
    }

    private function sqlite(): Manager
    {
        $capsule = new Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ], 'default');
        $capsule->setAsGlobal();
        $capsule->getDatabaseManager()->setDefaultConnection('default');
        $this->bindFacades($capsule);
        $this->capsule = $capsule;

        return $capsule;
    }

    private function bindFacades(Manager $capsule): void
    {
        $app = $capsule->getContainer();
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstance('db');
        Facade::clearResolvedInstance('db.schema');
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance('db.schema', $capsule->getConnection('default')->getSchemaBuilder());
    }

    private function migrator(): Migrator
    {
        self::assertInstanceOf(Manager::class, $this->capsule);
        $resolver = $this->capsule->getDatabaseManager();
        $repository = new DatabaseMigrationRepository($resolver, 'migrations');
        if (!$repository->repositoryExists()) {
            $repository->createRepository();
        }
        $migrator = new Migrator($repository, $resolver, new Filesystem());
        $migrator->setConnection('default');

        return $migrator;
    }

    private function runMigration(Migrator $migrator, string $path): void
    {
        $ran = $migrator->run([$path]);
        self::assertNotSame([], $ran);
    }

    private function rollback(Migrator $migrator, string $path): void
    {
        $rolled = $migrator->rollback([$path]);
        self::assertNotSame([], $rolled);
    }

    private function source(?string $path): string
    {
        self::assertNotNull($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);
        self::assertStringNotContainsString('$deferred', $contents);
        self::assertStringNotContainsString('$independent', $contents);

        return $contents;
    }

    private function uniqueIndex(\Illuminate\Database\Connection $connection, string $table, string $name): bool
    {
        foreach ($connection->select('pragma index_list(' . $table . ')') as $index) {
            if (($index->name ?? null) === $name && (int) ($index->unique ?? 0) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $arguments
     * @param array<string, mixed> $bindings
     * @return array{int, string}
     */
    private function artisan(string $command, array $arguments, array $bindings = []): array
    {
        $container = new class extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        foreach ($bindings as $abstract => $instance) {
            $container->instance($abstract, $instance);
        }
        $application = new Application($container, new Dispatcher($container), '1');
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->add(new MigrationCommand());
        $application->add(new RemoveMigrationCommand());
        $application->add(new PendingCommand());
        $output = new BufferedOutput();
        $code = $application->run(new ArrayInput(['command' => $command] + $arguments), $output);

        return [$code, $output->fetch()];
    }

    private function temp(): string
    {
        $dir = \sys_get_temp_dir() . '/rhetoric-phase6-' . \bin2hex(\random_bytes(6));
        if (!\mkdir($dir, 0777, true) && !\is_dir($dir)) {
            self::fail('Could not create ' . $dir);
        }

        return $dir;
    }
}
