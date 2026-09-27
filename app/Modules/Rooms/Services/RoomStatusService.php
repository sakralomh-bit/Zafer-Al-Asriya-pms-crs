<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Services;

use App\Modules\Identity\Contracts\Actor;
use App\Modules\Identity\Contracts\AuthorizesRequests;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Rooms\Contracts\RoomSnapshot;
use App\Modules\Rooms\Contracts\RoomStatusPort;
use App\Modules\Rooms\Domain\AvailabilityStatus;
use App\Modules\Rooms\Domain\HousekeepingStatus;
use App\Modules\Rooms\Domain\OccupancyStatus;
use App\Modules\Rooms\Domain\RoomStatusAxis;
use App\Modules\Rooms\Domain\RoomStatusMachine;
use App\Modules\Rooms\Domain\RoomStatusTransition;
use App\Modules\Rooms\Models\PhysicalRoom;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecord;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Domain\ValidationFailed;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/**
 * Applies the `docs/STATE-MACHINES.md` §B room machine to storage.
 *
 * Three properties this service is built to guarantee:
 *
 *  1. **One axis per write.** `AC-T-006-02` requires that marking a room
 *     INSPECTED "updates ONLY the housekeeping axis and does not alter
 *     occupancy or availability". Every method below writes exactly one status
 *     column and touches nothing else, so axis independence is a property of the
 *     code shape rather than a thing to remember.
 *  2. **Denied, never filtered.** `AC-T-005-05`: a status change outside the
 *     caller's property scope is DENIED with `PROPERTY_SCOPE_DENIED`. The
 *     authorization check runs before the room is even read inside the
 *     transaction, so a caller learns nothing about a room they cannot reach.
 *  3. **Audited.** `AC-T-005-06`: every status change is audited, inside the
 *     same transaction as the change.
 *
 * Concurrency: the row is locked `FOR UPDATE` for the duration of a short
 * transaction and the transition is re-validated against the LOCKED state, not
 * against a value read earlier. Two concurrent status changes therefore cannot
 * both succeed from the same starting state. `ADR-0008` notes that a row lock
 * alone is not a complete guarantee for inventory; that caution applies to
 * allocation, and the concurrency gate for it is `T-007` (`CON-01`), which is
 * out of scope here.
 */
final class RoomStatusService implements RoomStatusPort
{
    public function __construct(
        private readonly RoomStatusMachine $machine,
        private readonly AuthorizesRequests $authorization,
        private readonly AuditRecorder $audit,
    ) {
    }

    // -----------------------------------------------------------------------
    // Occupancy axis
    // -----------------------------------------------------------------------

