<?php

declare(strict_types=1);

namespace Tyhp\Tests\Composer;

use PHPUnit\Framework\TestCase;
use Tyhp\Composer\ConstraintMerger;
use Tyhp\Composer\DiagnosticCodes;
use Tyhp\Composer\ExtraRequireSync;
use Tyhp\Composer\ExtraRequireSyncException;
use Tyhp\Composer\PackageMeta;
use Tyhp\Composer\RootSnapshot;
use Tyhp\Composer\TreeResolver;

final class TreeResolverTest extends TestCase
{
    public function testDecimalCoreExtrasYieldPhpAndCompilerInOneSolve(): void
    {
        $catalog = $this->decimalGraphCatalog();
        $root = new RootSnapshot(
            ['tyhp/decimal' => '@dev'],
            [],
            [],
        );

        $result = ExtraRequireSync::run($root, $catalog, true);

        self::assertFalse($result['skippedNoDev']);
        self::assertSame('@dev', $result['requireDev']['tyhp/compiler']);
        self::assertSame('@dev', $result['requireDev']['tyhpdef/php']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-mbstring', $result['requireDev']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-bcmath', $result['requireDev']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-gmp', $result['requireDev']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-decimal', $result['requireDev']);
        self::assertContains('tyhp/decimal', $catalog->fetchedNames);
        self::assertContains('tyhp/core', $catalog->fetchedNames);
        self::assertNotContains('php', $catalog->fetchedNames);
        self::assertNotContains('ext-mbstring', $catalog->fetchedNames);
        self::assertNotContains('composer-plugin-api', $catalog->fetchedNames);
        self::assertNotContains('tyhpdef/php-ext-bcmath', $catalog->fetchedNames);
    }

