<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Detects Composer --no-dev / COMPOSER_NO_DEV / locker install flags.
 */
final class DevMode
{
    public static function isNoDev(?object $composer = null): bool
    {
        $devMode = self::env('COMPOSER_DEV_MODE');
        if ($devMode === '0') {
            return true;
        }
        if ($devMode === '1') {
            return false;
        }

        $noDev = self::env('COMPOSER_NO_DEV');
        if ($noDev !== null && $noDev !== '' && $noDev !== '0' && \strtolower($noDev) !== 'false') {
            return true;
        }

        if ($composer !== null && \method_exists($composer, 'getLocker')) {
            try {
                $locker = $composer->getLocker();
                if (\is_object($locker) && \method_exists($locker, 'getDevMode')) {
                    $lockedDev = $locker->getDevMode();
                    if ($lockedDev === false) {
                        return true;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return false;
    }

    private static function env(string $name): ?string
    {
        $value = \getenv($name);
        if ($value === false) {
            return null;
        }

        return (string) $value;
    }
}
