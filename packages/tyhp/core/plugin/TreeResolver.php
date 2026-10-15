<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Walks extra.tyhp.require and runtime require edges before vendor populate.
 */
final class TreeResolver
{
    public const COMPILER_PACKAGE = 'tyhp/compiler';

    public const CORE_PACKAGE = 'tyhp/core';

    public const DEFAULT_COMPILER_CONSTRAINT = '@dev';

    /**
     * @return array{extras: array<string, string>, seen: array<string, true>}
     */
    public static function resolve(RootSnapshot $root, PackageCatalog $catalog): array
    {
        $constraints = [];
        $queue = [];
        $enqueue = static function (string $name, string $constraint) use (&$constraints, &$queue): void {
            $name = \strtolower($name);
            if ($name === '') {
                return;
            }
            if (!isset($constraints[$name])) {
                $constraints[$name] = $constraint === '' ? '*' : $constraint;
            }
            $queue[] = $name;
        };

        foreach ($root->require as $name => $constraint) {
            $enqueue((string) $name, (string) $constraint);
        }
        foreach ($root->requireDev as $name => $constraint) {
            $enqueue((string) $name, (string) $constraint);
        }
        foreach ($root->extraRequire as $name => $constraint) {
            $enqueue((string) $name, (string) $constraint);
        }

        $extras = ConstraintMerger::mergeExtras([], $root->extraRequire);
        $seen = [];

        while ($queue !== []) {
            $pkg = \array_shift($queue);
            if (!\is_string($pkg) || $pkg === '') {
                continue;
            }
            if (isset($seen[$pkg])) {
                continue;
            }
            if (PlatformPackages::isPlatform($pkg)) {
                continue;
            }
            $seen[$pkg] = true;

            $meta = $catalog->findBest($pkg, $constraints[$pkg] ?? '*');
            if ($meta === null) {
                continue;
            }

            $extras = ConstraintMerger::mergeExtras($extras, $meta->extraRequire);
            foreach ($meta->extraRequire as $name => $constraint) {
                $enqueue((string) $name, (string) $constraint);
            }
            foreach ($meta->require as $name => $constraint) {
                $enqueue((string) $name, (string) $constraint);
            }
        }

        $hasCore = isset($seen[self::CORE_PACKAGE]) || isset($root->require[self::CORE_PACKAGE]);
        if ($hasCore
            && !isset($extras[self::COMPILER_PACKAGE])
            && !ConstraintMerger::isSelfRequire(self::COMPILER_PACKAGE, $root->name)
        ) {
            $extras[self::COMPILER_PACKAGE] = self::DEFAULT_COMPILER_CONSTRAINT;
        }

        return ['extras' => $extras, 'seen' => $seen];
    }
}
