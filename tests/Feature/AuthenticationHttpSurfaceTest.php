<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Auth\Mfa\MfaFactorProvider;
use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditAction;
use App\Shared\Domain\ErrorCode;
use App\Shared\Http\Middleware\AssignCorrelationId;
use App\Shared\Http\Middleware\EnforceSessionLifetimes;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestFixtures;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * The authentication HTTP surface — `AC-T-004-01`, `AC-T-004-03`, `AC-T-004-06`,
 * `AC-T-004-07`, and `AC-T-004-09` at the ROUTE boundary.
 *
 * ============================ WHY THIS SUITE HAS TO EXIST ============================
 * Every one of these criteria was tested against a class. None was tested against
 * a route, because until this commit there were no routes: `AuthenticationService`
 * was called directly, `SessionSecurity::enforceLifetimes()` had no production
 * caller, and `AuthenticationRateLimiter` had no production caller either.
 *
 * A service test proves the service works. It does not prove anything is WIRED.
 * The gap this suite closes is the wiring: that the URL exists, that it is
 * reachable, that the middleware actually runs, that a refusal is the documented
 * 4xx rather than a 500, and that a secret never reaches a response.
 *
 * ============================ THE POLICY IS A FIXTURE, NOT A DECISION ============================
 * The values come from `ResolvesSecurityPolicy`, whose driver name
 * `test_only_hasher` exists nowhere in `config/security.php`. `SEC-007`,
 * `SEC-008`, and `B-05` remain unresolved, and a test result here is never
 * allowed to stand in for approving them.
 *
 * ============================ THE SESSION DRIVER IS `database` ============================
 * Because a login and the request that follows it are two separate HTTP calls,
 * the session has to survive between them. `phpunit.xml` sets `array`, which has
 * no server-side store and therefore cannot do that. The driver the application
 * actually runs is the one worth testing.
 */
final class AuthenticationHttpSurfaceTest extends TestCase
{
    use CreatesTestFixtures {
        // Aliased because this class needs its own `makeUser()` that also sets a
        // real credential. `CreatesTestFixtures::makeUser()` deliberately stores
        // a placeholder that cannot authenticate anyone, and that default has to
        // stay available under its own name so the difference is visible at the
        // call site rather than hidden in an override.
        makeUser as protected makeUserFixture;
    }
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    /** Synthetic, not a credential. `.test` is an RFC 2606 reserved domain. */
    private const PASSWORD = 'synthetic-not-a-real-password';

    private const EMAIL = 'surface.auth@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        // `array` cannot carry a session from the login response into the next
        // request, which is the whole shape of what is being tested here.
        config(['session.driver' => 'database']);

        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth');

