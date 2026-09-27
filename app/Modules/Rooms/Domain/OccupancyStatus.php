<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

/**
 * Axis 1 of 3: occupancy.
 *
 * `DATA-MODEL` §2.7 and `AC-T-005-01`: room status is three ORTHOGONAL axes,
 * not a single `status` column. A single column produces the contradiction
 * "occupied and clean" and cannot answer "can this room be sold right now".
 */
enum OccupancyStatus: string
{
    case Vacant = 'VACANT';
    case Occupied = 'OCCUPIED';
    case Reserved = 'RESERVED';
}
