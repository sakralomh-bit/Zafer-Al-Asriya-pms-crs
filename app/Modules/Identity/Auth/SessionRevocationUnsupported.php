<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * Sessions cannot be revoked because the session store cannot support it.
 *
 * `AC-T-004-04` requires that a role or scope change revokes live sessions
 * without waiting for expiry. `SessionRevoker` does that by removing
 * server-side rows, which only works for the `database` driver.
 *
 * This failure exists so that an incompatible driver is a loud, typed refusal
 * rather than a delete that affects zero rows and reports success. A silent
 * no-op is the worst possible outcome here: the code would look correct, the
 * tests would pass against the database driver, and production — or a
 * misconfigured environment — would keep serving sessions that the
 * authorization layer has already stopped trusting.
 */
final class SessionRevocationUnsupported extends DomainFailure
{
    public static function forDriver(string $driver): self
    {
        return new self(
            ErrorCode::ServiceUnavailable,
            'Sessions cannot be revoked: the session driver is ['.$driver.'] and this application '
            .'revokes sessions by removing their server-side rows. AC-T-004-04 cannot be honoured '
            .'until the driver supports server-side revocation.',
        );
    }
}