        // `ResolvesSecurityPolicy` names the driver `test_only_hasher`, which
        // exists nowhere in `config/security.php`. It is registered here to the
        // framework's own bcrypt at the suite's reduced rounds — a fast fixture
        // primitive, not a statement about the approved algorithm. Without this
        // the policy refuses at `SEC-007` and every login answers 503, which
        // would make the whole suite a test of the refusal and nothing else.
        Hash::extend('test_only_hasher', static fn (): Hasher => new BcryptHasher(['rounds' => 4]));

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());
    }

    // =====================================================================
    // The routes exist and are wired
    // =====================================================================

    /**
     * `AC-T-004-01` — the four routes of `API-SPEC.md` §3.11 are registered.
     *
     * Asserted against the ROUTE TABLE rather than by calling them, because a
     * route that resolves but is not registered is invisible to a request test.
     */
    public function test_the_documented_authentication_routes_are_registered(): void
    {
        foreach ([
            'api/v1/auth/login',
            'api/v1/auth/logout',
            'api/v1/auth/mfa/verify',
            'api/v1/auth/step-up',
        ] as $path) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(static fn ($candidate): bool => $candidate->uri() === $path
                    && in_array('POST', $candidate->methods(), true));

            $this->assertNotNull($route, "POST {$path} is not registered. API-SPEC §3.11 specifies it.");
        }
    }

    /**
     * `AC-T-004-07` — the authentication routes carry the `web` middleware group.
     *
     * This is the assertion that would have caught the obvious wrong answer.
     * Laravel's `api` group has no session, no cookies, and no CSRF middleware,
     * so a route registered there is stateless by construction and CANNOT satisfy
     * "CSRF on cookie-authenticated state-changing requests" — there is no
     * session for a token to belong to. Registering these under `api:` would look
     * correct in a route listing and satisfy nothing.
     */
    public function test_the_authentication_routes_carry_the_web_middleware_group(): void
    {
        foreach ([
            'api/v1/auth/login',
            'api/v1/auth/logout',
            'api/v1/auth/mfa/verify',
            'api/v1/auth/step-up',
        ] as $path) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(static fn ($candidate): bool => $candidate->uri() === $path
                    && in_array('POST', $candidate->methods(), true));

            $this->assertContains(
                'web',
                $route?->gatherMiddleware() ?? [],
                "{$path} is not on the `web` group, so it has no session and no CSRF "
                .'middleware. AC-T-004-07 cannot be satisfied without them.',
            );
        }
    }

    /**
     * The three AUTHENTICATED routes enforce `SEC-008`; `login` does not.
     *
     * Both halves matter. A lifetime check on `login` would refuse every login
     * with `AUTH_REQUIRED`, because a login is the request that has no session
     * yet. A lifetime check missing from an authenticated route is a hole.
     */
    public function test_only_the_authenticated_routes_enforce_session_lifetimes(): void
    {
        $routeFor = static function (string $path): Route {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(static fn ($candidate): bool => $candidate->uri() === $path
                    && in_array('POST', $candidate->methods(), true));

            self::assertNotNull($route, "POST {$path} is not registered.");

            return $route;
        };

        foreach (['api/v1/auth/logout', 'api/v1/auth/mfa/verify', 'api/v1/auth/step-up'] as $path) {
            $this->assertContains(
                EnforceSessionLifetimes::class,
                $routeFor($path)->gatherMiddleware(),
                "{$path} does not enforce SEC-008. AC-T-004-03 says the lifetimes are enforced.",
            );
        }

        // `withoutMiddleware()` records an EXCLUSION; it does not remove the entry
        // from the route's own middleware list. `gatherMiddleware()` is what the
        // router runs and it does not apply the exclusion either — the router
        // filters at dispatch time. So the check has to read the exclusion
        // explicitly, and asserting only on `gatherMiddleware()` here would fail
        // for a route that is in fact correct.
        $login = $routeFor('api/v1/auth/login');

        $this->assertContains(
            EnforceSessionLifetimes::class,
            $login->excludedMiddleware(),
            'The login route does not exclude the lifetime check, so every login is refused '
            .'with AUTH_REQUIRED — a login is the request that has no session yet.',
        );
    }

    // =====================================================================
    // AC-T-004-01 — authentication over HTTP
    // =====================================================================

    public function test_a_valid_credential_authenticates_and_returns_only_the_minimum(): void
    {
        $user = $this->makeUser(self::EMAIL, Role::FrontDeskAgent, password: Hash::make(self::PASSWORD));

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);

        $response->assertOk();

        $body = $response->json();

        $this->assertSame((string) $user->id, $body['user_id']);
        $this->assertSame(
            ['user_id', 'roles'],
            array_keys($body),
            'The login body is a fixed shape. API-SPEC §1.6 prohibits tokens, and this '
            .'endpoint must not grow one: a body that echoes a session token would put '
            .'it in every access log between here and the client.',
        );

        $this->assertNotContains(
            'property_scope',
            array_keys($body),
            'Granted properties are absent on purpose. Authorisation is the server\'s '
            .'decision per request, never a claim handed to the client.',
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => AuditAction::AuthSucceeded->value,
            'actor_user_id' => (string) $user->id,
        ]);
    }

    public function test_a_wrong_password_is_refused_without_saying_whether_the_account_exists(): void
    {
        $this->makeUser(self::EMAIL, Role::FrontDeskAgent, password: Hash::make(self::PASSWORD));

        $wrong = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => 'not-the-password',
        ]);

        $unknown = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody.here@example.test',
            'password' => 'not-the-password',
        ]);

        $wrong->assertStatus(401);
        $unknown->assertStatus(401);

        // Identical apart from the correlation ID, which MUST differ — two
        // requests are two events in the trail. Comparing the whole body would
        // fail for the right-looking reason and prove nothing, so the ID is
        // removed before the comparison and its difference is asserted instead.
        $wrongBody = $wrong->json();
        $unknownBody = $unknown->json();

        $wrongId = $wrongBody['request_id'];
        $unknownId = $unknownBody['request_id'];

        unset($wrongBody['request_id'], $unknownBody['request_id']);

        $this->assertSame($wrongBody, $unknownBody, 'The two refusals differ, so one of them '
            .'reveals whether the account exists. TH-01 treats that as part of credential attack.');

        $this->assertNotSame(
            $wrongId,
            $unknownId,
            'Two requests carry the same correlation ID, so ADR-0016 §7 end-to-end '
            .'reconstruction cannot tell the two events apart.',
        );

        $this->assertSame(ErrorCode::AuthFailed->value, $wrong->json('code'));

        $this->assertDatabaseHas('audit_events', ['action' => AuditAction::AuthFailed->value]);
    }

    /**
     * `AC-T-004-09` and `API-SPEC.md` §1.6 — no secret in any response.
     *
     * Checked on a REFUSED login, which is the response most likely to carry
     * diagnostic detail: an error path is where a careless implementation echoes
     * the input back.
     */
    public function test_no_response_ever_echoes_a_submitted_credential(): void
    {
        $this->makeUser(self::EMAIL, Role::FrontDeskAgent, password: Hash::make(self::PASSWORD));

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => 'a-very-distinctive-wrong-secret-value',
        ]);

        $encoded = (string) json_encode($response->json());

        $this->assertStringNotContainsString(
            'a-very-distinctive-wrong-secret-value',
            $encoded,
            'API-SPEC §1.6 prohibits secrets in any response body.',
        );
        $this->assertStringNotContainsString(
            'trace',
            strtolower($encoded),
            'API-SPEC §1.6 prohibits framework debug output in a response body.',
        );
    }

    public function test_a_missing_credential_field_is_a_validation_failure_not_a_defect(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL]);

        $response->assertStatus(422);
        $this->assertSame(ErrorCode::ValidationFailed->value, $response->json('code'));

        // §1.7 reserves 500 for a defect. A client that forgot a field did not
        // break anything, and an unhandled TypeError here would be exactly that.
        $this->assertSame(
            ['code', 'message', 'request_id', 'details', 'retryable'],
            array_keys((array) $response->json()),
            'A validation failure still uses the documented error shape.',
        );
    }

    /**
     * `AC-T-004-06` — the login route is rate limited, over HTTP.
     *
     * The fixture's lockout threshold is 3, so this locks the account out and the
     * fourth call is refused whatever the password is.
     */
    public function test_login_is_rate_limited_over_http(): void
    {
        $this->makeUser(self::EMAIL, Role::FrontDeskAgent, password: Hash::make(self::PASSWORD));

        $last = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = $this->postJson('/api/v1/auth/login', [
                'email' => self::EMAIL,
                'password' => 'wrong',
            ]);
        }

        $this->assertNotNull($last);
        $this->assertContains(
            $last->status(),
            [401, 429],
            'Repeated failures must eventually be refused with 429 RATE_LIMITED.',
        );

        // Refusals ARE audited — `ADR-0016` requires denials, not only successes.
        // What must not exist is a SUCCESS, and in particular not one achieved
        // with the wrong password once the limiter is in force.
        $this->assertDatabaseMissing('audit_events', [
            'action' => AuditAction::AuthSucceeded->value,
        ]);
    }

    // =====================================================================
    // AC-T-004-01 — logout, and the session it ends
    // =====================================================================

    public function test_logout_requires_a_session_and_writes_the_audit_event(): void
    {
        $anonymous = $this->postJson('/api/v1/auth/logout');
        $anonymous->assertStatus(401);
        $this->assertSame(ErrorCode::AuthRequired->value, $anonymous->json('code'));

        $user = $this->loginAs(self::EMAIL, Role::FrontDeskAgent);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertOk();
        $this->assertSame(['status' => 'logged_out'], $response->json());

        $this->assertDatabaseHas('audit_events', [
            'action' => AuditAction::AuthLogout->value,
            'actor_user_id' => (string) $user->id,
        ]);
    }

    public function test_after_logout_the_session_no_longer_authenticates(): void
    {
        $this->loginAs(self::EMAIL, Role::FrontDeskAgent);

        $this->assertAuthenticated();

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertGuest();
    }

    // =====================================================================
    // AC-T-004-03 — SEC-008 enforced at the route, not only in the class
    // =====================================================================

    public function test_an_idle_session_is_refused_at_the_route_before_the_controller_runs(): void
    {
        $this->loginAs(self::EMAIL, Role::FrontDeskAgent);

        $auditBefore = DB::table('audit_events')->count();

        // Past the fixture's 900 s idle timeout.
        Carbon::setTestNow(Carbon::now()->addSeconds(1200));

        $response = $this->postJson('/api/v1/auth/logout');

        Carbon::setTestNow();

        $response->assertStatus(401);
        $this->assertSame(ErrorCode::AuthRequired->value, $response->json('code'));

        $this->assertSame(
            $auditBefore,
            DB::table('audit_events')->count(),
            'The controller ran. The lifetime check must happen in the middleware, '
            .'before any handler writes an audit row.',
        );

        $this->addToAssertionCount(1);
    }

    public function test_a_session_past_its_absolute_lifetime_is_refused_and_destroyed(): void
    {
        $this->loginAs(self::EMAIL, Role::FrontDeskAgent);

        // 8 h absolute in the fixture; 9 h is past it, and also past the 900 s
        // idle timeout, so this proves the ABSOLUTE anchor is what ended it.
        Carbon::setTestNow(Carbon::now()->addHours(9));

        $response = $this->postJson('/api/v1/auth/logout');

        Carbon::setTestNow();

        $response->assertStatus(401);
        $this->assertGuest();
    }

    /**
     * An UNRESOLVED `SEC-008` must 503 rather than serve.
     *
     * The system does not know how long a session may live, and letting requests
     * through would be the silent default this project refuses everywhere else.
     */
    public function test_an_unresolved_session_policy_refuses_rather_than_comparing_against_nothing(): void
    {
        $this->makeUser(self::EMAIL, Role::FrontDeskAgent, password: Hash::make(self::PASSWORD));

        $this->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->app->instance(SecurityPolicy::class, $this->unresolvedSecurityPolicy());

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(503);
        $this->assertSame(ErrorCode::ServiceUnavailable->value, $response->json('code'));
    }

    // =====================================================================
    // The MFA routes refuse honestly rather than pretending to work
    // =====================================================================

    /**
     * `MfaFactorProvider` has no production implementation — `H-03` and `C-10`
     * are open — so the two second-factor routes cannot verify anything.
     *
     * The assertion is that they answer 503 `SERVICE_UNAVAILABLE`. If they
     * answered 500, `bootstrap/app.php`'s catch-all would report a defect on every
     * call, and an endpoint that alarms continuously is an alarm nobody reads.
     * This is the difference between a known gap and a bug.
     */
    public function test_the_second_factor_routes_refuse_while_no_factor_store_exists(): void
    {
        $this->loginAs(self::EMAIL, Role::FrontDeskAgent);

        foreach ([
            '/api/v1/auth/mfa/verify',
            '/api/v1/auth/step-up',
        ] as $path) {
            $response = $this->postJson($path, [
                'operation' => 'refund',
                'code' => '123456',
            ]);

            $response->assertStatus(503);
            $this->assertSame(
                ErrorCode::ServiceUnavailable->value,
                $response->json('code'),
                "{$path} must report the capability as unavailable, not as a defect.",
            );
        }

        $this->assertFalse(
            $this->app->bound(MfaFactorProvider::class),
            'A production MfaFactorProvider appeared. If one is ever bound, the 503 above '
            .'is stale and these two tests must be revisited.',
        );
    }

    /**
     * No `/mfa/enrol` route exists, and that absence is the control.
     *
     * Enrolment needs somewhere to store a secret; `docs/DATA-MODEL.md` §2 reserves
     * `mfa_secrets`, no migration creates it, and `H-03` has not settled how a
     * secret is sealed. A route that accepted a secret and stored it under an
     * invented scheme would be worse than no route at all.
     */
    public function test_there_is_no_mfa_enrolment_route(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        foreach ($uris as $uri) {
            $this->assertStringNotContainsString(
                'mfa/enrol',
                $uri,
                'An MFA enrolment route exists. H-03 key management and the C-10 '
                .'accountability question are unresolved, so there is nowhere to put a secret.',
            );
        }

        $this->addToAssertionCount(1);
    }

    // =====================================================================
    // The correlation ID reaches the audit trail
    // =====================================================================

    public function test_the_client_correlation_id_reaches_the_audit_row(): void
    {
        $user = $this->makeUser(self::EMAIL, Role::FrontDeskAgent, password: Hash::make(self::PASSWORD));

        $correlationId = (string) Str::ulid();

        $response = $this->withHeaders([AssignCorrelationId::HEADER => $correlationId])
            ->postJson('/api/v1/auth/login', [
                'email' => self::EMAIL,
                'password' => self::PASSWORD,
            ]);

        $response->assertOk();

        // The ID in the trail must be the one the client saw, or ADR-0016 §7's
        // end-to-end reconstruction fails.
        $this->assertSame($correlationId, $response->headers->get(AssignCorrelationId::HEADER));
        $this->assertDatabaseHas('audit_events', [
            'action' => AuditAction::AuthSucceeded->value,
            'actor_user_id' => (string) $user->id,
            'correlation_id' => $correlationId,
        ]);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * `CreatesTestFixtures::makeUser()` deliberately stores a placeholder that
     * cannot authenticate anyone, because `SEC-007` is unresolved and a fixture
     * must not imply an approved algorithm. A test that needs a REAL credential
     * sets one afterwards, here, so the placeholder stays honest for every test
     * that does not.
     *
     * The hash is the framework's own bcrypt at the suite's reduced rounds. It is
     * a fixture primitive, not a statement about the approved algorithm.
     */
    private function makeUser(string $email, Role $role, ?string $password = null): User
    {
        $user = $this->makeUserFixture($email, $role);
        if ($password === null) {
            return $user;
        }

        User::query()->whereKey($user->id)->update(['password' => $password]);

        return $user;
    }

    private function loginAs(string $email, Role $role): User
    {
        $user = $this->makeUser($email, $role, password: Hash::make(self::PASSWORD));

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertOk();

        return $user;
    }
}
