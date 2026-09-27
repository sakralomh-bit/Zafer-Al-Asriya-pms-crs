<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

/**
 * Axis 3 of 3: availability. This is what makes a room non-sellable
 * independently of whether anyone is in it.
 */
enum AvailabilityStatus: string
{
    case Sellable = 'SELLABLE';
    case OutOfOrder = 'OUT_OF_ORDER';
    case Blocked = 'BLOCKED';
}
