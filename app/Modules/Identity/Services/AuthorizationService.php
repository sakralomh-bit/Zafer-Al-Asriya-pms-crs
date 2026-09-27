<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Contracts\Actor;
use App\Modules\Identity\Contracts\AuthorizesRequests;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecord;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Authorization\AccountNotActive;
use App\Shared\Authorization\PermissionDenied;
use App\Shared\Authorization\PropertyScopeDenied;
use App\Shared\Domain\DomainFailure;

/**
 * The single authorization decision point.
 *
 * `ADR-0014` §1:
 *
 *     Allow = Identity x Role x Resource x Action x Scope x Policy
 *
 * "Every one of the six factors must evaluate true. A missing factor is a
 * denial, not a pass. The formula is evaluated server-side on every request,
 * before any business logic executes."
 *
 * Evaluation order is scope, then permission. Scope first is deliberate: a
 * caller with no grant for a property must not learn whether they would have
 * been permitted to act on it there. The denial is `PROPERTY_SCOPE_DENIED`
 * either way.
 *
 * EVERY denial is audited. `ADR-0014` §7 requires deny-by-default to be
 * provable, and a denial nobody can inspect is not provable.
 */
final class AuthorizationService implements AuthorizesRequests
{
    public function __construct(
        private readonly PropertyScopeResolver $scopeResolver,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @throws PropertyScopeDenied|PermissionDenied
     */
    public function authorize(
        Actor $actor,
        Permission $permission,
        string $propertyId,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $correlationId = null,
    ): void {
        if (! $actor->isActive()) {
            $this->deny(
                $actor,
                $permission,
                $propertyId,
                AccountNotActive::forStatus($actor->accountStatus()),
                $subjectType,
                $subjectId,
                $correlationId,
            );
        }

        // ---- Scope factor -------------------------------------------------
        if (! $this->scopeResolver->hasGrant($actor, $propertyId)) {
            $this->deny(
                $actor,
                $permission,
                $propertyId,
                PropertyScopeDenied::forProperty($propertyId, $actor->identifier()),
                $subjectType,
                $subjectId,
                $correlationId,
            );
        }

        // ---- Role / Resource / Action factors -----------------------------
        if (! $actor->holds($permission)) {
            $this->deny(
                $actor,
                $permission,
                $propertyId,
                PermissionDenied::forPermission($permission->value),
                $subjectType,
                $subjectId,
                $correlationId,
            );
        }
    }

    /**
     * Non-throwing form, for policy `before()` hooks and for tests that assert
     * the decision rather than the exception.
     */
    public function allows(
        Actor $actor,
        Permission $permission,
        string $propertyId,
    ): bool {
        if (! $actor->isActive()) {
            return false;
        }

        if (! $this->scopeResolver->hasGrant($actor, $propertyId)) {
            return false;
        }

        return $actor->holds($permission);
    }

    /**
     * Authorization for an ORGANIZATION-level act, such as creating the first
     * property.
     *
     * `ADR-0007` states: "**No query executes without a property scope** (except
     * organization-level admin)." This method is that single, explicit exception.
     * It is not a bypass: it still requires the permission, it still requires an
     * ACTIVE identity, it still audits, and it still requires the actor to hold
     * at least one live property grant — so a user who has been granted nothing
     * cannot bootstrap their way into the hierarchy.
     */
    public function authorizeOrganizationLevel(
        Actor $actor,
        Permission $permission,
        string $organizationId,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $correlationId = null,
    ): void {
        if (! $actor->isActive()) {
            $this->deny(
                $actor,
                $permission,
                $organizationId,
                AccountNotActive::forStatus($actor->accountStatus()),
                $subjectType,
                $subjectId,
                $correlationId,
            );
        }

        $hasAnyGrant = count($this->scopeResolver->grantedPropertyIds($actor)) > 0;

        if (! $hasAnyGrant || ! $actor->holds($permission)) {
            $this->deny(
                $actor,
                $permission,
                $organizationId,
                PermissionDenied::forPermission($permission->value),
                $subjectType,
                $subjectId,
                $correlationId,
            );
        }
    }

    /**
     * @throws PropertyScopeDenied|PermissionDenied|AccountNotActive
     */
    private function deny(
        Actor $actor,
        Permission $permission,
        string $propertyId,
        DomainFailure $failure,
        ?string $subjectType,
        ?string $subjectId,
        ?string $correlationId,
    ): never {
        $this->audit->recordDenial(
            AuditRecord::of(
                action: AuditAction::AuthorizationDenied,
                actorUserId: $actor->identifier(),
                actorRole: ($actor->roles()[0] ?? null)?->value,
                propertyId: $propertyId,
                subjectType: $subjectType,
                subjectId: $subjectId,
                source: 'authorization',
                correlationId: $correlationId,
                additionalContext: ['requested_permission' => $permission->value],
                result: 'DENIED',
            ),
            $failure,
        );

        throw $failure;
    }
}
