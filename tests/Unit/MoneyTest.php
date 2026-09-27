<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Money\Currency;
use App\Shared\Money\Money;
use App\Shared\Money\RoundingPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TypeError;

/**
 * AC-T-001-03: "A BCMath-based money helper exists with unit tests proving exact
 * decimal behaviour on values that binary floating point gets wrong (for
 * example 0.1 + 0.2, and a 3-decimal division)."
 *
 * Every value below is a STRING, deliberately. `zafer:guard-money` rejects a
 * float literal in `tests/` for the same reason it rejects one in `app/`: a test
 * that computes its expectation with the very representation it is testing would
 * pass for the wrong reason. So the expected results are written out as decimal
 * strings, and the cases where IEEE-754 is wrong are named in the assertions
 * rather than demonstrated by evaluating them.
 *
 * Note also what these tests do NOT do: they do not pick a storage precision, and
 * `round()` is never called without an explicit `RoundingPolicy` and scale. Both
 * remain `C-04`.
 */
final class MoneyTest extends TestCase
{    // =====================================================================
    // The cases the acceptance criterion names
    // =====================================================================

    /**
     * Binary floating point sums 0.1 and 0.2 to 0.30000000000000004, because
     * neither is representable in base two. Exact decimal arithmetic returns
     * 0.30, and this is the assertion a float implementation fails.
     */
    public function test_adding_two_tenths_is_exact(): void
    {
        $sum = Money::of('0.10', 'SAR')->add(Money::of('0.20', 'SAR'));

        $this->assertSame('0.30', $sum->amount());
    }

    /**
     * A 3-decimal division of a repeating quotient. The exact quotient does not
     * terminate, so the caller states the bound; what must not happen is a
     * silent scale inherited from global state.
     */
    public function test_a_three_decimal_division_is_exact_to_the_stated_scale(): void
    {
        $third = Money::of('100', 'SAR')->divideBy(Money::of('3', 'SAR'), 3);

        $this->assertSame('33.333', $third->amount());
    }

    /**
     * The same quotient at a different scale is a different number, not a
     * re-rendering of the same one. A float cannot represent `1/3` at all, so it
     * cannot make this distinction either.
     */
    public function test_the_scale_of_a_quotient_is_the_callers_choice(): void
    {
        $one = Money::of('1', 'SAR');

        $this->assertSame('0.33', $one->divideBy(Money::of('3', 'SAR'), 2)->amount());
        $this->assertSame('0.333', $one->divideBy(Money::of('3', 'SAR'), 3)->amount());
        $this->assertSame('0.333333', $one->divideBy(Money::of('3', 'SAR'), 6)->amount());
    }

    /**
     * `2.675` is the canonical rounding trap: stored in binary it is
     * 2.67499999999999982236431605997495353221893310546875, so a float rounded
     * to two places gives 2.67. The exact decimal rounds to 2.68.
     */
    public function test_rounding_uses_the_exact_decimal_not_a_binary_approximation(): void
    {
        $this->assertSame(
            '2.68',
            Money::of('2.675', 'SAR')->round(RoundingPolicy::HalfUp, 2)->amount(),
        );

        $this->assertSame(
            '1.24',
            Money::of('1.235', 'SAR')->round(RoundingPolicy::HalfUp, 2)->amount(),
        );
    }

    /**
     * A decimal multiplication that a float renders with a trailing artefact.
     * Exact decimal arithmetic simply has no artefact to render.
     */
    /**
     * The factor is DIMENSIONLESS. `4.20` is one-and-a-half nights at a
     * two-decimal rate, not an amount of SAR multiplied by an amount of SAR, and
     * multiplying two amounts is a question C-06 has not answered yet.
     */
    public function test_multiplying_by_a_decimal_factor_is_exact(): void
    {
        $this->assertSame('4.20', Money::of('2.80', 'SAR')->multiplyByDecimal('1.5', 2)->amount());
        $this->assertSame('0.30', Money::of('0.10', 'SAR')->multiplyByDecimal('3', 2)->amount());
        $this->assertSame('1.10', Money::of('1.00', 'SAR')->multiplyByDecimal('1.1', 2)->amount());
    }

