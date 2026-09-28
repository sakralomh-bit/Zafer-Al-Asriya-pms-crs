<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Auth\SecurityPolicy;

/**
 * Builds a `SecurityPolicy` for a test, explicitly.
 *
 * ================================================================================
 * EVERY NUMBER IN THIS FILE IS A TEST FIXTURE, NOT A PROJECT DECISION.
 * ================================================================================
 *
 * `SEC-007` (password algorithm), `SEC-008` (session idle timeout, absolute
 * lifetime, step-up freshness), and `B-05` (rate-limit and lockout values) are
 * all OPEN. See `docs/TASKS.md` §13 and the T-004 decision register.
 *
 * A number appearing here is a fixture that lets the MECHANISM be proven. It is
 * not a recommendation, not a default, and not a value the security owner has
 * approved. Nothing in this file is read by `config/security.php`, and no value
 * here can reach a production environment.
 *
 * This is the arrangement the money code already uses for `C-04`: the mechanism
 * is complete and tested, the policy is the caller's, and a test result is
 * never allowed to stand in for the decision.
 *
 * The counterpart of this file is `SecurityPolicy::fromConfig()`, which reads
 * the real configuration. `UnresolvedSecurityPolicyTest` asserts that path
 * refuses rather than defaults.
 */
trait ResolvesSecurityPolicy
{
    /**
     * The fixture values. Named so that a reader of any test using them can see
     * immediately that they are not a policy.
     *
     * @var array<string, mixed>
     */
    private const TEST_ONLY_POLICY = [
        'password_hashing' => [
            // `SEC-007` is OPEN. This names an algorithm ONLY so the hasher
            // resolution path can execute. Choosing bcrypt here would be
            // choosing it for the project, and it is not chosen.
            'algorithm' => 'test_only_hasher',
            'options' => null,
        ],
        'session' => [
            // `SEC-008` is OPEN.
            'idle_timeout_seconds' => 900,
            'absolute_lifetime_seconds' => 28800,
        ],
        'authentication_rate_limit' => [
            // `B-05` is OPEN.
            'max_attempts' => 5,
            'decay_seconds' => 60,
        ],
        'lockout' => [
            // `B-05` is OPEN.
            'threshold' => 3,
            'seconds' => 300,
        ],
        'step_up' => [
            // `DR-T004-04` is OPEN.
            'freshness_seconds' => 300,
        ],
        'security_headers' => [
            'content_security_policy' => null,
            'strict_transport_security' => null,
            'referrer_policy' => null,
            'permissions_policy' => null,
        ],
    ];

    /**
     * A policy whose values are ALL unresolved — the shipped state.
     */
    protected function unresolvedSecurityPolicy(): SecurityPolicy
    {
        return new SecurityPolicy([]);
    }

    /**
     * A policy carrying the explicit fixtures above.
     *
     * @param  array<string, mixed>  $overrides  merged over the fixtures, for a
     *                                           test that needs one value absent
     */
    protected function securityPolicyWith(array $overrides = []): SecurityPolicy
    {
        return new SecurityPolicy($this->mergeDeep(self::TEST_ONLY_POLICY, $overrides));
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeDeep(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $base[$key] = is_array($value) && is_array($base[$key] ?? null)
                ? $this->mergeDeep($base[$key], $value)
                : $value;
        }

        return $base;
    }
}
