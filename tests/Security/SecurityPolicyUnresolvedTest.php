<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\Mfa\MfaMethod;
use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Auth\SecurityPolicyUnresolved;
use App\Shared\Domain\ErrorCode;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * A shipped baseline is not the same thing as a silent default, and this suite
 * is where the difference is proved.
 *
 * `docs/SECURITY.md` §12.1.1 records every `T-004` security value as either an
 * `IMPLEMENTED TECHNICAL BASELINE` or a `BLOCKED` row, and none of them as a
 * `FORMALLY REGISTERED` decision — `docs/PRD.md` §15 holds `DR-001` … `DR-014`
 * and nothing else. `config/security.php` now ships the baselines.
 *
 * So the fail-closed property has MOVED, and this suite tests where it moved to.
 * It used to be "a null must refuse"; now it is:
 *
 *   1. A value that is BLANK, MALFORMED, NON-POSITIVE, or the WRONG SHAPE still
 *      REFUSES with 503. Somebody broke the configuration, and the safe answer
 *      to a broken security instruction is to stop.
 *   2. An absolute session lifetime that does not EXCEED the idle timeout
 *      REFUSES. That is `SEC-008`'s invariant, and it is new: with
 *      `absolute <= idle` one of the two controls can never bind.
 *   3. An UNSUPPORTED second factor — including SMS and email — REFUSES, and
 *      says why.
 *   4. The header getters return a header or `null`, never a refusal, and HSTS
 *      returns `null` until the deployment guarantee is asserted.
 *
 * Property 1 is the one that matters most, and it is what distinguishes a
 * documented engineering choice from a default. A default silently replaces
 * whatever an operator typed. A baseline is a value with a recorded rationale in
 * §12.1, and if an operator explicitly blanks it, that is an instruction the
 * system cannot follow safely — so it refuses instead of quietly reverting.
 *
 * `SecurityBaselineTest` covers the other side: that the values which DO ship
 * are the ones §12.1 documents.
 */
final class SecurityPolicyUnresolvedTest extends TestCase
{
    use ResolvesSecurityPolicy;

    protected function setUp(): void
    {
        parent::setUp();

        // One driver is registered so the "unregistered name is refused" tests
        // can be told apart from "every name is refused". The name is the same
        // TEST-ONLY fixture the other `T-004` suites use.
        Hash::extend(
            'test_only_hasher',
            static fn (): Hasher => new BcryptHasher(['rounds' => 4]),
        );
    }

    // =====================================================================
    // 1. Blank / malformed values still refuse
    // =====================================================================

    public function test_a_blank_policy_refuses_rather_than_inventing_a_value(): void
    {
        $policy = $this->unresolvedSecurityPolicy();

        $this->assertRefuses(fn (SecurityPolicy $p) => $p->passwordHasher(), 'SEC-007', $policy);
        $this->assertRefuses(fn (SecurityPolicy $p) => $p->sessionIdleTimeoutSeconds(), 'SEC-008', $policy);
        $this->assertRefuses(fn (SecurityPolicy $p) => $p->sessionAbsoluteLifetimeSeconds(), 'SEC-008', $policy);
        $this->assertRefuses(fn (SecurityPolicy $p) => $p->authenticationRateLimit(), 'B-05', $policy);
        $this->assertRefuses(fn (SecurityPolicy $p) => $p->ipRateLimit(), 'B-05', $policy);
        $this->assertRefuses(fn (SecurityPolicy $p) => $p->lockout(), 'B-05', $policy);
        $this->assertRefuses(fn (SecurityPolicy $p) => $p->stepUpFreshnessSeconds(), 'SEC-008', $policy);
    }

    /**
     * A blank is different from a value nobody set.
     *
     * `config/security.php` supplies a default, so an ABSENT environment variable
     * correctly yields the baseline. An environment variable that is present and
     * EMPTY is an operator who set the value to nothing, and the safe answer to
     * that instruction is to refuse rather than to reinterpret it as "use the
     * default". This is the assertion that stops a baseline behaving like a
     * default.
     */
    public function test_an_explicitly_blanked_value_refuses_rather_than_reverting_to_the_baseline(): void
    {
        $this->app['config']->set('security.session.idle_timeout_seconds', '');

        $this->expectException(SecurityPolicyUnresolved::class);

        SecurityPolicy::fromConfig()->sessionIdleTimeoutSeconds();
    }

