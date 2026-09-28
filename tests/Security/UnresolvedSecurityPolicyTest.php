<?php

declare(strict_types=1);

namespace Tests\Security;

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
 * The shipped state of every undecided security value is `null`, and a `null`
 * must REFUSE rather than become a default.
 *
 * `docs/SECURITY.md` §6 records the password algorithm, both session lifetimes,
 * the step-up freshness window, the header set, and the rate-limit values as
 * `TBD`. `T-004` §Risks: "implement the mechanism, and do not invent the
 * parameters. A wrong session lifetime is a security decision made by
 * accident."
 *
 * So this suite proves two separate things, and they are not the same thing:
 *
 *   1. THE CONFIGURATION IS EMPTY. Every leaf under `config/security.php` is
 *      `null`. If someone fills one in, this suite fails — which is correct,
 *      because a value can only enter with the owner's decision, and the
 *      decision is not recorded anywhere yet.
 *   2. THE MECHANISM REFUSES. Each accessor raises a typed
 *      `SecurityPolicyUnresolved` carrying the decision id, and never returns a
 *      number.
 *
 * Nothing here asserts what a session SHOULD last or what a hash SHOULD use.
 */
final class UnresolvedSecurityPolicyTest extends TestCase
{
    use ResolvesSecurityPolicy;

    protected function setUp(): void
    {
        parent::setUp();

        // One driver is registered so the "unregistered name is refused" tests
        // can be told apart from "every name is refused". The name is the same
        // TEST-ONLY fixture the other `T-004` suites use, and it registers
        // nothing in `config/security.php`.
        Hash::extend(
            'test_only_hasher',
            static fn (): Hasher => new BcryptHasher(['rounds' => 4]),
        );
    }

    public function test_every_shipped_security_value_is_unresolved(): void
    {
        $leaves = $this->flatten((array) config('security', []));

        $this->assertNotEmpty($leaves, 'config/security.php must define the policy surface.');

        $resolved = array_keys(array_filter($leaves, static fn (mixed $v): bool => $v !== null));

        $this->assertSame(
            [],
            $resolved,
            'These security values are set, but no owner has decided them. A value here is an '
            .'invented security decision: '.implode(', ', $resolved)
        );
    }

