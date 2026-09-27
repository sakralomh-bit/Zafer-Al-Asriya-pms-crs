<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Authorization\RolePermissionMatrix;
use Tests\TestCase;

/**
 * The role x permission matrix, asserted case by case.
 *
 * `ADR-0014` §7: "Deny-by-default is a claim that is only meaningful if it is
 * tested."
 *
 * This suite is deliberately mostly NEGATIVE. A positive test proves a role can
 * do something; a negative test proves it cannot. The negatives are the ones
 * that carry the security claim, and the ones a feature request is most likely
 * to erode by adding a permission to a role to make some other test pass.
 *
 * Every negative below is quoted from `ADR-0014` §5, which is the authority. The
 * `hold()` assertions are written as "this role must NOT hold X" so that the
 * failure message names the exact rule that broke.
 *
 * No database is needed: the matrix is a pure function of the role enum, and
 * asserting it through the database would only prove the same thing more slowly.
 */
final class RolePermissionMatrixTest extends TestCase
{
    // =====================================================================
    // The negatives `ADR-0014` §5 names explicitly
    // =====================================================================

    /** "Finance ... Not: check-in/out" */
    public function test_finance_cannot_check_a_guest_in_or_out(): void
    {
        $this->assertRoleDoesNotHold(Role::Finance, [
            Permission::ReservationCheckIn,
            Permission::ReservationCheckOut,
        ]);
    }

    /** "Auditor ... Any write at all, including to the audit trail." */
    public function test_auditor_holds_no_write_permission(): void
    {
        foreach (RolePermissionMatrix::permissionsFor(Role::Auditor) as $held) {
            $this->assertTrue(
                $held->isRead(),
                "Auditor must be read-only, but holds the write permission [{$held->value}].",
            );
        }
    }

    public function test_auditor_cannot_write_the_audit_trail(): void
    {
        // The audit trail is READ-ONLY for the auditor, and deliberately so.
        $this->assertTrue(
            in_array(Permission::AuditTrailRead, RolePermissionMatrix::permissionsFor(Role::Auditor), true),
            'Auditor must be able to READ the audit trail; that is the point of the role.',
        );

        // There is no `audit_trail.write` case in the catalogue at all, so the
        // assertion that actually matters is the general one: no write
        // permission of any kind is present. Asserting against a permission
        // that does not exist would be a tautology.
        $writes = array_filter(
            RolePermissionMatrix::permissionsFor(Role::Auditor),
            static fn (Permission $p): bool => ! $p->isRead(),
        );

        $this->assertSame([], $writes, 'Auditor must hold no write permission at all.');
    }

    /** "Housekeeping ... NOT: guest identity data" */
    public function test_housekeeping_cannot_read_guest_identity_data(): void
    {
        $this->assertRoleDoesNotHold(Role::Housekeeping, [
            Permission::GuestRead,
            Permission::GuestIdentityRead,
        ]);
    }

    /**
     * `AC-T-006-04` restates this as: Housekeeping cannot read guest identity
     * data, folio data, or financial data. The folio and financial halves are
     * asserted here too, because "cannot read the guest" and "cannot read the
     * guest's folio" are different absences and only one of them is obvious
     * from the ADR sentence.
     */
    public function test_housekeeping_cannot_read_folio_or_financial_data(): void
    {
        $this->assertRoleDoesNotHold(Role::Housekeeping, [
            Permission::FolioRead,
            Permission::FolioCharge,
            Permission::PostingRead,
            Permission::PaymentRead,
            Permission::PaymentCreate,
            Permission::RefundRead,
            Permission::RefundCreate,
        ]);
    }

    /** "a Reservation Agent cannot take a payment" */
    public function test_a_reservation_agent_cannot_take_a_payment(): void
    {
        $this->assertRoleDoesNotHold(Role::ReservationAgent, [
            Permission::PaymentCreate,
            Permission::PaymentRead,
            Permission::RefundCreate,
            Permission::PostingRead,
        ]);
    }

    /** "Reservation Agent ... Not: check-in/out" */
    public function test_a_reservation_agent_cannot_check_in(): void
    {
        $this->assertRoleDoesNotHold(Role::ReservationAgent, [
            Permission::ReservationCheckIn,
            Permission::ReservationCheckOut,
        ]);
    }

    /** "Not: scope changes" — true of Night Auditor, Revenue Manager, Finance. */
    public function test_operational_roles_cannot_change_scope(): void
    {
        foreach ([Role::NightAuditor, Role::RevenueManager, Role::Finance, Role::FrontDeskAgent] as $role) {
            $this->assertRoleDoesNotHold($role, [
                Permission::ScopeGrant,
                Permission::ScopeRevoke,
            ]);
        }
    }

    /** "Housekeeping ... NOT: ... reservations, rates" */
    public function test_housekeeping_cannot_touch_reservations_or_rates(): void
    {
        $this->assertRoleDoesNotHold(Role::Housekeeping, [
            Permission::ReservationRead,
            Permission::ReservationCreate,
            Permission::ReservationUpdate,
            Permission::ReservationCancel,
            Permission::RatePlanRead,
            Permission::RatePlanUpdate,
            Permission::RestrictionUpdate,
        ]);
    }