    public function test_a_non_positive_or_non_numeric_value_is_refused(): void
    {
        foreach (['0', '-60', 'soon', '1e9', '15 minutes'] as $bad) {
            $policy = new SecurityPolicy(['session' => ['idle_timeout_seconds' => $bad]]);

            $this->assertRefuses(
                fn (SecurityPolicy $p) => $p->sessionIdleTimeoutSeconds(),
                'SEC-008',
                $policy,
                'value: '.$bad,
            );
        }
    }

    public function test_a_malformed_value_is_refused_rather_than_coerced(): void
    {
        $policy = new SecurityPolicy(['session' => ['idle_timeout_seconds' => ['not', 'a', 'number']]]);

        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->sessionIdleTimeoutSeconds(),
            'SEC-007/SEC-008/B-05',
            $policy,
        );
    }

    /**
     * The refusal is `SERVICE_UNAVAILABLE`, and that choice is deliberate.
     *
     * 503 is the honest answer: the server cannot correctly perform this
     * operation right now. It is not 401 (the caller is not unauthenticated) and
     * not 403 (the caller is not forbidden). It is also not a bespoke code,
     * because `ADR-0019` fixes the vocabulary to `API-SPEC` §2.
     */
    public function test_an_unresolved_policy_is_service_unavailable_not_a_denial(): void
    {
        try {
            $this->unresolvedSecurityPolicy()->sessionIdleTimeoutSeconds();
            $this->fail('Expected the idle timeout to refuse.');
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame(ErrorCode::ServiceUnavailable, $unresolved->errorCode);
            $this->assertSame(503, $unresolved->errorCode->httpStatus());
        }
    }

    // =====================================================================
    // 2. SEC-008's invariant
    // =====================================================================

    /**
     * `SEC-008` requires the absolute lifetime to EXCEED the idle timeout, and
     * that is now checked rather than assumed.
     *
     * With `absolute == idle` the two refusals are indistinguishable, so an
     * operator cannot tell which control fired. With `absolute < idle` the
     * absolute lifetime is DEAD CODE that looks active, and an attacker who
     * keeps a session alive never reaches it.
     */
    public function test_an_absolute_lifetime_that_does_not_exceed_the_idle_timeout_is_refused(): void
    {
        foreach ([['idle' => 900, 'absolute' => 900], ['idle' => 900, 'absolute' => 600]] as $pair) {
            $policy = new SecurityPolicy([
                'session' => [
                    'idle_timeout_seconds' => $pair['idle'],
                    'absolute_lifetime_seconds' => $pair['absolute'],
                ],
            ]);

            try {
                $policy->sessionAbsoluteLifetimeSeconds();
                $this->fail("Expected a refusal for idle={$pair['idle']} absolute={$pair['absolute']}.");
            } catch (SecurityPolicyUnresolved $unresolved) {
                $this->assertSame('SEC-008', $unresolved->decision);
                $this->assertStringContainsString(
                    'strictly greater',
                    $unresolved->getMessage(),
                    'The refusal must name the invariant, not just the setting.',
                );
            }
        }
    }

    /**
     * The value is NOT silently raised to make the pair consistent.
     *
     * Clamping would resolve the configuration error by replacing the number the
     * operator typed with a number they did not — which is precisely the "security
     * decision made by accident" the whole policy class exists to prevent.
     */
    public function test_the_invariant_violation_does_not_silently_raise_the_absolute_lifetime(): void
    {
        $policy = new SecurityPolicy([
            'session' => ['idle_timeout_seconds' => 43200, 'absolute_lifetime_seconds' => 900],
        ]);

        try {
            $policy->sessionAbsoluteLifetimeSeconds();
            $this->fail('Expected a refusal.');
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertStringContainsString('43200', $unresolved->getMessage());
            $this->assertStringContainsString('900', $unresolved->getMessage());
        }
    }

    public function test_a_correctly_ordered_pair_resolves(): void
    {
        $policy = $this->securityPolicyWith([
            'session' => ['idle_timeout_seconds' => 900, 'absolute_lifetime_seconds' => 901],
        ]);

        $this->assertSame(901, $policy->sessionAbsoluteLifetimeSeconds());
        $this->assertSame(
            900,
            $policy->sessionIdleTimeoutSeconds(),
            'Reading a valid absolute lifetime must not disturb the idle timeout.',
        );
    }

    /**
     * A broken absolute lifetime must not be allowed to break the idle timeout
     * as collateral damage — the two are read through separate accessors, and
     * the invariant is enforced in the one that owns it.
     */
    public function test_a_broken_absolute_lifetime_does_not_break_the_idle_timeout(): void
    {
        $policy = $this->securityPolicyWith([
            'session' => ['idle_timeout_seconds' => 900, 'absolute_lifetime_seconds' => 900],
        ]);

        $this->assertSame(
            900,
            $policy->sessionIdleTimeoutSeconds(),
            'The idle timeout is still a readable, valid value on its own.',
        );

        $this->expectException(SecurityPolicyUnresolved::class);

        $policy->sessionAbsoluteLifetimeSeconds();
    }

    // =====================================================================
    // 3. SEC-007 hashing
    // =====================================================================

    /**
     * An UNREGISTERED algorithm name must fail closed like any other unusable
     * value, not escape as a framework exception.
     *
     * `Illuminate\Support\Manager::createDriver()` throws a raw
     * `\InvalidArgumentException` for a driver it cannot build. Before this was
     * handled, a typo in `SECURITY_PASSWORD_ALGORITHM` escaped `SecurityPolicy`
     * as that exception, which is not a `DomainFailure`, carries no
     * `SERVICE_UNAVAILABLE`, and would reach the error renderer as a 500.
     *
     * The three requirements are asserted separately, because each can fail
     * alone: the right exception class, the right decision, and the right
     * status. The explicit negative assertion names the cause instead of the
     * symptom.
     */
    public function test_an_unregistered_algorithm_fails_closed_instead_of_escaping(): void
    {
        $policy = $this->securityPolicyWith([
            'password_hashing' => ['algorithm' => 'definitely-invalid'],
        ]);

        try {
            $policy->passwordHasher();

            $this->fail('Expected SecurityPolicyUnresolved for an unregistered algorithm.');
        } catch (InvalidArgumentException $framework) {
            $this->fail(
                'A framework InvalidArgumentException escaped the policy: '.$framework->getMessage()
            );
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame('SEC-007', $unresolved->decision);
            $this->assertSame('security.password_hashing.algorithm', $unresolved->setting);
            $this->assertSame(ErrorCode::ServiceUnavailable, $unresolved->errorCode);
            $this->assertSame(503, $unresolved->errorCode->httpStatus());
        }
    }

    public function test_every_unregistered_algorithm_name_fails_closed(): void
    {
        foreach (['definitely-invalid', 'md5', 'PASSWORD_BCRYPT', 'bcrypt2', 'argon2', ''] as $name) {
            $policy = $this->securityPolicyWith([
                'password_hashing' => ['algorithm' => $name],
            ]);

            $this->assertRefuses(
                fn (SecurityPolicy $p) => $p->passwordHasher(),
                'SEC-007',
                $policy,
                'algorithm: '.$name,
            );
        }
    }

    public function test_a_registered_algorithm_still_resolves(): void
    {
        $policy = $this->securityPolicyWith([
            'password_hashing' => ['algorithm' => 'test_only_hasher'],
        ]);

        [$hasher, $algorithm] = $policy->passwordHasher();

        $this->assertInstanceOf(Hasher::class, $hasher);
        $this->assertSame('test_only_hasher', $algorithm);
    }

    public function test_malformed_password_hashing_options_fail_closed(): void
    {
        // The last two are the interesting ones. `memory=1,time=1,memory=2` is a
        // setting nobody decided which half wins, and last-wins would be a
        // silent coin toss on a security value. `memory_cost=65536` uses the
        // `password_hash()` spelling; it is ACCEPTED and normalised, not refused
        // — see the test below that proves the normalisation.
        foreach (['not-a-pair', 'rounds=', '=12', 'memory=abc', 'memory=1.5', 'memory=-12', 'memory=1,time=1,memory=2', 'colour=blue'] as $bad) {
            $policy = $this->securityPolicyWith([
                'password_hashing' => ['options' => $bad],
            ]);

            $this->assertRefuses(
                fn (SecurityPolicy $p) => $p->passwordHashingOptions(),
                'SEC-007',
                $policy,
                'options: '.$bad,
            );
        }
    }

    /**
     * Both option spellings are accepted, and the driver's spelling comes back.
     *
     * `ArgonHasher::make()` reads `$options['memory']` / `$options['time']` /
     * `$options['threads']` and then passes them to `password_hash()` under the
     * names `memory_cost` / `time_cost` / `threads`. The two sets of names
     * differ, only the first one works, and an operator writing the second one
     * would otherwise get a silent fallback to Laravel's 1 MiB default.
     */
    public function test_both_option_spellings_are_accepted_and_normalised(): void
    {
        $driverSpelling = $this->securityPolicyWith([
            'password_hashing' => ['options' => 'memory=65536,time=2,threads=1'],
        ])->passwordHashingOptions();

        $phpSpelling = $this->securityPolicyWith([
            'password_hashing' => ['options' => 'memory_cost=65536,time_cost=2,threads=1'],
        ])->passwordHashingOptions();

        $this->assertSame(['memory' => 65536, 'time' => 2, 'threads' => 1], $driverSpelling);
        $this->assertSame($driverSpelling, $phpSpelling, 'Both spellings must produce the same options.');
    }

    // =====================================================================
    // 4. MFA
    // =====================================================================

    /**
     * SMS and email are refused, and the refusal says WHY.
     *
     * A bare "invalid value" for something an operator deliberately chose is the
     * most frustrating possible answer, and an operator who does not understand
     * the reason will look for a way around it.
     */
    public function test_sms_and_email_are_refused_as_second_factors_with_a_reason(): void
    {
        foreach (['sms' => 'SIM swap', 'email' => 'credential-reset'] as $method => $expected) {
            $policy = $this->securityPolicyWith(['mfa' => ['primary' => $method]]);

            try {
                $policy->mfaPrimaryMethod();
                $this->fail("Expected {$method} to be refused as a second factor.");
            } catch (SecurityPolicyUnresolved $unresolved) {
                $this->assertSame('SEC-001', $unresolved->decision);
                $this->assertStringContainsString(
                    $expected,
                    $unresolved->getMessage(),
                    "The {$method} refusal must name the reason it is not a factor.",
                );
                $this->assertStringContainsString(
                    MfaMethod::Totp->value,
                    $unresolved->getMessage(),
                    'The refusal must say where to go instead.',
                );
            }
        }
    }

    public function test_a_completely_unrecognised_mfa_method_is_refused_too(): void
    {
        $policy = $this->securityPolicyWith(['mfa' => ['primary' => 'carrier-pigeon']]);

        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->mfaPrimaryMethod(),
            'SEC-001',
            $policy,
        );
    }

    /**
     * `ADR-0014` §5 is the source of the required set, so a name that is not one
     * of the twelve roles cannot appear in it.
     */
    public function test_a_required_role_that_does_not_exist_is_refused(): void
    {
        $policy = $this->securityPolicyWith([
            'mfa' => ['required_roles' => ['GROUP_MANAGER', 'HEAD_HOTELIER']],
        ]);

        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->mfaRequiredRoles(),
            'SEC-007/SEC-008/B-05',
            $policy,
        );
    }

    public function test_an_unparseable_mfa_required_roles_list_is_refused(): void
    {
        $policy = $this->securityPolicyWith(['mfa' => ['required_roles' => 'GROUP_MANAGER']]);

        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->mfaRequiredRoles(),
            'SEC-007/SEC-008/B-05',
            $policy,
        );
    }

    /**
     * A TOTP digit count outside 6–8, or a period outside 15–120 s, is not a
     * preference — it is a value no mainstream authenticator app can answer, and
     * that failure looks identical to a broken second factor.
     */
    public function test_a_totp_parameter_outside_its_usable_range_is_refused_rather_than_clamped(): void
    {
        // The failing setting is NAMED in the case rather than recovered from
        // the array at runtime. `array_key_first()` gives a `string` but not one
        // the analyser can tie back to the specific offset, so the label and the
        // override stay together and the refusal message is trustworthy.
        $cases = [
            'digits=4' => ['digits' => 4],
            'digits=9' => ['digits' => 9],
            'period_seconds=5' => ['period_seconds' => 5],
            'period_seconds=600' => ['period_seconds' => 600],
            'window=9' => ['window' => 9],
        ];

        foreach ($cases as $label => $bad) {
            $policy = $this->securityPolicyWith(['mfa' => $bad]);

            $this->assertRefuses(
                fn (SecurityPolicy $p) => $p->totpParameters(),
                'SEC-007/SEC-008/B-05',
                $policy,
                $label,
            );
        }
    }

    public function test_the_borderline_totp_parameters_are_accepted(): void
    {
        $low = $this->securityPolicyWith([
            'mfa' => ['digits' => 6, 'period_seconds' => 15, 'window' => 0],
        ])->totpParameters();

        $high = $this->securityPolicyWith([
            'mfa' => ['digits' => 8, 'period_seconds' => 120, 'window' => 2],
        ])->totpParameters();

        $this->assertSame(6, $low->digits);
        $this->assertSame(1, $low->candidateCounters(), 'A window of 0 accepts only the current period.');
        $this->assertSame(8, $high->digits);
        $this->assertSame(5, $high->candidateCounters());
    }

    // =====================================================================
    // 5. Headers
    // =====================================================================

    /**
     * HSTS is ABSENT until the deployment guarantee is asserted, and this is the
     * shipped state.
     *
     * Once a browser has seen the header it refuses plaintext for `max-age` with
     * no user override, and `includeSubDomains` extends that to hosts this
     * application does not control. Turning it on before TLS is correct
     * everywhere is not a hardening win; it is an availability decision about the
     * whole estate taken by whoever filled in a config field.
     */
    public function test_hsts_is_absent_until_the_deployment_guarantee_is_asserted(): void
    {
        $this->assertNull(
            $this->securityPolicyWith(['security_headers' => ['hsts' => ['enabled' => false]]])
                ->strictTransportSecurity(),
        );

        $this->assertNull(
            $this->unresolvedSecurityPolicy()->strictTransportSecurity(),
            'A policy with no HSTS configuration must omit the header, not guess at it.',
        );
    }

    public function test_hsts_is_composed_only_once_enabled(): void
    {
        $directive = $this->securityPolicyWith([
            'security_headers' => ['hsts' => [
                'enabled' => true,
                'max_age' => 31536000,
                'include_sub_domains' => true,
                'preload' => false,
            ]],
        ])->strictTransportSecurity();

        $this->assertSame('max-age=31536000; includeSubDomains', $directive);
        $this->assertStringNotContainsString('preload', (string) $directive);
    }

    /**
     * A header getter returns a header or `null`, and NEVER a refusal.
     *
     * A refusal would be wrong: an undecided header is not a reason to refuse to
     * serve a response, and turning a policy question into a 503 would take the
     * whole application down over a response header.
     */
    public function test_header_getters_return_a_value_or_null_never_a_refusal(): void
    {
        $configured = $this->securityPolicyWith();
        $absent = $this->unresolvedSecurityPolicy();

        $this->assertNotNull($configured->contentSecurityPolicy());
        $this->assertSame('nosniff', $configured->xContentTypeOptions());
        $this->assertSame('DENY', $configured->xFrameOptions());
        $this->assertSame('no-referrer', $configured->referrerPolicy());
        $this->assertNotNull($configured->permissionsPolicy());

        $this->assertNull($absent->contentSecurityPolicy());
        $this->assertNull($absent->xContentTypeOptions());
        $this->assertNull($absent->xFrameOptions());
        $this->assertNull($absent->referrerPolicy());
        $this->assertNull($absent->permissionsPolicy());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function assertRefuses(
        callable $access,
        string $expectedDecision,
        ?SecurityPolicy $policy = null,
        string $context = '',
    ): void {
        try {
            $access($policy ?? $this->unresolvedSecurityPolicy());
            $this->fail('Expected SecurityPolicyUnresolved. '.$context);
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame(
                $expectedDecision,
                $unresolved->decision,
                'The refusal must name the decision that has to be made. '.$context,
            );

            $this->assertNotSame('', $unresolved->setting, 'The refusal must name the setting. '.$context);
        }
    }
}
