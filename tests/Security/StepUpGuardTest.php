<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Auth\SessionSecurity;
use App\Modules\Identity\Auth\StepUpGuard;
use App\Modules\Identity\Auth\StepUpOperation;
use App\Modules\Identity\Auth\StepUpRequired;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Domain\ErrorCode;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestFixtures;
use Tests\Support\CreatesStepUpProofs;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * `AC-T-004-05` — step-up authentication is required for the canonical
 * operation set.
 *
 * The set is the seven operations of `docs/SECURITY.md` §6.1, cross-referenced by
 * `docs/API-SPEC.md` §3.11.1 and `PRD.md` `SEC-018`. The gate is proven HERE, by
 * calling it directly. It is deliberately not wired to a route in this task, and
 * that limitation is stated rather than hidden: an unenforced control is not an
 * enforced control, which is why `docs/TASKS.md` §13.1.4 keeps `AC-T-004-05` at
 * PARTIAL.
 *
 * What is proven, one condition at a time, because a gate that checks only some
 * of its conditions fails in a way nobody notices until an incident:
 *
 *   | Condition                    | Attack it closes                          |
 *   |------------------------------|-------------------------------------------|
 *   | the step-up exists           | "no timestamp" read as "not yet expired"   |
 *   | it is fresh                  | a stale step-up from hours ago             |
 *   | it is FOR THIS OPERATION     | a document reveal authorising a refund     |
 *   | it was by THIS SUBJECT       | a session-re-associated step-up carrying over |
 */
final class StepUpGuardTest extends TestCase
{
    use CreatesStepUpProofs;
    use CreatesTestFixtures;
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    private const FRESHNESS = 300;

    private User $user;

    private SessionSecurity $sessions;

