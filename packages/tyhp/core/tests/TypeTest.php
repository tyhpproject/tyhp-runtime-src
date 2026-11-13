<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tyhp\BuiltIn;
use Tyhp\Exceptions\InvalidTypeException;
use Tyhp\ExtModule;
use Tyhp\GenericRuntime;
use Tyhp\NamedType;
use Tyhp\Type;
use Tyhp\TypeLike;

class TypeTest extends TestCase
{
    public function testScalarTypeSingletons(): void
    {
        $factories = [
            'string', 'int', 'float', 'bool', 'null', 'void',
            'mixed', 'never', 'array', 'object', 'callable',
            'iterable', 'resource',
        ];

        foreach ($factories as $factory) {
            $first = Type::$factory();
            $second = Type::$factory();
            self::assertSame($first, $second, "Type::$factory() should return the same singleton instance.");
        }
    }

    public function testTypeOf(): void
    {
        self::assertSame(Type::null(), Type::of(null));
        self::assertSame(Type::int(), Type::of(42));
        self::assertSame(Type::float(), Type::of(3.14));
        self::assertSame(Type::string(), Type::of('hello'));
        self::assertSame(Type::bool(), Type::of(true));
        self::assertSame(Type::array(), Type::of([1, 2, 3]));
        self::assertSame(Type::fromClassName(\stdClass::class), Type::of(new \stdClass()));

        $resource = \fopen('php://memory', 'r');
        try {
            self::assertNotNull(Type::of($resource));
        } finally {
            \fclose($resource);
        }
    }

    public function testTypeIs(): void
    {
        self::assertTrue(Type::is('hello', Type::string()));
        self::assertFalse(Type::is(42, Type::string()));

        self::assertTrue(Type::is(42, Type::int()));
        self::assertTrue(Type::is(true, Type::bool()));
        self::assertTrue(Type::is(null, Type::null()));
        self::assertTrue(Type::is(new \stdClass(), Type::object()));

        // float widening: int passes float check
        self::assertTrue(Type::is(42, Type::float()));
        self::assertTrue(Type::is(3.14, Type::float()));
        self::assertFalse(Type::is('nope', Type::float()));
    }

    public function testVoidAcceptsOnlyNull(): void
    {
        self::assertFalse(Type::void()->isNullable());
        self::assertNull(Type::void()->defaultValue());
        self::assertTrue(Type::is(null, Type::void()));
        self::assertTrue(Type::is(Type::void()->defaultValue(), Type::void()));

        self::assertFalse(Type::is(0, Type::void()));
        self::assertFalse(Type::is('', Type::void()));
        self::assertFalse(Type::is('x', Type::void()));
        self::assertFalse(Type::is(false, Type::void()));
        self::assertFalse(Type::is([], Type::void()));

        self::assertTrue(Type::is(null, Type::null()));
        self::assertFalse(Type::is(null, Type::never()));
        self::assertTrue(Type::is(null, Type::mixed()));

        $nullableInt = Type::nullable(Type::int());
        self::assertTrue(Type::is(null, $nullableInt));
        self::assertTrue(Type::is(0, $nullableInt));
        self::assertFalse(Type::is('', $nullableInt));
        self::assertFalse(Type::is('hello', $nullableInt));
    }

    public function testUnionType(): void
    {
        $union = Type::union(Type::int(), Type::string());

        self::assertTrue(Type::is(42, $union));
        self::assertTrue(Type::is('hello', $union));
        self::assertFalse(Type::is(true, $union));

        self::assertTrue(Type::compatible($union, Type::int()));
        self::assertTrue(Type::compatible($union, Type::string()));
        self::assertFalse(Type::compatible($union, Type::bool()));

        self::assertSame('int|string', (string) $union);
    }

    public function testUnionTypeFlattensNested(): void
    {
        $inner = Type::union(Type::int(), Type::string());
        $outer = Type::union($inner, Type::bool());

        self::assertSame('int|string|bool', (string) $outer);
        self::assertTrue(Type::is(42, $outer));
        self::assertTrue(Type::is('hello', $outer));
        self::assertTrue(Type::is(true, $outer));
    }

    public function testUnionTypeTooFewThrows(): void
    {
        $this->expectException(InvalidTypeException::class);
        Type::union(Type::int());
    }

    public function testIntersectionType(): void
    {
        $a = Type::fromClassName(\Countable::class);
        $b = Type::fromClassName(\Traversable::class);
        $intersection = Type::intersection($a, $b);

        self::assertSame('Countable&Traversable', (string) $intersection);

        $obj = new \ArrayObject();
        self::assertTrue(Type::is($obj, $intersection));

        $plain = new \stdClass();
        self::assertFalse(Type::is($plain, $intersection));

        self::assertTrue(Type::compatible($intersection, $intersection));
    }

    public function testIntersectionTypeTooFewThrows(): void
    {
        $this->expectException(InvalidTypeException::class);
        Type::intersection(Type::fromClassName(\Countable::class));
    }

    public function testNullableType(): void
    {
        $nullable = Type::nullable(Type::string());

        self::assertTrue(Type::is(null, $nullable));
        self::assertTrue(Type::is('hello', $nullable));
        self::assertFalse(Type::is(42, $nullable));
        self::assertTrue($nullable->isNullable());
        self::assertSame('?string', (string) $nullable);
    }

    public function testNullableOfNullableReturnsSame(): void
    {
        $nullable = Type::nullable(Type::string());
        $doubleNullable = Type::nullable($nullable);

        self::assertSame($nullable, $doubleNullable);
    }

    public function testNullableOfMixedReturnsMixed(): void
    {
        $result = Type::nullable(Type::mixed());

        self::assertSame(Type::mixed(), $result);
    }

    public function testGenericType(): void
    {
        $tParam = new NamedType('T', Type::int());
        $uParam = new NamedType('U', Type::string());
        $generic = Type::generic('Foo', $tParam, $uParam);

        self::assertSame('Foo<int, string>', (string) $generic);
        self::assertSame('generic', $generic->getKind());
        self::assertSame('Foo', $generic->getName());

        $foundT = $generic->genericParameter('T');
        self::assertNotNull($foundT);
        self::assertSame($tParam, $foundT);

        $foundU = $generic->genericParameter('U');
        self::assertNotNull($foundU);
        self::assertSame($uParam, $foundU);

        self::assertNull($generic->genericParameter('V'));
    }

    public function testCompatibilityMixedAcceptsAll(): void
    {
        self::assertTrue(Type::compatible(Type::mixed(), Type::int()));
        self::assertTrue(Type::compatible(Type::mixed(), Type::string()));
        self::assertTrue(Type::compatible(Type::mixed(), Type::bool()));
        self::assertTrue(Type::compatible(Type::mixed(), Type::null()));
        self::assertTrue(Type::compatible(Type::mixed(), Type::array()));
        self::assertTrue(Type::compatible(Type::mixed(), Type::object()));
    }

