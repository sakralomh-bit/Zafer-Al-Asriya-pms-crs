<?php

declare(strict_types=1);

namespace App\Modules\Rooms\Domain;

/**
 * Who `docs/STATE-MACHINES.md` §B.2 names as the actor for a transition.
 *
 * This is a LABEL, not an authorization decision. The permission required is
 * expressed by the calling service, because `ADR-0014` grants permissions per
 * resource and action while §B.2 describes intent. Keeping the two apart stops
 * a state-machine description from quietly becoming an authorization grant.
 */
enum RoomTransitionActor: string
{
    case System = 'SYSTEM';
    case FrontDesk = 'FRONT_DESK';
    case Housekeeping = 'HOUSEKEEPING';
    case Manager = 'MANAGER';
    case Inspector = 'INSPECTOR';
}
