<?php

declare(strict_types=1);

namespace App\Shared\Money;

/**
 * A rounding mode that a caller must supply EXPLICITLY.
 *
 * Blocker `C-04` (rounding stage, rounding mode, storage precision and scale) is
 * NOT CONFIRMED as of 2026-09-27. The modes below are the candidate set named in
 * `ADR-0006`; the case names exist so a policy can be expressed and tested, NOT
 * because any of them has been approved.
 *
 * There is deliberately NO default. `Money::round()` will not run without a
 * policy, and `config('money.rounding_policy')` ships empty on purpose.
 *
 * @see docs/BLOCKER-STATUS.md C-04
 */
enum RoundingPolicy: string
{
    case HalfUp = 'HALF_UP';
    case HalfDown = 'HALF_DOWN';
    case HalfEven = 'HALF_EVEN';
    case HalfUpAwayFromZero = 'HALF_UP_AWAY_FROM_ZERO';
    case Floor = 'FLOOR';
    case Ceiling = 'CEILING';
    case TowardZero = 'TOWARD_ZERO';

    /**
     * Offset, in units, added to the truncated value before truncating again.
     * `$negative === true` means the magnitude is rounded and the sign reapplied.
     */
    public function increment(int $scale, bool $negative): string
    {
        $half = bcdiv('1', '2', $scale + 1);
        $one = bcadd('1', '0', $scale + 1);

        $offset = match ($this) {
            self::HalfUp, self::HalfUpAwayFromZero => $half,
            self::HalfDown => $negative ? bcsub('0', $half, $scale + 1) : $half,
            self::HalfEven => '0',
            self::Floor => $negative ? '0' : bcsub($one, $half, $scale + 1),
            self::Ceiling => $negative ? bcsub('0', $one, $scale + 1) : $one,
            self::TowardZero => '0',
        };

        return $offset;
    }
}
