<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The request was refused because a rate limit or lockout is in force.
 *
 * `docs/API-SPEC.md` §1.5 assigns `RATE_LIMITED` the status 429, and §1.5's
 * table for 429 says it "includes retry guidance". A 429 without a hint about
 * when to return is a client that either hammers the endpoint or gives up, so
 * the retry delay is carried on the failure and the renderer sets it as the
 * `Retry-After` header.
 *
 * `docs/SECURITY.md` §12 records the rate limit VALUES as TBD, so the numbers
 * come from the owning subsystem's policy rather than from here.
 */
final class RateLimited extends DomainFailure
{
    public function __construct(
        public readonly int $retryAfterSeconds,
        string $message,
    ) {
        parent::__construct(ErrorCode::RateLimited, $message, [
            ['field' => 'retry_after_seconds', 'message' => (string) max(0, $retryAfterSeconds)],
        ]);
    }
}
