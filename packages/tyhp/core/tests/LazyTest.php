<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tyhp\Concerns\BootsTraits;
use Tyhp\Concerns\HasGenerics;
use Tyhp\Generic;
use Tyhp\Lazy;
use Tyhp\NamedType;
use Tyhp\Type;

if (\PHP_VERSION_ID >= 80400) {
    $nativeLazy = \dirname(__DIR__, 3) . '/dist/tyhp-core/804.latest/src/Tyhp/Lazy.php';
    if (\is_file($nativeLazy)) {
        require_once $nativeLazy;
    }
}

/**
 * Stand-in for a compiled `class LazyGenericBox<T>`: the init hook records T, and the
 * constructor reads that binding. Same shape as {@see GenericBox}.
 */
class LazyGenericBox
{
    use HasGenerics;
    use BootsTraits;

    public mixed $item = null;

    public ?string $typeAtConstruction = null;

    public function __construct(mixed $item = null)
    {
        $this->tyhpBootTraits();
        if ($this->__tyhpGeneric->needsInit()) {
            self::__initGenerics__tyhpGeneric(null);
        }

        $this->item = $item;
        $this->typeAtConstruction = $this->boundType();
    }

    protected function __initGenerics__tyhpGeneric(?Type ...$generics): void
    {
        $this->tyhpBootTraits();
        if ($this->__tyhpGeneric->isInitialized(self::class)) {
            return;
        }

        $t = $generics[0] ?? Type::mixed();
        $this->__tyhpGeneric->init(static::class, self::class, new NamedType('T', $t));
        $this->__tyhpGeneric->markBound();
    }

    public function boundType(): string
    {
        $this->tyhpBootTraits();

        return (string) ($this->__tyhpGeneric->genericType(self::class, 'T')
            ?->getUnderlyingType() ?? Type::mixed());
    }
}

final class LazyStateProbe
{
    public string $label;

    public bool $initialized = false;

    public function __construct(string $label = 'init')
    {
        $this->label = $label;
        $this->initialized = true;
    }
}

final class LazyDestructorProbe
{
    public static int $destroyed = 0;

    public bool $ready = false;

    public function __construct()
    {
        $this->ready = true;
    }

    public function __destruct()
    {
        ++self::$destroyed;
    }
}

final class LazyTest extends TestCase
{
    public function testEmittedLazyPhp_MatchesPhpVersionFloor(): void
    {
        $php84 = self::readEmittedLazy('804');
        self::assertStringContainsString('->newLazyGhost(', $php84);
        self::assertStringContainsString('->newLazyProxy(', $php84);
        self::assertStringContainsString('->isUninitializedLazyObject(', $php84);
        self::assertStringContainsString('->initializeLazyObject(', $php84);
        self::assertStringContainsString('->resetAsLazyGhost(', $php84);
        self::assertStringContainsString('->setRawValueWithoutLazyInitialization(', $php84);
        self::assertStringNotContainsString('PHP_VERSION_ID', $php84);
        self::assertStringNotContainsString('->newInstanceWithoutConstructor(', $php84);

        // The 8.2 build ships both bodies. 8.4 lazy reflection calls are valid
        // only in the PHP_VERSION_ID >= 80400 branch.
        $php82 = self::readEmittedLazy('802');
        self::assertStringContainsString('->newInstanceWithoutConstructor(', $php82);
        self::assertStringContainsString('->setValue(', $php82);
        self::assertStringContainsString('PHP_VERSION_ID >= 80400', $php82);
        self::assertStringContainsString('PHP_VERSION_ID < 80400', $php82);
        self::assertVersionGateCheckerDistinguishesGuardedCalls();
        self::assertPhp84CallsAreVersionGated($php82, [
            'newLazyGhost',
            'newLazyProxy',
            'resetAsLazyGhost',
            'isUninitializedLazyObject',
            'initializeLazyObject',
            'setRawValueWithoutLazyInitialization',
        ]);
    }

    public function testGhost_OnPhp84_StaysUninitializedUntilPropertyRead(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('native lazy objects require PHP 8.4');
        }

