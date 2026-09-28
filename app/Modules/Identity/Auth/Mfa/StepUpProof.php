<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth\Mfa;

use App\Modules\Identity\Auth\SessionSecurity;
use App\Modules\Identity\Auth\StepUpOperation;
use App\Shared\Domain\DomainFailure;
use Illuminate\Support\Carbon;

/**
 * A VERIFIED second factor, bound to one subject and one operation.
 *
 * ============================ WHAT THIS OBJECT IS ============================
 * The capability that `StepUpGuard` requires before it will record a completed
 * step-up. It exists because the previous design had no such thing: the gate
 * exposed `perform(Request, StepUpOperation, ?string)`, and any caller with the
 * object in hand could call it and manufacture a finished step-up without ever
 * proving anything. The gate checked the RESULT of a step-up and nothing checked
 * where the result came from, so the control was one careless call away from
 * being decorative.
 *
 * ============================ WHERE THE TRUST BOUNDARY ACTUALLY IS ============================
 * Stated plainly, because a security primitive that oversells itself is worse
 * than none.
 *
 * PHP cannot make a class unconstructible by application code: reflection
 * defeats any visibility modifier, and application code can read
 * `config('app.key')`. A private constructor is therefore NOT a boundary and
 * this class does not claim to be one.
 *
 * The boundary is a POSSESSION REQUIREMENT, and it is enforced by the SHAPE of
 * the factory rather than by any rule a caller is asked to follow. To obtain a
 * proof you must call `mintFromVerifiedTotp()` with the enrolled factor and a
 * code for it, and that method runs RFC 6238 verification itself and constructs
 * nothing until that succeeds.
 *
 * THREE THINGS A CALLER CANNOT DO, and each is a parameter that does not exist
 * rather than a comment asking them not to:
 *
 *   1. SUBSTITUTE A SUBJECT. The subject is not an argument. It is read from the
 *      authenticated identity and the factor is fetched FOR that identity by
 *      `MfaFactorProvider`, so the two are bound by the lookup rather than
 *      supplied side by side. A caller holding User A's factor obtains a proof
 *      for User A, or nothing — there is no signature that produces a proof for
 *      an identity whose factor was never checked.
 *   2. CHOOSE A FUTURE TIMESTAMP. The verification instant is read from the clock
 *      inside the mint. There is no `$at` parameter on the production path, so
 *      a caller cannot pin a timestamp ahead of now and make a step-up fresh when
 *      it is not. Tests move the clock with `Carbon::setTestNow()` instead, which
 *      is a test-only mechanism and changes no API.
 *   3. SKIP THE VERIFICATION. There is no factory that takes a subject, an
 *      operation, a timestamp and a method name. An earlier version of this class
 *      had one, and it was a forgery door; a structural test now fails if any
 *      public factory here can be called without a secret and a code.
 *
 * Defence in depth, beyond the above: `StepUpGuard` re-checks the subject and
 * operation binding at the moment of completion, and the session state is sealed
 * with an HMAC so the session payload alone cannot establish a step-up.
 *
 * ============================ WHAT IT DELIBERATELY DOES NOT CARRY ============================
 * No secret, and no code. The submitted code is consumed by the verifier and
 * discarded; the secret is never copied into a proof, an audit record, or a
 * log. `API-SPEC.md` §1.6 prohibits that, and a proof that outlived the request
 * carrying a secret would be a second copy of the thing `H-03` has not yet
 * settled how to seal.
 *
 * The proof binds ONE operation. It is not a general "the user re-authenticated"
 * token, because that is the property that made cross-operation reuse possible:
 * a proof for a document reveal must not be spendable on a refund.
 */
