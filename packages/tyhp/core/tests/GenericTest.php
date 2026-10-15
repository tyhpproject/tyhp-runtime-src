<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tyhp\Concerns\BootsTraits;
use Tyhp\Concerns\HasGenerics;
use Tyhp\Exceptions\InvalidTypeException;
use Tyhp\Generic;
use Tyhp\GenericRuntime;
use Tyhp\NamedType;
use Tyhp\Type;

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

    public function __construct(mixed $item)
    {
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
