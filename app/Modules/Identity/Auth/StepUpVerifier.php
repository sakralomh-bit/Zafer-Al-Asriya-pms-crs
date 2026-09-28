<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Auth\Mfa\MfaFactorProvider;
use App\Modules\Identity\Auth\Mfa\MfaMethod;
use App\Modules\Identity\Auth\Mfa\StepUpProof;
use App\Modules\Identity\Auth\Mfa\TotpParameters;
use App\Modules\Identity\Auth\Mfa\TotpVerifier;
use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecord;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Domain\DomainFailure;
use Illuminate\Support\Carbon;

/**
 * The trust boundary: a submitted second factor in, a `StepUpProof` out.
 *
 * ============================ THE PROBLEM THIS EXISTS TO SOLVE ============================
 * Before this class, `StepUpGuard` had a public `perform(Request, StepUpOperation,
 * ?string)` that recorded a COMPLETED step-up. It verified nothing. Any caller
 * holding the guard could invoke it and obtain a valid, fresh, correctly-bound
 * step-up for any of the seven operations without presenting a second factor at
 * all. The gate below was then satisfied, and every privileged action the gate
 * fronts was authorised.
 *
 * That is not a missing feature. It is the control being absent while appearing
 * present — the failure mode `SEC-018` exists to prevent. `perform()` is replaced
 * by `StepUpGuard::complete()`, which will not run without a `StepUpProof`, and a
 * proof can only be produced here, from a real RFC 6238 check.
 *
 * ============================ WHAT IS DELIBERATELY NOT HERE ============================
 * This is the trusted HALF of MFA and nothing more. It is NOT:
 *
 *   - An enrolment flow. Who may enrol a factor for whom is an accountability
 *     question `C-10` has not answered, and answering it here would be inventing
 *     a security workflow. `docs/DATA-MODEL.md` §2 reserves `mfa_secrets`; no
 *     migration creates it.
 *   - A recovery path. "How is a staff member who lost their authenticator at
 *     02:00 identified, and by whom" is the same unanswered question, and a
 *     recovery code is a bypass by construction.
 *   - A secret store. The factor is fetched through `MfaFactorProvider`, which
 *     has NO production implementation because `H-03` has not settled how a
 *     secret is sealed or rotated. A deployment that cannot supply a factor
 *     cannot complete a step-up, and refusing is the correct behaviour for a
 *     missing security decision.
 *   - A rate limiter for challenges. A challenge endpoint would need one, and
 *     building it without the endpoint would be building half a control.
 *   - An HTTP endpoint. `API-SPEC.md` §3.11 describes `POST /api/v1/auth/step-up`
 *     and `POST /api/v1/auth/mfa/verify`; neither is registered as a route in
 *     this task. Adding a route that accepts a code is a different, larger
 *     decision, and this class is deliberately callable only from application
 *     code that already has an authenticated user and a submitted code.
 *
 * ============================ WHY THE FAILURE IS AUDITED SEPARATELY ============================
 * `ADR-0016:23` requires "MFA challenge and failure" to be auditable. A failed
 * second factor is recorded as `MFA_FAILED` rather than folded into `AUTH_FAILED`
 * because the two answer different questions: a failed login is "we do not know
 * you", and a failed second factor is "we know exactly who you are and the
 * factor did not hold". Collapsing them would erase the signal that separates a
 * compromised password from a coerced or socially engineered one, which is the
 * signal a security review is looking for.
 *
 * Neither the code nor the secret is ever written to the audit trail.
 * `API-SPEC.md` §1.6 prohibits it, and the trail is append-only — anything put
 * there is permanent.
 */
final class StepUpVerifier
{
    public function __construct(
        private readonly TotpVerifier $totp,
        private readonly AuditRecorder $audit,
        private readonly SessionSecurity $sessions,
        private readonly MfaFactorProvider $factors,
    ) {}

