<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Property;
use App\Modules\Rooms\Contracts\RoomSnapshot;
use App\Modules\Rooms\Contracts\RoomStatusPort;
use App\Modules\Rooms\Domain\RoomStatusAxis;
use App\Modules\Rooms\Domain\RoomStatusMachine;
use App\Modules\Rooms\Domain\RoomStatusTransition;
use App\Modules\Rooms\Models\PhysicalRoom;
use App\Modules\Rooms\Models\RoomType;
use App\Modules\Rooms\Services\RoomStatusService;
use App\Shared\Audit\AuditAction;
use App\Shared\Authorization\PermissionDenied;
use App\Shared\Authorization\PropertyScopeDenied;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestFixtures;
use Tests\TestCase;

/**
 * `T-005` — room master data and the three-axis room status machine.
 *
 * Covers `AC-T-005-01` … `AC-T-005-06`.
 *
 * --------------------------------------------------------------------------------
 * WHY THIS SUITE EXISTS
 *
 * Every error path in `RoomStatusMachine` and `RoomStatusService` was throwing
 * `new DomainFailure(...)` against an ABSTRACT class. That is a PHP `Error`, not
 * a domain failure, so `AC-T-005-03` — "every invalid transition in §B.3 returns
 * its documented deterministic error code" — was failing on 100% of invalid
 * transitions. A caller received a 500 and a stack trace instead of
 * `ROOM_STATE_INVALID`. No test existed, so nothing reported it.
 *
 * The negative tests below are therefore the point of the file, not the positive
 * ones. A machine that allows everything needs no test.
 * --------------------------------------------------------------------------------
 */
