<?php

declare(strict_types=1);

namespace Tyhp\Compiler\Tests;

use PHPUnit\Framework\TestCase;
use Tyhp\Compiler\Platform;

final class PlatformTest extends TestCase
{
    public function testReleaseAssetNamesMatchReleaseScript(): void
    {
        self::assertSame('tyhp-osx-arm64', (new Platform('osx', 'arm64'))->releaseAssetName('self-contained'));
        self::assertSame('tyhp-osx-x64-fxdependent', (new Platform('osx', 'x64'))->releaseAssetName('framework-dependent'));
        self::assertSame('tyhp-linux-x64', (new Platform('linux', 'x64'))->releaseAssetName('self-contained'));
        self::assertSame('tyhp-linux-arm64-fxdependent', (new Platform('linux', 'arm64'))->releaseAssetName('framework-dependent'));
        self::assertSame('tyhp-win-x64.exe', (new Platform('win', 'x64'))->releaseAssetName('self-contained'));
        self::assertSame('tyhp-win-x64-fxdependent.exe', (new Platform('win', 'x64'))->releaseAssetName('framework-dependent'));
    }

    public function testWindowsBinaryFileNameUsesExe(): void
    {
        self::assertSame('tyhp.exe', (new Platform('win', 'x64'))->binaryFileName());
        self::assertSame('tyhp', (new Platform('osx', 'arm64'))->binaryFileName());
        self::assertSame('tyhp', (new Platform('linux', 'x64'))->binaryFileName());
    }

    public function testDetectMapsUnames(): void
    {
        $osx = Platform::detect('Darwin', 'arm64');
        self::assertSame('osx', $osx->os);
        self::assertSame('arm64', $osx->arch);

        $linux = Platform::detect('Linux', 'x86_64');
        self::assertSame('linux', $linux->os);
        self::assertSame('x64', $linux->arch);

        $win = Platform::detect('Windows', 'AMD64');
        self::assertSame('win', $win->os);
        self::assertSame('x64', $win->arch);
    }

    public function testUnsupportedPlatformsThrow(): void
    {
        $this->expectException(\RuntimeException::class);
        Platform::detect('BSD', 'x86_64');
    }

    public function testWinArm64IsUnsupported(): void
    {
        $this->expectException(\RuntimeException::class);
        Platform::detect('Windows', 'arm64');
    }
}
