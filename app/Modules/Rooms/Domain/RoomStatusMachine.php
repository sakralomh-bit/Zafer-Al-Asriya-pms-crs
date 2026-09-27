<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

use App\Shared\Audit\AuditAction;
use App\Shared\Domain\BusinessRuleViolation;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * The `docs/STATE-MACHINES.md` §B room state machine, encoded.
 *
 * §B.2 supplies thirteen legal transitions across the three axes. §B.3 supplies
 * six refusals, each with a specific error code. Rule G-1 of the specification:
 * "No transition may be implied or enforced **only** by UI behaviour. Every
 * transition is enforced server-side." Rule G-2: an invalid transition MUST
 * return a deterministic, machine-readable error code — never a generic
 * validation error, never a partial success.
 *
 * This class is pure: it decides, it does not write. `RoomStatusService` applies
 * the decision, which keeps the whole matrix testable without a database and
 * makes "the machine said no" independent of "the write failed".
 */
final class RoomStatusMachine
{
    /**
     * §B.2, row by row.
     *
     * Built by a method rather than a class constant because PHP does not permit
     * `new` in a constant initializer, and hiding the table behind a constant
     * that silently fell back to `[]` on an older PHP would be a guard that
     * passes while checking nothing.
     *
     * @var list<RoomStatusTransition>|null
     */
    private static ?array $transitions = null;

    /**
     * @return list<RoomStatusTransition>
     */
    public static function transitions(): array
    {
        if (self::$transitions !== null) {
            return self::$transitions;
        }

        self::$transitions = [
            // ---- Occupancy axis --------------------------------------------
            new RoomStatusTransition(
                RoomStatusAxis::Occupancy,
                'VACANT', 'RESERVED',
                RoomTransitionActor::System,
                AuditAction::RoomReserved,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Occupancy,
                'RESERVED', 'OCCUPIED',
                RoomTransitionActor::FrontDesk,
                AuditAction::RoomOccupied,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Occupancy,
                'OCCUPIED', 'VACANT',
                RoomTransitionActor::FrontDesk,
                AuditAction::RoomVacant,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Occupancy,
                'RESERVED', 'VACANT',
                RoomTransitionActor::System,
                AuditAction::RoomReleased,
            ),

            // ---- Housekeeping axis ----------------------------------------
            new RoomStatusTransition(
                RoomStatusAxis::Housekeeping,
                'CLEAN', 'IN_PROGRESS',
                RoomTransitionActor::Housekeeping,
                AuditAction::HousekeepingStarted,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Housekeeping,
                'IN_PROGRESS', 'DIRTY',
                RoomTransitionActor::Housekeeping,
                AuditAction::HousekeepingDirty,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Housekeeping,
                'DIRTY', 'CLEAN',
                RoomTransitionActor::Housekeeping,
                AuditAction::HousekeepingClean,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Housekeeping,
                'CLEAN', 'INSPECTED',
                RoomTransitionActor::Inspector,
                AuditAction::HousekeepingInspected,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Housekeeping,
                'INSPECTED', 'DIRTY',
                RoomTransitionActor::Inspector,
                AuditAction::HousekeepingReinspectionFailed,
            ),

            // ---- Availability axis ----------------------------------------
            new RoomStatusTransition(
                RoomStatusAxis::Availability,
                'SELLABLE', 'OUT_OF_ORDER',
                RoomTransitionActor::Housekeeping,
                AuditAction::RoomOutOfOrder,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Availability,
                'OUT_OF_ORDER', 'SELLABLE',
                RoomTransitionActor::Housekeeping,
                AuditAction::RoomReturnedToService,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Availability,
                'SELLABLE', 'BLOCKED',
                RoomTransitionActor::Manager,
                AuditAction::RoomBlocked,
            ),
            new RoomStatusTransition(
                RoomStatusAxis::Availability,
                'BLOCKED', 'SELLABLE',
                RoomTransitionActor::Manager,
                AuditAction::RoomUnblocked,
            ),
        ];

        return self::$transitions;
    }

