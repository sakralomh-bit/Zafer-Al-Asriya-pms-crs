<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Shared\Domain\RateLimited;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Throttles authentication attempts and applies the lockout rule.
 *
 * `AC-T-004-06`: "Authentication endpoints are rate limited, and lockout
 * behaviour is defined and tested." `SEC-010` requires rate limiting on
 * authentication.
 *
 * ============================ TWO DIMENSIONS, NOT ONE ============================
 * The keying is the security property of this class, so it is worth stating
 * precisely what was wrong and what replaces it.
 *
 * The previous key was ONE digest over `email + IP`. Hashing it was correct and
 * is kept. COMBINING the two into a single key was not, because one key gives an
 * attacker the PRODUCT of the two limits rather than the protection of both:
 *
 *   - N accounts against M addresses produced N×M distinct keys, so the effective
 *     ceiling was N×M×attempts. The per-account protection the ceiling was
 *     supposed to express did not exist.
 *   - An attacker ROTATING source addresses against one account received a fresh
 *     key on every request and was never throttled at all. That is the single
 *     most damaging property of a combined key, and it is exactly the technique
 *     a credential-stuffing list is sold for.
 *   - The address was consequently never throttled on its own merits, so the
 *     "one source spraying twenty identities" pattern was invisible.
 *
 * Both dimensions are now SEPARATE, HASHED, and BOTH ENFORCED, and neither is
 * derived from the other:
 *
 *   | Dimension  | Key material                                  | Ceiling | Lockout |
 *   |------------|-----------------------------------------------|---------|---------|
 *   | `account`  | normalised SUBMITTED identifier                | 5       | YES     |
 *   | `address`  | client address from the trusted proxy chain    | 30      | NO      |
 *
 * The ACCOUNT key uses the SUBMITTED identifier, never a resolved user ID, and
 * never the outcome of a lookup. A nonexistent account has no ID, so keying on
 * the ID would make the nonexistent-account case FREE — a hole shaped exactly
 * like the attack it is meant to stop. Keying on the submission counts an
 * unconfirmed account identically to a confirmed one.
 *
 * The ACCOUNT key is what lockout applies to, and the ADDRESS key only
 * throttles. That asymmetry is deliberate and is the whole reason the two
 * dimensions are separate:
 *
 *   - Lockout on the ACCOUNT: the blast radius is one identity, its owner is
 *     present at the front desk, and a human can clear it. This is the strongest
 *     response, so it belongs where it is recoverable.
 *   - NO lockout on the ADDRESS: a hotel group behind one NAT or a corporate
 *     proxy would then lose an entire property from service, and a spray would
 *     become a denial of service. The address dimension instead gets a LOOSER
 *     CEILING, which sheds a spray without removing a shared office.
 *
 * A pure per-account limit would let anyone lock out any known username; a pure
 * per-address limit would let one spray punish a whole office. Both together
 * bound the attack and neither failure mode survives.
 *
 * ============================ NO ENUMERATION ============================
 * Neither dimension, and neither refusal, distinguishes a nonexistent account
 * from a wrong password. The nonexistent account is bucketed exactly as a real
 * one is — not exempted, which would make the absence of a lockout the oracle,
 * and not locked harder, which would invert the same oracle.
 *
 * ============================ NOTHING SECRET IS STORED ============================
 * The account dimension is a SHA-256 digest of the normalised identifier, and
 * the address dimension a digest of the client address. No credential, no
 * password, and no raw identifier is written to the cache backend, a cache dump,
 * or any cache-metrics output. `hash()` is used rather than an HMAC with the
 * application key because a limiter key is not an authenticity claim and must not
 * break when a secret rotates.
 *
 * This is a PRE-AUTHENTICATION gate. It cannot grant access and never runs after
 * authorization, so it cannot become an authorization bypass. Its only effect is
 * to refuse earlier and more cheaply.
 */
final class AuthenticationRateLimiter
{
    /**
     * Prefixes keep the two dimensions apart in one flat keyspace. A distinct
     * prefix per dimension is what stops a counter for one from being read as a
     * counter for the other, and it makes a cache dump readable.
     */
    private const ACCOUNT_LIMIT_PREFIX = 'auth-attempt:account:';

    private const ACCOUNT_LOCKOUT_PREFIX = 'auth-lockout:account:';

    private const ADDRESS_LIMIT_PREFIX = 'auth-attempt:address:';

    /**
     * The value used for the address dimension when no address can be resolved.
     *
     * NOT a wildcard. A deployment behind an unresolvable client address puts
     * every caller into one shared bucket, which is the conservative outcome: it
     * over-restricts a set of callers who all look identical, and it does so
     * visibly. The alternative — exempting an unknown address — would hand an
     * attacker a free pass by omitting a header the attacker controls.
     */
    private const UNKNOWN_ADDRESS = 'unknown';

    public function __construct(
        private readonly SecurityPolicy $policy,
    ) {}

