<?php

declare(strict_types=1);

namespace Tyhp\Tests\Composer;

use PHPUnit\Framework\TestCase;
use Tyhp\Composer\ConstraintCompatibility;
use Tyhp\Composer\PlatformPackages;

final class ConstraintCompatibilityTest extends TestCase
{
    public function testIdenticalAndStarAreCompatible(): void
    {
        self::assertTrue(ConstraintCompatibility::areCompatible('@dev', '@dev'));
        self::assertTrue(ConstraintCompatibility::areCompatible('^1.0', '*'));
        self::assertTrue(ConstraintCompatibility::areCompatible('^1.0', '^1.2'));
    }

    public function testDisjointMajorsAreNotCompatible(): void
    {
        self::assertFalse(ConstraintCompatibility::areCompatible('^1.0', '^2.0'));
    }

    public function testPlatformDetection(): void
    {
        self::assertTrue(PlatformPackages::isPlatform('php'));
        self::assertTrue(PlatformPackages::isPlatform('PHP'));
        self::assertTrue(PlatformPackages::isPlatform('ext-mbstring'));
        self::assertTrue(PlatformPackages::isPlatform('composer-plugin-api'));
        self::assertTrue(PlatformPackages::isPlatform('composer-runtime-api'));
        self::assertFalse(PlatformPackages::isPlatform('tyhp/core'));
        self::assertFalse(PlatformPackages::isPlatform('tyhpdef/php'));
    }

    public function testPlatformDetectionMatchesComposerExactly(): void
    {
        // The real Composer platform variants (Composer\Repository\PlatformRepository::PLATFORM_PACKAGE_REGEX).
        self::assertTrue(PlatformPackages::isPlatform('php-64bit'));
        self::assertTrue(PlatformPackages::isPlatform('php-ipv6'));
        self::assertTrue(PlatformPackages::isPlatform('php-zts'));
        self::assertTrue(PlatformPackages::isPlatform('php-debug'));
        self::assertTrue(PlatformPackages::isPlatform('hhvm'));
        self::assertTrue(PlatformPackages::isPlatform('lib-openssl'));
        self::assertTrue(PlatformPackages::isPlatform('composer'));

        // Not a real Composer platform package: a bare `php-*` prefix match would
        // wrongly treat this as platform and skip fetching it.
        self::assertFalse(PlatformPackages::isPlatform('php-foo'));
        self::assertFalse(PlatformPackages::isPlatform('php-parser/php-parser'));
    }
}
