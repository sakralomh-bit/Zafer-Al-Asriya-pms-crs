<?php

declare(strict_types=1);

namespace App\Shared\Money;

use App\Shared\Domain\DomainRuleViolation;
use JsonSerializable;
use Stringable;

/**
 * An exact decimal monetary amount. `ADR-0006`, `D-006`, `SEC-017`, `BUS-006`.
 *
 * Invariants enforced by construction:
 *
 *  1. The amount is held as a **string** in canonical decimal form. Binary
 *     floating point is never involved, and cannot enter: the constructor accepts
 *     only `string|int`, and every file in `app/` carries `declare(strict_types=1)`
 *     so a `float` argument is a `TypeError`, not a silent coercion.
 *  2. Every BCMath call passes an **explicit** scale. The global `bcscale()` is
 *     never read and never set, so a scale leaking in from elsewhere cannot
 *     change a result. (`ADR-0006` risk section requires exactly this.)
 *  3. Construction and arithmetic never round. A result is either exact or it
 *     is rejected.
 *  4. Operations that cannot be exact (`divide`, `multiply` to a chosen scale)
 *     require the scale to be stated by the caller.
 *  5. `round()` has **no default policy**. Blocker `C-04` is unresolved, so
 *     rounding without an explicit `RoundingPolicy` is a hard failure.
 *  6. Currencies must match. There is no implicit conversion and no FX rate.
 *
 * @see docs/ADR/0006-money-representation-bcmath-decimal.md
 * @see docs/BLOCKER-STATUS.md C-04, C-06
 */
final class Money implements JsonSerializable, Stringable
{
    /**
     * Hard ceiling on any requested scale. This is a representation guard against
     * an unbounded computation, NOT a financial policy value. The storage
     * precision and scale remain `TBD` (C-04).
     */
    public const MAX_SCALE = 30;

    private function __construct(
        private readonly string $amount,
        private readonly Currency $currency,
    ) {
    }

    /**
     * @param string|int $amount Canonical decimal string such as "1234.56", "-0.10", "7"
     *
     * @throws DomainRuleViolation on an unparseable amount
     */
    public static function of(string|int $amount, Currency|string $currency): self
    {
        $currency = $currency instanceof Currency ? $currency : Currency::fromCode($currency);

        $raw = is_int($amount) ? (string) $amount : trim($amount);

        if (preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/', $raw, $matches) !== 1) {
            throw DomainRuleViolation::validationFailed(
                field: 'amount',
                message: 'A monetary amount must be a plain decimal string; '
                    .'binary floating point is not an accepted source.',
            );
        }

        $sign = $matches[1] === '-' ? '-' : '';
        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[3] ?? '';

        $canonical = $fraction === '' ? $integer : $integer . '.' . $fraction;
        $canonical = self::stripNegativeZero($canonical);

        return new self($sign . $canonical, $currency);
    }

    public static function zero(Currency|string $currency): self
    {
        return self::of('0', $currency);
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    public function scale(): int
    {
        $dot = strpos($this->amount, '.');

        return $dot === false ? 0 : strlen($this->amount) - $dot - 1;
    }

    /**
     * Exact: adding two exact decimals never loses a digit.
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        $scale = max($this->scale(), $other->scale());

        return new self(
            self::stripNegativeZero(bcadd($this->amount, $other->amount, $scale)),
            $this->currency,
        );
    }

    /**
     * Exact: subtracting two exact decimals never loses a digit.
     */
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $scale = max($this->scale(), $other->scale());

        return new self(
            self::stripNegativeZero(bcsub($this->amount, $other->amount, $scale)),
            $this->currency,
        );
    }

    /**
     * Multiplication by an exact integer factor is exact and needs no scale.
     */
    public function multiplyByInteger(int $factor): self
    {
        if ($factor === 0) {
            return self::zero($this->currency);
        }

        return new self(
            self::stripNegativeZero(bcmul($this->amount, (string) $factor, 0)),
            $this->currency,
        );
    }

    /**
     * Multiplication by a decimal factor. The caller MUST state the result scale.
     *
     * This is a precision limit, not the authorised business rounding stage.
     * `ADR-0006` requires the rounding stage to be a single, separately
     * configured point (C-04); this method does not round, it only bounds the
     * number of retained digits and the caller is accountable for the choice.
     */
    public function multiplyByDecimal(self $factor, int $scale): self
    {
        $this->assertScale($scale);

        if (preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/', trim($factor), $matches) !== 1) {
            throw DomainRuleViolation::validationFailed(
                field: 'factor',
                message: 'A decimal factor must be a plain decimal string.',
            );
        }

        $canonical = ltrim($matches[2], '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        if (isset($matches[3]) && $matches[3] !== '') {
            $canonical .= '.' . $matches[3];
        }

        return new self(
            self::stripNegativeZero(bcmul($this->amount, $matches[1] . $canonical, $scale)),
            $this->currency,
        );
    }

