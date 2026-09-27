<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

/**
 * Which of the three orthogonal axes a transition acts on.
 */
enum RoomStatusAxis: string
{
    case Occupancy = 'occupancy';
    case Housekeeping = 'housekeeping';
    case Availability = 'availability';
}
