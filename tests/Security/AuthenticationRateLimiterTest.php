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
 * ============================ THE NUMBERS ARE FIXTURES ============================
 * The ceilings used here come from `ResolvesSecurityPolicy` and are NOT the
 * shipped baselines and NOT an approved policy. `SecurityBaselineTest` covers
 * the baseline itself. The mechanism is what this file is about.
 *
 * ============================ WHAT IS ACTUALLY BEING PROVEN ============================
 * The keying. The previous implementation used ONE combined `email + IP` key,
 * and this suite is where the replacement is proven rather than assumed. The
 * properties that matter, each with the attack it answers:
 *
 *   | Test                                    | Attack it defeats                     |
 *   |-----------------------------------------|---------------------------------------|
 *   | `..._rotating_addresses_do_not_reset`  | rotating-IP credential stuffing        |
 *   | `..._spraying_identifiers_from_one_...` | a spray from a single egress          |
 *   | `..._a_nonexistent_account_costs_...`  | spraying identifiers that do not exist |
 *   | `..._independent_keys`                 | a combined key giving N×M buckets      |
 *   | `..._no_credential_in_the_key`         | a cache dump leaking staff identifiers |
 *   | `..._uniform_refusal`                  | account existence from a 429           |
 */
final class AuthenticationRateLimiterTest extends TestCase
{
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    /** TEST-ONLY, mirroring `ResolvesSecurityPolicy`. Not a decision. */
    private const TEST_LOCKOUT_THRESHOLD = 3;

    private const IDENTIFIER = 'limiter.subject@example.test';

    private const IP = '203.0.113.10';

    private const OTHER_IP = '198.51.100.4';

