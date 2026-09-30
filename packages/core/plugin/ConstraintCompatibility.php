<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Whether two Composer constraint strings have a non-empty intersection.
 */
final class ConstraintCompatibility
{
    public static function areCompatible(string $left, string $right): bool
    {
        $left = \trim($left);
        $right = \trim($right);
        if ($left === $right) {
            return true;
        }
        if ($left === '' || $right === '' || $left === '*' || $right === '*') {
            return true;
        }

        if (\class_exists(\Composer\Semver\VersionParser::class)) {
            try {
                $parser = new \Composer\Semver\VersionParser();
                $a = $parser->parseConstraints(self::normalizeForParser($left));
                $b = $parser->parseConstraints(self::normalizeForParser($right));
                if (\class_exists(\Composer\Semver\Intervals::class)) {
                    return \Composer\Semver\Intervals::haveIntersections($a, $b);
                }

                return $a->matches($b);
            } catch (\Throwable) {
                // Fall through to the heuristic used when composer/semver is absent (unit tests).
            }
        }

        return self::heuristicCompatible($left, $right);
    }

    public static function normalizeForParser(string $constraint): string
    {
        $constraint = \trim($constraint);
        if ($constraint === '@dev' || $constraint === '@alpha' || $constraint === '@beta' || $constraint === '@RC' || $constraint === '@stable') {
            return '*' . $constraint;
        }

        return $constraint;
    }

    private static function heuristicCompatible(string $left, string $right): bool
    {
        if (self::isDevAlias($left) || self::isDevAlias($right)) {
            return true;
        }

        $leftMajor = self::caretOrTildeMajor($left);
        $rightMajor = self::caretOrTildeMajor($right);
        if ($leftMajor !== null && $rightMajor !== null && $leftMajor !== $rightMajor) {
            return false;
        }

        return true;
    }

    private static function isDevAlias(string $constraint): bool
    {
        return $constraint === '@dev'
            || \str_starts_with($constraint, 'dev-')
            || \str_ends_with($constraint, '@dev');
    }

    private static function caretOrTildeMajor(string $constraint): ?int
    {
        if (\preg_match('/^[\^~]\s*(\d+)/', $constraint, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }
}
