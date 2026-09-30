<?php

declare(strict_types=1);

namespace Tyhp\Tests\Composer;

use Tyhp\Composer\PackageCatalog;
use Tyhp\Composer\PackageMeta;

/**
 * In-memory catalog for plugin unit tests. Never talks to Packagist.
 */
final class InMemoryPackageCatalog implements PackageCatalog
{
    /** @var array<string, list<PackageMeta>> */
    private array $packages = [];

    /** @var list<string> */
    public array $fetchedNames = [];

    public function add(PackageMeta $package): void
    {
        $this->packages[\strtolower($package->name)][] = $package;
    }

    public function findBest(string $name, string $constraint): ?PackageMeta
    {
        $this->fetchedNames[] = \strtolower($name);
        $candidates = $this->packages[\strtolower($name)] ?? [];
        if ($candidates === []) {
            return null;
        }
        $best = $candidates[0];
        foreach ($candidates as $candidate) {
            if (\version_compare($candidate->version, $best->version, '>')) {
                $best = $candidate;
            }
        }

        return $best;
    }
}
