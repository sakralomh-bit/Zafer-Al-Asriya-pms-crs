<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * The caller has no grant for the requested property.
 *
 * `ADR-0014` §3: a request for an ungranted property receives an explicit
 * denial, "never an empty result set" — for two reasons, and the second is the
 * important one:
 *
 *  1. An empty result discloses that the property exists. A denial does not.
 *  2. An empty result makes a scope bug invisible in testing: "no rooms
 *     returned" looks identical to "correctly denied". A denial is assertable.
 */
final class PropertyScopeDenied extends DomainFailure
{
    public static function forProperty(string $propertyId, string $actorUserId): self
    {
        return new self(
            ErrorCode::PropertyScopeDenied,
            'You do not have access to the requested property.',
            [],
        );
    }
}
