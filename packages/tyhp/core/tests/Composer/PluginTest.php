<?php

declare(strict_types=1);

namespace Tyhp\Tests\Composer;

use PHPUnit\Framework\TestCase;
use Tyhp\Composer\DevMode;
use Tyhp\Composer\DiagnosticCodes;
use Tyhp\Composer\ExtraRequireSyncException;
use Tyhp\Composer\Plugin;

final class PluginTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $envRestore = [];

    protected function tearDown(): void
    {
        foreach ($this->envRestore as $name => $value) {
            if ($value === false) {
                \putenv($name);
                unset($_SERVER[$name], $_ENV[$name]);
            } else {
                \putenv($name . '=' . $value);
                $_SERVER[$name] = $_ENV[$name] = $value;
            }
        }
        $this->envRestore = [];
    }

    public function testSubscribesToPreDependenciesSolvingAndPrePoolCreate(): void
    {
        $events = Plugin::getSubscribedEvents();
        self::assertArrayHasKey('pre-dependencies-solving', $events);
        self::assertArrayHasKey('pre-pool-create', $events);
        self::assertSame('onPreDependenciesSolving', $events['pre-dependencies-solving'][0]);
        self::assertSame('onPreDependenciesSolving', $events['pre-pool-create'][0]);
    }

    public function testImplementsPluginAndEventSubscriber(): void
    {
        $plugin = new Plugin();
        self::assertInstanceOf(\Composer\Plugin\PluginInterface::class, $plugin);
        self::assertInstanceOf(\Composer\EventDispatcher\EventSubscriberInterface::class, $plugin);
    }

    /**
     * Composer 2 only calls PluginManager::registerPackage() for installed packages whose
     * getType() is composer-plugin or composer-installer. extra.class on a library package
     * is never read. Constructing Plugin in PHPUnit does not catch a wrong package type.
     */
    public function testCoreComposerJsonTypeIsComposerPlugin(): void
    {
        $path = \dirname(__DIR__, 2) . '/composer.json';
        self::assertFileExists($path);
        $decoded = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($decoded);
        self::assertSame('tyhp/core', $decoded['name']);
        self::assertSame('composer-plugin', $decoded['type']);
        self::assertSame('Tyhp\\Composer\\Plugin', $decoded['extra']['class'] ?? null);
        self::assertSame('^2.3', $decoded['require']['composer-plugin-api'] ?? null);
        self::assertTrue($decoded['config']['allow-plugins']['tyhp/core']);
    }

    public function testNoDevDoesNotWriteComposerJson(): void
    {
        $dir = \sys_get_temp_dir() . '/tyhp-plugin-evt-' . \bin2hex(\random_bytes(8));
        \mkdir($dir, 0777, true);
        $path = $dir . '/composer.json';
        $original = <<<'JSON'
{
    "name": "app/root",
    "require": {
        "tyhp/core": "@dev"
    }
}
JSON;
        \file_put_contents($path, $original);
        $cwd = \getcwd();
        $this->setEnv('COMPOSER_DEV_MODE', '0');

        $composer = new \Composer\Composer();
        $root = new FakeRootPackage();
        $root->requires = ['tyhp/core' => new FakeLink('tyhp/core', '@dev')];
        $root->extra = [
            'tyhp' => [
                'require' => ['tyhpdef/php' => '@dev'],
            ],
        ];
        $composer->package = $root;
        $composer->repositoryManager = new FakeRepositoryManager([]);
        $composer->locker = new FakeLocker(false);

        $plugin = new Plugin();
        $io = new FakeIO();
        $plugin->activate($composer, $io);

        try {
            \chdir($dir);
            $plugin->onPreDependenciesSolving(new \stdClass());
            self::assertSame($original, \file_get_contents($path));
            self::assertSame([], $root->devRequires);
        } finally {
            if (\is_string($cwd)) {
                \chdir($cwd);
            }
            \unlink($path);
            \rmdir($dir);
        }
    }

    public function testDevModeWritesRequireDevAndSetDevRequires(): void
    {
        $dir = \sys_get_temp_dir() . '/tyhp-plugin-dev-' . \bin2hex(\random_bytes(8));
        \mkdir($dir, 0777, true);
        $path = $dir . '/composer.json';
        \file_put_contents($path, <<<'JSON'
{
    "name": "app/root",
    "require": {
        "tyhp/core": "@dev"
    }
}
JSON);
        $cwd = \getcwd();
        $this->setEnv('COMPOSER_DEV_MODE', '1');

        $core = new FakeComposerPackage(
            'tyhp/core',
            '1.0.0.0',
            [],
            [
                'tyhp' => [
                    'interopContractVersion' => 1,
                    'require' => [
                        'tyhpdef/php' => '@dev',
                    ],
                ],
            ],
        );

        $composer = new \Composer\Composer();
        $root = new FakeRootPackage();
        $root->requires = ['tyhp/core' => new FakeLink('tyhp/core', '@dev')];
        $composer->package = $root;
        $composer->repositoryManager = new FakeRepositoryManager(['tyhp/core' => [$core]]);
        $composer->locker = new FakeLocker(true);

        $plugin = new Plugin();
        $event = new FakePoolEvent();
        try {
            \chdir($dir);
            $plugin->activate($composer, new FakeIO());
            $plugin->onPreDependenciesSolving($event);
        } finally {
            if (\is_string($cwd)) {
                \chdir($cwd);
            }
        }

        try {
            $decoded = \json_decode((string) \file_get_contents($path), true);
            self::assertIsArray($decoded);
            self::assertSame('@dev', $decoded['require-dev']['tyhp/compiler']);
            self::assertSame('@dev', $decoded['require-dev']['tyhpdef/php']);
            self::assertTrue($decoded['config']['allow-plugins']['tyhp/core']);
            self::assertArrayNotHasKey('tyhpdef/php-ext-mbstring', $decoded['require-dev']);
            self::assertArrayHasKey('tyhp/compiler', $root->devRequires);
            self::assertArrayHasKey('tyhpdef/php', $event->requiredNames);
        } finally {
            \unlink($path);
            \rmdir($dir);
        }
    }

    public function testCompilerRootStripsSelfRequireAndKeepsOtherPins(): void
    {
        $dir = \sys_get_temp_dir() . '/tyhp-plugin-compiler-root-' . \bin2hex(\random_bytes(8));
        \mkdir($dir, 0777, true);
        $path = $dir . '/composer.json';
        \file_put_contents($path, <<<'JSON'
{
    "name": "tyhp/compiler",
    "require": {
        "tyhp/core": "@dev"
    },
    "require-dev": {
        "tyhp/compiler": "@dev",
        "phpunit/phpunit": "^11.0"
    }
}
JSON);
        $cwd = \getcwd();
        $this->setEnv('COMPOSER_DEV_MODE', '1');

        $core = new FakeComposerPackage(
            'tyhp/core',
            '1.0.0.0',
            [],
            [
                'tyhp' => [
                    'require' => [
                        'tyhpdef/php' => '@dev',
                    ],
                ],
            ],
        );

        $composer = new \Composer\Composer();
        $root = new FakeRootPackage();
        $root->name = 'tyhp/compiler';
        $root->requires = ['tyhp/core' => new FakeLink('tyhp/core', '@dev')];
        $root->devRequires = [
            'tyhp/compiler' => new FakeLink('tyhp/compiler', '@dev'),
            'phpunit/phpunit' => new FakeLink('phpunit/phpunit', '^11.0'),
        ];
        $composer->package = $root;
        $composer->repositoryManager = new FakeRepositoryManager(['tyhp/core' => [$core]]);
        $composer->locker = new FakeLocker(true);

        $plugin = new Plugin();
        try {
            \chdir($dir);
            $plugin->activate($composer, new FakeIO());
            $plugin->onPreDependenciesSolving(new FakePoolEvent());
        } finally {
            if (\is_string($cwd)) {
                \chdir($cwd);
            }
        }

        try {
            $decoded = \json_decode((string) \file_get_contents($path), true);
            self::assertIsArray($decoded);
            self::assertArrayNotHasKey('tyhp/compiler', $decoded['require-dev']);
            self::assertSame('^11.0', $decoded['require-dev']['phpunit/phpunit']);
            self::assertSame('@dev', $decoded['require-dev']['tyhpdef/php']);
            self::assertArrayNotHasKey('tyhp/compiler', $root->devRequires);
            self::assertArrayHasKey('phpunit/phpunit', $root->devRequires);
            self::assertArrayHasKey('tyhpdef/php', $root->devRequires);
        } finally {
            \unlink($path);
            \rmdir($dir);
        }
    }

    public function testDisjointPinWritesErrorAndThrows7700(): void
    {
        $dir = \sys_get_temp_dir() . '/tyhp-plugin-disjoint-' . \bin2hex(\random_bytes(8));
        \mkdir($dir, 0777, true);
        $path = $dir . '/composer.json';
        \file_put_contents($path, '{}');
        $cwd = \getcwd();
        $this->setEnv('COMPOSER_DEV_MODE', '1');

        $core = new FakeComposerPackage(
            'tyhp/core',
            '1.0.0.0',
            [],
            ['tyhp' => ['require' => ['tyhpdef/php' => '^2.0']]],
        );
        $composer = new \Composer\Composer();
        $root = new FakeRootPackage();
        $root->requires = ['tyhp/core' => new FakeLink('tyhp/core', '@dev')];
        $root->devRequires = ['tyhpdef/php' => new FakeLink('tyhpdef/php', '^1.0')];
        $composer->package = $root;
        $composer->repositoryManager = new FakeRepositoryManager(['tyhp/core' => [$core]]);
        $composer->locker = new FakeLocker(true);

        $io = new FakeIO();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);

        try {
            \chdir($dir);
            try {
                $plugin->onPreDependenciesSolving(new \stdClass());
                self::fail('Expected disjoint pin to abort the solve');
            } catch (ExtraRequireSyncException $e) {
                self::assertSame(DiagnosticCodes::DISJOINT_CONSTRAINT, $e->tyhpCode);
                self::assertNotEmpty($io->errors);
                self::assertStringContainsString('TYHP7700', $io->errors[0]);
            }
        } finally {
            if (\is_string($cwd)) {
                \chdir($cwd);
            }
            \unlink($path);
            \rmdir($dir);
        }
    }

    public function testDevModeDetectorHonorsComposerNoDev(): void
    {
        $this->setEnv('COMPOSER_DEV_MODE', false);
        $this->setEnv('COMPOSER_NO_DEV', '1');
        self::assertTrue(DevMode::isNoDev());
    }

    public function testLockerNoDevIsHonoredWhenEnvUnset(): void
    {
        $this->setEnv('COMPOSER_DEV_MODE', false);
        $this->setEnv('COMPOSER_NO_DEV', false);
        $composer = new \Composer\Composer();
        $composer->locker = new FakeLocker(false);
        self::assertTrue(DevMode::isNoDev($composer));
    }

    private function setEnv(string $name, string|false $value): void
    {
        if (!\array_key_exists($name, $this->envRestore)) {
            $current = \getenv($name);
            $this->envRestore[$name] = $current === false ? false : (string) $current;
        }
        if ($value === false) {
            \putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        } else {
            \putenv($name . '=' . $value);
            $_SERVER[$name] = $_ENV[$name] = $value;
        }
    }
}

