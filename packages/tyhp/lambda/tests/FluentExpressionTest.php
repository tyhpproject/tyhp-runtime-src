<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expr;
use Tyhp\Expression\BinaryExpression;
use Tyhp\Expression\ConstantExpression;
use Tyhp\Expression\NullSafeAccessExpression;
use Tyhp\Expression\PropertyAccessExpression;
use Tyhp\Expression\TernaryExpression;
use Tyhp\PropertyPath;

class FluentExpressionTest extends TestCase
{
    public function testGteAndEqBuildComparisonsTheWalkerRuns(): void
    {
        $person = Expr::param('person', FluentPerson::class);

        $adult = $person->age->gte(18);
        self::assertInstanceOf(BinaryExpression::class, $adult->body);
        self::assertSame('>=', $adult->body->operator);
        self::assertTrue($adult(new FluentPerson(18)));
        self::assertFalse($adult(new FluentPerson(17)));

        $oslo = $person->city->eq('Oslo');
        self::assertInstanceOf(BinaryExpression::class, $oslo->body);
        self::assertSame('===', $oslo->body->operator);
        self::assertTrue($oslo(new FluentPerson(city: 'Oslo')));
        self::assertFalse($oslo(new FluentPerson(city: 'Bergen')));
    }

    public function testAndOrThenElseNullSafeAndExprShapeTheTree(): void
    {
        $person = Expr::param('person', FluentPerson::class);

        $either = $person->age->gte(18)->and()->city->eq('Oslo')->or()->vip;
        self::assertInstanceOf(BinaryExpression::class, $either->body);
        self::assertSame('||', $either->body->operator);
        self::assertInstanceOf(BinaryExpression::class, $either->body->left);
        self::assertSame('&&', $either->body->left->operator);
        self::assertTrue($either(new FluentPerson(20, 'Oslo')));
        self::assertTrue($either(new FluentPerson(10, 'Bergen', vip: true)));
        self::assertFalse($either(new FluentPerson(10, 'Bergen')));

        $grouped = $person->age->gte(18)->and(fn ($p) => $p->city->eq('Oslo')->or()->vip);
        self::assertInstanceOf(BinaryExpression::class, $grouped->body);
        self::assertSame('&&', $grouped->body->operator);
        self::assertInstanceOf(BinaryExpression::class, $grouped->body->right);
        self::assertSame('||', $grouped->body->right->operator);
        self::assertTrue($grouped(new FluentPerson(20, 'Bergen', vip: true)));
        self::assertFalse($grouped(new FluentPerson(20, 'Bergen')));
        self::assertFalse($grouped(new FluentPerson(10, 'Bergen', vip: true)));

        $choice = $person->age->gte(18)->then()->address->nullSafe()->city->else('minor');
        self::assertInstanceOf(TernaryExpression::class, $choice->body);
        self::assertInstanceOf(NullSafeAccessExpression::class, $choice->body->ifTrue);
        self::assertInstanceOf(PropertyAccessExpression::class, $choice->body->ifTrue->object);
        self::assertSame('address', $choice->body->ifTrue->object->property);
        self::assertSame('city', $choice->body->ifTrue->property);
        self::assertInstanceOf(ConstantExpression::class, $choice->body->ifFalse);
        self::assertSame('minor', $choice->body->ifFalse->value);
        self::assertSame('Oslo', $choice(new FluentPerson(20, address: new FluentAddress('Oslo'))));
        self::assertSame('minor', $choice(new FluentPerson(10, address: new FluentAddress('Oslo'))));
        self::assertNull($choice(new FluentPerson(40)));

        $home = $person->age->gte(18)->then()->city->else()->region;
        self::assertInstanceOf(TernaryExpression::class, $home->body);
        self::assertInstanceOf(PropertyAccessExpression::class, $home->body->ifFalse);
        self::assertSame('region', $home->body->ifFalse->property);
        self::assertSame('North', $home(new FluentPerson(10, region: 'North')));

        $wrapped = $person->expr(
            fn ($p) => $p->age->gte(18)->then()->address->nullSafe()->city->else()->region,
        )->eq('Oslo');
        self::assertInstanceOf(BinaryExpression::class, $wrapped->body);
        self::assertSame('===', $wrapped->body->operator);
        self::assertSame('ternary', $wrapped->body->left->nodeType);
        self::assertTrue($wrapped(new FluentPerson(20, address: new FluentAddress('Oslo'))));
        self::assertTrue($wrapped(new FluentPerson(10, region: 'Oslo')));
        self::assertFalse($wrapped(new FluentPerson(10, region: 'North')));
    }

    public function testBackedBySkipsTheWalker(): void
    {
        $expr = Expr::param('person', FluentPerson::class)
            ->age
            ->gte(18)
            ->backedBy(fn (FluentPerson $person): bool => false);

        self::assertFalse($expr(new FluentPerson(40)));
        self::assertSame('bool', $expr->returnType);
    }

    public function testWalkerRunsWhenBackedByIsOmitted(): void
    {
        $expr = Expr::param('person', FluentPerson::class)->age->gte(18);

        self::assertNull($expr->callable);
        self::assertTrue($expr(new FluentPerson(40)));
        self::assertFalse($expr->compile()(new FluentPerson(10)));
    }

    public function testNullSafeShortCircuits(): void
    {
        $expr = Expr::param('person', FluentPerson::class)->address->nullSafe()->city;

        self::assertNull($expr(new FluentPerson()));
        self::assertSame('Oslo', $expr(new FluentPerson(address: new FluentAddress('Oslo'))));

        $bomb = new FluentBomb();
        $skipped = Expr::param('bomb', FluentBomb::class)->value(false)->and()->call('boom');
        self::assertFalse($skipped($bomb));
        self::assertSame(0, $bomb->calls);

        $kept = Expr::param('bomb', FluentBomb::class)->value('kept')->coalesce()->call('boom');
        self::assertSame('kept', $kept($bomb));
        self::assertSame(0, $bomb->calls);
    }

    public function testNullSafeMustBeFollowedByAProperty(): void
    {
        $this->expectException(\LogicException::class);
        Expr::param('person', FluentPerson::class)->address->nullSafe()->eq('Oslo');
    }

    public function testPropertyPathFluentFormUsesTheClosureOrTheWalker(): void
    {
        $walked = PropertyPath::on(FluentPerson::class)->age;
        self::assertSame(['age'], $walked->getSegments());
        self::assertSame(36, $walked->getValue(new FluentPerson(36)));

        $backed = PropertyPath::on(FluentPerson::class)
            ->age
            ->backedBy(fn (FluentPerson $person): int => 7);
        self::assertSame('int', $backed->returnType);
        self::assertSame(7, $backed->getValue(new FluentPerson(36)));

        $city = PropertyPath::on(FluentPerson::class)->address->nullSafe()->city;
        self::assertSame('nullSafeAccess', $city->body->nodeType);
        self::assertNull($city->getValue(new FluentPerson()));
        self::assertSame('Oslo', $city->getValue(new FluentPerson(address: new FluentAddress('Oslo'))));
    }
}

class FluentAddress
{
    public function __construct(public string $city)
    {
    }
}

class FluentPerson
{
    public function __construct(
        public int $age = 0,
        public string $city = '',
        public bool $vip = false,
        public ?FluentAddress $address = null,
        public string $region = '',
    ) {
    }
}

class FluentBomb
{
    public int $calls = 0;

    public function boom(): bool
    {
        $this->calls++;
        throw new \RuntimeException('boom');
    }
}
