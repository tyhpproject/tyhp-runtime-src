<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tyhp\Exceptions\InvalidTypeException;
use Tyhp\NamedType;
use Tyhp\Type;

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
}
