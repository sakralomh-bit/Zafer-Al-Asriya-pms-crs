<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Domain;

/**
 * `docs/STATE-MACHINES.md` §J.4.
 *
 * Recorded gap: §J.4 is a one-line summary and does not state where
 * `REWORK_REQUIRED` branches from, nor where `CANCELLED` may be taken from.
 * `HousekeepingTaskMachine` implements the reading consistent with the rest of
 * the document set (an inspection failing, whether the first or a repeat, and a
 * task being withdrawn before work starts) and the machine class states both
 * choices explicitly so a reviewer can see them. This is recorded in
 * `docs/STATE-MACHINES.md` follow-up notes rather than resolved silently.
 */
enum HousekeepingTaskStatus: string
{
    case Created = 'CREATED';
    case Assigned = 'ASSIGNED';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Inspected = 'INSPECTED';
    case ReworkRequired = 'REWORK_REQUIRED';
    case Cancelled = 'CANCELLED';

    /**
     * G-3: terminal states are explicit and no transition leaves one.
     *
     * ONLY `CANCELLED` is terminal. `INSPECTED` is NOT, despite looking final:
     *
     *   `docs/STATE-MACHINES.md` §J.4 — "... -> COMPLETED -> INSPECTED, with
     *   REWORK_REQUIRED on failed re-inspection."
     *
     * A re-inspection that fails happens AFTER the task is INSPECTED, so
     * `INSPECTED -> REWORK_REQUIRED` is a required edge and
     * `HousekeepingTaskMachine` declares it. Listing `Inspected` here made that
     * edge dead code: `assertAllowed()` checks `isTerminal()` before consulting
     * the transition table, so a re-inspection failure was refused with "a task
     * in INSPECTED is terminal" — the exact case the edge exists to allow.
     *
     * `REWORK_REQUIRED` is not terminal either; it returns to `ASSIGNED`.
     */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }
}
