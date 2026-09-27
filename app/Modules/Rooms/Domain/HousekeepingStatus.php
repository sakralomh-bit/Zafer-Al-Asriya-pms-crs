<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

/**
 * Axis 2 of 3: housekeeping. Evaluated entirely independently of occupancy.
 */
enum HousekeepingStatus: string
{
    case Clean = 'CLEAN';
    case Dirty = 'DIRTY';
    case Inspected = 'INSPECTED';
    case InProgress = 'IN_PROGRESS';
}
