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
 * action set, and `DR-T004-09` fixes that set as the seven operations named in
 * `docs/SECURITY.md` §6: refund, configuration, scope grants, business-date
 * reopen, export, impersonation, identity-document reveal.
 *
 * What is NOT here is a way to satisfy this. `SessionSecurity::recordStepUp()`
 * and `stepUpAt()` carry the state; nothing reads it yet, because the gate that
 * would read it is NOT IMPLEMENTED and must not be implemented until the
 * mechanism exists. No MFA mechanism is specified in any governing document —
 * `docs/SECURITY.md` §12 records "MFA coverage: which roles mandatory" as
 * `TBD`, and nothing names TOTP, SMS, email, or a passkey. Building the gate
 * now would mean inventing a security architecture (`DR-T004-08`, still OPEN).
 *
 * This class is therefore a refusal with no producer. It exists so the error
 * contract is fixed and tested while the mechanism stays honestly absent.
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
