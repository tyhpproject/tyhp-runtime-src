<?php

declare(strict_types=1);

namespace Tyhp\Tests\Lambda;

use PHPUnit\Framework\TestCase;
use Tyhp\Expr;
use Tyhp\Expression\EvalClosure;
use Tyhp\PropertyPath;

function tyhp_lambda_eval_marker(): string
{
    $GLOBALS['tyhp_lambda_eval_attack'] = true;
    $GLOBALS['tyhp_lambda_eval_marker_calls'] = ($GLOBALS['tyhp_lambda_eval_marker_calls'] ?? 0) + 1;
    return 'marked';
}

class EvalBackingTest extends TestCase
{
    protected function setUp(): void
    {
        EvalClosure::$compilations = 0;
        EvalClosure::$lastSource = '';
        $GLOBALS['tyhp_lambda_eval_attack'] = false;
        $GLOBALS['tyhp_lambda_eval_marker_calls'] = 0;
    }

    public function testFluentExpressionWithoutBackedByInvokes(): void
    {
        $person = Expr::param('person', EvalPerson::class);

        $adult = $person->age->gte(18);
        self::assertTrue($adult(new EvalPerson(36)));
        self::assertFalse($adult(new EvalPerson(17)));
        self::assertSame(1, EvalClosure::$compilations);

        $age = Expr::param('person', EvalPerson::class)->age;
        self::assertSame(36, $age(new EvalPerson(36)));

        $city = Expr::param('person', EvalPerson::class)->address->nullSafe()->city;
        self::assertSame('Paris', $city(new EvalPerson(address: new EvalAddress('Paris'))));
        self::assertNull($city(new EvalPerson()));
        self::assertSame('Paris', $city(new EvalPerson(address: new EvalAddress('Paris'))));

        $fallback = Expr::param('person', EvalPerson::class)
            ->address
            ->nullSafe()
            ->city
            ->coalesce('missing');
        self::assertSame('Paris', $fallback(new EvalPerson(address: new EvalAddress('Paris'))));
        self::assertSame('missing', $fallback(new EvalPerson()));
        self::assertSame('missing', $fallback(new EvalPerson(address: new EvalAddress(null))));

        $path = PropertyPath::on(EvalPerson::class)->address->nullSafe()->city;
        self::assertSame('Paris', $path->getValue(new EvalPerson(address: new EvalAddress('Paris'))));
        self::assertNull($path->getValue(new EvalPerson()));

        self::assertSame(5, EvalClosure::$compilations);
        self::assertFalse($GLOBALS['tyhp_lambda_eval_attack']);
    }

    public function testCompiledClosureIsCached(): void
    {
        $expr = Expr::param('person', EvalPerson::class)->age->gte(18);
        self::assertTrue($expr(new EvalPerson(40)));
        $compiled = $expr->compile();
        self::assertFalse($compiled(new EvalPerson(10)));
        self::assertSame(1, EvalClosure::$compilations);
    }

    public function testNastyStringRoundTripsWithoutRunning(): void
    {
        $payload = "q'\"\\\n\${{\$" . "\0<?php '); \$GLOBALS['tyhp_lambda_eval_attack'] = true; \\Tyhp\\Tests\\Lambda\\tyhp_lambda_eval_marker(); // SENTINEL_eval_backer";

        $literal = Expr::literal($payload);
        self::assertSame($payload, $literal());
        self::assertStringNotContainsString('tyhp_lambda_eval_marker', EvalClosure::$lastSource);
        self::assertStringNotContainsString('tyhp_lambda_eval_attack', EvalClosure::$lastSource);
        self::assertStringNotContainsString($payload, EvalClosure::$lastSource);
        self::assertStringContainsString('use ($__bound)', EvalClosure::$lastSource);

        $compared = Expr::param('text', 'string')->eq($payload);
        self::assertTrue($compared($payload));
        self::assertFalse($compared('other'));
        self::assertStringNotContainsString('tyhp_lambda_eval_marker', EvalClosure::$lastSource);
        self::assertStringNotContainsString('SENTINEL', EvalClosure::$lastSource);

        self::assertFalse($GLOBALS['tyhp_lambda_eval_attack']);
        self::assertSame(0, $GLOBALS['tyhp_lambda_eval_marker_calls']);
    }

    public function testSemicolonNamesAreWalkedAndNotEvaluated(): void
    {
        $row = new \stdClass();
        $row->{'a;b'} = 5;
        $property = Expr::param('row', \stdClass::class)->prop('a;b');
        self::assertSame(5, $property($row));

        $method = Expr::param('box', EvalCallableBox::class)->call('a;b');
        self::assertSame('a;b', $method(new EvalCallableBox()));

        $path = PropertyPath::on(\stdClass::class)->prop('a;b');
        self::assertSame(5, $path->getValue($row));

        self::assertSame(0, EvalClosure::$compilations);
        self::assertSame('', EvalClosure::$lastSource);
        self::assertStringNotContainsString('a;b', EvalClosure::$lastSource);
    }

    public function testBackedByDoesNotEvaluate(): void
    {
        $expr = Expr::param('person', EvalPerson::class)
            ->age
            ->gte(18)
            ->backedBy(fn (EvalPerson $person): bool => false);

        self::assertFalse($expr(new EvalPerson(40)));
        self::assertFalse($expr->compile()(new EvalPerson(40)));
        self::assertSame(0, EvalClosure::$compilations);
        self::assertSame('', EvalClosure::$lastSource);
    }

    public function testObjectConstantIsWalked(): void
    {
        $object = new \stdClass();
        $object->label = 'x';
        self::assertSame($object, Expr::literal($object)());
        self::assertSame(0, EvalClosure::$compilations);
        self::assertSame('', EvalClosure::$lastSource);
    }
}

class EvalAddress
{
    public function __construct(public ?string $city)
    {
    }
}

class EvalPerson
{
    public function __construct(
        public int $age = 0,
        public string $city = '',
        public ?EvalAddress $address = null,
    ) {
    }
}

class EvalCallableBox
{
    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): string
    {
        return $name;
    }
}
