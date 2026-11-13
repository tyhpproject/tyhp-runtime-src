<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expression;
use Tyhp\Expression\BinaryExpression;
use Tyhp\Expression\ConstantExpression;
use Tyhp\Expression\ExpressionInterpreter;
use Tyhp\Expression\ExpressionVisitor;
use Tyhp\Expression\ParameterExpression;
use Tyhp\Expression\PropertyAccessExpression;
use Tyhp\Expression\TernaryExpression;
use Tyhp\ExtModule;

class ExpressionTest extends TestCase
{
    public function testLambdaReadsTheReturnTypeFromTheClosure(): void
    {
        $param = new ParameterExpression('value', 'int', 0);
        $expression = Expression::lambda()(
            new ConstantExpression(1, 'int'),
            [$param],
            fn(int $value): int => $value + 1,
        );

        self::assertSame('int', $expression->returnType);
        self::assertSame(43, $expression(42));
    }

    public function testLambdaReadsTheReturnTypeFromTheBodyWhenTheClosureOmitsIt(): void
    {
        $param = new ParameterExpression('n', 'int', 0);
        $expression = Expression::lambda()(
            new BinaryExpression($param, '>', new ConstantExpression(0, '0'), 'bool'),
            [$param],
            fn(int $n) => $n > 0,
        );

        self::assertSame('bool', $expression->returnType);
        self::assertTrue($expression(1));
    }

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

