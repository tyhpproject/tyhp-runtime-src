<?php

declare(strict_types=1);

namespace Tyhp\Compiler\Tests;

use PHPUnit\Framework\TestCase;
use Tyhp\Compiler\Installer;
use Tyhp\Compiler\Platform;

final class InstallerTest extends TestCase
{
    private string $packageRoot;

    protected function setUp(): void
    {
        $this->packageRoot = \sys_get_temp_dir() . '/tyhp-compiler-test-' . \bin2hex(\random_bytes(8));
        \mkdir($this->packageRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->packageRoot);
    }

    public function testNormalizeReleaseTagPinsCompilerVersion(): void
    {
        self::assertSame('v805.1.0-beta.1', Installer::normalizeReleaseTag('805.1.0-beta.1'));
        self::assertSame('v805.1.0-beta.1', Installer::normalizeReleaseTag('v805.1.0-beta.1'));
    }

    public function testParseChecksumFileReadsGnuSha256sumLines(): void
    {
        $hash = \str_repeat('a', 64);
        $contents = $hash . "  tyhp-osx-arm64\n" . \str_repeat('b', 64) . " *tyhp-win-x64.exe\n# comment\n";
        $map = Installer::parseChecksumFile($contents);
        self::assertSame($hash, $map['tyhp-osx-arm64']);
        self::assertSame(\str_repeat('b', 64), $map['tyhp-win-x64.exe']);
        self::assertArrayNotHasKey('missing', $map);
    }

    public function testInstallBinaryDownloadsVerifiesAndChmods(): void
    {
        $payload = "native-cli-bytes\n";
        $asset = 'tyhp-osx-arm64';
        $http = $this->httpForAsset($asset, $payload);
        $installer = $this->installer($http, new Platform('osx', 'arm64'), hasDotNet9: false);

        $installer->installBinary();

        $path = $installer->nativeBinaryPath();
        self::assertFileExists($path);
        self::assertSame($payload, \file_get_contents($path));
        self::assertTrue(\is_executable($path));
        self::assertSame("v805.1.0-beta.1\n", \file_get_contents($installer->nativeDir() . '/version'));
        self::assertTrue($installer->isNativeBinaryAvailable());
    }

    public function testInstallBinaryIsIdempotentWhenMatchingBinaryPresent(): void
    {
        $payload = "already-there\n";
        $this->writeNative($payload, 'v805.1.0-beta.1');
        $http = new RecordingHttpClient(forbid: true);
        $installer = $this->installer($http, new Platform('osx', 'arm64'), hasDotNet9: false);

        $installer->installBinary();

        self::assertSame([], $http->urls);
        self::assertSame($payload, \file_get_contents($installer->nativeBinaryPath()));
    }

