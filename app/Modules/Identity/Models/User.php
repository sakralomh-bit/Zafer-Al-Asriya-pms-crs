<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RolePermissionMatrix;
use App\Modules\Identity\Contracts\Actor;
use App\Shared\Domain\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Staff identity.
 *
 * --------------------------------------------------------------------------------
 * NO `is_superadmin`, NO `is_admin`, NO `bypass_scope` COLUMN.
 *
 * `SEC-004` and `ADR-0014` §2 forbid it outright: a superuser flag is a
 * permanent, unauditable bypass of the scope model, it cannot be audited
 * meaningfully, and it makes the property-breakout test vacuous because the
 * superuser path would pass it by construction. Group Manager's access to all
 * ten properties is ten explicit rows in `user_property_scope`
 * (`AC-T-003-03`). A test asserts this column set is exactly what it is.
 * --------------------------------------------------------------------------------
 *
 * `password` exists but the hashing algorithm is `TBD` (`SEC-007`) and is NOT
 * chosen here — `T-004` owns that decision.
 */
final class User extends DomainModel implements Actor
{
    public const STATUS_INVITED = 'INVITED';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_DISABLED = 'DISABLED';

    protected $table = 'users';

    protected $fillable = [
        'email',
        'name',
        'password',
        'status',
        'locale',
        'email_verified_at',
        'mfa_enrolled_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'mfa_enrolled_at' => 'datetime',
        'last_login_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    /**
     * A property is a GRANT row, not a filter and not a flag.
     *
     * @return HasMany<UserPropertyScope, $this>
     */
    public function propertyScopes(): HasMany
    {
        return $this->hasMany(UserPropertyScope::class, 'user_id');
    }

    /**
     * A role assignment always names the property it applies in. Roles are
     * never global (`DATA-MODEL` §2.1).
     *
     * @return HasMany<UserRole, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class, 'user_id');
    }

    /**
     * The `roles` TABLE rows for this user.
     *
     * Named `roleRecords` rather than `roles` because `roles(): array` is the
     * `Actor` contract method and returns the resolved role ENUMS. Two methods
     * with the same name and different meanings is exactly the kind of
     * ambiguity that makes a model unreadable.
     *
     * @return BelongsToMany<RoleRecord, $this>
     */
    public function roleRecords(): BelongsToMany
    {
        return $this->belongsToMany(RoleRecord::class, 'user_roles', 'user_id', 'role_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function accountStatus(): string
    {
        return (string) $this->status;
    }

    /**
     * Every permission this user holds, aggregated across all of their
     * property-scoped role assignments.
     *
     * Note this is a PERMISSION set, not a SCOPE. A permission here says what
     * the user may do; whether they may do it to a given property is a separate
     * question answered by `PropertyScopeResolver`, and both must pass
     * (`ADR-0014` §1).
     *
     * @return Collection<int, Permission>
     */
    public function permissions(): Collection
    {
        $permissions = collect();

        foreach ($this->activeRoleAssignments() as $assignment) {
            $role = $assignment->roleRecord;

            if ($role === null) {
                continue;
            }

            $roleEnum = Role::tryFrom($role->code);

            if ($roleEnum === null) {
                continue;
            }

            foreach (RolePermissionMatrix::permissionsFor($roleEnum) as $permission) {
                $permissions->push($permission);
            }
        }

        return $permissions->unique(fn (Permission $p): string => $p->value)->values();
    }

    /**
     * @return Collection<int, UserRole>
     */
    public function activeRoleAssignments(): Collection
    {
        return $this->roleAssignments
            ->filter(static fn (UserRole $assignment): bool => $assignment->isActive())
            ->values();
    }

    /**
     * @return Collection<int, Role>
     */
    public function activeRoles(): Collection
    {
        return $this->activeRoleAssignments()
            ->map(static fn (UserRole $assignment): ?Role => $assignment->roleRecord === null
                ? null
                : Role::tryFrom($assignment->roleRecord->code))
            ->filter()
            ->unique(fn (Role $role): string => $role->value)
            ->values();
    }

    // -----------------------------------------------------------------------
    // Actor contract — the surface other modules are allowed to use.
    // -----------------------------------------------------------------------

    public function identifier(): string
    {
        return (string) $this->getKey();
    }

    /**
     * @return list<string>
     */
    public function grantedPropertyIds(): array
    {
        return array_values($this->propertyScopes()
            ->get()
            ->filter(static fn (UserPropertyScope $scope): bool => $scope->isActive())
            ->map(static fn (UserPropertyScope $scope): string => (string) $scope->property_id)
            ->unique()
            ->sort()
            ->values()
            ->all());
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return array_values($this->activeRoles()->all());
    }

    public function holds(Permission $permission): bool
    {
        return $this->permissions()->contains($permission);
    }
}
