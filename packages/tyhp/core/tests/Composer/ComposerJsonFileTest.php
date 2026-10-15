<?php

declare(strict_types=1);

namespace Tyhp\Tests\Composer;

use PHPUnit\Framework\TestCase;
use Tyhp\Composer\ComposerJsonFile;
use Tyhp\Composer\DiagnosticCodes;
use Tyhp\Composer\ExtraRequireSync;
use Tyhp\Composer\ExtraRequireSyncException;
use Tyhp\Composer\PackageMeta;
use Tyhp\Composer\RootSnapshot;

final class ComposerJsonFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/tyhp-plugin-json-' . \bin2hex(\random_bytes(8));
        \mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->dir);
    }

    public function testWriteRequireDevPreservesOtherKeys(): void
    {
        $path = $this->dir . '/composer.json';
        \file_put_contents($path, <<<'JSON'
{
    "name": "app/root",
    "require": {
        "tyhp/core": "@dev"
    },
    "config": {
        "allow-plugins": {
            "tyhp/core": true
        }
    }
}
JSON);

        $store = new ComposerJsonFile($path);
        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'tyhp/core',
            '1.0.0.0',
            [],
            [
                'tyhpdef/php' => '@dev',
            ],
        ));
        ExtraRequireSync::run(
            new RootSnapshot(['tyhp/core' => '@dev'], [], []),
            $catalog,
            true,
            $store,
        );

        $decoded = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($decoded);
        self::assertSame('app/root', $decoded['name']);
        self::assertSame(['tyhp/core' => '@dev'], $decoded['require']);
        self::assertTrue($decoded['config']['allow-plugins']['tyhp/core']);
        self::assertSame('@dev', $decoded['require-dev']['tyhp/compiler']);
        self::assertSame('@dev', $decoded['require-dev']['tyhpdef/php']);
        self::assertArrayNotHasKey('tyhpdef/php-ext-mbstring', $decoded['require-dev']);
    }

    public function testInvalidJsonIsTyhp7703(): void
    {
        $path = $this->dir . '/composer.json';
        \file_put_contents($path, '{not json');
        $store = new ComposerJsonFile($path);

        try {
            $store->read();
            self::fail('Expected parse failure');
        } catch (ExtraRequireSyncException $e) {
            self::assertSame(DiagnosticCodes::ROOT_JSON_WRITE_FAILED, $e->tyhpCode);
            self::assertSame(7703, $e->getCode());
            self::assertStringContainsString('TYHP7703', $e->getMessage());
            self::assertStringContainsString($path, $e->getMessage());
        }
    }

    public function testJsonArrayRootIsTyhp7703(): void
    {
        $path = $this->dir . '/composer.json';
        \file_put_contents($path, '[]');

        try {
            (new ComposerJsonFile($path))->read();
            self::fail('Expected non-object JSON to fail');
        } catch (ExtraRequireSyncException $e) {
            self::assertSame(7703, $e->getCode());
        }
    }

    public function testUnchangedRequireDevDoesNotRewriteFile(): void
    {
        $path = $this->dir . '/composer.json';
        $json = <<<'JSON'
{
    "name": "app/root",
    "require": {
        "tyhp/core": "@dev"
    },
    "require-dev": {
        "tyhp/compiler": "@dev",
        "tyhpdef/php": "@dev"
    },
    "config": {
        "allow-plugins": {
            "tyhp/core": true
        }
    }
}
JSON;
        \file_put_contents($path, $json);
        $mtime = \filemtime($path);
        \clearstatcache(true, $path);

        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'tyhp/core',
            '1.0.0.0',
            [],
            [
                'tyhpdef/php' => '@dev',
            ],
        ));
        $result = ExtraRequireSync::run(
            new RootSnapshot(
                ['tyhp/core' => '@dev'],
                [
                    'tyhp/compiler' => '@dev',
                    'tyhpdef/php' => '@dev',
                ],
                [],
            ),
            $catalog,
            true,
            new ComposerJsonFile($path),
        );

        self::assertFalse($result['changed']);
        self::assertSame($json, \file_get_contents($path));
        self::assertSame($mtime, \filemtime($path));
    }

    public function testWriteRequireDevAddsAllowPluginsWhenMissing(): void
    {
        $path = $this->dir . '/composer.json';
        \file_put_contents($path, <<<'JSON'
{
    "name": "app/root",
    "require": {
        "tyhp/core": "@dev"
    }
}
JSON);

        $store = new ComposerJsonFile($path);
        $store->writeRequireDev(['tyhpdef/php' => '@dev']);

        $decoded = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['config']['allow-plugins']['tyhp/core']);
        self::assertSame('@dev', $decoded['require-dev']['tyhpdef/php']);
    }

    public function testEnsureAllowPluginsDoesNotClobberFalse(): void
    {
        $path = $this->dir . '/composer.json';
        \file_put_contents($path, <<<'JSON'
{
    "name": "app/root",
    "config": {
        "allow-plugins": {
            "tyhp/core": false
        }
    }
}
JSON);

        $store = new ComposerJsonFile($path);
        self::assertFalse($store->ensureAllowPlugins());

        $decoded = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($decoded);
        self::assertFalse($decoded['config']['allow-plugins']['tyhp/core']);
    }

    public function testUnchangedRequireDevWritesAllowPluginsWhenMissing(): void
    {
        $path = $this->dir . '/composer.json';
        $json = <<<'JSON'
{
    "name": "app/root",
    "require": {
        "tyhp/core": "@dev"
    },
    "require-dev": {
        "tyhp/compiler": "@dev",
        "tyhpdef/php": "@dev"
    }
}
JSON;
        \file_put_contents($path, $json);

        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'tyhp/core',
            '1.0.0.0',
            [],
            [
                'tyhpdef/php' => '@dev',
            ],
        ));
        $result = ExtraRequireSync::run(
            new RootSnapshot(
                ['tyhp/core' => '@dev'],
                [
                    'tyhp/compiler' => '@dev',
                    'tyhpdef/php' => '@dev',
                ],
                [],
            ),
            $catalog,
            true,
            new ComposerJsonFile($path),
        );

        self::assertFalse($result['changed']);
        $decoded = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['config']['allow-plugins']['tyhp/core']);
    }

    public function testCompilerRootWriteStripsSelfRequire(): void
    {
        $path = $this->dir . '/composer.json';
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

        $catalog = new InMemoryPackageCatalog();
        $catalog->add(new PackageMeta(
            'tyhp/core',
            '1.0.0.0',
            [],
            [
                'tyhpdef/php' => '@dev',
            ],
        ));
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
            new ComposerJsonFile($path),
        );

        self::assertTrue($result['changed']);
        $decoded = \json_decode((string) \file_get_contents($path), true);
        self::assertIsArray($decoded);
        self::assertArrayNotHasKey('tyhp/compiler', $decoded['require-dev']);
        self::assertSame('^11.0', $decoded['require-dev']['phpunit/phpunit']);
        self::assertSame('@dev', $decoded['require-dev']['tyhpdef/php']);
        self::assertSame(['tyhp/core' => '@dev'], $decoded['require']);
    }
}
