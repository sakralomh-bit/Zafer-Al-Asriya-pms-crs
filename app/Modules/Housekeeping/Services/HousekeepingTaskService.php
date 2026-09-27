<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Services;

use App\Modules\Housekeeping\Domain\HousekeepingTaskMachine;
use App\Modules\Housekeeping\Domain\HousekeepingTaskStatus;
use App\Modules\Housekeeping\Models\HousekeepingTask;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Contracts\Actor;
use App\Modules\Identity\Contracts\AuthorizesRequests;
use App\Modules\Rooms\Contracts\RoomStatusPort;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecord;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Domain\ValidationFailed;
use Illuminate\Support\Facades\DB;

/**
 * The housekeeping task lifecycle and the room-availability blocking it drives.
 *
 * `docs/STATE-MACHINES.md` §J.4 (task states) and §B.2 (room housekeeping axis).
 *
 * ---------------------------------------------------------------------------
 * MODULE BOUNDARY
 *
 * This module does NOT import `Modules\Rooms\Models`. Every room interaction
 * goes through `Rooms\Contracts\RoomStatusPort`, which Rooms owns and
 * implements (`ADR-0017` §1: "Cross-module access goes through an explicit
 * contract interface owned by the providing module"). Identity interactions go
 * through `Identity\Contracts\AuthorizesRequests` and `Identity\Contracts\Actor`
 * for the same reason. `zafer:guard-modules` fails the build if either
 * dependency is reached into directly.
 * ---------------------------------------------------------------------------
 *
 * ---------------------------------------------------------------------------
 * TASK TRANSITION -> ROOM HOUSEKEEPING AXIS
 *
 * Only the edges where §B.2 supplies an exact counterpart are driven here. The
 * remaining §B.2 edges (`CLEAN -> IN_PROGRESS`, `IN_PROGRESS -> DIRTY`) belong
 * to the checkout/turndown sequence, which is `T-011`/`T-018`; they remain
 * implemented and tested in the Rooms module and are not guessed at here.
 *
 *   task COMPLETED        -> room DIRTY      -> CLEAN
 *   task INSPECTED        -> room CLEAN      -> INSPECTED
 *   task REWORK_REQUIRED  -> room INSPECTED  -> DIRTY
 *
 * `AC-T-006-02` — "Marking a room INSPECTED updates ONLY the housekeeping axis
 * and does not alter occupancy or availability" — holds because the room write
 * goes through the Rooms port, which writes one axis per call.
 *
 * `AC-T-006-05` — "A room in DIRTY status cannot be allocated or checked into" —
 * is not enforced here. It falls out of the §B.1 occupiability rule
 * (`Rooms\Contracts\RoomSnapshot::isOccupiable()`), which the allocation and
 * check-in paths call. Both are `T-007`/`T-011`, out of scope for this session;
 * what exists here is the axis they will read.
 */
