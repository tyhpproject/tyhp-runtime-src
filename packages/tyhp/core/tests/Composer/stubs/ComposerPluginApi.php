<?php

declare(strict_types=1);

/**
 * Minimal Composer plugin-api stubs for PHPUnit when composer/composer is not installed.
 * Skipped when the real classes already exist.
 */

namespace Composer\Plugin {
    if (!\interface_exists(PluginInterface::class, false)) {
        interface PluginInterface
        {
            public function activate(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void;

            public function deactivate(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void;

            public function uninstall(\Composer\Composer $composer, \Composer\IO\IOInterface $io): void;
        }
    }

    if (!\class_exists(PluginEvents::class, false)) {
        class PluginEvents
        {
            public const INIT = 'init';

            public const PRE_DEPENDENCIES_SOLVING = 'pre-dependencies-solving';

            public const PRE_POOL_CREATE = 'pre-pool-create';
        }
    }
}

namespace Composer\EventDispatcher {
    if (!\interface_exists(EventSubscriberInterface::class, false)) {
        interface EventSubscriberInterface
        {
            /**
             * @return array<string, mixed>
             */
            public static function getSubscribedEvents();
        }
    }
}

namespace Composer\IO {
    if (!\interface_exists(IOInterface::class, false)) {
        interface IOInterface
        {
            /**
             * @param string|string[] $messages
             */
            public function writeError($messages, bool $newline = true, int $verbosity = 2): void;
        }
    }
}

namespace Composer\Package {
    if (!\class_exists(Link::class, false)) {
        class Link
        {
            public const TYPE_DEV_REQUIRE = 'requires (for development)';

            public function __construct(
                private readonly string $source,
                private readonly string $target,
                private readonly object $constraint,
                private readonly string $description = 'requires',
                private readonly ?string $prettyConstraint = null,
            ) {
            }

            public function getSource(): string
            {
                return $this->source;
            }

            public function getTarget(): string
            {
                return $this->target;
            }

            public function getPrettyConstraint(): string
            {
                return (string) ($this->prettyConstraint ?? '');
            }

            public function getDescription(): string
            {
                return $this->description;
            }

            public function getConstraint(): object
            {
                return $this->constraint;
            }
        }
    }
}

namespace Composer {
    if (!\class_exists(Composer::class, false)) {
        class Composer
        {
            public mixed $package = null;

            public mixed $locker = null;

            public mixed $repositoryManager = null;

            public function getPackage(): mixed
            {
                return $this->package;
            }

            public function getLocker(): mixed
            {
                return $this->locker;
            }

            public function getRepositoryManager(): mixed
            {
                return $this->repositoryManager;
            }

            public function getConfig(): mixed
            {
                return null;
            }
        }
    }
}
