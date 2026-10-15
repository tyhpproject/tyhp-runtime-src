<?php

declare(strict_types=1);

namespace Tyhp\Tests\Decimal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tyhp\Backend\BcMathBackend;
use Tyhp\Backend\DecimalBackend;
use Tyhp\Backend\GmpBackend;
use Tyhp\Backend\IntegerScaledBackend;
use Tyhp\Backend\PhpDecimalBackend;
use Tyhp\Contracts\DecimalConvertible;
use Tyhp\Decimal;

/**
 * Runs the same expectations against every backend whose extension is installed.
 * A backend whose extension is missing is reported as skipped.
 */
final class DecimalBackendTest extends TestCase
{
    private string $originalBackendName = '';

    protected function setUp(): void
    {
        $this->originalBackendName = Decimal::getBackendName();
    }

    protected function tearDown(): void
    {
        if ($this->originalBackendName !== '') {
            Decimal::setBackend(self::createBackend($this->originalBackendName));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function backendNames(): array
    {
        return [
            'bcmath' => ['bcmath'],
            'gmp' => ['gmp'],
            'integer-scaled' => ['integer-scaled'],
            'decimal' => ['decimal'],
        ];
    }

    private static function createBackend(string $name): DecimalBackend
    {
        switch ($name) {
            case 'bcmath':
                return new BcMathBackend();
            case 'gmp':
                return new GmpBackend();
            case 'decimal':
                return new PhpDecimalBackend();
            default:
                // The pure-PHP backend raises a one-time E_USER_NOTICE when constructed.
                \set_error_handler(static fn(): bool => true, \E_USER_NOTICE);
                try {
                    return new IntegerScaledBackend();
                } finally {
                    \restore_error_handler();
                }
        }
    }

    private function useBackend(string $name): void
    {
        $extension = $name === 'integer-scaled' ? null : $name;
        if ($extension !== null && !\extension_loaded($extension)) {
            $this->markTestSkipped("ext-{$extension} is not installed");
        }

        Decimal::setBackend(self::createBackend($name));
        $this->assertSame($name, Decimal::getBackendName());
    }

    // -------------------------------------------------------
    // Leading zeros and signs (gmp used to parse them as octal)
    // -------------------------------------------------------

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function arithmeticCases(): array
    {
        $rows = [
            'fraction with leading zero' => ['add', '0.0825', '1', '1.0825'],
            'octal-looking int' => ['add', '010', '1', '11'],
            'invalid octal digit' => ['add', '09', '1', '10'],
            'three leading zeros' => ['add', '007', '1', '8'],
            'leading zeros in fraction operand' => ['add', '007.50', '1', '8.50'],
            'negative fraction' => ['add', '-0.05', '0.01', '-0.04'],
            'negative fraction result' => ['subtract', '0.01', '0.05', '-0.04'],
            'zero plus zero' => ['add', '0', '0', '0'],
            'negative zero plus zero' => ['add', '-0', '0', '0'],
            'multiply with leading zero fraction' => ['multiply', '19.99', '0.0825', '1.6491'],
            'divide by leading zero fraction' => ['divide', '1', '0.05', '20.00'],
            'modulo of octal-looking int' => ['modulo', '010', '3', '1'],
            'small fraction' => ['add', '0.001', '0.002', '0.003'],
        ];

        $cases = [];
        foreach (self::backendNames() as $name => [$backend]) {
            foreach ($rows as $label => [$op, $a, $b, $expected]) {
                $cases["{$name}: {$label}"] = [$backend, $op, $a, $b, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('arithmeticCases')]
    public function testArithmeticWithLeadingZeros(string $backend, string $op, string $a, string $b, string $expected): void
    {
        $this->useBackend($backend);

        $result = (new Decimal($a))->$op($b);

        $this->assertSame($expected, $result->value, "{$op}({$a}, {$b})");
    }

    #[DataProvider('backendNames')]
    public function testFloatSumDoesNotParseAsOctal(string $backend): void
    {
        $this->useBackend($backend);

        $sum = (new Decimal(0.1))->add(new Decimal(0.2));

        $this->assertSame('0.10000000000000000555', (new Decimal(0.1))->value);
        $this->assertSame('0.30000000000000001665', $sum->value);
        $this->assertSame(20, $sum->scale);
    }

    #[DataProvider('backendNames')]
    public function testPowerAndSqrtWithLeadingZeroFractions(string $backend): void
    {
        $this->useBackend($backend);

        $this->assertSame('0.0025', (new Decimal('0.0500'))->power(2)->value);
        $this->assertSame('0.2500', (new Decimal('0.0625'))->sqrt()->value);
        $this->assertSame('1.0000', (new Decimal('1.0000'))->power(0)->value);
    }

    #[DataProvider('backendNames')]
    public function testCompareWithLeadingZeros(string $backend): void
    {
        $this->useBackend($backend);

        $this->assertSame(0, (new Decimal('007'))->compareTo('7'));
        $this->assertSame(-1, (new Decimal('0.0825'))->compareTo('0.09'));
        $this->assertSame(1, (new Decimal('010'))->compareTo('9'));
        $this->assertSame(0, (new Decimal('-0'))->compareTo('0'));
    }

    /**
     * Every backend must agree with bcmath on a grid of operands, including result scales
     * below an operand's own scale: the exact result is computed first, then truncated.
     */
    #[DataProvider('backendNames')]
    public function testBackendsAgreeWithBcMath(string $backend): void
    {
        if (!\extension_loaded('bcmath')) {
            $this->markTestSkipped('ext-bcmath is not installed');
        }

        $operands = [
            '0', '007', '0.0825', '-0.05', '1.5', '10', '-3.75', '0.001', '123456789.987654321', '-0',
            '7.00', '1.99', '1.00', '0.55', '-1.99', '99.995',
        ];
        $reference = new BcMathBackend();

        $this->useBackend($backend);
        $actual = self::createBackend($backend);

        foreach ($operands as $a) {
            foreach ($operands as $b) {
                foreach ([0, 1, 2, 6] as $scale) {
                    foreach (['add', 'subtract', 'multiply', 'divide', 'modulo'] as $op) {
                        if (($op === 'divide' || $op === 'modulo') && self::isZero($b)) {
                            continue;
                        }

                        $this->assertSame(
                            self::canonical($reference->$op($a, $b, $scale)),
                            self::canonical($actual->$op($a, $b, $scale)),
                            "{$op}({$a}, {$b}, {$scale}) on {$backend}",
                        );
                    }

                    $this->assertSame(
                        $reference->compare($a, $b, $scale),
                        $actual->compare($a, $b, $scale),
                        "compare({$a}, {$b}, {$scale}) on {$backend}",
                    );
                }
            }

            foreach ([0, 1, 2, 6] as $scale) {
                foreach ([0, 1, 2, 3] as $exponent) {
                    $this->assertSame(
                        self::canonical($reference->power($a, $exponent, $scale)),
                        self::canonical($actual->power($a, $exponent, $scale)),
                        "power({$a}, {$exponent}, {$scale}) on {$backend}",
                    );
                }

                if (\str_starts_with($a, '-') && !self::isZero($a)) {
                    continue;
                }

                $this->assertSame(
                    self::canonical($reference->sqrt($a, $scale)),
                    self::canonical($actual->sqrt($a, $scale)),
                    "sqrt({$a}, {$scale}) on {$backend}",
                );
            }
        }
    }

    /**
     * @return array<string, array{string, string, string, string, int, string}>
     */
    public static function lowScaleCases(): array
    {
        $rows = [
            'divide 7.00 / 1.99 at scale 0' => ['divide', '7.00', '1.99', 0, '3'],
            'divide 1.00 / 0.55 at scale 1' => ['divide', '1.00', '0.55', 1, '1.8'],
            'divide by a small divisor is not zero' => ['divide', '1', '0.001', 2, '1000.00'],
            'divide negative dividend' => ['divide', '-7.00', '1.99', 0, '-3'],
            'add below operand scale' => ['add', '1.55', '1.55', 1, '3.1'],
            'subtract below operand scale' => ['subtract', '1.55', '-1.55', 1, '3.1'],
            'multiply below operand scale' => ['multiply', '1.55', '1.55', 1, '2.4'],
            'modulo below operand scale' => ['modulo', '7.00', '1.99', 0, '1'],
            'modulo keeps the sign of the dividend' => ['modulo', '-7.5', '2', 0, '-1'],
            'power below operand scale' => ['power', '1.5', '3', 0, '3'],
            'sqrt below operand scale' => ['sqrt', '1.99', '', 1, '1.4'],
        ];

        $cases = [];
        foreach (self::backendNames() as $name => [$backend]) {
            foreach ($rows as $label => [$op, $a, $b, $scale, $expected]) {
                $cases["{$name}: {$label}"] = [$backend, $op, $a, $b, $scale, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('lowScaleCases')]
    public function testResultScaleBelowOperandScaleTruncatesTheExactResult(
        string $backend,
        string $op,
        string $a,
        string $b,
        int $scale,
        string $expected,
    ): void {
        $this->useBackend($backend);
        $actual = self::createBackend($backend);

        $result = match ($op) {
            'power' => $actual->power($a, (int) $b, $scale),
            'sqrt' => $actual->sqrt($a, $scale),
            default => $actual->$op($a, $b, $scale),
        };

        $this->assertSame($expected, $result, "{$op}({$a}, {$b}, {$scale}) on {$backend}");
    }

    #[DataProvider('backendNames')]
    public function testDecimalDivideWithScaleBelowOperandScale(string $backend): void
    {
        $this->useBackend($backend);

        $this->assertSame('3', (new Decimal('7.00'))->divide('1.99', 0)->value);
        $this->assertSame('1.8', (new Decimal('1.00'))->divide('0.55', 1)->value);
    }

    #[DataProvider('backendNames')]
    public function testZeroDivisorIsDetectedOnTheExactValue(string $backend): void
    {
        $this->useBackend($backend);
        $actual = self::createBackend($backend);

        foreach (['0', '0.000', '-0.00'] as $zero) {
            try {
                $actual->divide('1', $zero, 2);
                $this->fail("divide by {$zero} should throw");
            } catch (\DivisionByZeroError) {
                $this->addToAssertionCount(1);
            }

            try {
                $actual->modulo('1', $zero, 2);
                $this->fail("modulo by {$zero} should throw");
            } catch (\DivisionByZeroError) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('1000.00', $actual->divide('1', '0.001', 2));
        $this->assertSame('0.00', $actual->modulo('0.001', '0.002', 2));
    }

    #[DataProvider('backendNames')]
    public function testSqrtOfNegativeValueThrowsEvenWhenItRoundsToZero(string $backend): void
    {
        $this->useBackend($backend);
        $actual = self::createBackend($backend);

        $this->expectException(\InvalidArgumentException::class);
        $actual->sqrt('-0.001', 2);
    }

    private static function isZero(string $value): bool
    {
        return \ltrim(\str_replace(['.', '-'], '', $value), '0') === '';
    }

    /**
     * Backends may differ in how they print a zero ("-0.00" vs "0.00"); the digits must match.
     */
    private static function canonical(string $value): string
    {
        $isZero = \ltrim(\str_replace(['.', '-'], '', $value), '0') === '';

        return $isZero ? \ltrim($value, '-') : $value;
    }

    // -------------------------------------------------------
    // sqrt truncates at scale 0
    // -------------------------------------------------------

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function sqrtCases(): array
    {
        $rows = [
            ['2', 0, '1'],
            ['3', 0, '1'],
            ['4', 0, '2'],
            ['8', 0, '2'],
            ['15', 0, '3'],
            ['16', 0, '4'],
            ['0', 0, '0'],
            ['1', 0, '1'],
            ['2', 6, '1.414213'],
            ['99999999999999999999', 0, '9999999999'],
            ['1000000000000000000000000000000', 0, '1000000000000000'],
            ['0.5', 0, '0'],
        ];

        $cases = [];
        foreach (self::backendNames() as $name => [$backend]) {
            foreach ($rows as [$value, $scale, $expected]) {
                $cases["{$name}: sqrt({$value}, {$scale})"] = [$backend, $value, $scale, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('sqrtCases')]
    public function testSqrtTruncates(string $backend, string $value, int $scale, string $expected): void
    {
        $this->useBackend($backend);

        $this->assertSame($expected, (new Decimal($value))->sqrt($scale)->value);
    }

    #[DataProvider('backendNames')]
    public function testSqrtDefaultScaleOfWholeNumberTruncates(string $backend): void
    {
        $this->useBackend($backend);

        $this->assertSame('1', (new Decimal('2'))->sqrt()->value);
    }

    // -------------------------------------------------------
    // round(): ties need every discarded digit to be 5 then zeros
    // -------------------------------------------------------

    /**
     * @return array<string, array{string, int, int, string}>
     */
    public static function roundingCases(): array
    {
        return [
            // Above a tie: every mode rounds away from zero.
            '2.5451 p2 half-up' => ['2.5451', 2, \PHP_ROUND_HALF_UP, '2.55'],
            '2.5451 p2 half-down' => ['2.5451', 2, \PHP_ROUND_HALF_DOWN, '2.55'],
            '2.5451 p2 half-even' => ['2.5451', 2, \PHP_ROUND_HALF_EVEN, '2.55'],
            '2.5451 p2 half-odd' => ['2.5451', 2, \PHP_ROUND_HALF_ODD, '2.55'],
            '2.5051 p2 half-even' => ['2.5051', 2, \PHP_ROUND_HALF_EVEN, '2.51'],
            '2.5051 p2 half-down' => ['2.5051', 2, \PHP_ROUND_HALF_DOWN, '2.51'],
            '2.5051 p2 half-odd' => ['2.5051', 2, \PHP_ROUND_HALF_ODD, '2.51'],
            '2.50001 p0 half-even' => ['2.50001', 0, \PHP_ROUND_HALF_EVEN, '3'],
            // Exact ties.
            '2.545 p2 half-up' => ['2.545', 2, \PHP_ROUND_HALF_UP, '2.55'],
            '2.545 p2 half-down' => ['2.545', 2, \PHP_ROUND_HALF_DOWN, '2.54'],
            '2.545 p2 half-even' => ['2.545', 2, \PHP_ROUND_HALF_EVEN, '2.54'],
            '2.545 p2 half-odd' => ['2.545', 2, \PHP_ROUND_HALF_ODD, '2.55'],
            '2.555 p2 half-up' => ['2.555', 2, \PHP_ROUND_HALF_UP, '2.56'],
            '2.555 p2 half-down' => ['2.555', 2, \PHP_ROUND_HALF_DOWN, '2.55'],
            '2.555 p2 half-even' => ['2.555', 2, \PHP_ROUND_HALF_EVEN, '2.56'],
            '2.555 p2 half-odd' => ['2.555', 2, \PHP_ROUND_HALF_ODD, '2.55'],
            '2.5450000 p2 half-even' => ['2.5450000', 2, \PHP_ROUND_HALF_EVEN, '2.54'],
            '2.5450000 p2 half-odd' => ['2.5450000', 2, \PHP_ROUND_HALF_ODD, '2.55'],
            // Below a tie.
            '2.5449 p2 half-up' => ['2.5449', 2, \PHP_ROUND_HALF_UP, '2.54'],
            '2.5449 p2 half-even' => ['2.5449', 2, \PHP_ROUND_HALF_EVEN, '2.54'],
            '2.5549 p2 half-odd' => ['2.5549', 2, \PHP_ROUND_HALF_ODD, '2.55'],
            '2.5499 p2 half-up' => ['2.5499', 2, \PHP_ROUND_HALF_UP, '2.55'],
            // Negative values: ties go toward or away from zero by mode.
            '-2.5451 p2 half-even' => ['-2.5451', 2, \PHP_ROUND_HALF_EVEN, '-2.55'],
            '-2.5451 p2 half-down' => ['-2.5451', 2, \PHP_ROUND_HALF_DOWN, '-2.55'],
            '-2.545 p2 half-up' => ['-2.545', 2, \PHP_ROUND_HALF_UP, '-2.55'],
            '-2.545 p2 half-down' => ['-2.545', 2, \PHP_ROUND_HALF_DOWN, '-2.54'],
            '-2.545 p2 half-even' => ['-2.545', 2, \PHP_ROUND_HALF_EVEN, '-2.54'],
            '-2.555 p2 half-even' => ['-2.555', 2, \PHP_ROUND_HALF_EVEN, '-2.56'],
            '-2.555 p2 half-odd' => ['-2.555', 2, \PHP_ROUND_HALF_ODD, '-2.55'],
            // Whole-number results.
            '2.5 p0 half-even' => ['2.5', 0, \PHP_ROUND_HALF_EVEN, '2'],
            '3.5 p0 half-even' => ['3.5', 0, \PHP_ROUND_HALF_EVEN, '4'],
            '2.5 p0 half-odd' => ['2.5', 0, \PHP_ROUND_HALF_ODD, '3'],
            '3.5 p0 half-odd' => ['3.5', 0, \PHP_ROUND_HALF_ODD, '3'],
            '2.5 p0 half-down' => ['2.5', 0, \PHP_ROUND_HALF_DOWN, '2'],
            '2.51 p0 half-even' => ['2.51', 0, \PHP_ROUND_HALF_EVEN, '3'],
            '2.51 p0 half-down' => ['2.51', 0, \PHP_ROUND_HALF_DOWN, '3'],
            '2.50 p0 half-down' => ['2.50', 0, \PHP_ROUND_HALF_DOWN, '2'],
            '0.5 p0 half-even' => ['0.5', 0, \PHP_ROUND_HALF_EVEN, '0'],
            '-0.5 p0 half-even' => ['-0.5', 0, \PHP_ROUND_HALF_EVEN, '0'],
            '1.5 p0 half-even' => ['1.5', 0, \PHP_ROUND_HALF_EVEN, '2'],
            // Carry.
            '9.995 p2 half-even' => ['9.995', 2, \PHP_ROUND_HALF_EVEN, '10.00'],
            '9.9951 p2 half-down' => ['9.9951', 2, \PHP_ROUND_HALF_DOWN, '10.00'],
            '9.995 p2 half-down' => ['9.995', 2, \PHP_ROUND_HALF_DOWN, '9.99'],
            '99.5 p0 half-up' => ['99.5', 0, \PHP_ROUND_HALF_UP, '100'],
            // Padding when there is nothing to discard.
            '1.5 p4 half-even' => ['1.5', 4, \PHP_ROUND_HALF_EVEN, '1.5000'],
        ];
    }

    #[DataProvider('roundingCases')]
    public function testRoundModes(string $value, int $precision, int $mode, string $expected): void
    {
        $result = (new Decimal($value))->round($precision, $mode);

        $this->assertSame($expected, $result->value);
        $this->assertSame($precision, $result->scale);
    }

    public function testRoundNegativePrecisionThrowsValueErrorWithoutWarning(): void
    {
        $warnings = [];
        \set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        try {
            $this->expectException(\ValueError::class);
            $this->expectExceptionMessageMatches('/precision/i');
            (new Decimal('1.5'))->round(-1);
        } finally {
            \restore_error_handler();
            $this->assertSame([], $warnings);
        }
    }

    public function testRoundNegativePrecisionOnWholeNumberThrows(): void
    {
        $this->expectException(\ValueError::class);
        (new Decimal('15'))->round(-1);
    }

    public function testNegativeScaleThrows(): void
    {
        $this->expectException(\ValueError::class);
        (new Decimal('1.5'))->withScale(-1);
    }

    public function testNegativeConstructorScaleThrows(): void
    {
        $this->expectException(\ValueError::class);
        new Decimal('1.5', -2);
    }

    // -------------------------------------------------------
    // withScale / constructor scale reduction under half-up
    // -------------------------------------------------------

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function halfUpScaleCases(): array
    {
        return [
            '1.5 -> 0' => ['1.5', 0, '2'],
            '1.4 -> 0' => ['1.4', 0, '1'],
            '1.75 -> 0' => ['1.75', 0, '2'],
            '0.5 -> 0' => ['0.5', 0, '1'],
            '9.5 -> 0' => ['9.5', 0, '10'],
            '99.5 -> 0' => ['99.5', 0, '100'],
            '-1.5 -> 0' => ['-1.5', 0, '-2'],
            '-0.4 -> 0' => ['-0.4', 0, '0'],
            '1.25 -> 1' => ['1.25', 1, '1.3'],
            '1.005 -> 2' => ['1.005', 2, '1.01'],
            '5.1 -> 4' => ['5.1', 4, '5.1000'],
        ];
    }

    #[DataProvider('halfUpScaleCases')]
    public function testWithScaleRoundsHalfUp(string $value, int $scale, string $expected): void
    {
        $result = (new Decimal($value))->withScale($scale);

        $this->assertSame($expected, $result->value);
        $this->assertSame($scale, $result->scale);
    }

    #[DataProvider('halfUpScaleCases')]
    public function testConstructorScaleRoundsHalfUp(string $value, int $scale, string $expected): void
    {
        $this->assertSame($expected, (new Decimal($value, $scale))->value);
    }

    public function testAvgOfWholeNumbersRoundsHalfUp(): void
    {
        $this->assertSame('2', Decimal::avg(new Decimal('1'), new Decimal('2'))->value);
    }

    // -------------------------------------------------------
    // DecimalConvertible with an explicit scale
    // -------------------------------------------------------

    private static function convertible(string $value): DecimalConvertible
    {
        return new class ($value) implements DecimalConvertible {
            public function __construct(private readonly string $value) {}

            public function __toDecimal(): Decimal
            {
                return new Decimal($this->value);
            }
        };
    }

    public function testConvertibleWithScaleReformatsValue(): void
    {
        $widened = new Decimal(self::convertible('1.5'), 4);
        $this->assertSame(4, $widened->scale);
        $this->assertSame('1.5000', $widened->value);

        $rounded = new Decimal(self::convertible('99.999'), 2);
        $this->assertSame(2, $rounded->scale);
        $this->assertSame('100.00', $rounded->value);

        $whole = new Decimal(self::convertible('2.5'), 0);
        $this->assertSame(0, $whole->scale);
        $this->assertSame('3', $whole->value);
    }

    public function testConvertibleWithoutScaleKeepsValueAndScale(): void
    {
        $d = new Decimal(self::convertible('12.340'));

        $this->assertSame('12.340', $d->value);
        $this->assertSame(3, $d->scale);
    }

    public function testDecimalWithScaleReformatsValue(): void
    {
        $d = new Decimal(new Decimal('3.14159'), 2);

        $this->assertSame('3.14', $d->value);
        $this->assertSame(2, $d->scale);
    }

    // -------------------------------------------------------
    // Generated operator methods
    // -------------------------------------------------------

    public function testNegateOperatorMethod(): void
    {
        $this->assertSame('-5.50', Decimal::__negate(new Decimal('5.50'))->value);
        $this->assertSame('5.50', Decimal::__negate(new Decimal('-5.50'))->value);
    }

    public function testGreaterThanOperatorMethod(): void
    {
        $this->assertFalse(Decimal::__isGreaterThan(new Decimal('5.00'), new Decimal('5.0')));
        $this->assertFalse(Decimal::__isGreaterThan(new Decimal('5.01'), new Decimal('5.1')));
        $this->assertTrue(Decimal::__isGreaterThan(new Decimal('5.2'), '5.19'));
    }

    public function testOperatorMethodsAcceptCoercibleRightOperands(): void
    {
        $this->assertSame('6.5', Decimal::__add(new Decimal('5.0'), new Decimal('1.5'))->value);
        $this->assertSame('3.5', Decimal::__subtract(new Decimal('5.0'), '1.5')->value);
        $this->assertSame('7.5', Decimal::__multiply(new Decimal('5.0'), new Decimal('1.5'))->value);
        $this->assertTrue(Decimal::__isLessThan(new Decimal('1.0'), '1.5'));
        $this->assertTrue(Decimal::__isGreaterThanOrEqual(new Decimal('2.0'), 2));
        $this->assertSame(1, Decimal::__compare(new Decimal('2.0'), 1.5));
    }
}
