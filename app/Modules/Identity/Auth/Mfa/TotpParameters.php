<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth\Mfa;

/**
 * The RFC 6238 parameters this deployment verifies against.
 *
 * A value object rather than three loose integers, because these three numbers
 * are meaningless apart and a partial set is a misconfiguration that produces a
 * challenge no authenticator app can answer — which is indistinguishable, from
 * the user's side, from a broken second factor.
 *
 * `SecurityPolicy` bounds every field, so an out-of-range value is refused by
 * name at the policy boundary rather than silently clamped here.
 */
final readonly class TotpParameters
{
    public function __construct(
        /**
         * Code length. 6 and 8 are the two values RFC 6238 implementations in
         * the wild agree on; 7 is a rounding artefact and no mainstream app
         * offers it.
         */
        public int $digits = 6,

        /**
         * Seconds each code covers. 30 is the RFC default and what every
         * authenticator app assumes. A shorter period shrinks the window in which
         * a shoulder-surfed code is still valid; a longer one widens it.
         */
        public int $periodSeconds = 30,

        /**
         * How many periods either side of the current one are accepted, to
         * tolerate clock skew between the server and a staff handset.
         *
         * Deliberately small. Each extra period is an extra opportunity to replay
         * a code that has already been observed, so this is a skew allowance and
         * not a tolerance: a server and a phone on NTP are well inside one
         * period of each other, and a window of 2 or more would accept a code
         * from 60 seconds ago.
         */
        public int $window = 1,
    ) {}

    /**
     * How many counter values `TotpVerifier` will try.
     *
     * One current period plus `window` either side. The COUNT rather than the
     * range, so the `+/- window` decision lives in exactly one place instead of
     * being re-derived at each call site.
     */
    public function candidateCounters(): int
    {
        return 1 + (2 * $this->window);
    }
}
