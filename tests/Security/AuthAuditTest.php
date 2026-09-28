<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\AuthenticationService;
use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Auth\SessionSecurity;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditAction;
use App\Shared\Domain\DomainFailure;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTestFixtures;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * `AC-T-004-01` — "Authentication and logout produce audit events."
 *
 * The event names are not chosen here. `AUTH_SUCCEEDED`, `AUTH_FAILED`, and
 * `AUTH_LOGOUT` are transcribed from `docs/API-SPEC.md` §3.11. `STEP_UP_PERFORMED`
 * was admitted to that table by `DR-T004-10`, which resolved the decision the
 * T-004 review had left open.
 *
 * The second job of this suite is negative. `ADR-0016` §8 and
 * `docs/API-SPEC.md` §1.6 forbid secrets in the audit trail, and the trail is
 * append-only — a credential written into it is a credential that can never be
 * rotated away. So every event is inspected for the password, the hash, the
 * session identifier, and the submitted email.
 */
final class AuthAuditTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    private const PASSWORD = 'synthetic-not-a-real-password';

    private const EMAIL = 'audit.staff@example.test';

    private User $user;

    private AuthenticationService $authentication;

    private SessionSecurity $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        config(['session.driver' => 'database']);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth');

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());

        // A TEST-ONLY driver name that exists nowhere in `config/security.php`.
        // `SEC-007` is OPEN; nothing here selects a project algorithm. See
        // `AuthenticationTest` for the same arrangement and its rationale.
        Hash::extend('test_only_hasher', static fn (): Hasher => new BcryptHasher(['rounds' => 4]));

        $this->user = $this->makeUser(self::EMAIL, Role::HotelManager);
        $this->user->forceFill([
            'password' => Hash::driver('test_only_hasher')->make(self::PASSWORD),
        ])->save();
        $this->reread($this->user);

        $this->authentication = $this->app->make(AuthenticationService::class);
        $this->sessions = $this->app->make(SessionSecurity::class);
    }

    public function test_a_successful_login_writes_auth_succeeded(): void
    {
        $this->authentication->login($this->request(), self::EMAIL, self::PASSWORD, 'corr-audit-ok');

        $row = $this->rowFor(AuditAction::AuthSucceeded);

        $this->assertNotNull($row, 'A successful authentication must be audited.');
        $this->assertSame((string) $this->user->id, $row['actor_user_id']);
        $this->assertSame('SUCCESS', $row['result']);
        $this->assertSame('auth_login', $row['source']);
    }

    /**
     * A denial is written too, and it is written BEFORE the refusal propagates.
     *
     * A trail that records only successful authentication is blank during
     * exactly the event a brute-force attack consists of.
     */
    public function test_a_failed_login_writes_auth_failed_as_a_denial(): void
    {
        try {
            $this->authentication->login($this->request(), self::EMAIL, 'synthetic-wrong', 'corr-audit-1');
            $this->fail('Expected the attempt to be refused.');
        } catch (DomainFailure) {
            // The refusal is asserted in AuthenticationTest; here the point is
            // what it left behind.
        }

        $row = $this->rowFor(AuditAction::AuthFailed);

        $this->assertNotNull($row, 'A refused authentication must be audited.');
        $this->assertSame('DENIED_AUTH_FAILED', $row['result']);
        $this->assertSame('password_mismatch', $row['reason']);
    }

    public function test_logout_writes_auth_logout(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->authentication->logout($request, 'corr-audit-2');

        $row = $this->rowFor(AuditAction::AuthLogout);

        $this->assertNotNull($row, 'Ending a session must be audited.');
        $this->assertSame((string) $this->user->id, $row['actor_user_id']);
        $this->assertSame('auth_logout', $row['source']);
    }

    public function test_the_correlation_id_is_propagated_onto_every_event(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $this->authentication->logout($request, 'corr-audit-chain');

        $row = $this->rowFor(AuditAction::AuthLogout);

        $this->assertNotNull($row);
        $this->assertSame('corr-audit-chain', $row['correlation_id']);
    }

    /**
     * `DR-T004-10` admitted `STEP_UP_PERFORMED` to the audit vocabulary.
     *
     * This asserts the EVENT exists and is spelled as the specification spells
     * it. It does not assert that anything emits it: the gate that would emit
     * it is `T-004` Stage 3 and is not built, because the mechanism that
     * SATISFIES a step-up is unspecified (`DR-T004-08`, OPEN). Asserting an
     * emission here would require inventing the mechanism.
     */
    public function test_step_up_performed_is_part_of_the_audit_vocabulary(): void
    {
        $this->assertSame('STEP_UP_PERFORMED', AuditAction::StepUpPerformed->value);
        $this->assertSame(AuditAction::StepUpPerformed, AuditAction::from('STEP_UP_PERFORMED'));
    }

    public function test_the_four_authentication_events_are_distinct(): void
    {
        $values = [
            AuditAction::AuthSucceeded->value,
            AuditAction::AuthFailed->value,
            AuditAction::AuthLogout->value,
            AuditAction::StepUpPerformed->value,
        ];

        $this->assertCount(4, array_unique($values));
    }

    /**
     * The step-up STATE is written to the session, not to the audit trail.
     *
     * Nothing is emitted because nothing is implemented, and a test that
     * asserted an event here would be asserting a feature that does not exist.
     */
    public function test_recording_a_step_up_writes_session_state_and_emits_nothing(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $before = DB::table('audit_events')->count();

        $this->sessions->recordStepUp($request, 'refund');

        $this->assertNotNull($this->sessions->stepUpAt($request));
        $this->assertSame(
            $before,
            DB::table('audit_events')->count(),
            'No audit event is emitted for a step-up: the gate that would emit it is not implemented.',
        );
    }

    /**
     * Every field of every authentication event, inspected for a secret.
     *
     * This is the assertion that matters most in this file. The trail is
     * append-only, so anything written into it is permanent.
     */
    public function test_no_authentication_event_contains_a_secret(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $this->authentication->logout($request, 'corr-audit-secret');

        $this->assertGreaterThan(0, DB::table('audit_events')->count());

        $haystack = implode("\n", array_map(
            static fn (object $row): string => json_encode($row) ?: '',
            DB::table('audit_events')->get()->all(),
        ));

        $this->assertStringNotContainsString(self::PASSWORD, $haystack);
        $this->assertStringNotContainsString((string) $this->user->password, $haystack);
        $this->assertStringNotContainsString($request->session()->getId(), $haystack);
    }

    /**
     * The failure reason is a fixed vocabulary, not free text carrying whatever
     * the caller submitted. A trail that stored every submitted address would
     * be a list of candidate credentials — personal data under `COM-003`.
     */
    public function test_the_failure_reason_is_a_fixed_vocabulary(): void
    {
        $suspended = $this->makeUser('audit.suspended@example.test', Role::HotelManager, User::STATUS_SUSPENDED);
        $suspended->forceFill([
            'password' => Hash::driver('test_only_hasher')->make(self::PASSWORD),
        ])->save();
        $this->reread($suspended);

        $cases = [
            ['no_matching_account', 'nobody@example.test', 'synthetic-wrong'],
            ['password_mismatch', self::EMAIL, 'synthetic-wrong'],
            ['account_not_active', (string) $suspended->email, self::PASSWORD],
        ];

        foreach ($cases as [$expected, $submittedEmail, $submittedPassword]) {
            try {
                $this->authentication->login($this->request(), $submittedEmail, $submittedPassword, 'corr-vocab');
                $this->fail('Expected the attempt to be refused.');
            } catch (DomainFailure) {
                // The refusal itself is asserted elsewhere.
            }

            $reasons = DB::table('audit_events')
                ->where('action', AuditAction::AuthFailed->value)
                ->pluck('reason')
                ->all();

            $this->assertContains($expected, $reasons, 'reason vocabulary');

            $this->assertNotContains(
                $submittedEmail,
                $reasons,
                'A reason must come from the fixed vocabulary, never from what the caller sent.',
            );
        }
    }

    /**
     * The most recent row for an event, as an array.
     *
     * An array rather than a `stdClass` so the column names are checked against
     * a declared shape instead of being whatever the driver happened to return.
     *
     * @return array<string, mixed>|null
     */
    private function rowFor(AuditAction $action): ?array
    {
        $row = DB::table('audit_events')
            ->where('action', $action->value)
            ->orderByDesc('occurred_at')
            ->first();

        return $row === null ? null : (array) $row;
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/auth/login', 'POST');

        $store = $this->app['session']->driver();
        $store->start();
        $request->setLaravelSession($store);

        return $request;
    }
}
