<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Domain\DomainFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the append-only audit trail.
 *
 * `ADR-0016`:
 *
 *  - The trail is **append-only**. There is no update path and no delete path,
 *    and the `audit_events` table is additionally guarded at the database level
 *    so that even a direct SQL UPDATE or DELETE is rejected.
 *  - The identity that performs an action **cannot alter the record** of that
 *    action. There is no superuser bypass on the audit path.
 *  - An audit write belongs to the same transaction as the business change where
 *    the two are the same fact, and an audit failure must never be swallowed
 *    (ADR risk table: "Audit writing fails silently and a gap opens").
 */
final class AuditRecorder
{
    /**
     * Record an accepted action.
     *
     * Call this inside the caller's transaction so the business change and its
     * audit record commit together or not at all.
     */
    public function record(AuditRecord $record): void
    {
        $this->insert($record, $record->result);
    }

    /**
     * Record a refusal.
     *
     * A denial is written now, and — because the business change it refused may
     * still be inside a transaction that is about to roll back — re-written from
     * an `afterRollback` hook so a denial can never be erased by the rollback of
     * the request that triggered it.
     */
    public function recordDenial(AuditRecord $record, DomainFailure $failure): void
    {
        $result = 'DENIED_' . $failure->errorCode->value;

        $this->insert($record, $result);

        DB::afterRollback(function () use ($record, $result, $failure): void {
            try {
                $this->insert($record, $result);
            } catch (Throwable $auditFailure) {
                // An audit gap is a security event in its own right. It is logged
                // at error level and alerted; it is NOT converted into a silent
                // success and NOT presented to the caller as anything but a denial.
                Log::error('audit.denial_write_failed', [
                    'action' => $record->action->value,
                    'denied_code' => $failure->errorCode->value,
                    'correlation_id' => $record->correlationId,
                    'error' => $auditFailure->getMessage(),
                ]);
            }
        });
    }

    private function insert(AuditRecord $record, string $result): void
    {
        DB::table('audit_events')->insert([
            'id' => (string) Str::ulid(),
            'action' => $record->action->value,
            'actor_user_id' => $record->actorUserId,
            'actor_role' => $record->actorRole,
            'property_id' => $record->propertyId,
            'subject_type' => $record->subjectType,
            'subject_id' => $record->subjectId,
            'occurred_at' => now(),
            'reason' => $record->reason,
            'correlation_id' => $record->correlationId,
            'source' => $record->source,
            'result' => $result,
            'before_state' => $record->before === null
                ? null
                : json_encode($record->before, JSON_THROW_ON_ERROR),
            'after_state' => $record->after === null
                ? null
                : json_encode($record->after, JSON_THROW_ON_ERROR),
            'context' => json_encode($record->additionalContext, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