    public function test_the_password_algorithm_refuses_with_sec_007(): void
    {
        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->passwordHasher(),
            'SEC-007',
        );
    }

    public function test_the_session_idle_timeout_refuses_with_sec_008(): void
    {
        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->sessionIdleTimeoutSeconds(),
            'SEC-008',
        );
    }

    public function test_the_session_absolute_lifetime_refuses_with_sec_008(): void
    {
        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->sessionAbsoluteLifetimeSeconds(),
            'SEC-008',
        );
    }

    public function test_the_rate_limit_refuses_with_b_05(): void
    {
        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->authenticationRateLimit(),
            'B-05',
        );
    }

    public function test_the_lockout_refuses_with_b_05(): void
    {
        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->lockout(),
            'B-05',
        );
    }

    public function test_the_step_up_freshness_refuses_with_sec_008(): void
    {
        $this->assertRefuses(
            fn (SecurityPolicy $p) => $p->stepUpFreshnessSeconds(),
            'SEC-008',
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
            SecurityPolicy::fromConfig()->sessionIdleTimeoutSeconds();
            $this->fail('Expected the idle timeout to refuse.');
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame(ErrorCode::ServiceUnavailable, $unresolved->errorCode);
            $this->assertSame(503, $unresolved->errorCode->httpStatus());
        }
    }

    /**
     * The work factor is NOT defaulted either.
     *
     * An empty array is returned, not `['rounds' => 12]` or anything else. The
     * caller therefore has nothing to pass, and the owner still has to decide.
     */
    public function test_the_work_factor_is_not_defaulted(): void
    {
        $this->assertSame([], SecurityPolicy::fromConfig()->passwordHashingOptions());
    }

    /**
     * `config/session.php` ships Laravel's stock `'lifetime' => 120`.
     *
     * That number is a DECOY, and this test exists so it stays one. If any
     * authentication code ever reads it as a session lifetime, the owner would
     * have made a security decision by accident — precisely the outcome
     * `T-004` §Risks names. The policy refuses even though a plausible number
     * is sitting in the configuration one file away.
     */
    public function test_laravels_stock_session_lifetime_is_not_adopted(): void
    {
        $stock = config('session.lifetime');

        $this->assertNotNull($stock, 'Laravel ships a stock session lifetime; the decoy must exist to be refused.');

        $this->expectException(SecurityPolicyUnresolved::class);

        SecurityPolicy::fromConfig()->sessionIdleTimeoutSeconds();
    }

    /**
     * The four header getters return `null` rather than a header and rather
     * than a refusal.
     *
     * A refusal would be wrong: an unset header is not a reason to refuse to
     * authenticate. Returning `null` leaves the decision — and the header set —
     * exactly where `docs/SECURITY.md` §6 and `API-SPEC` §5 put it: `TBD`.
     */
    public function test_no_security_header_is_invented(): void
    {
        $policy = SecurityPolicy::fromConfig();

        $this->assertNull($policy->contentSecurityPolicy());
        $this->assertNull($policy->strictTransportSecurity());
        $this->assertNull($policy->referrerPolicy());
        $this->assertNull($policy->permissionsPolicy());
    }

    /**
     * An UNREGISTERED algorithm name must fail closed like any other unresolved
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
     * status. `catch (SecurityPolicyUnresolved)` alone would already fail the
     * test on an `InvalidArgumentException`; the explicit negative assertion
     * says so, so the failure names the cause instead of the symptom.
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

    /**
     * The same refusal for every unregistered name, not just one.
     *
     * A single example could pass for a value that happens to be special-cased.
     * These are the plausible ways an operator gets this wrong: a real algorithm
     * name belonging to another library, a PHP constant, and a name with the
     * right prefix but no driver behind it.
     */
    public function test_every_unregistered_algorithm_name_fails_closed(): void
    {
        foreach (['definitely-invalid', 'md5', 'PASSWORD_BCRYPT', 'bcrypt2', 'argon2'] as $name) {
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

    /**
     * A registered algorithm still resolves. Without this, the refusal above
     * could be satisfied by refusing everything.
     */
    public function test_a_registered_algorithm_still_resolves(): void
    {
        $policy = $this->securityPolicyWith([
            'password_hashing' => ['algorithm' => 'test_only_hasher'],
        ]);

        [$hasher, $algorithm] = $policy->passwordHasher();

        $this->assertInstanceOf(Hasher::class, $hasher);
        $this->assertSame('test_only_hasher', $algorithm);
    }

    /**
     * MALFORMED password-hashing options still fail closed.
     *
     * `passwordHashingOptions()` has no runtime effect in `T-004` because there
     * is no password-creation path, but it is still the single place the value
     * is parsed, so a malformed one must be refused by name rather than
     * silently ignored. `T-004` has no test for this case.
     */
    public function test_malformed_password_hashing_options_fail_closed(): void
    {
        foreach (['not-a-pair', 'rounds=', '=12', 'rounds=abc', 'rounds=1.5', 'rounds=-12'] as $bad) {
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
     * A value that is present but unusable is reported as a configuration
     * defect, not silently coerced.
     */
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
     * Zero, a negative number, and a non-numeric string are all refused.
     *
     * A zero timeout would end every session instantly and a negative one would
     * do the same; both are configuration errors, and neither may be treated as
     * "no limit".
     */
    public function test_a_non_positive_or_non_numeric_value_is_refused(): void
    {
        foreach (['0', '-60', 'soon', '1e9'] as $bad) {
            $policy = new SecurityPolicy(['session' => ['idle_timeout_seconds' => $bad]]);

            $this->assertRefuses(
                fn (SecurityPolicy $p) => $p->sessionIdleTimeoutSeconds(),
                'SEC-008',
                $policy,
                'value: '.$bad,
            );
        }
    }

    private function assertRefuses(
        callable $access,
        string $expectedDecision,
        ?SecurityPolicy $policy = null,
        string $context = '',
    ): void {
        try {
            $access($policy ?? SecurityPolicy::fromConfig());
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

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function flatten(array $config, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $leaves += $this->flatten($value, $path);

                continue;
            }

            $leaves[$path] = $value;
        }

        return $leaves;
    }
}
