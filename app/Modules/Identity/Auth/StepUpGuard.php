<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Auth\Mfa\StepUpProof;
use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecord;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Domain\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The step-up gate: does the caller hold a CURRENT step-up FOR THIS OPERATION?
 *
 * `SEC-018` requires step-up on the seven canonical operations, and
 * `docs/API-SPEC.md` §3.11.1 enumerates the same seven. This class is the one
 * place that requirement becomes an executable check.
 *
 * ============================ THE TRUST BOUNDARY IS NOT OPTIONAL ============================
 * These four conditions describe the RESULT of a step-up. None of them asks
 * where that result came from, so all four passing proves only that a session
 * holds three consistent keys — which is exactly what a caller fabricating those
 * keys would produce.
 *
 * That gap was real and it was exploitable. This class previously had:
 *
 *     public function perform(Request, StepUpOperation, ?string): User
 *
 * which recorded a completed step-up and verified nothing. Holding this object
 * was sufficient to obtain a fresh, correctly-bound step-up for any of the seven
 * operations with no second factor involved, and all four checks below would then
 * pass. The control was present, tested, and inert.
 *
 * Completion now goes through `complete()`, which requires a `StepUpProof` — a
 * value that exists only after `StepUpVerifier` has run an RFC 6238 check
 * against a secret the caller holds. The four conditions below are unchanged and
 * are still all required; they are now the SECOND gate rather than the only one.
 * `assertSatisfied()` deliberately does not know or care how a step-up was
 * obtained, so a step-up established by any future trusted path satisfies it
 * exactly as one established today does.
 *
 * ============================ FOUR INDEPENDENT CONDITIONS ============================
 * All four must hold. Each closes a different hole, and a gate that checks only
 * some of them is a gate that fails in a way nobody notices until an incident:
 *
 *   1. IT EXISTS. A session with no step-up keys at all is refused. A gate that
 *      treats "no timestamp" as "not yet expired" would authorise everything.
 *   2. IT IS FRESH. `auth.step_up_at` is compared against
 *      `step_up.freshness_seconds` — 300 s. The window is read from the POLICY
 *      and is deliberately NOT either session lifetime: a step-up is a recent
 *      proof of presence for one operation, not a general grant, and comparing
 *      it to a 12-hour absolute lifetime would let a step-up taken at hour zero
 *      authorise a refund at hour eleven.
 *   3. IT IS FOR THIS OPERATION. `auth.step_up_operation` must equal the
 *      operation being attempted. A step-up taken to reveal a masked document
 *      number must NOT authorise a refund. This is a string equality check, not
 *      a subset or prefix test, so `export` does not satisfy `export_data` and
 *      `Refund` does not satisfy `refund`.
 *   4. IT WAS PERFORMED BY THIS SUBJECT. `auth.step_up_subject` must equal the
 *      authenticated user. Without this, validity is a property of the BROWSER
 *      rather than of the identity, and any re-association of a session would
 *      carry a step-up with it.
 *
 * ============================ COMPLETING A STEP-UP ============================
 * `complete()` records that a step-up happened, and it requires a
 * `StepUpProof` to do it. Producing a proof is `StepUpVerifier`'s job and
 * involves an RFC 6238 verification against a real secret.
 *
 * There is deliberately no re-authentication endpoint and no MFA challenge
 * handler here. `docs/API-SPEC.md` §3.11 describes `POST /api/v1/auth/step-up`
 * and `POST /api/v1/auth/mfa/verify`, but registering a route that accepts a
 * secret and a code is a larger decision than this task, and the accountability
 * question `C-10` has not answered sits directly on it — who may enrol, and who
 * authorises a recovery. Building the handler without that answer would be
 * inventing a security workflow, and inventing one is worse than having none,
 * because an invented recovery path is a bypass with extra steps.
 *
 * ============================ WHAT IS NOT IN HERE ============================
 * No route, no middleware, and no endpoint. `StepUpGuard` is a reusable object
 * that an operation's write path consults; wiring it to the two operations that
 * exist today (`ScopeGrantService` for scope grants, which is a
 * configuration-class change) would be correct but is a change to those
 * services' behaviour, and this task did not add a caller. The gate is proven by
 * tests that call it directly, and `AC-T-004-05` remains PARTIAL for the honest
 * reason that an unenforced control is not an enforced control.
 *
 * ============================ NOT AN AUTHORIZATION DECISION ============================
 * Passing this gate proves the caller recently re-authenticated. It grants
 * NOTHING. `ADR-0014` still decides whether this user may refund, reopen a
 * business date, or reveal a document at this property, and every permission and
 * scope check downstream is unchanged. Step-up is a second gate in series, not
 * a substitute for the first — a user who passes it and holds no roles is still
 * refused every operation.
 */
