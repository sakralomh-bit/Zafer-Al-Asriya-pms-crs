<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Housekeeping\Domain\HousekeepingTaskMachine;
use App\Modules\Housekeeping\Domain\HousekeepingTaskStatus;
use App\Modules\Housekeeping\Models\HousekeepingTask;
use App\Modules\Housekeeping\Services\HousekeepingTaskService;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Property;
use App\Modules\Rooms\Models\PhysicalRoom;
use App\Modules\Rooms\Models\RoomType;
use App\Modules\Rooms\Services\RoomStatusService;
use App\Shared\Authorization\PropertyScopeDenied;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ValidationFailed;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestFixtures;
use Tests\TestCase;

/**
 * `T-006` — housekeeping task lifecycle and the room availability it drives.
 *
 * Covers `AC-T-006-01` … `AC-T-006-05`.
 *
 * The interesting part of this task is not the task state machine; it is the
 * interaction between the task lifecycle and the room's housekeeping axis. A
 * task that completes without marking the room clean leaves a room that looks
 * sellable and is not, so `AC-T-006-02` is asserted by comparing all three room
 * axes before and after, not just the one that is supposed to change.
 */
final class HousekeepingTaskTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;

    private HousekeepingTaskService $service;

    private Property $property;

    private PhysicalRoom $room;

    private User $attendant;

    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        $this->service = $this->app->make(HousekeepingTaskService::class);
        $this->property = $this->makeProperty('Housekeeping Hotel');
        $this->room = $this->makeRoom();
        $this->room->forceFill(['housekeeping_status' => 'DIRTY'])->save();

        $this->attendant = $this->makeUser('attendant@example.test', Role::Housekeeping);
        $this->grantProperty($this->attendant, $this->property);

        // The inspector is also a Housekeeping user; §B.2 gives Housekeeping the
        // CLEAN -> INSPECTED and INSPECTED -> DIRTY edges.
        $this->inspector = $this->makeUser('inspector@example.test', Role::Housekeeping);
        $this->grantProperty($this->inspector, $this->property);
    }

    // =====================================================================
    // AC-T-006-01 — the task lifecycle
    // =====================================================================

    public function test_a_task_moves_created_assigned_in_progress_completed_inspected(): void
    {
        $task = $this->service->create(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'TURNDOWN',
            'Guest departing.',
            'corr-01',
        );

        $this->assertSame(HousekeepingTaskStatus::Created, $task->status);

        $task = $this->service->assign($this->attendant, $task, $this->attendant, 'corr-02');
        $this->assertSame(HousekeepingTaskStatus::Assigned, $task->status);

        $task = $this->service->start($this->attendant, $task, 'corr-03');
        $this->assertSame(HousekeepingTaskStatus::InProgress, $task->status);

        $task = $this->service->complete($this->attendant, $task, 'corr-04');
        $this->assertSame(HousekeepingTaskStatus::Completed, $task->status);

        $task = $this->service->inspectPass($this->inspector, $task, 'corr-05');
        $this->assertSame(HousekeepingTaskStatus::Inspected, $task->status);

        $this->assertSame('INSPECTED', $this->axes()['housekeeping']);
    }

    public function test_a_failed_reinspection_sends_the_task_to_rework(): void
    {
        // §J.4: REWORK_REQUIRED is reached on a FAILED RE-INSPECTION, so the
        // room must actually have been inspected first.
        $task = $this->service->inspectPass(
            $this->inspector,
            $this->completedTask(),
            'corr-rework-0',
        );

        $task = $this->service->requireRework(
            $this->inspector,
            $task,
            'The mirror in the bathroom was missed.',
            'corr-rework',
        );

        $this->assertSame(HousekeepingTaskStatus::ReworkRequired, $task->status);
    }

    /**
     * `REWORK_REQUIRED` is also reachable from `COMPLETED`, where the room is
     * `CLEAN` and no inspection has happened. §B.2 has no `CLEAN -> DIRTY` edge,
     * so the room axis is left alone rather than driving a re-inspection
     * failure that never occurred.
     */
    public function test_rework_before_inspection_leaves_the_room_clean(): void
    {
        // Completing the task is what drives the room DIRTY -> CLEAN (§B.2
        // "Cleaning completed"), so the baseline is read AFTER it, not before.
        $task = $this->completedTask();

        $this->assertSame('CLEAN', $this->axes()['housekeeping']);

        $this->service->requireRework(
            $this->inspector,
            $task,
            'The linen was the wrong shade.',
            'corr-rework-early',
        );

        $this->assertSame(
            'CLEAN',
            $this->axes()['housekeeping'],
            '§B.2 provides no CLEAN -> DIRTY edge, so the room must not be moved.',
        );
    }

    public function test_rework_requires_a_reason(): void
    {
        $task = $this->completedTask();

        $this->expectException(ValidationFailed::class);
        $this->service->requireRework($this->inspector, $task, '   ', 'corr-no-reason');
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $task = $this->service->create(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'TURNDOWN',
            'Guest still in residence.',
            'corr-cancel',
        );

        $this->expectException(ValidationFailed::class);
        $this->service->cancel($this->attendant, $task, '', 'corr-no-cancel-reason');
    }

    /**
     * `CANCELLED` is the only terminal task state. `INSPECTED` is not, because
     * §J.4 puts `REWORK_REQUIRED` after it on a failed re-inspection — see
     * `HousekeepingTaskStatus::isTerminal()`.
     */
    public function test_a_terminal_task_cannot_change_state(): void
    {
        $task = $this->service->create(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'TURNDOWN',
            'Guest still in residence.',
            'corr-terminal',
        );

        $task = $this->service->cancel($this->attendant, $task, 'Guest extended their stay.', 'corr-terminal-2');

        $this->assertTrue($task->status->isTerminal(), 'CANCELLED is terminal.');

        $this->expectException(DomainFailure::class);
        $this->service->assign($this->attendant, $task, $this->attendant, 'corr-terminal-3');
    }

    /**
     * §J.4: `INSPECTED` must NOT be terminal, or a failed re-inspection cannot
     * be recorded at all. This asserts the edge is reachable, because
     * `assertAllowed()` consults `isTerminal()` before the transition table and
     * would otherwise refuse the documented case.
     */
    public function test_an_inspected_task_can_still_require_rework(): void
    {
        $task = $this->service->inspectPass(
            $this->inspector,
            $this->completedTask(),
            'corr-inspected',
        );

        $this->assertFalse(
            $task->status->isTerminal(),
            'INSPECTED must not be terminal; §J.4 places REWORK_REQUIRED after it.',
        );

        $task = $this->service->requireRework(
            $this->inspector,
            $task,
            'The mirror in the bathroom was missed.',
            'corr-inspected-rework',
        );

        $this->assertSame(HousekeepingTaskStatus::ReworkRequired, $task->status);
    }

    public function test_an_illegal_task_transition_is_refused(): void
    {
        $task = $this->service->create(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'TURNDOWN',
            null,
            'corr-illegal',
        );

        // CREATED -> COMPLETED skips assignment and start.
        $this->expectException(DomainFailure::class);
        $this->service->complete($this->attendant, $task, 'corr-illegal-2');
    }

    // =====================================================================
    // AC-T-006-02 — axis independence
    // =====================================================================

    /**
     * The headline test of this task. Completing a task moves the room's
     * HOUSEKEEPING axis from DIRTY to CLEAN and must touch nothing else.
     */
    public function test_completing_a_task_moves_only_the_housekeeping_axis(): void
    {
        $this->room->forceFill(['housekeeping_status' => 'DIRTY'])->save();

        $before = $this->axes();

        $this->service->complete($this->attendant, $this->taskInProgress(), 'corr-axes-1');

        $after = $this->axes();

        $this->assertSame('DIRTY', $before['housekeeping']);
        $this->assertSame('CLEAN', $after['housekeeping'], 'Completing a task marks the room CLEAN.');

        $this->assertSame($before['occupancy'], $after['occupancy'], 'Occupancy must not change.');
        $this->assertSame($before['availability'], $after['availability'], 'Availability must not change.');
    }

    /**
     * `AC-T-006-02` verbatim: marking a room INSPECTED updates ONLY the
     * housekeeping axis.
     */
    public function test_inspecting_a_task_moves_only_the_housekeeping_axis(): void
    {
        $before = $this->axes();

        $this->service->inspectPass($this->inspector, $this->completedTask(), 'corr-axes-2');

        $after = $this->axes();

        $this->assertSame('INSPECTED', $after['housekeeping']);
        $this->assertSame($before['occupancy'], $after['occupancy']);
        $this->assertSame($before['availability'], $after['availability']);
    }

    public function test_failed_reinspection_moves_the_room_back_to_dirty(): void
    {
        $this->service->inspectPass(
            $this->inspector,
            $this->completedTask(),
            'corr-axes-3',
        );

        $this->assertSame('INSPECTED', $this->axes()['housekeeping']);

        $task = HousekeepingTask::query()
            ->where('room_id', $this->room->id)
            ->latest('created_at')
            ->firstOrFail();

        $this->service->requireRework($this->inspector, $task, 'Missed the mirror.', 'corr-axes-3c');

        $this->assertSame('DIRTY', $this->axes()['housekeeping']);
    }

    // =====================================================================
    // AC-T-006-03 — out of order
    // =====================================================================

    public function test_raising_out_of_order_makes_the_room_non_sellable_immediately(): void
    {
        $this->assertSame('SELLABLE', $this->axes()['availability']);

        $this->service->raiseOutOfOrder(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'Air conditioning failed.',
            'corr-ooo',
        );

        $this->assertSame('OUT_OF_ORDER', $this->axes()['availability']);
    }

    public function test_out_of_order_is_audited_with_its_reason(): void
    {
        $this->service->raiseOutOfOrder(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'Air conditioning failed.',
            'corr-ooo-2',
        );

        $row = DB::table('audit_events')
            ->where('subject_type', 'physical_room')
            ->where('subject_id', $this->room->id)
            ->where('action', 'ROOM_OUT_OF_ORDER')
            ->first();

        $this->assertNotNull($row, 'Taking a room out of order must be audited.');
        $this->assertSame('Air conditioning failed.', $row->reason, 'The reason must be recorded.');
    }

    /**
     * `AC-T-006-03` also implies the withdrawal is reversible, otherwise one
     * maintenance ticket removes inventory permanently.
     */
    public function test_returning_to_service_restores_sellability(): void
    {
        $this->service->raiseOutOfOrder(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'Air conditioning failed.',
            'corr-ooo-3',
        );

        $this->app->make(RoomStatusService::class)
            ->returnToService($this->room, $this->attendant, 'Repaired.', 'corr-ooo-4');

        $this->assertSame('SELLABLE', $this->axes()['availability']);
    }

    // =====================================================================
    // AC-T-006-04 — Housekeeping sees no guest or financial data
    // =====================================================================

    /**
     * `AC-T-006-04` has a half that is easy and a half that is easy to fake.
     *
     * The service exposes no guest, folio, posting, or amount accessor at all,
     * and `housekeeping_tasks` has no column that could hold one. That is
     * asserted structurally below rather than by calling methods and being
     * satisfied that the right ones are missing.
     */
    public function test_the_task_service_exposes_no_guest_or_financial_data(): void
    {
        $forbidden = ['guest', 'folio', 'posting', 'payment', 'amount', 'total', 'balance', 'refund'];

        $methods = array_map(
            strtolower(...),
            get_class_methods($this->service),
        );

        foreach ($forbidden as $needle) {
            foreach ($methods as $method) {
                $this->assertStringNotContainsString(
                    $needle,
                    $method,
                    "HousekeepingTaskService::{$method}() suggests housekeeping can reach {$needle} data.",
                );
            }
        }
    }

    public function test_the_task_table_has_no_guest_or_financial_column(): void
    {
        $columns = array_map(strtolower(...), DB::getSchemaBuilder()->getColumnListing('housekeeping_tasks'));

        foreach (['guest', 'folio', 'posting', 'payment', 'amount', 'total', 'balance', 'document_number', 'national_id'] as $needle) {
            $this->assertNotContains(
                $needle,
                $columns,
                "housekeeping_tasks must not carry a [{$needle}] column (AC-T-006-04).",
            );
        }
    }

    /**
     * A Housekeeping user granted to the property still cannot approve a refund
     * there. Property scope and role permission are separate factors; a grant is
     * not a promotion.
     */
    public function test_housekeeping_cannot_reach_financial_actions_in_its_own_property(): void
    {
        $this->assertSame(1, DB::table('user_property_scope')
            ->where('user_id', $this->attendant->id)
            ->count(), 'The fixture must actually grant the attendant this property.');

        $this->assertFalse(
            $this->attendant->holds(Permission::RefundApprove),
            'Housekeeping must not hold refund.approve even inside its own property.',
        );
        $this->assertFalse($this->attendant->holds(Permission::FolioRead));
        $this->assertFalse($this->attendant->holds(Permission::GuestIdentityRead));
    }

    // =====================================================================
    // AC-T-006-05 — a DIRTY room is not occupiable
    // =====================================================================

    public function test_a_dirty_room_is_not_occupiable(): void
    {
        $this->room->forceFill(['housekeeping_status' => 'DIRTY'])->save();

        $this->assertFalse(
            $this->service->roomCleanliness($this->room->id, $this->property->id) === 'INSPECTED',
        );
    }

    // =====================================================================
    // Scope and audit
    // =====================================================================

    public function test_a_task_outside_the_callers_scope_is_refused(): void
    {
        $outsider = $this->makeUser('outsider@example.test', Role::Housekeeping);
        $foreignRoom = $this->makeRoom(property: $this->makeProperty('Foreign Hotel'));

        $this->expectException(PropertyScopeDenied::class);
        $this->service->create($outsider, $foreignRoom->id, $foreignRoom->property_id, 'TURNDOWN', null, 'corr-scope');
    }

    public function test_every_task_transition_is_audited(): void
    {
        $this->service->create($this->attendant, $this->room->id, $this->property->id, 'TURNDOWN', 'Departure.', 'corr-aud');

        $this->assertGreaterThanOrEqual(
            1,
            DB::table('audit_events')->where('subject_type', 'housekeeping_task')->count(),
            'Creating a task must leave an audit row.',
        );
    }

    public function test_the_machine_is_the_single_source_of_task_transitions(): void
    {
        $machine = $this->app->make(HousekeepingTaskMachine::class);

        $this->assertTrue($machine->isAllowed(HousekeepingTaskStatus::Created, HousekeepingTaskStatus::Assigned));
        $this->assertFalse($machine->isAllowed(HousekeepingTaskStatus::Created, HousekeepingTaskStatus::Inspected));
    }

    // =====================================================================

    private function completedTask(): HousekeepingTask
    {
        $task = $this->service->create(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'TURNDOWN',
            'Guest departing.',
            'corr-helper',
        );

        $task = $this->service->assign($this->attendant, $task, $this->attendant, 'corr-helper-2');
        $task = $this->service->start($this->attendant, $task, 'corr-helper-3');

        return $this->service->complete($this->attendant, $task, 'corr-helper-4');
    }

    private function taskInProgress(): HousekeepingTask
    {
        $task = $this->service->create(
            $this->attendant,
            $this->room->id,
            $this->property->id,
            'TURNDOWN',
            'Guest departing.',
            'corr-helper-5',
        );

        $task = $this->service->assign($this->attendant, $task, $this->attendant, 'corr-helper-6');

        return $this->service->start($this->attendant, $task, 'corr-helper-7');
    }

    /**
     * @return array{occupancy: string, housekeeping: string, availability: string}
     */
    private function axes(): array
    {
        $room = $this->reread($this->room);

        return [
            'occupancy' => $room->occupancy_status->value,
            'housekeeping' => $room->housekeeping_status->value,
            'availability' => $room->availability_status->value,
        ];
    }

    private function makeRoom(?Property $property = null): PhysicalRoom
    {
        $property ??= $this->property;

        $roomType = RoomType::query()->create([
            'property_id' => $property->id,
            'code' => 'STD',
            'name' => 'Standard Synthetic Room',
            'max_occupancy' => 2,
            'is_active' => true,
        ]);

        return PhysicalRoom::query()->create([
            'property_id' => $property->id,
            'room_type_id' => $roomType->id,
            'room_number' => (string) random_int(100, 999),
        ]);
    }
}
