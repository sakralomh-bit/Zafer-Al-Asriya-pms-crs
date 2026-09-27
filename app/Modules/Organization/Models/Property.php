<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Shared\Domain\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hotel. The anchor of every property-scoped table (`D-001`, `DR-001`).
 *
 * NOTE: `Property` does NOT use `ScopedToProperty`. It is the root of the
 * property hierarchy, not a member of it — it has no `property_id` column, and
 * `ADR-0007` rule 4 resolves it through `organization_id` instead.
 */
final class Property extends DomainModel
{
    protected $table = 'properties';

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'timezone',
        'currency',
        'tax_rate_id',
        'address',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'lock_version' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    public function operatingConfigs(): HasMany
    {
        return $this->hasMany(PropertyOperatingConfig::class, 'property_id');
    }

    public function configurationVersions(): HasMany
    {
        return $this->hasMany(ConfigurationVersion::class, 'property_id');
    }

    /**
     * `ADR-0017` §2: there is deliberately NO `roomTypes()` or `rooms()`
     * relation here. Reaching into `Modules\Rooms\Models` from the Organization
     * module would be the boundary violation `zafer:guard-modules` exists to
     * reject, and the relationship would be bidirectional by convenience rather
     * than by contract.
     */
}
