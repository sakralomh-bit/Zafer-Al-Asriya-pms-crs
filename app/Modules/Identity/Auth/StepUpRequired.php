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
 * action set, and `ADR-0014` §6 names the operations: refund, identity-document
 * reveal, and (with Finance approval) business-date reopen.
 *
 * What is NOT here is a way to satisfy this. No MFA mechanism is specified in
 * any governing document — `docs/SECURITY.md` §12 records "MFA coverage: which
 * roles mandatory" as `TBD`, and nothing names TOTP, SMS, email, or a passkey.
 * Choosing one would be inventing a security architecture, so `StepUpGuard`
 * implements the REQUIREMENT as a gate that fails closed and leaves the
 * satisfaction mechanism unimplemented. See `StepUpGuard`.
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