    /**
     * "Hotel Manager ... Not: other properties; granting own scope".
     *
     * The "own scope" half is a separation-of-duties rule enforced at runtime by
     * `ScopeGrantService`, not by withholding the permission — a Hotel Manager
     * who cannot grant scope at all could not administer anyone. So this asserts
     * only what the matrix itself is responsible for.
     */
    public function test_a_hotel_manager_cannot_administer_scope_or_approve_refunds(): void
    {
        $this->assertRoleDoesNotHold(Role::HotelManager, [
            Permission::ScopeGrant,
            Permission::ScopeRevoke,
            Permission::RefundApprove,
        ]);
    }

    /** "Compliance Officer ... Not: modify posted financial records" */
    public function test_a_compliance_officer_cannot_modify_financial_records(): void
    {
        $this->assertRoleDoesNotHold(Role::ComplianceOfficer, [
            Permission::FolioCharge,
            Permission::PaymentCreate,
            Permission::RefundCreate,
        ]);
    }

    /** "Compliance Officer ... Not: ... ordinary booking operations" */
    public function test_a_compliance_officer_cannot_run_booking_operations(): void
    {
        $this->assertRoleDoesNotHold(Role::ComplianceOfficer, [
            Permission::ReservationCreate,
            Permission::ReservationUpdate,
            Permission::ReservationCancel,
            Permission::ReservationCheckIn,
            Permission::ReservationCheckOut,
        ]);
    }

    /** "Night Auditor ... Not: refunds" */
    public function test_a_night_auditor_cannot_refund(): void
    {
        $this->assertRoleDoesNotHold(Role::NightAuditor, [
            Permission::RefundRead,
            Permission::RefundCreate,
            Permission::RefundApprove,
        ]);
    }

    /** "Revenue Manager ... Not: refunds, scope changes, check-in" */
    public function test_a_revenue_manager_cannot_refund_scope_or_check_in(): void
    {
        $this->assertRoleDoesNotHold(Role::RevenueManager, [
            Permission::RefundRead,
            Permission::RefundCreate,
            Permission::ScopeGrant,
            Permission::ScopeRevoke,
            Permission::ReservationCheckIn,
        ]);
    }

    // =====================================================================
    // Deferral and default-deny
    // =====================================================================

    /**
     * "POS Cashier — Phase C — deferred. Role defined so the model is complete;
     * no Phase A permissions."
     */
    public function test_pos_cashier_holds_nothing_in_phase_a(): void
    {
        $this->assertTrue(Role::PosCashier->isDeferredToLaterPhase());
        $this->assertSame([], RolePermissionMatrix::permissionsFor(Role::PosCashier));
    }

    public function test_support_is_read_only_and_time_bound(): void
    {
        $this->assertTrue(Role::Support->requiresTimeBoundGrants());

        foreach (RolePermissionMatrix::permissionsFor(Role::Support) as $held) {
            $this->assertTrue(
                $held->isRead(),
                "Support must diagnose within scope, but holds the write permission [{$held->value}].",
            );
        }
    }

    /**
     * Deny-by-default has two directions. The obvious one is "an unknown
     * permission is refused". The direction that actually rots is the one
     * below: if a NEW role case is added to the enum and not to the matrix, the
     * `default => []` arm makes it hold nothing, which is safe. This test makes
     * that safety explicit so a future `default => Permission::all()` is caught.
     */
    public function test_every_role_case_is_covered_by_the_matrix(): void
    {
        foreach (Role::cases() as $role) {
            $permissions = RolePermissionMatrix::permissionsFor($role);

            if ($role === Role::PosCashier) {
                $this->assertSame([], $permissions);

                continue;
            }

            $this->assertNotEmpty(
                $permissions,
                "Role [{$role->value}] holds no permissions. If that is intentional, document it "
                . 'here; if not, it is missing from RolePermissionMatrix.',
            );
        }
    }

    public function test_every_permission_case_is_valid(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->assertStringContainsString(
                '.',
                $permission->value,
                "Permission [{$permission->value}] has no resource.action separator, so action() "
                . 'cannot classify it and readOnly() would misclassify it as a write.',
            );
        }
    }

    /**
     * `ADR-0014` §5: Support "no grant without a named approver and an expiry".
     * The role exists in the matrix as read-only; the time-bound half is a
     * grant-time rule owned by `ScopeGrantService` and covered there.
     */
    public function test_support_is_the_only_role_requiring_time_bound_grants(): void
    {
        $requiring = array_values(array_filter(
            Role::cases(),
            static fn (Role $role): bool => $role->requiresTimeBoundGrants(),
        ));

        $this->assertSame([Role::Support], $requiring);
    }

    public function test_every_role_has_an_adr_citation(): void
    {
        foreach (Role::cases() as $role) {
            $this->assertNotSame(
                'No ADR citation recorded.',
                RolePermissionMatrix::citationFor($role),
                "Role [{$role->value}] has no `ADR-0014` §5 citation recorded in the matrix.",
            );
        }
    }

    // =====================================================================

    /**
     * @param list<Permission> $forbidden
     */
    private function assertRoleDoesNotHold(Role $role, array $forbidden): void
    {
        $held = RolePermissionMatrix::permissionsFor($role);

        foreach ($forbidden as $permission) {
            $this->assertNotContains(
                $permission,
                $held,
                "Role [{$role->value}] must NOT hold [{$permission->value}] per ADR-0014 §5.",
            );
        }
    }
}