    public function testChecksumMismatchLeavesNoBinary(): void
    {
        $payload = "tampered\n";
        $wrongHash = \str_repeat('c', 64);
        $asset = 'tyhp-linux-x64';
        $http = $this->httpForAsset($asset, $payload, $wrongHash);
        $installer = $this->installer($http, new Platform('linux', 'x64'), hasDotNet9: false);

        try {
            $installer->installBinary();
            self::fail('Expected checksum mismatch to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('SHA-256 mismatch', $e->getMessage());
            self::assertStringContainsString($asset, $e->getMessage());
        }

        self::assertFileDoesNotExist($installer->nativeBinaryPath());
        self::assertFileDoesNotExist($installer->nativeDir() . '/version');
        self::assertFalse($installer->isNativeBinaryAvailable());
    }

    public function testMissingChecksumsAssetRefusesInstall(): void
    {
        $asset = 'tyhp-osx-arm64';
        $release = $this->releaseJson($asset, includeChecksums: false);
        $http = new RecordingHttpClient([
            'releases/tags/v805.1.0-beta.1' => $release,
        ]);
        $installer = $this->installer($http, new Platform('osx', 'arm64'), hasDotNet9: false);

        try {
            $installer->installBinary();
            self::fail('Expected missing checksums.txt to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('checksums.txt', $e->getMessage());
        }

        self::assertFileDoesNotExist($installer->nativeBinaryPath());
    }

    public function testEmptyDownloadFailsAndLeavesNoBinary(): void
    {
        $asset = 'tyhp-osx-arm64';
        $hash = \hash('sha256', '');
        $http = $this->httpForAsset($asset, '', $hash);
        $installer = $this->installer($http, new Platform('osx', 'arm64'), hasDotNet9: false);

        try {
            $installer->installBinary();
            self::fail('Expected empty download to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('empty', $e->getMessage());
        }

        self::assertFileDoesNotExist($installer->nativeBinaryPath());
    }

    public function testFrameworkDependentAssetWhenDotNet9Present(): void
    {
        $payload = "fx-dep\n";
        $asset = 'tyhp-linux-x64-fxdependent';
        $http = $this->httpForAsset($asset, $payload);
        $installer = $this->installer($http, new Platform('linux', 'x64'), hasDotNet9: true);

        $installer->installBinary();

        self::assertSame($payload, \file_get_contents($installer->nativeBinaryPath()));
        $joined = \implode("\n", $http->urls);
        self::assertStringContainsString($asset, $joined);
    }

    public function testWindowsAssetNameAndExeDestination(): void
    {
        $payload = "win-cli\n";
        $asset = 'tyhp-win-x64.exe';
        $http = $this->httpForAsset($asset, $payload);
        $installer = $this->installer($http, new Platform('win', 'x64'), hasDotNet9: false);

        $installer->installBinary();

        self::assertSame(
            $installer->nativeDir() . \DIRECTORY_SEPARATOR . 'tyhp.exe',
            $installer->nativeBinaryPath()
        );
        self::assertFileExists($installer->nativeBinaryPath());
        self::assertSame($payload, \file_get_contents($installer->nativeBinaryPath()));
    }

    public function testReadsVersionFromComposerJson(): void
    {
        \file_put_contents($this->packageRoot . '/composer.json', \json_encode([
            'name' => 'tyhp/compiler',
            'version' => '805.1.0-beta.1',
        ], \JSON_THROW_ON_ERROR));
        $installer = new Installer(
            $this->packageRoot,
            new RecordingHttpClient(forbid: true),
            new Platform('osx', 'arm64'),
            false,
        );
        self::assertSame('v805.1.0-beta.1', $installer->expectedReleaseTag());
    }

    public function testInvalidComposerJsonThrowsParseError(): void
    {
        \file_put_contents($this->packageRoot . '/composer.json', '{not-json');
        $installer = new Installer(
            $this->packageRoot,
            new RecordingHttpClient(forbid: true),
            new Platform('osx', 'arm64'),
            false,
        );

        try {
            $installer->expectedReleaseTag();
            self::fail('Expected invalid composer.json to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('could not be parsed', $e->getMessage());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testComposerJsonMissingVersionThrows(): void
    {
        \file_put_contents($this->packageRoot . '/composer.json', \json_encode([
            'name' => 'tyhp/compiler',
        ], \JSON_THROW_ON_ERROR));
        $installer = new Installer(
            $this->packageRoot,
            new RecordingHttpClient(forbid: true),
            new Platform('osx', 'arm64'),
            false,
        );

        try {
            $installer->expectedReleaseTag();
            self::fail('Expected composer.json without version to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('has no version', $e->getMessage());
        }
    }

    public function testInvalidGithubReleaseJsonThrowsParseError(): void
    {
        $http = new RecordingHttpClient([
            'releases/tags/v805.1.0-beta.1' => '{not-json',
        ]);
        $installer = $this->installer($http, new Platform('osx', 'arm64'), hasDotNet9: false);

        try {
            $installer->installBinary();
            self::fail('Expected invalid GitHub JSON to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('GitHub release JSON could not be parsed', $e->getMessage());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }

        self::assertFileDoesNotExist($installer->nativeBinaryPath());
    }

    public function testGithubApiErrorBodyIsReportedAsMissingTag(): void
    {
        $http = new RecordingHttpClient([
            'releases/tags/v805.1.0-beta.1' => \json_encode([
                'message' => 'Not Found',
                'documentation_url' => 'https://docs.github.com/rest',
            ], \JSON_THROW_ON_ERROR),
        ]);
        $installer = $this->installer($http, new Platform('osx', 'arm64'), hasDotNet9: false);

        try {
            $installer->installBinary();
            self::fail('Expected missing GitHub release to throw.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('was not found', $e->getMessage());
            self::assertStringContainsString('Not Found', $e->getMessage());
        }

        self::assertFileDoesNotExist($installer->nativeBinaryPath());
    }

    public function testEmptyLeftoverIsNotAvailable(): void
    {
        $dir = $this->packageRoot . '/native';
        \mkdir($dir, 0777, true);
        $path = $dir . '/tyhp';
        \file_put_contents($path, '');
        \chmod($path, 0755);
        $installer = $this->installer(new RecordingHttpClient(forbid: true), new Platform('osx', 'arm64'), false);
        self::assertFalse($installer->isNativeBinaryAvailable());
    }

    public function testNonExecutableIsNotAvailable(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX execute bit is not used on Windows.');
        }
        $dir = $this->packageRoot . '/native';
        \mkdir($dir, 0777, true);
        $path = $dir . '/tyhp';
        \file_put_contents($path, "not-exec\n");
        \chmod($path, 0644);
        $installer = $this->installer(new RecordingHttpClient(forbid: true), new Platform('osx', 'arm64'), false);
        self::assertFalse($installer->isNativeBinaryAvailable());
    }

    /**
     * @param RecordingHttpClient $http
     */
    private function installer(RecordingHttpClient $http, Platform $platform, bool $hasDotNet9): Installer
    {
        return new Installer(
            $this->packageRoot,
            $http,
            $platform,
            $hasDotNet9,
            '805.1.0-beta.1',
        );
    }

    private function httpForAsset(string $assetName, string $payload, ?string $hash = null): RecordingHttpClient
    {
        $hash ??= \hash('sha256', $payload);

        return new RecordingHttpClient([
            'releases/tags/v805.1.0-beta.1' => $this->releaseJson($assetName, includeChecksums: true),
            'checksums.txt' => $hash . '  ' . $assetName . "\n",
            $assetName => $payload,
        ]);
    }

    private function releaseJson(string $assetName, bool $includeChecksums): string
    {
        $assets = [
            [
                'name' => $assetName,
                'browser_download_url' => 'https://example.test/download/' . $assetName,
                'size' => 12,
            ],
        ];
        if ($includeChecksums) {
            $assets[] = [
                'name' => 'checksums.txt',
                'browser_download_url' => 'https://example.test/download/checksums.txt',
                'size' => 80,
            ];
        }

        return \json_encode([
            'tag_name' => 'v805.1.0-beta.1',
            'draft' => false,
            'assets' => $assets,
        ], \JSON_THROW_ON_ERROR);
    }

    private function writeNative(string $payload, string $tag): void
    {
        $dir = $this->packageRoot . '/native';
        \mkdir($dir, 0777, true);
        $path = $dir . '/tyhp';
        \file_put_contents($path, $payload);
        \chmod($path, 0755);
        \file_put_contents($dir . '/version', $tag . "\n");
    }

    private static function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $items = \scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . \DIRECTORY_SEPARATOR . $item;
            if (\is_dir($full)) {
                self::removeTree($full);
            } else {
                \unlink($full);
            }
        }
        \rmdir($path);
    }
}
