<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Shared\Domain\DomainModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenancy root (`D-001`). Exactly one row in v1.0.
 */
final class Organization extends DomainModel
{
    protected $table = 'organizations';

    protected $fillable = [
        'name',
        'code',
        'timezone',
        'default_currency',
    ];

    public function legalEntities(): HasMany
    {
        return $this->hasMany(LegalEntity::class, 'organization_id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'organization_id');
    }
}
