<?php

declare(strict_types=1);

namespace App\Modules\Housekeeping\Domain;

use App\Shared\Domain\BusinessRuleViolation;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * The `docs/STATE-MACHINES.md` §J.4 housekeeping-task machine, encoded.
 *
 * The documented spine is:
 *
 *     CREATED -> ASSIGNED -> IN_PROGRESS -> COMPLETED -> INSPECTED
 *
 * with two branches, `REWORK_REQUIRED` and `CANCELLED`.
 *
 * TWO PLACEMENT DECISIONS ARE MADE HERE, and both are called out because §J.4
 * does not state them:
 *
 *  1. `REWORK_REQUIRED` is reachable from BOTH `COMPLETED` (the first inspection
 *     fails) and `INSPECTED` (a re-inspection fails). `AC-T-006-01` says
 *     "REWORK_REQUIRED on failed re-inspection" without restricting it to a task
 *     that was previously INSPECTED, and §B.2 has the room-level equivalent in
 *     `INSPECTED -> DIRTY` on "Re-inspection failed". Excluding the
 *     `COMPLETED -> REWORK_REQUIRED` edge would make a first-time inspection
 *     failure unrepresentable, which is a business outcome that plainly occurs.
 *  2. `CANCELLED` is reachable from `CREATED` and `ASSIGNED` only — a task that
 *     has already been worked on cannot be "cancelled", it has outcomes.
 *
 * Neither choice introduces a new state or a new code. They are recorded so a
 * reviewer can disagree with a specific edge rather than with the shape.
 */
final class HousekeepingTaskMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'CREATED' => ['ASSIGNED', 'CANCELLED'],
        'ASSIGNED' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['COMPLETED'],
        'COMPLETED' => ['INSPECTED', 'REWORK_REQUIRED'],
        'INSPECTED' => ['REWORK_REQUIRED'],
        'REWORK_REQUIRED' => ['ASSIGNED'],
        'CANCELLED' => [],
    ];

    /**
     * @return list<string>
     */
    public static function allowedTargets(HousekeepingTaskStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }

    public function isAllowed(HousekeepingTaskStatus $from, HousekeepingTaskStatus $to): bool
    {
        return in_array($to->value, self::allowedTargets($from), true);
    }

    /**
     * @throws DomainFailure BUSINESS_RULE_VIOLATION when the move is not in §J.4
     */
    public function assertAllowed(HousekeepingTaskStatus $from, HousekeepingTaskStatus $to): void
    {
        if ($from->isTerminal()) {
            throw new BusinessRuleViolation(
                ErrorCode::BusinessRuleViolation,
                sprintf('A task in %s is terminal and cannot change state.', $from->value),
            );
        }

        if (! $this->isAllowed($from, $to)) {
            throw new BusinessRuleViolation(
                ErrorCode::BusinessRuleViolation,
                sprintf(
                    'A housekeeping task cannot move from %s to %s (STATE-MACHINES.md §J.4).',
                    $from->value,
                    $to->value,
                ),
            );
        }
    }
}
