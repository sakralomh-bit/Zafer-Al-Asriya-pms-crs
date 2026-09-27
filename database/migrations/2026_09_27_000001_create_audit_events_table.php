<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `audit_events` — the append-only audit trail.
 *
 * Requirements: `DR-012`, `ADR-0016`, `AC-FR-010-01`, `BUS-014`.
 *
 * All ten `ADR-0016` §2 fields are present as columns: actor (who), action
 * (what), `occurred_at` (when), `property_id` (where), `before_state` /
 * `after_state` (before + after), `reason`, `correlation_id`, `source`,
 * `result`.
 *
 * Immutability is enforced in the DATABASE, not only in application code
 * (`ADR-0016` §3). MySQL triggers reject UPDATE and DELETE, so no application
 * bug, no artisan command, and no manual `psql`-equivalent session can alter
 * history. `ADR-0016` §2: "Do not allow ordinary users to silently alter audit
 * history."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('action', 80);

            // who
            $table->char('actor_user_id', 26)->nullable();
            $table->string('actor_role', 60)->nullable();

            // where
            $table->char('property_id', 26)->nullable();

            // what was acted upon
            $table->string('subject_type', 80)->nullable();
            $table->char('subject_id', 26)->nullable();

            // when
            $table->timestamp('occurred_at', precision: 6);

            $table->text('reason')->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->string('source', 120);
            $table->string('result', 80);

            // before / after — redacted before storage, never raw
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->json('context')->nullable();

            $table->timestamp('created_at', precision: 6);

            $table->index(['property_id', 'occurred_at'], 'audit_property_time_idx');
            $table->index(['actor_user_id', 'occurred_at'], 'audit_actor_time_idx');
            $table->index(['subject_type', 'subject_id'], 'audit_subject_idx');
            $table->index('action', 'audit_action_idx');
            $table->index('correlation_id', 'audit_correlation_idx');
        });

        $this->installImmutabilityGuards();
    }

    public function down(): void
    {
        $this->removeImmutabilityGuards();
        Schema::dropIfExists('audit_events');
    }

    /**
     * The audit trail is append-only at the storage layer.
     *
     * OPERATIONAL REQUIREMENT: when binary logging is enabled, MySQL refuses to
     * create a trigger for a user without the `SUPER` privilege unless
     * `log_bin_trust_function_creators` is enabled. The migration user must
     * therefore hold `SUPER`, or the server must set
     * `log_bin_trust_function_creators = 1`. This is stated rather than worked
     * around, because a deployment that cannot install the immutability guards
     * should fail loudly here and not ship an auditable-but-mutable ledger.
     */
    private function installImmutabilityGuards(): void
    {
        try {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER audit_events_no_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'audit_events is append-only: UPDATE is prohibited by ADR-0016'
            SQL);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Could not install the audit_events append-only guard (ADR-0016). '
                .'With binary logging enabled, MySQL requires either the SUPER privilege for the '
                .'migration user or log_bin_trust_function_creators = 1 on the server. '
                .'Underlying error: '.$e->getMessage(),
                previous: $e,
            );
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_events_no_delete
            BEFORE DELETE ON audit_events
            FOR EACH ROW
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'audit_events is append-only: DELETE is prohibited by ADR-0016'
        SQL);
    }

    private function removeImmutabilityGuards(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_events_no_delete');
    }
};
