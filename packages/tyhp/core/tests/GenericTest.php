<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tyhp\BuiltIn;
use Tyhp\Concerns\BootsTraits;
use Tyhp\Concerns\HasGenerics;
use Tyhp\Exceptions\InvalidTypeException;
use Tyhp\Generic;
use Tyhp\GenericRuntime;
use Tyhp\NamedType;
use Tyhp\Type;
use Tyhp\TypeLike;

if (\PHP_VERSION_ID >= 80400) {
    $nativeGeneric = \dirname(__DIR__, 3) . '/dist/tyhp-core/804.latest/src/Tyhp/Generic.php';
    if (\is_file($nativeGeneric)) {
        require_once $nativeGeneric;
    }
}

/**
 * Shaped like the emitter's output for a tracked `class GenericBox<T>`: GenericRuntime,
 * the trait, the generated init hook, the factory, and the constructor's gated call into it.
 */
#[GenericRuntime(erased: false, factory: 'new_GenericBox__tyhpGeneric', layouts: [1])]
class GenericBox
{
    use HasGenerics;
    use BootsTraits;

    private static ?\ReflectionClass $__reflectedClass__tyhpGeneric = null;

    public mixed $item = null;

    public bool $constructorRan = false;

    /**
     * Records what T was bound to at the moment the constructor body ran, which is the whole point
     * of binding before construction.
     */
    public ?string $typeAtConstruction = null;

    /** `spl_object_id($this)` inside the constructor, to compare with the object the caller holds. */
    public ?int $thisAtConstruction = null;

    public function __construct(mixed $item)
    {
        $this->thisAtConstruction = \spl_object_id($this);
        $this->tyhpBootTraits();
        if ($this->__tyhpGeneric->needsInit()) {
            self::__initGenerics__tyhpGeneric(null);
        }

        $this->item = $item;
        $this->constructorRan = true;
        $this->typeAtConstruction = $this->boundType();
    }

    public static function new_GenericBox__tyhpGeneric(?Type $__generic_T, mixed $item): self
    {
        self::$__reflectedClass__tyhpGeneric ??= new \ReflectionClass(self::class);
        $obj = self::$__reflectedClass__tyhpGeneric->newInstanceWithoutConstructor();
        $obj->__initGenerics__tyhpGeneric($__generic_T);
        $obj->__construct($item);
        return $obj;
    }

    protected function __initGenerics__tyhpGeneric(?Type ...$generics): void
    {
        $this->tyhpBootTraits();
        if ($this->__tyhpGeneric->isInitialized(GenericBox::class)) {
            return;
        }

        $t = $generics[0] ?? Type::mixed();
        $this->__tyhpGeneric->init(static::class, GenericBox::class, new NamedType('T', $t));
        $this->__tyhpGeneric->markBound();
    }

    public function boundType(): string
    {
        $this->tyhpBootTraits();
        return (string) ($this->__tyhpGeneric->genericType(GenericBox::class, 'T')
            ?->getUnderlyingType() ?? Type::mixed());
    }
}

/** No GenericRuntime stamp, so not something Generic can bind. */
class PlainBox
{
    public function __construct(public mixed $item = null)
    {
    }
}

#[GenericRuntime(erased: true, layouts: [1])]
class ErasedBox
{
    public function __construct(public mixed $item = null)
    {
    }
}

#[GenericRuntime(erased: true, layouts: [99], compiler: 'future')]
class FutureLayoutBox
{
    public function __construct(public mixed $item = null)
    {
    }
}

/**
 * A tracked generic whose constructor has a defaulted parameter in the middle, the shape a
 * compiled `PropertyPath` has (`$allowEmpty` between `$callable` and `$nullSafeFlags`).
 */
#[GenericRuntime(erased: false, factory: 'new_GenericNamedBox__tyhpGeneric', layouts: [1])]
class GenericNamedBox
{
    use HasGenerics;
    use BootsTraits;

