<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expr;
use Tyhp\Expression;
use Tyhp\Expression\ArrayExpression;
use Tyhp\Expression\ArrayItem;
use Tyhp\Expression\BinaryExpression;
use Tyhp\Expression\ConstantExpression;
use Tyhp\Expression\ExpressionNode;
use Tyhp\Expression\ExpressionSerializer;
use Tyhp\Expression\ExpressionShape;
use Tyhp\Expression\LambdaExpression;
use Tyhp\Expression\MethodCallExpression;
use Tyhp\Expression\NullSafeMethodCallExpression;
use Tyhp\Expression\ParameterExpression;
use Tyhp\Expression\PropertyAccessExpression;
use Tyhp\Expression\TernaryExpression;
use Tyhp\PropertyPath;

class ExpressionShapeTest extends TestCase
{
    public function testCapturedValuesShareAKeyAndAreExtracted(): void
    {
        // Same chain the compiler emits for `fn ($u) => $u->age > $minAge`.
        $build = static fn(int $minAge): Expression => Expr::param('u', ShapePerson::class)
            ->age
            ->gt($minAge)
            ->backedBy(fn(ShapePerson $u): bool => $u->age > $minAge);

        $adult = $build(18);
        $senior = $build(65);

        self::assertFalse(ExpressionSerializer::equals($adult, $senior));
        self::assertSame(ExpressionSerializer::shapeKey($adult), ExpressionSerializer::shapeKey($senior));
        self::assertSame([18], ExpressionSerializer::shapeParameters($adult));
        self::assertSame([65], ExpressionSerializer::shapeParameters($senior));
    }