    private AuthenticationRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());
        $this->limiter = $this->app->make(AuthenticationRateLimiter::class);
    }

    // =====================================================================
    // Baseline behaviour
    // =====================================================================

    public function test_an_attempt_within_the_window_is_allowed(): void
    {
        $this->limiter->assertAttemptAllowed(self::IDENTIFIER, self::IP);

        $this->addToAssertionCount(1);
    }

    /**
     * The rate limiter is exercised on its own here, with the lockout pushed out
     * of the way. In the shared fixture the lockout threshold is lower than the
     * ceiling, so the lockout would be the binding rule and the ceiling would
     * never be reached — a test that passed while proving nothing.
     */
    public function test_attempts_below_the_ceiling_are_still_allowed(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => 2, 'ip_max_attempts' => 99],
            'lockout' => ['threshold' => 99, 'seconds' => 300],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        $limiter->recordFailure(self::IDENTIFIER, self::IP);
        $limiter->assertAttemptAllowed(self::IDENTIFIER, self::IP);

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
            'authentication_rate_limit' => ['max_attempts' => 2, 'ip_max_attempts' => 99],
            'lockout' => ['threshold' => 99, 'seconds' => 300],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        $limiter->recordFailure(self::IDENTIFIER, self::IP);
        $limiter->recordFailure(self::IDENTIFIER, self::IP);

        try {
            $limiter->assertAttemptAllowed(self::IDENTIFIER, self::IP);
            $this->fail('Expected the attempt to be refused.');
        } catch (RateLimited $limited) {
            $this->assertSame(ErrorCode::RateLimited, $limited->errorCode);
            $this->assertSame(429, $limited->errorCode->httpStatus());
            $this->assertGreaterThan(0, $limited->retryAfterSeconds);
        }
    }

    public function test_a_lockout_refuses_before_the_rate_ceiling_is_reached(): void
    {
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD; $i++) {
            $this->limiter->recordFailure(self::IDENTIFIER, self::IP);
        }

        $this->expectException(RateLimited::class);

        $this->limiter->assertAttemptAllowed(self::IDENTIFIER, self::IP);
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
    public function test_the_account_counter_is_cleared_when_a_lockout_is_applied(): void
    {
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD; $i++) {
            $this->limiter->recordFailure(self::IDENTIFIER, self::IP);
        }

        $this->assertSame(
            0,
            RateLimiter::attempts($this->accountKey(self::IDENTIFIER)),
            'Locking out must also clear the account attempt counter.',
        );

        $this->assertSame(
            1,
            RateLimiter::attempts($this->lockoutKey(self::IDENTIFIER)),
            'The lockout itself must be recorded.',
        );
    }

    public function test_clearing_resets_the_account_the_lockout_and_the_address(): void
    {
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD + 1; $i++) {
            $this->limiter->recordFailure(self::IDENTIFIER, self::IP);
        }

        $this->limiter->clear(self::IDENTIFIER, self::IP);

        $this->assertSame(0, RateLimiter::attempts($this->accountKey(self::IDENTIFIER)));
        $this->assertSame(0, RateLimiter::attempts($this->lockoutKey(self::IDENTIFIER)));
        $this->assertSame(
            0,
            RateLimiter::attempts($this->addressKey(self::IP)),
            'A successful login must not leave a penalty on a shared address that '
            .'punishes the next colleague who signs in from it.',
        );

        $this->limiter->assertAttemptAllowed(self::IDENTIFIER, self::IP);

        $this->addToAssertionCount(1);
    }

    // =====================================================================
    // THE KEYING — the property the previous implementation lacked
    // =====================================================================

    /**
     * THE ROTATING-IP ATTACK, AND THE TEST THAT PROVES IT IS NOW STOPPED.
     *
     * Under a single combined `email + IP` key this loop is unbounded: every
     * request from a new address produces a new key, so `tooManyAttempts` is
     * never true and the account is never throttled. The attacker is not slowed
     * down at all, and the counter they exhaust is the address dimension's — one
     * hit each, against a ceiling of 30.
     *
     * This is the single most damaging property of the old keying, and it is
     * exactly the technique a credential-stuffing list is sold for.
     */
    public function test_rotating_addresses_against_one_account_are_still_throttled(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => 5, 'ip_max_attempts' => 30],
            'lockout' => ['threshold' => 99, 'seconds' => 900],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        // Ten different source addresses, one account.
        for ($i = 0; $i < 10; $i++) {
            $address = '198.51.100.'.($i + 1);
            $limiter->recordFailure(self::IDENTIFIER, $address);
        }

        $this->expectException(RateLimited::class);

        // An eleventh address — a brand-new key under the old keying — is still
        // refused, because the ACCOUNT dimension is now independent of the
        // address and is already past its ceiling of 5.
        $limiter->assertAttemptAllowed(self::IDENTIFIER, '198.51.100.200');
    }

    /**
     * The converse: the ADDRESS dimension is enforced on its own, independently
     * of the account.
     *
     * A spray from one egress at many DIFFERENT accounts is invisible to the
     * account dimension, because no single account reaches its ceiling. Under the
     * old combined keying the same spray was also invisible, because each
     * (account, address) pair was a distinct bucket. This is what catches it.
     */
    public function test_spraying_many_identifiers_from_one_address_is_throttled(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => 5, 'ip_max_attempts' => 10],
            'lockout' => ['threshold' => 99, 'seconds' => 900],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        for ($i = 0; $i < 10; $i++) {
            $limiter->recordFailure('sprayed.subject.'.$i.'@example.test', self::IP);
        }

        $this->expectException(RateLimited::class);

        // An identifier that has never been seen, from the same address.
        $limiter->assertAttemptAllowed('never.seen@example.test', self::IP);
    }

    /**
     * A nonexistent account costs exactly what a real one costs.
     *
     * The key is built from the SUBMITTED identifier, never from a resolved user
     * id, precisely so that this holds: an unconfirmed identifier has no id, and
     * keying on the id would make every nonexistent account FREE — a hole shaped
     * exactly like the attack it is meant to stop.
     *
     * The converse matters just as much. An exemption would make the ABSENCE of a
     * lockout the enumeration oracle, and locking nonexistent accounts harder
     * would invert the same oracle. Neither is done.
     */
    public function test_a_nonexistent_account_consumes_the_same_controls(): void
    {
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD; $i++) {
            $this->limiter->recordFailure('no.such.person@example.test', self::IP);
        }

        $this->assertSame(
            1,
            RateLimiter::attempts($this->lockoutKey('no.such.person@example.test')),
            'An unconfirmed identifier must be locked out exactly like a confirmed one.',
        );

        $this->expectException(RateLimited::class);

        $this->limiter->assertAttemptAllowed('no.such.person@example.test', self::IP);
    }

    /**
     * The two dimensions produce two different keys, neither derived from the
     * other.
     */
    public function test_the_account_and_address_dimensions_are_independent_keys(): void
    {
        $this->limiter->recordFailure(self::IDENTIFIER, self::IP);

        $account = $this->accountKey(self::IDENTIFIER);
        $address = $this->addressKey(self::IP);

        $this->assertNotSame($account, $address);
        $this->assertSame(1, RateLimiter::attempts($account));
        $this->assertSame(1, RateLimiter::attempts($address));
    }

    /**
     * The account key does NOT change when the address does. That is the whole
     * point: one identity has one budget regardless of where the attempt comes
     * from.
     */
    public function test_the_account_key_does_not_depend_on_the_address(): void
    {
        $this->limiter->recordFailure(self::IDENTIFIER, self::IP);
        $this->limiter->recordFailure(self::IDENTIFIER, self::OTHER_IP);

        $this->assertSame(
            2,
            RateLimiter::attempts($this->accountKey(self::IDENTIFIER)),
            'Two failures from two addresses are two hits on ONE account budget.',
        );
    }

    /**
     * One account is not collateral damage from another account's traffic, and
     * one address is not collateral damage from another address's.
     */
    public function test_the_lockout_is_scoped_to_one_account(): void
    {
        for ($i = 0; $i < self::TEST_LOCKOUT_THRESHOLD; $i++) {
            $this->limiter->recordFailure(self::IDENTIFIER, self::IP);
        }

        $this->limiter->assertAttemptAllowed('someone.else@example.test', self::OTHER_IP);

        $this->addToAssertionCount(1);
    }

    /**
     * NO raw identifier and NO raw address is written to the cache backend.
     *
     * A limiter keyed by email writes that email into the cache, the cache dump,
     * and any cache-metrics output. An attacker with read access to any of those
     * would obtain a list of staff identifiers — which `COM-003` classifies as
     * personal data.
     */
    public function test_the_cache_key_is_a_digest_and_never_the_identifier(): void
    {
        foreach ([$this->accountKey(self::IDENTIFIER), $this->addressKey(self::IP)] as $key) {
            $this->assertStringNotContainsString(self::IDENTIFIER, $key);
            $this->assertStringNotContainsString(strtolower(self::IDENTIFIER), $key);
            $this->assertStringNotContainsString(self::IP, $key);

            // The cache key is a self-describing prefix plus a SHA-256 digest.
            // Asserting the WHOLE key is 64 hex would fail on the prefix, and
            // asserting nothing about the digest shape would pass if the key
            // became a reversible encoding.
            $this->assertMatchesRegularExpression(
                '/^auth-attempt:(account|address):[0-9a-f]{64}$/',
                $key,
            );
        }
    }

    public function test_the_account_key_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame(
            $this->accountKey(self::IDENTIFIER),
            $this->accountKey('  LIMITER.Subject@Example.Test '),
            'Two spellings of one mailbox must not be two rate-limit budgets, or a '
            .'ceiling can be doubled by typing one capital letter.',
        );
    }

    /**
     * An unresolvable address collapses to ONE shared bucket rather than to "no
     * limit".
     *
     * The alternative — exempting an unknown address — would hand an attacker a
     * free pass by omitting something they control, and would make the address
     * dimension disappear exactly when the deployment is misconfigured.
     */
    public function test_a_missing_address_still_produces_a_stable_shared_key(): void
    {
        $this->assertSame($this->addressKey(null), $this->addressKey(null));
        $this->assertNotSame($this->addressKey(null), $this->addressKey(self::IP));
    }

    /**
     * One uniform refusal, whatever the reason.
     *
     * The account lockout, the account ceiling, and the address ceiling all
     * produce the SAME message. Differentiating them would tell an attacker which
     * dimension is failing and therefore how their attack is being detected, and
     * it would tell a legitimate user that their colleague's account is involved.
     */
    public function test_every_refusal_is_uniform(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => 99, 'ip_max_attempts' => 99],
            'lockout' => ['threshold' => 2, 'seconds' => 900],
        ]));
        $lockedOut = $this->app->make(AuthenticationRateLimiter::class);

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => 1, 'ip_max_attempts' => 99],
            'lockout' => ['threshold' => 99, 'seconds' => 900],
        ]));
        $atCeiling = $this->app->make(AuthenticationRateLimiter::class);

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => 99, 'ip_max_attempts' => 1],
            'lockout' => ['threshold' => 99, 'seconds' => 900],
        ]));
        $addressCeiling = $this->app->make(AuthenticationRateLimiter::class);

        $messages = [];

        foreach ([[$lockedOut, 2], [$atCeiling, 1], [$addressCeiling, 1]] as [$limiter, $failures]) {
            for ($i = 0; $i < $failures; $i++) {
                $limiter->recordFailure(self::IDENTIFIER, self::IP);
            }

            try {
                $limiter->assertAttemptAllowed(self::IDENTIFIER, self::IP);
                $this->fail('Expected a refusal.');
            } catch (RateLimited $limited) {
                $messages[] = $limited->getMessage();
            }
        }

        $this->assertCount(3, $messages);
        $this->assertCount(1, array_unique($messages), 'The three refusal reasons must be indistinguishable.');
    }

    // =====================================================================
    // Fail-closed
    // =====================================================================

    public function test_an_unresolved_account_ceiling_refuses_rather_than_limiting_nothing(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => null],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        try {
            $limiter->assertAttemptAllowed(self::IDENTIFIER, self::IP);
            $this->fail('Expected the unresolved ceiling to refuse.');
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame('B-05', $unresolved->decision);
            $this->assertSame(503, $unresolved->errorCode->httpStatus());
        }
    }

    /**
     * The ADDRESS dimension fails closed too, and this one matters more than the
     * usual case.
     *
     * If the address ceiling were absent while the account ceiling is present,
     * the system would still be safe against a rotating-IP attack — and would
     * quietly have lost the only control against a spray, because no individual
     * account in a spray reaches its own ceiling. A silently missing dimension is
     * worse than a missing one, so it refuses.
     */
    public function test_an_unresolved_address_ceiling_refuses_rather_than_limiting_nothing(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['ip_max_attempts' => null],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        try {
            $limiter->assertAttemptAllowed(self::IDENTIFIER, self::IP);
            $this->fail('Expected the unresolved address ceiling to refuse.');
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame('B-05', $unresolved->decision);
            $this->assertStringContainsString(
                'rotating',
                $unresolved->getMessage(),
                'The refusal must say what is lost when this value is missing.',
            );
        }
    }

    public function test_an_unresolved_lockout_refuses_rather_than_locking_nobody(): void
    {
        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'lockout' => ['threshold' => null, 'seconds' => null],
        ]));
        $limiter = $this->app->make(AuthenticationRateLimiter::class);

        $this->expectException(SecurityPolicyUnresolved::class);

        $limiter->recordFailure(self::IDENTIFIER, self::IP);
    }

    // =====================================================================
    // Helpers
    // =====================================================================
    //
    // The two key-building methods are PRIVATE, and that is correct — a key
    // format is an implementation detail that a caller must not depend on. These
    // reach them by reflection to build the FULL cache key a counter lives under,
    // prefix included, so a test can assert on what is actually in the store
    // rather than on a re-implementation of the format that would agree with a
    // broken key function.

    private const ACCOUNT_LIMIT_PREFIX = 'auth-attempt:account:';

    private const ACCOUNT_LOCKOUT_PREFIX = 'auth-lockout:account:';

    private const ADDRESS_LIMIT_PREFIX = 'auth-attempt:address:';

    private function accountKey(string $identifier): string
    {
        return self::ACCOUNT_LIMIT_PREFIX.$this->invokeKey('accountKey', $identifier);
    }

    private function lockoutKey(string $identifier): string
    {
        return self::ACCOUNT_LOCKOUT_PREFIX.$this->invokeKey('accountKey', $identifier);
    }

    private function addressKey(?string $address): string
    {
        return self::ADDRESS_LIMIT_PREFIX.$this->invokeKey('addressKey', $address);
    }

    private function invokeKey(string $method, ?string $argument): string
    {
        return (string) (new \ReflectionMethod(AuthenticationRateLimiter::class, $method))
            ->invoke($this->limiter, $argument);
    }
}
