<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

use App\Shared\Audit\AuditAction;

/**
 * One row of the `docs/STATE-MACHINES.md` §B.2 transition table.
 */
final class RoomStatusTransition
{
    public function __construct(
        public readonly RoomStatusAxis $axis,
        public readonly string $from,
        public readonly string $to,
        public readonly RoomTransitionActor $actor,
        public readonly AuditAction $auditAction,
    ) {
    }

    public function matches(RoomStatusAxis $axis, string $from, string $to): bool
    {
        return $this->axis === $axis
            && $this->from === $from
            && $this->to === $to;
    }
}