final class StepUpGuard
{
    public function __construct(
        private readonly SecurityPolicy $policy,
        private readonly SessionSecurity $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Refuse unless the session holds a fresh step-up for this exact operation
     * by this exact subject.
     *
     * @throws StepUpRequired when any of the four conditions fails
     * @throws SecurityPolicyUnresolved when the freshness window is unusable
     */
    public function assertSatisfied(Request $request, StepUpOperation $operation): void
    {
        $user = $this->sessions->requireUser();

        $performedAt = $this->sessions->stepUpAt($request);

        if ($performedAt === null) {
            throw StepUpRequired::forOperation($operation->value);
        }

        $freshness = $this->policy->stepUpFreshnessSeconds();

        // `absolute: true` for the same reason `enforceLifetimes()` asks for it:
        // Carbon 3 returns a SIGNED difference, so a bare `>=` against a
        // positive window is never true and every step-up would appear eternal.
        $age = Carbon::now()->diffInSeconds($performedAt, absolute: true);

        if ($age >= $freshness) {
            throw StepUpRequired::forOperation($operation->value);
        }

        if ($this->sessions->stepUpOperation($request) !== $operation->value) {
            throw StepUpRequired::forOperation($operation->value);
        }

        if ($this->sessions->stepUpSubject($request) !== (string) $user->id) {
            throw StepUpRequired::forOperation($operation->value);
        }
    }

    /**
     * Is a step-up currently satisfied? A boolean form, for a caller that has
     * already decided it wants to know rather than to refuse.
     */
    public function isSatisfied(Request $request, StepUpOperation $operation): bool
    {
        try {
            $this->assertSatisfied($request, $operation);

            return true;
        } catch (StepUpRequired) {
            return false;
        }
    }

    /**
     * Record a COMPLETED step-up — but ONLY against a verified second factor.
     *
     * ============================ WHY THIS REPLACED `perform()` ============================
     * The previous signature was:
     *
     *     public function perform(Request $request, StepUpOperation $op, ?string $id): User
     *
     * and it verified nothing at all. It is a generic "mark this session as
     * having completed a step-up" function: any caller with this object in hand
     * could produce a fresh, correctly-bound step-up for any of the seven
     * operations without presenting a second factor. The four checks in
     * `assertSatisfied()` would then all pass, because they all describe the
     * RESULT of a step-up and none of them asks where that result came from.
     *
     * `SEC-018` is not "remember that someone said they re-authenticated". It
     * requires a privileged action to be gated on re-authentication, and a
     * parameter that any caller can supply is not re-authentication.
     *
     * The fix is structural rather than documentary: a `StepUpProof` is required,
     * and a proof can only be minted by `StepUpVerifier` after an RFC 6238
     * check. There is no longer any signature on this class that records a
     * step-up without one. Adding a sibling method later that does would be a
     * visible change to this class, and the test suite asserts that the old
     * method name is gone.
     *
     * ============================ THE BINDING IS RE-DERIVED, NOT TRUSTED ============================
     * The operation written to the session is the operation named in the PROOF,
     * not the one passed as an argument. The argument is still required and is
     * still checked — it is what the caller is asking to authorise — but it is
     * not what gets recorded. That distinction matters: if the recorded operation
     * came from the caller, a caller could verify a factor for `export` and
     * request `refund`, and the mismatch would have to be caught by a check
     * somewhere. Instead the proof's operation is authoritative and the argument
     * must agree with it.
     *
     * The subject is likewise taken from the session's authenticated user, never
     * from the proof, so a proof minted for one identity cannot spend itself on
     * another. Both mismatches raise `StepUpRequired` rather than a distinct
     * error, so a caller cannot use the error to learn which check failed.
     *
     * `API-SPEC.md` §3.11 line 334 fixes the event name: `POST
     * /api/v1/auth/step-up` audits `STEP_UP_PERFORMED`, and §3.11.1 notes it is a
     * reserved action with no emitter. This is that emitter. The name is not
     * invented and is not substituted — `ADR-0016:23`'s authentication catalogue
     * does not list it, but that list is explicitly a MINIMUM catalogue "from
     * `Prd_Maker.md` §32", and `SEC-018` is a formal `PRD.md` requirement that a
     * privileged action produce an audit event. A minimum list is not an
     * exhaustive one.
     *
     * The operation IS recorded in the audit context. It is an operation name
     * from a closed enum, never free text from a caller and never a credential,
     * and `API-SPEC.md` §1.6 prohibits secrets rather than the name of the
     * operation being protected. The verified FACTOR is recorded alongside it so a
     * reviewer can see which second factor was actually checked — the one thing
     * that distinguishes a real step-up from a fabricated one.
     *
     * @param  StepUpProof  $proof  a verified second factor, from `StepUpVerifier`
     * @param  StepUpOperation  $operation  what the caller intends to authorise;
     *                                      must match the proof's operation
     *
     * @throws StepUpRequired when there is no authenticated subject, or the proof
     *                        belongs to a different subject or operation
     */
    public function complete(
        Request $request,
        StepUpOperation $operation,
        StepUpProof $proof,
        ?string $correlationId,
    ): User {
        $user = $this->sessions->requireUser();

        // The proof must be for the identity NOW ACTING. Compared as strings
        // because the session stores the subject as a string and `id` may not be.
        if (! $proof->isFor((string) $user->id)) {
            throw StepUpRequired::forOperation($operation->value);
        }

        // The proof must be for the operation about to be performed. A proof for a
        // document reveal is not spendable on a refund, and this is where that is
        // enforced rather than assumed.
        if ($proof->operation !== $operation) {
            throw StepUpRequired::forOperation($operation->value);
        }

        $this->sessions->recordStepUp($request, $proof);

        $this->audit->record(AuditRecord::of(
            action: AuditAction::StepUpPerformed,
            actorUserId: (string) $user->id,
            actorRole: $user->activeRoles()->first()?->value,
            propertyId: null,
            subjectType: 'user',
            subjectId: (string) $user->id,
            source: 'auth_step_up',
            correlationId: $correlationId,
            additionalContext: [
                'operation' => $proof->operation->value,
                // The factor that was actually verified. Without this, a
                // `STEP_UP_PERFORMED` record cannot be distinguished from a step-up
                // that some future path recorded without one.
                'method' => $proof->method->value,
                'ip_address' => $request->ip(),
            ],
        ));

        return $user;
    }

    /**
     * The error code a failed step-up carries.
     *
     * Exposed so a caller rendering a problem report agrees with the gate rather
     * than inventing a code. `API-SPEC.md` §2.1 fixes `STEP_UP_REQUIRED` at 403:
     * the caller IS authenticated, and is being refused the OPERATION, which is
     * what makes this a 403 and not a 401.
     */
    public static function errorCode(): ErrorCode
    {
        return ErrorCode::StepUpRequired;
    }
}