final class FakeIO implements \Composer\IO\IOInterface
{
    /** @var list<string> */
    public array $errors = [];

    public function writeError($messages, bool $newline = true, int $verbosity = 2): void
    {
        foreach ((array) $messages as $message) {
            $this->errors[] = (string) $message;
        }
    }
}

final class FakeRootPackage
{
    /** @var array<string, FakeLink> */
    public array $requires = [];

    /** @var array<string, FakeLink|\Composer\Package\Link> */
    public array $devRequires = [];

    /** @var array<string, mixed> */
    public array $extra = [];

    public string $name = '__root__';

    public function getName(): string
    {
        return $this->name;
    }

    public function getRequires(): array
    {
        return $this->requires;
    }

    public function getDevRequires(): array
    {
        return $this->devRequires;
    }

    public function setDevRequires(array $links): void
    {
        $this->devRequires = $links;
    }

    public function getExtra(): array
    {
        return $this->extra;
    }
}

final class FakeLink
{
    public function __construct(
        private readonly string $target,
        private readonly string $constraint,
    ) {
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getPrettyConstraint(): string
    {
        return $this->constraint;
    }
}

final class FakeLocker
{
    public function __construct(private readonly ?bool $devMode)
    {
    }

    public function getDevMode(): ?bool
    {
        return $this->devMode;
    }
}

final class FakeRepositoryManager
{
    /**
     * @param array<string, list<FakeComposerPackage>> $packages
     */
    public function __construct(private readonly array $packages)
    {
    }