    private static ?\ReflectionClass $__reflectedClass__tyhpGeneric = null;

    public function __construct(
        public mixed $first = null,
        public bool $flag = false,
        public array $items = [],
    ) {
        $this->tyhpBootTraits();
        if ($this->__tyhpGeneric->needsInit()) {
            self::__initGenerics__tyhpGeneric(null);
        }
    }

    public static function new_GenericNamedBox__tyhpGeneric(
        ?Type $__generic_T,
        mixed $first = null,
        bool $flag = false,
        array $items = [],
    ): self {
        self::$__reflectedClass__tyhpGeneric ??= new \ReflectionClass(self::class);
        $obj = self::$__reflectedClass__tyhpGeneric->newInstanceWithoutConstructor();
        $obj->__initGenerics__tyhpGeneric($__generic_T);
        $obj->__construct($first, $flag, $items);
        return $obj;
    }

    protected function __initGenerics__tyhpGeneric(?Type ...$generics): void
    {
        $this->tyhpBootTraits();
        if ($this->__tyhpGeneric->isInitialized(GenericNamedBox::class)) {
            return;
        }

        $t = $generics[0] ?? Type::mixed();
        $this->__tyhpGeneric->init(static::class, GenericNamedBox::class, new NamedType('T', $t));
        $this->__tyhpGeneric->markBound();
    }
}

/**
 * Root of a two-level generic chain, shaped like the emitter's output for `class GenericChainParent<T>`.
 * Every hook call is logged so a test can compare the chain `new` runs with the chain `ghost` runs.
 */
class GenericChainParent
{
    use HasGenerics;
    use BootsTraits;

    /** @var list<string> */
    public array $hookLog = [];

    public ?string $parentTypeAtConstruction = null;

    public function __construct()
    {
        $this->tyhpBootTraits();
        if ($this->__tyhpGeneric->needsInit()) {
            self::__initGenerics__tyhpGeneric(null);
        }

        $this->parentTypeAtConstruction = (string) $this->__tyhpGeneric->resolvedType(GenericChainParent::class, 'T');
    }

    protected function __initGenerics__tyhpGeneric(?Type ...$generics): void
    {
        $this->tyhpBootTraits();
        $t = $generics[0] ?? Type::mixed();
        $this->hookLog[] = 'Parent:' . $t;
        if ($this->__tyhpGeneric->isInitialized(GenericChainParent::class)) {
            return;
        }

        $this->__tyhpGeneric->init(static::class, GenericChainParent::class, new NamedType('T', $t));
        $this->__tyhpGeneric->markBound();
    }
}

/**
 * `class GenericChainChild<U> extends GenericChainParent<string>`: its hook takes only `U` and chains to
 * the parent's hook with the parent's own argument.
 */
#[GenericRuntime(erased: false, factory: 'new_GenericChainChild__tyhpGeneric', layouts: [1])]
class GenericChainChild extends GenericChainParent
{
    public ?string $childTypeAtConstruction = null;

    public ?int $thisAtConstruction = null;

    public function __construct(public mixed $item = null)
    {
        $this->tyhpBootTraits();
        if ($this->__tyhpGeneric->needsInit()) {
            self::__initGenerics__tyhpGeneric(null);
        }

        parent::__construct();
        $this->childTypeAtConstruction = (string) $this->__tyhpGeneric->resolvedType(GenericChainChild::class, 'U');
        $this->thisAtConstruction = \spl_object_id($this);
    }

    public static function new_GenericChainChild__tyhpGeneric(?Type $__generic_U, mixed $item = null): self
    {
        $obj = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $obj->__initGenerics__tyhpGeneric($__generic_U);
        $obj->__construct($item);
        return $obj;
    }

