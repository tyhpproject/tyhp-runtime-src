<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expression\FluentSupport;

class FluentSupportPathTest extends TestCase
{
    public function testObjectPropertyChain(): void
    {
        $source = (object) ['user' => (object) ['name' => 'Ada']];
        $get = FluentSupport::synthesizePathCallable(['user', 'name'], [false, false]);

        self::assertSame('Ada', $get($source));
    }

    public function testAssociativeArrayAndStructShapedArray(): void
    {
        $source = ['user' => ['name' => 'Ada']];
        $get = FluentSupport::synthesizePathCallable(['user', 'name'], [false, false]);

        self::assertSame('Ada', $get($source));
    }

    public function testMixedObjectAndArrayPath(): void
    {
        $source = (object) ['address' => ['city' => 'Paris']];
        $get = FluentSupport::synthesizePathCallable(['address', 'city'], [false, false]);

        self::assertSame('Paris', $get($source));
    }

    public function testArrayAccessOffset(): void
    {
        $source = new \ArrayObject(['user' => new \ArrayObject(['name' => 'Ada'])]);
        $get = FluentSupport::synthesizePathCallable(['user', 'name'], [false, false]);

        self::assertSame('Ada', $get($source));
    }

    public function testNullSafeMissingArrayKeyReturnsNull(): void
    {
        $get = FluentSupport::synthesizePathCallable(['user', 'name'], [true, true]);

        self::assertNull($get(['other' => 1]));
        self::assertNull($get(null));
        self::assertNull($get(42));
    }

    public function testStrictNonObjectLikeThrowsTypeError(): void
    {
        $get = FluentSupport::synthesizePathCallable(['user'], [false]);

        $this->expectException(\TypeError::class);
        $get(42);
    }

    public function testStrictMissingArrayKeyThrowsTypeError(): void
    {
        $get = FluentSupport::synthesizePathCallable(['user'], [false]);

        $this->expectException(\TypeError::class);
        $get(['other' => 1]);
    }
}
