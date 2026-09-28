<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Auth\SessionRevocationUnsupported;
use App\Modules\Identity\Auth\SessionRevoker;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserPropertyScope;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecord;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Authorization\PermissionDenied;
use App\Shared\Domain\BusinessRuleViolation;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use Illuminate\Support\Carbon;

/**
 * Grants and revokes property access.
 *
 * `ADR-0014` §6, "Separation of duties and step-up":
 *
 *   "Grant or revoke a scope | Cannot grant to oneself; requires an independent
 *    approver; audited"
 *
 * `AC-T-003-04` makes the self-grant rule executable, and `ADR-0014`'s risk
 * table rates a self-grant as **Critical**. It is therefore checked here, on
 * the server, before any write, and the attempt is audited whether or not it
 * succeeds.
 *
 * `AC-T-003-03` — Group Manager's access to all ten properties — is produced by
 * calling this ten times. It is deliberately not a flag and not a shortcut.
 *
 * `AC-T-004-04` — "A session is revoked when the user's role or property scope
 * changes, without waiting for expiry" — is satisfied HERE, because these are
 * the only property-scope write paths in the system. Every change a live
 * session could be relying on passes through one of the two methods below, so
 * this is the one place revocation has to happen; `SessionRevoker` is otherwise
 * called by nothing in `app/`.
 *
 * The dependency is INTERNAL to the module: `Services` → `Auth` inside
 * `Modules\Identity`. It is not a cross-module import, and it does not reach
 * into the authorization engine — `SessionRevoker` takes a user id and touches
 * only the session table. `Actor`, `AuthorizesRequests`, and
 * `AuthorizationService` are untouched by this wiring.
 */
