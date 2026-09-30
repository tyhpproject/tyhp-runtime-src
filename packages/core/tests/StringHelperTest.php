<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tyhp\StringHelper;

final class StringHelperTest extends TestCase
{
    public function testHasMbStringExtMatchesExtensionLoaded(): void
    {
        self::assertSame(\extension_loaded('mbstring'), StringHelper::hasMbStringExt());
        self::assertSame(\extension_loaded('mbstring'), StringHelper::hasMbStringExt());
    }

    public function testWrappersMatchMbstringWhenLoaded(): void
    {
        if (!\extension_loaded('mbstring')) {
            self::markTestSkipped('ext-mbstring is not loaded');
        }

        $sample = 'Äé日本語';
        self::assertSame(\mb_strlen($sample), StringHelper::strlen($sample));
        self::assertSame(\mb_substr($sample, 1, 2), StringHelper::substr($sample, 1, 2));
        self::assertSame(\mb_strpos($sample, '語'), StringHelper::strpos($sample, '語'));
        self::assertSame(\mb_strrpos($sample, '語'), StringHelper::strrpos($sample, '語'));
        self::assertSame(\mb_stripos($sample, 'É'), StringHelper::stripos($sample, 'É'));
        self::assertSame(\mb_strtolower($sample), StringHelper::strtolower($sample));
        self::assertSame(\mb_strtoupper($sample), StringHelper::strtoupper($sample));
        self::assertSame(\mb_str_split($sample, 1), StringHelper::strSplit($sample, 1));
        self::assertSame(\mb_ord($sample), StringHelper::ord($sample));
        self::assertSame(\mb_convert_case($sample, \MB_CASE_TITLE), StringHelper::ucWords($sample));
        self::assertNotFalse(StringHelper::detectEncoding($sample));
        self::assertSame(
            \mb_convert_encoding($sample, 'UTF-8', 'UTF-8'),
            StringHelper::convertEncoding($sample, 'UTF-8', 'UTF-8'),
        );

        if (\method_exists(StringHelper::class, 'strPad')) {
            self::assertSame(\mb_str_pad($sample, 10, '.'), StringHelper::strPad($sample, 10, '.'));
        }

        if (\method_exists(StringHelper::class, 'ucFirst')) {
            self::assertSame(\mb_ucfirst($sample), StringHelper::ucFirst($sample));
        }

        if (\method_exists(StringHelper::class, 'lcFirst')) {
            self::assertSame(\mb_lcfirst($sample), StringHelper::lcFirst($sample));
        }
    }
}
