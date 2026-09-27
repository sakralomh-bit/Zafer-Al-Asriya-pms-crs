<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

use App\Shared\Domain\ErrorCode;

enum RoomOccupiabilityFailure: string
{
    case OutOfOrder = 'ROOM_NOT_SELLABLE';
    case HousekeepingNotInspected = 'ROOM_NOT_OCCUPIABLE';
    case NotVacant = 'ROOM_NOT_OCCUPIABLE';

    public function errorCode(): ErrorCode
    {
        return match ($this) {
            self::OutOfOrder => ErrorCode::RoomNotSellable,
            self::HousekeepingNotInspected, self::NotVacant => ErrorCode::RoomNotOccupiable,
        };
    }
}
