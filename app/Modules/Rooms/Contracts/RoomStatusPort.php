<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Contracts;

use App\Modules\Rooms\Domain\AvailabilityStatus;
use App\Modules\Rooms\Domain\HousekeepingStatus;
use App\Modules\Rooms\Domain\OccupancyStatus;
use App\Shared\Domain\DomainFailure;

/**
 * The room status operations another module may perform.
 *
 * `ADR-0017` §1: "Cross-module access goes through an explicit contract
 * interface owned by the providing module." Housekeeping needs to move a room's
 * housekeeping axis and take a room out of order; this is the only surface on
 * which it may do so, and it lives in Rooms because Rooms owns the room.
 *
 * Why the contract takes identifiers rather than a model: passing a
 * `PhysicalRoom` from another module would put `Modules\Rooms\Models` into
 * Housekeeping's imports, which the boundary guard rejects and which is the
 * coupling `ADR-0017` exists to prevent. The id plus the property id is also
 * the safer signature — the property is re-read inside the transaction rather
 * than trusted from the caller's object graph.
 *
 * Implementations must keep the three axes independent: no method here writes
 * more than one status column.
 */
interface RoomStatusPort
{
    /**
     * Housekeeping axis: `DIRTY` -> `CLEAN` (§B.2 "Cleaning completed").
     *
     * @throws DomainFailure
     */
    public function markCleanById(
        string $roomId,
        string $propertyId,
        ?string $correlationId = null,
    ): void;

    /**
     * Housekeeping axis: `CLEAN` -> `INSPECTED` (§B.2 "Inspection passed").
     *
     * Updates ONLY `housekeeping_status`. Occupancy and availability are not
     * read for the write and are not modified (`AC-T-006-02`).
     *
     * @throws DomainFailure
     */
    public function markInspectedById(
        string $roomId,
        string $propertyId,
        ?string $correlationId = null,
    ): void;

    /**
     * Housekeeping axis: `INSPECTED` -> `DIRTY` (§B.2 "Re-inspection failed;
     * room not occupiable").
     *
     * @throws DomainFailure
     */
    public function failReinspectionById(
        string $roomId,
        string $propertyId,
        string $reason,
        ?string $correlationId = null,
    ): void;

    /**
     * Availability axis: `SELLABLE` -> `OUT_OF_ORDER` (§B.2).
     *
     * Makes the room non-sellable immediately and audits it with a reason
     * (`AC-T-006-03`).
     *
     * @throws DomainFailure
     */
    public function markOutOfOrderById(
        \App\Modules\Identity\Contracts\Actor $actor,
        string $roomId,
        string $propertyId,
        string $reason,
        ?string $correlationId = null,
    ): void;

    /**
     * Read-only projection of the three axes. A value object, not a model, so
     * a caller cannot mutate a room through it.
     */
    public function snapshot(string $roomId, string $propertyId): RoomSnapshot;
}
