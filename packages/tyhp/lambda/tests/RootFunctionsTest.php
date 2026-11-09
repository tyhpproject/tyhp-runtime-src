<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expression;
use Tyhp\PropertyPath;
use Tyhp\Type;

class RootFunctionsTest extends TestCase
{
    public function testExprMatchesExpressionOf(): void
    {
        $fromRoot = \_expr(User::class)->age->gte(18);
        $fromExpression = Expression::of(User::class)->age->gte(18);

        self::assertTrue($fromRoot->equals($fromExpression));
    }

    public function testExprWithSegmentsMatchesExpressionOf(): void
    {
        $fromRoot = \_expr(User::class, 'address', 'city');
        $fromExpression = Expression::of(User::class, 'address', 'city');

        self::assertTrue($fromRoot->equals($fromExpression));
        self::assertSame(['address', 'city'], $fromRoot->getSegments());
        self::assertSame($fromExpression->getSegments(), $fromRoot->getSegments());
    }

    public function testPathMatchesPropertyPathOn(): void
    {
        $fromRoot = \_path(User::class)->name;
        $fromOn = PropertyPath::on(User::class)->name;

        self::assertTrue($fromRoot->equals($fromOn));
        self::assertSame(['name'], $fromRoot->getSegments());
        self::assertSame($fromOn->getSegments(), $fromRoot->getSegments());
    }

    public function testExprCompanionBindsTheSameWayAsExpressionOfCompanion(): void
    {
        $type = Type::fromClassName(User::class);
        $fromRoot = \_expr__tyhpGeneric($type)(User::class, 'address', 'city');
        $fromExpression = Expression::of__tyhpGeneric($type)(User::class, 'address', 'city');

        self::assertTrue($fromRoot->equals($fromExpression));
        self::assertSame(['address', 'city'], $fromRoot->getSegments());
    }

    public function testPathCompanionBindsTheSameWayAsPropertyPathOnCompanion(): void
    {
        $type = Type::fromClassName(User::class);
        $fromRoot = \_path__tyhpGeneric($type)(User::class)->name;
        $fromOn = PropertyPath::on__tyhpGeneric($type)(User::class)->name;

        self::assertTrue($fromRoot->equals($fromOn));
        self::assertSame(['name'], $fromRoot->getSegments());
    }
}

class User
{
    public function __construct(
        public string $name = '',
        public int $age = 0,
        public ?UserAddress $address = null,
    ) {
    }
}

class UserAddress
{
    public function __construct(public string $city = '')
    {
    }
}