    public function testCompatibilityNeverFitsAll(): void
    {
        self::assertTrue(Type::compatible(Type::int(), Type::never()));
        self::assertTrue(Type::compatible(Type::string(), Type::never()));
        self::assertTrue(Type::compatible(Type::bool(), Type::never()));
        self::assertTrue(Type::compatible(Type::array(), Type::never()));
    }

    public function testCompatibilityUnionNarrow(): void
    {
        $union = Type::union(Type::int(), Type::string());

        self::assertTrue(Type::compatible($union, Type::int()));
        self::assertTrue(Type::compatible($union, Type::string()));
        self::assertFalse(Type::compatible($union, Type::bool()));
    }

    public function testCompatibilityStrictNoFloatWidening(): void
    {
        // Unlike Type::is(), compatible() does NOT allow int → float widening
        self::assertFalse(Type::compatible(Type::float(), Type::int()));
        self::assertTrue(Type::compatible(Type::float(), Type::float()));
    }

    public function testReadOnly(): void
    {
        $type = Type::int();
        self::assertFalse($type->isReadOnly());

        $ro = $type->asReadOnly();
        self::assertTrue($ro->isReadOnly());
        self::assertNotSame($type, $ro);
        self::assertSame('int', (string) $ro);

        // Already read-only returns same instance
        $roAgain = $ro->asReadOnly();
        self::assertSame($ro, $roAgain);
    }

    public function testNonNullable(): void
    {
        $nullable = Type::nullable(Type::string());
        self::assertTrue($nullable->isNullable());

        $nonNullable = $nullable->asNonNullable();
        self::assertFalse($nonNullable->isNullable());
        self::assertSame('string', (string) $nonNullable);

        // Already non-nullable returns same instance
        $plain = Type::string();
        self::assertSame($plain, $plain->asNonNullable());
    }

    public function testFromClassName(): void
    {
        $type = Type::fromClassName(\stdClass::class);

        self::assertSame('class', $type->getKind());
        self::assertSame('stdClass', $type->getName());

        $obj = new \stdClass();
        self::assertTrue(Type::is($obj, $type));
        self::assertFalse(Type::is('not an object', $type));

        // Singleton behavior
        self::assertSame($type, Type::fromClassName(\stdClass::class));
    }

    public function testToString(): void
    {
        self::assertSame('int', (string) Type::int());
        self::assertSame('?string', (string) Type::nullable(Type::string()));

        $tParam = new NamedType('T', Type::int());
        $uParam = new NamedType('U', Type::string());
        self::assertSame('Foo<int, string>', (string) Type::generic('Foo', $tParam, $uParam));

        self::assertSame('int|string', (string) Type::union(Type::int(), Type::string()));

        $a = Type::fromClassName('A');
        $b = Type::fromClassName('B');
        self::assertSame('A&B', (string) Type::intersection($a, $b));
    }

    public function testGetKind(): void
    {
        self::assertSame('scalar', Type::int()->getKind());
        self::assertSame('scalar', Type::string()->getKind());
        self::assertSame('scalar', Type::mixed()->getKind());
        self::assertSame('class', Type::fromClassName('Foo')->getKind());
        self::assertSame('union', Type::union(Type::int(), Type::string())->getKind());

        $a = Type::fromClassName('A');
        $b = Type::fromClassName('B');
        self::assertSame('intersection', Type::intersection($a, $b)->getKind());

        $named = new NamedType('T', Type::int());
        self::assertSame('generic', Type::generic('Box', $named)->getKind());
    }

    public function testStructTypeIs(): void
    {
        $user = Type::struct(
            'User',
            [
                'name' => Type::string(),
                'age' => Type::int(),
            ],
            ['name', 'age'],
        );

        self::assertSame('struct', $user->getKind());
        self::assertSame('User', $user->getName());
        self::assertTrue(Type::is(['name' => 'Ann', 'age' => 30], $user));
        self::assertTrue(Type::is(['name' => 'Ann', 'age' => 30, 'extra' => true], $user));
        self::assertFalse(Type::is(['name' => 'Ann'], $user));
        self::assertFalse(Type::is(['name' => 'Ann', 'age' => '30'], $user));
        self::assertFalse(Type::is(new \stdClass(), $user));
        self::assertSame([], $user->defaultValue());
    }

    public function testObjectShapeTypeIsExistenceOnly(): void
    {
        $clock = Type::objectShape('ClockShape', ['now'], []);

        self::assertSame('objectShape', $clock->getKind());
        self::assertSame('ClockShape', $clock->getName());

        $withNow = new class {
            public function now(): int
            {
                return 1;
            }
        };
        $withoutNow = new class {
            public function later(): int
            {
                return 1;
            }
        };

        $withExtra = new class {
            public function now(): int
            {
                return 1;
            }

            public function extra(): void
            {
            }
        };

        self::assertTrue(Type::is($withNow, $clock));
        self::assertTrue(Type::is($withExtra, $clock));
        self::assertFalse(Type::is($withoutNow, $clock));
        self::assertFalse(Type::is(['now' => 1], $clock));
        self::assertFalse(Type::is(null, $clock));
        self::assertNull($clock->defaultValue());
    }

    public function testObjectShapeTypeMatchesPublicProperties(): void
    {
        $hasTimezone = Type::objectShape('HasTimezone', [], ['timezone']);

        $withTimezone = new class {
            public string $timezone = 'UTC';
        };
        $withoutTimezone = new class {
            public string $name = 'x';
        };
        $privateTimezone = new class {
            private string $timezone = 'UTC';
        };

        self::assertTrue(Type::is($withTimezone, $hasTimezone));
        self::assertFalse(Type::is($withoutTimezone, $hasTimezone));
        self::assertFalse(Type::is($privateTimezone, $hasTimezone));
        self::assertFalse(Type::is(['timezone' => 'UTC'], $hasTimezone));
    }

    public function testCallableShapeTypeIsExistenceOnly(): void
    {
        $predicate = Type::callableShape('Predicate');

        self::assertSame('callableShape', $predicate->getKind());
        self::assertSame('Predicate', $predicate->getName());

        self::assertTrue(Type::is(static fn (int $n): bool => $n > 0, $predicate));
        self::assertTrue(Type::is('strlen', $predicate));
        self::assertFalse(Type::is(42, $predicate));
        self::assertFalse(Type::is(null, $predicate));
        self::assertFalse(Type::is(new \stdClass(), $predicate));
        self::assertNull($predicate->defaultValue());
    }

    public function testGetName(): void
    {
        self::assertSame('int', Type::int()->getName());
        self::assertSame('string', Type::string()->getName());
        self::assertSame('mixed', Type::mixed()->getName());
        self::assertSame('MyClass', Type::fromClassName('MyClass')->getName());

        // Composite types have null name
        self::assertNull(Type::union(Type::int(), Type::string())->getName());

        $a = Type::fromClassName('A');
        $b = Type::fromClassName('B');
        self::assertNull(Type::intersection($a, $b)->getName());
    }

