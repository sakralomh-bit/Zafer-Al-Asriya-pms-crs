<?php

declare(strict_types=1);

namespace App\Modules\Identity\Authorization;

/**
 * The twelve roles named in `D-001` and specified in `ADR-0014` §5.
 *
 * A role is a NAME, not a capability set. What a role may do is
 * `RolePermissionMatrix`. Keeping the two apart is what lets `ADR-0014` §5's
 * per-role statements be encoded once and tested as a matrix rather than
 * re-derived from a permissions table that anyone can edit.
 */
enum Role: string
{
    case GroupManager = 'GROUP_MANAGER';
    case HotelManager = 'HOTEL_MANAGER';
    case FrontDeskAgent = 'FRONT_DESK_AGENT';
    case ReservationAgent = 'RESERVATION_AGENT';
    case Housekeeping = 'HOUSEKEEPING';
    case Finance = 'FINANCE';
    case NightAuditor = 'NIGHT_AUDITOR';
    case PosCashier = 'POS_CASHIER';
    case RevenueManager = 'REVENUE_MANAGER';
    case ComplianceOfficer = 'COMPLIANCE_OFFICER';
    case Auditor = 'AUDITOR';
    case Support = 'SUPPORT';

    public function label(): string
    {
        return match ($this) {
            self::GroupManager => 'Group Manager',
            self::HotelManager => 'Hotel Manager',
            self::FrontDeskAgent => 'Front Desk Agent',
            self::ReservationAgent => 'Reservation Agent',
            self::Housekeeping => 'Housekeeping',
            self::Finance => 'Finance',
            self::NightAuditor => 'Night Auditor',
            self::PosCashier => 'POS Cashier',
            self::RevenueManager => 'Revenue Manager',
            self::ComplianceOfficer => 'Compliance Officer',
            self::Auditor => 'Auditor',
            self::Support => 'Support',
        };
    }

    /**
     * `ADR-0014` §5 describes POS Cashier as "Phase C — deferred. Role defined so
     * the model is complete; no Phase A permissions." The role exists so the
     * twelve-role model is whole; it grants nothing until Phase C.
     */
    public function isDeferredToLaterPhase(): bool
    {
        return $this === self::PosCashier;
    }

    /**
     * `ADR-0014` §6: a Support grant must be time-bound and carry a named
     * approver. This is the only role for which that is mandatory.
     */
    public function requiresTimeBoundGrants(): bool
    {
        return $this === self::Support;
    }

    /**
     * `ADR-0014` §5: "Auditor — Read-only scope ... Any write at all".
     * Derived rather than listed, so a new write permission cannot be granted to
     * the Auditor by omission.
     */
    public function isReadOnlyByDefinition(): bool
    {
        return $this === self::Auditor;
    }
}