    protected function __initGenerics__tyhpGeneric(?Type ...$generics): void
    {
        $this->tyhpBootTraits();
        $u = $generics[0] ?? Type::mixed();
        $this->hookLog[] = 'Child:' . $u;
        if ($this->__tyhpGeneric->isInitialized(GenericChainChild::class)) {
            return;
        }

        $this->__tyhpGeneric->init(static::class, GenericChainChild::class, new NamedType('U', $u));
        parent::__initGenerics__tyhpGeneric(Type::string());
    }
}

/** Stamped subclass that declares no hook of its own: the protected hook is declared on `GenericBox`. */
#[GenericRuntime(erased: false, factory: 'new_GenericInheritedHookBox__tyhpGeneric', layouts: [1])]
class GenericInheritedHookBox extends GenericBox
{
    public static function new_GenericInheritedHookBox__tyhpGeneric(?Type $__generic_T, mixed $item): self
    {
        $obj = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $obj->__initGenerics__tyhpGeneric($__generic_T);
        $obj->__construct($item);
        return $obj;
    }
}

/** An erased class with no instance properties cannot be lazy, and PHP never runs its ghost initializer. */
#[GenericRuntime(erased: true, layouts: [1])]
class GenericStatelessErasedBox
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }
}

/** An erased class with no constructor at all. */
#[GenericRuntime(erased: true, layouts: [1])]
class GenericBareErasedBox
{
    public int $value = 5;
}

class GenericTest extends TestCase
{
    /**
     * A named argument that skips a defaulted parameter must stay bound to its own parameter,
     * not slide into the next positional slot.
     */
    public function testNamedArgumentSkippingADefaultedParameterKeepsItsName(): void
    {
        $box = Generic::bind(GenericNamedBox::class, null)('x', items: [true, false]);

        self::assertSame('x', $box->first);
        self::assertFalse($box->flag, 'the skipped defaulted parameter keeps its default');
        self::assertSame([true, false], $box->items);
    }

    public function testNamedArgumentsMayBeReordered(): void
    {
        $box = Generic::bind(GenericNamedBox::class, Type::int())(items: [1], flag: true, first: 5);

        self::assertSame(5, $box->first);
        self::assertTrue($box->flag);
        self::assertSame([1], $box->items);
    }

    /**
     * The reason this helper exists. A constructor body that reads its own type arguments has to see
     * them already bound, which a plain `new` cannot do.
     */
    public function testTypeArgumentsAreBoundBeforeTheConstructorRuns(): void
    {
        $box = Generic::bind(GenericBox::class, Type::int())(42);

        self::assertInstanceOf(GenericBox::class, $box);
        self::assertTrue($box->constructorRan);
        self::assertSame(42, $box->item, 'constructor arguments are forwarded');
        self::assertSame('int', $box->typeAtConstruction, 'T was already bound during construction');
    }

    public function testNewIsEquivalentToInvoking(): void
    {
        $box = Generic::bind(GenericBox::class, Type::string())->new('hello');

        self::assertSame('hello', $box->item);
        self::assertSame('string', $box->boundType());
    }

    public function testNewStaysEager(): void
    {
        $box = Generic::bind(GenericBox::class, Type::int())->new(42);
        if (\PHP_VERSION_ID >= 80400) {
            self::assertFalse((new \ReflectionClass($box))->isUninitializedLazyObject($box));
        }
        self::assertTrue($box->constructorRan);
        self::assertSame(42, $box->item);

        $invoked = Generic::bind(GenericBox::class, Type::int())(42);
        if (\PHP_VERSION_ID >= 80400) {
            self::assertFalse((new \ReflectionClass($invoked))->isUninitializedLazyObject($invoked));
        }
        self::assertTrue($invoked->constructorRan);
        self::assertSame(42, $invoked->item);
    }