final readonly class StepUpProof
{
    private function __construct(
        /** The identity whose second factor was verified. */
        public string $subjectId,
        /** The single operation this proof authorises. */
        public StepUpOperation $operation,
        /**
         * When the second factor was VERIFIED.
         *
         * The freshness anchor is this instant, not the instant the state was
         * written. If the two differed, a caller could verify a factor and then
         * delay recording it, and the delay would silently extend the window.
         */
        public Carbon $verifiedAt,
        /** Which factor was actually verified — recorded, not re-decided. */
        public MfaMethod $method,
    ) {}

    /**
     * Mint a proof — and only by VERIFYING the factor in the same breath.
     *
     * ============================ THE SHAPE IS THE BOUNDARY ============================
     * This method takes NO subject and NO timestamp, and their absence is the
     * whole point.
     *
     * An earlier version of this class took a secret, a code, a subject id, a
     * timestamp and a method name. Verification was genuine — a code still had to
     * match — but the subject and the timestamp were caller-controlled, so a
     * caller holding User A's factor could produce a proof naming User B, or pin
     * a verification instant in the future so a step-up read as fresh when it was
     * not. `StepUpVerifier` passed the right values, which is a convention, not a
     * control.
     *
     * Both are now DERIVED rather than supplied:
     *
     *   - The subject is read from the authenticated identity via
     *     `SessionSecurity::requireUser()`, which resolves the guard. The factor is
     *     then fetched FOR that identity, so the subject and the factor cannot be
     *     paired across two different people. A caller who holds User A's factor
     *     gets a proof for User A or `null`; there is no argument that names a
     *     different subject.
     *   - The instant is read from the clock here. A caller cannot supply one, so
     *     a future-dated step-up cannot be manufactured. Tests that need a fixed
     *     moment move the clock with `Carbon::setTestNow()` rather than passing a
     *     parameter, which keeps the override out of the production API entirely.
     *
     * The operation remains a parameter: the caller IS requesting an authorisation
     * for a specific operation, and the operation is checked against the proof
     * again in `StepUpGuard::complete()`.
     *
     * Returns null on failure rather than throwing, for the same reason the
     * verifier does: a wrong code is an expected outcome of a challenge, and a
     * caller that has to catch an exception to discover it is a caller that will
     * eventually forget to catch it — which turns a refused second factor into an
     * allowed one. A missing or unresolvable factor raises instead, because that
     * is a deployment defect rather than a user typing the wrong digits.
     *
     * @param  SessionSecurity  $sessions  resolves the authenticated subject
     * @param  MfaFactorProvider  $factors  supplies the factor FOR that subject
     * @param  TotpVerifier  $totp  the RFC 6238 primitive
     * @param  string  $submittedCode  what the user submitted
     * @param  TotpParameters|null  $parameters  null uses the RFC defaults
     *
     * @throws DomainFailure when no factor is enrolled
     */
    public static function mintFromVerifiedTotp(
        SessionSecurity $sessions,
        MfaFactorProvider $factors,
        TotpVerifier $totp,
        StepUpOperation $operation,
        string $submittedCode,
        ?TotpParameters $parameters = null,
    ): ?self {
        $subjectId = $sessions->subjectIdOfAuthenticatedUser();

        // The factor is fetched FOR the subject that was just resolved. This is the
        // line that removes subject substitution: a caller cannot hand in a
        // different identity, because the identity was not theirs to hand in.
        $factor = $factors->factorFor($sessions->requireUser());

        $verifiedAt = Carbon::now();

        if (! $totp->verify($factor, $submittedCode, $parameters, $verifiedAt)) {
            return null;
        }

        return new self($subjectId, $operation, $verifiedAt, MfaMethod::Totp);
    }

    /**
     * Does this proof belong to the identity now acting?
     *
     * Compared as strings because the session stores the subject as a string and
     * `$user->id` may be an int or a string depending on the driver. A strict
     * `===` between `1` and `'1'` would refuse a legitimate subject for a
     * formatting reason, which is the kind of failure that gets "fixed" by
     * loosening the check.
     */
    public function isFor(string $subjectId): bool
    {
        return $this->subjectId === $subjectId;
    }

    /**
     * A description safe for an audit context.
     *
     * The factor and the operation, never the code and never the secret.
     */
    public function describe(): string
    {
        return $this->method->value.':'.$this->operation->value;
    }
}
