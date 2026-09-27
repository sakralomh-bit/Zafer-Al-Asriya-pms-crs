<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\RoleRecord;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserPropertyScope;
use App\Modules\Identity\Models\UserRole;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\Property;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared, SYNTHETIC fixture builders.
 *
 * `docs/TEST-STRATEGY.md` §10 requires synthetic data only in every
 * non-production environment. Every value here is invented and obviously so:
 * `.test` email domains (RFC 2606 reserved), `Synthetic ...` names, and
 * non-secret placeholder strings. No real hotel, guest, staff member, card, or
 * credential appears in any test.
 *
 * The distinction between a ROLE ASSIGNMENT and a PROPERTY GRANT is the single
 * most important thing these builders encode, and it is easy to get wrong:
 *
 *   `user_roles`           — "this person is a Housekeeping ATTENDANT here"
 *   `user_property_scope`  — "this person may REACH this property at all"
 *
 * They are independent. `makeUser()` creates the first; only `grantProperty()`
 * creates the second. A user created but never granted has roles and no access,
 * which is what makes the property-breakout tests meaningful.
 */
trait CreatesTestFixtures
{
    /**
     * Re-read a row a test has just written.
     *
     * `Model::fresh()` is nullable because in production a row can genuinely be
     * gone. In a test that just wrote it, a `null` means the assertion is about
     * to dereference nothing and report a property error instead of the real
     * cause, so it fails here with the row's class and key.
     *
     * @template TModel of Model
     *
     * @param  TModel  $model
     * @return TModel
     */
    protected function reread(Model $model): Model
    {
        $fresh = $model->fresh();

        if ($fresh === null) {
            self::fail(sprintf(
                'Expected to re-read %s [%s] after writing it, but the row is gone.',
                $model::class,
                (string) $model->getKey(),
            ));
        }

        return $fresh;
    }

    protected function makeOrganization(?string $suffix = null): Organization
    {
        $suffix ??= substr(md5((string) static::class.uniqid('', true)), 0, 8);

        return Organization::query()->create([
            'name' => 'Synthetic Group '.$suffix,
            'code' => 'ORG'.strtoupper($suffix),
            'timezone' => 'Asia/Riyadh',
            'default_currency' => 'SAR',
        ]);
    }

    protected function makeProperty(
        string $name,
        ?Organization $organization = null,
        string $currency = 'SAR',
    ): Property {
        $organization ??= $this->makeOrganization();

        return Property::query()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'code' => 'P'.strtoupper(substr(md5($name.uniqid('', true)), 0, 8)),
            'timezone' => 'Asia/Riyadh',
            'currency' => $currency,
            'is_active' => true,
        ]);
    }

    protected function makeUser(
        string $email,
        Role $role,
        string $status = User::STATUS_ACTIVE,
    ): User {
        $user = User::query()->create([
            'email' => $email,
            'name' => 'Test '.strtoupper(explode('@', $email)[0]),
            // Not a credential. The hashing algorithm is TBD under SEC-007 and is
            // owned by T-004, so this is a placeholder that cannot authenticate
            // anyone and is not a hash of anything.
            'password' => 'not-a-real-password',
            'status' => $status,
            'locale' => 'en',
        ]);

        $roleRecord = RoleRecord::query()->where('code', $role->value)->firstOrFail();

        // A role is NEVER global: `user_roles.property_id` is a NOT NULL foreign
        // key to `properties` (DATA-MODEL §2.1), so the user needs a property to
        // hold the role IN. This is a role assignment, NOT a scope grant.
        $roleProperty = $this->makeProperty('Role Anchor for '.$email);

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $roleRecord->id,
            'property_id' => $roleProperty->id,
            'granted_by_user_id' => $user->id,
            'granted_at' => now(),
        ]);

        return User::query()->findOrFail($user->id);
    }

    protected function grantProperty(User $user, Property $property): UserPropertyScope
    {
        return UserPropertyScope::query()->create([
            'user_id' => $user->id,
            'property_id' => $property->id,
            'granted_by_user_id' => $user->id,
            'approved_by_user_id' => $user->id,
            'granted_at' => now(),
            'reason' => 'Synthetic test grant.',
        ]);
    }
}
