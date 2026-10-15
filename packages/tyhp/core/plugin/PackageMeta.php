<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Repository metadata used by the extra.tyhp.require tree walk.
 *
 * Intentionally omits require-dev: dependency author-only pins are not ambient.
 */
final class PackageMeta
{
    /**
     * @param array<string, string> $require
     * @param array<string, string> $extraRequire
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly array $require,
        public readonly array $extraRequire,
    ) {}

    public static function fromComposerPackage(object $package): self
    {
        $name = '';
        if (\method_exists($package, 'getName')) {
            $name = \strtolower((string) $package->getName());
        }

        $version = '0.0.0.0';
        if (\method_exists($package, 'getVersion')) {
            $version = (string) $package->getVersion();
        }

        $require = [];
        if (\method_exists($package, 'getRequires')) {
            $require = self::linksToConstraints($package->getRequires());
        }

        $extraRequire = [];
        if (\method_exists($package, 'getExtra')) {
            $extraRequire = self::tyhpRequireFromExtra($package->getExtra());
        }

        return new self($name, $version, $require, $extraRequire);
    }

    /**
     * @param mixed $extra
     * @return array<string, string>
     */
    public static function tyhpRequireFromExtra(mixed $extra): array
    {
        if (!\is_array($extra)) {
            return [];
        }

        $tyhp = $extra['tyhp'] ?? null;
        if (!\is_array($tyhp)) {
            return [];
        }

        $require = $tyhp['require'] ?? null;
        if (!\is_array($require)) {
            return [];
        }

        $out = [];
        foreach ($require as $name => $constraint) {
            if (!\is_string($name) || $name === '' || !\is_string($constraint)) {
                continue;
            }
            $out[\strtolower($name)] = $constraint;
        }

        return $out;
    }

    /**
     * @param mixed $links
     * @return array<string, string>
     */
    public static function linksToConstraints(mixed $links): array
    {
        if (!\is_array($links)) {
            return [];
        }

        $out = [];
        foreach ($links as $name => $link) {
            $packageName = \is_string($name) ? \strtolower($name) : '';
            $constraint = '';
            if (\is_object($link) && \method_exists($link, 'getPrettyConstraint')) {
                $constraint = (string) $link->getPrettyConstraint();
                if ($packageName === '' && \method_exists($link, 'getTarget')) {
                    $packageName = \strtolower((string) $link->getTarget());
                }
            } elseif (\is_string($link)) {
                $constraint = $link;
            }
            if ($packageName === '' || $constraint === '') {
                continue;
            }
            $out[$packageName] = $constraint;
        }

        return $out;
    }
}
