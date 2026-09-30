<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Merges collected extra.tyhp.require pins onto root require-dev.
 */
final class ConstraintMerger
{
    /**
     * Merge {@see $extras} into an extras map. Compatible duplicates keep the existing pin.
     *
     * @param array<string, string> $extras
     * @param array<string, string> $incoming
     * @return array<string, string>
     */
    public static function mergeExtras(array $extras, array $incoming): array
    {
        foreach ($incoming as $name => $constraint) {
            $name = \strtolower((string) $name);
            if ($name === '' || !\is_string($constraint)) {
                continue;
            }
            if (!isset($extras[$name])) {
                $extras[$name] = $constraint;
                continue;
            }
            if (ConstraintCompatibility::areCompatible($extras[$name], $constraint)) {
                continue;
            }
            throw new ExtraRequireSyncException(
                DiagnosticCodes::DISJOINT_CONSTRAINT,
                DiagnosticCodes::disjointMessage($name, $extras[$name], $constraint),
            );
        }

        return $extras;
    }

    /**
     * Composer forbids a root package from requiring itself. Empty names and the
     * unnamed-root sentinel are not real package names.
     */
    public static function isSelfRequire(string $packageName, string $rootPackageName): bool
    {
        $packageName = \strtolower(\trim($packageName));
        $rootPackageName = \strtolower(\trim($rootPackageName));
        if ($packageName === '' || $rootPackageName === '' || $rootPackageName === '__root__') {
            return false;
        }

        return $packageName === $rootPackageName;
    }

    /**
     * @param array<string, string> $rootRequire
     * @param array<string, string> $rootRequireDev
     * @param array<string, string> $extras
     * @return array<string, string>
     */
    public static function mergeOntoRequireDev(
        array $rootRequire,
        array $rootRequireDev,
        array $extras,
        string $rootPackageName = '',
    ): array {
        $merged = [];
        foreach ($rootRequireDev as $name => $constraint) {
            $name = \strtolower((string) $name);
            if (self::isSelfRequire($name, $rootPackageName)) {
                continue;
            }
            $merged[$name] = (string) $constraint;
        }

        foreach ($extras as $name => $extraConstraint) {
            $name = \strtolower((string) $name);
            if ($name === '' || !\is_string($extraConstraint)) {
                continue;
            }
            if (self::isSelfRequire($name, $rootPackageName) || isset($rootRequire[$name])) {
                continue;
            }
            if (!isset($merged[$name])) {
                $merged[$name] = $extraConstraint;
                continue;
            }
            if (ConstraintCompatibility::areCompatible($merged[$name], $extraConstraint)) {
                continue;
            }
            throw new ExtraRequireSyncException(
                DiagnosticCodes::DISJOINT_CONSTRAINT,
                DiagnosticCodes::disjointMessage($name, $merged[$name], $extraConstraint),
            );
        }

        return $merged;
    }
}
