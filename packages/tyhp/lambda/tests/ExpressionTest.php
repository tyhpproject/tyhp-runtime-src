<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expression;
use Tyhp\Expression\BinaryExpression;
use Tyhp\Expression\ConstantExpression;
use Tyhp\Expression\ExpressionVisitor;
use Tyhp\Expression\ParameterExpression;

class ExpressionTest extends TestCase
{
    public function testInvokeEvaluatesCallable(): void
    {
        $param = new ParameterExpression('value', 'int', 0);
        $body = new ConstantExpression(1, 'int');
        $expression = new Expression(
            $body,
            [$param],
            fn(int $value): int => $value + 1,
            'int',
        );

        self::assertSame(43, ($expression)(42));
    }

    public function testInvokeSupportsMultipleParametersAndNonScalarResults(): void
    {
        $a = new ParameterExpression('a', 'array', 0);
        $b = new ParameterExpression('b', 'array', 1);
        $expression = new Expression(
            new ConstantExpression(null, 'null'),
            [$a, $b],
            fn(array $a, array $b): array => \array_merge($a, $b),
            'array',
        );

        self::assertSame([1, 2, 3], $expression([1], [2, 3]));
    }

    public function testInvokeAndCompileAgree(): void
    {
        $param = new ParameterExpression('value', 'int', 0);
        $expression = new Expression(
            new ConstantExpression(2, 'int'),
            [$param],
            fn(int $value): int => $value * 2,
            'int',
        );

        self::assertSame($expression->compile()(21), $expression(21));
    }

    public function testAndAllCombinesPredicates(): void
    {
        $param = new ParameterExpression('n', 'int', 0);
        $isPositive = new Expression(
            new BinaryExpression($param, '>', new ConstantExpression(0, '0'), 'bool'),
            [$param],
            fn(int $n): bool => $n > 0,
            'bool',
        );
        $isSmall = new Expression(
            new BinaryExpression($param, '<', new ConstantExpression(10, '10'), 'bool'),
            [$param],
            fn(int $n): bool => $n < 10,
            'bool',
        );

        $both = Expression::andAll($isPositive, $isSmall);
        $either = Expression::orAll($isPositive, $isSmall);

        self::assertSame('&&', $both->body->operator);
        self::assertTrue($both(5));
        self::assertFalse($both(50));
        self::assertSame('||', $either->body->operator);
        self::assertTrue($either(50));
    }

    public function testCompileReturnsCallable(): void
    {
        $param = new ParameterExpression('value', 'string', 0);
        $body = new ConstantExpression('prefix-', 'string');
        $expression = new Expression(
            $body,
            [$param],
            fn(string $value): string => 'prefix-' . $value,
            'string',
        );

        $compiled = $expression->compile();
        self::assertSame('prefix-tyhp', $compiled('tyhp'));
    }

    public function testVisitorTraversesExpressionTree(): void
    {
        $left = new ConstantExpression(1, 'int');
        $right = new ConstantExpression(2, 'int');
        $body = new BinaryExpression($left, '+', $right, 'int');
        $expression = new Expression($body, [], fn(): int => 3, 'int');

        $visitor = new class extends ExpressionVisitor {
            public int $visitCount = 0;

            public function visitParameter(ParameterExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitPropertyAccess(\Tyhp\Expression\PropertyAccessExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitNullSafeAccess(\Tyhp\Expression\NullSafeAccessExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitMethodCall(\Tyhp\Expression\MethodCallExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitStaticMethodCall(\Tyhp\Expression\StaticMethodCallExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitBinary(BinaryExpression $node): mixed
            {
                $this->visitCount++;
                $this->visit($node->left);
                $this->visit($node->right);
                return null;
            }

            public function visitUnary(\Tyhp\Expression\UnaryExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitConstant(ConstantExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitTernary(\Tyhp\Expression\TernaryExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitCoalesce(\Tyhp\Expression\CoalesceExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitArrayAccess(\Tyhp\Expression\ArrayAccessExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitCast(\Tyhp\Expression\CastExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }

            public function visitNew(\Tyhp\Expression\NewExpression $node): mixed
            {
                $this->visitCount++;
                return null;
            }
        };

        $visitor->visit($expression->body);
        self::assertSame(3, $visitor->visitCount);
    }
}