    /**
     * Division. A non-terminating quotient is bounded by the caller-stated scale.
     *
     * The bound is a precision limit only. Where business rounding is required,
     * the caller must additionally and deliberately call `round()` with an
     * explicit `RoundingPolicy`.
     */
    public function divideBy(self $divisor, int $scale): self
    {
        $this->assertSameCurrency($divisor);
        $this->assertScale($scale);

        if (bccomp($divisor->amount, '0', self::MAX_SCALE) === 0) {
            throw DomainRuleViolation::businessRuleViolation(
                'MONEY_DIVISION_BY_ZERO',
                'A monetary amount cannot be divided by zero.',
            );
        }

        return new self(
            self::stripNegativeZero(bcdiv($this->amount, $divisor->amount, $scale)),
            $this->currency,
        );
    }

    /**
     * Apply an EXPLICIT rounding policy at an EXPLICIT scale.
     *
     * There is no default policy and no default scale, because both are blocker
     * `C-04`. Nothing in the codebase may round a monetary value without a
     * caller stating which policy it is applying and where.
     */
    public function round(RoundingPolicy $policy, int $scale): self
    {
        $this->assertScale($scale);

        $negative = str_starts_with($this->amount, '-');
        $magnitude = $negative ? substr($this->amount, 1) : $this->amount;

        $dot = strpos($magnitude, '.');
        $integer = $dot === false ? $magnitude : substr($magnitude, 0, $dot);
        $fraction = $dot === false ? '' : substr($magnitude, $dot + 1);

        // Pad so position $scale and $scale+1 always exist.
        $fraction = str_pad($fraction, $scale + 1, '0');
        $kept = $scale > 0 ? substr($fraction, 0, $scale) : '';
        $nextDigit = (int) $fraction[$scale];
        $tailIsSignificant = ltrim(substr($fraction, $scale + 1), '0') !== '';
        $anyRemainder = $nextDigit > 0 || $tailIsSignificant;

        $increment = match ($policy) {
            RoundingPolicy::HalfUp, RoundingPolicy::HalfUpAwayFromZero => $nextDigit >= 5,
            RoundingPolicy::HalfDown => $nextDigit > 5 || ($nextDigit === 5 && $tailIsSignificant),
            RoundingPolicy::HalfEven => $nextDigit > 5
                || ($nextDigit === 5 && $tailIsSignificant)
                || ($nextDigit === 5 && $kept !== '' && ((int) substr($kept, -1)) % 2 === 1),
            RoundingPolicy::Floor => $anyRemainder && $negative,
            RoundingPolicy::Ceiling => $anyRemainder && ! $negative,
            RoundingPolicy::TowardZero => false,
        };

        $base = $scale > 0 ? $integer . '.' . $kept : $integer;
        $result = $increment ? bcadd($base, '1', $scale) : $base;

        return new self(self::stripNegativeZero(($negative ? '-' : '') . $result), $this->currency);
    }

    public function negate(): self
    {
        if (bccomp($this->amount, '0', self::MAX_SCALE) === 0) {
            return $this;
        }

        return new self(
            str_starts_with($this->amount, '-')
                ? substr($this->amount, 1)
                : '-' . $this->amount,
            $this->currency,
        );
    }

    public function absolute(): self
    {
        return new self(ltrim($this->amount, '-'), $this->currency);
    }

    /**
     * Numeric comparison. String comparison is WRONG: "1.00" !== "1.0000" while
     * the two are the same amount. This is the risk `ADR-0006` names.
     */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return bccomp($this->amount, $other->amount, self::MAX_SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', self::MAX_SCALE) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', self::MAX_SCALE) > 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', self::MAX_SCALE) < 0;
    }

    /**
     * Split into $parts shares, returning exact amounts plus a remainder share.
     *
     * The remainder is the largest share, which keeps the sum exactly equal to
     * the original with no lost and no invented minor units.
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw DomainRuleViolation::businessRuleViolation(
                'MONEY_ALLOCATION_INVALID',
                'A monetary amount must be allocated into at least one part.',
            );
        }

        if ($parts === 1) {
            return [$this];
        }

        $scale = $this->scale();
        $unit = bcdiv($this->amount, (string) $parts, $scale);
        $allocated = self::of($unit, $this->currency);
        $remainder = $this->subtract($allocated->multiplyByInteger($parts - 1));

        $shares = array_fill(0, $parts, $allocated);
        $shares[$parts - 1] = $remainder;

        return $shares;
    }

    /**
     * String transport, per `ADR-0006`: a monetary value is a JSON string, never
     * a JSON number, so no client can silently parse it into a float.
     *
     * @return array{amount: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency->code,
        ];
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency->code;
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw DomainRuleViolation::businessRuleViolation(
                'MONEY_CURRENCY_MISMATCH',
                'A monetary operation requires both amounts to share one currency. '
                    .'Cross-currency conversion requires an explicit exchange rate '
                    .'service, which does not exist because C-06 is unresolved.',
            );
        }
    }

    private function assertScale(int $scale): void
    {
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw DomainRuleViolation::businessRuleViolation(
                'MONEY_SCALE_OUT_OF_RANGE',
                'A monetary scale must be between 0 and ' . self::MAX_SCALE . '.',
            );
        }
    }

    private static function stripNegativeZero(string $canonical): string
    {
        $dot = strpos($canonical, '.');
        $integer = $dot === false ? $canonical : substr($canonical, 0, $dot);
        $fraction = $dot === false ? '' : substr($canonical, $dot + 1);

        if (trim($integer, '0') === '' && ($fraction === '' || trim($fraction, '0') === '')) {
            return '0';
        }

        return $canonical;
    }
}