    /**
     * §B.2: `VACANT` -> `RESERVED`. Actor: System (a room selected for an
     * allocation). Callers outside the allocation flow have no route to this.
     */
    public function markReserved(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Occupancy,
            OccupancyStatus::Reserved,
            correlationId: $correlationId,
        );
    }

    /**
     * §B.2: `RESERVED` -> `OCCUPIED`. Actor: Front Desk.
     *
     * The §B.1 occupiability precondition (all three axes) is enforced inside
     * `assertAllowed`, which is what makes `AC-FR-004-01` — a DIRTY room cannot
     * be checked into — hold here.
     */
    public function markOccupied(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Occupancy,
            OccupancyStatus::Occupied,
            correlationId: $correlationId,
        );
    }

    /**
     * §B.2: `OCCUPIED` -> `VACANT`. Actor: Front Desk / Housekeeping.
     *
     * §B.3 refuses this with `ROOM_OCCUPIED` unless the caller states the
     * check-out has completed. That is the whole point of the flag: a room does
     * not become vacant because someone edited a column.
     */
    public function markVacantAfterCheckOut(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Occupancy,
            OccupancyStatus::Vacant,
            checkOutCompleted: true,
            correlationId: $correlationId,
        );
    }

    /**
     * §B.2: `OCCUPIED` -> `VACANT` with NO check-out. §B.3 row 1 refuses it
     * with `ROOM_OCCUPIED`. Exposed as its own named method so the illegal
     * action is visible at the call site instead of being reachable by omitting
     * a boolean.
     *
     * @throws DomainFailure ROOM_OCCUPIED
     */
    public function markVacantWithoutCheckOut(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Occupancy,
            OccupancyStatus::Vacant,
            checkOutCompleted: false,
            correlationId: $correlationId,
        );
    }

    /**
     * §B.2: `RESERVED` -> `VACANT`. Actor: System (allocation released, hold
     * expired, or reservation cancelled).
     */
    public function markReleased(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Occupancy,
            OccupancyStatus::Vacant,
            correlationId: $correlationId,
        );
    }

    // -----------------------------------------------------------------------
    // Housekeeping axis — writes ONLY housekeeping_status
    // -----------------------------------------------------------------------

    /**
     * §B.2: `CLEAN` -> `IN_PROGRESS`. Precondition: task assigned.
     */
    public function startHousekeeping(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Housekeeping,
            HousekeepingStatus::InProgress,
            correlationId: $correlationId,
        );
    }

    /**
     * §B.2: `IN_PROGRESS` -> `DIRTY`.
     */
    public function markDirty(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Housekeeping,
            HousekeepingStatus::Dirty,
            correlationId: $correlationId,
        );
    }

    /**
     * §B.2: `DIRTY` -> `CLEAN`. Precondition: cleaning completed.
     */
    public function markClean(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Housekeeping,
            HousekeepingStatus::Clean,
            correlationId: $correlationId,
        );
    }

    /**
     * §B.2: `CLEAN` -> `INSPECTED`. Precondition: inspection passed.
     *
     * `AC-T-006-02`: this writes `housekeeping_status` and nothing else.
     */
    public function markInspected(PhysicalRoom $room, ?string $correlationId = null): PhysicalRoom
    {
        return $this->transition(
            $room,
            RoomStatusAxis::Housekeeping,
            HousekeepingStatus::Inspected,
            correlationId: $correlationId,
        );
    }

    /**
     * §B.2: `INSPECTED` -> `DIRTY`. Precondition: re-inspection failed, room not
     * occupiable. Audit event `HOUSEKEEPING_REINSPECTION_FAILED`.
     *
     * @throws DomainFailure when a reason is not supplied
     */
    public function failReinspection(
        PhysicalRoom $room,
        string $reason,
        ?string $correlationId = null,
    ): PhysicalRoom {
        if (trim($reason) === '') {
            throw ValidationFailed::field('reason', 'A failed re-inspection must record why it failed.');
        }

        return $this->transition(
            $room,
            RoomStatusAxis::Housekeeping,
            HousekeepingStatus::Dirty,
            correlationId: $correlationId,
            reason: $reason,
        );
    }

    // -----------------------------------------------------------------------
    // Availability axis — writes ONLY availability_status
    // -----------------------------------------------------------------------

    /**
     * §B.2: `SELLABLE` -> `OUT_OF_ORDER`. Actor: Housekeeping / Maintenance.
     * Precondition: reason recorded. Audit event `ROOM_OUT_OF_ORDER`.
     *
     * `AC-T-006-03`: the change is non-sellable immediately (it is the same
     * write) and is audited with a reason.
     *
     * The database CHECK `physical_rooms_out_of_order_requires_reason` makes the
     * reason mandatory at the storage layer as well.
     */
    public function markOutOfOrder(
        PhysicalRoom $room,
        Actor $actor,
        string $reason,
        ?string $correlationId = null,
    ): PhysicalRoom {
        if (trim($reason) === '') {
            throw ValidationFailed::field('reason', 'Taking a room out of order must record a reason.');
        }

        $this->authorization->authorize(
            $actor,
            Permission::RoomAvailabilityTransition,
            (string) $room->property_id,
            subjectType: 'physical_room',
            subjectId: (string) $room->id,
            correlationId: $correlationId,
        );

        return $this->transition(
            $room,
            RoomStatusAxis::Availability,
            AvailabilityStatus::OutOfOrder,
            correlationId: $correlationId,
            extraAttributes: [
                'out_of_order_reason' => $reason,
                'out_of_order_since' => now(),
                'out_of_order_by_user_id' => $actor->identifier(),
            ],
            reason: $reason,
        );
    }

    /**
     * §B.2: `OUT_OF_ORDER` -> `SELLABLE`. Actor: Housekeeping.
     * Precondition: maintenance cleared; housekeeping re-verified.
     */
    public function returnToService(
        PhysicalRoom $room,
        Actor $actor,
        ?string $reason,
        ?string $correlationId = null,
    ): PhysicalRoom {
        $this->authorization->authorize(
            $actor,
            Permission::RoomAvailabilityTransition,
            (string) $room->property_id,
            subjectType: 'physical_room',
            subjectId: (string) $room->id,
            correlationId: $correlationId,
        );

        return $this->transition(
            $room,
            RoomStatusAxis::Availability,
            AvailabilityStatus::Sellable,
            correlationId: $correlationId,
            extraAttributes: [
                'out_of_order_reason' => null,
                'out_of_order_since' => null,
                'out_of_order_by_user_id' => null,
            ],
            reason: $reason,
        );
    }

    /**
     * §B.2: `SELLABLE` -> `BLOCKED`. Actor: Group Manager / Hotel Manager.
     * Precondition: reason and duration recorded.
     */
    public function block(
        PhysicalRoom $room,
        Actor $actor,
        string $reason,
        string $from,
        ?string $until,
        ?string $correlationId = null,
    ): PhysicalRoom {
        if (trim($reason) === '') {
            throw ValidationFailed::field('reason', 'Blocking a room must record a reason.');
        }

        $this->authorization->authorize(
            $actor,
            Permission::RoomBlockTransition,
            (string) $room->property_id,
            subjectType: 'physical_room',
            subjectId: (string) $room->id,
            correlationId: $correlationId,
        );

        return $this->transition(
            $room,
            RoomStatusAxis::Availability,
            AvailabilityStatus::Blocked,
            correlationId: $correlationId,
            extraAttributes: [
                'blocked_reason' => $reason,
                'blocked_from' => $from,
                'blocked_until' => $until,
                'blocked_by_user_id' => $actor->identifier(),
            ],
            reason: $reason,
        );
    }

    /**
     * §B.2: `BLOCKED` -> `SELLABLE`. Actor: Group Manager / Hotel Manager.
     * Precondition: block period ended.
     */
    public function unblock(
        PhysicalRoom $room,
        Actor $actor,
        ?string $reason,
        ?string $correlationId = null,
    ): PhysicalRoom {
        $this->authorization->authorize(
            $actor,
            Permission::RoomBlockTransition,
            (string) $room->property_id,
            subjectType: 'physical_room',
            subjectId: (string) $room->id,
            correlationId: $correlationId,
        );

        return $this->transition(
            $room,
            RoomStatusAxis::Availability,
            AvailabilityStatus::Sellable,
            correlationId: $correlationId,
            extraAttributes: [
                'blocked_reason' => null,
                'blocked_from' => null,
                'blocked_until' => null,
                'blocked_by_user_id' => null,
            ],
            reason: $reason,
        );
    }

    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // RoomStatusPort — the cross-module surface (ADR-0017 §1)
    // -----------------------------------------------------------------------

    public function markCleanById(
        string $roomId,
        string $propertyId,
        ?string $correlationId = null,
    ): void {
        $this->markClean($this->loadScoped($roomId, $propertyId), $correlationId);
    }

    public function markInspectedById(
        string $roomId,
        string $propertyId,
        ?string $correlationId = null,
    ): void {
        $this->markInspected($this->loadScoped($roomId, $propertyId), $correlationId);
    }

    public function failReinspectionById(
        string $roomId,
        string $propertyId,
        string $reason,
        ?string $correlationId = null,
    ): void {
        $this->failReinspection($this->loadScoped($roomId, $propertyId), $reason, $correlationId);
    }

    public function markOutOfOrderById(
        Actor $actor,
        string $roomId,
        string $propertyId,
        string $reason,
        ?string $correlationId = null,
    ): void {
        $this->markOutOfOrder($this->loadScoped($roomId, $propertyId), $actor, $reason, $correlationId);
    }

    public function snapshot(string $roomId, string $propertyId): RoomSnapshot
    {
        $room = $this->loadScoped($roomId, $propertyId);

        return new RoomSnapshot(
            id: (string) $room->id,
            propertyId: (string) $room->property_id,
            roomNumber: (string) $room->room_number,
            occupancyStatus: $room->occupancy_status->value,
            housekeepingStatus: $room->housekeeping_status->value,
            availabilityStatus: $room->availability_status->value,
        );
    }

    /**
     * Load a room INSIDE the caller's declared property scope. A room id that
     * does not belong to the stated property is not found — the scope is part
     * of the lookup, not a filter applied afterwards.
     */
    private function loadScoped(string $roomId, string $propertyId): PhysicalRoom
    {
        return PhysicalRoom::query()
            ->forProperty($propertyId)
            ->whereKey($roomId)
            ->firstOrFail();
    }

    /**
     * The single write path. Every axis method routes through here, which is
     * what makes "one axis per write" a structural property rather than a
     * convention.
     *
     * @param OccupancyStatus|HousekeepingStatus|AvailabilityStatus|string $to
     * @param array<string, mixed> $extraAttributes
     * @param bool $checkOutCompleted whether the check-out flow has actually
     *        finished. This is what separates the LEGAL `OCCUPIED -> VACANT`
     *        (§B.2) from the ILLEGAL one (§B.3 row 1), and the pair itself is
     *        identical in both cases — so the distinction has to arrive as a
     *        fact from the caller, not be derived from the room.
     */
    private function transition(
        PhysicalRoom $room,
        RoomStatusAxis $axis,
        OccupancyStatus|HousekeepingStatus|AvailabilityStatus|string $to,
        array $extraAttributes = [],
        ?string $reason = null,
        ?string $correlationId = null,
        bool $checkOutCompleted = false,
    ): PhysicalRoom {
        // The machine and the database column both work in the enum's string
        // value, but the public methods pass the ENUM. That direction is
        // deliberate: an enum case cannot be misspelled, and `'RESERVD'` is a
        // string that reaches the machine, matches no transition, and surfaces as
        // ROOM_STATE_INVALID on a typo rather than at the call site. Under
        // `declare(strict_types=1)` a bare `string $to` against a backed enum is
        // a TypeError, which is how this surfaced — every public method in the
        // class was calling with an enum and fataling.
        $to = $to instanceof BackedEnum ? $to->value : $to;

        return DB::transaction(function () use ($room, $axis, $to, $extraAttributes, $reason, $correlationId, $checkOutCompleted): PhysicalRoom {
            /** @var PhysicalRoom $locked */
            $locked = PhysicalRoom::query()
                ->whereKey($room->id)
                ->lockForUpdate()
                ->firstOrFail();

            // The context is rebuilt from the LOCKED row, never from the
            // caller's snapshot, so a concurrent change cannot be validated
            // against a stale starting state.
            $beforeContext = $locked->context($checkOutCompleted);
            $beforeValue = $this->currentValue($locked, $axis);

            try {
                $transition = $this->machine->assertAllowed($axis, $beforeValue, $to, $beforeContext);
            } catch (DomainFailure $refused) {
                // `ADR-0016`: the audit trail records "attempts, denials, and
                // configuration changes". A refused transition is a denial, so it
                // is recorded BEFORE the exception unwinds — otherwise the
                // rollback of the surrounding transaction erases the only trace
                // that someone tried. `recordDenial` also re-writes the row from
                // an afterRollback hook for exactly this reason.
                $this->audit->recordDenial(
                    AuditRecord::of(
                        action: AuditAction::RoomStatusTransitionDenied,
                        actorUserId: $extraAttributes['out_of_order_by_user_id']
                            ?? $extraAttributes['blocked_by_user_id']
                            ?? null,
                        actorRole: null,
                        propertyId: (string) $locked->property_id,
                        subjectType: 'physical_room',
                        subjectId: (string) $locked->id,
                        source: 'room_status',
                        correlationId: $correlationId,
                        reason: $reason,
                        before: [$axis->value => $beforeValue],
                        after: [$axis->value => $to],
                        additionalContext: [
                            'room_number' => $locked->room_number,
                            'attempted_transition' => $beforeValue . '->' . $to,
                            'denied_code' => $refused->errorCode->value,
                        ],
                    ),
                    $refused,
                );

                throw $refused;
            }

            $locked->forceFill([$this->columnFor($axis) => $to]);
            $locked->forceFill($extraAttributes);
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(AuditRecord::of(
                action: $transition->auditAction,
                actorUserId: $extraAttributes['out_of_order_by_user_id']
                    ?? $extraAttributes['blocked_by_user_id']
                    ?? null,
                actorRole: null,
                propertyId: (string) $locked->property_id,
                subjectType: 'physical_room',
                subjectId: (string) $locked->id,
                source: 'room_status',
                correlationId: $correlationId,
                reason: $reason,
                before: [$axis->value => $beforeValue],
                after: [$axis->value => $to],
                additionalContext: [
                    'room_number' => $locked->room_number,
                    'transition' => $transition->from . '->' . $transition->to,
                    'actor' => 'room_status_service',
                ],
            ));

            $room->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Read one axis. `RoomStatusContext` deliberately exposes no setters, so the
     * value is read from the model rather than from a mutable snapshot.
     */
    private function currentValue(PhysicalRoom $room, RoomStatusAxis $axis): string
    {
        return match ($axis) {
            RoomStatusAxis::Occupancy => $room->occupancy_status->value,
            RoomStatusAxis::Housekeeping => $room->housekeeping_status->value,
            RoomStatusAxis::Availability => $room->availability_status->value,
        };
    }

    /**
     * The single column an axis writes. Returning a string rather than a list
     * is what makes "one axis, one column" impossible to violate by accident:
     * there is no way to pass a second column name to `transition()`.
     */
    private function columnFor(RoomStatusAxis $axis): string
    {
        return match ($axis) {
            RoomStatusAxis::Occupancy => 'occupancy_status',
            RoomStatusAxis::Housekeeping => 'housekeeping_status',
            RoomStatusAxis::Availability => 'availability_status',
        };
    }
}