    /**
     * @return list<RoomStatusTransition>
     */
    public static function transitionsFor(RoomStatusAxis $axis): array
    {
        return array_values(array_filter(
            self::transitions(),
            static fn (RoomStatusTransition $t): bool => $t->axis === $axis,
        ));
    }

    public function isAllowed(RoomStatusAxis $axis, string $from, string $to): bool
    {
        foreach (self::transitions() as $transition) {
            if ($transition->matches($axis, $from, $to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws DomainFailure with a §B.3 code when the transition is refused
     */
    public function assertAllowed(
        RoomStatusAxis $axis,
        string $from,
        string $to,
        RoomStatusContext $context,
    ): RoomStatusTransition {
        $transition = $this->find($axis, $from, $to);

        if ($transition === null) {
            // §B.3 row 5 — "DIRTY -> INSPECTED skipping cleaning" — and row 2,
            // "VACANT -> OCCUPIED without a check-in transition", are both
            // simply pairs that are not in §B.2. One deterministic code covers
            // "this pair is not a transition".
            throw new BusinessRuleViolation(
                ErrorCode::RoomStateInvalid,
                sprintf('A room cannot move from %s to %s on the %s axis.', $from, $to, $axis->value),
            );
        }

        $this->assertPreconditions($axis, $to, $context, $transition);

        return $transition;
    }

    /**
     * The three §B.3 rows that are preconditions rather than pairs.
     */
    private function assertPreconditions(
        RoomStatusAxis $axis,
        string $to,
        RoomStatusContext $context,
        RoomStatusTransition $transition,
    ): void {
        // §B.3 row 4: "Any occupancy change on an OUT_OF_ORDER room ->
        // ROOM_OUT_OF_ORDER". A room under maintenance cannot change occupancy
        // at all, in either direction, including being released.
        if ($axis === RoomStatusAxis::Occupancy && $context->availability === AvailabilityStatus::OutOfOrder) {
            throw new BusinessRuleViolation(
                ErrorCode::RoomOutOfOrder,
                'The room is out of order; its occupancy cannot be changed.',
            );
        }

        // §B.3 row 1: "OCCUPIED -> VACANT without check-out -> ROOM_OCCUPIED".
        // The pair is legal in §B.2; vacating a room that has not been checked
        // out of is not.
        if (
            $axis === RoomStatusAxis::Occupancy
            && $transition->to === OccupancyStatus::Vacant->value
            && $transition->from === OccupancyStatus::Occupied->value
            && ! $context->checkOutCompleted
        ) {
            throw new BusinessRuleViolation(
                ErrorCode::RoomOccupied,
                'The room is still occupied; it must be checked out before it can be vacated.',
            );
        }

        // §B.3 row 3 and §B.1: entering OCCUPIED requires the room to be
        // occupiable on all three axes. This is the `AC-FR-004-01` /
        // `AC-T-005-04` rule — a DIRTY room cannot be checked into.
        if ($axis === RoomStatusAxis::Occupancy && $transition->to === OccupancyStatus::Occupied->value) {
            if ($context->housekeeping !== HousekeepingStatus::Inspected) {
                throw new BusinessRuleViolation(
                    ErrorCode::RoomNotOccupiable,
                    'The room has not been inspected and is not occupiable.',
                );
            }

            if ($context->availability !== AvailabilityStatus::Sellable) {
                throw new BusinessRuleViolation(
                    ErrorCode::RoomNotOccupiable,
                    'The room is not available for occupancy.',
                );
            }
        }
    }

    private function find(RoomStatusAxis $axis, string $from, string $to): ?RoomStatusTransition
    {
        foreach (self::transitions() as $transition) {
            if ($transition->matches($axis, $from, $to)) {
                return $transition;
            }
        }

        return null;
    }
}
