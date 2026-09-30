<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Collects extra.tyhp.require, merges onto require-dev, and optionally persists composer.json.
 */
final class ExtraRequireSync
{
    /**
     * @return array{skippedNoDev: bool, changed: bool, requireDev: array<string, string>, extras: array<string, string>, seen: array<string, true>}
     */
    public static function run(
        RootSnapshot $root,
        PackageCatalog $catalog,
        bool $devMode,
        ?ComposerJsonFile $composerJson = null,
    ): array {
        if (!$devMode) {
            return [
                'skippedNoDev' => true,
                'changed' => false,
                'requireDev' => $root->requireDev,
                'extras' => [],
                'seen' => [],
            ];
        }

        $resolved = TreeResolver::resolve($root, $catalog);
        $merged = ConstraintMerger::mergeOntoRequireDev(
            $root->require,
            $root->requireDev,
            $resolved['extras'],
            $root->name,
        );

        $changed = !self::mapsEqual($root->requireDev, $merged);
        if ($composerJson !== null) {
            if ($changed) {
                $composerJson->writeRequireDev($merged);
            } else {
                $composerJson->ensureAllowPlugins();
            }
        }

        return [
            'skippedNoDev' => false,
            'changed' => $changed,
            'requireDev' => $merged,
            'extras' => $resolved['extras'],
            'seen' => $resolved['seen'],
        ];
    }

    /**
     * @param array<string, string> $left
     * @param array<string, string> $right
     */
    public static function mapsEqual(array $left, array $right): bool
    {
        $left = self::normalizeMap($left);
        $right = self::normalizeMap($right);
        if (\count($left) !== \count($right)) {
            return false;
        }
        foreach ($left as $name => $constraint) {
            if (!isset($right[$name]) || $right[$name] !== $constraint) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $map
     * @return array<string, string>
     */
    private static function normalizeMap(array $map): array
    {
        $out = [];
        foreach ($map as $name => $constraint) {
            $out[\strtolower((string) $name)] = (string) $constraint;
        }

        return $out;
    }
}