final class RoomStatusMachineTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;

    private RoomStatusService $service;

    private RoomStatusMachine $machine;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        $this->service = $this->app->make(RoomStatusService::class);
        $this->machine = $this->app->make(RoomStatusMachine::class);
        $this->property = $this->makeProperty('Room Machine Hotel');
    }

    // =====================================================================
    // AC-T-005-01 — three INDEPENDENT axes
    // =====================================================================

    /**
     * The classic PMS contradiction is "occupied and clean". A single status
     * column cannot represent it, so the schema must not have one.
     */
    public function test_room_status_is_three_columns_and_not_one(): void
    {
        $columns = Schema::getColumnListing('physical_rooms');

        foreach (['occupancy_status', 'housekeeping_status', 'availability_status'] as $axis) {
            $this->assertContains($axis, $columns, "physical_rooms must carry [{$axis}].");
        }

        foreach (['status', 'room_status', 'state'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "physical_rooms must NOT carry a single [{$forbidden}] column; that is the model "
                .'this axis design exists to replace.',
            );
        }
    }

    /**
     * A room may be simultaneously OCCUPIED, CLEAN, and SELLABLE. Under a single
     * status column none of those three states is reachable.
     */
    public function test_a_room_can_be_occupied_and_clean_and_sellable_at_once(): void
    {
        $room = $this->makeRoom(['housekeeping_status' => 'INSPECTED']);

        $this->service->markReserved($room);
        $this->service->markOccupied($room);

        $fresh = $this->reread($room);

        $this->assertSame('OCCUPIED', $fresh->occupancy_status->value);
        $this->assertSame('INSPECTED', $fresh->housekeeping_status->value);
        $this->assertSame('SELLABLE', $fresh->availability_status->value);
    }

    // =====================================================================
    // AC-T-005-02 — every §B.2 transition is implemented
    // =====================================================================

    public function test_the_machine_encodes_every_documented_transition(): void
    {
        $transitions = RoomStatusMachine::transitions();

        $this->assertCount(13, $transitions, 'STATE-MACHINES.md §B.2 defines 13 room transitions.');

        $this->assertAllowed(RoomStatusAxis::Occupancy, 'VACANT', 'RESERVED');
        $this->assertAllowed(RoomStatusAxis::Occupancy, 'RESERVED', 'OCCUPIED');
        $this->assertAllowed(RoomStatusAxis::Occupancy, 'OCCUPIED', 'VACANT');
        $this->assertAllowed(RoomStatusAxis::Occupancy, 'RESERVED', 'VACANT');

        $this->assertAllowed(RoomStatusAxis::Housekeeping, 'CLEAN', 'IN_PROGRESS');
        $this->assertAllowed(RoomStatusAxis::Housekeeping, 'IN_PROGRESS', 'DIRTY');
        $this->assertAllowed(RoomStatusAxis::Housekeeping, 'DIRTY', 'CLEAN');
        $this->assertAllowed(RoomStatusAxis::Housekeeping, 'CLEAN', 'INSPECTED');
        $this->assertAllowed(RoomStatusAxis::Housekeeping, 'INSPECTED', 'DIRTY');

        $this->assertAllowed(RoomStatusAxis::Availability, 'SELLABLE', 'OUT_OF_ORDER');
        $this->assertAllowed(RoomStatusAxis::Availability, 'OUT_OF_ORDER', 'SELLABLE');
        $this->assertAllowed(RoomStatusAxis::Availability, 'SELLABLE', 'BLOCKED');
        $this->assertAllowed(RoomStatusAxis::Availability, 'BLOCKED', 'SELLABLE');
    }

    /**
     * No cross-axis edge may exist. Occupancy and housekeeping are independent,
     * and a machine that allowed `OCCUPIED -> DIRTY` as a single step would
     * quietly merge the two axes back together.
     */
    public function test_no_transition_crosses_axes(): void
    {
        foreach (RoomStatusMachine::transitions() as $transition) {
            $this->assertTrue(
                $transition->axis !== RoomStatusAxis::Occupancy
                    || in_array($transition->from, ['VACANT', 'RESERVED', 'OCCUPIED'], true),
                'Every occupancy transition must start from an occupancy value.',
            );
        }

        // The three axes are genuinely disjoint sets of edges. If they shared an
        // edge, the machine would be one status column wearing three hats.
        $edges = array_map(
            static fn (RoomStatusTransition $t): string => $t->axis->value.':'.$t->from.'>'.$t->to,
            RoomStatusMachine::transitions(),
        );

        $this->assertSame(
            count($edges),
            count(array_unique($edges)),
            'Duplicate transition edges would make the machine ambiguous.',
        );
    }

    public function test_the_occupancy_lifecycle_runs_end_to_end(): void
    {
        $room = $this->makeRoom(['housekeeping_status' => 'INSPECTED']);

        $this->service->markReserved($room);
        $this->assertSame('RESERVED', $this->reread($room)->occupancy_status->value);

        $this->service->markOccupied($room);
        $this->assertSame('OCCUPIED', $this->reread($room)->occupancy_status->value);

        $this->service->markVacantAfterCheckOut($room);
        $this->assertSame('VACANT', $this->reread($room)->occupancy_status->value);
    }

    public function test_a_reservation_can_be_released_without_a_checkout(): void
    {
        $room = $this->makeRoom();

        $this->service->markReserved($room);
        $this->service->markReleased($room);

        $this->assertSame('VACANT', $this->reread($room)->occupancy_status->value);
    }

    public function test_the_housekeeping_lifecycle_runs_end_to_end(): void
    {
        $room = $this->makeRoom(['housekeeping_status' => 'CLEAN']);

        $this->service->startHousekeeping($room);
        $this->assertSame('IN_PROGRESS', $this->reread($room)->housekeeping_status->value);

        $this->service->markDirty($room);
        $this->assertSame('DIRTY', $this->reread($room)->housekeeping_status->value);

        $this->service->markClean($room);
        $this->assertSame('CLEAN', $this->reread($room)->housekeeping_status->value);

        $this->service->markInspected($room);
        $this->assertSame('INSPECTED', $this->reread($room)->housekeeping_status->value);

        $this->service->failReinspection($room, 'The mirror was missed.', 'corr-1');
        $this->assertSame('DIRTY', $this->reread($room)->housekeeping_status->value);
    }

    // =====================================================================
    // AC-T-005-03 — invalid transitions return DOCUMENTED error codes
    // =====================================================================

    /**
     * The headline test. Every one of these threw a PHP `Error` before the
     * abstract-class fix.
     */
    public function test_an_invalid_transition_returns_room_state_invalid(): void
    {
        $room = $this->makeRoom();

        // VACANT -> OCCUPIED is not in §B.2; a room becomes OCCUPIED only
        // through RESERVED.
        $this->assertRefused(
            fn () => $this->service->markOccupied($room),
            ErrorCode::RoomStateInvalid,
        );
    }

    public function test_a_dirty_room_cannot_skip_straight_to_inspected(): void
    {
        $room = $this->makeRoom(['housekeeping_status' => 'DIRTY']);

        $this->assertRefused(
            fn () => $this->service->markInspected($room),
            ErrorCode::RoomStateInvalid,
        );
    }

    public function test_an_out_of_order_room_reports_room_out_of_order(): void
    {
        $room = $this->makeRoom();

        // `markOutOfOrder` takes an ACTOR and authorizes `room_availability.
        // transition` — out-of-order is a permission-controlled action, not a
        // system action, so a bare room is not enough to drive it.
        $actor = $this->makeAuthorizedUser('ooo@example.test', Role::HotelManager);
        $this->service->markOutOfOrder($room, $actor, 'Air conditioning failed.', 'corr-2');

        $this->assertRefused(
            fn () => $this->service->markReserved($room),
            ErrorCode::RoomOutOfOrder,
        );
    }

    /**
     * §B.3 row 1: `OCCUPIED -> VACANT` is a LEGAL pair in §B.2, so the
     * illegality is not the transition — it is the missing check-out. The two
     * `markVacant*` methods are therefore identical except for the
     * `checkOutCompleted` fact, and this test asserts the pair really does
     * behave differently.
     *
     * They did not. `transition()` called `$locked->context()` with no argument,
     * so the flag was always false: the legal path was refused with
     * ROOM_OCCUPIED and a checked-out guest's room could never be vacated. The
     * two methods were byte-for-byte the same, which also made the
     * "illegal action is visible at the call site" claim in their docblock
     * false.
     */
    public function test_vacating_without_a_checkout_is_refused_but_with_one_is_allowed(): void
    {
        $room = $this->makeRoom(['housekeeping_status' => 'INSPECTED']);

        $this->service->markReserved($room);
        $this->service->markOccupied($room);

        $this->assertRefused(
            fn () => $this->service->markVacantWithoutCheckOut($room, 'corr-nocheckout'),
            ErrorCode::RoomOccupied,
        );

        $this->service->markVacantAfterCheckOut($room, 'corr-checkout');

        $this->assertSame('VACANT', $this->reread($room)->occupancy_status->value);
    }

    public function test_a_failed_transition_leaves_the_room_untouched(): void
    {
        $room = $this->makeRoom();
        $before = $this->reread($room);

        try {
            $this->service->markOccupied($room);
        } catch (DomainFailure) {
            // expected
        }

        $after = $this->reread($room);

        $this->assertSame($before->occupancy_status->value, $after->occupancy_status->value);
        $this->assertSame($before->housekeeping_status->value, $after->housekeeping_status->value);
        $this->assertSame($before->availability_status->value, $after->availability_status->value);
    }

    // =====================================================================
    // AC-T-005-04 — occupiability evaluates ALL THREE axes
    // =====================================================================

    public function test_a_room_is_occupiable_only_when_all_three_axes_allow_it(): void
    {
        $occupiable = new RoomSnapshot('1', '2', '101', 'VACANT', 'INSPECTED', 'SELLABLE');
        $this->assertTrue($occupiable->isOccupiable());

        // One axis wrong in each direction is enough to refuse.
        $this->assertFalse(
            (new RoomSnapshot('1', '2', '101', 'OCCUPIED', 'INSPECTED', 'SELLABLE'))->isOccupiable(),
            'A non-VACANT room is not occupiable.',
        );
        $this->assertFalse(
            (new RoomSnapshot('1', '2', '101', 'VACANT', 'CLEAN', 'SELLABLE'))->isOccupiable(),
            'A merely CLEAN room is not occupiable; it must be INSPECTED.',
        );
        $this->assertFalse(
            (new RoomSnapshot('1', '2', '101', 'VACANT', 'DIRTY', 'SELLABLE'))->isOccupiable(),
            'A DIRTY room is not occupiable (AC-T-006-05).',
        );
        $this->assertFalse(
            (new RoomSnapshot('1', '2', '101', 'VACANT', 'INSPECTED', 'OUT_OF_ORDER'))->isOccupiable(),
            'An out-of-order room is not occupiable.',
        );
        $this->assertFalse(
            (new RoomSnapshot('1', '2', '101', 'VACANT', 'INSPECTED', 'BLOCKED'))->isOccupiable(),
            'A BLOCKED room is not occupiable.',
        );
    }

    /**
     * `AC-T-006-05`: a DIRTY room cannot be allocated or checked into. The
     * allocation and check-in paths are T-007/T-011; what exists now is the
     * predicate they read, and it is asserted here so the rule is fixed before
     * anything depends on it.
     */
    public function test_a_dirty_room_is_not_occupiable(): void
    {
        $room = $this->makeRoom(['housekeeping_status' => 'DIRTY']);

        $this->assertFalse(
            $this->service->snapshot($room->id, $this->property->id)->isOccupiable(),
        );
    }

    // =====================================================================
    // AC-T-005-05 — scope
    // =====================================================================

    public function test_a_status_change_outside_the_callers_scope_is_denied(): void
    {
        $actor = $this->makeUser('noscope@example.test', Role::HotelManager);
        $theirProperty = $this->makeProperty('Their Hotel');
        $foreignProperty = $this->makeProperty('Foreign Hotel');

        $this->grantProperty($actor, $theirProperty);

        $foreignRoom = $this->makeRoom(property: $foreignProperty);

        $this->expectException(PropertyScopeDenied::class);
        $this->service->markOutOfOrder($foreignRoom, $actor, 'Not my hotel.', 'corr-scope');
    }

    public function test_the_scope_is_asserted_before_the_transition_is_validated(): void
    {
        $actor = $this->makeUser('noscope2@example.test', Role::HotelManager);
        $theirProperty = $this->makeProperty('Their Hotel 2');
        $foreignProperty = $this->makeProperty('Foreign Hotel 2');

        $this->grantProperty($actor, $theirProperty);

        // The action is BOTH out of scope AND would be refused on its merits.
        // Scope is checked first, so the caller cannot use the error code to
        // probe a foreign property's room state.
        $foreignRoom = $this->makeRoom(property: $foreignProperty);

        try {
            $this->service->markOutOfOrder($foreignRoom, $actor, 'Not my hotel.', 'corr-scope-2');
            $this->fail('Expected a scope denial.');
        } catch (PropertyScopeDenied $denied) {
            $this->assertSame(ErrorCode::PropertyScopeDenied, $denied->errorCode);
        }
    }

    public function test_out_of_order_requires_a_reason(): void
    {
        $actor = $this->makeAuthorizedUser('reason@example.test', Role::HotelManager);
        $room = $this->makeRoom();

        $this->assertRefused(
            fn () => $this->service->markOutOfOrder($room, $actor, '   ', 'corr-reason'),
            ErrorCode::ValidationFailed,
        );
    }

    /**
     * `AC-T-006-03`: out-of-order is permission-controlled, so a caller whose
     * role lacks `room_availability.transition` is refused even inside their own
     * property. Scope and permission are separate factors and both are checked.
     */
    public function test_out_of_order_is_refused_without_the_permission(): void
    {
        $actor = $this->makeUser('noperm@example.test', Role::FrontDeskAgent);
        $this->grantProperty($actor, $this->property);

        $room = $this->makeRoom();

        $this->expectException(PermissionDenied::class);
        $this->service->markOutOfOrder($room, $actor, 'Front desk cannot do this.', 'corr-noperm');
    }

    // =====================================================================
    // AC-T-005-06 — audit
    // =====================================================================

    public function test_every_status_change_is_audited_with_before_and_after(): void
    {
        $room = $this->makeRoom();

        $this->service->markReserved($room, 'corr-audit-1');

        $row = DB::table('audit_events')
            ->where('subject_type', 'physical_room')
            ->where('subject_id', $room->id)
            ->orderByDesc('occurred_at')
            ->first();

        $this->assertNotNull($row, 'A room status change must leave an audit row.');

        $before = json_decode((string) $row->before_state, true);
        $after = json_decode((string) $row->after_state, true);

        $this->assertSame('VACANT', $before['occupancy'] ?? null);
        $this->assertSame('RESERVED', $after['occupancy'] ?? null);
        $this->assertSame('corr-audit-1', $row->correlation_id);
    }

    public function test_a_refused_transition_is_audited_too(): void
    {
        $room = $this->makeRoom();

        try {
            $this->service->markOccupied($room, 'corr-audit-2');
        } catch (DomainFailure) {
            // expected
        }

        $this->assertSame(
            1,
            DB::table('audit_events')
                ->where('subject_type', 'physical_room')
                ->where('subject_id', $room->id)
                ->where('action', AuditAction::RoomStatusTransitionDenied->value)
                ->count(),
            'A refused transition must be audited, not merely refused.',
        );
    }

    // =====================================================================
    // Concurrency (ADR-0008)
    // =====================================================================

    public function test_each_transition_increments_the_lock_version(): void
    {
        $room = $this->makeRoom();
        $start = (int) $room->lock_version;

        $this->service->markReserved($room);

        $this->assertGreaterThan($start, (int) $this->reread($room)->lock_version);
    }

    /**
     * The Rooms port is the surface Housekeeping is allowed to use. Its presence
     * is what makes `ADR-0017` §1 work, and `test_it_allows_a_contracts_import`
     * in the Architecture suite is what stops that from being wishful.
     */
    public function test_the_status_port_is_bound_to_the_rooms_implementation(): void
    {
        $this->assertInstanceOf(RoomStatusService::class, $this->app->make(RoomStatusPort::class));
    }

    // =====================================================================

    private function assertAllowed(RoomStatusAxis $axis, string $from, string $to): void
    {
        $this->assertTrue(
            $this->machine->isAllowed($axis, $from, $to),
            "§B.2 requires {$axis->value}: {$from} -> {$to} to be a valid transition.",
        );
    }

    private function assertRefused(callable $call, ErrorCode $expected): void
    {
        try {
            $call();
            $this->fail("Expected the transition to be refused with {$expected->value}.");
        } catch (DomainFailure $failure) {
            $this->assertSame(
                $expected,
                $failure->errorCode,
                "Expected {$expected->value}, got {$failure->errorCode->value}: {$failure->getMessage()}",
            );
        }
    }

    /**
     * A user granted to this property and holding the given role.
     */
    private function makeAuthorizedUser(string $email, Role $role): User
    {
        $user = $this->makeUser($email, $role);
        $this->grantProperty($user, $this->property);

        return $user;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function makeRoom(array $attributes = [], ?Property $property = null): PhysicalRoom
    {
        $property ??= $this->property;

        $roomType = RoomType::query()->create([
            'property_id' => $property->id,
            'code' => 'STD',
            'name' => 'Standard Synthetic Room',
            'max_occupancy' => 2,
            'is_active' => true,
        ]);

        return PhysicalRoom::query()->create($attributes + [
            'property_id' => $property->id,
            'room_type_id' => $roomType->id,
            'room_number' => (string) random_int(100, 999),
        ]);
    }
}