    public function testThirdPartyExtraNameIsMerged(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'vendor/lib',
            '1.0.0.0',
            ['tyhp/core' => '@dev'],
            ['acme/tyhpdefs' => '@dev'],
        ));
        $catalog->add($this->coreMeta());
        $catalog->add(new PackageMeta('acme/tyhpdefs', '1.0.0.0', [], []));
        $catalog->add(new PackageMeta('tyhpdef/php', '1.0.0.0', [], []));

        $result = ExtraRequireSync::run(
            new RootSnapshot(['vendor/lib' => '@dev'], [], []),
            $catalog,
            true,
        );

        self::assertSame('@dev', $result['requireDev']['acme/tyhpdefs']);
        self::assertSame('@dev', $result['requireDev']['tyhpdef/php']);
        self::assertSame('@dev', $result['requireDev']['tyhp/compiler']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-mbstring', $result['requireDev']);
    }

    public function testRuntimeRequireOfPackageWithExtrasIsFollowed(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'app/wrapper',
            '1.0.0.0',
            ['tyhp/core' => '@dev'],
            [],
        ));
        $catalog->add($this->coreMeta());
        $catalog->add(new PackageMeta('tyhpdef/php', '1.0.0.0', [], []));

        $result = ExtraRequireSync::run(
            new RootSnapshot(['app/wrapper' => '@dev'], [], []),
            $catalog,
            true,
        );

        self::assertSame('@dev', $result['requireDev']['tyhpdef/php']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-mbstring', $result['requireDev']);
        self::assertContains('tyhp/core', $catalog->fetchedNames);
    }

    public function testCycleBetweenExtrasTerminates(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta('pkg/a', '1.0.0.0', [], ['pkg/b' => '@dev']));
        $catalog->add(new PackageMeta('pkg/b', '1.0.0.0', [], ['pkg/a' => '@dev']));

        $resolved = TreeResolver::resolve(
            new RootSnapshot(['pkg/a' => '@dev'], [], []),
            $catalog,
        );

        $aCount = \count(\array_filter($catalog->fetchedNames, static fn(string $n): bool => $n === 'pkg/a'));
        $bCount = \count(\array_filter($catalog->fetchedNames, static fn(string $n): bool => $n === 'pkg/b'));
        self::assertSame(1, $aCount);
        self::assertSame(1, $bCount);
        self::assertArrayHasKey('pkg/b', $resolved['extras']);
        self::assertArrayHasKey('pkg/a', $resolved['seen']);
        self::assertArrayHasKey('pkg/b', $resolved['seen']);
    }

    public function testCompatibleExistingPinIsKept(): void
    {
        $catalog = $this->decimalGraphCatalog();
        $root = new RootSnapshot(
            ['tyhp/decimal' => '@dev'],
            ['tyhpdef/php' => '^1.0'],
            [],
        );

        $result = ExtraRequireSync::run($root, $catalog, true);

        self::assertSame('^1.0', $result['requireDev']['tyhpdef/php']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-mbstring', $result['requireDev']);
    }

    public function testHighestMatchingVersionSuppliesExtras(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta('vendor/lib', '1.0.0.0', [], ['old/extra' => '@dev']));
        $catalog->add(new PackageMeta('vendor/lib', '2.0.0.0', [], ['new/extra' => '@dev']));
        $catalog->add(new PackageMeta('old/extra', '1.0.0.0', [], []));
        $catalog->add(new PackageMeta('new/extra', '1.0.0.0', [], []));

        $result = ExtraRequireSync::run(
            new RootSnapshot(['vendor/lib' => '*'], [], []),
            $catalog,
            true,
        );

        self::assertSame('@dev', $result['requireDev']['new/extra']);
        self::assertArrayNotHasKey('old/extra', $result['requireDev']);
    }

    public function testDisjointExistingPinErrorsWithTyhp7700(): void
    {
        $root = new RootSnapshot(
            ['tyhp/core' => '@dev'],
            ['tyhpdef/php' => '^1.0'],
            [],
        );
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'tyhp/core',
            '1.0.0.0',
            [],
            ['tyhpdef/php' => '^2.0'],
        ));

        try {
            ExtraRequireSync::run($root, $catalog, true);
            self::fail('Expected disjoint extra constraint to throw');
        } catch (ExtraRequireSyncException $e) {
            self::assertSame(DiagnosticCodes::DISJOINT_CONSTRAINT, $e->tyhpCode);
            self::assertSame(7700, $e->getCode());
            self::assertStringContainsString('TYHP7700', $e->getMessage());
            self::assertStringContainsString('tyhpdef/php', $e->getMessage());
        }
    }

    public function testPlatformPackagesAreNotFetched(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'tyhp/core',
            '1.0.0.0',
            [
                'php' => '>=8.2',
                'ext-mbstring' => '*',
                'composer-plugin-api' => '^2.3',
                'composer-runtime-api' => '^2.0',
            ],
            ['tyhpdef/php' => '@dev'],
        ));
        $catalog->add(new PackageMeta('tyhpdef/php', '1.0.0.0', ['php' => '>=8.2'], []));

        ExtraRequireSync::run(
            new RootSnapshot(['tyhp/core' => '@dev', 'php' => '>=8.2'], [], []),
            $catalog,
            true,
        );

        self::assertNotContains('php', $catalog->fetchedNames);
        self::assertNotContains('ext-mbstring', $catalog->fetchedNames);
        self::assertNotContains('composer-plugin-api', $catalog->fetchedNames);
        self::assertNotContains('composer-runtime-api', $catalog->fetchedNames);
        self::assertContains('tyhp/core', $catalog->fetchedNames);
    }

    public function testNoDevDoesNotWriteComposerJson(): void
    {
        $dir = \sys_get_temp_dir() . '/tyhp-plugin-nodev-' . \bin2hex(\random_bytes(8));
        \mkdir($dir, 0777, true);
        $path = $dir . '/composer.json';
        $original = <<<'JSON'
{
    "name": "app/root",
    "require": {
        "tyhp/core": "@dev"
    },
    "require-dev": {}
}
JSON;
        \file_put_contents($path, $original);
        try {
            $store = new \Tyhp\Composer\ComposerJsonFile($path);
            $catalog = new InMemoryPackageCatalog();
            $catalog->add($this->coreMeta());
            $result = ExtraRequireSync::run(
                new RootSnapshot(['tyhp/core' => '@dev'], [], []),
                $catalog,
                false,
                $store,
            );
            self::assertTrue($result['skippedNoDev']);
            self::assertSame($original, \file_get_contents($path));
            self::assertSame([], $catalog->fetchedNames);
        } finally {
            \unlink($path);
            \rmdir($dir);
        }
    }

    public function testDependencyRequireDevIsNotWalked(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'tyhp/decimal',
            '1.0.0.0',
            ['tyhp/core' => '@dev'],
            ['tyhpdef/php' => '@dev'],
        ));
        $catalog->add($this->coreMeta());
        $catalog->add(new PackageMeta('tyhpdef/php', '1.0.0.0', [], []));
        $catalog->add(new PackageMeta(
            'tyhpdef/php-ext-gmp',
            '1.0.0.0',
            [],
            ['should-not/appear' => '@dev'],
        ));

        $result = ExtraRequireSync::run(
            new RootSnapshot(['tyhp/decimal' => '@dev'], [], []),
            $catalog,
            true,
        );

        self::assertNotContains('tyhpdef/php-ext-gmp', $catalog->fetchedNames);
        self::assertNotContains('should-not/appear', $catalog->fetchedNames);
        self::assertArrayNotHasKey('should-not/appear', $result['requireDev']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-gmp', $result['requireDev']);
    }

    public function testRootExtraTyhpRequireIsHonored(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta('acme/ambient', '1.0.0.0', [], []));

        $result = ExtraRequireSync::run(
            new RootSnapshot([], [], ['acme/ambient' => '@dev']),
            $catalog,
            true,
        );

        self::assertSame('@dev', $result['requireDev']['acme/ambient']);
        self::assertContains('acme/ambient', $catalog->fetchedNames);
        self::assertArrayNotHasKey('tyhp/compiler', $result['requireDev']);
    }

    public function testPackageObjectIsNotTreatedAsRequireMap(): void
    {
        $meta = PackageMeta::fromComposerPackage(new class {
            public function getName(): string
            {
                return 'vendor/lib';
            }

            public function getVersion(): string
            {
                return '1.0.0.0';
            }

            public function getRequires(): array
            {
                return [];
            }

            public function getExtra(): array
            {
                return [
                    'tyhp' => [
                        'require' => ['tyhpdef/php' => '@dev'],
                        'package' => [
                            'include' => ['./package.tyhpdef'],
                            'overlay' => ['./_tyhpdef/overlays/*.tyhpdef'],
                        ],
                    ],
                ];
            }
        });

        self::assertSame(['tyhpdef/php' => '@dev'], $meta->extraRequire);
        self::assertArrayNotHasKey('include', $meta->extraRequire);
        self::assertArrayNotHasKey('./package.tyhpdef', $meta->extraRequire);
        self::assertArrayNotHasKey('package', $meta->extraRequire);
    }

    public function testUnknownExtraTyhpKeysAreIgnored(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'vendor/lib',
            '1.0.0.0',
            [],
            ['acme/keep' => '@dev'],
        ));
        $catalog->add(new PackageMeta('acme/keep', '1.0.0.0', [], []));

        $meta = PackageMeta::fromComposerPackage(new class {
            public function getName(): string
            {
                return 'vendor/lib';
            }

            public function getVersion(): string
            {
                return '1.0.0.0';
            }

            public function getRequires(): array
            {
                return [];
            }

            public function getExtra(): array
            {
                return [
                    'tyhp' => [
                        'interopContractVersion' => 1,
                        'futureFlag' => true,
                        'require' => ['acme/keep' => '@dev'],
                    ],
                ];
            }
        });

        self::assertSame(['acme/keep' => '@dev'], $meta->extraRequire);
        $result = ExtraRequireSync::run(
            new RootSnapshot(['vendor/lib' => '@dev'], [], []),
            $catalog,
            true,
        );
        self::assertSame('@dev', $result['requireDev']['acme/keep']);
    }

    public function testAlreadyInRootRequireIsNotCopiedToRequireDev(): void
    {
        $merged = ConstraintMerger::mergeOntoRequireDev(
            ['tyhpdef/php' => '@dev'],
            [],
            ['tyhpdef/php' => '@dev', 'tyhp/compiler' => '@dev'],
        );
        self::assertArrayNotHasKey('tyhpdef/php', $merged);
        self::assertSame('@dev', $merged['tyhp/compiler']);
    }

    public function testRootPackageIsNotCopiedOntoRequireDev(): void
    {
        $merged = ConstraintMerger::mergeOntoRequireDev(
            ['tyhp/core' => '@dev'],
            ['tyhp/compiler' => '@dev', 'phpunit/phpunit' => '^11.0'],
            ['tyhp/compiler' => '@dev', 'tyhpdef/php' => '@dev'],
            'TyHp/Compiler',
        );
        self::assertArrayNotHasKey('tyhp/compiler', $merged);
        self::assertSame('^11.0', $merged['phpunit/phpunit']);
        self::assertSame('@dev', $merged['tyhpdef/php']);
    }

    public function testCompilerRootDoesNotPinSelfAndKeepsOtherExtras(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add($this->coreMeta());
        $catalog->add(new PackageMeta('tyhpdef/php', '1.0.0.0', [], []));

        $result = ExtraRequireSync::run(
            new RootSnapshot(
                ['tyhp/core' => '@dev'],
                [
                    'tyhp/compiler' => '@dev',
                    'phpunit/phpunit' => '^11.0',
                ],
                [],
                'tyhp/compiler',
            ),
            $catalog,
            true,
        );

        self::assertArrayNotHasKey('tyhp/compiler', $result['requireDev']);
        self::assertArrayNotHasKey('tyhp/compiler', $result['extras']);
        self::assertSame('^11.0', $result['requireDev']['phpunit/phpunit']);
        self::assertSame('@dev', $result['requireDev']['tyhpdef/php']);
        self::assertTrue($result['changed']);
    }

    public function testUnnamedRootStillPinsCompiler(): void
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add($this->coreMeta());
        $catalog->add(new PackageMeta('tyhpdef/php', '1.0.0.0', [], []));

        $result = ExtraRequireSync::run(
            new RootSnapshot(['tyhp/core' => '@dev'], [], [], '__root__'),
            $catalog,
            true,
        );

        self::assertSame('@dev', $result['requireDev']['tyhp/compiler']);
        self::assertSame('@dev', $result['requireDev']['tyhpdef/php']);
    }

    private function decimalGraphCatalog(): InMemoryPackageCatalog
    {
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'tyhp/decimal',
            '1.0.0.0',
            [
                'php' => '>=8.2',
                'tyhp/core' => '@dev',
            ],
            ['tyhpdef/php' => '@dev'],
        ));
        $catalog->add($this->coreMeta());
        $catalog->add(new PackageMeta('tyhpdef/php', '1.0.0.0', ['php' => '>=8.2'], []));
        $catalog->add(new PackageMeta('tyhpdef/php-ext-bcmath', '1.0.0.0', [], []));
        $catalog->add(new PackageMeta('tyhpdef/php-ext-gmp', '1.0.0.0', [], []));
        $catalog->add(new PackageMeta('tyhpdef/php-ext-decimal', '1.0.0.0', [], []));

        return $catalog;
    }

    private function coreMeta(): PackageMeta
    {
        return new PackageMeta(
            'tyhp/core',
            '1.0.0.0',
            [
                'php' => '>=8.2',
                'composer-plugin-api' => '^2.3',
            ],
            [
                'tyhpdef/php' => '@dev',
            ],
        );
    }
}
