<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Authorization\Permission;
use App\Shared\Domain\DomainFailure;

/**
 * The authorization decision point, as other modules see it.
 *
 * `ADR-0014` §1: `Allow = Identity x Role x Resource x Action x Scope x Policy`.
 * The implementation lives in `Modules\Identity\Services\AuthorizationService`;
 * this interface is the only surface another module is allowed to touch, so the
 * six-factor formula cannot be bypassed by calling something narrower or wider.
 */
interface AuthorizesRequests
{
    /**
     * @throws DomainFailure PROPERTY_SCOPE_DENIED when there is no grant for the
     *                        property — a DENIAL, never an empty result
     * @throws DomainFailure PERMISSION_DENIED     when the actor's roles do not
     *                        include the permission
     */
    public function authorize(
        Actor $actor,
        Permission $permission,
        string $propertyId,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $correlationId = null,
    ): void;

    public function allows(Actor $actor, Permission $permission, string $propertyId): bool;
}
