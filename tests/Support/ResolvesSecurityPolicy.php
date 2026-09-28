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
 * `SEC-007` (password algorithm and work factor), `SEC-008` (session idle
 * timeout, absolute lifetime, step-up freshness), and `B-05` (rate-limit and
 * lockout values) all now ship IMPLEMENTED TECHNICAL BASELINES in
 * `config/security.php`, chosen under delegated technical authority and analysed
 * in `docs/SECURITY.md` §12.1. None of them is a registered decision:
 * `docs/PRD.md` §15 holds `DR-001` … `DR-014` and nothing else.
 *
 * The fixtures below are NOT those baselines. They are deliberately different
 * numbers, and they exist so a test can prove a MECHANISM without depending on a
 * configuration value. The consequences are worth stating, because a fixture that
 * quietly shadows a baseline makes a mechanism test look like a policy test:
 *
 *  - The lockout threshold here (3) is BELOW the attempt ceiling (5), so the
 *    lockout is the binding rule in most tests that use it. A test that needs the
 *    CEILING to bind overrides both.
 *  - The decay window here (60 s) is much shorter than the shipped 300 s, so a
 *    test can watch a window expire without sleeping for five minutes.
 *
 * Because these differ from the shipped values, a mechanism test that passes
 * here is not a test of the baseline. `SecurityBaselineTest` covers the baseline
 * itself by reading `SecurityPolicy::fromConfig()`.
 *
 * This is the arrangement the money code already uses for `C-04`: the mechanism
 * is complete and tested, the policy is the caller's, and a test result is never
 * allowed to stand in for a decision.
 *
 * The counterpart of this file is `SecurityPolicy::fromConfig()`, which reads the
 * real configuration. `SecurityPolicyUnresolvedTest` asserts that a value which
 * is BLANK or MALFORMED still refuses rather than reverting to the baseline.
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
            // A driver registered by the test itself, not a real algorithm. The
            // shipped baseline is `argon2id`; naming a test driver here proves the
            // resolution path without this fixture choosing a project algorithm.
            'algorithm' => 'test_only_hasher',
            'options' => null,
        ],
        'session' => [
            'idle_timeout_seconds' => 900,
            // 8 h, comfortably above the 900 s idle timeout so the two lifetimes
            // are distinguishable. `SecurityPolicy` REFUSES an absolute lifetime
            // that is not strictly greater than the idle timeout — that refusal
            // is `SEC-008`'s invariant and it is covered in
            // `SecurityPolicyUnresolvedTest`.
            'absolute_lifetime_seconds' => 28800,
        ],
        'authentication_rate_limit' => [
            'max_attempts' => 5,
            'decay_seconds' => 60,
            // The address dimension is looser than the account dimension and is
            // configured SEPARATELY, so a test can prove the two are enforced
            // independently rather than as one combined key.
            'ip_max_attempts' => 30,
            'ip_decay_seconds' => 60,
        ],
        'lockout' => [
            'threshold' => 3,
            'seconds' => 300,
        ],
        'step_up' => [
            'freshness_seconds' => 300,
        ],
        'mfa' => [
            'primary' => 'totp',
            'preferred' => 'passkey',
            'digits' => 6,
            'period_seconds' => 30,
            'window' => 1,
            'required_roles' => [
                'GROUP_MANAGER',
                'HOTEL_MANAGER',
                'FINANCE',
                'NIGHT_AUDITOR',
                'COMPLIANCE_OFFICER',
                'REVENUE_MANAGER',
                'AUDITOR',
                'SUPPORT',
            ],
        ],
        'security_headers' => [
            'content_security_policy' => "default-src 'self'; frame-ancestors 'none'",
            'x_content_type_options' => 'nosniff',
            'x_frame_options' => 'DENY',
            'referrer_policy' => 'no-referrer',
            'permissions_policy' => 'geolocation=(), camera=(), microphone=()',
            'hsts' => [
                'enabled' => false,
                'max_age' => 31536000,
                'include_sub_domains' => true,
                'preload' => false,
            ],
        ],
    ];

    /**
     * A policy carrying NO values at all — every leaf absent.
     *
     * Used to prove that a blank or malformed setting refuses instead of quietly
     * reverting to the shipped baseline. That is the property that separates a
     * documented baseline from a silent default.
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
