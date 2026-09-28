<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Auth\SecurityPolicyUnresolved;
use App\Modules\Identity\Auth\SessionRevocationUnsupported;
use App\Modules\Identity\Auth\SessionRevoker;
use App\Modules\Identity\Auth\SessionSecurity;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestFixtures;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * `AC-T-004-03` (idle timeout and absolute lifetime) and `AC-T-004-04`
 * (revocation), plus the session-fabrication control `docs/SECURITY.md` `TH-01`
 * names as "stolen session".
 *
 * ================================================================================
 * EVERY LIFETIME NUMBER IN THIS SUITE IS A TEST FIXTURE.
 * ================================================================================
 *
 * `SEC-008` — the session idle timeout and absolute lifetime — is OPEN. The
 * values in `ResolvesSecurityPolicy` exist so the MECHANISM can be exercised.
 * They are not a project decision, and no production value is asserted here.
 * `UnresolvedSecurityPolicyTest` proves the shipped configuration is empty and
 * that the mechanism refuses it.
 *
 * The two lifetimes are tested SEPARATELY, because they answer different
 * questions: one asks how long a session may sit idle, the other asks how long
 * it may exist at all. A single "it expired" test would pass even if the two
 * were conflated, which is the bug this separation exists to prevent.
 */
final class SessionSecurityTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    /** TEST-ONLY fixture, mirroring `ResolvesSecurityPolicy`. Not a decision. */
    private const TEST_IDLE_SECONDS = 900;

    private const TEST_ABSOLUTE_SECONDS = 28800;

    private User $user;

    private SessionSecurity $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        config(['session.driver' => 'database']);
        $this->forgetSessionInstances();

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());

        $this->user = $this->makeUser('session.staff@example.test', Role::HotelManager);
        $this->sessions = $this->app->make(SessionSecurity::class);
    }

    // =====================================================================
    // Session creation and fixation protection
    // =====================================================================

    public function test_starting_a_session_signs_the_user_in(): void
    {
        $request = $this->request();

        $this->sessions->start($request, $this->user);

        $authenticated = Auth::guard('web')->user();

        $this->assertInstanceOf(User::class, $authenticated);
        $this->assertTrue($authenticated->is($this->user));
    }

    /**
     * `TH-01` — session fixation. An identifier an attacker planted before
     * login must not be the identifier the authenticated session continues
     * under, or the attacker simply waits for the victim to log in and then
     * presents the identifier they already had.
     */
    public function test_the_session_identifier_changes_at_authentication(): void
    {
        $request = $this->request();
        $before = $request->session()->getId();

        $this->sessions->start($request, $this->user);

        $this->assertNotSame($before, $request->session()->getId());
    }

    /**
     * Regeneration carries data forward on purpose: the CSRF token and any
     * pre-login cart state survive, while the IDENTIFIER does not.
     */
    public function test_regeneration_preserves_data_but_not_the_identifier(): void
    {
        $request = $this->request();
        $request->session()->put('pre_login_marker', 'kept');
        $before = $request->session()->getId();

        $this->sessions->start($request, $this->user);

        $this->assertSame('kept', $request->session()->get('pre_login_marker'));
        $this->assertNotSame($before, $request->session()->getId());
    }

    public function test_both_lifetime_anchors_are_recorded_at_authentication(): void
    {
        $request = $this->request();

        $this->sessions->start($request, $this->user);

        $this->assertNotNull($request->session()->get('auth.authenticated_at'));
        $this->assertNotNull($request->session()->get('auth.last_activity_at'));
    }

    public function test_a_new_session_carries_no_step_up(): void
    {
        $request = $this->request();

        $this->sessions->start($request, $this->user);
        $this->sessions->recordStepUp($request, 'refund');
        $this->assertNotNull($this->sessions->stepUpAt($request));

        // Logging in again must not inherit a step-up the new login never
        // performed, or a re-login would satisfy a sensitive operation the
        // attacker never verified.
        $this->sessions->start($request, $this->user);

        $this->assertNull($this->sessions->stepUpAt($request));
    }

    public function test_an_inactive_user_cannot_be_signed_in(): void
    {
        $suspended = $this->makeUser('suspended.staff@example.test', Role::HotelManager, User::STATUS_SUSPENDED);

        try {
            $this->sessions->start($this->request(), $suspended);
            $this->fail('Expected the suspended account to be refused.');
        } catch (DomainFailure $refusal) {
            $this->assertSame(
                ErrorCode::AuthFailed,
                $refusal->errorCode,
                'The refusal must be AUTH_FAILED, not a lifecycle code, so it confirms nothing.',
            );
        }

        $this->assertFalse(Auth::guard('web')->check());
    }

    // =====================================================================
    // The two lifetimes, tested independently
    // =====================================================================

    public function test_a_session_within_both_lifetimes_passes_and_refreshes_the_idle_anchor(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $firstAnchor = (string) $request->session()->get('auth.last_activity_at');

        Carbon::setTestNow(Carbon::now()->addSeconds(self::TEST_IDLE_SECONDS - 10));

        $this->sessions->enforceLifetimes($request);

        $this->assertNotSame(
            $firstAnchor,
            (string) $request->session()->get('auth.last_activity_at'),
            'Activity inside the idle window must extend the idle anchor.',
        );
    }

    /**
     * The idle timeout is extended by activity. Crossing the ABSOLUTE
     * lifetime with a recent activity anchor must still refuse — that is what
     * separates the two limits.
     */
    public function test_activity_does_not_extend_the_absolute_lifetime(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        // Activity right up to the idle edge, then a long absolute wait.
        Carbon::setTestNow(Carbon::now()->addSeconds(self::TEST_IDLE_SECONDS - 5));
        $this->sessions->enforceLifetimes($request);

        Carbon::setTestNow(Carbon::now()->addSeconds(self::TEST_ABSOLUTE_SECONDS));

        $this->assertRefusedForLifetimes($request, 'absolute');
    }

    public function test_an_idle_session_is_refused(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        Carbon::setTestNow(Carbon::now()->addSeconds(self::TEST_IDLE_SECONDS + 1));

        $this->assertRefusedForLifetimes($request, 'idle');
    }

    /**
     * An expired session is DESTROYED before the refusal is raised.
     *
     * A 401 that leaves the session intact lets the caller retry straight into
     * a session the server has already decided is too old.
     */
    public function test_an_expired_session_is_destroyed_not_merely_refused(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $id = $request->session()->getId();

        Carbon::setTestNow(Carbon::now()->addSeconds(self::TEST_IDLE_SECONDS + 1));

        try {
            $this->sessions->enforceLifetimes($request);
            $this->fail('Expected the idle session to be refused.');
        } catch (DomainFailure) {
            // The refusal itself is asserted elsewhere; what matters here is
            // the state left behind.
        }

        $this->assertNotSame($id, $request->session()->getId(), 'An expired session must be destroyed.');
        $this->assertFalse(Auth::guard('web')->check());
    }

    public function test_a_session_with_no_lifetime_anchor_is_refused(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        // A session this class did not create cannot be shown to be within any
        // lifetime, and AC-T-004-03 is about proving the lifetime.
        $request->session()->forget(['auth.authenticated_at', 'auth.last_activity_at']);

        $this->assertRefusedForLifetimes($request, 'missing');
    }

    public function test_enforcing_lifetimes_without_a_session_is_refused(): void
    {
        try {
            $this->sessions->enforceLifetimes($this->request());
            $this->fail('Expected an unauthenticated request to be refused.');
        } catch (DomainFailure $refusal) {
            $this->assertSame(ErrorCode::AuthRequired, $refusal->errorCode);
        }
    }

    /**
     * `SEC-008` is OPEN, so with the shipped configuration an authenticated
     * session cannot be evaluated at all. The refusal happens BEFORE any
     * lifetime is compared, so no undecided number is ever used as a limit.
     */
    public function test_an_unresolved_lifetime_refuses_instead_of_comparing_against_nothing(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->app->instance(SecurityPolicy::class, $this->unresolvedSecurityPolicy());
        $sessions = $this->app->make(SessionSecurity::class);

        try {
            $sessions->enforceLifetimes($request);
            $this->fail('Expected the unresolved lifetime to refuse.');
        } catch (SecurityPolicyUnresolved $unresolved) {
            $this->assertSame('SEC-008', $unresolved->decision);
            $this->assertSame(503, $unresolved->errorCode->httpStatus());
        }
    }

    // =====================================================================
    // Logout
    // =====================================================================

    public function test_ending_a_session_clears_the_user_and_the_identifier(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $before = $request->session()->getId();

        $this->sessions->end($request);

        $this->assertFalse(Auth::guard('web')->check());
        $this->assertNotSame($before, $request->session()->getId());
    }

    /**
     * The CSRF token is rotated on logout. A token that outlives the session
     * is a token an attacker who read it before logout can keep presenting.
     */
    public function test_ending_a_session_rotates_the_csrf_token(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $before = $request->session()->token();

        $this->sessions->end($request);

        $this->assertNotSame($before, $request->session()->token());
    }

    // =====================================================================
    // AC-T-004-04 — revocation
    // =====================================================================

    public function test_revocation_removes_every_live_session_for_a_user(): void
    {
        $this->startTwoLiveSessionsFor($this->user);

        $this->assertSame(2, DB::table('sessions')->where('user_id', $this->user->id)->count());

        $revoked = $this->app->make(SessionRevoker::class)->revokeAllFor((string) $this->user->id);

        $this->assertSame(2, $revoked);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
    }

    public function test_revocation_leaves_other_users_untouched(): void
    {
        $other = $this->makeUser('other.staff@example.test', Role::Housekeeping);

        $this->startTwoLiveSessionsFor($this->user);
        $this->startOneLiveSessionFor($other);

        $this->app->make(SessionRevoker::class)->revokeAllFor((string) $this->user->id);

        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_revoking_nothing_is_not_an_error(): void
    {
        $this->assertSame(0, $this->app->make(SessionRevoker::class)->revokeAllFor('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
    }

    /**
     * The driver check is the whole point.
     *
     * Revocation deletes rows from `sessions`. Under `file`, `cookie`, or
     * `redis` that delete affects nothing while appearing to succeed — the
     * silent no-op `AC-T-004-04` exists to prevent. `phpunit.xml` defaults to
     * the `array` driver, so this is not hypothetical here: it is the driver
     * every other test in this suite runs under.
     */
    public function test_an_unsupported_session_driver_refuses_loudly(): void
    {
        config(['session.driver' => 'array']);

        try {
            $this->app->make(SessionRevoker::class)->revokeAllFor((string) $this->user->id);
            $this->fail('Expected an unsupported driver to refuse rather than silently delete nothing.');
        } catch (SessionRevocationUnsupported $unsupported) {
            $this->assertSame(ErrorCode::ServiceUnavailable, $unsupported->errorCode);
            $this->assertStringContainsString('array', $unsupported->getMessage());
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function assertRefusedForLifetimes(Request $request, string $because): void
    {
        try {
            $this->sessions->enforceLifetimes($request);
            $this->fail('Expected the session to be refused. because: '.$because);
        } catch (DomainFailure $refusal) {
            $this->assertSame(
                ErrorCode::AuthRequired,
                $refusal->errorCode,
                'A lifetime refusal is AUTH_REQUIRED, not a 403. because: '.$because,
            );
        }
    }

    private function startOneLiveSessionFor(User $user): void
    {
        $request = $this->request();
        $request->setLaravelSession($this->app['session']->driver());

        $this->sessions->start($request, $user);
        $request->session()->save();

        Auth::guard('web')->logout();
    }

    /**
     * Two rows for one user, so "revokes everything" is distinguishable from
     * "revokes the most recent one".
     */
    private function startTwoLiveSessionsFor(User $user): void
    {
        $this->startOneLiveSessionFor($user);
        $this->startOneLiveSessionFor($user);
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/whatever', 'GET');

        $store = $this->app['session']->driver();
        $store->start();
        $request->setLaravelSession($store);

        return $request;
    }

    private function forgetSessionInstances(): void
    {
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth');
    }
}