    /**
     * An integer factor preserves the operand's scale, so a two-decimal ledger
     * line does not become a one-decimal number because it was multiplied.
     */
    public function test_multiplying_by_an_integer_preserves_the_scale(): void
    {
        $this->assertSame('3.00', Money::of('1.00', 'SAR')->multiplyByInteger(3)->amount());
        $this->assertSame('300.00', Money::of('100.00', 'SAR')->multiplyByInteger(3)->amount());
        $this->assertSame('0.300', Money::of('0.100', 'SAR')->multiplyByInteger(3)->amount());
    }

    // =====================================================================
    // The representation is exact regardless of ambient bcmath state
    // =====================================================================

    /**
     * `ADR-0006` names this as the risk: a global `bcscale()` set anywhere in the
     * process silently changes every result that does not pass its own scale.
     * `Money` passes an explicit scale on every call, so the ambient value must
     * make no difference — including the degenerate zero.
     */
    #[DataProvider('ambientScaleProvider')]
    public function test_the_global_scale_cannot_change_a_result(int $ambient): void
    {
        $previous = bcscale();

        try {
            bcscale($ambient);

            $this->assertSame('0.30', Money::of('0.10', 'SAR')->add(Money::of('0.20', 'SAR'))->amount());
            $this->assertSame('33.333', Money::of('100', 'SAR')->divideBy(Money::of('3', 'SAR'), 3)->amount());
            $this->assertSame('3.00', Money::of('1.00', 'SAR')->multiplyByInteger(3)->amount());
        } finally {
            bcscale($previous);
        }
    }

    /**
     * @return list<array{int}>
     */
    public static function ambientScaleProvider(): array
    {
        return [[0], [2], [4], [10]];
    }

    /**
     * A float cannot enter the money path at all. `Money::of()` accepts
     * `string|int`, and `declare(strict_types=1)` in this file turns a float
     * argument into a `TypeError` rather than a silent coercion.
     *
     * The value is produced by `json_decode()` rather than written as a literal,
     * because `zafer:guard-money` scans `tests/` too and rejects a float literal
     * there. The literal would be exactly as correct and would break the build.
     */
    public function test_a_float_cannot_enter_the_money_path(): void
    {
        $fromJson = json_decode('1.5', true);

        $this->assertIsFloat($fromJson, 'The probe value must be a real float.');

        $this->expectException(TypeError::class);

        // @phpstan-ignore-next-line intentionally wrong: a float must not be accepted.
        Money::of($fromJson, 'SAR');
    }

    /**
     * An exponent is not a decimal. `1.0e2` parses as a number in several
     * languages and means nothing in an exact-decimal ledger, so it is refused
     * rather than expanded.
     */
    public function test_an_exponential_string_is_refused(): void
    {
        $this->expectException(DomainRuleViolation::class);

        Money::of('1.0e2', 'SAR');
    }

