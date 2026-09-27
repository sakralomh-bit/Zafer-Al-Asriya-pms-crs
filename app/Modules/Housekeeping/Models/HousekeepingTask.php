<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Models;

use App\Modules\Housekeeping\Domain\HousekeepingTaskStatus;
use App\Shared\Domain\DomainModel;
use App\Shared\Tenancy\ScopedToProperty;

/**
 * A housekeeping task for ONE room.
 *
 * `AC-T-006-04`: "Housekeeping CANNOT read guest identity data, folio data, or
 * financial data." The strongest available form of that control is that this
 * table has no column holding any of it, so no policy, filter, or forgotten
 * `->hidden` can leak it. The permission matrix independently withholds
 * `guest.read`, `guest_identity.read`, `folio.*`, and `posting.*` from the
 * HOUSEKEEPING role.
 *
 * The table also carries no amount, no rate, and no currency. `C-04` blocks
 * monetary columns and housekeeping has no financial permission regardless.
 */
final class HousekeepingTask extends DomainModel
{
    use ScopedToProperty;

    protected $table = 'housekeeping_tasks';

    protected $fillable = [
        'property_id',
        'room_id',
        'status',
        'task_type',
        'assigned_to_user_id',
        'created_by_user_id',
        'inspected_by_user_id',
        'assigned_at',
        'started_at',
        'completed_at',
        'inspected_at',
        'cancelled_at',
        'rework_reason',
        'cancel_reason',
        'reason',
        'correlation_id',
    ];

    protected $casts = [
        'status' => HousekeepingTaskStatus::class,
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'inspected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    /**
     * `ADR-0017` §2: this module does not reach into `Modules\Rooms\Models` for
     * a relation. The room is read through `Rooms\Contracts\RoomStatusPort`.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
