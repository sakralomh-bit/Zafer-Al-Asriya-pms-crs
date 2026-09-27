<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization;

/**
 * The role x permission matrix, transcribed from `ADR-0014` §5.
 *
 * `ADR-0014` §7: "Deny-by-default is a claim that is only meaningful if it is
 * tested." This class is the single source of that matrix, and
 * `tests/Security/RolePermissionMatrixTest.php` asserts it case by case —
 * including every negative the ADR names in §5:
 *
 *   - "Finance cannot check in"                -> no `reservation.check_in`
 *   - "Auditor cannot write"                   -> read-only set, derived
 *   - "Housekeeping cannot read a document
 *      number"                                 -> no `guest_identity.read`
 *   - "a Reservation Agent cannot take a
 *      payment"                               -> no `payment.create`
 *
 * A role that is absent from this map has NO permissions. That is the
 * deny-by-default direction: the safe state is the empty one.
 */
final class RolePermissionMatrix
{
    /**
     * @var array<string, string>
     */
    private const CITATIONS = [
        'GROUP_MANAGER' => 'ADR-0014 §5: "Group-wide configuration, consolidated reporting, rate and '
            .'policy approval, business-date reopen approval, role and scope administration. '
            .'Nothing by role — but subject to separation of duties (§6); cannot grant themselves '
            .'additional scope."',
        'HOTEL_MANAGER' => 'ADR-0014 §5: "Full operational and financial authority for those properties; '
            .'room blocks; rate overrides with approval; out-of-order decisions; Night Auditor/'
            .'Housekeeping supervision. Not: other properties; granting own scope; refunds outside '
            .'approval thresholds."',
        'FRONT_DESK_AGENT' => 'ADR-0014 §5: "Check-in, check-out, room transfer, stay extension, guest '
            .'lookup, folio view and charge entry. Not: refunds, rate configuration, night audit, '
            .'scope administration, configuration."',
        'RESERVATION_AGENT' => 'ADR-0014 §5: "Availability search, reservation create/modify/cancel, holds, '
            .'no-show marking, guest lookup. Not: check-in/out, refunds, configuration, financial '
            .'posting."',
        'HOUSEKEEPING' => 'ADR-0014 §5: "Room housekeeping status, task assignment and completion, '
            .'inspection, out-of-order raising. NOT: guest identity data, folio and financial data, '
            .'reservations, rates."',
        'FINANCE' => 'ADR-0014 §5: "Postings, payments, refunds (step-up + approval), settlement, '
            .'reconciliation, invoice and tax work, financial reporting. Not: check-in/out, rate '
            .'configuration, scope administration, night audit execution."',
        'NIGHT_AUDITOR' => 'ADR-0014 §5: "Run/resume night audit, mark no-shows, raise out-of-order, '
            .'reopen with approval. Not: refunds, rate configuration, scope changes, own approval of '
            .'a reopen."',
        'POS_CASHIER' => 'ADR-0014 §5: "Phase C — deferred. Role defined so the model is complete; no '
            .'Phase A permissions."',
        'REVENUE_MANAGER' => 'ADR-0014 §5: "Rates, rate plans, restrictions, availability management, '
            .'forecasting. Not: refunds, scope changes, check-in, financial posting."',
        'COMPLIANCE_OFFICER' => 'ADR-0014 §5: "Invoice and compliance submission review, audit trail '
            .'review, tax configuration review, DLQ replay authorization. Not: modify posted '
            .'financial records; ordinary booking operations; alter audit records."',
        'AUDITOR' => 'ADR-0014 §5: "Read-only scope. Read everything within scope, including the audit '
            .'trail. Any write at all, including to the audit trail. No impersonation."',
        'SUPPORT' => 'ADR-0014 §5: "Explicitly scoped, time-bound. Diagnose within scope; every access '
            .'audited; no persistent grant. Not: anything outside scope; no grant without a named '
            .'approver and an expiry."',
    ];

    /**
     * @return list<Permission>
     */
    public static function permissionsFor(Role $role): array
    {
        // POS Cashier is Phase C. It holds nothing today, on purpose.
        if ($role->isDeferredToLaterPhase()) {
            return [];
        }

        // Group Manager: "Nothing by role". Self-grant and self-approval are
        // blocked at runtime by separation of duties (§6), not by withholding a
        // permission, so the permission set really is the whole catalogue.
        if ($role === Role::GroupManager) {
            return Permission::all();
        }

        // Auditor and Support are both "diagnose/read within scope", so the read
        // set is derived rather than enumerated. See Permission::readOnly().
        if ($role->isReadOnlyByDefinition() || $role === Role::Support) {
            return Permission::readOnly();
        }

        return match ($role) {
            Role::HotelManager => self::hotelManager(),
            Role::FrontDeskAgent => self::frontDeskAgent(),
            Role::ReservationAgent => self::reservationAgent(),
            Role::Housekeeping => self::housekeeping(),
            Role::Finance => self::finance(),
            Role::NightAuditor => self::nightAuditor(),
            Role::RevenueManager => self::revenueManager(),
            Role::ComplianceOfficer => self::complianceOfficer(),
            default => [],
        };
    }

    /**
     * The document text this mapping was transcribed from. Surfaced so a
     * reviewer can diff the code against the ADR without guessing.
     */
    public static function citationFor(Role $role): string
    {
        return self::CITATIONS[$role->value] ?? 'No ADR citation recorded.';
    }

