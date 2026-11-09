<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\AdvancedContext;
use Tyhp\Rhetoric\Fixtures\Blog;
use Tyhp\Rhetoric\Fixtures\ConventionBlogContext;
use Tyhp\Rhetoric\Fixtures\Memo;
use Tyhp\Rhetoric\Fixtures\PolishScenarios;
use Tyhp\Rhetoric\Fixtures\QueryContext;
use Tyhp\Rhetoric\Laravel\RhetoricServiceProvider;
use Tyhp\Rhetoric\Mapping\ColumnMap;
use Tyhp\Rhetoric\Mapping\KeyMap;
use Tyhp\Rhetoric\Mapping\MapCache;
use Tyhp\Rhetoric\Mapping\MappingArchive;
use Tyhp\Rhetoric\Mapping\ModelMap;
use Tyhp\Rhetoric\Query\Hydrator;
use Tyhp\Rhetoric\Migrations\Auto\AutoMigrator;

final class PolishTest extends TestCase
{
    private string $mapping;

    private string $manifest;

    protected function setUp(): void
    {
        MapCache::clear();
        AutoMigrator::flush();
        $this->mapping = \tempnam(\sys_get_temp_dir(), 'rhetoric-map');
        $this->manifest = \tempnam(\sys_get_temp_dir(), 'rhetoric-manifest');
        self::assertNotFalse($this->mapping);
        self::assertNotFalse($this->manifest);
    }

    protected function tearDown(): void
    {
        MapCache::clear();
        AutoMigrator::flush();
        if (\is_file($this->mapping)) {
            \unlink($this->mapping);
        }
        if (\is_file($this->manifest)) {
            \unlink($this->manifest);
        }
    }

    public function testPagesLengthSimpleAndCursorResults(): void
    {
        $context = $this->posts();

        self::assertSame(['total' => 3, 'ids' => [1, 2]], PolishScenarios::lengthPage($context, 1, 2));
        self::assertSame(['total' => 3, 'ids' => [3]], PolishScenarios::lengthPage($context, 2, 2));
        self::assertSame(['total' => 3, 'ids' => []], PolishScenarios::lengthPage($context, 5, 2));
        self::assertSame(['total' => 2, 'ids' => [2]], PolishScenarios::filteredPage($context));
        self::assertSame(['more' => true, 'ids' => [1, 2]], PolishScenarios::simplePage($context, 1, 2));
        self::assertSame(['more' => false, 'ids' => [3]], PolishScenarios::simplePage($context, 2, 2));
        self::assertSame([[1, 2], [3], [3]], PolishScenarios::cursorPages($context));
    }

    public function testATaggedPageKeepsTheCommentTelescopeReads(): void
    {
        $context = $this->posts();
        $context->connection()->flushQueryLog();
        $context->connection()->enableQueryLog();
        PolishScenarios::taggedPage($context);

        $sql = '';
        foreach ($context->connection()->getQueryLog() as $entry) {
            $sql .= ' ' . (string) ($entry['query'] ?? '');
        }
        self::assertStringContainsString('/* posts */', $sql);
        self::assertGreaterThanOrEqual(2, \substr_count($sql, '/* posts */'));
    }

    public function testARepeatedQueryReusesTheTranslatedStatement(): void
    {
        $context = $this->posts();
        $profile = PolishScenarios::repeatedReads($context);

        self::assertSame(1, $profile[0]);
        self::assertSame(1, $profile[1]);
        self::assertSame(1, $profile[2]);
        self::assertGreaterThanOrEqual(6, $profile[3]);
    }

    public function testContextFactoryCreatesANewInstanceEachCall(): void
    {
        $app = new Container();
        $app->scoped(ConventionBlogContext::class);
        $scoped = $app->make(ConventionBlogContext::class);
        $made = PolishScenarios::contexts($app);

        self::assertInstanceOf(ConventionBlogContext::class, $made[0]);
        self::assertInstanceOf(ConventionBlogContext::class, $made[1]);
        self::assertNotSame($made[0], $made[1]);
        self::assertNotSame($scoped, $made[0]);
    }

