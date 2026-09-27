<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Contracts\Actor;
use App\Modules\Identity\Contracts\AuthorizesRequests;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserPropertyScope;
use App\Modules\Identity\Services\AuthorizationService;
use App\Modules\Identity\Services\PropertyScopeResolver;
use App\Modules\Identity\Services\ScopeGrantService;
use App\Modules\Organization\Models\Organization;
use App\Modules\Organization\Models\Property;
use App\Shared\Audit\AuditAction;
use App\Shared\Authorization\AccountNotActive;
use App\Shared\Authorization\PermissionDenied;
use App\Shared\Authorization\PropertyScopeDenied;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestFixtures;
use Tests\TestCase;

/**
 * The authorization decision point, exercised against a real database.
 *
 * `ADR-0014` §1 states the rule this whole system rests on:
 *
 *     Allow = Identity x Role x Resource x Action x Scope x Policy
 *
 *     "Every one of the six factors must evaluate true. A missing factor is a
 *      denial, not a pass."
 *
 * The unit-level matrix tests prove the Role/Resource/Action half. This suite
 * proves the Identity and Scope halves, which need rows, and — more importantly
 * — proves that the two halves are checked TOGETHER. A user with the right role
 * and no property grant must still be denied, and that combined case is the one
 * that a real breach looks like.
 *
 * `ADR-0014` §7: "Deny-by-default is a claim that is only meaningful if it is
 * tested." Every test here asserts a REFUSAL unless it is explicitly labelled
 * as the allow case.
 */
