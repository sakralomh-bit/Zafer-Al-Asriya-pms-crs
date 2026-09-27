<?php

declare(strict_types=1);

use App\Shared\Database\CheckConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `room_types` and `physical_rooms`.
 *
 * Requirements: `FR-001`, `DR-006`, `AC-T-005-01`, `AC-T-005-04`,
 * `docs/STATE-MACHINES.md` §B.1.
 *
 * --------------------------------------------------------------------------------
 * ROOM STATUS IS THREE INDEPENDENT COLUMNS, NOT ONE `status` COLUMN.
 *
 * `DATA-MODEL` §2.7 and `AC-T-005-01` reject a single status column because it
 * produces contradictions such as a room that is simultaneously "occupied" and
 * "clean", and because "can this room be sold right now" cannot be answered
 * without cross-referencing three genuinely independent concepts.
 * --------------------------------------------------------------------------------
 *
 * NO MONETARY COLUMN. Rate and price belong to rate plans, which are blocked
 * behind `C-04` and are not part of T-005.
 *
 * `out_of_order_reason` is a nullable free-text field, not an enumeration.
 * `docs/STATE-MACHINES.md` §B.4 lists out-of-order reason codes as `TBD`, and
 * inventing a code list would present a guess as a policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_types', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->foreign('property_id')->references('id')->on('properties')
                ->restrictOnDelete();

            $table->string('code', 60);
            $table->string('name', 200);

            // FR-001 validation: occupancy within room-type limits.
            $table->unsignedTinyInteger('max_occupancy')->default(2);
            $table->unsignedTinyInteger('max_adults')->nullable();
            $table->unsignedTinyInteger('max_children')->nullable();
            $table->unsignedTinyInteger('max_infants')->nullable();

            $table->json('bed_configuration')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['property_id', 'code'], 'room_types_property_code_uq');
            $table->index(['property_id', 'is_active'], 'room_types_property_active_idx');
        });

        Schema::create('physical_rooms', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->foreign('property_id')->references('id')->on('properties')
                ->restrictOnDelete();
            $table->char('room_type_id', 26);
            $table->foreign('room_type_id')->references('id')->on('room_types')
                ->restrictOnDelete();

            $table->string('room_number', 40);
            $table->string('floor', 20)->nullable();

            // ---- Axis 1: occupancy (docs/STATE-MACHINES.md §B.1) ----
            $table->enum('occupancy_status', ['VACANT', 'OCCUPIED', 'RESERVED'])
                ->default('VACANT');

            // ---- Axis 2: housekeeping ----
            $table->enum('housekeeping_status', ['CLEAN', 'DIRTY', 'INSPECTED', 'IN_PROGRESS'])
                ->default('CLEAN');

            // ---- Axis 3: availability ----
            $table->enum('availability_status', ['SELLABLE', 'OUT_OF_ORDER', 'BLOCKED'])
                ->default('SELLABLE');

            // Out-of-order / block attribution. §B.2 requires a reason for both
            // transitions; the CHECK constraints below make it mandatory rather
            // than merely expected.
            $table->text('out_of_order_reason')->nullable();
            $table->timestamp('out_of_order_since', precision: 6)->nullable();
            $table->char('out_of_order_by_user_id', 26)->nullable();

            $table->text('blocked_reason')->nullable();
            $table->date('blocked_from')->nullable();
            $table->date('blocked_until')->nullable();
            $table->char('blocked_by_user_id', 26)->nullable();

            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            // FR-001: unique room code per property.
            $table->unique(['property_id', 'room_number'], 'physical_rooms_property_number_uq');

            // Indexes lead with property_id (DM-5). The composite indexes serve
            // the sellability and occupiability predicates that gate allocation
            // and check-in.
            $table->index(
                ['property_id', 'occupancy_status', 'housekeeping_status', 'availability_status'],
                'physical_rooms_occupiable_idx',
            );
            $table->index(['property_id', 'room_type_id', 'availability_status'], 'physical_rooms_sellable_idx');
            $table->index(['property_id', 'housekeeping_status'], 'physical_rooms_housekeeping_idx');
        });

        CheckConstraints::add(
            'physical_rooms',
            'physical_rooms_out_of_order_requires_reason',
            "availability_status <> 'OUT_OF_ORDER' OR out_of_order_reason IS NOT NULL",
        );

        CheckConstraints::add(
            'physical_rooms',
            'physical_rooms_blocked_requires_reason_and_from_date',
            "availability_status <> 'BLOCKED' "
            .'OR (blocked_reason IS NOT NULL AND blocked_from IS NOT NULL)',
        );
    }

    public function down(): void
    {
        CheckConstraints::drop('physical_rooms', 'physical_rooms_blocked_requires_reason_and_from_date');
        CheckConstraints::drop('physical_rooms', 'physical_rooms_out_of_order_requires_reason');
        Schema::dropIfExists('physical_rooms');
        Schema::dropIfExists('room_types');
    }
};
