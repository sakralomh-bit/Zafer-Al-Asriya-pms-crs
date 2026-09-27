<?php

declare(strict_types=1);

namespace App\Shared\Money;

use App\Shared\Domain\DomainRuleViolation;

/**
 * An ISO 4217-shaped currency code.
 *
 * This is deliberately NOT an enum of supported currencies.
 *
 * Blocker `C-06` (single currency or multi-currency, and whether FX enters the
 * ledger at all) is NOT CONFIRMED as of 2026-09-27. An enum would present a
 * currency policy decision as a code artifact. This value object therefore
 * validates only the ISO 4217 *shape* — three uppercase letters — and asserts
 * nothing about which currencies the business permits.
 *
 * "SAR" is not special-cased anywhere in the domain.
 *
 * @see docs/BLOCKER-STATUS.md C-06
 * @see docs/ADR/0006-money-representation-bcmath-decimal.md
 */
final class Currency
{
    private const PATTERN = '/^[A-Z]{3}$/';

    private function __construct(
        public readonly string $code,
    ) {
    }

    /**
     * @throws DomainRuleViolation when the code is not three uppercase letters
     */
    public static function fromCode(string $code): self
    {
        $normalised = strtoupper(trim($code));

        if (preg_match(self::PATTERN, $normalised) !== 1) {
            throw DomainRuleViolation::validationFailed(
                field: 'currency',
                message: 'A currency must be a three-letter ISO 4217 code.',
            );
        }

        return new self($normalised);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