    public function testFromClosureReadsAComparison(): void
    {
        $fn = fn ($u) => $u->age > 1;
        $expr = Expression::fromClosure($fn, null);
        self::assertInstanceOf(BinaryExpression::class, $expr->body);
        $body = $expr->body;
        self::assertSame('>', $body->operator);
        self::assertInstanceOf(PropertyAccessExpression::class, $body->left);
        self::assertSame('age', $body->left->property);
        self::assertInstanceOf(ConstantExpression::class, $body->right);
        self::assertSame(1, $body->right->value);

        $young = (object) ['age' => 0];
        $old = (object) ['age' => 4];
        self::assertSame($fn($young), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $young));
        self::assertSame($fn($old), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $old));
    }

    public function testFromClosureAppliesTheExpectedShape(): void
    {
        $fn = fn ($u) => $u->age > 1;
        $expr = Expression::fromClosure($fn, 'callable(\\User): bool');

        self::assertSame('User', $expr->parameters[0]->paramType);
        self::assertSame('bool', $expr->returnType);
    }

    public function testFromClosureKeepsADeclaredParameterType(): void
    {
        $fn = fn (object $u) => $u->age > 1;
        $expr = Expression::fromClosure($fn, 'callable(\\User): string');

        self::assertSame('object', $expr->parameters[0]->paramType);
        self::assertSame('bool', $expr->returnType);
    }

    public function testFromClosureRejectsADeclaredReturnType(): void
    {
        $this->assertOpcodeOrSourceRejection(fn (object $u): bool => $u->age > 1);
    }

    public function testFromClosureAcceptsADeclaredReturnTypePhpDoesNotCheck(): void
    {
        $mixed = Expression::fromClosure(fn ($u): mixed => $u->age, null);
        self::assertInstanceOf(PropertyAccessExpression::class, $mixed->body);

        $literal = Expression::fromClosure(fn (): int => 1, null);
        self::assertInstanceOf(ConstantExpression::class, $literal->body);
        self::assertSame(1, $literal->body->value);

        $nullable = Expression::fromClosure(function (): ?string { return null; }, null);
        self::assertInstanceOf(ConstantExpression::class, $nullable->body);
        self::assertNull($nullable->body->value);
    }

    public function testFromClosureRejectsADeclaredReturnTypeOnANestedLambda(): void
    {
        $this->assertOpcodeOrSourceRejection(fn ($u) => fn ($v): bool => $v > 1);
    }

    public function testFromClosureRejectsADeclaredReturnTypeThatTheLiteralDoesNotMatch(): void
    {
        $this->assertOpcodeOrSourceRejection(fn (): string => 1);
    }

    public function testFromClosureRejectsADefaultParameterValue(): void
    {
        $this->assertOpcodeOrSourceRejection(fn ($u, $n = 1) => $u->age > $n);
    }

    public function testFromClosureFoldsALiteralArray(): void
    {
        $expr = Expression::fromClosure(fn () => [1, 'a' => 2], null);

        self::assertInstanceOf(ConstantExpression::class, $expr->body);
        self::assertSame([1, 'a' => 2], $expr->body->value);
    }

    public function testFromClosureReadsTheOpcodesOnlyWhenTheModuleIsLoaded(): void
    {
        // The optimizer reorders `5 < $u->age` to `$u->age > 5`. Only the opcode walk sees the
        // reordered form; the source walk keeps the order the closure was written in.
        $expr = Expression::fromClosure(fn ($u) => 5 < $u->age, null);

        self::assertInstanceOf(BinaryExpression::class, $expr->body);
        if (ExtModule::available()) {
            self::assertSame('>', $expr->body->operator);
            self::assertInstanceOf(PropertyAccessExpression::class, $expr->body->left);
        } else {
            self::assertSame('<', $expr->body->operator);
            self::assertInstanceOf(ConstantExpression::class, $expr->body->left);
        }
    }

    public function testFromClosureNeverReadsAnotherClosureOnTheSameLines(): void
    {
        $pair = [fn ($u) => $u->a, fn ($u) => $u->b];

        if (ExtModule::available()) {
            self::assertSame('a', Expression::fromClosure($pair[0], null)->body->property);
            self::assertSame('b', Expression::fromClosure($pair[1], null)->body->property);

            return;
        }

        // Two closures on one line with the same parameters cannot be told apart from source.
        $this->expectException(\InvalidArgumentException::class);
        Expression::fromClosure($pair[1], null);
    }

    public function testFromClosureCopiesACapturedValue(): void
    {
        $min = 18;
        $fn = fn ($u) => $u->age > $min;
        $expr = Expression::fromClosure($fn, null);
        self::assertInstanceOf(BinaryExpression::class, $expr->body);
        self::assertInstanceOf(ConstantExpression::class, $expr->body->right);
        self::assertSame(18, $expr->body->right->value);

        $person = (object) ['age' => 20];
        self::assertSame($fn($person), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $person));
    }

    public function testFromClosureFoldsIntegerArithmetic(): void
    {
        $fn = fn () => 1 + 2;
        $expr = Expression::fromClosure($fn, null);

        self::assertSame(3, ExpressionInterpreter::evaluate($expr->body, $expr->parameters));
        self::assertSame(3, $fn());
    }

    public function testFromClosureAgreesWhenTheOptimizerReordersAComparison(): void
    {
        $fn = fn ($u) => 1 < $u->age;
        $expr = Expression::fromClosure($fn, null);
        $young = (object) ['age' => 0];
        $old = (object) ['age' => 4];
        self::assertSame($fn($young), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $young));
        self::assertSame($fn($old), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $old));
    }

    public function testFromClosureAgreesOnABooleanChain(): void
    {
        $fn = fn ($u) => $u->a && $u->b && $u->c;
        $expr = Expression::fromClosure($fn, null);
        $yes = (object) ['a' => true, 'b' => true, 'c' => true];
        $no = (object) ['a' => true, 'b' => false, 'c' => true];
        self::assertSame($fn($yes), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $yes));
        self::assertSame($fn($no), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $no));
    }

    public function testFromClosureAgreesOnADoubleNot(): void
    {
        $fn = fn ($u) => !!$u->ok;
        $expr = Expression::fromClosure($fn, null);
        $yes = (object) ['ok' => 1];
        $no = (object) ['ok' => 0];
        self::assertSame($fn($yes), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $yes));
        self::assertSame($fn($no), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $no));
    }

    public function testFromClosureAgreesOnElvis(): void
    {
        $fn = fn ($u) => $u->nick ?: 'none';
        $expr = Expression::fromClosure($fn, null);
        self::assertInstanceOf(TernaryExpression::class, $expr->body);
        self::assertNull($expr->body->ifTrue);
        $named = (object) ['nick' => 'Ada'];
        $blank = (object) ['nick' => ''];
        self::assertSame($fn($named), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $named));
        self::assertSame($fn($blank), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $blank));
    }

    public function testFromClosureAgreesOnIsNull(): void
    {
        $fn = fn ($u) => \is_null($u->name);
        $expr = Expression::fromClosure($fn, null);
        $named = (object) ['name' => 'Ada'];
        $blank = (object) ['name' => null];
        self::assertSame($fn($named), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $named));
        self::assertSame($fn($blank), ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $blank));
    }

    public function testFromClosureBuildsANestedLambda(): void
    {
        $fn = fn ($u) => fn ($v) => $u->age > $v;
        $expr = Expression::fromClosure($fn, null);
        $person = (object) ['age' => 5];
        $built = ExpressionInterpreter::evaluate($expr->body, $expr->parameters, $person);
        self::assertInstanceOf(\Closure::class, $built);
        self::assertTrue($built(3));
        self::assertFalse($built(9));
    }

    public function testFromClosureRejectsABodyWithoutAReturn(): void
    {
        try {
            Expression::fromClosure(function () {}, null);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                'closureTree needs a closure body that returns an expression',
                $e->getMessage(),
            );
        }
    }

    public function testFromClosureRejectsUseBindings(): void
    {
        $n = 1;
        try {
            Expression::fromClosure(function () use ($n) { return $n; }, null);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                'closureTree does not accept use bindings; write an arrow function',
                $e->getMessage(),
            );
        }
    }

    public function testFromClosureRejectsAssignmentWithoutTokenizingAfterTheWalker(): void
    {
        $this->assertOpcodeOrSourceRejection(fn ($u) => $u->age = 1);
    }

    public function testFromClosureRejectsIncrement(): void
    {
        $this->assertOpcodeOrSourceRejection(fn ($u) => ++$u->age);
    }

    public function testFromClosureRejectsMatch(): void
    {
        $this->assertOpcodeOrSourceRejection(fn ($u) => match ($u->age) { 1 => true, default => false });
    }

    private function assertOpcodeOrSourceRejection(\Closure $fn): void
    {
        try {
            Expression::fromClosure($fn, null);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            if (ExtModule::available()) {
                self::assertStringContainsString('opcode', $e->getMessage());
            } else {
                self::assertStringNotContainsString('opcode', $e->getMessage());
            }
        }
    }
}