    public function testKeyIsACompactHexHash(): void
    {
        $key = ExpressionSerializer::shapeKey(Expr::param('u', ShapePerson::class)->age->gt(18));

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $key);
    }

    public function testKeyIsTheSameInAnotherProcess(): void
    {
        $script = 'require ' . \var_export(__DIR__ . '/bootstrap.php', true) . ';'
            . 'echo \Tyhp\Expression\ExpressionSerializer::shapeKey(\Tyhp\Expr::param("u", "App\\\\Person")->age->gt(18)->and()->name->eq("Ada"));';
        $output = \shell_exec(\escapeshellarg(\PHP_BINARY) . ' -r ' . \escapeshellarg($script));

        $local = ExpressionSerializer::shapeKey(
            Expr::param('u', 'App\\Person')->age->gt(99)->and()->name->eq('Grace'),
        );
        self::assertSame($local, $output);
    }

    public function testLiteralTypeSpellingsWidenToTheValueType(): void
    {
        $param = new ParameterExpression('n', 'int', 0);
        $make = static fn(mixed $value, string $type): Expression => new Expression(
            new BinaryExpression($param, '===', new ConstantExpression($value, $type), 'bool'),
            [$param],
            null,
            'bool',
        );

        self::assertSame(
            ExpressionSerializer::shapeKey($make(0, '0')),
            ExpressionSerializer::shapeKey($make(10, 'int')),
        );
        self::assertSame(
            ExpressionSerializer::shapeKey($make(1.5, '1.5')),
            ExpressionSerializer::shapeKey($make(2.0, '2')),
        );
        self::assertSame(
            ExpressionSerializer::shapeKey($make(true, 'true')),
            ExpressionSerializer::shapeKey($make(false, 'false')),
        );
        self::assertSame(
            ExpressionSerializer::shapeKey($make('Ada', "'Ada'")),
            ExpressionSerializer::shapeKey($make('Grace', 'string')),
        );
    }

    public function testConstantTypeSpellingsStayInTheKey(): void
    {
        $param = new ParameterExpression('n', 'mixed', 0);
        $make = static fn(mixed $value, string $type): Expression => new Expression(
            new BinaryExpression($param, '===', new ConstantExpression($value, $type), 'bool'),
            [$param],
            null,
            'bool',
        );

        $int = ExpressionSerializer::shapeKey($make(5, 'int'));
        self::assertNotSame($int, ExpressionSerializer::shapeKey($make('5', 'string')));
        self::assertNotSame($int, ExpressionSerializer::shapeKey($make(null, 'null')));
        self::assertNotSame($int, ExpressionSerializer::shapeKey($make(5.0, 'float')));
        self::assertNotSame(
            ExpressionSerializer::shapeKey($make(null, '?int')),
            ExpressionSerializer::shapeKey($make(null, '?string')),
        );
    }

    public function testDifferentOperatorsPropertiesAndMethodsGiveDifferentKeys(): void
    {
        $person = static fn(): Expr => Expr::param('u', ShapePerson::class);
        $base = ExpressionSerializer::shapeKey($person()->age->gt(18));

        self::assertNotSame($base, ExpressionSerializer::shapeKey($person()->age->gte(18)));
        self::assertNotSame($base, ExpressionSerializer::shapeKey($person()->score->gt(18)));
        self::assertNotSame($base, ExpressionSerializer::shapeKey($person()->age->gt(18)->not()));
        self::assertNotSame(
            ExpressionSerializer::shapeKey($person()->call('rank', 1)),
            ExpressionSerializer::shapeKey($person()->call('level', 1)),
        );
        self::assertNotSame(
            ExpressionSerializer::shapeKey($person()->call('rank', 1)),
            ExpressionSerializer::shapeKey($person()->call('rank', 1, 2)),
        );
    }

    public function testParameterTypesPositionsAndNamesGiveDifferentKeys(): void
    {
        $make = static fn(string $type, int $index, string $name): Expression => new Expression(
            new PropertyAccessExpression(new ParameterExpression($name, $type, $index), 'age', 'int'),
            [new ParameterExpression($name, $type, $index)],
            null,
            'int',
        );

        $base = ExpressionSerializer::shapeKey($make('App\\Person', 0, 'u'));
        self::assertSame($base, ExpressionSerializer::shapeKey($make('App\\Person', 0, 'u')));
        self::assertNotSame($base, ExpressionSerializer::shapeKey($make('App\\Admin', 0, 'u')));
        self::assertNotSame($base, ExpressionSerializer::shapeKey($make('App\\Person', 1, 'u')));
        self::assertNotSame($base, ExpressionSerializer::shapeKey($make('App\\Person', 0, 'v')));
    }

    public function testReturnTypesAndNodeTypesGiveDifferentKeys(): void
    {
        $param = new ParameterExpression('u', 'App\\Person', 0);
        $make = static fn(string $nodeType, string $returnType): Expression => new Expression(
            new PropertyAccessExpression($param, 'age', $nodeType),
            [$param],
            null,
            $returnType,
        );

        $base = ExpressionSerializer::shapeKey($make('int', 'int'));
        self::assertNotSame($base, ExpressionSerializer::shapeKey($make('int', 'mixed')));
        self::assertNotSame($base, ExpressionSerializer::shapeKey($make('?int', 'int')));
    }

    public function testParametersFollowWrittenOrder(): void
    {
        // `$u->age > 18 && $u->name === 'Ada' || $u->score < 1.5`
        $expression = Expr::param('u', ShapePerson::class)
            ->age->gt(18)
            ->and()->name->eq('Ada')
            ->or()->score->lt(1.5);

        self::assertSame([18, 'Ada', 1.5], ExpressionSerializer::shapeParameters($expression));
    }

    public function testMethodCallsAndTernariesListTheirConstantsLeftToRight(): void
    {
        $receiver = new ConstantExpression(new ShapePerson(), ShapePerson::class);
        $call = new MethodCallExpression(
            $receiver,
            'rank',
            [new ConstantExpression(1, 'int'), new ConstantExpression(2, 'int')],
            'int',
        );
        $ternary = new TernaryExpression(new ConstantExpression(true, 'bool'), $call, new ConstantExpression(3, 'int'), 'int');
        $expression = new Expression($ternary, [], null, 'int');

        $shape = ExpressionShape::of($expression);

        self::assertSame([true, $receiver->value, 1, 2, 3], $shape->parameters);
        self::assertSame(1, $shape->slotOf($receiver));
        self::assertSame(\count($shape->parameters), \count($shape->constants));
    }

    public function testSlotOfMapsATranslatorsConstantNodeToItsValue(): void
    {
        $build = static fn(int $min, string $name): Expression => Expr::param('u', ShapePerson::class)
            ->age->gt($min)
            ->and()->name->eq($name);
        $first = ExpressionShape::of($build(18, 'Ada'));
        $second = ExpressionShape::of($build(30, 'Grace'));

        $nameNode = $first->constants[1];
        self::assertSame('Ada', $nameNode->value);
        $slot = $first->slotOf($nameNode);

        self::assertSame($first->key, $second->key);
        self::assertSame('Grace', $second->parameters[$slot]);
    }

    public function testSlotOfRejectsANodeFromAnotherTree(): void
    {
        $shape = ExpressionShape::of(Expr::param('u', ShapePerson::class)->age->gt(18));

        $this->expectException(\InvalidArgumentException::class);
        $shape->slotOf(new ConstantExpression(18, 'int'));
    }

    public function testTreeWithoutConstantsHasNoParameters(): void
    {
        $expression = Expr::param('u', ShapePerson::class)->age->gt(Expr::param('u', ShapePerson::class)->score);

        self::assertSame([], ExpressionSerializer::shapeParameters($expression));
    }

    public function testExpressionOfBuilderTreesShareAKey(): void
    {
        $adult = Expression::of(ShapePerson::class, 'age')->gte(18);
        $senior = Expression::of(ShapePerson::class, 'age')->gte(65);
        $score = Expression::of(ShapePerson::class, 'score')->gte(18);

        self::assertSame(ExpressionSerializer::shapeKey($adult), ExpressionSerializer::shapeKey($senior));
        self::assertNotSame(ExpressionSerializer::shapeKey($adult), ExpressionSerializer::shapeKey($score));
        self::assertSame([65], ExpressionSerializer::shapeParameters($senior));
    }

    public function testExpressionOfCombinedTreesExtractEveryConstant(): void
    {
        $left = Expression::of(ShapePerson::class, 'age')->gte(18);
        $right = Expression::of(ShapePerson::class, 'name')->eq('Ada');
        $other = Expression::of(ShapePerson::class, 'name')->eq('Grace');

        self::assertSame(
            ExpressionSerializer::shapeKey($left->and($right)),
            ExpressionSerializer::shapeKey($left->and($other)),
        );
        self::assertNotSame(
            ExpressionSerializer::shapeKey($left->and($right)),
            ExpressionSerializer::shapeKey($left->or($right)),
        );
        self::assertSame([18, 'Grace'], ExpressionSerializer::shapeParameters($left->and($other)));
    }

    public function testPropertyPathKeysFollowTheSegments(): void
    {
        $city = new PropertyPath('App\\Person', 'string', ['address', 'city']);
        $sameCity = PropertyPath::from('App\\Person', ['address', 'city'], fn(object $p): string => $p->address->city);
        $zip = new PropertyPath('App\\Person', 'string', ['address', 'zip']);
        $nullSafeCity = new PropertyPath('App\\Person', 'string', ['address', 'city'], nullSafeFlags: [false, true]);

        $key = ExpressionSerializer::shapeKey($city);
        self::assertSame($key, ExpressionSerializer::shapeKey($sameCity));
        self::assertNotSame($key, ExpressionSerializer::shapeKey($zip));
        self::assertNotSame($key, ExpressionSerializer::shapeKey($nullSafeCity));
        self::assertSame([], ExpressionSerializer::shapeParameters($city));
    }

    public function testFluentPropertyPathMatchesTheConstructedPath(): void
    {
        $fluent = PropertyPath::on(ShapePerson::class)->name;
        $constructed = new PropertyPath(ShapePerson::class, 'mixed', ['name']);
        $typed = new PropertyPath(ShapePerson::class, 'string', ['name']);

        self::assertSame(ExpressionSerializer::shapeKey($constructed), ExpressionSerializer::shapeKey($fluent));
        self::assertNotSame(ExpressionSerializer::shapeKey($typed), ExpressionSerializer::shapeKey($fluent));
    }

    public function testNestedLambdaConstantsAreParameters(): void
    {
        $make = static function (int $rating, string $returnType = 'bool'): Expression {
            $blog = new ParameterExpression('b', 'App\\Blog', 0);
            $post = new ParameterExpression('p', 'App\\Post', 0);
            $lambda = new LambdaExpression(
                [$post],
                new BinaryExpression(
                    new PropertyAccessExpression($post, 'rating', 'int'),
                    '>',
                    new ConstantExpression($rating, (string) $rating),
                    'bool',
                ),
                $returnType,
            );
            return new Expression(
                new MethodCallExpression(new PropertyAccessExpression($blog, 'posts', 'mixed'), 'any', [$lambda], 'bool'),
                [$blog],
                null,
                'bool',
            );
        };

        self::assertSame(ExpressionSerializer::shapeKey($make(3)), ExpressionSerializer::shapeKey($make(4)));
        self::assertNotSame(ExpressionSerializer::shapeKey($make(3)), ExpressionSerializer::shapeKey($make(3, 'mixed')));
        self::assertSame([4], ExpressionSerializer::shapeParameters($make(4)));
    }

    public function testArrayLiteralValuesAreParametersButConstantKeysStayInTheKey(): void
    {
        $param = new ParameterExpression('u', 'App\\Person', 0);
        $make = static fn(string $key, mixed $value): Expression => new Expression(
            new ArrayExpression([
                new ArrayItem(new PropertyAccessExpression($param, 'id', 'int'), new ConstantExpression($key, 'string')),
                new ArrayItem(new ConstantExpression($value, 'int')),
            ]),
            [$param],
            null,
            'array',
        );

        self::assertSame(ExpressionSerializer::shapeKey($make('id', 1)), ExpressionSerializer::shapeKey($make('id', 2)));
        self::assertNotSame(ExpressionSerializer::shapeKey($make('id', 1)), ExpressionSerializer::shapeKey($make('key', 1)));
        self::assertSame(['id', 2], ExpressionSerializer::shapeParameters($make('id', 2)));
    }

    public function testNullSafeMethodCallsDifferFromPlainCalls(): void
    {
        $param = new ParameterExpression('u', 'App\\Person', 0);
        $arguments = static fn(int $n): array => [new ConstantExpression($n, 'int')];
        $plain = new Expression(new MethodCallExpression($param, 'rank', $arguments(1), 'int'), [$param], null, 'int');
        $nullSafe = new Expression(new NullSafeMethodCallExpression($param, 'rank', $arguments(1), '?int'), [$param], null, '?int');
        $nullSafeOther = new Expression(new NullSafeMethodCallExpression($param, 'rank', $arguments(2), '?int'), [$param], null, '?int');

        self::assertNotSame(ExpressionSerializer::shapeKey($plain), ExpressionSerializer::shapeKey($nullSafe));
        self::assertSame(ExpressionSerializer::shapeKey($nullSafe), ExpressionSerializer::shapeKey($nullSafeOther));
        self::assertSame([2], ExpressionSerializer::shapeParameters($nullSafeOther));
    }

    public function testUnsupportedNodeIsRejected(): void
    {
        $node = new class ('int', 'custom') extends ExpressionNode {
            public function accept(\Tyhp\Expression\ExpressionVisitor $visitor): mixed
            {
                return null;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        ExpressionSerializer::shapeKey(new Expression($node, [], null, 'int'));
    }
}

class ShapePerson
{
    public function __construct(
        public int $age = 0,
        public string $name = '',
        public float $score = 0.0,
    ) {
    }

    public function rank(int ...$weights): int
    {
        return \array_sum($weights);
    }
}