    public function testTheMappingCacheIsWhatALaterProcessLoads(): void
    {
        $context = new ConventionBlogContext();
        MappingArchive::write($this->mapping, [$context->mapping()]);
        $raw = \file_get_contents($this->mapping);
        self::assertIsString($raw);
        $raw = \str_replace("'table' => 'blogs'", "'table' => 'cached_blogs'", $raw);
        self::assertNotFalse(\file_put_contents($this->mapping, $raw));
        MapCache::clear();

        self::assertSame(1, MappingArchive::load($this->mapping));
        self::assertSame('cached_blogs', (new ConventionBlogContext())->mapping()->model(Blog::class)->table);
    }

    public function testAStoredScopeStillUsesTheContextThatRunsTheQuery(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'rhetoric');
        $capsule->setAsGlobal();
        $built = new AdvancedContext('rhetoric', 1);
        $built->createSchema();
        MappingArchive::write($this->mapping, [$built->mapping()]);
        MapCache::clear();
        self::assertSame(1, MappingArchive::load($this->mapping));

        $context = new AdvancedContext('rhetoric', 1);
        $context->connection()->table('scoped_posts')->insert([
            ['tenant_id' => 1, 'title' => 'ours'],
            ['tenant_id' => 2, 'title' => 'theirs'],
        ]);
        self::assertSame(['ours'], PolishScenarios::scopedTitles($context));
    }

    public function testCacheAndClearWriteBothFiles(): void
    {
        $app = new class extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }

            public function runningInConsole(): bool
            {
                return true;
            }

            public function configPath(string $path = ''): string
            {
                return '/app/config' . ($path === '' ? '' : '/' . $path);
            }

            public function basePath(string $path = ''): string
            {
                return '/app' . ($path === '' ? '' : '/' . $path);
            }
        };
        $app->instance('config', new class ($this->mapping, $this->manifest) implements Repository {
            /** @var array<string, mixed> */
            private array $items;

            public function __construct(string $mapping, string $manifest)
            {
                $this->items = [
                    'rhetoric' => [
                        'contexts' => [ConventionBlogContext::class],
                        'mapping_cache' => $mapping,
                        'auto_migrate' => ['manifest' => $manifest],
                    ],
                ];
            }

            public function has($key): bool
            {
                return $this->get($key, $this) !== $this;
            }

            public function get($key, $default = null): mixed
            {
                $current = $this->items;
                foreach (\explode('.', (string) $key) as $segment) {
                    if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                        return $default;
                    }
                    $current = $current[$segment];
                }

                return $current;
            }

            public function all(): array
            {
                return $this->items;
            }

            public function set($key, $value = null): void
            {
                $this->items[(string) $key] = $value;
            }

            public function prepend($key, $value): void
            {
            }

            public function push($key, $value): void
            {
            }
        });
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $app->instance('db', $capsule->getDatabaseManager());
        $provider = new RhetoricServiceProvider($app);
        $provider->register();
        $provider->boot();
        $artisan = new Application($app, new Dispatcher($app), '1');

        self::assertSame(0, $artisan->call('rhetoric:cache'));
        self::assertFileExists($this->mapping);
        self::assertFileExists($this->manifest);
        $stored = \file_get_contents($this->mapping);
        self::assertIsString($stored);
        self::assertStringContainsString('ConventionBlogContext', $stored);

        self::assertSame(0, $artisan->call('rhetoric:clear'));
        self::assertFileDoesNotExist($this->mapping);
        self::assertFileDoesNotExist($this->manifest);
    }

    public function testASimplePageDoesNotCountAndALengthPageCountsWithoutTheLimit(): void
    {
        $context = $this->posts();
        $log = $context->connection();
        $log->flushQueryLog();
        $log->enableQueryLog();
        PolishScenarios::simplePage($context, 2, 2);
        $simple = $this->statements($context);
        $log->flushQueryLog();
        PolishScenarios::lengthPage($context, 2, 2);
        $length = $this->statements($context);

        self::assertCount(1, $simple);
        self::assertStringNotContainsString('count(', \strtolower($simple[0]));
        self::assertStringContainsString('limit 3', \strtolower($simple[0]));
        self::assertCount(2, $length);
        $count = \strtolower($length[0]);
        self::assertStringContainsString('count(', $count);
        self::assertStringNotContainsString('limit', $count);
        self::assertStringNotContainsString('offset', $count);
        self::assertStringNotContainsString('order by', $count);
        self::assertStringContainsString('limit 2 offset 2', \strtolower($length[1]));
    }

    public function testAPageNumberPastTheLargestOffsetIsAnEmptyPage(): void
    {
        $context = $this->posts();

        self::assertSame(['total' => 3, 'ids' => []], PolishScenarios::lengthPage($context, \PHP_INT_MAX, 15));
        self::assertSame(['more' => false, 'ids' => []], PolishScenarios::simplePage($context, \PHP_INT_MAX, 15));
    }

    public function testACursorWalkOverTiedValuesVisitsEveryRowOnce(): void
    {
        $context = $this->ranked([1, 1, 1, 2, 2, 3, 3]);

        self::assertSame([[1, 2], [3, 4], [5, 6], [7]], PolishScenarios::cursorWalk($context, 'rating', 2));
        self::assertSame([[6, 7], [4, 5], [1, 2], [3]], PolishScenarios::cursorWalk($context, 'ratingDesc', 2));
        self::assertSame([[3, 2], [1, 5], [4, 7], [6]], PolishScenarios::cursorWalk($context, 'ratingThenIdDesc', 2));
        self::assertSame([[1, 2], [3, 4], [5, 6], [7]], PolishScenarios::cursorWalk($context, 'key', 2));
    }

    public function testACursorWalkOrderedByADateCarriesTheDateInTheCursor(): void
    {
        $context = $this->ranked([1, 1, 1, 1, 1], ['2024-01-05 00:00:00', '2024-01-01 00:00:00', '2024-01-03 00:00:00', '2024-01-02 00:00:00', '2024-01-04 00:00:00']);

        self::assertSame([[2, 4], [3, 5], [1]], PolishScenarios::cursorWalk($context, 'published', 2));
    }

    public function testThePreviousCursorReturnsTheEarlierPage(): void
    {
        $context = $this->ranked([1, 1, 1, 2, 2, 3, 3]);

        self::assertSame([[1, 2], [3, 4], [1, 2]], PolishScenarios::cursorBack($context, 'rating', 2));
    }

    public function testACachedPlanBindsTheCurrentValueAndTag(): void
    {
        $context = $this->posts();
        $context->connection()->flushQueryLog();
        $context->connection()->enableQueryLog();

        self::assertSame([2, 3], PolishScenarios::filtered($context, 4, 'first'));
        self::assertSame([2], PolishScenarios::filtered($context, 5, 'second'));
        self::assertSame([1, 2, 3], PolishScenarios::filtered($context, 1, 'first'));

        $statements = $this->statements($context);
        self::assertCount(3, $statements);
        self::assertStringContainsString('/* first */', $statements[0]);
        self::assertStringContainsString('/* second */', $statements[1]);
        self::assertStringNotContainsString('/* first */', $statements[1]);
        self::assertStringContainsString('/* first */', $statements[2]);
    }

    public function testAStoredScopeReadsTheTenantOfTheContextThatRunsTheQuery(): void
    {
        $capsule = new \Illuminate\Database\Capsule\Manager();
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ], 'rhetoric');
        $capsule->setAsGlobal();
        $built = new AdvancedContext('rhetoric', 2);
        $built->createSchema();
        MappingArchive::write($this->mapping, [$built->mapping()]);
        MapCache::clear();
        self::assertSame(1, MappingArchive::load($this->mapping));

        $one = new AdvancedContext('rhetoric', 1);
        $one->connection()->table('scoped_posts')->insert([
            ['tenant_id' => 1, 'title' => 'ours'],
            ['tenant_id' => 2, 'title' => 'theirs'],
        ]);
        $two = new AdvancedContext('rhetoric', 2);

        self::assertSame(['ours'], PolishScenarios::scopedTitles($one));
        self::assertSame(['theirs'], PolishScenarios::scopedTitles($two));
        self::assertSame(['ours'], PolishScenarios::scopedTitles($one));
    }

    public function testCachingAgainWritesTheCurrentMappingNotTheOneAlreadyLoaded(): void
    {
        $context = new ConventionBlogContext();
        MappingArchive::write($this->mapping, [$context->mapping()]);
        $raw = \file_get_contents($this->mapping);
        self::assertIsString($raw);
        self::assertNotFalse(\file_put_contents($this->mapping, \str_replace("'table' => 'blogs'", "'table' => 'stale_blogs'", $raw)));
        MapCache::clear();
        self::assertSame(1, MappingArchive::load($this->mapping));
        self::assertSame('stale_blogs', (new ConventionBlogContext())->mapping()->model(Blog::class)->table);

        $app = new Container();
        $app->instance('config', $this->configuration());
        MappingArchive::capture($app);
        MapCache::clear();
        self::assertSame(1, MappingArchive::load($this->mapping));

        self::assertSame('blogs', (new ConventionBlogContext())->mapping()->model(Blog::class)->table);
    }

    public function testAMapBuiltAfterAnotherWasReleasedDoesNotReuseItsConstructorBinding(): void
    {
        $row = (object) ['id' => 7, 'body' => 'hello', 'label' => 'x'];
        $bound = $this->memoMap(['id', 'body']);
        $memo = Hydrator::models($bound, [$row])[0];
        self::assertInstanceOf(Memo::class, $memo);
        self::assertSame(7, $memo->id);
        // Built while the first map is alive so that its object id is free for the map that follows.
        $columns = $this->memoColumns(['label']);
        $key = new KeyMap(['label'], ['label'], true);
        unset($bound);

        $unbound = new ModelMap(Memo::class, 'memos', null, $columns, $key, [], [], [], []);
        $second = Hydrator::models($unbound, [$row])[0];

        self::assertInstanceOf(Memo::class, $second);
    }

    /**
     * @param array<int, string> $names
     */
    private function memoMap(array $names): ModelMap
    {
        return new ModelMap(Memo::class, 'memos', null, $this->memoColumns($names), new KeyMap([$names[0]], [$names[0]], true), [], [], [], []);
    }

    /**
     * @param array<int, string> $names
     * @return array<string, ColumnMap>
     */
    private function memoColumns(array $names): array
    {
        $columns = [];
        foreach ($names as $name) {
            $columns[$name] = new ColumnMap($name, $name, $name === 'id' ? 'int' : 'string', false, null, null, null, false, null);
        }

        return $columns;
    }

    private function configuration(): Repository
    {
        return new class ($this->mapping) implements Repository {
            public function __construct(private string $mapping)
            {
            }

            public function has($key): bool
            {
                return $this->get($key, $this) !== $this;
            }

            public function get($key, $default = null): mixed
            {
                if ($key === 'rhetoric.contexts') {
                    return [ConventionBlogContext::class];
                }
                if ($key === 'rhetoric.mapping_cache') {
                    return $this->mapping;
                }

                return $default;
            }

            public function all(): array
            {
                return [];
            }

            public function set($key, $value = null): void
            {
            }

            public function prepend($key, $value): void
            {
            }

            public function push($key, $value): void
            {
            }
        };
    }

    /**
     * @return array<int, string>
     */
    private function statements(QueryContext $context): array
    {
        $statements = [];
        foreach ($context->connection()->getQueryLog() as $entry) {
            $statements[] = (string) ($entry['query'] ?? '');
        }

        return $statements;
    }

    /**
     * @param array<int, int> $ratings
     * @param array<int, string>|null $published
     */
    private function ranked(array $ratings, ?array $published = null): QueryContext
    {
        $context = $this->posts();
        $connection = $context->connection();
        $connection->table('posts')->delete();
        foreach ($ratings as $index => $rating) {
            $connection->table('posts')->insert([
                'id' => $index + 1,
                'blog_id' => 1,
                'title' => 'p' . ($index + 1),
                'slug' => 'p' . ($index + 1),
                'rating' => $rating,
                'published_at' => $published[$index] ?? null,
                'deleted_at' => null,
            ]);
        }

        return $context;
    }

    private function posts(): QueryContext
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

        return new QueryContext('rhetoric');
    }
}
