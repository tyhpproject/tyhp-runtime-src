<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Root package require / require-dev / extra.tyhp.require as pretty constraint maps.
 */
final class RootSnapshot
{
    /**
     * @param array<string, string> $require
     * @param array<string, string> $requireDev
     * @param array<string, string> $extraRequire
     * @param string $name Root composer.json package name (empty when unnamed)
     */
    public function __construct(
        public readonly array $require,
        public readonly array $requireDev,
        public readonly array $extraRequire,
        public readonly string $name = '',
    ) {}

    public static function fromRootPackage(object $package): self
    {
        $require = [];
        if (\method_exists($package, 'getRequires')) {
            $require = PackageMeta::linksToConstraints($package->getRequires());
        }

        $requireDev = [];
        if (\method_exists($package, 'getDevRequires')) {
            $requireDev = PackageMeta::linksToConstraints($package->getDevRequires());
        }

        $extraRequire = [];
        if (\method_exists($package, 'getExtra')) {
            $extraRequire = PackageMeta::tyhpRequireFromExtra($package->getExtra());
        }

        $name = '';
        if (\method_exists($package, 'getName')) {
            $name = (string) $package->getName();
        }

        return new self($require, $requireDev, $extraRequire, $name);
    }
}