final class HousekeepingTaskService
{
    public function __construct(
        private readonly HousekeepingTaskMachine $machine,
        private readonly AuthorizesRequests $authorization,
        private readonly RoomStatusPort $roomStatus,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(
        Actor $actor,
        string $roomId,
        string $propertyId,
        string $taskType = 'TURNDOWN',
        ?string $reason = null,
        ?string $correlationId = null,
    ): HousekeepingTask {
        $this->authorization->authorize(
            $actor,
            Permission::HousekeepingTaskCreate,
            $propertyId,
            subjectType: 'physical_room',
            subjectId: $roomId,
            correlationId: $correlationId,
        );

        return DB::transaction(function () use ($actor, $roomId, $propertyId, $taskType, $reason, $correlationId): HousekeepingTask {
            $task = new HousekeepingTask;
            $task->forceFill([
                'property_id' => $propertyId,
                'room_id' => $roomId,
                'status' => HousekeepingTaskStatus::Created,
                'task_type' => $taskType,
                'created_by_user_id' => $actor->identifier(),
                'reason' => $reason,
                'correlation_id' => $correlationId,
            ])->save();

            $this->audit->record(AuditRecord::of(
                action: AuditAction::HousekeepingTaskCreated,
                actorUserId: $actor->identifier(),
                actorRole: ($actor->roles()[0] ?? null)?->value,
                propertyId: $propertyId,
                subjectType: 'housekeeping_task',
                subjectId: (string) $task->id,
                source: 'housekeeping',
                correlationId: $correlationId,
                reason: $reason,
                after: ['status' => HousekeepingTaskStatus::Created->value, 'task_type' => $taskType],
            ));

            return $task;
        });
    }

    /**
     * `CREATED` / `REWORK_REQUIRED` -> `ASSIGNED`.
     */
    public function assign(
        Actor $actor,
        HousekeepingTask $task,
        Actor $assignee,
        ?string $correlationId = null,
    ): HousekeepingTask {
        $this->authorize($actor, Permission::HousekeepingTaskAssign, $task, $correlationId);

        return $this->transition(
            $actor,
            $task,
            HousekeepingTaskStatus::Assigned,
            correlationId: $correlationId,
            after: ['assigned_to_user_id' => $assignee->identifier()],
            timestamps: ['assigned_at' => now()],
        );
    }

    /**
     * `ASSIGNED` -> `IN_PROGRESS`.
     */
    public function start(
        Actor $actor,
        HousekeepingTask $task,
        ?string $correlationId = null,
    ): HousekeepingTask {
        $this->authorize($actor, Permission::HousekeepingTaskTransition, $task, $correlationId);

        return $this->transition(
            $actor,
            $task,
            HousekeepingTaskStatus::InProgress,
            correlationId: $correlationId,
            timestamps: ['started_at' => now()],
        );
    }

    /**
     * `IN_PROGRESS` -> `COMPLETED`, and the room's housekeeping axis
     * `DIRTY` -> `CLEAN` (§B.2 "Cleaning completed").
     */
    public function complete(
        Actor $actor,
        HousekeepingTask $task,
        ?string $correlationId = null,
    ): HousekeepingTask {
        $this->authorize($actor, Permission::HousekeepingTaskTransition, $task, $correlationId);

        $updated = $this->transition(
            $actor,
            $task,
            HousekeepingTaskStatus::Completed,
            correlationId: $correlationId,
            timestamps: ['completed_at' => now()],
        );

        $this->roomStatus->markCleanById(
            (string) $task->room_id,
            (string) $task->property_id,
            $correlationId,
        );

        return $updated;
    }

    /**
     * `COMPLETED` -> `INSPECTED`, and the room's housekeeping axis
     * `CLEAN` -> `INSPECTED`.
     *
     * `AC-T-006-02`: only the housekeeping axis changes. Occupancy and
     * availability are untouched, which the test suite asserts by comparing all
     * three axes before and after.
     */
    public function inspectPass(
        Actor $actor,
        HousekeepingTask $task,
        ?string $correlationId = null,
    ): HousekeepingTask {
        $this->authorize($actor, Permission::HousekeepingTaskInspect, $task, $correlationId);

        $updated = $this->transition(
            $actor,
            $task,
            HousekeepingTaskStatus::Inspected,
            correlationId: $correlationId,
            after: ['inspected_by_user_id' => $actor->identifier()],
            timestamps: ['inspected_at' => now()],
        );

        $this->roomStatus->markInspectedById(
            (string) $task->room_id,
            (string) $task->property_id,
            $correlationId,
        );

        return $updated;
    }

    /**
     * `COMPLETED` or `INSPECTED` -> `REWORK_REQUIRED`.
     *
     * A reason is mandatory and is enforced twice: here, and by the database
     * CHECK `housekeeping_tasks_rework_requires_reason`.
     */
    public function requireRework(
        Actor $actor,
        HousekeepingTask $task,
        string $reworkReason,
        ?string $correlationId = null,
    ): HousekeepingTask {
        $this->authorize($actor, Permission::HousekeepingTaskInspect, $task, $correlationId);

        if (trim($reworkReason) === '') {
            throw ValidationFailed::field(
                'rework_reason',
                'Rework must record why the work was rejected.',
            );
        }

        $updated = $this->transition(
            $actor,
            $task,
            HousekeepingTaskStatus::ReworkRequired,
            correlationId: $correlationId,
            after: ['rework_reason' => $reworkReason],
        );

        // THE ROOM AXIS MOVES ONLY IF THE ROOM WAS ACTUALLY INSPECTED.
        //
        // `docs/STATE-MACHINES.md` §B.2 has exactly one rework edge:
        // `INSPECTED -> DIRTY` (HOUSEKEEPING_REINSPECTION_FAILED). It applies to
        // a room that was inspected and failed re-inspection.
        //
        // This method is ALSO reachable from `COMPLETED`, where the room is
        // `CLEAN` and no inspection has happened yet. Driving
        // `failReinspectionById` there raised ROOM_STATE_INVALID — the refusal
        // path for a re-inspection that never occurred. §B.2 deliberately
        // provides NO `CLEAN -> DIRTY` edge, so there is no documented
        // transition to drive in that case and the room is left `CLEAN` for the
        // next cleaning cycle (`CLEAN -> IN_PROGRESS`).
        //
        // The alternative — inventing a `CLEAN -> DIRTY` edge — would be a state
        // machine change disguised as a bug fix. `docs/STATE-MACHINES.md` should
        // state what happens to a room whose cleaning is rejected before
        // inspection; until it does, the room stays CLEAN and this is recorded as
        // an open documentation question rather than resolved here.
        if ($this->roomStatus->snapshot((string) $task->room_id, (string) $task->property_id)->housekeepingStatus
            === 'INSPECTED') {
            $this->roomStatus->failReinspectionById(
                (string) $task->room_id,
                (string) $task->property_id,
                $reworkReason,
                $correlationId,
            );
        }

        return $updated;
    }

    /**
     * `CREATED` or `ASSIGNED` -> `CANCELLED`. A reason is mandatory.
     */
    public function cancel(
        Actor $actor,
        HousekeepingTask $task,
        string $reason,
        ?string $correlationId = null,
    ): HousekeepingTask {
        $this->authorize($actor, Permission::HousekeepingTaskTransition, $task, $correlationId);

        if (trim($reason) === '') {
            throw ValidationFailed::field('cancel_reason', 'Cancelling a task must record why it was withdrawn.');
        }

        return $this->transition(
            $actor,
            $task,
            HousekeepingTaskStatus::Cancelled,
            correlationId: $correlationId,
            after: ['cancel_reason' => $reason],
            timestamps: ['cancelled_at' => now()],
        );
    }

    /**
     * `AC-T-006-03`: taking a room out of order makes it non-sellable
     * immediately and audits the change with a reason.
     *
     * Delegates to the Rooms port so the availability axis has exactly one
     * writer, and so the step-up requirement `ADR-0014` §6 attaches to this
     * action has a single place to be enforced when `T-004` adds step-up.
     */
    public function raiseOutOfOrder(
        Actor $actor,
        string $roomId,
        string $propertyId,
        string $reason,
        ?string $correlationId = null,
    ): void {
        $this->roomStatus->markOutOfOrderById($actor, $roomId, $propertyId, $reason, $correlationId);
    }

    /**
     * `AC-T-006-04`: Housekeeping cannot read guest identity data, folio data,
     * or financial data.
     *
     * This service exposes no such capability at all. It has no method that
     * returns a guest, a folio, a posting, or an amount, and `housekeeping_tasks`
     * has no column that could hold one. The second half of the requirement —
     * that a Housekeeping user cannot READ those things through any other route
     * — is enforced by the permission matrix and asserted in the security suite.
     */
    public function roomCleanliness(string $roomId, string $propertyId): string
    {
        return $this->roomStatus->snapshot($roomId, $propertyId)->housekeepingStatus;
    }

    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $timestamps
     */
    private function transition(
        Actor $actor,
        HousekeepingTask $task,
        HousekeepingTaskStatus $to,
        ?string $correlationId,
        array $after = [],
        array $timestamps = [],
    ): HousekeepingTask {
        return DB::transaction(function () use ($actor, $task, $to, $correlationId, $after, $timestamps): HousekeepingTask {
            /** @var HousekeepingTask $locked */
            $locked = HousekeepingTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            $this->machine->assertAllowed($locked->status, $to);

            $before = $locked->status->value;

            $locked->forceFill(['status' => $to]);
            $locked->forceFill($after);
            $locked->forceFill($timestamps);
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(AuditRecord::of(
                action: AuditAction::HousekeepingTaskTransitioned,
                actorUserId: $actor->identifier(),
                actorRole: ($actor->roles()[0] ?? null)?->value,
                propertyId: (string) $locked->property_id,
                subjectType: 'housekeeping_task',
                subjectId: (string) $locked->id,
                source: 'housekeeping',
                correlationId: $correlationId,
                before: ['status' => $before],
                after: ['status' => $to->value] + $after,
                additionalContext: ['room_id' => (string) $locked->room_id],
            ));

            $task->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    private function authorize(
        Actor $actor,
        Permission $permission,
        HousekeepingTask $task,
        ?string $correlationId,
    ): void {
        $this->authorization->authorize(
            $actor,
            $permission,
            (string) $task->property_id,
            subjectType: 'housekeeping_task',
            subjectId: (string) $task->id,
            correlationId: $correlationId,
        );
    }
}