final class ScopeGrantService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly PropertyScopeResolver $scopeResolver,
        private readonly AuditRecorder $audit,
        private readonly SessionRevoker $sessions,
    ) {}

    /**
     * @throws PermissionDenied when the actor lacks `scope.grant`
     * @throws DomainFailure when the actor would be granting to themselves
     * @throws SessionRevocationUnsupported when the session driver cannot be revoked
     */
    public function grant(
        User $actor,
        User $subject,
        string $propertyId,
        ?string $reason,
        ?string $approvedByUserId,
        ?Carbon $expiresAt,
        ?string $correlationId,
    ): UserPropertyScope {
        // ORDER IS A SECURITY PROPERTY, NOT A STYLE CHOICE.
        //
        // The self-grant check runs FIRST, before the property-scope
        // authorization. The obvious ordering — authorize, then check — makes
        // the self-grant rule DEAD CODE:
        //
        //   A user who tries to grant themselves property P does not yet have a
        //   grant for P. Authorizing against P first therefore ALWAYS fails
        //   with PROPERTY_SCOPE_DENIED, and the self-grant branch below can
        //   never be reached. `ADR-0014` rates self-grant **Critical** and
        //   requires it to be audited; in the reverse order the attempt is
        //   indistinguishable from any other ungranted-property request and the
        //   SCOPE_SELF_GRANT_DENIED audit row is never written.
        //
        // The self-grant rule is about the relationship between actor and
        // subject, not about reaching the property, so nothing about checking it
        // first requires a scope the actor does not have.
        if ($subject->id === $actor->id) {
            $this->denySelfGrant($actor, $subject, $propertyId, $reason, $correlationId);
        }

        $this->authorization->authorize(
            $actor,
            Permission::ScopeGrant,
            $propertyId,
            subjectType: 'user_property_scope',
            correlationId: $correlationId,
        );

        $this->assertTimeBoundWhereRequired($actor, $subject, $approvedByUserId, $expiresAt, $correlationId);

        $scope = UserPropertyScope::query()->firstOrNew([
            'user_id' => $subject->id,
            'property_id' => $propertyId,
        ]);

        $scope->fill([
            'granted_by_user_id' => $actor->id,
            'approved_by_user_id' => $approvedByUserId ?? $actor->id,
            'reason' => $reason,
            'correlation_id' => $correlationId,
            'granted_at' => now(),
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'revoked_by_user_id' => null,
        ])->save();

        $this->scopeResolver->forget($actor);
        $this->scopeResolver->forget($subject);

        $this->audit->record(AuditRecord::of(
            action: AuditAction::ScopeGranted,
            actorUserId: (string) $actor->id,
            actorRole: $actor->activeRoles()->first()?->value,
            propertyId: $propertyId,
            subjectType: 'user',
            subjectId: (string) $subject->id,
            source: 'scope_grant',
            correlationId: $correlationId,
            reason: $reason,
            after: [
                'user_id' => (string) $subject->id,
                'property_id' => $propertyId,
                'expires_at' => $expiresAt?->toIso8601String(),
                'approved_by_user_id' => $approvedByUserId,
            ],
        ));

        $this->invalidateSubjectSessions($subject);

        return $scope;
    }

    /**
     * @throws PermissionDenied when the actor lacks `scope.revoke`
     * @throws SessionRevocationUnsupported when the session driver cannot be revoked
     */
    public function revoke(
        User $actor,
        User $subject,
        string $propertyId,
        ?string $reason,
        ?string $correlationId,
    ): void {
        // Same ordering rule as `grant()`: the self-revoke check precedes the
        // scope authorization, or it is unreachable.
        if ($subject->id === $actor->id) {
            $this->denySelfGrant($actor, $subject, $propertyId, $reason, $correlationId);
        }

        $this->authorization->authorize(
            $actor,
            Permission::ScopeRevoke,
            $propertyId,
            subjectType: 'user_property_scope',
            correlationId: $correlationId,
        );

        $scope = UserPropertyScope::query()
            ->where('user_id', $subject->id)
            ->where('property_id', $propertyId)
            ->first();

        if ($scope === null || $scope->isActive() === false) {
            return;
        }

        // The row is retained, never deleted: the audit trail must be able to
        // explain why someone could or could not see a property on a date.
        $scope->forceFill([
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
        ])->save();

        $this->scopeResolver->forget($subject);

        $this->audit->record(AuditRecord::of(
            action: AuditAction::ScopeRevoked,
            actorUserId: (string) $actor->id,
            actorRole: $actor->activeRoles()->first()?->value,
            propertyId: $propertyId,
            subjectType: 'user',
            subjectId: (string) $subject->id,
            source: 'scope_revoke',
            correlationId: $correlationId,
            reason: $reason,
            before: ['active' => true],
            after: ['active' => false],
        ));

        $this->invalidateSubjectSessions($subject);
    }

    /**
     * Cut the SUBJECT's live sessions after their scope has changed.
     *
     * WHY THE SUBJECT AND NOT THE ACTOR: the actor is the administrator who
     * made the change and their own authority is unaffected. Revoking their
     * session would be a self-inflicted denial of service with no security
     * benefit.
     *
     * WHY BOTH DIRECTIONS. `AC-T-004-04` says a session is revoked when the
     * scope *changes*, and both methods change it. For `revoke()` the need is
     * obvious: the next request is already denied by `PropertyScopeResolver`,
     * but the session is still an authenticated session and still reaches code
     * that only asks "is this authenticated". For `grant()` the session is not
     * carrying stale authority — nothing about permissions is cached in it — but
     * forcing a fresh sign-in means the widened grant takes effect under a
     * session established after the change rather than one established before
     * it, which is the stricter reading and the one the criterion states.
     *
     * In the `AC-T-003-03` provisioning case — Group Manager's ten properties,
     * granted by calling `grant()` ten times — the subject has no session yet,
     * so this is a no-op that deletes zero rows each time.
     *
     * CALLED AFTER THE AUDIT ROW, AND THAT ORDER IS A SECURITY PROPERTY. The
     * scope change is already persisted by this point and cannot be rolled
     * back, so the audit entry must exist regardless of whether the
     * revocation succeeds — `ADR-0016` requires the change to be recorded, and
     * an unaudited scope change is worse than a session that outlived it. If
     * revocation fails the exception propagates, so the failure is visible to
     * the caller rather than swallowed. Swallowing it here would be the exact
     * silent-failure mode `AC-T-004-04` exists to prevent.
     *
     * @throws SessionRevocationUnsupported
     */
    private function invalidateSubjectSessions(User $subject): void
    {
        $this->sessions->revokeAllFor((string) $subject->id);
    }

    /**
     * `ADR-0014` §5 (Support): "no grant without a named approver and an
     * expiry". A Support grant that lacks either is refused rather than created
     * and later noticed.
     *
     * @throws DomainFailure
     */
    private function assertTimeBoundWhereRequired(
        User $actor,
        User $subject,
        ?string $approvedByUserId,
        ?Carbon $expiresAt,
        ?string $correlationId,
    ): void {
        $isSupport = $subject->activeRoles()->contains(
            static fn (Role $role): bool => $role->requiresTimeBoundGrants(),
        );

        if (! $isSupport) {
            return;
        }

        if ($approvedByUserId === null || $approvedByUserId === '') {
            throw $this->buildSupportGrantFailure(
                ErrorCode::BusinessRuleViolation,
                'A Support grant requires a named approver (ADR-0014 §5).',
                $actor,
                $subject,
                $correlationId,
            );
        }

        if ($expiresAt === null) {
            throw $this->buildSupportGrantFailure(
                ErrorCode::BusinessRuleViolation,
                'A Support grant must be time-bound (ADR-0014 §5).',
                $actor,
                $subject,
                $correlationId,
            );
        }
    }

    /**
     * @throws DomainFailure
     */
    private function denySelfGrant(
        User $actor,
        User $subject,
        string $propertyId,
        ?string $reason,
        ?string $correlationId,
    ): never {
        $failure = new BusinessRuleViolation(
            ErrorCode::PermissionDenied,
            'A user cannot grant or revoke their own property access (ADR-0014 §6).',
        );

        $this->audit->recordDenial(
            AuditRecord::of(
                action: AuditAction::ScopeSelfGrantDenied,
                actorUserId: (string) $actor->id,
                actorRole: $actor->activeRoles()->first()?->value,
                propertyId: $propertyId,
                subjectType: 'user',
                subjectId: (string) $subject->id,
                source: 'scope_grant',
                correlationId: $correlationId,
                reason: $reason,
                result: 'DENIED',
            ),
            $failure,
        );

        throw $failure;
    }

    private function buildSupportGrantFailure(
        ErrorCode $code,
        string $message,
        User $actor,
        User $subject,
        ?string $correlationId,
    ): BusinessRuleViolation {
        // `BusinessRuleViolation`, NOT `DomainFailure`: the latter is abstract and
        // `new DomainFailure(...)` fatals, turning a security refusal into an
        // unhandled 500. See the class docblock.
        $failure = new BusinessRuleViolation($code, $message);

        $this->audit->recordDenial(
            AuditRecord::of(
                action: AuditAction::ScopeGranted,
                actorUserId: (string) $actor->id,
                actorRole: $actor->activeRoles()->first()?->value,
                propertyId: null,
                subjectType: 'user',
                subjectId: (string) $subject->id,
                source: 'scope_grant',
                correlationId: $correlationId,
                result: 'DENIED',
            ),
            $failure,
        );

        return $failure;
    }
}
