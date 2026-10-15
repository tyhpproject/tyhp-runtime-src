<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Tyhp diagnostic numbers shared with {@code MessageCode} 7700 / 7703.
 */
final class DiagnosticCodes
{
    public const DISJOINT_CONSTRAINT = 7700;

    public const ROOT_JSON_WRITE_FAILED = 7703;

    public static function disjointMessage(string $packageName, string $existing, string $extra): string
    {
        return 'TYHP7700: `extra.tyhp.require` constraint `' . $extra
            . '` for `' . $packageName . '` is disjoint from existing pin `' . $existing . '`';
    }

    public static function rootJsonMessage(string $path, string $detail): string
    {
        return 'TYHP7703: root `composer.json` at `' . $path . '` cannot be parsed or written: ' . $detail;
    }
}
