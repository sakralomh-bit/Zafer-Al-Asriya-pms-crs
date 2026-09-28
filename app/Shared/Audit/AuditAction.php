<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Domain\ErrorCode;

/**
 * The audit action vocabulary.
 *
 * Transcribed from the event names already fixed in `docs/STATE-MACHINES.md`
 * and `docs/API-SPEC.md`. An event name is part of the observable contract, so
 * cases are added only where a documented event already exists — never invented
 * ad hoc at a call site.
 *
 * @see docs/ADR/0016-audit-trail-immutability.md
 */
enum AuditAction: string
{
    // Organization / property master data (AC-T-002-04)
    case PropertyCreated = 'PROPERTY_CREATED';
    case PropertyUpdated = 'PROPERTY_UPDATED';
    case PropertyConfigurationChanged = 'PROPERTY_CONFIGURATION_CHANGED';
    case LegalEntityCreated = 'LEGAL_ENTITY_CREATED';
    case ConfigurationVersionRestored = 'CONFIGURATION_VERSION_RESTORED';

    // Identity and authorization (ADR-0014 §6)
    case ScopeGranted = 'SCOPE_GRANTED';
    case ScopeRevoked = 'SCOPE_REVOKED';
    case ScopeSelfGrantDenied = 'SCOPE_SELF_GRANT_DENIED';
    case RoleAssigned = 'ROLE_ASSIGNED';
    case RoleRemoved = 'ROLE_REMOVED';

    // Room status machine, docs/STATE-MACHINES.md §B.2
    case RoomReserved = 'ROOM_RESERVED';
    case RoomOccupied = 'ROOM_OCCUPIED';
    case RoomVacant = 'ROOM_VACANT';
    case RoomReleased = 'ROOM_RELEASED';
    case HousekeepingStarted = 'HOUSEKEEPING_STARTED';
    case HousekeepingDirty = 'HOUSEKEEPING_DIRTY';
    case HousekeepingClean = 'HOUSEKEEPING_CLEAN';
    case HousekeepingInspected = 'HOUSEKEEPING_INSPECTED';
    case HousekeepingReinspectionFailed = 'HOUSEKEEPING_REINSPECTION_FAILED';
    case RoomOutOfOrder = 'ROOM_OUT_OF_ORDER';
    case RoomReturnedToService = 'ROOM_RETURNED_TO_SERVICE';
    case RoomBlocked = 'ROOM_BLOCKED';
    case RoomUnblocked = 'ROOM_UNBLOCKED';

    // `ADR-0016`: the trail "records attempts, denials, and configuration changes".
    // A refused room transition is a denial and is audited like one. Before this
    // existed, `RoomStatusService::transition()` recorded the SUCCESS path and let
    // the refusal propagate unaudited, so a caller repeatedly attempting an illegal
    // transition left no trace at all — precisely the behaviour a compliance review
    // asks about and cannot answer.
    case RoomStatusTransitionDenied = 'ROOM_STATUS_TRANSITION_DENIED';

    // Room master data (FR-001)
    case RoomTypeCreated = 'ROOM_TYPE_CREATED';
    case RoomCreated = 'ROOM_CREATED';
    case RoomUpdated = 'ROOM_UPDATED';

    // Housekeeping task machine, docs/STATE-MACHINES.md §J.4
    case HousekeepingTaskCreated = 'HOUSEKEEPING_TASK_CREATED';
    case HousekeepingTaskTransitioned = 'HOUSEKEEPING_TASK_TRANSITIONED';

    // Access-control denials (ADR-0014 §3, §7)
    case AuthorizationDenied = 'AUTHORIZATION_DENIED';

    // Authentication, `docs/API-SPEC.md` §3.11. The event NAMES are
    // transcribed from the audit column of that table, not chosen here:
    // `AUTH_SUCCEEDED` / `AUTH_FAILED` on login and `AUTH_LOGOUT` on logout.
    //
    // `MFA_*` events are deliberately absent. `docs/API-SPEC.md` §3.11 names
    // them for `/auth/mfa/verify`, but no MFA mechanism is specified anywhere,
    // so there is no event to record and inventing one would fix an observable
    // contract to a design that does not exist.
    case AuthSucceeded = 'AUTH_SUCCEEDED';
    case AuthFailed = 'AUTH_FAILED';
    case AuthLogout = 'AUTH_LOGOUT';
    case StepUpPerformed = 'STEP_UP_PERFORMED';

    // `docs/API-SPEC.md` §3.11 line 333: `POST /api/v1/auth/mfa/verify` audits
    // `MFA_*` — the specification names the FAMILY and not the members, so the
    // two names below are transcribed from the two things
    // `docs/ADR/0016:23` requires to be auditable: "MFA challenge and failure".
    //
    // They exist now because `MfaPolicy` and `TotpVerifier` exist and are
    // tested, and an unverifiable MFA primitive with no way to record that a
    // challenge happened is an audit gap waiting for its first incident. They
    // have no emitter yet — there is no `/auth/mfa/verify` route and no
    // enrolment flow — and an action in the vocabulary is not an audit of
    // something that did not happen.
    //
    // An MFA FAILURE is a distinct action rather than an `AUTH_FAILED` because
    // it answers a different question. A failed login is "we do not know you";
    // a failed second factor is "we know exactly who you are and the second
    // factor did not hold". Collapsing them would erase the one signal that
    // distinguishes a compromised password from a coerced or socially
    // engineered one — which is the signal a security review actually wants.
    case MfaChallenged = 'MFA_CHALLENGED';
    case MfaFailed = 'MFA_FAILED';

    /**
     * A transition's outcome. `ADR-0016` records the `result` field, so a
     * refused action is auditable as a refusal and not only as a success.
     */
    public function result(): string
    {
        return $this->value;
    }

    public static function denialFor(ErrorCode $code): self
    {
        return match ($code) {
            ErrorCode::PropertyScopeDenied, ErrorCode::PermissionDenied,
            ErrorCode::StepUpRequired, ErrorCode::ImpersonationDenied => self::AuthorizationDenied,
            default => self::AuthorizationDenied,
        };
    }
}
