<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expr;
use Tyhp\Expression;
use Tyhp\Expression\ArrayExpression;
use Tyhp\Expression\ArrayItem;
use Tyhp\Expression\ConstantExpression;
use Tyhp\Expression\EvalBacker;
use Tyhp\Expression\EvalClosure;
use Tyhp\Expression\ExpressionInterpreter;
use Tyhp\Expression\ExpressionSerializer;
use Tyhp\Expression\ExpressionVisitor;
use Tyhp\Expression\MethodCallExpression;
use Tyhp\Expression\ParameterExpression;
use Tyhp\Expression\PropertyAccessExpression;

class ArrayExpressionTest extends TestCase
{
    protected function setUp(): void
    {
        EvalClosure::$compilations = 0;
        EvalClosure::$lastSource = '';
    }

    public function testKeyedArrayProjection(): void
    {
        [$body, $user] = self::projection();

        self::assertSame('array', $body->nodeType);
        self::assertCount(2, $body->items);
        self::assertInstanceOf(ConstantExpression::class, $body->items[0]->key);
        self::assertSame('id', $body->items[0]->key->value);
        self::assertInstanceOf(PropertyAccessExpression::class, $body->items[0]->value);

        $ada = new ArrayUser(7, 'Ada');
        self::assertSame(['id' => 7, 'name' => 'Ada'], ExpressionInterpreter::evaluate($body, [$user], $ada));

        $compiled = EvalBacker::tryCompile($body, [$user]);
        self::assertNotNull($compiled);
        self::assertSame(['id' => 7, 'name' => 'Ada'], $compiled($ada));
        self::assertSame(['id' => 7, 'name' => 'Ada'], (new Expression($body, [$user], null, 'array'))($ada));
    }

    public function testListMixedAndNestedArrays(): void
    {
        $user = new ParameterExpression('u', ArrayUser::class, 0);
        // [1, 'a' => $u->id, 2, [$u->name, [true]]]
        $body = new ArrayExpression([
            new ArrayItem(new ConstantExpression(1, '1')),
            new ArrayItem(new PropertyAccessExpression($user, 'id', 'int'), new ConstantExpression('a', 'string')),
            new ArrayItem(new ConstantExpression(2, '2')),
            new ArrayItem(new ArrayExpression([
                new ArrayItem(new PropertyAccessExpression($user, 'name', 'string')),
                new ArrayItem(new ArrayExpression([new ArrayItem(new ConstantExpression(true, 'true'))])),
            ])),
        ]);

        $expected = [0 => 1, 'a' => 3, 1 => 2, 2 => ['Bo', [true]]];
        $bo = new ArrayUser(3, 'Bo');
        self::assertSame($expected, ExpressionInterpreter::evaluate($body, [$user], $bo));

        $compiled = EvalBacker::tryCompile($body, [$user]);
        self::assertNotNull($compiled);
        self::assertSame($expected, $compiled($bo));
    }

    public function testEmptyArrayAndComputedKeys(): void
    {
        $user = new ParameterExpression('u', ArrayUser::class, 0);
        $empty = new ArrayExpression([]);
        self::assertSame([], ExpressionInterpreter::evaluate($empty, [$user], new ArrayUser(1, 'x')));

        // [$u->name => $u->id, '7' => 0]
        $keyed = new ArrayExpression([
            new ArrayItem(new PropertyAccessExpression($user, 'id', 'int'), new PropertyAccessExpression($user, 'name', 'string')),
            new ArrayItem(new ConstantExpression(0, '0'), new ConstantExpression('7', 'string')),
        ]);
        self::assertSame(['Cy' => 4, 7 => 0], ExpressionInterpreter::evaluate($keyed, [$user], new ArrayUser(4, 'Cy')));
        $compiled = EvalBacker::tryCompile($keyed, [$user]);
        self::assertNotNull($compiled);
        self::assertSame(['Cy' => 4, 7 => 0], $compiled(new ArrayUser(4, 'Cy')));
    }