    /**
     * Refuse the attempt if EITHER dimension is exhausted, or the account is
     * locked out.
     *
     * Checked in the order a caller should experience them: the account's own
     * lockout first (it is the most specific answer), then the account ceiling,
     * then the address ceiling. The order changes only which `Retry-After` a
     * caller is given; the refusal itself is deliberately uniform so it never
     * reveals which dimension fired or whether the account exists.
     *
     * @throws RateLimited `RATE_LIMITED` (429) with retry guidance
     * @throws SecurityPolicyUnresolved when a `B-05` value is unset or malformed
     */
    public function assertAttemptAllowed(string $identifier, ?string $ipAddress): void
    {
        $account = $this->policy->authenticationRateLimit();
        $address = $this->policy->ipRateLimit();

        $limitKey = self::ACCOUNT_LIMIT_PREFIX.$this->accountKey($identifier);
        $lockoutKey = self::ACCOUNT_LOCKOUT_PREFIX.$this->accountKey($identifier);

        if (RateLimiter::tooManyAttempts($lockoutKey, 1)) {
            throw $this->refused(RateLimiter::availableIn($lockoutKey));
        }

        if (RateLimiter::tooManyAttempts($limitKey, $account['attempts'])) {
            throw $this->refused(RateLimiter::availableIn($limitKey));
        }

        $addressKey = self::ADDRESS_LIMIT_PREFIX.$this->addressKey($ipAddress);

        if (RateLimiter::tooManyAttempts($addressKey, $address['attempts'])) {
            throw $this->refused(RateLimiter::availableIn($addressKey));
        }
    }

    /**
     * Record a failed attempt against BOTH dimensions, and lock the account out
     * when it crosses the threshold.
     *
     * Both dimensions are hit on every failure, including failures against an
     * account that does not exist. Recording only the account would leave a
     * spray of unknown identifiers to be limited by nothing at all.
     *
     * @throws SecurityPolicyUnresolved when a `B-05` value is unset or malformed
     */
    public function recordFailure(string $identifier, ?string $ipAddress): void
    {
        $account = $this->policy->authenticationRateLimit();
        $address = $this->policy->ipRateLimit();
        ['threshold' => $threshold, 'seconds' => $lockoutSeconds] = $this->policy->lockout();

        $limitKey = self::ACCOUNT_LIMIT_PREFIX.$this->accountKey($identifier);
        $addressKey = self::ADDRESS_LIMIT_PREFIX.$this->addressKey($ipAddress);

        RateLimiter::hit($limitKey, $account['decay']);
        RateLimiter::hit($addressKey, $address['decay']);

        if (RateLimiter::attempts($limitKey) < $threshold) {
            return;
        }

        // The account counter is cleared when the lockout is applied. Without
        // that, a user who served the lockout window would be re-locked the
        // instant it expired by a still-saturated counter, rather than being
        // given a full window.
        RateLimiter::clear($limitKey);
        RateLimiter::hit(self::ACCOUNT_LOCKOUT_PREFIX.$this->accountKey($identifier), $lockoutSeconds);

        // The ADDRESS counter is deliberately NOT cleared, and the address is
        // deliberately NOT locked out. The address dimension sheds a spray; a
        // lockout there would remove a whole shared egress from service. Its
        // counter keeps decaying on its own longer window, so a locked account
        // that is being attacked from one place leaves that fact visible.
    }

    /**
     * Clear every counter after a successful authentication.
     *
     * Clearing on success is what makes the limiter "attempts within a window"
     * rather than "failures ever", and it is what a locked-out-then-successful
     * user relies on to get their full window back.
     *
     * The address dimension is cleared here for the same reason a successful
     * login from a shared office should not spend the office's budget: an
     * address is shared by many legitimate users, so a single success must not
     * leave a penalty that punishes the next colleague who signs in.
     */
    public function clear(string $identifier, ?string $ipAddress): void
    {
        $accountKey = $this->accountKey($identifier);

        RateLimiter::clear(self::ACCOUNT_LIMIT_PREFIX.$accountKey);
        RateLimiter::clear(self::ACCOUNT_LOCKOUT_PREFIX.$accountKey);
        RateLimiter::clear(self::ADDRESS_LIMIT_PREFIX.$this->addressKey($ipAddress));
    }

    /**
     * The account dimension's opaque digest.
     *
     * Normalised with `lower(trim())` so two spellings of one mailbox are one
     * bucket — otherwise `Ada@x.test` and `ada@x.test` are two budgets and a
     * ceiling can be doubled by typing the capital letter.
     *
     * Returns the DIGEST ONLY. The dimension tag lives in the caller's prefix,
     * and putting it here as well would produce a key like
     * `auth-attempt:account:account:…`, which is confusing to read in a cache
     * dump and gives a key two places to disagree with itself.
     */
    private function accountKey(string $identifier): string
    {
        return hash('sha256', Str::lower(trim($identifier)));
    }

    /**
     * The address dimension's opaque key.
     *
     * Takes the address the APPLICATION resolved, which is
     * `Request::ip()` — Laravel's own value after the trusted-proxy chain has
     * been applied. That matters: reading `X-Forwarded-For` here directly would
     * trust a header any client can set, and an attacker who can choose the key
     * can choose a new bucket per request, which is the rotating-IP attack this
     * dimension exists to stop. Trust configuration lives in
     * `bootstrap/app.php`; this class does not second-guess it and does not
     * widen it.
     *
     * An unresolvable address collapses to a single shared bucket rather than
     * to "no limit". See `UNKNOWN_ADDRESS`.
     */
    private function addressKey(?string $ipAddress): string
    {
        $address = $ipAddress === null ? '' : trim($ipAddress);

        return hash('sha256', $address === '' ? self::UNKNOWN_ADDRESS : $address);
    }

    /**
     * ONE refusal for every dimension and every reason.
     *
     * The message names no account, no dimension, and no cause. An attacker
     * probing for valid identifiers learns nothing from a uniform 429, and a
     * user who mistypes a password five times is not told that their colleague's
     * account is involved.
     */
    private function refused(int $retryAfter): RateLimited
    {
        return new RateLimited(
            $retryAfter,
            'Too many sign-in attempts. Wait before trying again.',
        );
    }
}
