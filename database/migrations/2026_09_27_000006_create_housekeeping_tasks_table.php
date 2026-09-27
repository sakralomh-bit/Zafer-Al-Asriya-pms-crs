<?php

declare(strict_types=1);

use App\Shared\Database\CheckConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `housekeeping_tasks` — the `docs/STATE-MACHINES.md` §J.4 machine.
 *
 * Requirements: `FR-012`, `DR-006`, `AC-T-006-01`, `AC-T-006-02`, `AC-T-006-03`.
 *
 * State set is taken verbatim from §J.4:
 *   CREATED -> ASSIGNED -> IN_PROGRESS -> COMPLETED -> INSPECTED
 *   with branches REWORK_REQUIRED and CANCELLED.
 *
 * The task references a room, not a stay. A task never carries guest identity,
 * folio, or financial data — `AC-T-006-04` requires Housekeeping to be unable to
 * read those, and the strongest available form of that control is for the table
 * to have no column holding them at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housekeeping_tasks', function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            // DM-5: property-scoped, index leads with property_id.
            $table->char('property_id', 26);
            $table->foreign('property_id')->references('id')->on('properties')
                ->restrictOnDelete();
            $table->char('room_id', 26);
            $table->foreign('room_id')->references('id')->on('physical_rooms')
                ->restrictOnDelete();

            $table->enum('status', [
                'CREATED',
                'ASSIGNED',
                'IN_PROGRESS',
                'COMPLETED',
                'INSPECTED',
                'REWORK_REQUIRED',
                'CANCELLED',
            ])->default('CREATED');

            /**
             * The kind of work. `TASKS.md` T-006 records "inspection checklist
             * granularity ... TBD", so this is deliberately a coarse value the
             * operations owner can map onto a checklist later. It is NOT a
             * checklist: a task has no per-item completion storage, because the
             * checklist shape is an unanswered question.
             */
            $table->string('task_type', 60)->default('TURNDOWN');

            $table->char('assigned_to_user_id', 26)->nullable();
            $table->char('created_by_user_id', 26)->nullable();
            $table->char('inspected_by_user_id', 26)->nullable();

            $table->timestamp('assigned_at', precision: 6)->nullable();
            $table->timestamp('started_at', precision: 6)->nullable();
            $table->timestamp('completed_at', precision: 6)->nullable();
            $table->timestamp('inspected_at', precision: 6)->nullable();
            $table->timestamp('cancelled_at', precision: 6)->nullable();

            /**
             * `REWORK_REQUIRED` must carry the reason it was failed, and
             * `CANCELLED` must say why the task was withdrawn.
             */
            $table->text('rework_reason')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('reason')->nullable();

            $table->char('correlation_id', 26)->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->index(['property_id', 'status'], 'housekeeping_tasks_property_status_idx');
            $table->index(['room_id', 'status'], 'housekeeping_tasks_room_status_idx');
            $table->index(['property_id', 'assigned_to_user_id', 'status'], 'housekeeping_tasks_assignee_idx');
        });

        CheckConstraints::add(
            'housekeeping_tasks',
            'housekeeping_tasks_rework_requires_reason',
            "status <> 'REWORK_REQUIRED' OR rework_reason IS NOT NULL",
        );

        CheckConstraints::add(
            'housekeeping_tasks',
            'housekeeping_tasks_cancel_requires_reason',
            "status <> 'CANCELLED' OR cancel_reason IS NOT NULL",
        );
    }

    public function down(): void
    {
        CheckConstraints::drop('housekeeping_tasks', 'housekeeping_tasks_cancel_requires_reason');
        CheckConstraints::drop('housekeeping_tasks', 'housekeeping_tasks_rework_requires_reason');
        Schema::dropIfExists('housekeeping_tasks');
    }
};
