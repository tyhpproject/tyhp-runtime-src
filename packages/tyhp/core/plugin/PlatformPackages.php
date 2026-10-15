<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Composer platform packages are never fetched from repositories.
 *
 * Mirrors `\Composer\Repository\PlatformRepository::PLATFORM_PACKAGE_REGEX` exactly
 * (`php`, `php-64bit`, `php-ipv6`, `php-zts`, `php-debug`, `hhvm`, `ext-*`, `lib-*`,
 * `composer`, `composer-plugin-api`, `composer-runtime-api`). Delegate to the real
 * Composer helper when it is loaded (always true when this plugin runs inside an
 * actual Composer process) so a future Composer platform-package change cannot
 * silently drift from this list. The regex fallback below is only exercised by unit
 * tests, which do not load `composer/composer`, and is kept byte-for-byte identical
 * to Composer's own pattern (a loose `php-*` / arbitrary-suffix match would wrongly
 * treat a real vendor-less package name as a platform package).
 */
final class PlatformPackages
{
    private const FALLBACK_REGEX = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer(?:-(?:plugin|runtime)-api)?)$}iD';

    public static function isPlatform(string $name): bool
    {
        if (
            \class_exists(\Composer\Repository\PlatformRepository::class)
            && \method_exists(\Composer\Repository\PlatformRepository::class, 'isPlatformPackage')
        ) {
            try {
                return \Composer\Repository\PlatformRepository::isPlatformPackage($name);
            } catch (\Throwable) {
                // Fall through to the regex below.
            }
        }

        return \preg_match(self::FALLBACK_REGEX, $name) === 1;
    }
}
