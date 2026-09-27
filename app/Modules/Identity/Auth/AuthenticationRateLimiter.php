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
 * Two rules, because they answer different questions:
 *
 *   - RATE LIMIT bounds how fast attempts may be made, per key. It protects the
 *     account from online guessing.
 *   - LOCKOUT stops attempts for a window once the failure count crosses a
 *     threshold. It protects a *known* account from a sustained attack, and it
 *     is a decision with consequences: a locked-out identity cannot work, and
 *     a lockout an attacker can trigger is a denial-of-service against staff.
 *     That is why the threshold and window are a security owner's decision
 *     rather than a default.
 *
 * `docs/SECURITY.md` §12 records both as `TBD (needs B-05)`, so the VALUES come
 * from `SecurityPolicy`, which refuses rather than defaulting.
 *
 * THE KEY IS A HASH. The limiter is keyed on a digest of the submitted email
 * and the client address, never on the email itself: a limiter keyed by a
 * credential would put that credential into a cache backend, a cache dump, and
 * any cache-metrics output, and a limiter keyed on email alone would let an
 * attacker lock out a named account with no knowledge of its address.
 *
 * This is a PRE-AUTHENTICATION gate. It cannot grant access, and it never runs
 * after authorization — so it cannot become an authorization bypass. Its only
 * effect is to refuse earlier and more cheaply.
 */
final class AuthenticationRateLimiter
{
    private const LIMIT_PREFIX = 'auth-attempt:';

    private const LOCKOUT_PREFIX = 'auth-lockout:';

    public function __construct(
        private readonly SecurityPolicy $policy,
    ) {}

    /**
     * Refuse the attempt if the limit is exhausted or a lockout is active.
     *
     * @throws RateLimited `RATE_LIMITED` (429) with retry guidance
     */
    public function assertAttemptAllowed(string $email, ?string $ipAddress): void
    {
        $attempts = $this->policy->authenticationRateLimit()['attempts'];

        $digest = $this->key($email, $ipAddress);
        $lockoutKey = self::LOCKOUT_PREFIX.$digest;

        if (RateLimiter::tooManyAttempts($lockoutKey, 1)) {
            throw $this->locked(RateLimiter::availableIn($lockoutKey));
        }

        $limitKey = self::LIMIT_PREFIX.$digest;

        if (RateLimiter::tooManyAttempts($limitKey, $attempts)) {
            throw $this->limited(RateLimiter::availableIn($limitKey));
        }
    }

    /**
     * Record a failed attempt and apply the lockout when it crosses the
     * threshold.
     */
    public function recordFailure(string $email, ?string $ipAddress): void
    {
        ['attempts' => $attempts, 'decay' => $decay] = $this->policy->authenticationRateLimit();
        ['threshold' => $threshold, 'seconds' => $lockoutSeconds] = $this->policy->lockout();

        $digest = $this->key($email, $ipAddress);
        $limitKey = self::LIMIT_PREFIX.$digest;

        RateLimiter::hit($limitKey, $decay);

        if (RateLimiter::attempts($limitKey) < $threshold) {
            return;
        }

        // The attempt counter is cleared when the lockout is applied. Without
        // that, a user who served the lockout window would immediately be
        // re-locked by the still-saturated attempt counter rather than being
        // allowed the full window again.
        RateLimiter::clear($limitKey);
        RateLimiter::hit(self::LOCKOUT_PREFIX.$digest, $lockoutSeconds);
    }

    /**
     * Clear both counters after a successful authentication.
     *
     * Clearing the attempt counter on success is the behaviour that makes the
     * limiter "attempts within a window" rather than "failures ever", and it is
     * what a locked-out-then-successful user relies on to get their full window
     * back.
     */
    public function clear(string $email, ?string $ipAddress): void
    {
        $digest = $this->key($email, $ipAddress);

        RateLimiter::clear(self::LIMIT_PREFIX.$digest);
        RateLimiter::clear(self::LOCKOUT_PREFIX.$digest);
    }

    /**
     * A stable, non-reversible key. The digest exists so the credential is not
     * written into the cache backend; `hash()` is used rather than an HMAC with
     * the application key because a limiter key is not an authenticity claim
     * and must not depend on a rotating secret.
     */
    private function key(string $email, ?string $ipAddress): string
    {
        return hash('sha256', Str::lower(trim($email)).'|'.($ipAddress ?? 'unknown'));
    }

    private function locked(int $retryAfter): RateLimited
    {
        return new RateLimited(
            $retryAfter,
            'This account is temporarily locked after repeated failed sign-in attempts.',
        );
    }

    private function limited(int $retryAfter): RateLimited
    {
        return new RateLimited(
            $retryAfter,
            'Too many sign-in attempts. Wait before trying again.',
        );
    }
}
