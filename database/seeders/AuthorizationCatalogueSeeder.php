<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RolePermissionMatrix;
use App\Modules\Identity\Models\PermissionRecord;
use App\Modules\Identity\Models\RoleRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the fixed role and permission catalogue.
 *
 * `ADR-0014` rejected a general permission-per-action configuration engine for
 * v1.0 ("a general permission engine before the 12 named roles are proven
 * produces an untestable matrix"). The `roles` and `permissions` TABLES exist so
 * deny-by-default is stored as data, but the CONTENT is not editable: it comes
 * from `RolePermissionMatrix`, and there is no endpoint that changes it.
 *
 * Seeding is idempotent and re-asserts the matrix on every run, so a permission
 * added to the enum without a matching grant is corrected rather than silently
 * left absent.
 */
final class AuthorizationCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Role::cases() as $role) {
            // `id` is NOT listed among the update values. `HasUlids` generates
            // it on insert, and `updateOrCreate` applies the same array to both
            // paths — so including a fresh ULID here would attempt to REWRITE
            // the primary key of an existing role on every re-seed.
            RoleRecord::query()->updateOrCreate(
                ['code' => $role->value],
                [
                    'name' => $role->label(),
                    'description' => RolePermissionMatrix::citationFor($role),
                    'is_deferred_phase' => $role->isDeferredToLaterPhase(),
                    'deferred_phase' => $role->isDeferredToLaterPhase() ? 'C' : null,
                ],
            );
        }

        foreach (Permission::all() as $permission) {
            PermissionRecord::query()->updateOrCreate(
                ['code' => $permission->value],
                [
                    'id' => (string) Str::ulid(),
                    'resource' => $permission->resource(),
                    'action' => $permission->action(),
                    'description' => $permission->label(),
                ],
            );
        }

        $permissionIds = PermissionRecord::query()->pluck('id', 'code');

        foreach (Role::cases() as $role) {
            $roleRecord = RoleRecord::query()->where('code', $role->value)->firstOrFail();

            $granted = [];
            foreach (RolePermissionMatrix::permissionsFor($role) as $permission) {
                $granted[] = $permissionIds[$permission->value];
            }

            $roleRecord->permissions()->sync($granted);
        }
    }
}
