<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expr;
use Tyhp\Expression;
use Tyhp\Expression\ConstantExpression;
use Tyhp\Expression\EvalBacker;
use Tyhp\Expression\EvalClosure;
use Tyhp\Expression\ExpressionInterpreter;
use Tyhp\Expression\ExpressionNode;
use Tyhp\Expression\ExpressionSerializer;
use Tyhp\Expression\ExpressionVisitor;
use Tyhp\Expression\MethodCallExpression;
use Tyhp\Expression\NullSafeAccessExpression;
use Tyhp\Expression\NullSafeMethodCallExpression;
use Tyhp\Expression\ParameterExpression;
use Tyhp\Expression\PropertyAccessExpression;

class NullSafeMethodCallTest extends TestCase
{
    protected function setUp(): void
    {
        EvalClosure::$compilations = 0;
        EvalClosure::$lastSource = '';
        NullSafeCounter::$calls = 0;
    }

    public function testNullSafeCallTreeShape(): void
    {
        [$body] = self::addressFormat();

        self::assertInstanceOf(NullSafeMethodCallExpression::class, $body);
        self::assertSame('nullSafeMethodCall', $body->nodeType);
        self::assertSame('format', $body->method);
        self::assertSame('?string', $body->type);
        self::assertInstanceOf(PropertyAccessExpression::class, $body->object);
    }

    public function testNullSafeCallEvaluatesBothWays(): void
    {
        [$body, $user] = self::addressFormat();
        $withAddress = new NullSafeUser(new NullSafeAddress('Paris'));
        $withoutAddress = new NullSafeUser(null);

        self::assertSame('Paris!', ExpressionInterpreter::evaluate($body, [$user], $withAddress));
        self::assertNull(ExpressionInterpreter::evaluate($body, [$user], $withoutAddress));

        $compiled = EvalBacker::tryCompile($body, [$user]);
        self::assertNotNull($compiled);
        self::assertStringContainsString('?->format(', EvalClosure::$lastSource);
        self::assertSame('Paris!', $compiled($withAddress));
        self::assertNull($compiled($withoutAddress));
    }

    public function testNullSafeCallSkipsArgumentsAndTheRestOfTheChain(): void
    {
        // fn ($u) => $u->address?->withSuffix($u->counter->bump())->city
        $user = new ParameterExpression('u', NullSafeUser::class, 0);
        $body = new PropertyAccessExpression(
            new NullSafeMethodCallExpression(
                new PropertyAccessExpression($user, 'address', '?' . NullSafeAddress::class),
                'withSuffix',
                [new MethodCallExpression(new PropertyAccessExpression($user, 'counter'), 'bump', [], 'string')],
                NullSafeAddress::class,
            ),
            'city',
            '?string',
        );
        $withoutAddress = new NullSafeUser(null);

        self::assertNull(ExpressionInterpreter::evaluate($body, [$user], $withoutAddress));
        self::assertSame(0, NullSafeCounter::$calls);

        $compiled = EvalBacker::tryCompile($body, [$user]);
        self::assertNotNull($compiled);
        self::assertNull($compiled($withoutAddress));
        self::assertSame(0, NullSafeCounter::$calls);

        $withAddress = new NullSafeUser(new NullSafeAddress('Oslo'));
        self::assertSame('Oslo#1', ExpressionInterpreter::evaluate($body, [$user], $withAddress));
        self::assertSame('Oslo#2', $compiled($withAddress));
    }

    public function testNullSafePropertyShortCircuitsTheRestOfThePropertyChain(): void
    {
        // fn ($u) => $u->address?->owner->name
        $user = new ParameterExpression('u', NullSafeUser::class, 0);
        $body = new PropertyAccessExpression(
            new NullSafeAccessExpression(new PropertyAccessExpression($user, 'address'), 'owner'),
            'name',
        );
        $withoutAddress = new NullSafeUser(null);

        self::assertNull(ExpressionInterpreter::evaluate($body, [$user], $withoutAddress));
        $compiled = EvalBacker::tryCompile($body, [$user]);
        self::assertNotNull($compiled);
        self::assertNull($compiled($withoutAddress));
    }

    public function testChainEndsAtCoalesce(): void
    {
        // fn ($u) => $u->address?->format() ?? 'none'
        [$call, $user] = self::addressFormat();
        $body = new \Tyhp\Expression\CoalesceExpression($call, new ConstantExpression('none', 'string'), 'string');

        self::assertSame('none', ExpressionInterpreter::evaluate($body, [$user], new NullSafeUser(null)));
        $compiled = EvalBacker::tryCompile($body, [$user]);
        self::assertNotNull($compiled);
        self::assertSame('none', $compiled(new NullSafeUser(null)));
        self::assertSame('Rome!', $compiled(new NullSafeUser(new NullSafeAddress('Rome'))));
    }

    public function testFluentNullSafeCall(): void
    {
        $expression = Expr::param('u', NullSafeUser::class)->address->nullSafe()->call('format');

        self::assertInstanceOf(NullSafeMethodCallExpression::class, $expression->body);
        self::assertNull($expression(new NullSafeUser(null)));
        self::assertSame('Lima!', $expression(new NullSafeUser(new NullSafeAddress('Lima'))));

        $plain = Expr::param('u', NullSafeUser::class)->address->call('format');
        self::assertInstanceOf(MethodCallExpression::class, $plain->body);
    }

    public function testSerializerWritesNullSafeCall(): void
    {
        [$body, $user] = self::addressFormat();
        $expression = new Expression($body, [$user], null, '?string');
        $decoded = \json_decode(ExpressionSerializer::toJson($expression), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('nullSafeMethodCall', $decoded['body']['nodeType']);
        self::assertSame('format', $decoded['body']['method']);
        self::assertSame([], $decoded['body']['arguments']);

        $plain = new Expression(
            new MethodCallExpression($body->object, 'format', [], '?string'),
            [$user],
            null,
            '?string',
        );
        self::assertFalse($expression->equals($plain));
        [$sameBody, $sameUser] = self::addressFormat();
        self::assertTrue($expression->equals(new Expression($sameBody, [$sameUser], null, '?string')));
    }

    public function testVisitorDefaultReturnsNull(): void
    {
        [$body] = self::addressFormat();
        $visitor = new class extends ExpressionVisitor {
        };

        self::assertNull($body->accept($visitor));
    }

    /**
     * fn ($u) => $u->address?->format()
     *
     * @return array{NullSafeMethodCallExpression, ParameterExpression}
     */
    private static function addressFormat(): array
    {
        $user = new ParameterExpression('u', NullSafeUser::class, 0);
        $body = new NullSafeMethodCallExpression(
            new PropertyAccessExpression($user, 'address', '?' . NullSafeAddress::class),
            'format',
            [],
            '?string',
        );

        return [$body, $user];
    }
}

class NullSafeCounter
{
    public static int $calls = 0;

    public function bump(): string
    {
        self::$calls++;

        return '#' . self::$calls;
    }
}

class NullSafeAddress
{
    public ?NullSafeUser $owner = null;

    public function __construct(public string $city)
    {
    }

    public function format(): string
    {
        return $this->city . '!';
    }

    public function withSuffix(string $suffix): self
    {
        return new self($this->city . $suffix);
    }
}

class NullSafeUser
{
    public NullSafeCounter $counter;

    public string $name = 'n';

    public function __construct(public ?NullSafeAddress $address)
    {
        $this->counter = new NullSafeCounter();
    }
}
