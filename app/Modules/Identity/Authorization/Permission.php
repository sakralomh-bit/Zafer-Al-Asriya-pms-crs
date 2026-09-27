<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization;

/**
 * The atomic permission catalogue: an action on a resource.
 *
 * These cases are a fixed, closed set. `ADR-0014` rejected "fine-grained
 * permission-per-action configuration" for v1.0 because "a general permission
 * engine before the 12 named roles are proven produces an untestable matrix".
 * There is therefore no endpoint that creates, edits, or deletes a permission,
 * and the seeded `permissions` rows always match this enum.
 *
 * `guest_identity.read` exists as a SEPARATE permission from `guest.read`
 * precisely so that "Housekeeping cannot read a guest's document number"
 * (`ADR-0014` §5) is expressible as a single absence rather than a filter
 * bolted onto guest reads.
 */
enum Permission: string
{
    // Organization / property master data
    case OrganizationRead = 'organization.read';
    case OrganizationUpdate = 'organization.update';
    case LegalEntityRead = 'legal_entity.read';
    case PropertyRead = 'property.read';
    case PropertyCreate = 'property.create';
    case PropertyUpdate = 'property.update';
    case ConfigurationRead = 'configuration.read';
    case ConfigurationUpdate = 'configuration.update';

    // Rooms
    case RoomTypeRead = 'room_type.read';
    case RoomTypeCreate = 'room_type.create';
    case RoomTypeUpdate = 'room_type.update';
    case RoomRead = 'room.read';
    case RoomCreate = 'room.create';
    case RoomUpdate = 'room.update';
    case RoomOccupancyTransition = 'room_occupancy.transition';
    case RoomHousekeepingTransition = 'room_housekeeping.transition';
    case RoomAvailabilityTransition = 'room_availability.transition';
    case RoomBlockTransition = 'room_block.transition';

    // Housekeeping
    case HousekeepingTaskRead = 'housekeeping_task.read';
    case HousekeepingTaskCreate = 'housekeeping_task.create';
    case HousekeepingTaskAssign = 'housekeeping_task.assign';
    case HousekeepingTaskTransition = 'housekeeping_task.transition';
    case HousekeepingTaskInspect = 'housekeeping_task.inspect';

    // Guests
    case GuestRead = 'guest.read';
    case GuestIdentityRead = 'guest_identity.read';

    // Reservations and front desk
    case ReservationRead = 'reservation.read';
    case ReservationCreate = 'reservation.create';
    case ReservationUpdate = 'reservation.update';
    case ReservationCancel = 'reservation.cancel';
    case ReservationCheckIn = 'reservation.check_in';
    case ReservationCheckOut = 'reservation.check_out';

    // Financials
    case FolioRead = 'folio.read';
    case FolioCharge = 'folio.charge';
    case PostingRead = 'posting.read';
    case PaymentRead = 'payment.read';
    case PaymentCreate = 'payment.create';
    case RefundRead = 'refund.read';
    case RefundCreate = 'refund.create';
    case RefundApprove = 'refund.approve';
    case InvoiceRead = 'invoice.read';
    case TaxConfigRead = 'tax_config.read';

    // Revenue
    case RatePlanRead = 'rate_plan.read';
    case RatePlanUpdate = 'rate_plan.update';
    case RestrictionRead = 'restriction.read';
    case RestrictionUpdate = 'restriction.update';

    // Compliance / audit
    case ComplianceSubmissionRead = 'compliance_submission.read';
    case ComplianceSubmissionReplay = 'compliance_submission.replay';
    case AuditTrailRead = 'audit_trail.read';

    // Identity administration
    case UserRead = 'user.read';
    case UserCreate = 'user.create';
    case UserUpdate = 'user.update';
    case RoleRead = 'role.read';
    case ScopeGrant = 'scope.grant';
    case ScopeRevoke = 'scope.revoke';

    // Night audit
    case NightAuditRun = 'night_audit.run';
    case NightAuditReopenApprove = 'night_audit.reopen_approve';

    // Reporting and export
    case ReportRead = 'report.read';
    case ExportCreate = 'export.create';

    /**
     * Only `read` counts as a read action. `ADR-0014` §5 gives the Auditor
     * "Read everything within scope" and denies "Any write at all"; deriving the
     * read set from this one predicate is what stops a newly added write
     * permission from reaching the Auditor by omission.
     */
    public function isRead(): bool
    {
        return $this->action() === 'read';
    }

    public function action(): string
    {
        $separator = strpos($this->value, '.');

        return $separator === false
            ? $this->value
            : substr($this->value, $separator + 1);
    }

    public function resource(): string
    {
        $separator = strpos($this->value, '.');

        return $separator === false
            ? $this->value
            : substr($this->value, 0, $separator);
    }

    public function label(): string
    {
        return $this->value;
    }

    /**
     * @return list<self>
     */
    public static function readOnly(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $permission): bool => $permission->isRead(),
        ));
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