    public function testGhostOnPhp84DefersConstructionUntilPropertyRead(): void
    {
        if (\PHP_VERSION_ID < 80400) {
            self::markTestSkipped('native lazy objects require PHP 8.4');
        }

        $box = Generic::bind(GenericBox::class, Type::int())->ghost(42);
        $reflection = new \ReflectionClass($box);

        self::assertInstanceOf(GenericBox::class, $box);
        self::assertTrue($reflection->isUninitializedLazyObject($box));
        // A property read would run the constructor. The property stays lazy until then,
        // so constructorRan is still its default.
        self::assertTrue((new \ReflectionProperty(GenericBox::class, 'constructorRan'))->isLazy($box));

        $same = $box;
        self::assertSame(42, $box->item);
        self::assertSame($same, $box);
        self::assertFalse($reflection->isUninitializedLazyObject($box));
        self::assertTrue($box->constructorRan);
        self::assertSame('int', $box->typeAtConstruction);
    }

    public function testGhostBelowPhp84MatchesNew(): void
    {
        if (\PHP_VERSION_ID >= 80400) {
            self::markTestSkipped('the 8.4 build takes the native path');
        }

        $box = Generic::bind(GenericBox::class, Type::int())->ghost(42);

        self::assertInstanceOf(GenericBox::class, $box);
        self::assertTrue($box->constructorRan);
        self::assertSame(42, $box->item);
        self::assertSame('int', $box->typeAtConstruction);
    }

    public function testGhostBindsTypeArgumentsBeforeTheConstructor(): void
    {
        $box = Generic::bind(GenericBox::class, Type::int())->ghost(42);

        self::assertSame(42, $box->item);
        self::assertSame('int', $box->typeAtConstruction);
        self::assertTrue($box->constructorRan);
    }

    public function testGhostConstructorSeesTheObjectTheCallerHolds(): void
    {
        $box = Generic::bind(GenericBox::class, Type::int())->ghost(42);
        if (\PHP_VERSION_ID >= 80400) {
            self::assertTrue((new \ReflectionClass($box))->isUninitializedLazyObject($box));
        }

        self::assertSame(42, $box->item);
        self::assertSame(\spl_object_id($box), $box->thisAtConstruction);
    }

    /**
     * Each level receives only its own type arguments and chains to its parent, exactly as the eager
     * factory does. The log would differ if the leaf hook were called with every argument packed in.
     */
    public function testGhostRunsTheSameHookChainAsNew(): void
    {
        $eager = Generic::bind(GenericChainChild::class, Type::int())->new(7);
        $ghost = Generic::bind(GenericChainChild::class, Type::int())->ghost(7);

        if (\PHP_VERSION_ID >= 80400) {
            self::assertTrue((new \ReflectionClass($ghost))->isUninitializedLazyObject($ghost));
            self::assertTrue((new \ReflectionProperty(GenericChainParent::class, 'hookLog'))->isLazy($ghost));
        }

        self::assertSame(['Child:int', 'Parent:string'], $eager->hookLog);
        self::assertSame($eager->hookLog, $ghost->hookLog);
        self::assertSame('int', $ghost->childTypeAtConstruction);
        self::assertSame('string', $ghost->parentTypeAtConstruction);
        self::assertSame(7, $ghost->item);
        self::assertSame(\spl_object_id($ghost), $ghost->thisAtConstruction);
    }

    public function testGhostReachesAHookDeclaredOnTheParentClass(): void
    {
        $box = Generic::bind(GenericInheritedHookBox::class, Type::int())->ghost(9);
        if (\PHP_VERSION_ID >= 80400) {
            self::assertTrue((new \ReflectionClass($box))->isUninitializedLazyObject($box));
        }

        self::assertInstanceOf(GenericInheritedHookBox::class, $box);
        self::assertSame(9, $box->item);
        self::assertSame('int', $box->typeAtConstruction);
        self::assertSame(\spl_object_id($box), $box->thisAtConstruction);
    }

