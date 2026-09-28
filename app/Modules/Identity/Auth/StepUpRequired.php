<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * A sensitive operation was attempted without a current step-up.
 *
 * `docs/API-SPEC.md` §2.1: `STEP_UP_REQUIRED` — 403 — "Sensitive operation
 * requires re-authentication". `SEC-018` requires step-up on the privileged
 * action set, and the canonical set is the seven operations in
 * `docs/SECURITY.md` §6.1: refund, configuration, scope grants, business-date
 * reopen, export, impersonation, identity-document reveal. `StepUpOperation`
 * transcribes it and `StepUpGuard` enforces it.
 *
 * 403 AND NOT 401 IS DELIBERATE. The caller has proved who they are; what they
 * lack is the RECENT re-authentication the operation requires. Reporting it as
 * `AUTH_REQUIRED` would tell a client to sign in again, which does not help,
 * because signing in again is not what is missing — a step-up is.
 *
 * THE MESSAGE IS UNIFORM ACROSS ALL FOUR FAILURE MODES. A missing step-up, an
 * expired one, one taken for a different operation, and one taken by a different
 * subject all produce this same refusal with the same message. Differentiating
 * them would tell an attacker exactly which part of a stolen step-up is wrong and
 * therefore how to fix it, and would tell a legitimate user that their step-up
 * was real but for another operation — which is a step towards getting them to
 * perform the wrong one.
 *
 * The `operation` detail names which operation was attempted. That comes from a
 * CLOSED enum, so it is one of seven fixed strings and never caller-supplied
 * free text.
 *
 * This refusal is ALSO what an untrusted attempt to manufacture a step-up gets.
 * `StepUpGuard::complete()` raises it when the proof's subject or operation does
 * not match, and `SessionSecurity` returns null from every step-up read when the
 * integrity seal does not verify — so a caller that wrote the session keys by
 * hand is refused by the same class and the same error as one that did nothing.
 * One failure mode, one message, no oracle for which check fired.
 *
 * What is still NOT here is the endpoint that SATISFIES this: there is no
 * re-authentication route and no MFA challenge handler, because the enrolment
 * and recovery authority behind it are unresolved (`C-10`,
 * `docs/SECURITY.md` §12.1.1 rows 12 and 16). `StepUpVerifier` performs the
 * RFC 6238 check that produces a `StepUpProof`, and `StepUpGuard::complete()`
 * requires one — but nothing in this task registers a route, because a route
 * that accepts a secret and a code is a larger decision and answering the
 * accountability question `C-10` leaves open would mean inventing it.
 */
final class StepUpRequired extends DomainFailure
{
    public static function forOperation(string $operation): self
    {
        return new self(
            ErrorCode::StepUpRequired,
            'This operation requires step-up authentication; re-authenticate and try again.',
            [['field' => 'operation', 'message' => $operation]],
        );
    }
}
