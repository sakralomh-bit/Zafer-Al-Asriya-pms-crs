<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\AuthenticationFailed;
use App\Modules\Identity\Auth\AuthenticationService;
use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Auth\SecurityPolicyUnresolved;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditAction;
use App\Shared\Domain\ErrorCode;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTestFixtures;
use Tests\Support\RecordingHasher;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * `AC-T-004-01`, `AC-T-004-02`, and the `docs/SECURITY.md` `TH-01` requirement
 * that authentication must not disclose whether an account exists.
 *
 * ================================================================================
 * THE HASHING ALGORITHM IS `SEC-007` AND IS NOT CHOSEN BY THIS SUITE.
 * ================================================================================
 *
 * The policy value under test is the driver NAME `test_only_hasher`, which
 * exists nowhere in `config/security.php` and is registered by this class to a
 * closure that returns a recording wrapper. What is being proven is that the
 * policy selects a driver by name and that all three login paths perform the
 * same cryptographic work — not that any particular algorithm is correct. The
 * framework's own `BcryptHasher` at 4 rounds sits behind the wrapper purely as
 * a fast fixture primitive; the approved algorithm remains undecided.
 *
 * The lifetime, rate-limit, and lockout values come from
 * `ResolvesSecurityPolicy` and are likewise fixtures.
 *
 * There are no HTTP routes in this project yet (`T-004` Stage 3), so the
 * service is exercised directly, which is how every other security test in
 * this repository is written.
 */
