<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Models;

use App\Shared\Domain\DomainModel;
use App\Shared\Tenancy\ScopedToProperty;

final class RoomType extends DomainModel
{
    use ScopedToProperty;

    protected $table = 'room_types';

    protected $fillable = [
        'property_id',
        'code',
        'name',
        'max_occupancy',
        'max_adults',
        'max_children',
        'max_infants',
        'bed_configuration',
        'is_active',
    ];

    protected $casts = [
        'bed_configuration' => 'array',
        'is_active' => 'boolean',
        'max_occupancy' => 'integer',
        'max_adults' => 'integer',
        'max_children' => 'integer',
        'max_infants' => 'integer',
        'lock_version' => 'integer',
    ];
}
