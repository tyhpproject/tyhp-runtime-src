<?php

declare(strict_types=1);

namespace Tyhp\Tests\Io;

use PHPUnit\Framework\TestCase;
use Tyhp\Io\Db\MySqlPlaceholders;

final class MySqlPlaceholdersTest extends TestCase
{
    public function testReplacesPlaceholdersWithLiterals(): void
    {
        $sql = MySqlPlaceholders::interpolate(
            'INSERT INTO t VALUES (?, ?, ?, ?, ?)',
            [1, 2.5, 'it\'s', null, true],
            self::escape(...)
        );

        $this->assertSame("INSERT INTO t VALUES (1, 2.5, 'it\\'s', NULL, 1)", $sql);
    }

    public function testLeavesQuotedIdentifiersAndCommentsAlone(): void
    {
        $sql = MySqlPlaceholders::interpolate(
            "SELECT '?', \"a?\", `c?l`, 'it''s ?' FROM t -- ?\nWHERE a = ? # ?\n/* ? */ AND b = ?",
            [1, 'x'],
            self::escape(...)
        );

        $this->assertSame(
            "SELECT '?', \"a?\", `c?l`, 'it''s ?' FROM t -- ?\nWHERE a = 1 # ?\n/* ? */ AND b = 'x'",
            $sql
        );
    }

    public function testBackslashEscapedQuoteStaysInsideTheString(): void
    {
        $sql = MySqlPlaceholders::interpolate("SELECT 'a\\'?' , ?", [5], self::escape(...));

        $this->assertSame("SELECT 'a\\'?' , 5", $sql);
    }

    public function testDoubleDashWithoutSpaceIsAnOperator(): void
    {
        $this->assertSame('SELECT 1--2', MySqlPlaceholders::interpolate('SELECT 1--?', [2], self::escape(...)));
    }

    public function testTooFewParametersThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MySqlPlaceholders::interpolate('SELECT ?, ?', [1], self::escape(...));
    }

    public function testTooManyParametersThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MySqlPlaceholders::interpolate('SELECT ?', [1, 2], self::escape(...));
    }

    public function testNonFiniteFloatThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MySqlPlaceholders::interpolate('SELECT ?', [\INF], self::escape(...));
    }

    private static function escape(string $value): string
    {
        return \addslashes($value);
    }
}
