<?php

declare(strict_types=1);

namespace Tyhp\Composer;

/**
 * Composer plugin: walks extra.tyhp.require before the solver and writes root require-dev.
 *
 * Hosted on tyhp/core (`type: composer-plugin`, extra.class). No CommandProvider.
 */
final class Plugin implements \Composer\Plugin\PluginInterface, \Composer\EventDispatcher\EventSubscriberInterface
{
    private ?\Composer\Composer $composer = null;

    private ?\Composer\IO\IOInterface $io = null;

    private bool $ran = false;

    public function activate(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void
    {
        $this->composer = null;
        $this->io = null;
        $this->ran = false;
    }

    public function uninstall(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void
    {
        $this->composer = null;
        $this->io = null;
    }

    /**
     * Composer 2 removed `PluginEvents::PRE_DEPENDENCIES_SOLVING` (a Composer 1 event);
     * the constant does not exist on `\Composer\Plugin\PluginEvents` in Composer 2.x, so
     * subscribing to it by name only ever matches the literal string below, which
     * Composer 2 never dispatches. It is kept as a harmless, forward-compatible
     * subscription in case a future Composer reintroduces that event name.
     *
     * The functioning Composer 2 hook is `PluginEvents::PRE_POOL_CREATE`
     * (`pre-pool-create`), dispatched by `PoolBuilder::buildPool()` after Composer has
     * loaded the root request's packages but before the `Pool` (and therefore the
     * solver) is constructed. It is the last extension point that runs before vendor
     * populate / dependency solving, so both event names route to the same handler.
     * A one-shot guard (`$this->ran`) ensures a single run even if both fire (or if
     * `pre-pool-create` fires more than once for the same Composer command).
     *
     * @return array<string, array{0: string, 1?: int}>
     */
    public static function getSubscribedEvents(): array
    {
        $solving = 'pre-dependencies-solving';
        $pool = 'pre-pool-create';
        if (\class_exists(\Composer\Plugin\PluginEvents::class)) {
            $cls = \Composer\Plugin\PluginEvents::class;
            if (\defined($cls . '::PRE_DEPENDENCIES_SOLVING')) {
                $solving = \constant($cls . '::PRE_DEPENDENCIES_SOLVING');
            }
            if (\defined($cls . '::PRE_POOL_CREATE')) {
                $pool = \constant($cls . '::PRE_POOL_CREATE');
            }
        }

        return [
            $solving => ['onPreDependenciesSolving', 0],
            $pool => ['onPreDependenciesSolving', 0],
        ];
    }

    public function onPreDependenciesSolving(object $event): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        try {
            $this->synchronize($event);
        } catch (ExtraRequireSyncException $e) {
            $this->writeError($e->getMessage());
            throw $e;
        }
    }

    private function synchronize(object $event): void
    {
        $composer = $this->composer;
        if ($composer === null) {
            return;
        }
        if (DevMode::isNoDev($composer)) {
            return;
        }

        $rootPackage = $composer->getPackage();
        $root = RootSnapshot::fromRootPackage($rootPackage);
        $manager = \method_exists($composer, 'getRepositoryManager') ? $composer->getRepositoryManager() : null;
        if (!\is_object($manager)) {
            return;
        }

        $catalog = new ComposerPackageRepository($manager);
        $jsonPath = $this->composerJsonPath();
        $store = \is_file($jsonPath) ? new ComposerJsonFile($jsonPath) : null;

        $result = ExtraRequireSync::run($root, $catalog, true, $store);
        if (!$result['changed']) {
            return;
        }

        $this->setDevRequires($rootPackage, $result['requireDev']);
        $this->applyToRequest($event, $result['requireDev']);
        $this->addPackagesToPool($event, $catalog, $result['requireDev']);
    }

    /**
     * @param array<string, string> $requireDev
     */
    private function setDevRequires(object $rootPackage, array $requireDev): void
    {
        if (!\method_exists($rootPackage, 'setDevRequires') || !\class_exists(\Composer\Package\Link::class)) {
            return;
        }

        $source = '__root__';
        if (\method_exists($rootPackage, 'getName')) {
            $name = (string) $rootPackage->getName();
            if ($name !== '') {
                $source = $name;
            }
        }

        $description = \defined(\Composer\Package\Link::class . '::TYPE_DEV_REQUIRE')
            ? \constant(\Composer\Package\Link::class . '::TYPE_DEV_REQUIRE')
            : 'requires (for development)';

        $links = [];
        foreach ($requireDev as $name => $pretty) {
            try {
                $links[$name] = new \Composer\Package\Link(
                    $source,
                    $name,
                    $this->parseConstraint($pretty),
                    $description,
                    $pretty,
                );
            } catch (\Throwable $e) {
                throw new ExtraRequireSyncException(
                    DiagnosticCodes::ROOT_JSON_WRITE_FAILED,
                    DiagnosticCodes::rootJsonMessage($this->composerJsonPath(), $e->getMessage()),
                    $e,
                );
            }
        }

        $rootPackage->setDevRequires($links);
    }

    /**
     * @param array<string, string> $requireDev
     */
    private function applyToRequest(object $event, array $requireDev): void
    {
        if (!\method_exists($event, 'getRequest')) {
            return;
        }
        try {
            $request = $event->getRequest();
        } catch (\Throwable) {
            return;
        }
        if (!\is_object($request) || !\method_exists($request, 'requireName')) {
            return;
        }
        foreach ($requireDev as $name => $pretty) {
            try {
                $request->requireName($name, $this->parseConstraint($pretty));
            } catch (\Throwable) {
                try {
                    $request->requireName($name);
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * @param array<string, string> $requireDev
     */
    private function addPackagesToPool(object $event, ComposerPackageRepository $catalog, array $requireDev): void
    {
        if (!\method_exists($event, 'getPackages') || !\method_exists($event, 'setPackages')) {
            return;
        }
        try {
            $packages = $event->getPackages();
        } catch (\Throwable) {
            return;
        }
        if (!\is_array($packages)) {
            return;
        }

        $have = [];
        foreach ($packages as $package) {
            if (\is_object($package) && \method_exists($package, 'getName')) {
                $have[\strtolower((string) $package->getName())] = true;
            }
        }

        foreach ($requireDev as $name => $constraint) {
            if (isset($have[\strtolower($name)])) {
                continue;
            }
            foreach ($catalog->findComposerPackages($name, $constraint) as $package) {
                $packages[] = $package;
                if (\method_exists($package, 'getName')) {
                    $have[\strtolower((string) $package->getName())] = true;
                }
            }
        }

        try {
            $event->setPackages($packages);
        } catch (\Throwable) {
        }
    }

    private function parseConstraint(string $pretty): object
    {
        if (\class_exists(\Composer\Semver\VersionParser::class)) {
            return (new \Composer\Semver\VersionParser())
                ->parseConstraints(ConstraintCompatibility::normalizeForParser($pretty));
        }

        return new class ($pretty) {
            public function __construct(private readonly string $pretty) {}

            public function getPrettyString(): string
            {
                return $this->pretty;
            }

            public function __toString(): string
            {
                return $this->pretty;
            }
        };
    }

    private function composerJsonPath(): string
    {
        if (\class_exists(\Composer\Factory::class) && \method_exists(\Composer\Factory::class, 'getComposerFile')) {
            try {
                $file = (string) \Composer\Factory::getComposerFile();
                if ($file !== '') {
                    if (!$this->isAbsolutePath($file)) {
                        $cwd = \getcwd();
                        if ($cwd !== false) {
                            $file = $cwd . \DIRECTORY_SEPARATOR . $file;
                        }
                    }

                    return $file;
                }
            } catch (\Throwable) {
            }
        }

        $cwd = \getcwd();

        return ($cwd === false ? '.' : $cwd) . \DIRECTORY_SEPARATOR . 'composer.json';
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return \strlen($path) >= 3 && $path[1] === ':' && ($path[2] === '\\' || $path[2] === '/');
    }

    private function writeError(string $message): void
    {
        if ($this->io === null) {
            return;
        }
        try {
            $this->io->writeError('<error>' . $message . '</error>');
        } catch (\Throwable) {
        }
    }
}
