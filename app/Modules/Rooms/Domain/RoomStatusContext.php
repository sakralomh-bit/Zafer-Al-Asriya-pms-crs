<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

/**
 * The room's three status axes at a point in time, plus whatever the calling
 * flow asserts about how it got there.
 *
 * The "how it got there" flags exist because two of the §B.3 rows are not
 * expressible as a (from, to) pair:
 *
 *  - "OCCUPIED -> VACANT **without check-out**" — the pair itself IS legal in
 *    §B.2, so the illegality is the missing check-out, not the pair.
 *  - "INSPECTED -> OCCUPIED **while housekeeping is not INSPECTED**" — the
 *    operative rule is the §B.1 occupiability precondition, which spans all
 *    three axes.
 */
final class RoomStatusContext
{
    public function __construct(
        public readonly OccupancyStatus $occupancy,
        public readonly HousekeepingStatus $housekeeping,
        public readonly AvailabilityStatus $availability,
        public readonly bool $checkOutCompleted = false,
    ) {
    }

    /**
     * `docs/STATE-MACHINES.md` §B.1: an occupiable room is one where occupancy
     * is VACANT, housekeeping is INSPECTED, and availability is SELLABLE — all
     * three, evaluated together. This is the precondition for check-in
     * (transition A-T8) and `AC-T-005-04` / `AC-FR-004-01` are both this method.
     */
    public function isOccupiable(): bool
    {
        return $this->occupancy === OccupancyStatus::Vacant
            && $this->housekeeping === HousekeepingStatus::Inspected
            && $this->availability === AvailabilityStatus::Sellable;
    }

    /**
     * Why the room is not occupiable, in the order the specification tests them.
     * A caller uses this to explain the refusal without inventing its own rule.
     *
     * @return list<RoomOccupiabilityFailure>
     */
    public function occupiabilityFailures(): array
    {
        $failures = [];

        if ($this->availability !== AvailabilityStatus::Sellable) {
            $failures[] = RoomOccupiabilityFailure::OutOfOrder;
        }

        if ($this->housekeeping !== HousekeepingStatus::Inspected) {
            $failures[] = RoomOccupiabilityFailure::HousekeepingNotInspected;
        }

        if ($this->occupancy !== OccupancyStatus::Vacant) {
            $failures[] = RoomOccupiabilityFailure::NotVacant;
        }

        return $failures;
    }
}
