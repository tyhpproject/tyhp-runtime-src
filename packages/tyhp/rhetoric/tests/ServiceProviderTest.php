<?php

declare(strict_types=1);

namespace Tyhp\Tests\Rhetoric;

use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Tyhp\Rhetoric\Fixtures\AttributeBlogContext;
use Tyhp\Rhetoric\Fixtures\ConventionBlogContext;
use Tyhp\Rhetoric\Laravel\RhetoricServiceProvider;
use Tyhp\Rhetoric\Mapping\MapCache;
use Tyhp\Rhetoric\Migrations\Auto\AutoMigrator;

final class ServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        MapCache::clear();
        AutoMigrator::flush();
    }

    public function testConfiguredContextsAreBoundAsScopedServices(): void
    {
        $app = $this->app([
            ConventionBlogContext::class,
            AttributeBlogContext::class,
            'Missing\\Context',
            \stdClass::class,
            42,
        ]);
        $provider = new RhetoricServiceProvider($app);
        $provider->register();

        self::assertTrue($app->bound(ConventionBlogContext::class));
        self::assertTrue($app->bound(AttributeBlogContext::class));
        self::assertFalse($app->bound(\stdClass::class));
        self::assertFalse($app->bound('Missing\\Context'));

        $first = $app->make(ConventionBlogContext::class);
        self::assertSame($first, $app->make(ConventionBlogContext::class));
        $app->forgetScopedInstances();
        self::assertNotSame($first, $app->make(ConventionBlogContext::class));

        $repository = $app->make('config');
        self::assertInstanceOf(Repository::class, $repository);
    }

    public function testRegisterMergesTheDefaultConfig(): void
    {
        $app = $this->app([]);
        (new RhetoricServiceProvider($app))->register();

        $repository = $app->make('config');
        self::assertInstanceOf(Repository::class, $repository);
        self::assertSame([], $repository->get('rhetoric.contexts'));
    }

    public function testBootPublishesTheConfigAndRegistersTheShowCommand(): void
    {
        $app = $this->app([]);
        $provider = new RhetoricServiceProvider($app);
        $provider->register();
        $provider->boot();

        $paths = ServiceProvider::pathsToPublish(RhetoricServiceProvider::class, 'rhetoric-config');
        self::assertCount(1, $paths);
        foreach ($paths as $source => $destination) {
            self::assertFileExists($source);
            self::assertSame('rhetoric.php', \basename($source));
            self::assertSame('/app/config/rhetoric.php', $destination);
        }

        $artisan = new Application($app, new Dispatcher($app), '1');
        self::assertTrue($artisan->has('rhetoric:show'));
        self::assertTrue($artisan->has('rhetoric:migration'));
        self::assertTrue($artisan->has('rhetoric:remove-migration'));
        self::assertTrue($artisan->has('rhetoric:pending'));
        self::assertTrue($artisan->has('rhetoric:auto-migrate'));
        self::assertTrue($artisan->has('rhetoric:migrate-deferred'));
        self::assertTrue($artisan->has('rhetoric:cache'));
        self::assertTrue($artisan->has('rhetoric:clear'));
        self::assertTrue($artisan->has('rhetoric:scaffold'));
        self::assertTrue($artisan->has('rhetoric:baseline'));
        $stubs = ServiceProvider::pathsToPublish(RhetoricServiceProvider::class, 'rhetoric-stubs');
        self::assertCount(4, $stubs);
        foreach ($stubs as $source => $destination) {
            self::assertFileExists($source);
            self::assertStringEndsWith('/stubs/rhetoric/' . \basename($source), $destination);
        }
        self::assertContains('rhetoric:cache', ServiceProvider::$optimizeCommands);
        self::assertContains('rhetoric:clear', ServiceProvider::$optimizeClearCommands);
        self::assertFalse(AutoMigrator::hooksInstalled());
    }

    /**
     * @param array<int, mixed> $contexts
     */
    private function app(array $contexts): Container
    {
        $repository = new class ($contexts) implements Repository {
            /** @var array<string, mixed> */
            private array $items;

            /**
             * @param array<int, mixed> $contexts
             */
            public function __construct(array $contexts)
            {
                $this->items = ['rhetoric' => ['contexts' => $contexts]];
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
        };

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
        $app->instance('config', $repository);

        return $app;
    }
}