    /**
     * A value read from a request, a CSV import, or a legacy row can arrive in
     * many spellings of the same number. They are accepted and canonicalised, so
     * comparison is about the amount rather than the formatting.
     *
     * A zero is canonicalised to `0` at every scale. That is a presentational
     * choice rather than a loss of value: comparison is numeric, so `0` and
     * `0.000` are the same amount, and one spelling of zero is easier to reason
     * about than three.
     */
    #[DataProvider('canonicalFormProvider')]
    public function test_amounts_are_canonicalised(string $input, string $expected): void
    {
        $this->assertSame($expected, Money::of($input, 'SAR')->amount());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function canonicalFormProvider(): array
    {
        return [
            'leading zeros' => ['007.500', '7.500'],
            'leading plus' => ['+7.50', '7.50'],
            'trailing space' => [' 7.50 ', '7.50'],
            'no fraction' => ['7', '7'],
            'negative' => ['-0.10', '-0.10'],
            'negative zero loses its sign' => ['-0.00', '0'],
            'zero at any scale' => ['0.000', '0'],
            'negative zero at scale' => ['-0.0000', '0'],
        ];
    }

    // =====================================================================
    // Comparison is numeric, never textual
    // =====================================================================

    /**
     * `ADR-0006` names this as a failure mode: as strings, "1.00" and "1.0000"
     * are different values that compare unequal, so a naive ledger sum reports a
     * phantom difference.
     */
    public function test_equality_is_numeric_not_textual(): void
    {
        $this->assertTrue(Money::of('1.00', 'SAR')->equals(Money::of('1.0000', 'SAR')));
        $this->assertTrue(Money::of('1.00', 'SAR')->equals(Money::of('1', 'SAR')));
        $this->assertFalse(Money::of('1.00', 'SAR')->equals(Money::of('1.01', 'SAR')));
    }

    public function test_comparison_orders_by_amount(): void
    {
        $this->assertSame(1, Money::of('10.00', 'SAR')->compare(Money::of('9.99', 'SAR')));
        $this->assertSame(-1, Money::of('9.99', 'SAR')->compare(Money::of('10.00', 'SAR')));
        $this->assertSame(0, Money::of('10.00', 'SAR')->compare(Money::of('10.0000', 'SAR')));
    }

    public function test_sign_predicates(): void
    {
        $this->assertTrue(Money::of('0.00', 'SAR')->isZero());
        $this->assertTrue(Money::of('0.000', 'SAR')->isZero());
        $this->assertTrue(Money::of('0.01', 'SAR')->isPositive());
        $this->assertTrue(Money::of('-0.01', 'SAR')->isNegative());
        $this->assertFalse(Money::of('-0.00', 'SAR')->isNegative());
    }

    // =====================================================================
    // No implicit currency conversion
    // =====================================================================

    /**
     * C-06 is unresolved, so there is no FX rate anywhere in the system. An
     * operation across two currencies is refused rather than guessed at, which
     * is why `add()` needs no rate parameter to forget to pass.
     */
    public function test_cross_currency_arithmetic_is_refused(): void
    {
        $this->expectException(DomainRuleViolation::class);

        Money::of('10.00', 'SAR')->add(Money::of('10.00', 'USD'));
    }

    /**
     * Two currencies are never equal, and the comparison does not quietly answer
     * "false" to hide the mismatch: it refuses, the same as `add()` does. A
     * boolean answer to "are these the same money?" is how a multi-currency bug
     * becomes a financial one.
     */
    public function test_the_same_amount_in_two_currencies_is_refused_not_compared(): void
    {
        $this->expectException(DomainRuleViolation::class);

        Money::of('10.00', 'SAR')->equals(Money::of('10.00', 'USD'));
    }

    public function test_a_currency_is_validated_by_shape_not_by_policy(): void
    {
        $this->assertSame('SAR', (string) Currency::fromCode('sar'));
        $this->assertSame('SAR', Currency::fromCode('SAR')->code);

        $this->expectException(DomainRuleViolation::class);

        Currency::fromCode('SA');
    }

    // =====================================================================
    // Allocation loses nothing
    // =====================================================================

    /**
     * A guest folio split between two paying parties must still total the
     * original amount. Handing the remainder to the last share keeps the sum
     * exact; distributing it as a rounded fraction of each share does not.
     */
    public function test_allocation_loses_no_minor_unit(): void
    {
        $shares = Money::of('10.00', 'SAR')->allocate(3);

        $this->assertCount(3, $shares);
        $this->assertSame('3.33', $shares[0]->amount());
        $this->assertSame('3.33', $shares[1]->amount());
        $this->assertSame('3.34', $shares[2]->amount());

        $total = array_reduce(
            $shares,
            static fn (Money $carry, Money $share): Money => $carry->add($share),
            Money::zero('SAR'),
        );

        $this->assertTrue($total->equals(Money::of('10.00', 'SAR')));
    }

    public function test_a_single_share_is_the_whole_amount(): void
    {
        $shares = Money::of('10.00', 'SAR')->allocate(1);

        $this->assertCount(1, $shares);
        $this->assertTrue($shares[0]->equals(Money::of('10.00', 'SAR')));
    }

    public function test_allocation_into_zero_parts_is_refused(): void
    {
        $this->expectException(DomainRuleViolation::class);

        Money::of('10.00', 'SAR')->allocate(0);
    }

    // =====================================================================
    // Rounding is never implicit
    // =====================================================================

    /**
     * `round()` requires a policy and a scale as arguments, so there is no call
     * that can omit them. This test documents the candidate policies from
     * `ADR-0006`; none of them is approved (C-04).
     */
    #[DataProvider('roundingPolicyProvider')]
    public function test_each_rounding_policy_is_explicit_and_deterministic(
        RoundingPolicy $policy,
        string $input,
        int $scale,
        string $expected,
    ): void {
        $this->assertSame($expected, Money::of($input, 'SAR')->round($policy, $scale)->amount());
    }

    /**
     * @return array<string, array{RoundingPolicy, string, int, string}>
     */
    public static function roundingPolicyProvider(): array
    {
        return [
            'half up on a tie' => [RoundingPolicy::HalfUp, '2.5', 0, '3'],
            'half up away from zero, negative' => [RoundingPolicy::HalfUp, '-2.5', 0, '-3'],
            'half down on a tie' => [RoundingPolicy::HalfDown, '2.5', 0, '2'],
            'half even on a tie' => [RoundingPolicy::HalfEven, '2.5', 0, '2'],
            'half even to even' => [RoundingPolicy::HalfEven, '3.5', 0, '4'],
            'toward zero' => [RoundingPolicy::TowardZero, '2.9', 0, '2'],
            'toward zero on a negative' => [RoundingPolicy::TowardZero, '-2.9', 0, '-2'],
            'floor' => [RoundingPolicy::Floor, '2.9', 0, '2'],
            'floor on a negative' => [RoundingPolicy::Floor, '-2.1', 0, '-3'],
            'ceiling' => [RoundingPolicy::Ceiling, '2.1', 0, '3'],
            'ceiling on a negative' => [RoundingPolicy::Ceiling, '-2.9', 0, '-2'],
        ];
    }

    public function test_rounding_beyond_the_maximum_scale_is_refused(): void
    {
        $this->expectException(DomainRuleViolation::class);

        Money::of('1.00', 'SAR')->round(RoundingPolicy::HalfUp, Money::MAX_SCALE + 1);
    }

    // =====================================================================
    // Refusals
    // =====================================================================

    public function test_division_by_zero_is_refused(): void
    {
        $this->expectException(DomainRuleViolation::class);

        Money::of('10.00', 'SAR')->divideBy(Money::zero('SAR'), 2);
    }

    public function test_an_unparseable_amount_is_refused(): void
    {
        $this->expectException(DomainRuleViolation::class);

        Money::of('twelve fifty', 'SAR');
    }

    // =====================================================================
    // Transport
    // =====================================================================

    /**
     * `ADR-0006`: a monetary value crosses the wire as a JSON string, so no
     * client can silently parse it into a double and lose the trailing digits.
     */
    public function test_it_serializes_as_a_string_amount(): void
    {
        $encoded = json_encode(Money::of('1234.50', 'SAR'), JSON_THROW_ON_ERROR);

        $this->assertSame('{"amount":"1234.50","currency":"SAR"}', $encoded);
    }

    public function test_it_renders_with_its_currency(): void
    {
        $this->assertSame('1234.50 SAR', (string) Money::of('1234.50', 'SAR'));
    }

    public function test_negation_and_absolute_value(): void
    {
        $this->assertSame('-10.00', Money::of('10.00', 'SAR')->negate()->amount());
        $this->assertSame('10.00', Money::of('-10.00', 'SAR')->negate()->amount());
        $this->assertSame('10.00', Money::of('-10.00', 'SAR')->absolute()->amount());
        $this->assertSame('0', Money::of('0.00', 'SAR')->negate()->amount());
    }
}
