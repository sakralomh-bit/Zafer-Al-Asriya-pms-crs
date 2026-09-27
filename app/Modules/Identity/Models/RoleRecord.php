<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Domain\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RoleRecord extends DomainModel
{
    protected $table = 'roles';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_deferred_phase',
        'deferred_phase',
    ];

    protected $casts = [
        'is_deferred_phase' => 'boolean',
        'lock_version' => 'integer',
    ];

    /**
     * `withTimestamps()` is required, not decorative: `role_permissions` is
     * written by `AuthorizationCatalogueSeeder` through `sync()`, and `sync()`
     * writes NO pivot timestamps unless the relation declares them. Without
     * this the insert fails outright on the NOT NULL `created_at` column and the
     * whole authorization catalogue is unseedable.
     *
     * @return BelongsToMany<PermissionRecord, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PermissionRecord::class, 'role_permissions', 'role_id', 'permission_id')
            ->withTimestamps();
    }

    /**
     * @return HasMany<UserRole, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRole::class, 'role_id');
    }
}
