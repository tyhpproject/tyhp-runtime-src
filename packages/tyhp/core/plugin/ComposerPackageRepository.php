<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * PackageCatalog backed by Composer's RepositoryManager (Packagist + path/vcs repos).
 */
final class ComposerPackageRepository implements PackageCatalog
{
    public function __construct(private readonly object $repositoryManager) {}

    public function findBest(string $name, string $constraint): ?PackageMeta
    {
        $best = $this->selectBest($this->findComposerPackages($name, $constraint));
        if ($best === null) {
            return null;
        }

        return PackageMeta::fromComposerPackage($best);
    }

    /**
     * @return list<object>
     */
    public function findComposerPackages(string $name, string $constraint): array
    {
        if (!\method_exists($this->repositoryManager, 'getRepositories')) {
            return [];
        }

        $constraintArg = $this->constraintArgument($constraint);
        $found = [];
        try {
            $repos = $this->repositoryManager->getRepositories();
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($repos) && !$repos instanceof \Traversable) {
            return [];
        }

        foreach ($repos as $repo) {
            if (!\is_object($repo) || !\method_exists($repo, 'findPackages')) {
                continue;
            }
            try {
                $matches = $repo->findPackages($name, $constraintArg);
            } catch (\Throwable) {
                continue;
            }
            if (!\is_array($matches)) {
                continue;
            }
            foreach ($matches as $package) {
                if (\is_object($package)) {
                    $found[] = $package;
                }
            }
        }

        return $found;
    }

    /**
     * @param list<object> $packages
     */
    public function selectBest(array $packages): ?object
    {
        $best = null;
        $bestVersion = null;
        foreach ($packages as $package) {
            $version = \method_exists($package, 'getVersion') ? (string) $package->getVersion() : '0.0.0.0';
            if ($bestVersion === null || \version_compare($version, $bestVersion, '>')) {
                $best = $package;
                $bestVersion = $version;
            }
        }

        return $best;
    }

    private function constraintArgument(string $constraint): mixed
    {
        $constraint = \trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return null;
        }
        if (!\class_exists(\Composer\Semver\VersionParser::class)) {
            return $constraint;
        }
        try {
            return (new \Composer\Semver\VersionParser())
                ->parseConstraints(ConstraintCompatibility::normalizeForParser($constraint));
        } catch (\Throwable) {
            return $constraint;
        }
    }
}
