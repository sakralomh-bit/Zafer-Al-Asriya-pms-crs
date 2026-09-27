<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Models;

use App\Modules\Rooms\Domain\AvailabilityStatus;
use App\Modules\Rooms\Domain\HousekeepingStatus;
use App\Modules\Rooms\Domain\OccupancyStatus;
use App\Modules\Rooms\Domain\RoomStatusContext;
use App\Shared\Domain\DomainModel;
use App\Shared\Tenancy\ScopedToProperty;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real room, with THREE INDEPENDENT status columns.
 *
 * `AC-T-005-01` and `DATA-MODEL` §2.7. There is deliberately no `status`
 * column; `tests/Architecture/RoomStatusAxesTest.php` asserts its absence.
 */
final class PhysicalRoom extends DomainModel
{
    use ScopedToProperty;

    protected $table = 'physical_rooms';

    protected $fillable = [
        'property_id',
        'room_type_id',
        'room_number',
        'floor',
        'occupancy_status',
        'housekeeping_status',
        'availability_status',
        'out_of_order_reason',
        'out_of_order_since',
        'out_of_order_by_user_id',
        'blocked_reason',
        'blocked_from',
        'blocked_until',
        'blocked_by_user_id',
    ];

    protected $casts = [
        'occupancy_status' => OccupancyStatus::class,
        'housekeeping_status' => HousekeepingStatus::class,
        'availability_status' => AvailabilityStatus::class,
        'out_of_order_since' => 'datetime',
        'blocked_from' => 'date',
        'blocked_until' => 'date',
        'lock_version' => 'integer',
    ];

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function context(bool $checkOutCompleted = false): RoomStatusContext
    {
        return new RoomStatusContext(
            $this->occupancy_status,
            $this->housekeeping_status,
            $this->availability_status,
            $checkOutCompleted,
        );
    }

    /**
     * `docs/STATE-MACHINES.md` §B.1, evaluated across all three axes.
     */
    public function isOccupiable(): bool
    {
        return $this->context()->isOccupiable();
    }

    /**
     * `AC-T-006-03`: marking a room OUT_OF_ORDER makes it non-sellable
     * immediately. Sellability is the availability axis, so this is a single
     * column read with no cross-axis reasoning.
     */
    public function isSellable(): bool
    {
        return $this->availability_status === AvailabilityStatus::Sellable;
    }
}
