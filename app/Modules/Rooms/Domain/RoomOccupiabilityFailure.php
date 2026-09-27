<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

use App\Shared\Domain\ErrorCode;

/**
 * Why a room is not occupiable.
 *
 * `docs/STATE-MACHINES.md` §B.1 defines occupiable as occupancy `VACANT` **and**
 * housekeeping `INSPECTED` **and** availability `SELLABLE`, so there are three
 * distinct ways to fail and an operator needs to be told which one occurred.
 *
 * This enum is deliberately NOT string-backed. `docs/STATE-MACHINES.md` gives
 * those three failures only TWO outward error codes — `ROOM_NOT_SELLABLE` for an
 * out-of-order room and `ROOM_NOT_OCCUPIABLE` for the other two (lines 94, 166,
 * 228) — and a backed enum cannot hold two cases with one value. It reads
 * correctly until someone calls `from('ROOM_NOT_OCCUPIABLE')`, at which point
 * which of the two reasons they had is unknowable, and `HousekeepingNotInspected`
 * and `NotVacant` become the same case. The cases stay distinct here and the
 * documented code is produced by `errorCode()`, which is the only thing the API
 * is allowed to see.
 */
enum RoomOccupiabilityFailure
{
    case OutOfOrder;
    case HousekeepingNotInspected;
    case NotVacant;

    public function errorCode(): ErrorCode
    {
        return match ($this) {
            self::OutOfOrder => ErrorCode::RoomNotSellable,
            self::HousekeepingNotInspected, self::NotVacant => ErrorCode::RoomNotOccupiable,
        };
    }
}
