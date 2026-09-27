<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Contracts;

/**
 * An immutable view of a room's three status axes.
 *
 * Returned across a module boundary instead of an Eloquent model, so a consumer
 * can read a room's state without holding a handle on the row and without
 * importing the Rooms persistence layer (`ADR-0017` §2).
 */
final readonly class RoomSnapshot
{
    public function __construct(
        public string $id,
        public string $propertyId,
        public string $roomNumber,
        public string $occupancyStatus,
        public string $housekeepingStatus,
        public string $availabilityStatus,
    ) {
    }

    /**
     * `docs/STATE-MACHINES.md` §B.1: occupiable means VACANT, INSPECTED and
     * SELLABLE — all three, evaluated together.
     */
    public function isOccupiable(): bool
    {
        return $this->occupancyStatus === 'VACANT'
            && $this->housekeepingStatus === 'INSPECTED'
            && $this->availabilityStatus === 'SELLABLE';
    }

    public function isSellable(): bool
    {
        return $this->availabilityStatus === 'SELLABLE';
    }
}