    public function testGhostOfAClassWithoutInstancePropertiesStillRunsTheConstructor(): void
    {
        $before = GenericStatelessErasedBox::$constructed;
        $box = Generic::bind(GenericStatelessErasedBox::class, Type::int())->ghost();

        self::assertInstanceOf(GenericStatelessErasedBox::class, $box);
        self::assertSame($before + 1, GenericStatelessErasedBox::$constructed);
    }

    public function testGhostOfAClassWithoutAConstructorBehavesLikeNew(): void
    {
        $box = Generic::bind(GenericBareErasedBox::class, Type::int())->ghost();

        self::assertInstanceOf(GenericBareErasedBox::class, $box);
        self::assertSame(5, $box->value);

        $this->expectException(\ReflectionException::class);
        Generic::bind(GenericBareErasedBox::class, Type::int())->ghost(1);
    }

    public function testGhostSharesTheOneShotGuardWithNew(): void
    {
        $binder = Generic::bind(GenericBox::class, Type::int());
        $binder->ghost(1);

        $this->expectException(\LogicException::class);
        $binder->new(2);
    }

    public function testCallableBindHasNoGhost(): void
    {
        $fn = Generic::bind((new GenericBoxWriteProbe())->write(...), Type::int());

        self::assertInstanceOf(\Closure::class, $fn);
        self::assertNotInstanceOf(Generic::class, $fn);
        self::assertFalse(\method_exists($fn, 'ghost'));
    }

    public function testEmittedGenericPhpMatchesThePhpVersionFloor(): void
    {
        self::assertVersionGateCheckerDistinguishesGuardedCalls();

        $php84 = self::readEmittedGeneric('804');
        self::assertStringContainsString('->newLazyGhost(', $php84);
        self::assertStringNotContainsString('PHP_VERSION_ID', $php84);

        // The 8.2 build ships both bodies. newLazyGhost is valid only in the
        // PHP_VERSION_ID >= 80400 branch; the older branch stays on invokeArgs.
        $php82 = self::readEmittedGeneric('802');
        self::assertStringContainsString('PHP_VERSION_ID >= 80400', $php82);
        self::assertStringContainsString('PHP_VERSION_ID < 80400', $php82);
        self::assertPhp84CallsAreVersionGated($php82, ['newLazyGhost']);
        self::assertStringContainsString('->invokeArgs(', $php82);
        self::assertStringContainsString('->invokeArgs(', $php84);
    }

    public function testAnUnboundParameterFallsBackToMixed(): void
    {
        $box = Generic::bind(GenericBox::class)(null);

        self::assertSame('mixed', $box->boundType());
    }

    public function testExplicitNullLeavesTheParameterUnbound(): void
    {
        $box = Generic::bind(GenericBox::class, null)(null);

        self::assertSame('mixed', $box->boundType());
    }

    public function testConstructingTwiceFails(): void
    {
        $factory = Generic::bind(GenericBox::class, Type::int());
        $factory(1);

        $this->expectException(\LogicException::class);
        $factory(2);
    }

    public function testBindDoesNotConstructUntilInvoke(): void
    {
        $factory = Generic::bind(GenericBox::class, Type::int());
        $box = $factory(7);
        self::assertTrue($box->constructorRan);
        self::assertSame(7, $box->item);
    }

    public function testANonGenericClassIsRejected(): void
    {
        $this->expectException(InvalidTypeException::class);
        Generic::bind(PlainBox::class, Type::int());
    }

    public function testErasedClassConstructsWithNew(): void
    {
        $box = Generic::bind(ErasedBox::class, Type::int())(42);

        self::assertInstanceOf(ErasedBox::class, $box);
        self::assertSame(42, $box->item);
    }

    public function testUnknownLayoutsThrow(): void
    {
        $this->expectException(InvalidTypeException::class);
        Generic::bind(FutureLayoutBox::class, Type::int());
    }

    /**
     * The instance is not materialised by `bind()`, so a class that cannot be reflected has to fail
     * there rather than surfacing later as a half-built object.
     */
    public function testAnUnknownClassIsRejected(): void
    {
        $this->expectException(\ReflectionException::class);
        Generic::bind('Tyhp\Tests\Core\NoSuchClassAnywhere', Type::int());
    }