final class AuthenticationTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    /** Synthetic, not a credential. `.test` is an RFC 2606 reserved domain. */
    private const PASSWORD = 'synthetic-not-a-real-password';

    private const KNOWN_EMAIL = 'staff.auth@example.test';

    private RecordingHasher $hasher;

    private AuthenticationService $authentication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->useDatabaseSessionDriver();

        // `HashManager` invokes a custom creator through its own bound scope, so
        // a closure capturing `$this` is bound to the manager, not to the test.
        // A `static` closure has no `$this` at all and captures the wrapper
        // lexically, which is what makes the recorded call counts land here.
        $recording = new RecordingHasher(new BcryptHasher(['rounds' => 4]));
        Hash::extend('test_only_hasher', static fn (): RecordingHasher => $recording);
        $this->hasher = $recording;

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());
        $this->authentication = $this->app->make(AuthenticationService::class);
    }

    // =====================================================================
    // The success path
    // =====================================================================

    public function test_valid_credentials_establish_a_session(): void
    {
        $user = $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);

        $request = $this->loginRequest();

        $authenticated = $this->authentication->login($request, self::KNOWN_EMAIL, self::PASSWORD, 'corr-ok-1');

        $this->assertTrue($authenticated->is($user));
        $this->assertTrue($this->hasher->checkCalls() >= 1);
    }

    public function test_a_successful_login_records_the_time_of_the_last_login(): void
    {
        $user = $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);

        $this->assertNull($user->last_login_at);

        $this->authentication->login($this->loginRequest(), self::KNOWN_EMAIL, self::PASSWORD, 'corr-ok-2');

        $this->assertNotNull($this->reread($user)->last_login_at);
    }

    // =====================================================================
    // The three failure paths, and the one answer they must share
    // =====================================================================

    public function test_a_wrong_password_is_refused(): void
    {
        $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);

        $failure = $this->captureFailure(
            fn () => $this->authentication->login(
                $this->loginRequest(),
                self::KNOWN_EMAIL,
                'synthetic-wrong-password',
                'corr-bad-1',
            ),
        );

        $this->assertSame(ErrorCode::AuthFailed, $failure->errorCode);
        $this->assertSame(401, $failure->errorCode->httpStatus());
    }

    public function test_an_unknown_account_is_refused_identically(): void
    {
        $failure = $this->captureFailure(
            fn () => $this->authentication->login(
                $this->loginRequest(),
                'nobody.here@example.test',
                self::PASSWORD,
                'corr-bad-2',
            ),
        );

        $this->assertSame(ErrorCode::AuthFailed, $failure->errorCode);
    }

    /**
     * `TH-01` / `AC-T-004-01`: an inactive account is `AUTH_FAILED`, never
     * `ACCOUNT_SUSPENDED`.
     *
     * Reporting the lifecycle state here would confirm the account exists to
     * anyone who guesses the address. `ACCOUNT_SUSPENDED` exists and is used by
     * the AUTHORIZATION layer for an already-authenticated actor.
     */
    public function test_an_inactive_account_is_refused_identically(): void
    {
        $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager, User::STATUS_SUSPENDED);

        $failure = $this->captureFailure(
            fn () => $this->authentication->login(
                $this->loginRequest(),
                self::KNOWN_EMAIL,
                self::PASSWORD,
                'corr-bad-3',
            ),
        );

        $this->assertSame(ErrorCode::AuthFailed, $failure->errorCode);
        $this->assertNotSame(ErrorCode::AccountSuspended, $failure->errorCode);
    }

    /**
     * All three refusals carry the SAME message.
     *
     * If the unknown-account and wrong-password messages differed, the error
     * body alone would enumerate accounts and none of the timing work below
     * would matter.
     */
    public function test_every_refusal_carries_the_same_message(): void
    {
        $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);

        $messages = [
            $this->captureFailure(fn () => $this->authentication->login(
                $this->loginRequest(), 'nobody.here@example.test', self::PASSWORD, 'corr-msg-1',
            ))->getMessage(),

            $this->captureFailure(fn () => $this->authentication->login(
                $this->loginRequest(), self::KNOWN_EMAIL, 'synthetic-wrong-password', 'corr-msg-2',
            ))->getMessage(),

            $this->captureFailure(fn () => $this->authentication->login(
                $this->loginRequest(), self::KNOWN_EMAIL, '', 'corr-msg-3',
            ))->getMessage(),
        ];

        $this->assertCount(1, array_unique($messages), 'Every refusal must read identically.');
    }

    /**
     * The timing defence, asserted as a COUNT rather than as a stopwatch.
     *
     * The unknown-account path must perform a verification against a throwaway
     * hash, so that it costs what the wrong-password path costs. A timing
     * assertion would be a flake generator; the count is deterministic and is
     * the thing the defence actually rests on. If the dummy verification is
     * removed, `checkCalls` on this path drops and this test fails.
     */
    public function test_an_unknown_account_still_performs_a_hash_verification(): void
    {
        $this->hasher->reset();

        $this->captureFailure(fn () => $this->authentication->login(
            $this->loginRequest(), 'nobody.here@example.test', self::PASSWORD, 'corr-timing-1',
        ));

        $this->assertSame(
            1,
            $this->hasher->checkCalls(),
            'The unknown-account path must verify against a throwaway hash, or it is measurably faster.',
        );

        $this->assertSame(
            1,
            $this->hasher->makeCalls(),
            'Exactly one throwaway hash is created; a second would make this path slower instead.',
        );
    }

    public function test_a_wrong_password_performs_exactly_one_verification(): void
    {
        $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);
        $this->hasher->reset();

        $this->captureFailure(fn () => $this->authentication->login(
            $this->loginRequest(), self::KNOWN_EMAIL, 'synthetic-wrong-password', 'corr-timing-2',
        ));

        $this->assertSame(1, $this->hasher->checkCalls());
    }

    public function test_a_successful_login_performs_exactly_one_verification(): void
    {
        $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);
        $this->hasher->reset();

        $this->authentication->login($this->loginRequest(), self::KNOWN_EMAIL, self::PASSWORD, 'corr-timing-3');

        $this->assertSame(1, $this->hasher->checkCalls());
    }

    // =====================================================================
    // Fail-closed: the unresolved policy stops authentication entirely
    // =====================================================================

    /**
     * With `SEC-007` unresolved, NOBODY can authenticate — not even with a
     * correct password. That is the point of refusing rather than defaulting.
     */
    public function test_an_unresolved_algorithm_refuses_everyone(): void
    {
        $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);

        $this->app->instance(SecurityPolicy::class, $this->unresolvedSecurityPolicy());
        $authentication = $this->app->make(AuthenticationService::class);

        $this->expectException(SecurityPolicyUnresolved::class);

        $authentication->login($this->loginRequest(), self::KNOWN_EMAIL, self::PASSWORD, 'corr-closed-1');
    }

    public function test_an_unresolved_rate_limit_refuses_before_any_password_is_examined(): void
    {
        $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'authentication_rate_limit' => ['max_attempts' => null, 'decay_seconds' => null],
        ]));
        $authentication = $this->app->make(AuthenticationService::class);

        $this->hasher->reset();

        try {
            $authentication->login($this->loginRequest(), self::KNOWN_EMAIL, self::PASSWORD, 'corr-closed-2');
            $this->fail('Expected the unresolved rate limit to refuse.');
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame('B-05', $unresolved->decision);
            $this->assertSame(
                0,
                $this->hasher->checkCalls(),
                'The limiter is a pre-authentication gate: it must refuse before the password is touched.',
            );
        }
    }

    // =====================================================================
    // Nothing secret is written anywhere
    // =====================================================================

    /**
     * The audit trail is APPEND-ONLY, so a credential written into it is a
     * credential that can never be rotated away. Not one field of it may
     * contain the password, the hash, or the session identifier.
     */
    public function test_no_credential_reaches_the_audit_trail(): void
    {
        $user = $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);
        $storedHash = (string) $user->password;

        $this->authentication->login($this->loginRequest(), self::KNOWN_EMAIL, self::PASSWORD, 'corr-secret-1');
        $this->captureFailure(fn () => $this->authentication->login(
            $this->loginRequest(), self::KNOWN_EMAIL, 'synthetic-wrong-password', 'corr-secret-2',
        ));
        $this->authentication->logout($this->loginRequest(), 'corr-secret-3');

        $rows = DB::table('audit_events')->get()->map(static fn (object $r): string => json_encode($r) ?: '')->all();
        $haystack = implode("\n", $rows);

        $this->assertNotEmpty($rows, 'The authentication flow must have produced audit rows.');

        $this->assertStringNotContainsString(self::PASSWORD, $haystack, 'The password reached the audit trail.');
        $this->assertStringNotContainsString($storedHash, $haystack, 'The password hash reached the audit trail.');

        $this->assertStringNotContainsString(
            $this->app['session.store']->getId(),
            $haystack,
            'The session identifier reached the audit trail.',
        );
    }

    /**
     * The submitted email is not recorded on a failure either. A trail that
     * stores every guessed address is a list of candidate credentials.
     */
    public function test_a_failed_login_does_not_record_the_submitted_identifier(): void
    {
        $this->captureFailure(fn () => $this->authentication->login(
            $this->loginRequest(), 'nobody.here@example.test', self::PASSWORD, 'corr-secret-4',
        ));

        $reason = DB::table('audit_events')
            ->where('action', AuditAction::AuthFailed->value)
            ->value('reason');

        $this->assertSame('no_matching_account', $reason);
        $this->assertStringNotContainsString(
            'nobody.here@example.test',
            (string) $reason,
            'The audit reason must be a fixed vocabulary, never the submitted identifier.',
        );
    }

    public function test_the_user_agent_is_recorded_as_a_digest(): void
    {
        $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);

        $request = $this->loginRequest();
        $request->headers->set('User-Agent', 'SyntheticBrowser/1.0');

        $this->authentication->login($request, self::KNOWN_EMAIL, self::PASSWORD, 'corr-secret-5');

        $context = (string) DB::table('audit_events')
            ->where('action', AuditAction::AuthSucceeded->value)
            ->value('context');

        $this->assertStringNotContainsString('SyntheticBrowser/1.0', $context);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->userAgentHashFrom($context));
    }

    /**
     * The model never serialises its own credential, so an accidental
     * `toArray()` in a log or an API response cannot leak it.
     */
    public function test_the_user_model_hides_its_own_credential(): void
    {
        $user = $this->makeUserWithPassword(self::KNOWN_EMAIL, Role::HotelManager);

        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertStringNotContainsString((string) $user->password, $user->toJson());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function makeUserWithPassword(string $email, Role $role, string $status = User::STATUS_ACTIVE): User
    {
        $user = $this->makeUser($email, $role, $status);

        $user->forceFill(['password' => $this->hasher->make(self::PASSWORD)])->save();

        return $this->reread($user);
    }

    private function captureFailure(callable $attempt): AuthenticationFailed
    {
        try {
            $attempt();
        } catch (AuthenticationFailed $failed) {
            return $failed;
        }

        $this->fail('Expected the authentication attempt to be refused.');
    }

    private function seedRolesAndPermissions(): void
    {
        $this->seed(AuthorizationCatalogueSeeder::class);
    }

    /**
     * The session driver is switched to `database` for this suite.
     *
     * `phpunit.xml` sets `array` globally, which is right for tests that do not
     * care but wrong here: `AC-T-004-04` revokes sessions by deleting their
     * server-side rows, and an `array` driver has none. Testing against a
     * different driver than the application runs would prove nothing.
     */
    private function useDatabaseSessionDriver(): void
    {
        config(['session.driver' => 'database']);

        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth');
    }

    private function loginRequest(): Request
    {
        $request = Request::create('/api/v1/auth/login', 'POST');

        $store = $this->app['session']->driver();
        $store->start();
        $request->setLaravelSession($store);

        return $request;
    }

    private function userAgentHashFrom(string $jsonContext): string
    {
        $decoded = json_decode($jsonContext, true);

        return is_array($decoded) ? (string) ($decoded['user_agent_hash'] ?? '') : '';
    }
}
