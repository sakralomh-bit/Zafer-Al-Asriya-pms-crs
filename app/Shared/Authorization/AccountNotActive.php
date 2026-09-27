<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * The identity factor failed: the account exists but is not ACTIVE.
 *
 * `docs/STATE-MACHINES.md` §J.5 defines the user lifecycle
 * `INVITED -> ACTIVE -> SUSPENDED -> DISABLED`. Only ACTIVE may act. A
 * SUSPENDED or DISABLED account is denied here rather than being given an
 * empty permission set, so the reason a person cannot work is distinguishable
 * from the reason they cannot reach a property.
 */
final class AccountNotActive extends DomainFailure
{
    public static function forStatus(string $status): self
    {
        return new self(
            ErrorCode::AccountSuspended,
            'This account is not active.',
            [['field' => 'status', 'message' => $status]],
        );
    }
}
