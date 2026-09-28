<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\AuthenticationRateLimiter;
use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Auth\SecurityPolicyUnresolved;
use App\Shared\Domain\ErrorCode;
use App\Shared\Domain\RateLimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * `AC-T-004-06` — "Authentication endpoints are rate limited, and lockout
 * behaviour is defined and tested."
 *
 * ================================================================================
 * EVERY NUMBER HERE IS A TEST FIXTURE, NOT A PROJECT DECISION.
 * ================================================================================
 *
 * `B-05` — the rate-limit ceiling, the decay window, the lockout threshold, and
 * the lockout window — is OPEN. The values in `ResolvesSecurityPolicy`
 * (`max_attempts` 5, `decay` 60, `threshold` 3, `seconds` 300) exist so the
 * MECHANISM can be exercised. They are not recommendations, and nothing here
 * asserts that a hotel should use them. `UnresolvedSecurityPolicyTest` proves
 * the shipped configuration is empty and that the mechanism refuses it.
 *
 * This suite also covers `DR-T004-07`, which is OPEN: whether an
 * unauthenticated caller may drive a lockout against an account whose existence
 * is unconfirmed. That question is a Security Owner decision and is NOT decided
 * here. What IS tested is the fact that the key binds the client address as
 * well as the identifier, which is the part of the mechanism that is settled
 * and that bounds the blast radius.
 */
final class AuthenticationRateLimiterTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    /** TEST-ONLY, mirroring `ResolvesSecurityPolicy`. Not a decision. */
    private const TEST_LOCKOUT_THRESHOLD = 3;

    private const EMAIL = 'limiter.subject@example.test';

    private const IP = '203.0.113.10';

    private AuthenticationRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());
        $this->limiter = $this->app->make(AuthenticationRateLimiter::class);
    }

    public function test_an_attempt_within_the_window_is_allowed(): void
    {
        $this->limiter->assertAttemptAllowed(self::EMAIL, self::IP);

        $this->addToAssertionCount(1);
    }

    /**
     * The rate limiter is exercised on its own here, with the lockout pushed
     * out of the way. In the shared fixture the lockout threshold is lower than
     * the ceiling, so the lockout would be the binding rule and the ceiling
     * would never be reached — a test that passed while proving nothing.
     */
    public function test_attempts_below_the_ceiling_are_still_allowed(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => 2],
            'lockout' => ['threshold' => 99, 'seconds' => 300],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        $limiter->recordFailure(self::EMAIL, self::IP);
        $limiter->assertAttemptAllowed(self::EMAIL, self::IP);

        $this->addToAssertionCount(1);
    }

    /**
     * `API-SPEC` §1.5: a 429 "includes retry guidance". A bare 429 tells the
     * client nothing except that it failed, so the delay is carried on the
     * failure and must be present.
     */
    public function test_crossing_the_ceiling_refuses_with_retry_guidance(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => 2],
            'lockout' => ['threshold' => 99, 'seconds' => 300],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        $limiter->recordFailure(self::EMAIL, self::IP);
        $limiter->recordFailure(self::EMAIL, self::IP);

        try {
            $limiter->assertAttemptAllowed(self::EMAIL, self::IP);
            $this->fail('Expected the attempt to be refused.');
        } catch (RateLimited $limited) {
            $this->assertSame(ErrorCode::RateLimited, $limited->errorCode);
            $this->assertSame(429, $limited->errorCode->httpStatus());
            $this->assertGreaterThan(0, $limited->retryAfterSeconds);
        }
    }

    public function test_a_lockout_refuses_before_the_rate_ceiling_is_reached(): void
    {
        // The threshold (3) is lower than the ceiling (5), so the lockout is
        // what stops this, not the limiter. They are separate rules answering
        // separate questions and are tested separately.
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD; $i++) {
            $this->limiter->recordFailure(self::EMAIL, self::IP);
        }

        $this->expectException(RateLimited::class);

        $this->limiter->assertAttemptAllowed(self::EMAIL, self::IP);
    }

    /**
     * The attempt counter is cleared when the lockout is applied, so a user who
     * serves the window gets a full window again rather than being re-locked
     * instantly by a still-saturated counter.
     *
     * Asserted against the counter directly. Asking `assertAttemptAllowed()`
     * instead would prove nothing: the lockout is checked first, so it would
     * refuse regardless of the counter and the test would pass either way.
     */
    public function test_the_attempt_counter_is_cleared_when_a_lockout_is_applied(): void
    {
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD; $i++) {
            $this->limiter->recordFailure(self::EMAIL, self::IP);
        }

        $this->assertSame(
            0,
            RateLimiter::attempts('auth-attempt:'.$this->keyFor(self::EMAIL, self::IP)),
            'Locking out must also clear the attempt counter.',
        );

        $this->assertSame(
            1,
            RateLimiter::attempts('auth-lockout:'.$this->keyFor(self::EMAIL, self::IP)),
            'The lockout itself must be recorded.',
        );
    }

    public function test_clearing_resets_both_the_attempt_and_the_lockout(): void
    {
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD + 1; $i++) {
            $this->limiter->recordFailure(self::EMAIL, self::IP);
        }

        $this->limiter->clear(self::EMAIL, self::IP);

        $this->limiter->assertAttemptAllowed(self::EMAIL, self::IP);

        $this->addToAssertionCount(1);
    }

    public function test_the_lockout_is_scoped_to_one_key(): void
    {
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD; $i++) {
            $this->limiter->recordFailure(self::EMAIL, self::IP);
        }

        // A different identifier from a different address is a different key and
        // is not collateral damage. This is the bound on `DR-T004-07`: an
        // attacker who knows one address cannot lock every account from it.
        $this->limiter->assertAttemptAllowed('someone.else@example.test', '198.51.100.4');

        $this->addToAssertionCount(1);
    }

    /**
     * The key is a digest, never the credential.
     *
     * A limiter keyed by email writes that email into the cache backend, the
     * cache dump, and any cache-metrics output. An attacker with read access to
     * any of those would obtain a list of staff identifiers — and, per
     * `COM-003`, that list is personal data.
     */
    public function test_the_cache_key_is_a_digest_and_never_the_identifier(): void
    {
        $key = $this->keyFor(self::EMAIL, self::IP);

        $this->assertStringNotContainsString(self::EMAIL, $key);
        $this->assertStringNotContainsString(strtolower(self::EMAIL), $key);
        $this->assertStringNotContainsString(self::IP, $key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    public function test_the_key_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame(
            $this->keyFor(self::EMAIL, self::IP),
            $this->keyFor('  LIMITER.Subject@Example.Test ', self::IP),
            'Two spellings of one mailbox must not be two rate-limit buckets.',
        );
    }

    public function test_an_address_is_part_of_the_key(): void
    {
        $this->assertNotSame(
            $this->keyFor(self::EMAIL, self::IP),
            $this->keyFor(self::EMAIL, '198.51.100.4'),
        );
    }

    public function test_a_missing_address_still_produces_a_stable_key(): void
    {
        $this->assertSame($this->keyFor(self::EMAIL, null), $this->keyFor(self::EMAIL, null));
        $this->assertNotSame($this->keyFor(self::EMAIL, null), $this->keyFor(self::EMAIL, self::IP));
    }

    // =====================================================================
    // Fail-closed
    // =====================================================================

    public function test_an_unresolved_ceiling_refuses_rather_than_limiting_nothing(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => null],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        try {
            $limiter->assertAttemptAllowed(self::EMAIL, self::IP);
            $this->fail('Expected the unresolved ceiling to refuse.');
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame('B-05', $unresolved->decision);
            $this->assertSame(503, $unresolved->errorCode->httpStatus());
        }
    }

    public function test_an_unresolved_lockout_refuses_rather_than_locking_nobody(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'lockout' => ['threshold' => null, 'seconds' => null],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        $this->expectException(SecurityPolicyUnresolved::class);

        $limiter->recordFailure(self::EMAIL, self::IP);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function keyFor(string $email, ?string $ip): string
    {
        $method = new \ReflectionMethod(AuthenticationRateLimiter::class, 'key');

        return (string) $method->invoke($this->limiter, $email, $ip);
    }
}