        $ran = false;
        $ghost = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object) use (&$ran): void {
            $ran = true;
            $object->__construct('ready');
        });

        self::assertInstanceOf(LazyStateProbe::class, $ghost);
        self::assertFalse($ran);
        self::assertTrue(Lazy::isLazy($ghost));
        $id = \spl_object_id($ghost);

        self::assertSame('ready', $ghost->label);
        self::assertTrue($ran);
        self::assertFalse(Lazy::isLazy($ghost));
        self::assertSame($id, \spl_object_id($ghost));
    }

    public function testGhost_DoesNotBindGenericArguments(): void
    {
        // `T` names the class. The ghost must not guess its type arguments, even when
        // the caller's binding of `T` is a parameterized type. The initializer decides.
        $type = Type::generic(LazyGenericBox::class)->param('T', Type::int());
        $box = self::ghost($type, function (LazyGenericBox $object): void {
            $object->__construct(42);
        });

        self::assertInstanceOf(LazyGenericBox::class, $box);
        self::assertSame(42, $box->item);
        self::assertSame('mixed', $box->typeAtConstruction);
    }

    public function testGhost_InitializerThatThrows_LeavesObjectLazyOnPhp84(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('native lazy objects require PHP 8.4');
        }

        $attempts = 0;
        $ghost = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object) use (&$attempts): void {
            ++$attempts;
            if ($attempts === 1) {
                throw new \RuntimeException('first attempt fails');
            }
            $object->__construct('second');
        });

        try {
            $ghost->label;
            self::fail('the initializer exception must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('first attempt fails', $e->getMessage());
        }

        self::assertTrue(Lazy::isLazy($ghost));
        self::assertSame('second', $ghost->label);
        self::assertSame(2, $attempts);
        self::assertFalse(Lazy::isLazy($ghost));
    }

    public function testGhost_ForwardsOptions_OnPhp84(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('native lazy objects require PHP 8.4');
        }

        $initializer = function (LazyStateProbe $object): void {
            $object->__construct('serialized');
        };

        $default = self::ghost(LazyStateProbe::class, $initializer);
        \serialize($default);
        self::assertFalse(Lazy::isLazy($default));

        $skipping = self::ghost(
            LazyStateProbe::class,
            $initializer,
            \ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE,
        );
        \serialize($skipping);
        self::assertTrue(Lazy::isLazy($skipping));
    }

    public function testReset_ForwardsOptions_OnPhp84(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('native lazy objects require PHP 8.4');
        }

        $initializer = function (LazyDestructorProbe $object): void {
            $object->__construct();
        };

        LazyDestructorProbe::$destroyed = 0;
        $kept = new LazyDestructorProbe();
        Lazy::reset($kept, $initializer);
        self::assertSame(1, LazyDestructorProbe::$destroyed);

        LazyDestructorProbe::$destroyed = 0;
        $skipped = new LazyDestructorProbe();
        Lazy::reset($skipped, $initializer, \ReflectionClass::SKIP_DESTRUCTOR);
        self::assertSame(0, LazyDestructorProbe::$destroyed);
        self::assertTrue(Lazy::isLazy($skipped));
    }

    public function testGhost_BelowPhp84_RunsInitializerImmediately(): void
    {
        if (\PHP_VERSION_ID >= 80400) {
            self::markTestSkipped('the 8.4 build takes the native path');
        }

        $ran = false;
        $object = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object) use (&$ran): void {
            $ran = true;
            $object->__construct('eager');
        });

        self::assertTrue($ran);
        self::assertFalse(Lazy::isLazy($object));
        self::assertSame('eager', $object->label);
    }

    public function testProxy_OnPhp84_IsNotTheRealInstance(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('native lazy objects require PHP 8.4');
        }

        $real = new LazyStateProbe('real');
        $proxy = self::proxy(LazyStateProbe::class, function (LazyStateProbe $shell) use ($real): LazyStateProbe {
            self::assertNotSame($real, $shell);

            return $real;
        });

        self::assertInstanceOf(LazyStateProbe::class, $proxy);
        self::assertNotSame($real, $proxy);
        self::assertTrue(Lazy::isLazy($proxy));
        self::assertSame('real', $proxy->label);
        self::assertNotSame($real, $proxy);
        self::assertFalse(Lazy::isLazy($proxy));
    }

    public function testIsLazy_FalseForOrdinaryObject_AndForEagerFallback(): void
    {
        self::assertFalse(Lazy::isLazy(new LazyStateProbe('plain')));

        if (\PHP_VERSION_ID >= 80400) {
            return;
        }

        $object = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object): void {
            $object->__construct('eager');
        });
        self::assertFalse(Lazy::isLazy($object));

        $real = new LazyStateProbe('factory');
        $result = self::proxy(LazyStateProbe::class, function (LazyStateProbe $shell) use ($real): LazyStateProbe {
            $shell;

            return $real;
        });
        self::assertSame($real, $result);
        self::assertFalse(Lazy::isLazy($result));
    }

    public function testInitialize_ForcesInit_OnPhp84_AndReturnsSameInstance(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            $object = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object): void {
                $object->__construct('eager');
            });
            self::assertSame($object, Lazy::initialize($object));

            return;
        }

        $ghost = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object): void {
            $object->__construct('forced');
        });
        self::assertTrue(Lazy::isLazy($ghost));

        $same = Lazy::initialize($ghost);
        self::assertSame($ghost, $same);
        self::assertFalse(Lazy::isLazy($ghost));
        self::assertSame('forced', $ghost->label);
    }

    public function testReset_OnPhp84_BecomesLazyAgain(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('native lazy objects require PHP 8.4');
        }

        $ghost = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object): void {
            $object->__construct('first');
        });
        self::assertSame('first', $ghost->label);
        self::assertFalse(Lazy::isLazy($ghost));
        $id = \spl_object_id($ghost);

        Lazy::reset($ghost, function (LazyStateProbe $object): void {
            $object->__construct('second');
        });
        self::assertTrue(Lazy::isLazy($ghost));
        self::assertSame($id, \spl_object_id($ghost));
        self::assertSame('second', $ghost->label);
        self::assertFalse(Lazy::isLazy($ghost));
    }

    public function testPreset_DoesNotInitialize(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            $object = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object): void {
                $object->__construct('eager');
            });
            Lazy::preset($object, 'label', 'written');
            self::assertSame('written', $object->label);

            return;
        }

        $ghost = self::ghost(LazyStateProbe::class, function (LazyStateProbe $object): void {
            $object->__construct('init');
        });
        Lazy::preset($ghost, 'label', 'preset');
        self::assertTrue(Lazy::isLazy($ghost));
        self::assertSame(
            'preset',
            (new \ReflectionProperty(LazyStateProbe::class, 'label'))->getRawValue($ghost),
        );
        self::assertTrue(Lazy::isLazy($ghost));
    }

    private static function assertVersionGateCheckerDistinguishesGuardedCalls(): void
    {
        $gated = <<<'PHP'
            <?php
            if (\PHP_VERSION_ID >= 80400) {
                $object->newLazyGhost($initializer);
            }
            PHP;
        self::assertNull(self::php84CallGuardViolation($gated, 'newLazyGhost'));

        $unguarded = <<<'PHP'
            <?php
            $object->newLazyGhost($initializer);
            PHP;
        self::assertNotNull(self::php84CallGuardViolation($unguarded, 'newLazyGhost'));

        $olderBranch = <<<'PHP'
            <?php
            if (\PHP_VERSION_ID < 80400) {
                $object->newLazyGhost($initializer);
            }
            PHP;
        self::assertNotNull(self::php84CallGuardViolation($olderBranch, 'newLazyGhost'));
    }

    /**
     * @param list<string> $methods
     */
    private static function assertPhp84CallsAreVersionGated(string $php, array $methods): void
    {
        foreach ($methods as $method) {
            self::assertStringContainsString('->' . $method . '(', $php);
            self::assertNull(
                self::php84CallGuardViolation($php, $method),
                self::php84CallGuardViolation($php, $method) ?? '',
            );
        }
    }

    private static function php84CallGuardViolation(string $php, string $method): ?string
    {
        $tokens = \token_get_all($php);
        $guards = [];
        $depth = 0;
        $pending = null;
        $seen = 0;
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                if ($token[0] === \T_IF || $token[0] === \T_ELSEIF) {
                    $guard = self::versionGuardAfterIf($tokens, $i);
                    if ($guard !== null) {
                        $pending = $guard;
                    }
                }
                if ($token[0] === \T_OBJECT_OPERATOR && self::tokenIsCall($tokens, $i, $method)) {
                    $seen++;
                    if ($guards === []) {
                        return $method . ' is called outside PHP_VERSION_ID >= 80400';
                    }
                    $guard = $guards[\count($guards) - 1];
                    if (!self::guardExcludesPhpBefore($guard, 80400)) {
                        return $method . ' is called inside PHP_VERSION_ID ' . $guard['op'] . ' ' . $guard['version'];
                    }
                }
                continue;
            }

            if ($token === '{') {
                $depth++;
                if ($pending !== null) {
                    $pending['depth'] = $depth;
                    $guards[] = $pending;
                    $pending = null;
                }
                continue;
            }
            if ($token === '}') {
                while ($guards !== [] && $guards[\count($guards) - 1]['depth'] === $depth) {
                    \array_pop($guards);
                }
                $depth--;
                $pending = null;
                continue;
            }
            if ($token === ';') {
                $pending = null;
            }
        }

        if ($seen === 0) {
            return $method . ' is not called';
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{op: string, version: int}|null
     */
    private static function versionGuardAfterIf(array $tokens, int $ifIndex): ?array
    {
        $i = self::skipIgnorable($tokens, $ifIndex + 1);
        if ($i === null || $tokens[$i] !== '(') {
            return null;
        }
        $i++;
        $depth = 1;
        $guard = null;
        $count = \count($tokens);
        while ($i < $count && $depth > 0) {
            $token = $tokens[$i];
            if ($token === '(') {
                $depth++;
                $i++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                $i++;
                continue;
            }
            if ($depth === 1 && \is_array($token) && ($token[0] === \T_BOOLEAN_OR || $token[0] === \T_LOGICAL_OR)) {
                return null;
            }
            if ($depth === 1 && $guard === null) {
                $parsed = self::comparisonAt($tokens, $i);
                if ($parsed !== null) {
                    $guard = $parsed['guard'];
                    $i = $parsed['next'];
                    continue;
                }
            }
            $i++;
        }

        return $guard;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{guard: array{op: string, version: int}, next: int}|null
     */
    private static function comparisonAt(array $tokens, int $index): ?array
    {
        $i = $index;
        $token = $tokens[$i];
        if (\is_array($token) && $token[0] === \T_NAME_FULLY_QUALIFIED) {
            if ($token[1] !== '\\PHP_VERSION_ID') {
                return null;
            }
        } else {
            if (\is_array($token) && $token[0] === \T_NS_SEPARATOR) {
                $i = self::skipIgnorable($tokens, $i + 1);
                if ($i === null) {
                    return null;
                }
                $token = $tokens[$i];
            }
            if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== 'PHP_VERSION_ID') {
                return null;
            }
        }
        $i = self::skipIgnorable($tokens, $i + 1);
        if ($i === null) {
            return null;
        }
        $op = self::operatorText($tokens[$i]);
        if ($op === null) {
            return null;
        }
        $i = self::skipIgnorable($tokens, $i + 1);
        if ($i === null) {
            return null;
        }
        $number = $tokens[$i];
        if (!\is_array($number) || $number[0] !== \T_LNUMBER || !\ctype_digit($number[1])) {
            return null;
        }

        return [
            'guard' => ['op' => $op, 'version' => (int) $number[1]],
            'next' => $i + 1,
        ];
    }

    private static function operatorText(array|string $token): ?string
    {
        if ($token === '<' || $token === '>') {
            return $token;
        }
        if (!\is_array($token)) {
            return null;
        }

        return match ($token[0]) {
            \T_IS_GREATER_OR_EQUAL => '>=',
            \T_IS_SMALLER_OR_EQUAL => '<=',
            \T_IS_EQUAL => '==',
            \T_IS_IDENTICAL => '===',
            \T_IS_NOT_EQUAL => '!=',
            \T_IS_NOT_IDENTICAL => '!==',
            default => null,
        };
    }

    /**
     * @param array{op: string, version: int} $guard
     */
    private static function guardExcludesPhpBefore(array $guard, int $floor): bool
    {
        return match ($guard['op']) {
            '>=' => $guard['version'] >= $floor,
            '>' => $guard['version'] >= ($floor - 1),
            default => false,
        };
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function tokenIsCall(array $tokens, int $operatorIndex, string $method): bool
    {
        $i = self::skipIgnorable($tokens, $operatorIndex + 1);
        if ($i === null) {
            return false;
        }
        $token = $tokens[$i];
        if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== $method) {
            return false;
        }
        $i = self::skipIgnorable($tokens, $i + 1);

        return $i !== null && $tokens[$i] === '(';
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function skipIgnorable(array $tokens, int $index): ?int
    {
        $count = \count($tokens);
        while ($index < $count) {
            $token = $tokens[$index];
            if (\is_array($token) && (
                $token[0] === \T_WHITESPACE
                || $token[0] === \T_COMMENT
                || $token[0] === \T_DOC_COMMENT
            )) {
                $index++;
                continue;
            }

            return $index;
        }

        return null;
    }

    private static function readEmittedLazy(string $phpMajor): string
    {
        $path = \dirname(__DIR__, 3) . '/dist/tyhp-core/' . $phpMajor . '.latest/src/Tyhp/Lazy.php';
        self::assertFileExists($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @param callable(object): void $initializer
     */
    private static function ghost(Type|string $type, callable $initializer, int $options = 0): object
    {
        $bound = Generic::bind(Lazy::ghost(...), self::typeOf($type));
        self::assertInstanceOf(\Closure::class, $bound);
        $object = $bound($initializer, $options);
        self::assertIsObject($object);

        return $object;
    }

    /**
     * @param callable(object): object $factory
     */
    private static function proxy(Type|string $type, callable $factory, int $options = 0): object
    {
        $bound = Generic::bind(Lazy::proxy(...), self::typeOf($type));
        self::assertInstanceOf(\Closure::class, $bound);
        $object = $bound($factory, $options);
        self::assertIsObject($object);

        return $object;
    }

    private static function typeOf(Type|string $type): Type
    {
        return $type instanceof Type ? $type : Type::fromClassName($type);
    }
}
