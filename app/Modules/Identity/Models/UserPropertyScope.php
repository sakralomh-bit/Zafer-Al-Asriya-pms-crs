<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Shared\Domain\DomainModel;
use App\Shared\Tenancy\ScopedToProperty;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit GRANT of one user's access to one property.
 *
 * `ADR-0014` §2: "Access to a property is expressed as an explicit grant ...
 * Absence of a grant means no access."
 *
 * A revoked or expired grant is retained rather than deleted, because the audit
 * trail must be able to explain why a person could or could not see something
 * on a given date. `isActive()` is therefore the only thing that decides access,
 * and it checks BOTH `revoked_at` and `expires_at` — the latter is what makes a
 * Support grant time-bound.
 */
final class UserPropertyScope extends DomainModel
{
    use ScopedToProperty;

    protected $table = 'user_property_scope';

    protected $fillable = [
        'user_id',
        'property_id',
        'granted_by_user_id',
        'approved_by_user_id',
        'reason',
        'correlation_id',
        'granted_at',
        'expires_at',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
