<?php

declare(strict_types=1);

namespace Tyhp\Tests\Core;

use PHPUnit\Framework\TestCase;
use Tyhp\BuiltIn;
use Tyhp\Type;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Story49TypeAttribute
{
    public function __construct(public BuiltIn $type)
    {
    }
}

#[Story49TypeAttribute(Type::int)]
class Story49Attributed
{
    public function accept(BuiltIn $type = Type::int): BuiltIn
    {
        return $type;
    }
}

class BuiltInTest extends TestCase
{
    public function testConstantsAreBuiltInCases(): void
    {
        self::assertSame(BuiltIn::string, Type::string);
        self::assertSame(BuiltIn::int, Type::int);
        self::assertSame(BuiltIn::float, Type::float);
        self::assertSame(BuiltIn::bool, Type::bool);
        self::assertSame(BuiltIn::null, Type::null);
        self::assertSame(BuiltIn::void, Type::void);
        self::assertSame(BuiltIn::mixed, Type::mixed);
        self::assertSame(BuiltIn::never, Type::never);
        self::assertSame(BuiltIn::array, Type::array);
        self::assertSame(BuiltIn::object, Type::object);
        self::assertSame(BuiltIn::callable, Type::callable);
        self::assertSame(BuiltIn::iterable, Type::iterable);
        self::assertSame(BuiltIn::resource, Type::resource);

        self::assertSame(Type::int(), Type::arg(Type::int));
        self::assertSame(Type::string(), Type::string->toType());
        self::assertSame(Type::int(), BuiltIn::int->toType());
    }

    public function testConstantWorksAsDefaultAndAttributeArgument(): void
    {
        $method = new \ReflectionMethod(Story49Attributed::class, 'accept');
        self::assertSame(BuiltIn::int, $method->getParameters()[0]->getDefaultValue());

        $attribute = (new \ReflectionClass(Story49Attributed::class))
            ->getAttributes(Story49TypeAttribute::class)[0]
            ->newInstance();
        self::assertSame(BuiltIn::int, $attribute->type);
        self::assertSame(BuiltIn::int, (new Story49Attributed())->accept());
    }

    public function testBuiltInNullableAndOrReturnType(): void
    {
        self::assertFalse((new \ReflectionClass(Type::class))->hasMethod('nullable'));
        self::assertTrue((new \ReflectionClass(BuiltIn::class))->hasMethod('nullable'));
        self::assertInstanceOf(Type::class, BuiltIn::int->nullable());
        self::assertSame('?int', (string) BuiltIn::int->nullable());
        self::assertSame('?int', (string) Type::int()->nullable());
        self::assertSame('?int', (string) Type::nullable(Type::int()));
        self::assertInstanceOf(Type::class, Type::int->or(Type::string));
        self::assertSame('int|string', (string) Type::int->or(Type::string));
        self::assertSame('int|string', (string) BuiltIn::int->or(BuiltIn::string));
    }
}