    public function testEachCallProducesADistinctInstance(): void
    {
        $first = Generic::bind(GenericBox::class, Type::int())(1);
        $second = Generic::bind(GenericBox::class, Type::string())(2);

        self::assertNotSame($first, $second);
        self::assertSame('int', $first->boundType(), 'the cached reflection must not share bindings');
        self::assertSame('string', $second->boundType());
    }

    public function testBindRejectsAnonymousClosure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Generic::bind(function (): void {
        }, Type::int());
    }

    public function testBindRejectsNonStaticInstanceMethodWithoutReceiver(): void
    {
        $this->expectException(InvalidTypeException::class);
        Generic::bind(GenericBoxWriteProbe::class . '::write', Type::int());
    }

    public function testBindCallableReturnsBinderClosureUnchangedForByRef(): void
    {
        $holder = new GenericBoxWriteProbe();
        $fn = Generic::bind($holder->write(...), Type::int());
        $out = Type::string();
        $fn($out);

        self::assertSame('int', (string) $out);
    }

    public function testBindAcceptsListArrayCallableShape(): void
    {
        $holder = new GenericBoxWriteProbe();
        $fn = Generic::bind([$holder, 'write'], Type::int());
        $out = Type::string();
        $fn($out);

        self::assertSame('int', (string) $out);
    }

    public function testBindRejectsAssociativeTwoElementArrayAsCallable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Generic::bind([
            'receiver' => new GenericBoxWriteProbe(),
            'method' => 'write',
        ], Type::int());
    }

    public function testErasedCallablePreservesByRef(): void
    {
        $fn = Generic::bind(erased_write(...), Type::int());
        $out = 'before';
        $fn($out);
        self::assertSame('after', $out);
    }

    /**
     * Shaped like the emitter's output for a first-class callable of a generic method
     * whose parameter accepts an implicit conversion: the typed wrapper converts, then calls the target.
     */
    private function convertingWrapper(GenericBoxEchoProbe $probe): \Closure
    {
        return Generic::wrapCallable(
            $probe->echoType(...),
            static fn(\Closure $target): \Closure => static fn(int|string $n): string => $target((int) $n),
        );
    }

    public function testWrapCallableReturnsTheFactoryClosure(): void
    {
        $wrapper = $this->convertingWrapper(new GenericBoxEchoProbe());

        self::assertSame('mixed:7', $wrapper('7'));
        self::assertSame('mixed:7', $wrapper(7));
    }

    public function testBindOfWrappedCallableBindsTheTargetAndKeepsTheConversion(): void
    {
        $wrapper = $this->convertingWrapper(new GenericBoxEchoProbe());

        $bound = Generic::bind($wrapper, Type::int());

        self::assertSame('int:7', $bound('7'));
        self::assertSame('int:7', $bound(7));
        self::assertSame('mixed:7', $wrapper('7'), 'binding must not change the original wrapper');
    }

    public function testBindOfWrappedCallableAcceptsNullTypeArgument(): void
    {
        $bound = Generic::bind($this->convertingWrapper(new GenericBoxEchoProbe()), null);

        self::assertSame('mixed:3', $bound('3'));
    }

    public function testWrapCallableRejectsAFactoryThatDoesNotReturnAClosure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Generic::wrapCallable((new GenericBoxEchoProbe())->echoType(...), static fn(\Closure $target): int => 1);
    }

    public function testBindStillRejectsAnonymousClosureThatWasNotWrapped(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Generic::bind(static fn(int $n): string => (string) $n, Type::int());
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

    public function testGnrcAcceptsTypeBuiltInAndClassString(): void
    {
        $parameter = (new \ReflectionFunction('\_gnrc'))->getParameters()[1]->getType();
        $bindParameter = (new \ReflectionMethod(Generic::class, 'bind'))->getParameters()[1]->getType();
        self::assertNotNull($parameter);
        self::assertNotNull($bindParameter);
        self::assertSame((string) $bindParameter, (string) $parameter);

        $fromType = \_gnrc(GenericBox::class, Type::int())->new(1);
        self::assertSame(1, $fromType->item);
        self::assertSame('int', $fromType->boundType());

        $fromBuiltIn = \_gnrc(GenericBox::class, Type::int)->new(2);
        self::assertSame(2, $fromBuiltIn->item);
        self::assertSame('int', $fromBuiltIn->boundType());

        $fromClass = \_gnrc(GenericBox::class, \stdClass::class)->new(3);
        self::assertSame(3, $fromClass->item);
        self::assertSame('stdClass', $fromClass->boundType());
    }

    public function testBindLayout1CallableAcceptsTypeBuiltInAndClassString(): void
    {
        $holder = new GenericBoxEchoProbe();
        self::assertSame('int:1', Generic::bind($holder->echoType(...), Type::int())(1));
        self::assertSame('int:2', Generic::bind($holder->echoType(...), BuiltIn::int)(2));
        $fromClass = Generic::bind($holder->echoType(...), Type::class);
        self::assertInstanceOf(\Closure::class, $fromClass);
        $fromClass(3);
    }

    public function testBindLayout2CallableAcceptsTypeBuiltInAndClassString(): void
    {
        $holder = new Layout2EchoProbe();
        self::assertSame('int:1', Generic::bind($holder->echoType(...), Type::int())(1));
        self::assertSame('int:2', Generic::bind($holder->echoType(...), BuiltIn::int)(2));
        $fromClass = Generic::bind($holder->echoType(...), Type::class);
        self::assertInstanceOf(\Closure::class, $fromClass);
        $fromClass(3);

        $labeled = Generic::bind(layout2_label(...), Type::string());
        self::assertSame('string', $labeled());
        self::assertSame('string', Generic::bind(layout2_label(...), BuiltIn::string)());
        self::assertInstanceOf(\Closure::class, Generic::bind(layout2_label(...), \stdClass::class));
    }

    public function testStaticBinderCacheSharesTypeAndBuiltIn(): void
    {
        $target = ext_bind_label(...);
        ExtBindProbe::$builds = 0;
        $fromType = Generic::bind($target, Type::int());
        $fromBuiltIn = Generic::bind($target, BuiltIn::int);
        $again = Generic::bind($target, Type::int());

        self::assertSame('int', $fromType());
        self::assertSame('int', $fromBuiltIn());
        self::assertSame('int', $again());

        if (\Tyhp\ExtModule::available()) {
            self::assertSame($fromType, $fromBuiltIn);
            self::assertSame($fromType, $again);
            self::assertSame(1, ExtBindProbe::$builds);
        } else {
            self::assertNotSame($fromType, $fromBuiltIn);
            self::assertNotSame($fromType, $again);
            self::assertSame(3, ExtBindProbe::$builds);
        }
    }

    public function testInstanceBinderIsNotCached(): void
    {
        $left = new Layout2EchoProbe();
        $right = new Layout2EchoProbe();
        $fromLeft = Generic::bind($left->echoType(...), Type::int());
        $fromRight = Generic::bind($right->echoType(...), Type::int());

        self::assertNotSame($fromLeft, $fromRight);
        self::assertSame('int:1', $fromLeft(1));
        self::assertSame('int:2', $fromRight(2));
    }

    public function testClassBindIsNotCachedAndASecondNewThrows(): void
    {
        $spent = Generic::bind(GenericBox::class, Type::int());
        self::assertSame(1, $spent(1)->item);

        $fresh = Generic::bind(GenericBox::class, Type::int());
        self::assertNotSame($spent, $fresh);
        self::assertSame(2, $fresh(2)->item);

        $this->expectException(\LogicException::class);
        $spent(3);
    }

    public function testNullTypeArgumentInfersOnTheValueCall(): void
    {
        $fn = Generic::bind(ext_bind_infer(...), null);

        self::assertSame('int', $fn(42));
        self::assertSame('string', $fn('later'));
    }

    public function testBindUnknownTypeArgumentStillThrows(): void
    {
        $this->expectException(InvalidTypeException::class);
        Generic::bind(ext_bind_label(...), 'not-a-class');
    }

    private static function readEmittedGeneric(string $phpMajor): string
    {
        $path = \dirname(__DIR__, 3) . '/dist/tyhp-core/' . $phpMajor . '.latest/src/Tyhp/Generic.php';
        self::assertFileExists($path);
        $contents = \file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}

/** Mechanism D-shaped generic method returning its bound type, used by the wrapped-callable tests. */
class GenericBoxEchoProbe
{
    #[GenericRuntime(erased: false, binder: 'echoType__tyhpGeneric', layouts: [1])]
    public function echoType(int $n): string
    {
        return $this->echoType__tyhpGeneric(null)($n);
    }

    public function echoType__tyhpGeneric(?Type $__generic_T): \Closure
    {
        return static function (int $n) use ($__generic_T): string {
            return ((string) ($__generic_T ?? Type::mixed())) . ':' . $n;
        };
    }
}

/**
 * Mechanism D-shaped binder pair used by Generic::bind callable tests. The emitter stamps
 * `#[GenericRuntime]` on the generic *method* itself (not the class), so `Generic::bind()`
 * reflects `write`, not `GenericBoxWriteProbe`, to find it.
 */
class GenericBoxWriteProbe
{
    #[GenericRuntime(erased: false, binder: 'write__tyhpGeneric', layouts: [1])]
    public function write(Type &$out): void
    {
        $this->write__tyhpGeneric(null)($out);
    }

    public function write__tyhpGeneric(?Type $__generic_T): \Closure
    {
        return function (Type &$out) use ($__generic_T): void {
            $__generic_T ??= Type::mixed();
            $out = $__generic_T;
        };
    }
}

#[GenericRuntime(erased: true, layouts: [1])]
function erased_write(string &$out): void
{
    $out = 'after';
}

/** Layout 2 binder: the declared method is the binder. Every parameter is a type parameter. */
class Layout2EchoProbe
{
    #[GenericRuntime(erased: false, binder: 'echoType', layouts: [2])]
    public function echoType(TypeLike|string|null $T = null): \Closure
    {
        $T = Type::arg($T);
        return static function (int $n) use ($T): string {
            $T ??= Type::mixed();

            return ((string) $T) . ':' . $n;
        };
    }
}

#[GenericRuntime(erased: false, binder: 'layout2_label', layouts: [2])]
function layout2_label(TypeLike|string|null $T = null): \Closure
{
    $T = Type::arg($T);

    return static function () use ($T): string {
        $T ??= Type::mixed();

        return (string) $T;
    };
}

final class ExtBindProbe
{
    public static int $builds = 0;
}

#[GenericRuntime(erased: false, binder: 'ext_bind_label', layouts: [2])]
function ext_bind_label(TypeLike|string|null $T = null): \Closure
{
    ExtBindProbe::$builds++;
    $T = Type::arg($T);

    return static function () use ($T): string {
        $T ??= Type::mixed();

        return (string) $T;
    };
}

#[GenericRuntime(erased: false, binder: 'ext_bind_infer', layouts: [2])]
function ext_bind_infer(TypeLike|string|null $T = null): \Closure
{
    $T = Type::arg($T);

    return static function (mixed $value) use ($T): string {
        if ($T === null) {
            $inferred = null;
            if ($value !== null) {
                $inferred = Type::of($value);
            }
            $T = $inferred ?? Type::mixed();
        }

        return (string) $T;
    };
}
