<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth\Mfa;

use Illuminate\Support\Carbon;

/**
 * RFC 6238 TOTP verification.
 *
 * A PURE, STATELESS PRIMITIVE. It takes a Base32 secret and a submitted code and
 * answers one question. It does not enrol, does not store, does not decide who
 * may enrol, does not rate-limit a challenge, and has no recovery path.
 *
 * ============================ WHY IT EXISTS ALONE ============================
 * `docs/SECURITY.md` §12.1.1 splits MFA into two rows: row 12 (mechanism) and a
 * recovery/ownership dependency that is `BLOCKED — ACCOUNTABILITY` because
 * `C-10` names no Security Owner. That is not a reason to build nothing — it is
 * a reason to build the part that needs no authority and refuse the part that
 * does.
 *
 * The verification algorithm needs no governance decision: it is a published
 * specification, and implementing it is arithmetic. What it must NOT do is
 * acquire a secret, because that is where the missing accountability lands:
 *
 *   - WHO may enrol a factor for WHOM (an operator self-enrolling is not
 *     identity proof; a colleague enrolling for you is a takeover).
 *   - HOW a staff member who lost their authenticator is verified at 02:00 by
 *     somebody, and what that verification emits to the audit trail.
 *   - HOW a secret is sealed, with which key, under which rotation policy
 *     (`H-03`).
 *
 * Inventing those would be inventing a security workflow and an accountability
 * assignment. So: `mfa_secrets` is reserved in `docs/DATA-MODEL.md` §2 and NO
 * migration creates it here, and there is no enrolment endpoint, no secret
 * store, and no recovery code path. This class is the half that is safe.
 *
 * ============================ THE CRYPTOGRAPHY ============================
 * The HMAC is `hash_hmac('sha1', $counter, $secret, true)`.
 *
 * SHA-1 is required and is not a weakness here. RFC 4226 / RFC 6238 specify
 * HMAC-SHA1, and the security of the construction rests on the HMAC PRF and the
 * 20-byte truncated output, not on SHA-1's collision resistance — a collision
 * attack finds two *messages* with one digest, and forging a code requires
 * finding a preimage for a truncated HMAC under a secret key the attacker does
 * not have. Every mainstream authenticator app computes exactly this.
 *
 * `hash_hmac` is used rather than a home-grown MAC. The one subtlety worth
 * naming: the key is the RAW Base32-decoded secret, NOT the Base32 string. Using
 * the encoded form would produce codes that no authenticator app displays, and
 * that failure looks like a user typing the wrong digits.
 */
final class TotpVerifier
{
    /**
     * The Base32 alphabet, minus padding. RFC 4648.
     */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Verify a submitted code.
     *
     * @param  string  $base32Secret  the enrolment secret, Base32, unpadded
     * @param  string  $code  what the user submitted
     * @param  TotpParameters|null  $parameters  null uses the RFC defaults
     * @param  Carbon|null  $at  null uses the current time
     *
     * Comparison is over computed codes in constant time per candidate. The
     * comparison itself is not a secret-vs-secret comparison — the attacker
     * already knows the submitted code — so `hash_equals` is used because it
     * compares the full strings rather than short-circuiting on the first
     * differing character, not because this is an authentication secret.
     */
    public function verify(
        string $base32Secret,
        string $code,
        ?TotpParameters $parameters = null,
        ?Carbon $at = null,
    ): bool {
        $parameters ??= new TotpParameters;
        $at ??= Carbon::now();

        $secret = self::base32Decode($base32Secret);

        if ($secret === null) {
            // A secret that is not valid Base32 cannot produce a code any
            // authenticator app would have shown. Refusing is correct; throwing
            // would turn a stored-data defect into a 500 on the login path.
            return false;
        }

        $submitted = self::normalise($code, $parameters->digits);

        if ($submitted === null) {
            return false;
        }

        $counter = intdiv($at->getTimestamp(), $parameters->periodSeconds);

        for ($offset = -$parameters->window; $offset <= $parameters->window; $offset++) {
            $candidate = self::codeForCounter($secret, $counter + $offset, $parameters->digits);

            if (hash_equals($candidate, $submitted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The code for one counter value — public because the same function is what
     * an enrolment flow or a test needs to produce a valid code, and writing it
     * twice is how the two copies drift apart.
     */
    public function codeAt(
        string $base32Secret,
        int $counter,
        ?TotpParameters $parameters = null,
    ): string {
        $parameters ??= new TotpParameters;

        $secret = self::base32Decode($base32Secret);

        if ($secret === null) {
            return '';
        }

        return self::codeForCounter($secret, $counter, $parameters->digits);
    }

    /**
     * The counter value for a moment in time.
     */
    public function counterAt(
        Carbon $at,
        ?TotpParameters $parameters = null,
    ): int {
        $parameters ??= new TotpParameters;

        return intdiv($at->getTimestamp(), $parameters->periodSeconds);
    }

    /**
     * RFC 4226 dynamic truncation.
     *
     * The low four bits of the LAST byte select the offset of a 4-byte window in
     * the HMAC. Taking bits from the last byte rather than the first is what
     * makes the offset cover the whole digest.
     */
    private static function codeForCounter(string $secret, int $counter, int $digits): string
    {
        // 64-bit big-endian counter, as RFC 4226 specifies. `pack('J', …)` is
        // the explicit 64-bit big-endian encoding; a 32-bit `N` would silently
        // wrap and every code would repeat after 2^32 periods, which is about
        // 4,000 years and still not a reason to get it wrong.
        $binaryCounter = pack('J', $counter);

        $hash = hash_hmac('sha1', $binaryCounter, $secret, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        // Modulo 10^digits, then left-padded. `str_pad` rather than
        // `sprintf('%0*d')` so a leading zero survives — a 6-digit code is
        // frequently `000123`, and truncating that to 4 digits is a code that
        // never matches.
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Digits only, exactly as many as the policy requires.
     *
     * Users type `123 456` and paste `123456`; both are the same code. Anything
     * that is not exactly `digits` ASCII digits is not a code, and returning null
     * for it is better than stripping to whatever happens to be left, which would
     * turn a typo into a shorter string that could still match.
     */
    private static function normalise(string $code, int $digits): ?string
    {
        $stripped = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^[0-9]{'.$digits.'}$/', $stripped) !== 1) {
            return null;
        }

        return $stripped;
    }

    /**
     * Base32 decode, or null when the input is not Base32.
     *
     * Padding (`=`) is tolerated and case is insensitive, because both are things
     * operators paste from other tools. A character outside the alphabet is an
     * error, not something to skip: skipping would shift every subsequent
     * character and produce a plausible-looking wrong answer.
     */
    private static function base32Decode(string $secret): ?string
    {
        $cleaned = strtoupper(rtrim(trim($secret), '='));
        $cleaned = preg_replace('/\s+/', '', $cleaned) ?? '';

        if ($cleaned === '') {
            return null;
        }

        $buffer = 0;
        $bits = 0;
        $output = '';

        foreach (str_split($cleaned) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);

            if ($position === false) {
                return null;
            }

            $buffer = ($buffer << 5) | $position;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $output .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $output === '' ? null : $output;
    }
}