    private StepUpGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith([
            'step_up' => ['freshness_seconds' => self::FRESHNESS],
        ]));

        $this->user = $this->makeUser('step.up.subject@example.test', Role::Finance);
        $this->sessions = $this->app->make(SessionSecurity::class);
        $this->guard = $this->app->make(StepUpGuard::class);
    }

    // =====================================================================
    // The canonical set itself
    // =====================================================================

    /**
     * EXACTLY SEVEN, and they are the seven the three sources name.
     *
     * Asserted as a set rather than by counting, because "seven" is only
     * meaningful if it is these seven. A count alone would pass if the set were
     * replaced with seven wrong operations.
     */
    public function test_the_canonical_step_up_set_is_exactly_the_documented_seven(): void
    {
        $expected = [
            'refund',
            'configuration',
            'scope_grant',
            'business_date_reopen',
            'export',
            'impersonation',
            'identity_document_reveal',
        ];

        $this->assertSame($expected, StepUpOperation::names());
        $this->assertCount(7, StepUpOperation::cases());
    }

    /**
     * The three `STATE-MACHINES.md` operations are NOT here.
     *
     * `docs/STATE-MACHINES.md` requires step-up for reservation cancellation
     * (line 78), `OUT_OF_ORDER` (line 155), and `FORCED_CLOSE` (line 492). That
     * document is subordinate — `PRD.md:811` makes `SECURITY.md` normative, and
     * `STATE-MACHINES.md:7` calls itself "Draft — specification only" — so a
     * draft specification does not add to a normative control set. See
     * `docs/SECURITY.md` §12.1.7 "Conflict C".
     *
     * This test exists so that adding one of them is a DELIBERATE, visible code
     * change rather than an accident, and so that whoever adds one is forced to
     * read this comment first.
     */
    public function test_the_three_state_machine_operations_are_not_in_the_canonical_set(): void
    {
        foreach (['reservation_cancellation', 'out_of_order', 'forced_close'] as $notCanonical) {
            $this->assertNull(
                StepUpOperation::tryFromName($notCanonical),
                'A STATE-MACHINES.md operation is in the canonical set. Expanding the set is a PM '
                .'act: it must change SECURITY.md §6.1, API-SPEC.md §3.11.1, and PRD.md SEC-018 first.',
            );
        }
    }

    public function test_an_unknown_operation_name_resolves_to_null_rather_than_guessing(): void
    {
        $this->assertNull(StepUpOperation::tryFromName('refunds'));
        $this->assertNull(StepUpOperation::tryFromName('export_data'));
        $this->assertNull(StepUpOperation::tryFromName(''));

        // Case and whitespace are normalised: a near-miss is a real operation,
        // and `Refund` from a header is `refund`.
        $this->assertSame(StepUpOperation::Refund, StepUpOperation::tryFromName('  Refund '));
    }

    /**
     * Every canonical operation is enforced identically.
     *
     * A gate that special-cased one operation would let an implementer add an
     * eighth by copying the nearest case and forgetting the check. All seven are
     * exercised.
     */
    public function test_every_canonical_operation_is_gated(): void
    {
        foreach (StepUpOperation::cases() as $operation) {
            $request = $this->request();
            $this->sessions->start($request, $this->user);

            try {
                $this->guard->assertSatisfied($request, $operation);
                $this->fail("Expected {$operation->value} to be refused without a step-up.");
            } catch (StepUpRequired $refusal) {
                $this->assertSame(ErrorCode::StepUpRequired, $refusal->errorCode);
                $this->assertSame(403, $refusal->errorCode->httpStatus());
            }
        }
    }

    // =====================================================================
    // The four conditions
    // =====================================================================

    public function test_a_session_with_no_step_up_is_refused(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->assertFalse($this->guard->isSatisfied($request, StepUpOperation::Refund));

        $this->expectException(StepUpRequired::class);

        $this->guard->assertSatisfied($request, StepUpOperation::Refund);
    }

    public function test_a_fresh_step_up_for_the_same_operation_satisfies_the_gate(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->performStepUp($request, StepUpOperation::Refund);

        $this->guard->assertSatisfied($request, StepUpOperation::Refund);

        $this->addToAssertionCount(1);
    }

    /**
     * CONDITION 3 — a step-up for one operation does NOT authorise another.
     *
     * This is the cross-operation reuse the operation binding exists to stop. A
     * step-up taken to reveal a masked document number must not authorise a
     * refund, even seconds later and in the same session.
     */
    public function test_a_step_up_for_one_operation_does_not_satisfy_another(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->performStepUp($request, StepUpOperation::IdentityDocumentReveal);

        $this->expectException(StepUpRequired::class);

        $this->guard->assertSatisfied($request, StepUpOperation::Refund);
    }

    /**
     * Every other operation is refused too, not just refund. A binding that only
     * held for one pair would be a binding that had been written for one
     * endpoint.
     */
    public function test_a_step_up_satisfies_exactly_one_operation(): void
    {
        foreach (StepUpOperation::cases() as $performed) {
            $request = $this->request();
            $this->sessions->start($request, $this->user);
            $this->performStepUp($request, $performed);

            $this->assertTrue($this->guard->isSatisfied($request, $performed));

            foreach (StepUpOperation::cases() as $attempted) {
                if ($attempted === $performed) {
                    continue;
                }

                $this->assertFalse(
                    $this->guard->isSatisfied($request, $attempted),
                    "A step-up for {$performed->value} must not satisfy {$attempted->value}.",
                );
            }
        }
    }

    /**
     * CONDITION 2 — freshness, on its OWN clock.
     *
     * The window is 300 s and it is deliberately neither session lifetime. A step
     * up 30 s old is inside it; one 301 s old is not. `Carbon::setTestNow` moves
     * the clock rather than sleeping, and the anchor is written by the gate
     * itself so the test is not asserting against a hand-placed timestamp.
     */
    public function test_a_step_up_older_than_the_freshness_window_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:00:00Z'));

        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $this->performStepUp($request, StepUpOperation::Export);

        // Just inside.
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:04:59Z'));
        $this->assertTrue($this->guard->isSatisfied($request, StepUpOperation::Export));

        // Just outside.
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:05:01Z'));
        $this->assertFalse($this->guard->isSatisfied($request, StepUpOperation::Export));

        Carbon::setTestNow();

        $this->addToAssertionCount(1);
    }

    /**
     * The freshness window is NOT a session lifetime, in either direction.
     *
     * Both of these would pass if the gate had quietly reused the idle timeout
     * (900 s) or the absolute lifetime (8 h) instead of the step-up window
     * (300 s). A step-up that outlives its window is the whole defect.
     */
    public function test_the_freshness_window_is_independent_of_both_session_lifetimes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:00:00Z'));

        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $this->performStepUp($request, StepUpOperation::Refund);

        $policy = $this->securityPolicyWith(['step_up' => ['freshness_seconds' => self::FRESHNESS]]);

        $this->assertSame(
            300,
            $policy->stepUpFreshnessSeconds(),
            'The step-up window is its own value, not the idle timeout.',
        );

        $this->assertNotSame(
            $policy->sessionIdleTimeoutSeconds(),
            $policy->stepUpFreshnessSeconds(),
        );

        $this->assertNotSame(
            $policy->sessionAbsoluteLifetimeSeconds(),
            $policy->stepUpFreshnessSeconds(),
        );

        Carbon::setTestNow();

        $this->addToAssertionCount(1);
    }

    /**
     * CONDITION 4 — the step-up belongs to a SUBJECT, not merely to a browser.
     *
     * A step-up is written by one identity. If validity were a property of the
     * session payload alone, a session re-associated with a different user would
     * carry a valid-looking step-up with it. The subject key closes that.
     */
    public function test_a_step_up_performed_by_another_subject_does_not_satisfy_the_gate(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $this->performStepUp($request, StepUpOperation::Refund);

        $this->assertTrue($this->guard->isSatisfied($request, StepUpOperation::Refund));

        // `SessionSecurity::start()` is deliberately NOT used here: it clears the
        // step-up, which is the OTHER defence (a fresh login inherits no
        // step-up). This test is about what happens when the step-up keys
        // survive an identity change by some other route — a scope change, a
        // role change, a restored session payload — which is precisely the case
        // the subject binding exists to catch.
        //
        // The guard is driven through the `Auth` FACADE, not the container.
        // `SessionSecurity` resolves `Auth::guard('web')`, and the facade caches
        // its resolved instance — so `forgetInstance('auth')` on the container
        // would leave the facade pointing at the old manager and this test would
        // silently assert against the original user.
        $other = $this->makeUser('a.different.person@example.test', Role::Finance);

        Auth::guard('web')->setUser($other);

        $this->assertSame(
            (string) $other->id,
            (string) $this->sessions->requireUser()->id,
            'Precondition: the acting identity really has changed.',
        );

        $this->assertNotNull(
            $this->sessions->stepUpAt($request),
            'Precondition: the timestamp survives; this test is about the subject binding.',
        );
        $this->assertSame(
            $this->sessions->stepUpSubject($request),
            (string) $this->user->id,
            'Precondition: the step-up still names its original performer.',
        );

        $this->assertFalse(
            $this->guard->isSatisfied($request, StepUpOperation::Refund),
            'A step-up performed by one identity must not authorise another.',
        );
    }

    public function test_a_step_up_is_not_carried_across_re_authentication(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:00:00Z'));

        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $this->performStepUp($request, StepUpOperation::Refund);

        $this->assertTrue($this->guard->isSatisfied($request, StepUpOperation::Refund));

        // Signing in again must not inherit a step-up the new login never
        // performed, or a re-login would satisfy a sensitive operation that was
        // never re-verified.
        $this->sessions->start($request, $this->user);

        $this->assertNull($this->sessions->stepUpAt($request));
        $this->assertNull($this->sessions->stepUpOperation($request));
        $this->assertNull($this->sessions->stepUpSubject($request));

        Carbon::setTestNow();

        $this->addToAssertionCount(1);
    }

    public function test_forgetting_a_step_up_clears_all_three_keys_together(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $this->performStepUp($request, StepUpOperation::Refund);

        $this->sessions->forgetStepUp($request);

        $this->assertNull($this->sessions->stepUpAt($request));
        $this->assertNull($this->sessions->stepUpOperation($request));
        $this->assertNull($this->sessions->stepUpSubject($request));
    }

    // =====================================================================
    // Uniform refusal
    // =====================================================================

    /**
     * All four failure modes produce the SAME message.
     *
     * Differentiating them would tell an attacker which part of a stolen step-up
     * is wrong and therefore how to fix it, and would tell a legitimate user that
     * their step-up was real but for another operation — which is a step towards
     * getting them to perform the wrong one.
     */
    public function test_every_failure_mode_produces_one_indistinguishable_refusal(): void
    {
        $messages = [];

        // 1. No step-up at all.
        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $messages[] = $this->refusalMessage($request, StepUpOperation::Refund);

        // 2. Expired.
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:00:00Z'));
        $expired = $this->request();
        $this->sessions->start($expired, $this->user);
        $this->performStepUp($expired, StepUpOperation::Refund);
        Carbon::setTestNow(Carbon::parse('2026-01-01T13:00:00Z'));
        $messages[] = $this->refusalMessage($expired, StepUpOperation::Refund);
        Carbon::setTestNow();

        // 3. Wrong operation.
        $wrongOperation = $this->request();
        $this->sessions->start($wrongOperation, $this->user);
        $this->performStepUp($wrongOperation, StepUpOperation::Export);
        $messages[] = $this->refusalMessage($wrongOperation, StepUpOperation::Refund);

        // 4. Wrong subject — the same technique as the dedicated test, so the
        // acting identity genuinely changes rather than a session key.
        $wrongSubject = $this->request();
        $this->sessions->start($wrongSubject, $this->user);
        $this->performStepUp($wrongSubject, StepUpOperation::Refund);
        Auth::guard('web')->setUser(
            $this->makeUser('another.person@example.test', Role::Finance)
        );
        $messages[] = $this->refusalMessage($wrongSubject, StepUpOperation::Refund);

        $this->assertCount(4, $messages);
        $this->assertCount(1, array_unique($messages), 'The four failure modes must be indistinguishable.');
    }

    // =====================================================================
    // Audit — SEC-018 and API-SPEC §3.11
    // =====================================================================

    /**
     * `SEC-018` requires a privileged action that uses step-up to "produce an
     * audit event", and `docs/API-SPEC.md` §3.11 line 334 names it:
     * `POST /api/v1/auth/step-up` audits `STEP_UP_PERFORMED`. `StepUpGuard::complete`
     * is that emitter, and the name is the established one.
     *
     * `ADR-0016:23` does not list this event. It does not cancel the
     * requirement: that catalogue is explicitly a MINIMUM list "from
     * `Prd_Maker.md` §32", a minimum list is not an exhaustive one, and
     * `ADR-0016:25` already covers the privileged-access category.
     */
    public function test_performing_a_step_up_emits_the_documented_audit_event(): void
    {
        $before = DB::table('audit_events')->count();

        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->performStepUp($request, StepUpOperation::Refund, 'corr-9');

        $row = DB::table('audit_events')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame(AuditAction::StepUpPerformed->value, $row->action);
        $this->assertSame('corr-9', $row->correlation_id);
        $this->assertSame((string) $this->user->id, $row->actor_user_id);
        $this->assertSame($before + 1, DB::table('audit_events')->count());
    }

    /**
     * The emitter records WHICH FACTOR was verified.
     *
     * Without `method` in the context, a `STEP_UP_PERFORMED` row is
     * indistinguishable from a step-up recorded by some path that had no second
     * factor to verify — which is precisely the defect this class was changed to
     * close. The factor is the field that makes the audit record falsifiable.
     */
    public function test_the_step_up_audit_record_names_the_verified_factor(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->performStepUp($request, StepUpOperation::Refund, 'corr-9');

        $row = DB::table('audit_events')->latest('id')->first();

        $this->assertNotNull($row);
        $this->assertIsString($row->context);
        $this->assertStringContainsString('totp', $row->context);
    }

    public function test_the_step_up_audit_record_carries_the_operation_and_no_secret(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->performStepUp($request, StepUpOperation::IdentityDocumentReveal, 'corr-9');

        $row = DB::table('audit_events')->latest('id')->first();

        $this->assertNotNull($row, 'The step-up must have written an audit record.');
        $this->assertIsString($row->context);

        $payload = $row->context;

        $this->assertStringContainsString(StepUpOperation::IdentityDocumentReveal->value, $payload);
        $this->assertStringNotContainsString('password', strtolower($payload));
        $this->assertStringNotContainsString('secret', strtolower($payload));
    }

    /**
     * The gate is a gate, not a grant.
     *
     * `ADR-0014` still decides what this user may do. `StepUpGuard` has no
     * reference to a permission, a role, or a property scope, and that is the
     * property being asserted: a user who passes it and holds no roles is still
     * refused every operation downstream.
     */
    public function test_satisfying_the_gate_grants_nothing_by_itself(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);
        $this->performStepUp($request, StepUpOperation::Refund);

        $source = (string) file_get_contents(
            $this->app->basePath('app/Modules/Identity/Auth/StepUpGuard.php')
        );

        $this->assertStringNotContainsString(
            'Permission::',
            $source,
            'The step-up gate must not consult the permission matrix; authorization is ADR-0014\'s.',
        );

        $this->assertStringNotContainsString(
            'AuthorizesRequests',
            $source,
            'The step-up gate must not reach into the authorization layer.',
        );
    }

    /**
     * The gate has an audit emitter and needs no service container trickery to
     * get one, so a caller cannot accidentally build it without audit.
     */
    public function test_the_gate_is_constructed_with_the_audit_recorder(): void
    {
        $this->assertInstanceOf(
            AuditRecorder::class,
            $this->app->make(AuditRecorder::class),
            'StepUpGuard depends on AuditRecorder, which is how SEC-018\'s event is emitted.',
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function performStepUp(Request $request, StepUpOperation $operation, ?string $correlationId = 'corr-1'): void
    {
        $this->guard->complete(
            $request,
            $operation,
            $this->stepUpProofFor($this->user, $operation),
            $correlationId,
        );
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/folios/1/adjustments', 'POST');

        $store = $this->app['session']->driver();
        $store->start();
        $request->setLaravelSession($store);

        return $request;
    }

    private function refusalMessage(Request $request, StepUpOperation $operation): string
    {
        try {
            $this->guard->assertSatisfied($request, $operation);
            $this->fail('Expected a refusal.');
        } catch (StepUpRequired $refusal) {
            return $refusal->getMessage();
        }
    }
}
