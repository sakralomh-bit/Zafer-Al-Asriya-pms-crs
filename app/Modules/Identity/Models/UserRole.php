<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Authorization\Role;
use App\Shared\Domain\DomainModel;
use App\Shared\Tenancy\ScopedToProperty;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role held IN A PROPERTY. A role is never global (`DATA-MODEL` §2.1), which
 * is why `property_id` is NOT nullable here.
 */
final class UserRole extends DomainModel
{
    use ScopedToProperty;

    protected $table = 'user_roles';

    protected $fillable = [
        'user_id',
        'role_id',
        'property_id',
        'granted_by_user_id',
        'correlation_id',
        'granted_at',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function roleRecord(): BelongsTo
    {
        return $this->belongsTo(RoleRecord::class, 'role_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function role(): ?Role
    {
        $record = $this->roleRecord;

        return $record === null ? null : Role::tryFrom($record->code);
    }
}
