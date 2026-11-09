<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expression\NullSafeAccessExpression;
use Tyhp\Expression\PropertyAccessExpression;
use Tyhp\PropertyPath;

class PropertyPathTest extends TestCase
{
    public function testConstructsWithSegments(): void
    {
        $source = (object) ['user' => (object) ['name' => 'Ada']];
        $path = new PropertyPath(
            'object',
            'string',
            ['user', 'name'],
            fn(object $source): string => $source->user->name,
        );

        self::assertSame(['user', 'name'], $path->getSegments());
        self::assertSame('user.name', $path->getPath());
        self::assertSame('name', $path->getPropertyName());
    }

    public function testGetValueResolvesNestedProperty(): void
    {
        $source = (object) ['user' => (object) ['city' => 'Paris']];
        $path = new PropertyPath(
            'object',
            'string',
            ['user', 'city'],
            fn(object $source): string => $source->user->city,
        );

        self::assertSame('Paris', $path->getValue($source));
    }

    public function testNullSafeSegmentBuildsNullSafeNodeAndReturnsNull(): void
    {
        $path = new PropertyPath(
            'object',
            '?string',
            ['address', 'city'],
            fn(object $source): ?string => $source->address?->city,
            nullSafeFlags: [false, true],
        );

        self::assertSame('nullSafeAccess', $path->body->nodeType);
        self::assertNull($path->getValue((object) ['address' => null]));
        self::assertSame('Oslo', $path->getValue((object) ['address' => (object) ['city' => 'Oslo']]));
    }

    public function testNullSafeFlagsPassedPositionallyAfterAllowEmpty(): void
    {
        $path = new PropertyPath(
            'object',
            '?string',
            ['address', 'city'],
            fn(object $source): ?string => $source->address?->city,
            false,
            [false, true],
        );

        self::assertSame('nullSafeAccess', $path->body->nodeType);
    }

    public function testGetValueReturnsWhateverTheCallableReturns(): void
    {
        $path = new PropertyPath('object', 'int', ['age'], fn(object $source): int => $source->age);

        self::assertSame(36, $path->getValue((object) ['age' => 36]));
    }

    public function testEmptyPathAllowedWhenRequested(): void
    {
        $path = new PropertyPath('object', 'object', [], fn(object $source): object => $source, true);

        self::assertSame('', $path->getPath());
        self::assertSame('', $path->getPropertyName());
    }

    public function testLambdaInfersPropertyTypeWithoutAReturnTypeOnTheClosure(): void
    {
        $path = PropertyPath::from()(
            PropertyPathSample::class,
            ['age'],
            fn(PropertyPathSample $person) => $person->age,
        );

        self::assertSame('int', $path->returnType);
        self::assertSame(['age'], $path->getSegments());
        self::assertSame(36, $path->getValue(new PropertyPathSample('Ada', 36, 'Paris')));
    }

    public function testLambdaUsesTheClosureReturnTypeWhenPresent(): void
    {
        $path = PropertyPath::from()(
            PropertyPathSample::class,
            ['city'],
            fn(PropertyPathSample $person): string => $person->city,
        );

        self::assertSame('string', $path->returnType);
        self::assertSame('Paris', $path->getValue(new PropertyPathSample('Ada', 36, 'Paris')));
    }

    public function testLambdaNullSafeFlagMakesTheResultNullable(): void
    {
        $path = PropertyPath::from()(
            PropertyPathSample::class,
            ['nickname'],
            fn(PropertyPathSample $person) => $person->nickname,
            [true],
        );

        self::assertSame('?string', $path->returnType);
        self::assertNull($path->getValue(new PropertyPathSample('Ada', 36, 'Paris')));
    }

    public function testEmptyPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PropertyPath('object', 'mixed', [], fn(): mixed => null);
    }

    public function testFromClosureReadsAPropertyChain(): void
    {
        $fn = fn ($u) => $u->a->b;
        $path = PropertyPath::fromClosure($fn, null);

        self::assertSame(['a', 'b'], $path->getSegments());
        self::assertInstanceOf(PropertyAccessExpression::class, $path->body);
        self::assertSame('ok', $path->getValue((object) ['a' => (object) ['b' => 'ok']]));
    }

    public function testFromClosureReadsANullsafeChain(): void
    {
        $fn = fn ($u) => $u?->a?->b;
        $path = PropertyPath::fromClosure($fn, 'callable(\\User): string');

        self::assertSame(['a', 'b'], $path->getSegments());
        self::assertSame('User', $path->parameters[0]->paramType);
        self::assertSame('string', $path->returnType);
        self::assertInstanceOf(NullSafeAccessExpression::class, $path->body);
        self::assertNull($path->getValue((object) ['a' => null]));
        self::assertSame('ok', $path->getValue((object) ['a' => (object) ['b' => 'ok']]));
    }

    public function testFromClosureRejectsAnIndex(): void
    {
        $this->assertNotAPropertyPath(fn ($u) => $u->tags[0]);
    }

    public function testFromClosureRejectsAComparison(): void
    {
        $this->assertNotAPropertyPath(fn ($u) => $u->age > 1);
    }

    public function testFromClosureRejectsAMethodCall(): void
    {
        $this->assertNotAPropertyPath(fn ($u) => $u->name());
    }

    public function testFromClosureRejectsABareParameter(): void
    {
        $this->assertNotAPropertyPath(fn ($u) => $u);
    }

    public function testFromClosureRejectsAChainOffASecondParameter(): void
    {
        $this->assertNotAPropertyPath(fn ($a, $b) => $b->x);
    }

    private function assertNotAPropertyPath(\Closure $fn): void
    {
        try {
            PropertyPath::fromClosure($fn, null);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('property-path', $e->getMessage());
        }
    }
}

class PropertyPathSample
{
    public function __construct(
        public string $name,
        public int $age,
        public string $city,
        public ?string $nickname = null,
    ) {
    }
}