final class AuthorizationTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;

    private AuthorizesRequests $authorization;

    private PropertyScopeResolver $scopeResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorization = $this->app->make(AuthorizationService::class);
        $this->scopeResolver = $this->app->make(PropertyScopeResolver::class);

        $this->seedRolesAndPermissions();
    }

    // =====================================================================
    // The Scope factor
    // =====================================================================

    /**
     * `AC-T-003-02` / `ADR-0014` §3: a request for an ungranted property is an
     * explicit denial, NEVER an empty result set.
     *
     * Both halves of the ADR's reasoning are tested: the error CODE, and the
     * fact that the message does not disclose whether the property exists.
     */
    public function test_a_property_with_no_grant_is_denied_with_an_explicit_code(): void
    {
        $actor = $this->makeUser('agent@example.test', Role::GroupManager);
        $property = $this->makeProperty('Riyadh Central');

        // Deliberately NO grant: the actor holds every permission in the
        // catalogue and still cannot touch this property.
        try {
            $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);
            $this->fail('Expected a PropertyScopeDenied.');
        } catch (PropertyScopeDenied $denied) {
            $this->assertSame(ErrorCode::PropertyScopeDenied, $denied->errorCode);
        }
    }

    public function test_a_scope_denial_does_not_disclose_whether_the_property_exists(): void
    {
        $actor = $this->makeUser('agent2@example.test', Role::GroupManager);

        $existing = $this->makeProperty('Existing Hotel');
        $nonexistent = (string) Str::ulid();

        $deniedExisting = $this->captureScopeDenial($actor, $existing->id);
        $deniedMissing = $this->captureScopeDenial($actor, $nonexistent);

        // If these differ, the endpoint becomes a property-existence oracle.
        $this->assertSame(
            $deniedExisting->errorCode->value,
            $deniedMissing->errorCode->value,
        );
        $this->assertSame(
            $deniedExisting->getMessage(),
            $deniedMissing->getMessage(),
            'A denial must read identically whether or not the property exists, or it becomes an '
            .'existence oracle.',
        );
    }

    /**
     * The single most important test in this file.
     *
     * Role alone is NOT enough. A Group Manager holds every permission in the
     * catalogue — `RolePermissionMatrix` gives it `Permission::all()` — so a
     * grant check that were skipped entirely would let them into every property
     * in the group. This asserts they are not.
     */
    public function test_holding_every_permission_does_not_confer_scope(): void
    {
        $actor = $this->makeUser('boss@example.test', Role::GroupManager);
        $theirProperty = $this->makeProperty('Their Hotel');
        $someoneElses = $this->makeProperty('Someone Elses Hotel');

        $this->grantProperty($actor, $theirProperty);

        $this->authorization->authorize($actor, Permission::PropertyUpdate, $theirProperty->id);
        $this->addToAssertionCount(1);

        $this->expectException(PropertyScopeDenied::class);
        $this->authorization->authorize($actor, Permission::PropertyUpdate, $someoneElses->id);
    }

    public function test_a_revoked_grant_stops_working_immediately(): void
    {
        $actor = $this->makeUser('revokee@example.test', Role::HotelManager);
        $property = $this->makeProperty('Revocation Hotel');

        $this->grantProperty($actor, $property);
        $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);

        UserPropertyScope::query()
            ->where('user_id', $actor->id)
            ->where('property_id', $property->id)
            ->update(['revoked_at' => now()]);

        $this->scopeResolver->forget();

        $this->expectException(PropertyScopeDenied::class);
        $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);
    }

    /**
     * `ADR-0014` §5/§6: a Support grant is time-bound. An expired one is not a
     * grant, so the same code path denies it.
     */
    public function test_an_expired_grant_is_denied(): void
    {
        $actor = $this->makeUser('support@example.test', Role::Support);
        $property = $this->makeProperty('Support Hotel');

        UserPropertyScope::query()->create([
            'user_id' => $actor->id,
            'property_id' => $property->id,
            'granted_by_user_id' => $actor->id,
            'approved_by_user_id' => $actor->id,
            'granted_at' => Carbon::now()->subDays(2),
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->scopeResolver->forget();

        $this->expectException(PropertyScopeDenied::class);
        $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);
    }

    public function test_granted_property_ids_returns_only_live_grants(): void
    {
        $actor = $this->makeUser('mixed@example.test', Role::HotelManager);
        $live = $this->makeProperty('Live Hotel');
        $revoked = $this->makeProperty('Revoked Hotel');
        $expired = $this->makeProperty('Expired Hotel');

        $this->grantProperty($actor, $live);
        $this->grantProperty($actor, $revoked);
        $this->grantProperty($actor, $expired);

        UserPropertyScope::query()
            ->where('user_id', $actor->id)
            ->where('property_id', $revoked->id)
            ->update(['revoked_at' => now()]);

        UserPropertyScope::query()
            ->where('user_id', $actor->id)
            ->where('property_id', $expired->id)
            ->update(['expires_at' => Carbon::now()->subDay()]);

        $this->assertSame([$live->id], $actor->grantedPropertyIds());
    }

    // =====================================================================
    // The Identity factor
    // =====================================================================

    public function test_a_suspended_user_is_denied_even_with_a_grant_and_every_permission(): void
    {
        $actor = $this->makeUser('suspended@example.test', Role::GroupManager);
        $property = $this->makeProperty('Suspended Hotel');

        $this->grantProperty($actor, $property);
        $actor->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        try {
            $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);
            $this->fail('Expected an AccountNotActive.');
        } catch (AccountNotActive $denied) {
            $this->assertSame(ErrorCode::AccountSuspended, $denied->errorCode);
        }
    }

    /**
     * An INACTIVE identity is refused BEFORE the scope and permission checks.
     *
     * Ordering is a security property, not a style choice: a suspended user
     * probing a property id they were never granted must not learn anything
     * from the shape of the error.
     */
    public function test_an_inactive_identity_is_refused_before_scope_is_evaluated(): void
    {
        $actor = $this->makeUser('disabled@example.test', Role::Housekeeping);
        $property = $this->makeProperty('Never Granted Hotel');

        $actor->forceFill(['status' => User::STATUS_DISABLED])->save();

        try {
            $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);
            $this->fail('Expected an AccountNotActive.');
        } catch (AccountNotActive $denied) {
            $this->assertNotSame(
                ErrorCode::PropertyScopeDenied,
                $denied->errorCode,
                'An inactive identity must be refused before the scope check, so a suspended user '
                .'cannot use the error code to discover which properties exist.',
            );
        }
    }

    public function test_an_invited_user_is_not_yet_active(): void
    {
        $actor = $this->makeUser('invited@example.test', Role::HotelManager);
        $property = $this->makeProperty('Invited Hotel');

        $this->grantProperty($actor, $property);
        $actor->forceFill(['status' => User::STATUS_INVITED])->save();

        $this->assertFalse($actor->isActive());
        $this->expectException(AccountNotActive::class);
        $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);
    }

    // =====================================================================
    // The Role factor, combined with scope
    // =====================================================================

    /**
     * Scope without permission is also a denial. A Housekeeping user granted to
     * a property still cannot approve refunds there.
     */
    public function test_a_granted_user_is_still_denied_a_permission_their_role_lacks(): void
    {
        $actor = $this->makeUser('hk@example.test', Role::Housekeeping);
        $property = $this->makeProperty('Housekeeping Hotel');

        $this->grantProperty($actor, $property);

        $this->expectException(PermissionDenied::class);
        $this->authorization->authorize($actor, Permission::RefundApprove, $property->id);
    }

    /**
     * `AC-T-006-04`: Housekeeping cannot read guest identity data, even inside a
     * property they are fully granted to. This is the isolation the property
     * grant is most likely to be assumed to provide and does not.
     */
    public function test_housekeeping_cannot_read_guest_identity_within_its_own_property(): void
    {
        $actor = $this->makeUser('hk2@example.test', Role::Housekeeping);
        $property = $this->makeProperty('Housekeeping Scope Hotel');

        $this->grantProperty($actor, $property);

        $this->expectException(PermissionDenied::class);
        $this->authorization->authorize($actor, Permission::GuestIdentityRead, $property->id);
    }

    // =====================================================================
    // The organization-level exception
    // =====================================================================

    /**
     * `ADR-0007`: "No query executes without a property scope (except
     * organization-level admin)." Creating the FIRST property is the case that
     * exception exists for, because by definition the actor has no property to
     * be granted.
     */
    public function test_an_actor_with_a_live_grant_may_act_at_organization_level(): void
    {
        $actor = $this->makeUser('founder@example.test', Role::GroupManager);
        $organization = $this->makeOrganization();
        $property = $this->makeProperty('First Hotel', $organization);

        $this->grantProperty($actor, $property);

        $this->authorization->authorizeOrganizationLevel(
            $actor,
            Permission::PropertyCreate,
            $organization->id,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * The exception is not a bootstrap hole. An actor granted nothing cannot
     * create a property and thereby grant themselves one — which is the exact
     * chain the exception must not permit.
     */
    public function test_an_actor_with_no_grants_cannot_bootstrap_at_organization_level(): void
    {
        $actor = $this->makeUser('nobody@example.test', Role::GroupManager);
        $organization = $this->makeOrganization();

        try {
            $this->authorization->authorizeOrganizationLevel(
                $actor,
                Permission::PropertyCreate,
                $organization->id,
            );
            $this->fail('Expected the organization-level exception to refuse an actor with no grants.');
        } catch (PermissionDenied $denied) {
            $this->assertSame(ErrorCode::PermissionDenied, $denied->errorCode);
        }
    }

    public function test_the_organization_level_exception_still_requires_the_permission(): void
    {
        $actor = $this->makeUser('hk3@example.test', Role::Housekeeping);
        $organization = $this->makeOrganization();
        $property = $this->makeProperty('Some Hotel', $organization);

        $this->grantProperty($actor, $property);

        $this->expectException(PermissionDenied::class);
        $this->authorization->authorizeOrganizationLevel(
            $actor,
            Permission::PropertyCreate,
            $organization->id,
        );
    }

    // =====================================================================
    // Denials are auditable
    // =====================================================================

    /**
     * `ADR-0014` §7: deny-by-default must be PROVABLE. A denial nobody can
     * inspect is not provable, so every refusal is a row.
     */
    public function test_every_denial_is_audited(): void
    {
        $actor = $this->makeUser('auditee@example.test', Role::Housekeeping);
        $property = $this->makeProperty('Audit Hotel');

        $this->grantProperty($actor, $property);

        try {
            $this->authorization->authorize(
                $actor,
                Permission::RefundApprove,
                $property->id,
                subjectType: 'refund',
                subjectId: 'refund-1',
                correlationId: 'corr-denial-1',
            );
        } catch (PermissionDenied) {
            // expected
        }

        $row = DB::table('audit_events')
            ->where('actor_user_id', $actor->id)
            ->where('action', AuditAction::AuthorizationDenied->value)
            ->first();

        $this->assertNotNull($row, 'A denial must leave an audit row.');
        $this->assertStringStartsWith('DENIED', (string) $row->result);
        $this->assertSame('corr-denial-1', $row->correlation_id);
        $this->assertSame('refund', $row->subject_type);
        $this->assertStringContainsString('refund.approve', (string) $row->context);
    }

    public function test_a_scope_denial_is_also_audited(): void
    {
        $actor = $this->makeUser('auditee2@example.test', Role::GroupManager);
        $property = $this->makeProperty('Ungranted Audit Hotel');

        try {
            $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);
        } catch (PropertyScopeDenied) {
            // expected
        }

        $this->assertSame(
            1,
            DB::table('audit_events')
                ->where('actor_user_id', $actor->id)
                ->where('action', AuditAction::AuthorizationDenied->value)
                ->count(),
            'A scope denial must be audited just as a permission denial is.',
        );
    }

    /**
     * `SEC-004`: there is no superuser, no admin flag, and no scope bypass.
     *
     * This asserts the COLUMNS, not just the behaviour. A behavioural test
     * would still pass if someone added `is_superadmin` and left it defaulting
     * to false; the column assertion fails the moment the escape hatch exists,
     * which is the point.
     */
    public function test_there_is_no_superuser_or_bypass_column(): void
    {
        $columns = Schema::getColumnListing('users');

        foreach ([
            'is_superadmin', 'is_super_user', 'superadmin',
            'is_admin', 'bypass_scope', 'skip_authorization', 'is_root',
        ] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                "The users table must not have a [{$forbidden}] column (SEC-004, ADR-0014 §2).",
            );
        }
    }

    /**
     * `AC-T-003-03`: Group Manager's reach across all ten properties is TEN
     * EXPLICIT ROWS, not a flag and not a shortcut.
     */
    public function test_group_manager_multi_property_reach_is_ten_explicit_grants(): void
    {
        $actor = $this->makeUser('group@example.test', Role::GroupManager);
        $organization = $this->makeOrganization();

        $properties = [];

        for ($i = 1; $i <= 10; $i++) {
            $properties[] = $this->makeProperty("Hotel {$i}", $organization);
            $this->grantProperty($actor, $properties[$i - 1]);
        }

        $this->assertCount(10, $actor->grantedPropertyIds());
        $this->assertSame(10, UserPropertyScope::query()->where('user_id', $actor->id)->count());

        foreach ($properties as $property) {
            $this->authorization->authorize($actor, Permission::PropertyRead, $property->id);
        }

        $this->addToAssertionCount(1);
    }

    // =====================================================================
    // Scope grants: separation of duties and time bounds
    // =====================================================================

    /**
     * `AC-T-003-04` / `ADR-0014` §6, rated Critical: a user cannot grant
     * themselves access.
     */
    public function test_a_user_cannot_grant_themselves_a_property(): void
    {
        $actor = $this->makeUser('selfgrant@example.test', Role::GroupManager);
        $property = $this->makeProperty('Self Grant Hotel');
        $somewhereElse = $this->makeProperty('Anchor Hotel');

        // A live grant elsewhere, so they legitimately hold scope.grant and the
        // denial is the SELF-grant rule and not merely a missing permission.
        $this->grantProperty($actor, $somewhereElse);

        $service = $this->app->make(ScopeGrantService::class);

        try {
            $service->grant(
                $actor,
                $actor,
                $property->id,
                'because I said so',
                $actor->id,
                null,
                'corr-self-1',
            );
            $this->fail('Expected a self-grant to be refused.');
        } catch (DomainFailure $denied) {
            $this->assertSame(ErrorCode::PermissionDenied, $denied->errorCode);
        }

        $this->assertSame(
            0,
            UserPropertyScope::query()
                ->where('user_id', $actor->id)
                ->where('property_id', $property->id)
                ->count(),
            'A refused self-grant must not create a row.',
        );

        // `ADR-0014` rates self-grant Critical and requires it audited. Before
        // the ordering fix this row was never written: the actor has no grant
        // for the target property, so authorizing first failed with
        // PROPERTY_SCOPE_DENIED and the self-grant branch was unreachable.
        $this->assertSame(
            1,
            DB::table('audit_events')
                ->where('actor_user_id', $actor->id)
                ->where('action', AuditAction::ScopeSelfGrantDenied->value)
                ->count(),
            'A self-grant attempt must be audited as a self-grant attempt, distinct from an '
            .'ordinary ungranted-property request.',
        );
    }

    /**
     * `ADR-0014` §5: "no grant without a named approver and an expiry" — a
     * Support grant missing either is refused rather than created and noticed
     * later.
     */
    public function test_a_support_grant_without_an_expiry_is_refused(): void
    {
        $grantor = $this->makeUser('grantor@example.test', Role::GroupManager);
        $support = $this->makeUser('support2@example.test', Role::Support);
        $property = $this->makeProperty('Support Grant Hotel');

        $this->grantProperty($grantor, $property);

        $service = $this->app->make(ScopeGrantService::class);

        $this->expectException(DomainFailure::class);

        $service->grant(
            $grantor,
            $support,
            $property->id,
            'diagnosing an incident',
            $grantor->id,
            null, // no expiry
            'corr-support-1',
        );
    }

    public function test_a_support_grant_without_a_named_approver_is_refused(): void
    {
        $grantor = $this->makeUser('grantor2@example.test', Role::GroupManager);
        $support = $this->makeUser('support3@example.test', Role::Support);
        $property = $this->makeProperty('Support Grant Hotel 2');

        $this->grantProperty($grantor, $property);

        $service = $this->app->make(ScopeGrantService::class);

        $this->expectException(DomainFailure::class);

        $service->grant(
            $grantor,
            $support,
            $property->id,
            'diagnosing an incident',
            null, // no named approver
            Carbon::now()->addHour(),
            'corr-support-2',
        );
    }

    public function test_a_time_bound_support_grant_with_an_approver_succeeds_and_then_expires(): void
    {
        $grantor = $this->makeUser('grantor3@example.test', Role::GroupManager);
        $approver = $this->makeUser('approver@example.test', Role::ComplianceOfficer);
        $support = $this->makeUser('support4@example.test', Role::Support);
        $property = $this->makeProperty('Support Grant Hotel 3');

        $this->grantProperty($grantor, $property);
        $this->grantProperty($approver, $property);

        $service = $this->app->make(ScopeGrantService::class);

        $service->grant(
            $grantor,
            $support,
            $property->id,
            'diagnosing an incident',
            $approver->id,
            Carbon::now()->addHour(),
            'corr-support-3',
        );

        $this->scopeResolver->forget();
        $this->authorization->authorize($support, Permission::PropertyRead, $property->id);
        $this->addToAssertionCount(1);

        // Wind the grant forward past its expiry and the same call is denied.
        UserPropertyScope::query()
            ->where('user_id', $support->id)
            ->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->scopeResolver->forget();

        $this->expectException(PropertyScopeDenied::class);
        $this->authorization->authorize($support, Permission::PropertyRead, $property->id);
    }

    public function test_a_revoke_retains_the_row_rather_than_deleting_it(): void
    {
        $grantor = $this->makeUser('revoker@example.test', Role::GroupManager);
        $subject = $this->makeUser('subject@example.test', Role::HotelManager);
        $property = $this->makeProperty('Retained Row Hotel');

        $this->grantProperty($grantor, $property);
        $this->grantProperty($subject, $property);

        $this->app->make(ScopeGrantService::class)->revoke(
            $grantor,
            $subject,
            $property->id,
            'left the company',
            'corr-revoke-1',
        );

        $this->assertSame(
            1,
            UserPropertyScope::query()
                ->where('user_id', $subject->id)
                ->where('property_id', $property->id)
                ->count(),
            'A revoked grant is retained so the trail can explain historical access.',
        );

        $this->assertNotNull(
            UserPropertyScope::query()
                ->where('user_id', $subject->id)
                ->where('property_id', $property->id)
                ->first()
                ?->revoked_at,
        );
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * Capture the scope denial for a property, so two denials can be compared.
     *
     * `ADR-0014` §3 requires a denial rather than an empty result, and requires
     * it not to disclose whether the property exists. Comparing the two
     * denials is the only way to assert that second half.
     */
    private function captureScopeDenial(Actor $actor, string $propertyId): DomainFailure
    {
        try {
            $this->authorization->authorize($actor, Permission::PropertyRead, $propertyId);
        } catch (DomainFailure $denied) {
            return $denied;
        }

        $this->fail('Expected a scope denial.');
    }

    private function seedRolesAndPermissions(): void
    {
        $this->seed(AuthorizationCatalogueSeeder::class);
    }
}