    /**
     * @return list<FakeRepository>
     */
    public function getRepositories(): array
    {
        return [new FakeRepository($this->packages)];
    }
}

final class FakeRepository
{
    /**
     * @param array<string, list<FakeComposerPackage>> $packages
     */
    public function __construct(private readonly array $packages)
    {
    }

    /**
     * @return list<FakeComposerPackage>
     */
    public function findPackages($name, $constraint = null): array
    {
        return $this->packages[\strtolower((string) $name)] ?? [];
    }
}

final class FakeComposerPackage
{
    /**
     * @param array<string, FakeLink> $require
     * @param array<string, mixed> $extra
     */
    public function __construct(
        private readonly string $name,
        private readonly string $version,
        private readonly array $require,
        private readonly array $extra,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getRequires(): array
    {
        return $this->require;
    }

    public function getExtra(): array
    {
        return $this->extra;
    }
}

final class FakePoolEvent
{
    /** @var list<object> */
    public array $packages = [];

    /** @var array<string, true> */
    public array $requiredNames = [];

    public function getPackages(): array
    {
        return $this->packages;
    }

    public function setPackages(array $packages): void
    {
        $this->packages = $packages;
    }

    public function getRequest(): object
    {
        $names =& $this->requiredNames;

        return new class ($names) {
            /** @param array<string, true> $names */
            public function __construct(private array &$names)
            {
            }

            public function requireName(string $packageName, mixed $constraint = null): void
            {
                $this->names[\strtolower($packageName)] = true;
            }
        };
    }
}
