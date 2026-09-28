<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth\Mfa;

use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Authorization\Role;

/**
 * Which roles must satisfy a second factor.
 *
 * DERIVED FROM `ADR-0014` §5, NOT INVENTED AND NOT RANKED.
 *
 * The rule is mechanical and is stated once so it can be checked against the
 * source rather than taken on trust:
 *
 *   > A role requires MFA if `ADR-0014` §5 gives it the power to perform one of
 *   > the seven canonical step-up operations, or any operation that changes
 *   > AUTHORIZATION, MONEY, or GUEST IDENTITY DATA.
 *
 * Applied to the twelve roles of §5, that yields eight. The remaining four are
 * each explicitly EXCLUDED from those powers by §5 itself, which is why they are
 * absent rather than merely judged low-risk:
 *
 *   | Role              | §5's exclusion                                                                 |
 *   |-------------------|--------------------------------------------------------------------------------|
 *   | Front Desk Agent  | "Refunds, rate configuration, night audit, scope administration, configuration"  |
 *   | Reservation Agent | "Check-in/out, refunds, configuration, financial posting"                          |
 *   | Housekeeping      | "**Guest identity data**, folio and financial data, reservations, rates"            |
 *   | POS Cashier       | "No Phase A permissions" — deferred to Phase C                                     |
 *
 * Two inclusions deserve their reasoning stated, because both look like
 * judgement calls and neither is:
 *
 *   - **Auditor.** §5 grants read access to everything in scope, explicitly
 *     "including the audit trail". A trail is only as good as the integrity of
 *     the identity that reads it, and an unauthenticated-factor auditor is an
 *     attractive target precisely because auditing is high-value and low-noise.
 *     `ADR-0014` denies the Auditor every write; MFA is what protects the reads.
 *   - **Support.** §5 grants "diagnose within scope; every access audited" and
 *     forbids any grant "without a named approver and an expiry". A time-bound
 *     grant is not a small grant: it reaches guest data, it is the role most
 *     likely to be socially engineered, and its named approver is an
 *     accountability `C-10` has not supplied. Included on the POWER it holds.
 *
 * NOTHING HERE IS A BUSINESS PRIORITY ORDER. A role absent from the required set
 * is not a role judged unimportant; it is a role that `ADR-0014` §5 says cannot
 * perform the operations MFA protects.
 *
 * The set is read from `SecurityPolicy` (`mfa.required_roles`) so an operator
 * can tighten it for a deployment. `MfaMethod` in the same configuration records
 * the documented TOTP-primary / passkey-preferred direction.
 *
 * @see docs/ADR/0014-authorization-model-role-plus-property-scope.md §5
 */
final readonly class MfaPolicy
{
    /** @var list<Role> */
    private array $required;

    /**
     * @param  list<string>  $requiredRoleValues
     */
    public function __construct(
        private MfaMethod $primary,
        private MfaMethod $preferred,
        private array $requiredRoleValues,
    ) {
        $this->required = array_values(array_map(
            static fn (string $role): Role => Role::from($role),
            $requiredRoleValues,
        ));
    }

    public static function fromPolicy(SecurityPolicy $policy): self
    {
        return new self(
            $policy->mfaPrimaryMethod(),
            $policy->mfaPreferredMethod(),
            $policy->mfaRequiredRoles(),
        );
    }

    public function primaryMethod(): MfaMethod
    {
        return $this->primary;
    }

    public function preferredMethod(): MfaMethod
    {
        return $this->preferred;
    }

    /**
     * Must this role satisfy a second factor before it can be used?
     */
    public function isRequiredFor(Role $role): bool
    {
        return in_array($role, $this->required, true);
    }

    /**
     * Does this identity hold any role that requires MFA?
     *
     * A user may hold SEVERAL roles. Requiring MFA because ANY held role needs it
     * is the safe reading of the matrix: a person who is both Front Desk Agent
     * and Finance is a person who can refund, and "they signed in as the lower
     * role" is not a property an identity can be required to have.
     *
     * @param  list<Role>  $roles
     */
    public function isRequiredForAnyOf(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->isRequiredFor($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Role>
     */
    public function requiredRoles(): array
    {
        return $this->required;
    }
}