    private static function promiseOf(Type $inner): Type
    {
        return Type::generic('Tyhp\\Promise', new NamedType('TReturn', $inner));
    }

    public function testAwaitedLeavesNonPromiseTypesUnchanged(): void
    {
        self::assertSame(Type::int(), Type::awaited(Type::int()));
        self::assertSame(Type::mixed(), Type::awaited(Type::mixed()));
        self::assertSame(Type::void(), Type::awaited(Type::void()));

        $user = Type::fromClassName('App\\User');
        self::assertSame($user, Type::awaited($user));
    }

    public function testAwaitedUnwrapsPromiseLayers(): void
    {
        $user = Type::fromClassName('App\\User');

        self::assertSame($user, Type::awaited(self::promiseOf($user)));
        self::assertSame($user, Type::awaited(self::promiseOf(self::promiseOf($user))));
        self::assertSame(Type::void(), Type::awaited(self::promiseOf(Type::void())));
    }

    public function testAwaitedOfABarePromiseIsMixed(): void
    {
        self::assertSame(Type::mixed(), Type::awaited(Type::fromClassName('Tyhp\\Promise')));
        self::assertSame(Type::mixed(), Type::awaited(Type::fromClassName('\\Tyhp\\Promise')));
    }

    public function testAwaitedIgnoresOtherClassesNamedLikeAPromise(): void
    {
        $other = Type::fromClassName('App\\Promise');

        self::assertSame($other, Type::awaited($other));
    }

    public function testAwaitedUnwrapsUnionsMemberWise(): void
    {
        $settled = Type::awaited(Type::union(self::promiseOf(Type::int()), Type::string()));

        self::assertSame('int|string', (string) $settled);
        self::assertSame(Type::int(), Type::awaited(Type::union(self::promiseOf(Type::int()), Type::int())));
        self::assertSame(
            Type::mixed(),
            Type::awaited(Type::union(Type::fromClassName('Tyhp\\Promise'), Type::int())),
        );
    }

    public function testAwaitedKeepsNullability(): void
    {
        $settled = Type::awaited(Type::nullable(self::promiseOf(Type::int())));

        self::assertTrue(Type::is(null, $settled));
        self::assertTrue(Type::is(3, $settled));
        self::assertFalse(Type::is('x', $settled));
    }

    public function testAwaitedSeesThroughNamedTypes(): void
    {
        self::assertSame(
            Type::int(),
            Type::awaited(new NamedType('T', self::promiseOf(Type::int()))),
        );
    }

    public function testScalarFactoriesAreImmutable(): void
    {
        foreach ([
            Type::string(), Type::int(), Type::float(), Type::bool(), Type::null(),
            Type::void(), Type::mixed(), Type::never(), Type::array(), Type::object(),
            Type::callable(), Type::iterable(), Type::resource(),
            Type::fromClassName(\stdClass::class),
        ] as $type) {
            self::assertTrue($type->isImmutable());
            self::assertSame($type, $type->immutable());
        }
    }

    public function testNameCopiesAnImmutableSingleton(): void
    {
        $named = Type::string()->name('UserId');

        self::assertNotSame(Type::string(), $named);
        self::assertFalse($named->isImmutable());
        self::assertSame('UserId', $named->getName());
        self::assertSame('string', Type::string()->getName());
        self::assertTrue(Type::string()->isImmutable());
        self::assertTrue(Type::is('hi', $named));
        self::assertTrue(Type::is('hi', Type::string()));
    }

    public function testImmutableStructFieldCopies(): void
    {
        $struct = Type::struct()->field('a', Type::int());
        self::assertSame($struct, $struct->field('a', Type::int()));
        self::assertFalse($struct->isImmutable());

        self::assertSame($struct, $struct->immutable());
        self::assertTrue($struct->isImmutable());

        $copy = $struct->field('b', Type::string());
        self::assertNotSame($struct, $copy);
        self::assertFalse($copy->isImmutable());
        self::assertTrue($struct->isImmutable());
        self::assertTrue(Type::is(['a' => 1], $struct));
        self::assertFalse(Type::is(['a' => 1], $copy));
        self::assertTrue(Type::is(['a' => 1, 'b' => 'x'], $copy));
    }

    public function testModifyInPlaceThrowsWhenImmutable(): void
    {
        $method = new \ReflectionMethod(Type::class, 'modifyInPlace');

        $this->expectException(InvalidTypeException::class);
        $method->invoke(Type::string());
    }

    public function testRegisterDoesNotRunTheClosureUntilRef(): void
    {
        $ran = false;
        Type::register('DeferredRun', function () use (&$ran): Type {
            $ran = true;

            return Type::int();
        });

        self::assertFalse($ran);
        $type = Type::ref('DeferredRun');
        self::assertTrue($ran);
        self::assertSame('DeferredRun', $type->getName());
        self::assertTrue($type->isImmutable());
        self::assertTrue(Type::is(1, $type));
        self::assertSame($type, Type::ref('DeferredRun'));
    }

