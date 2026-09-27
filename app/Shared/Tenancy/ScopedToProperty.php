<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Modules\Organization\Models\Property;
use App\Shared\Domain\DomainModel;
use App\Shared\Domain\DomainRuleViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies to every model that hangs off a `Property`.
 *
 * `ADR-0007` "Consequences": every repository method must accept `property_id`,
 * and `DATA-MODEL` §1.2 rule 3 states that "raw unfiltered queries on scoped
 * tables are prohibited" and that access "always resolves through a property".
 *
 * This trait is the reusable seam that makes that mechanical. A query on a
 * property-scoped model is only reachable through `forProperty()`, so a
 * forgotten `WHERE property_id` is a missing method call rather than a silent
 * cross-property read.
 *
 * @phpstan-require-extends DomainModel
 */
trait ScopedToProperty
{
    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * Constrain a query to one property. The property id is a required argument
     * and is never inferred from a request payload.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForProperty(Builder $query, string $propertyId): Builder
    {
        if ($propertyId === '') {
            throw DomainRuleViolation::validationFailed(
                field: 'property_id',
                message: 'A property scope is required; an unscoped query is not permitted.',
            );
        }

        return $query->where($this->getTable().'.property_id', $propertyId);
    }
}