    public function testArrayAsMethodArgument(): void
    {
        // fn ($u) => $u->hasIdIn([1, 2, 3])
        $user = new ParameterExpression('u', ArrayUser::class, 0);
        $body = new MethodCallExpression($user, 'hasIdIn', [
            new ArrayExpression([
                new ArrayItem(new ConstantExpression(1, '1')),
                new ArrayItem(new ConstantExpression(2, '2')),
                new ArrayItem(new ConstantExpression(3, '3')),
            ]),
        ], 'bool');

        self::assertTrue(ExpressionInterpreter::evaluate($body, [$user], new ArrayUser(2, 'x')));
        self::assertFalse(ExpressionInterpreter::evaluate($body, [$user], new ArrayUser(9, 'x')));
        $compiled = EvalBacker::tryCompile($body, [$user]);
        self::assertNotNull($compiled);
        self::assertTrue($compiled(new ArrayUser(3, 'x')));
    }

    public function testFluentArrayOf(): void
    {
        $u = Expr::param('u', ArrayUser::class);
        $projection = Expr::arrayOf([$u->id, $u->name, 'x'], [0 => 'id', 1 => 'name']);

        self::assertInstanceOf(ArrayExpression::class, $projection->body);
        self::assertNull($projection->body->items[2]->key);
        self::assertCount(1, $projection->parameters);
        self::assertSame(['id' => 5, 'name' => 'Di', 0 => 'x'], $projection(new ArrayUser(5, 'Di')));

        $inList = $u->call('hasIdIn', Expr::arrayOf([1, 5]));
        self::assertTrue($inList(new ArrayUser(5, 'Di')));
        self::assertFalse($inList(new ArrayUser(6, 'Di')));
    }

    public function testSerializerWritesArrayItems(): void
    {
        [$body, $user] = self::projection();
        $expression = new Expression($body, [$user], null, 'array');
        $decoded = \json_decode(ExpressionSerializer::toJson($expression), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('array', $decoded['body']['nodeType']);
        self::assertSame('array', $decoded['body']['type']);
        self::assertSame('constant', $decoded['body']['items'][0]['key']['nodeType']);
        self::assertSame('id', $decoded['body']['items'][0]['key']['value']);
        self::assertSame('propertyAccess', $decoded['body']['items'][0]['value']['nodeType']);

        $list = new Expression(
            new ArrayExpression([new ArrayItem(new ConstantExpression(1, '1'))]),
            [],
            null,
            'array',
        );
        $listDecoded = \json_decode(ExpressionSerializer::toJson($list), true, 512, \JSON_THROW_ON_ERROR);
        self::assertNull($listDecoded['body']['items'][0]['key']);
    }

    public function testSerializerEqualityDistinguishesKeys(): void
    {
        [$leftBody, $leftUser] = self::projection();
        [$sameBody, $sameUser] = self::projection();
        $renamed = new ArrayExpression([
            new ArrayItem(new PropertyAccessExpression($leftUser, 'id', 'int'), new ConstantExpression('key', 'string')),
            new ArrayItem(new PropertyAccessExpression($leftUser, 'name', 'string'), new ConstantExpression('name', 'string')),
        ]);

        $left = new Expression($leftBody, [$leftUser], null, 'array');
        self::assertTrue($left->equals(new Expression($sameBody, [$sameUser], null, 'array')));
        self::assertFalse($left->equals(new Expression($renamed, [$leftUser], null, 'array')));
    }

    public function testVisitorDefaultReturnsNull(): void
    {
        [$body] = self::projection();
        $visitor = new class extends ExpressionVisitor {
        };

        self::assertNull($body->accept($visitor));
    }

    /**
     * fn ($u) => ['id' => $u->id, 'name' => $u->name]
     *
     * @return array{ArrayExpression, ParameterExpression}
     */
    private static function projection(): array
    {
        $user = new ParameterExpression('u', ArrayUser::class, 0);
        $body = new ArrayExpression([
            new ArrayItem(new PropertyAccessExpression($user, 'id', 'int'), new ConstantExpression('id', 'string')),
            new ArrayItem(new PropertyAccessExpression($user, 'name', 'string'), new ConstantExpression('name', 'string')),
        ]);

        return [$body, $user];
    }
}

class ArrayUser
{
    public function __construct(public int $id, public string $name)
    {
    }

    /**
     * @param array<int> $ids
     */
    public function hasIdIn(array $ids): bool
    {
        return \in_array($this->id, $ids, true);
    }
}