    public function testRegisteringTheSameNameTwiceThrowsAndKeepsTheFirstFactory(): void
    {
        Type::register('DupRegister', fn (): Type => Type::int());

        $secondRan = false;
        try {
            Type::register('DupRegister', function () use (&$secondRan): Type {
                $secondRan = true;

                return Type::string();
            });
            self::fail('A second register of the same name must throw.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(InvalidTypeException::class, $e);
            self::assertSame(
                'Type declaration "DupRegister" is already registered.',
                $e->getMessage(),
            );
        }

        self::assertFalse($secondRan);
        $type = Type::ref('DupRegister');
        self::assertSame('DupRegister', $type->getName());
        self::assertTrue(Type::is(1, $type));
        self::assertFalse(Type::is('kept', $type));
        self::assertFalse($secondRan);
    }

    public function testRefOfStringAliasDoesNotRenameTheSingleton(): void
    {
        Type::register('UserIdAlias', fn () => Type::string());
        $named = Type::ref('UserIdAlias');

        self::assertSame('string', Type::string()->getName());
        self::assertTrue(Type::string()->isImmutable());
        self::assertNotSame(Type::string(), $named);
        self::assertSame('UserIdAlias', $named->getName());
        self::assertTrue($named->isImmutable());
        self::assertTrue(Type::is('hi', $named));
        self::assertSame($named, Type::ref('UserIdAlias'));
    }

    public function testUnknownRefThrows(): void
    {
        $this->expectException(InvalidTypeException::class);
        Type::ref('NoSuchDeclaration');
    }

    public function testRefCachesByNameAndGenericStructure(): void
    {
        Type::register('BoxCache', fn (Type $T) => Type::struct()->field('value', $T));

        $strings = Type::ref('BoxCache', Type::string());
        $stringsAgain = Type::ref('BoxCache', Type::string());
        $list = Type::ref('BoxCache', Type::listOf(Type::string()));
        $listAgain = Type::ref('BoxCache', Type::listOf(Type::string()));
        $ints = Type::ref('BoxCache', Type::listOf(Type::int()));

        self::assertSame($strings, $stringsAgain);
        self::assertSame($list, $listAgain);
        self::assertNotSame($list, $ints);
        self::assertNotSame($strings, $list);
        self::assertTrue(Type::is(['value' => 'a'], $strings));
        self::assertTrue(Type::is(['value' => ['a']], $list));
        self::assertFalse(Type::is(['value' => [1]], $list));
    }

    public function testRecursiveRefUsesAPlaceholder(): void
    {
        Type::register('NodeRec', fn () => Type::struct()
            ->field('value', Type::int())
            ->optional('next', Type::ref('NodeRec')->nullable()));

        $node = Type::ref('NodeRec');
        self::assertTrue(Type::is(['value' => 1], $node));
        self::assertTrue(Type::is(['value' => 1, 'next' => null], $node));
        self::assertTrue(Type::is(['value' => 1, 'next' => ['value' => 2]], $node));
        self::assertFalse(Type::is(['next' => ['value' => 2]], $node));
        self::assertSame($node, Type::ref('NodeRec'));
    }

    public function testDeclarationsMayRegisterInAnyOrder(): void
    {
        Type::register('OrderRight', fn () => Type::struct()->field('left', Type::ref('OrderLeft')));
        Type::register('OrderLeft', fn () => Type::struct()->field('n', Type::int()));

        $right = Type::ref('OrderRight');
        self::assertTrue(Type::is(['left' => ['n' => 3]], $right));
        self::assertFalse(Type::is(['left' => []], $right));
    }

    public function testFieldOptionalAndNullable(): void
    {
        $shape = Type::struct()
            ->field('name', Type::string())
            ->optional('age', Type::int())
            ->optional('nick', Type::string()->nullable());

        self::assertTrue(Type::is(['name' => 'a'], $shape));
        self::assertFalse(Type::is([], $shape));
        self::assertTrue(Type::is(['name' => 'a', 'age' => 1], $shape));
        self::assertFalse(Type::is(['name' => 'a', 'age' => null], $shape));
        self::assertTrue(Type::is(['name' => 'a', 'nick' => null], $shape));
        self::assertTrue(Type::is(['name' => 'a', 'nick' => 'n'], $shape));
        self::assertFalse(Type::string()->isNullable());
    }

    public function testListOfAndMapOf(): void
    {
        self::assertTrue(Type::is(['a', 'b'], Type::listOf(Type::string())));
        self::assertFalse(Type::is(['a', 1], Type::listOf(Type::string())));
        self::assertTrue(Type::is(['a' => 1], Type::mapOf(Type::string(), Type::int())));
        self::assertFalse(Type::is(['a' => 'b'], Type::mapOf(Type::string(), Type::int())));
    }

    public function testUnionIntersectionShapeGenericAndClosure(): void
    {
        $union = Type::union()->int()->string();
        self::assertTrue(Type::is(1, $union));
        self::assertTrue(Type::is('a', $union));
        self::assertFalse(Type::is(1.5, $union));

        $both = Type::intersection()->class(\ArrayAccess::class)->class(\Countable::class);
        self::assertTrue(Type::is(new \ArrayObject(), $both));
        self::assertFalse(Type::is(new \stdClass(), $both));

        $shape = Type::shape()->method('now')->property('tz');
        $clock = new class {
            public string $tz = 'UTC';

            public function now(): int
            {
                return 1;
            }
        };
        self::assertTrue(Type::is($clock, $shape));
        self::assertFalse(Type::is(new \stdClass(), $shape));

        $generic = Type::generic(\ArrayObject::class)->param('TValue', Type::string());
        self::assertSame('ArrayObject<string>', (string) $generic);

        $closure = Type::closure();
        self::assertFalse($closure->isNullable());
        $nullable = $closure->nullable();
        self::assertTrue($nullable->isNullable());
        self::assertSame($closure, $nullable);
        self::assertFalse(Type::closure()->isNullable());
    }

    public function testObjectShapeMethodReturnType(): void
    {
        $base = Type::shape()->immutable();
        $clock = $base->method('now')->returns(Type::fromClassName(\DateTimeImmutable::class));
        $nameOnly = Type::shape()->method('now');

        $matching = new class {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2020-01-02T03:04:05+00:00');
            }
        };
        $stringReturn = new class {
            public function now(): string
            {
                return 'now';
            }
        };
        $extraRequired = new class {
            public function now(string $zone): \DateTimeImmutable
            {
                return new \DateTimeImmutable($zone);
            }
        };
        $extraOptional = new class {
            public function now(string $zone = 'UTC'): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2020-01-02T03:04:05+00:00');
            }
        };

