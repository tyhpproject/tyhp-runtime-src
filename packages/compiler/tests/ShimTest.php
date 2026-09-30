<?php

declare(strict_types=1);

namespace Tyhp\Compiler\Tests;

use PHPUnit\Framework\TestCase;
use Tyhp\Compiler\Installer;
use Tyhp\Compiler\Platform;
use Tyhp\Compiler\Shim;

final class ShimTest extends TestCase
{
    private string $packageRoot;
    private string $stderrPath;
    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->packageRoot = \sys_get_temp_dir() . '/tyhp-shim-test-' . \bin2hex(\random_bytes(8));
        \mkdir($this->packageRoot, 0777, true);
        $this->stderrPath = $this->packageRoot . '/stderr.txt';
        $handle = \fopen($this->stderrPath, 'w+');
        if ($handle === false) {
            self::fail('Unable to open stderr capture file.');
        }
        $this->stderr = $handle;
    }

    protected function tearDown(): void
    {
        if (\is_resource($this->stderr)) {
            \fclose($this->stderr);
        }
        self::removeTree($this->packageRoot);
    }

    public function testForwardsArgvAndExitCode(): void
    {
        $argvFile = $this->packageRoot . '/seen-argv.json';
        $this->writeFakeNative($argvFile);
        $shim = $this->shim(new RecordingHttpClient(forbid: true));

        $code = $shim->run(['tyhp', 'build', '--exit=42', 'src/index.tyhp']);

        self::assertSame(42, $code);
        $seen = \json_decode((string) \file_get_contents($argvFile), true);
        self::assertSame(['build', '--exit=42', 'src/index.tyhp'], $seen);
        self::assertSame('', $this->stderrContents());
    }

    public function testInstallBinaryIsInterceptedAndNotForwarded(): void
    {
        $argvFile = $this->packageRoot . '/seen-argv.json';
        $this->writeFakeNative($argvFile);
        $http = new RecordingHttpClient(forbid: true);
        $shim = $this->shim($http);

        $code = $shim->run(['tyhp', '--install-binary']);

        self::assertSame(0, $code);
        self::assertFileDoesNotExist($argvFile);
        self::assertSame([], $http->urls);
        self::assertSame('', $this->stderrContents());
    }

    public function testInstallBinaryAmongOtherArgsIsStillIntercepted(): void
    {
        $argvFile = $this->packageRoot . '/seen-argv.json';
        $this->writeFakeNative($argvFile);
        $shim = $this->shim(new RecordingHttpClient(forbid: true));

        $code = $shim->run(['tyhp', 'build', '--install-binary']);

        self::assertSame(0, $code);
        self::assertFileDoesNotExist($argvFile);
    }

    public function testMissingNativeBinaryErrorsWithoutDownloading(): void
    {
        $http = new RecordingHttpClient(forbid: true);
        $shim = $this->shim($http);

        $code = $shim->run(['tyhp', 'build']);

        self::assertSame(1, $code);
        $err = $this->stderrContents();
        self::assertStringContainsString('--install-binary', $err);
        self::assertStringContainsString('vendor/bin/tyhp --install-binary', $err);
        self::assertStringContainsString('--no-scripts', $err);
        self::assertSame([], $http->urls);
    }

    public function testEmptyLeftoverErrorsWithoutDownloading(): void
    {
        $dir = $this->packageRoot . '/native';
        \mkdir($dir, 0777, true);
        $path = $dir . '/tyhp';
        \file_put_contents($path, '');
        \chmod($path, 0755);
        $http = new RecordingHttpClient(forbid: true);
        $shim = $this->shim($http);

        $code = $shim->run(['tyhp', 'lint']);

        self::assertSame(1, $code);
        self::assertStringContainsString('--install-binary', $this->stderrContents());
        self::assertSame([], $http->urls);
    }

    public function testChecksumMismatchOnInstallBinaryLeavesNoBinary(): void
    {
        $payload = "bad\n";
        $asset = 'tyhp-osx-arm64';
        $http = new RecordingHttpClient([
            'releases/tags/v805.1.0-beta.1' => \json_encode([
                'tag_name' => 'v805.1.0-beta.1',
                'assets' => [
                    [
                        'name' => $asset,
                        'browser_download_url' => 'https://example.test/download/' . $asset,
                    ],
                    [
                        'name' => 'checksums.txt',
                        'browser_download_url' => 'https://example.test/download/checksums.txt',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'checksums.txt' => \str_repeat('d', 64) . '  ' . $asset . "\n",
            $asset => $payload,
        ]);
        $shim = $this->shim($http);

        $code = $shim->run(['tyhp', '--install-binary']);

        self::assertSame(1, $code);
        self::assertStringContainsString('SHA-256 mismatch', $this->stderrContents());
        self::assertFileDoesNotExist($this->packageRoot . '/native/tyhp');
        self::assertFileDoesNotExist($this->packageRoot . '/native/version');
    }

    public function testInstallBinaryDownloadsWhenMissing(): void
    {
        $payload = $this->fakeNativeSource($this->packageRoot . '/downloaded-argv.json');
        $asset = 'tyhp-osx-arm64';
        $hash = \hash('sha256', $payload);
        $http = new RecordingHttpClient([
            'releases/tags/v805.1.0-beta.1' => \json_encode([
                'tag_name' => 'v805.1.0-beta.1',
                'assets' => [
                    [
                        'name' => $asset,
                        'browser_download_url' => 'https://example.test/download/' . $asset,
                    ],
                    [
                        'name' => 'checksums.txt',
                        'browser_download_url' => 'https://example.test/download/checksums.txt',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'checksums.txt' => $hash . '  ' . $asset . "\n",
            $asset => $payload,
        ]);
        $shim = $this->shim($http);

        $code = $shim->run(['tyhp', '--install-binary']);

        self::assertSame(0, $code);
        self::assertFileExists($this->packageRoot . '/native/tyhp');
        self::assertTrue(\is_executable($this->packageRoot . '/native/tyhp'));
    }

    public function testBinScriptErrorsWhenNativeMissing(): void
    {
        $bin = \dirname(__DIR__) . '/bin/tyhp';
        $proc = \proc_open(
            [\PHP_BINARY, $bin, 'version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($proc);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $code = \proc_close($proc);

        self::assertSame(1, $code);
        self::assertSame('', $stdout);
        self::assertStringContainsString('--install-binary', (string) $stderr);
        self::assertStringContainsString('vendor/bin/tyhp --install-binary', (string) $stderr);
    }

    private function shim(RecordingHttpClient $http): Shim
    {
        $installer = new Installer(
            $this->packageRoot,
            $http,
            new Platform('osx', 'arm64'),
            false,
            '805.1.0-beta.1',
        );

        return new Shim($installer, $this->stderr);
    }

    private function writeFakeNative(string $argvFile): void
    {
        $dir = $this->packageRoot . '/native';
        \mkdir($dir, 0777, true);
        $path = $dir . '/tyhp';
        \file_put_contents($path, $this->fakeNativeSource($argvFile));
        \chmod($path, 0755);
        \file_put_contents($dir . '/version', "v805.1.0-beta.1\n");
    }

    private function fakeNativeSource(string $argvFile): string
    {
        $export = \var_export($argvFile, true);

        return <<<PHP
#!/usr/bin/env php
<?php
\$args = \\array_slice(\$argv, 1);
\\file_put_contents({$export}, \\json_encode(\$args));
\$exit = 0;
foreach (\$args as \$arg) {
    if (\\str_starts_with(\$arg, '--exit=')) {
        \$exit = (int) \\substr(\$arg, 7);
    }
}
exit(\$exit);
PHP;
    }

    private function stderrContents(): string
    {
        \fflush($this->stderr);
        $contents = \file_get_contents($this->stderrPath);

        return $contents === false ? '' : $contents;
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