    /**
     * Verify a submitted second factor for WHOEVER IS AUTHENTICATED, and, only on
     * success, mint a proof.
     *
     * The signature has NO subject parameter and NO timestamp parameter, and that
     * is the design rather than an omission:
     *
     *   - The SUBJECT is resolved from the guard by `SessionSecurity`, and the
     *     factor is fetched FOR that subject. A caller holding User A's factor
     *     cannot produce a proof for User B, because there is no argument in which
     *     to name B — and cannot pair A's factor with B's identity, because the
     *     lookup is keyed on the resolved identity and a code computed from A's
     *     factor will not match the secret stored for B.
     *   - The TIMESTAMP comes from the server clock inside the mint. A caller
     *     cannot pin a verification instant in the future, so a step-up cannot be
     *     made fresh before it is. Tests move the clock with `Carbon::setTestNow()`
     *     rather than passing an override, which keeps that capability out of the
     *     production API.
     *
     * Both were caller-controlled in an earlier version of this path. A caller
     * could then mint a proof naming a different subject, or date one arbitrarily.
     * `StepUpVerifier` passed the correct values, but that is a convention about
     * call sites and not a control.
     *
     * Returns null rather than throwing on a failed challenge. A failed code is an
     * expected outcome of a challenge, not a defect, and a caller that has to
     * catch an exception to discover "wrong code" is a caller that will forget to
     * catch it — which turns a refused second factor into an allowed one.
     * Configuration and policy faults still throw, because those are genuinely
     * exceptional and must not be read as "the user typed it wrong".
     *
     * @param  string  $submittedCode  what the user submitted
     * @param  TotpParameters|null  $parameters  null uses the policy's own
     *
     * @throws SecurityPolicyUnresolved when the TOTP parameters are unusable,
     *                                  which is a broken deployment rather than
     *                                  a wrong code
     * @throws DomainFailure when no factor is enrolled for the
     *                       authenticated identity
     */
    public function verifyTotp(
        StepUpOperation $operation,
        string $submittedCode,
        ?TotpParameters $parameters = null,
        ?string $correlationId = null,
    ): ?StepUpProof {
        // Read for the FAILED record, which has no proof to read it from. The mint
        // reads its own, because it must not be able to be told what time it is.
        //
        // On success the PROOF'S instant is the one recorded, not this one. The two
        // are separate clock reads, and nothing forced them to agree — so the trail
        // could carry a `verified_at` that was not the instant the proof's freshness
        // window is measured from. Of the two values, the one an investigator reads
        // is the one in the trail, so it is the one that must be right.
        $failureAt = Carbon::now();

        $proof = StepUpProof::mintFromVerifiedTotp(
            $this->sessions,
            $this->factors,
            $this->totp,
            $operation,
            $submittedCode,
            $parameters,
        );

        if ($proof === null) {
            $this->recordFailure($this->sessions->requireUser(), $operation, $correlationId, $failureAt);

            return null;
        }

        $this->recordChallenge(
            $this->sessions->requireUser(),
            $operation,
            $proof->method,
            $correlationId,
            $proof->verifiedAt,
        );

        return $proof;
    }

    /**
     * A successful verification is auditable, and the audit names the FACTOR and
     * the OPERATION.
     *
     * The operation is recorded here rather than at step-up completion because
     * this is where it is actually decided: the verifier is handed the operation
     * the proof will authorise, so a proof can never be minted for one operation
     * and later spent on another.
     */
    private function recordChallenge(
        User $user,
        StepUpOperation $operation,
        MfaMethod $method,
        ?string $correlationId,
        Carbon $at,
    ): void {
        $this->audit->record(AuditRecord::of(
            action: AuditAction::MfaChallenged,
            actorUserId: (string) $user->id,
            actorRole: $user->activeRoles()->first()?->value,
            propertyId: null,
            subjectType: 'user',
            subjectId: (string) $user->id,
            source: 'auth_mfa',
            correlationId: $correlationId,
            additionalContext: [
                'result' => 'accepted',
                'method' => $method->value,
                'operation' => $operation->value,
                'ip_address' => null,
                // Present so a reader can tell a fresh verification from a replay
                // of an old log line. The timestamp itself is not secret.
                'verified_at' => $at->toIso8601String(),
            ],
        ));
    }

    /**
     * A refused code is auditable too, and carries NO code and NO secret.
     *
     * `ip_address` is null rather than a real value because this class has no
     * request: it is given a user, a secret, and a code. Passing a fabricated
     * address would put something untrue in an append-only trail, and threading
     * a `Request` through to fill it would couple a crypto primitive to the HTTP
     * layer for no security gain. A caller holding a request should record the
     * address on the challenge event it already emits.
     */
    private function recordFailure(
        User $user,
        StepUpOperation $operation,
        ?string $correlationId,
        Carbon $at,
    ): void {
        $this->audit->record(AuditRecord::of(
            action: AuditAction::MfaFailed,
            actorUserId: (string) $user->id,
            actorRole: $user->activeRoles()->first()?->value,
            propertyId: null,
            subjectType: 'user',
            subjectId: (string) $user->id,
            source: 'auth_mfa',
            correlationId: $correlationId,
            additionalContext: [
                'result' => 'refused',
                'method' => MfaMethod::Totp->value,
                'operation' => $operation->value,
                'ip_address' => null,
                'attempted_at' => $at->toIso8601String(),
            ],
        ));
    }
}
