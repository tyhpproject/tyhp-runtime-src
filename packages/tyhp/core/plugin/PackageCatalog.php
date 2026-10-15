<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Looks up package metadata from Composer repositories (or a test double).
 */
interface PackageCatalog
{
    /**
     * Highest version that satisfies {@see $constraint}, or null when the name is unknown.
     */
    public function findBest(string $name, string $constraint): ?PackageMeta;
}