    /**
     * @return list<Permission>
     */
    private static function hotelManager(): array
    {
        return self::allExcept([
            // "role and scope administration" is listed for Group Manager only,
            // and a Hotel Manager is explicitly barred from "granting own scope".
            // Granting scope to OTHERS is not listed either, so it is withheld.
            Permission::ScopeGrant,
            Permission::ScopeRevoke,

            // Separation of duties (§6): the approver of a refund must not be the
            // requester. A Hotel Manager may raise a refund within threshold but
            // may never be its approver.
            Permission::RefundApprove,
        ]);
    }

    /**
     * @return list<Permission>
     */
    private static function frontDeskAgent(): array
    {
        return [
            Permission::PropertyRead,
            Permission::ConfigurationRead,
            Permission::RoomTypeRead,
            Permission::RoomRead,
            Permission::RoomOccupancyTransition,
            Permission::ReservationRead,
            // "room transfer, stay extension" are reservation changes.
            Permission::ReservationCreate,
            Permission::ReservationUpdate,
            Permission::ReservationCheckIn,
            Permission::ReservationCheckOut,
            Permission::GuestRead,
            Permission::GuestIdentityRead,
            // "folio view and charge entry"
            Permission::FolioRead,
            Permission::FolioCharge,
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function reservationAgent(): array
    {
        return [
            Permission::PropertyRead,
            Permission::RoomTypeRead,
            Permission::RoomRead,
            Permission::ReservationRead,
            Permission::ReservationCreate,
            Permission::ReservationUpdate,
            Permission::ReservationCancel,
            // "guest lookup"
            Permission::GuestRead,
            Permission::GuestIdentityRead,
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function housekeeping(): array
    {
        return [
            Permission::PropertyRead,
            Permission::RoomTypeRead,
            Permission::RoomRead,
            // "room housekeeping status" + docs/STATE-MACHINES.md §B.2, which
            // names Housekeeping as an actor for OCCUPIED -> VACANT.
            Permission::RoomOccupancyTransition,
            Permission::RoomHousekeepingTransition,
            // "out-of-order raising" — NOT room_block, which §B.2 assigns to
            // Group Manager / Hotel Manager.
            Permission::RoomAvailabilityTransition,
            Permission::HousekeepingTaskRead,
            Permission::HousekeepingTaskCreate,
            Permission::HousekeepingTaskAssign,
            Permission::HousekeepingTaskTransition,
            Permission::HousekeepingTaskInspect,
            // NO guest.read, NO guest_identity.read, NO folio.*, NO posting.*,
            // NO reservation.*, NO rate_plan.*.
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function finance(): array
    {
        return [
            Permission::OrganizationRead,
            Permission::LegalEntityRead,
            Permission::PropertyRead,
            Permission::FolioRead,
            Permission::FolioCharge,
            Permission::PostingRead,
            Permission::PaymentRead,
            Permission::PaymentCreate,
            Permission::RefundRead,
            Permission::RefundCreate,
            Permission::RefundApprove,
            Permission::InvoiceRead,
            Permission::TaxConfigRead,
            Permission::ReportRead,
            // NO reservation.check_in / reservation.check_out ("Not: check-in/out"),
            // NO rate_plan.update, NO scope.*, NO night_audit.run.
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function nightAuditor(): array
    {
        return [
            Permission::PropertyRead,
            Permission::RoomTypeRead,
            Permission::RoomRead,
            // "raise out-of-order"
            Permission::RoomAvailabilityTransition,
            Permission::ReservationRead,
            Permission::NightAuditRun,
            // "reopen with approval". "Own approval of a reopen" is blocked at
            // runtime (T-017 AC-T-017-02), not by withholding this permission.
            Permission::NightAuditReopenApprove,
            Permission::PostingRead,
            Permission::FolioRead,
            // NO refund.*, NO rate_plan.update, NO scope.*.
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function revenueManager(): array
    {
        return [
            Permission::PropertyRead,
            Permission::RoomTypeRead,
            Permission::RoomRead,
            // "availability management"
            Permission::RoomAvailabilityTransition,
            Permission::RatePlanRead,
            Permission::RatePlanUpdate,
            Permission::RestrictionRead,
            Permission::RestrictionUpdate,
            Permission::ReservationRead,
            Permission::ReportRead,
            // NO refund.*, NO scope.*, NO reservation.check_in, NO posting.*.
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function complianceOfficer(): array
    {
        return [
            Permission::OrganizationRead,
            Permission::LegalEntityRead,
            Permission::PropertyRead,
            Permission::InvoiceRead,
            Permission::TaxConfigRead,
            Permission::ComplianceSubmissionRead,
            // "DLQ replay authorization"
            Permission::ComplianceSubmissionReplay,
            Permission::AuditTrailRead,
            // "review" only — may not modify posted financial records, so no
            // posting.write / folio.charge / payment.*.
            Permission::PostingRead,
            Permission::ReportRead,
            // NO reservation.* ("ordinary booking operations").
        ];
    }

    /**
     * @param  list<Permission>  $excluded
     * @return list<Permission>
     */
    private static function allExcept(array $excluded): array
    {
        return array_values(array_filter(
            Permission::all(),
            static fn (Permission $permission): bool => ! in_array($permission, $excluded, true),
        ));
    }
}
