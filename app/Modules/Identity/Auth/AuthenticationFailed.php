<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * Authentication failed: the credentials did not identify an ACTIVE identity.
 *
 * `docs/API-SPEC.md` §2.1: `AUTH_FAILED` — 401 — "Invalid credentials".
 *
 * This is returned for BOTH "no such account" and "wrong password", and the
 * distinction is destroyed on purpose. `docs/SECURITY.md` `TH-01` treats user
 * enumeration as part of credential attack, and the observable difference
 * between the two cases is a timing difference as well as a message
 * difference — which is why `AuthenticationService` performs a dummy hash
 * verification when no account matches, so the two paths cost the same.
 *
 * An INACTIVE account is also `AUTH_FAILED` here, not `ACCOUNT_SUSPENDED`.
 * `ACCOUNT_SUSPENDED` exists and is used by the AUTHORIZATION layer
 * (`AccountNotActive`) for a valid, previously authenticated actor whose
 * account has since been suspended. Reporting it at the login boundary would
 * confirm the account exists, so at login the answer is the same refusal for
 * every non-ACTIVE state.
 */
final class AuthenticationFailed extends DomainFailure
{
    public static function invalidCredentials(): self
    {
        return new self(
            ErrorCode::AuthFailed,
            'The email or password is incorrect.',
        );
    }
}