        self::assertTrue(Type::is($matching, $clock));
        self::assertFalse(Type::is($stringReturn, $clock));
        self::assertFalse(Type::is($extraRequired, $clock));
        self::assertTrue(Type::is($extraOptional, $clock));
        self::assertTrue(Type::is($stringReturn, $nameOnly));
        self::assertTrue(Type::is($stringReturn, $base));
        self::assertTrue($base->isImmutable());
        self::assertFalse($clock->isImmutable());
        self::assertSame(Type::string(), Type::string());
    }

    public function testObjectShapeNullableAndUnionReturns(): void
    {
        $nullable = Type::shape()->method('now')->returns(
            Type::nullable(Type::fromClassName(\DateTimeImmutable::class)),
        );
        $strict = Type::shape()->method('now')->returns(Type::fromClassName(\DateTimeImmutable::class));
        $union = Type::shape()->method('id')->returns(Type::union(Type::string(), Type::int()));
        $asFloat = Type::shape()->method('n')->returns(Type::float());

        $plain = new class {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2020-01-02T03:04:05+00:00');
            }
        };
        $nullish = new class {
            public function now(): ?\DateTimeImmutable
            {
                return null;
            }
        };
        $intId = new class {
            public function id(): int
            {
                return 1;
            }
        };
        $boolId = new class {
            public function id(): bool
            {
                return true;
            }
        };
        $both = new class {
            public function id(): string|int
            {
                return 1;
            }
        };
        $ints = new class {
            public function n(): int
            {
                return 1;
            }
        };
        $subtype = new class {
            public function fail(): \RuntimeException
            {
                return new \RuntimeException('x');
            }
        };
        $supertype = new class {
            public function fail(): \Exception
            {
                return new \Exception('x');
            }
        };
        $exceptionShape = Type::shape()->method('fail')->returns(Type::fromClassName(\Exception::class));
        $runtimeShape = Type::shape()->method('fail')->returns(Type::fromClassName(\RuntimeException::class));

        self::assertTrue(Type::is($plain, $nullable));
        self::assertTrue(Type::is($nullish, $nullable));
        self::assertTrue(Type::is($plain, $strict));
        self::assertFalse(Type::is($nullish, $strict));
        self::assertTrue(Type::is($intId, $union));
        self::assertTrue(Type::is($both, $union));
        self::assertFalse(Type::is($boolId, $union));
        self::assertTrue(Type::is($ints, $asFloat));
        self::assertTrue(Type::is($subtype, $exceptionShape));
        self::assertFalse(Type::is($supertype, $runtimeShape));
    }

    public function testObjectShapeMethodParameters(): void
    {
        $shape = Type::shape()
            ->method('format')
            ->param('instant', Type::fromClassName(\DateTimeImmutable::class))
            ->returns(Type::string());
        $optionalZone = Type::shape()
            ->method('format')
            ->param('instant', Type::fromClassName(\DateTimeImmutable::class))
            ->param('zone', Type::string(), optional: true)
            ->returns(Type::string());
        $variadic = Type::shape()
            ->method('join')
            ->param('parts', Type::string(), variadic: true)
            ->returns(Type::string());

        $matching = new class {
            public function format(\DateTimeImmutable $instant): string
            {
                return $instant->format('c');
            }
        };
        $wider = new class {
            public function format(object $instant): string
            {
                return 'x';
            }
        };
        $narrower = new class {
            public function format(string $instant): string
            {
                return $instant;
            }
        };
        $parentParam = new class {
            public function format(\Exception $instant): string
            {
                return $instant->getMessage();
            }
        };
        $childParam = new class {
            public function format(\RuntimeException $instant): string
            {
                return $instant->getMessage();
            }
        };
        $zoneRequired = new class {
            public function format(\DateTimeImmutable $instant, string $zone): string
            {
                return $zone;
            }
        };
        $zoneOptional = new class {
            public function format(\DateTimeImmutable $instant, string $zone = 'UTC'): string
            {
                return $zone;
            }
        };
        $join = new class {
            public function join(string ...$parts): string
            {
                return \implode('', $parts);
            }
        };
        $notVariadic = new class {
            public function join(string $parts): string
            {
                return $parts;
            }
        };
        $exceptionShape = Type::shape()
            ->method('format')
            ->param('instant', Type::fromClassName(\RuntimeException::class))
            ->returns(Type::string());
        $broadExceptionShape = Type::shape()
            ->method('format')
            ->param('instant', Type::fromClassName(\Exception::class))
            ->returns(Type::string());

        self::assertTrue(Type::is($matching, $shape));
        self::assertTrue(Type::is($wider, $shape));
        self::assertFalse(Type::is($narrower, $shape));
        self::assertTrue(Type::is($parentParam, $exceptionShape));
        self::assertFalse(Type::is($childParam, $broadExceptionShape));
        self::assertFalse(Type::is($zoneRequired, $optionalZone));
        self::assertTrue(Type::is($zoneOptional, $optionalZone));
        self::assertTrue(Type::is($join, $variadic));
        self::assertFalse(Type::is($notVariadic, $variadic));
        self::assertSame(Type::string(), Type::string());
    }

    public function testTypeArgNormalizesTypeLikeAndClassStrings(): void
    {
        self::assertNull(Type::arg(null));
        $type = Type::string();
        self::assertSame($type, Type::arg($type));
        self::assertSame(Type::int(), Type::arg(BuiltIn::int));
        self::assertSame(Type::int(), Type::arg(Type::int));
        self::assertSame(Type::fromClassName(\stdClass::class), Type::arg(\stdClass::class));
        self::assertSame(Type::fromClassName(TypeLike::class), Type::arg(TypeLike::class));
        self::assertSame(Type::fromClassName(BuiltIn::class), Type::arg(BuiltIn::class));

        try {
            Type::arg('array<int>');
            self::fail('A type spelling must not be accepted by Type::arg.');
        } catch (InvalidTypeException $e) {
            self::assertStringContainsString('Type::parse', $e->getMessage());
        }
    }

    public function testNullableStaysMagicAndOptionalHasBothForms(): void
    {
        $reflection = new \ReflectionClass(Type::class);
        self::assertFalse($reflection->hasMethod('nullable'));
        self::assertFalse($reflection->hasMethod('optional'));
        self::assertTrue($reflection->hasMethod('or'));
        self::assertTrue($reflection->hasMethod('toType'));
        self::assertSame('?int', (string) Type::nullable(Type::int()));
        self::assertSame('?int', (string) Type::int()->nullable());
        self::assertSame('?string', (string) Type::nullable(Type::string));

        $fluent = Type::struct()->optional('name', Type::string);
        self::assertTrue(Type::is([], $fluent));
        self::assertTrue(Type::is(['name' => 'Ada'], $fluent));
        self::assertFalse(Type::is(['name' => 1], $fluent));
    }

    public function testStructFieldMapRequiresKeysUnlessOptional(): void
    {
        $shape = Type::struct([
            'id' => Type::int,
            'name' => Type::optional(Type::string),
        ]);

        self::assertTrue(Type::is(['id' => 1], $shape));
        self::assertTrue(Type::is(['id' => 1, 'name' => 'Ada'], $shape));
        self::assertFalse(Type::is(['name' => 'Ada'], $shape));
        self::assertFalse(Type::is([], $shape));
        self::assertFalse(Type::is(['id' => '1'], $shape));

        $named = Type::struct(name: 'App\\UserData', fields: ['id' => Type::int]);
        self::assertSame('App\\UserData', $named->getName());
        self::assertFalse(Type::is([], $named));
        self::assertTrue(Type::is(['id' => 4], $named));

        $twoArgument = Type::struct('User', ['name' => Type::string()]);
        self::assertFalse(Type::is([], $twoArgument));
        self::assertTrue(Type::is(['name' => 'Ada'], $twoArgument));

        $threeArgument = Type::struct(
            'User',
            ['name' => Type::string(), 'age' => Type::int()],
            ['name'],
        );
        self::assertTrue(Type::is(['name' => 'Ada'], $threeArgument));
        self::assertFalse(Type::is(['age' => 1], $threeArgument));
        self::assertTrue(Type::is(['name' => 'Ada', 'age' => 3], $threeArgument));

        $explicitlyNoneRequired = Type::struct('User', ['name' => Type::string()], []);
        self::assertTrue(Type::is([], $explicitlyNoneRequired));
        self::assertTrue(Type::is(['name' => 'Ada'], $explicitlyNoneRequired));
        self::assertFalse(Type::is(['name' => 1], $explicitlyNoneRequired));
    }

    public function testListAndMapFactories(): void
    {
        self::assertSame((string) Type::listOf(Type::int()), (string) Type::list(Type::int));
        self::assertSame(
            (string) Type::mapOf(Type::string(), Type::int()),
            (string) Type::map(Type::string, Type::int),
        );
        self::assertSame('int|string', (string) Type::int->or(Type::string));
        self::assertSame(Type::int(), Type::int->toType());
        self::assertSame(Type::string(), Type::string->toType());
    }

    public function testOrOfTheSameTypeIsThatType(): void
    {
        self::assertSame(Type::int(), Type::int()->or(Type::int()));
        self::assertSame('array<int>', (string) Type::list(Type::int)->or(Type::list(Type::int)));
        self::assertSame('int|string', (string) Type::int()->or(Type::string(), Type::int(), Type::string()));
    }

    public function testGenericArgumentsByPositionNameAndNamedType(): void
    {
        $positional = Type::generic(Story49Box::class, Type::int());
        $named = Type::generic(Story49Box::class, T: Type::int());
        $explicit = Type::generic(Story49Box::class, new NamedType('T', Type::int()));
        $customName = Type::generic(Story49Box::class, new NamedType('Custom', Type::int()));

        self::assertSame('T', $positional->genericParameter('T')?->getParameterName());
        self::assertSame('T', $named->genericParameter('T')?->getParameterName());
        self::assertSame('T', $explicit->genericParameter('T')?->getParameterName());
        self::assertSame('Custom', $customName->genericParameter('Custom')?->getParameterName());
        self::assertNull($customName->genericParameter('T'));
        self::assertSame('int', (string) $positional->typeArgument(0));
        self::assertSame('int', (string) $named->typeArgument('T'));

        $mixed = Type::generic(Story49Pair::class, Type::string(), TValue: Type::int());
        self::assertSame('string', (string) $mixed->typeArgument('TKey'));
        self::assertSame('int', (string) $mixed->typeArgument('TValue'));
        self::assertSame('string', (string) $mixed->typeArgument(0));

        $plain = Type::generic(\stdClass::class, Type::int());
        self::assertSame('stdClass<int>', (string) $plain);
        self::assertSame('T0', $plain->genericParameter('T0')?->getParameterName());

        self::assertSame((string) Type::list(Type::int()), (string) Type::generic('array', Type::int()));
        self::assertSame(
            (string) Type::map(Type::string(), Type::int()),
            (string) Type::generic('array', TKey: Type::string(), TValue: Type::int()),
        );
        self::assertSame('TValue', Type::generic('iterable', Type::int())->genericParameter('TValue')?->getParameterName());
    }

    public function testGenericBuilderFormStillAppendsParameters(): void
    {
        // The compiler emits `Type::generic(X::class)->param('T', $t)` for type-parameter lookups.
        $builder = Type::generic(Story49Box::class)->param('T', Type::int());
        self::assertSame('int', (string) $builder->typeArgument('T'));
        self::assertTrue(Type::compatible($builder, Type::generic(Story49Box::class, Type::int())));

        $pair = Type::generic(Story49Pair::class)
            ->param('TKey', Type::string())
            ->param('TValue', Type::int());
        self::assertTrue(Type::compatible($pair, Type::generic(Story49Pair::class, Type::string(), Type::int())));

        foreach (['array', 'iterable'] as $name) {
            $viaBuilder = Type::generic($name)->param('TValue', Type::int());
            self::assertSame('generic', $viaBuilder->getKind());
            self::assertSame($name . '<int>', (string) $viaBuilder);
            self::assertTrue(Type::compatible(Type::parse($name . '<int>'), $viaBuilder));
        }
    }

    public function testGenericRejectsBadArguments(): void
    {
        try {
            Type::generic(Story49Box::class, Type::int(), Type::string());
            self::fail('Too many type arguments must throw.');
        } catch (InvalidTypeException $e) {
            self::assertStringContainsString('expects', $e->getMessage());
        }

        try {
            Type::generic(Story49Pair::class, Type::int());
            self::fail('Too few type arguments must throw.');
        } catch (InvalidTypeException $e) {
            self::assertStringContainsString('expects', $e->getMessage());
        }

        try {
            Type::generic(Story49Box::class, Nope: Type::int());
            self::fail('An unknown type parameter name must throw.');
        } catch (InvalidTypeException $e) {
            self::assertStringContainsString('Unknown type parameter', $e->getMessage());
        }

        try {
            Type::generic(Story49Pair::class, Type::string(), TKey: Type::int());
            self::fail('A named argument must not fill a positional slot.');
        } catch (InvalidTypeException $e) {
            self::assertStringContainsString('already filled', $e->getMessage());
        }

        try {
            Type::generic(\stdClass::class, T: Type::int());
            self::fail('A plain PHP class accepts positional arguments only.');
        } catch (InvalidTypeException $e) {
            self::assertStringContainsString('position', $e->getMessage());
        }
    }

    public function testGenericSpellingsAreTheSameType(): void
    {
        $positional = Type::generic(Story49Box::class, Type::int());
        $named = Type::generic(Story49Box::class, T: Type::int());
        $explicit = Type::generic(Story49Box::class, new NamedType('T', Type::int()));
        $parsed = Type::parse(Story49Box::class . '<int>');

        self::assertTrue(Type::compatible($positional, $named));
        self::assertTrue(Type::compatible($named, $explicit));
        self::assertTrue(Type::compatible($explicit, $parsed));
        self::assertTrue(Type::compatible($parsed, $positional));
        self::assertTrue(Type::compatible($positional, Type::generic(Story49Box::class, new NamedType('Custom', Type::int()))));

        $box = new Story49Box();
        $box->__tyhpGeneric = new Story49GenericBag($parsed);
        self::assertTrue(Type::is($box, $positional));
        self::assertTrue(Type::is($box, $named));
        self::assertTrue(Type::is($box, $explicit));
        self::assertTrue(Type::is($box, $parsed));

        Type::register('Story49Holder', static fn (Type $T): Type => Type::struct()->field('v', $T));
        $first = Type::ref('Story49Holder', $positional);
        self::assertSame($first, Type::ref('Story49Holder', $named));
        self::assertSame($first, Type::ref('Story49Holder', $explicit));
        self::assertSame($first, Type::ref('Story49Holder', $parsed));
        self::assertNotSame($first, Type::ref('Story49Holder', Type::generic(Story49Box::class, Type::string())));
    }

    public function testParseDuplicateUnionIsRejected(): void
    {
        try {
            Type::parse('int|int');
            self::fail('Expected InvalidTypeException.');
        } catch (InvalidTypeException $e) {
            self::assertSame('Union type requires at least 2 member types.', $e->getMessage());
        }

        self::assertSame('int|int|null', (string) Type::parse('?int|int'));
        self::assertSame('int|int|null', (string) Type::parse('?int|?int'));
        self::assertSame('int|string', (string) Type::parse('int|string|int'));
    }

    public function testParseDeepSpelling(): void
    {
        self::assertNestedFoo(Type::parse(self::nestedFooSpelling(3)), 3);
        self::assertNestedFoo(Type::parse(self::nestedFooSpelling(128)), 128);
        self::assertNestedFoo(Type::parse(self::nestedFooSpelling(129)), 129);
    }

    public function testIsObjectShapeMatchesWhenTheNativeWalkDeclines(): void
    {
        $shape = Type::shape()->method('now');
        $clock = new class {
            public function now(): int
            {
                return 1;
            }
        };

        if (ExtModule::available()) {
            self::assertNull(\Tyhp\Ext::typeIs($shape, $clock));
        }

        self::assertTrue(Type::is($clock, $shape));
        self::assertFalse(Type::is(new \stdClass(), $shape));
    }

    public function testIsCallsTheExtensionOnlyWhenItIsAvailable(): void
    {
        $probe = new TypeWalkProbe([1, 2]);

        self::assertTrue(Type::is($probe, Type::parse('iterable<int>')));
        self::assertNotSame([], $probe->frames);
        self::assertSame(ExtModule::available(), \in_array('Tyhp\\Ext::typeIs', $probe->frames, true));
        self::assertContains('Tyhp\\Type::is', $probe->frames);
        self::assertFalse(Type::is(new TypeWalkProbe([1, 'two']), Type::parse('iterable<int>')));
    }

    public function testIsDoesNotSwallowAnExceptionFromTheWalk(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('walk failed');

        Type::is(new TypeWalkProbe([1], failWith: new \RuntimeException('walk failed')), Type::parse('iterable<int>'));
    }

    public function testParseTakesTheExtensionGraphOnlyWhenItIsAvailable(): void
    {
        $frames = [];
        Type::register('TypeParseProbeAlias', static function () use (&$frames): Type {
            foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $frames[] = ($frame['class'] ?? '') . '::' . $frame['function'];
            }

            return Type::int();
        });

        $parsed = Type::parse('TypeParseProbeAlias');

        self::assertTrue(Type::is(5, $parsed));
        self::assertFalse(Type::is('5', $parsed));
        self::assertSame(ExtModule::available(), \in_array('Tyhp\\Type::typeFromExtGraph', $frames, true));
        self::assertSame(!ExtModule::available(), \in_array('Tyhp\\Type::parseNamedSpelling', $frames, true));
    }

    public function testParseWithTheExtensionBuildsWhatThePhpParserBuilds(): void
    {
        if (!ExtModule::available()) {
            self::markTestSkipped('The tyhp extension is not available.');
        }

        Type::register('TypeParseParityAlias', static fn (Type $T): Type => Type::list($T));
        $spellings = [
            'int', '?int', 'null', 'mixed', '?mixed', 'true', 'decimal', 'int|string', '?int|string', 'int|null',
            'string|int|null', '(int|string)|null', '\\Countable&\\Traversable', 'array', 'iterable', 'array<int>',
            'array<string, ?int>', 'iterable<int>', 'iterable<string, App\\Post>', '?\\stdClass', 'App\\Post',
            'App\\Table<App\\Blog>', 'App\\Table<int, string|null>', 'struct App\\Address', 'object App\\Clock',
            'callable App\\Handler', 'TypeParseParityAlias<int>', 'array<TypeParseParityAlias<string>>',
            'struct TypeParseParityAlias<int>',
        ];

        foreach ($spellings as $spelling) {
            $graph = \Tyhp\Ext::typeParse($spelling);
            self::assertIsArray($graph, $spelling);

            $fromGraph = self::callPrivate('typeFromExtGraph', $graph);
            $fromParser = self::parseWithPhpParser($spelling);

            self::assertInstanceOf(Type::class, $fromGraph, $spelling);
            self::assertSame((string) $fromParser, (string) $fromGraph, $spelling);
            self::assertSame($fromParser->getKind(), $fromGraph->getKind(), $spelling);
            self::assertTrue(Type::compatible($fromParser, $fromGraph), $spelling);
            self::assertTrue(Type::compatible($fromGraph, $fromParser), $spelling);
            self::assertSame((string) $fromParser, (string) Type::parse($spelling), $spelling);
        }
    }

    public function testParseSpellingsOnlyThePhpParserAcceptsStillThrow(): void
    {
        if (ExtModule::available()) {
            self::assertNull(\Tyhp\Ext::typeParse('array<int, int, int>'));
            self::assertNull(\Tyhp\Ext::typeParse('int !'));
        }

        foreach (['array<int, int, int>', 'int !', 'int<string>', '', '?'] as $spelling) {
            try {
                Type::parse($spelling);
                self::fail('Expected InvalidTypeException for "' . $spelling . '".');
            } catch (InvalidTypeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testParseDoesNotHideAnAliasFactoryFailure(): void
    {
        $calls = 0;
        Type::register('TypeParseFailingAlias', static function () use (&$calls): Type {
            $calls++;
            throw new InvalidTypeException('alias factory failed');
        });

        try {
            Type::parse('TypeParseFailingAlias');
            self::fail('The alias factory failure must reach the caller.');
        } catch (InvalidTypeException $e) {
            self::assertSame('alias factory failed', $e->getMessage());
        }

        self::assertSame(1, $calls);
    }

    /**
     * @param array<string, mixed> $graph
     */
    private static function callPrivate(string $method, array $graph): mixed
    {
        return (new \ReflectionMethod(Type::class, $method))->invoke(null, $graph);
    }

    private static function parseWithPhpParser(string $spelling): Type
    {
        $tokens = (new \ReflectionMethod(Type::class, 'tokenizeSpelling'))->invoke(null, $spelling);
        $position = 0;
        $args = [$tokens, &$position, $spelling];
        $parsed = (new \ReflectionMethod(Type::class, 'parseUnionSpelling'))->invokeArgs(null, $args);
        self::assertInstanceOf(Type::class, $parsed);

        return $parsed;
    }

    private static function nestedFooSpelling(int $depth): string
    {
        $spelling = 'int';
        for ($i = 0; $i < $depth; $i++) {
            $spelling = 'Foo<' . $spelling . '>';
        }

        return $spelling;
    }

    private static function assertNestedFoo(Type $type, int $depth): void
    {
        $current = $type;
        for ($i = 0; $i < $depth; $i++) {
            self::assertSame('Foo', $current->getName());
            $next = $current->typeArgument(0);
            self::assertInstanceOf(Type::class, $next);
            $current = $next;
        }

        self::assertSame(Type::int(), $current);
    }

    public function testDefaultValueCallsClassDeclaredTyhpDefault(): void
    {
        $before = DefaultValueHookParent::$calls;
        $first = Type::fromClassName(DefaultValueHookParent::class)->defaultValue();
        $second = Type::fromClassName(DefaultValueHookParent::class)->defaultValue();

        self::assertInstanceOf(DefaultValueHookParent::class, $first);
        self::assertInstanceOf(DefaultValueHookParent::class, $second);
        self::assertSame(DefaultValueHookParent::class, $first::class);
        self::assertSame('parent', $first->source);
        self::assertNotSame($first, $second);
        self::assertSame($before + 2, DefaultValueHookParent::$calls);

        $spelled = '\\' . DefaultValueHookParent::class;
        $fromSpelling = Type::fromClassName($spelled)->defaultValue();
        self::assertInstanceOf(DefaultValueHookParent::class, $fromSpelling);
        self::assertSame(DefaultValueHookParent::class, $fromSpelling::class);

        $aliased = Type::fromClassName(DefaultValueHookParent::class)->name('NotAClass');
        self::assertSame('NotAClass', $aliased->getName());
        $fromAlias = $aliased->defaultValue();
        self::assertInstanceOf(DefaultValueHookParent::class, $fromAlias);
        self::assertSame(DefaultValueHookParent::class, $fromAlias::class);

        $beforeGeneric = DefaultValueHookParent::$calls;
        $fromGeneric = Type::generic(DefaultValueHookParent::class, Type::int())->defaultValue();
        self::assertInstanceOf(DefaultValueHookParent::class, $fromGeneric);
        self::assertSame(DefaultValueHookParent::class, $fromGeneric::class);
        self::assertSame($beforeGeneric + 1, DefaultValueHookParent::$calls);

        $beforeAbstract = DefaultValueHookAbstract::$calls;
        $fromAbstract = Type::fromClassName(DefaultValueHookAbstract::class)->defaultValue();
        self::assertInstanceOf(DefaultValueHookAbstractMade::class, $fromAbstract);
        self::assertSame($beforeAbstract + 1, DefaultValueHookAbstract::$calls);

        $beforeNullable = DefaultValueHookParent::$calls;
        self::assertNull(Type::nullable(Type::fromClassName(DefaultValueHookParent::class))->defaultValue());
        self::assertSame($beforeNullable, DefaultValueHookParent::$calls);

        $case = Type::fromClassName(DefaultValueHookColor::class)->defaultValue();
        self::assertSame(DefaultValueHookColor::Red, $case);
    }

    public function testDefaultValueIgnoresInheritedTyhpDefault(): void
    {
        $before = DefaultValueHookParent::$calls;
        self::assertNull(Type::fromClassName(DefaultValueHookChild::class)->defaultValue());
        self::assertNull(Type::fromClassName(DefaultValueHookGrandchild::class)->defaultValue());
        self::assertNull(Type::generic(DefaultValueHookChild::class)->defaultValue());
        self::assertSame($before, DefaultValueHookParent::$calls);

        $own = Type::fromClassName(DefaultValueHookChildOwn::class)->defaultValue();
        self::assertInstanceOf(DefaultValueHookChildOwn::class, $own);
        self::assertSame(DefaultValueHookChildOwn::class, $own::class);
        self::assertSame('child', $own->source);
        self::assertSame($before, DefaultValueHookParent::$calls);

        $beforeAbstract = DefaultValueHookAbstract::$calls;
        self::assertNull(Type::fromClassName(DefaultValueHookAbstractMade::class)->defaultValue());
        self::assertSame($beforeAbstract, DefaultValueHookAbstract::$calls);
    }

    public function testDefaultValueWithoutTyhpDefaultIsNull(): void
    {
        self::assertNull(Type::fromClassName(DefaultValueHookNone::class)->defaultValue());
        self::assertNull(Type::fromClassName(\stdClass::class)->defaultValue());
        self::assertNull(Type::fromClassName(DefaultValueHookPrivate::class)->defaultValue());
        self::assertNull(Type::fromClassName(DefaultValueHookInterface::class)->defaultValue());
        self::assertNull(Type::generic('array', Type::int())->defaultValue());
        self::assertNull(Type::object()->defaultValue());
    }

    public function testDefaultValueDoesNotReuseTheInstance(): void
    {
        $first = Type::fromClassName(DefaultValueHookParent::class)->defaultValue();
        $second = Type::fromClassName(DefaultValueHookParent::class)->defaultValue();
        self::assertInstanceOf(DefaultValueHookParent::class, $first);
        self::assertInstanceOf(DefaultValueHookParent::class, $second);
        self::assertNotSame($first, $second);

        $one = Type::fromClassName(DefaultValueHookSingleton::class)->defaultValue();
        $two = Type::fromClassName(DefaultValueHookSingleton::class)->defaultValue();
        self::assertInstanceOf(DefaultValueHookSingleton::class, $one);
        self::assertSame($one, $two);

        $red = Type::fromClassName(DefaultValueHookColor::class)->defaultValue();
        $again = Type::fromClassName(DefaultValueHookColor::class)->defaultValue();
        self::assertSame(DefaultValueHookColor::Red, $red);
        self::assertSame($red, $again);
    }
}

#[GenericRuntime(erased: false, factory: 'new_Story49Box__tyhpGeneric', layouts: [1])]
class Story49Box
{
    public ?object $__tyhpGeneric = null;

    public static function new_Story49Box__tyhpGeneric(?Type $__generic_T, mixed $value = null): void
    {
    }
}

#[GenericRuntime(erased: false, factory: 'new_Story49Pair__tyhpGeneric', layouts: [1])]
class Story49Pair
{
    public static function new_Story49Pair__tyhpGeneric(?Type $__generic_TKey, ?Type $__generic_TValue): void
    {
    }
}

class Story49GenericBag
{
    public function __construct(private Type $type)
    {
    }

    public function objectType(): Type
    {
        return $this->type;
    }
}

/**
 * An iterator that records the call stack the first time the type walk reads it, so a test can tell
 * whether `\Tyhp\Ext::typeIs` or the PHP walk reached it.
 *
 * @implements \Iterator<int, mixed>
 */
final class TypeWalkProbe implements \Iterator
{
    /** @var list<string> */
    public array $frames = [];

    private int $position = 0;

    /**
     * @param list<mixed> $items
     */
    public function __construct(private array $items, private ?\Throwable $failWith = null)
    {
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        if ($this->frames === []) {
            foreach (\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $this->frames[] = ($frame['class'] ?? '') . '::' . $frame['function'];
            }
        }

        return $this->position < \count($this->items);
    }

    public function current(): mixed
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->items[$this->position];
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        $this->position++;
    }
}

class DefaultValueHookParent
{
    public static int $calls = 0;

    public string $source = '';

    public static function __tyhpDefault(): static
    {
        self::$calls++;
        $value = new self();
        $value->source = 'parent';

        return $value;
    }
}

class DefaultValueHookChild extends DefaultValueHookParent
{
}

class DefaultValueHookGrandchild extends DefaultValueHookChild
{
}

class DefaultValueHookChildOwn extends DefaultValueHookParent
{
    public static function __tyhpDefault(): static
    {
        $value = new self();
        $value->source = 'child';

        return $value;
    }
}

class DefaultValueHookNone
{
}

class DefaultValueHookPrivate
{
    private static function __tyhpDefault(): static
    {
        return new self();
    }
}

class DefaultValueHookSingleton
{
    private static ?self $instance = null;

    public static function __tyhpDefault(): static
    {
        return self::$instance ??= new self();
    }
}

interface DefaultValueHookInterface
{
    public static function __tyhpDefault(): static;
}

abstract class DefaultValueHookAbstract
{
    public static int $calls = 0;

    public static function __tyhpDefault(): static
    {
        self::$calls++;

        return new DefaultValueHookAbstractMade();
    }
}

class DefaultValueHookAbstractMade extends DefaultValueHookAbstract
{
}

enum DefaultValueHookColor
{
    case Red;

    public static function __tyhpDefault(): static
    {
        return self::Red;
    }
}
