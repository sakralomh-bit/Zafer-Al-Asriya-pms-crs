<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserPropertyScope;
use App\Modules\Identity\Services\PropertyScopeResolver;
use App\Modules\Identity\Services\ScopeGrantService;
use App\Modules\Organization\Models\Property;
use App\Shared\Audit\AuditAction;
use App\Shared\Authorization\PropertyScopeDenied;
use App\Shared\Domain\DomainFailure;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestFixtures;
use Tests\TestCase;

/**
 * `AC-T-004-04` — "A session is revoked when the user's role or property scope
 * changes, without waiting for expiry."
 *
 * This suite exists because the criterion was previously UNEVIDENCED in the way
 * that mattered. `SessionRevoker` had four tests of its own, but every one of
 * them called `revokeAllFor()` DIRECTLY. Not one test ever changed a scope and
 * then asked whether the session survived — so the revoker could be perfect and
 * the criterion still unmet, and nothing would have failed.
 *
 * The wiring it tests lives in `ScopeGrantService`, because those two methods
 * are the only property-scope write paths in the system. A test that exercises
 * the revoker in isolation cannot distinguish "revocation works" from "revocation
 * is ever triggered", and only the second one satisfies the criterion.
 *
 * ROLE ASSIGNMENT IS DELIBERATELY NOT COVERED. No role-assignment write path
 * exists in the repository to call a revoker from — only the `User::roles()`
 * relation and the `UserRole` model. Inventing one to make this suite tidier
 * would be inventing a security capability, so the half of `AC-T-004-04` that
 * says "role" stays open and is recorded as a blocker rather than faked.
 *
 * The session driver is switched to `database` for this suite, which is what
 * `config/session.php` and `.env` actually select. `phpunit.xml` defaults to
 * `SESSION_DRIVER=array`, and against that driver revocation cannot happen at
 * all — which is why the real configuration is the one worth testing.
 */
final class ScopeChangeSessionInvalidationTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);

        $this->seed(AuthorizationCatalogueSeeder::class);
    }

    // =====================================================================
    // 1 + 2. The scope change itself invalidates the subject's sessions
    // =====================================================================

    /**
     * REVOKE: the narrowing case, and the one the criterion is really about.
     *
     * Without revocation the next request is already denied by
     * `PropertyScopeResolver` — but the session is still an authenticated
     * session. It still carries an actor, still satisfies any endpoint that
     * only asks "is this authenticated", and still reaches code that was never
     * given a scope to check. That gap is the difference between "the next
     * check fails" and "there is no next check, because there is no session".
     */
    public function test_revoking_a_scope_invalidates_the_subjects_live_sessions(): void
    {
        [$grantor, $subject, $property] = $this->twoParties();
        $this->grantProperty($subject, $property);
        $this->openSessionsFor($subject, 2);

        $this->service()->revoke($grantor, $subject, $property->id, 'no longer needed', 'corr-rev-1');

        $this->assertSame(0, $this->sessionCountFor($subject), 'The subjects sessions must not survive a scope revocation.');
    }

    /**
     * GRANT: the widening case.
     *
     * Nothing in the session is stale after a grant — no permission is cached
     * in it — but the criterion says a session is revoked when the scope
     * *changes*, so the session is cut here too and the widened grant takes
     * effect under a sign-in that happened after the change.
     */
    public function test_granting_a_scope_invalidates_the_subjects_live_sessions(): void
    {
        [$grantor, $subject, $property] = $this->twoParties();
        $this->openSessionsFor($subject, 1);

        $this->service()->grant(
            $grantor,
            $subject,
            $property->id,
            'covering the new hotel',
            $grantor->id,
            null,
            'corr-grant-1',
        );

        $this->assertSame(0, $this->sessionCountFor($subject), 'The subjects sessions must not survive a scope grant.');
    }

    // =====================================================================
    // 3. Authorization semantics are unchanged
    // =====================================================================

    /**
     * Revocation removes the SESSION, not the AUTHORIZATION.
     *
     * These are different systems and a plausible way to break this feature is
     * to make the grant disappear along with the session. So: the scope row is
     * retained, the subject's authority is recomputed by the unchanged engine,
     * and the newly-ungranted property is refused with the documented code
     * rather than with anything session-shaped.
     */
    public function test_revocation_does_not_alter_authorization_semantics(): void
    {
        [$grantor, $subject, $property] = $this->twoParties();
        $elsewhere = $this->makeProperty('Untouched Hotel');
        $this->grantProperty($subject, $property);
        $this->grantProperty($subject, $elsewhere);
        $this->openSessionsFor($subject, 1);

        $resolver = $this->app->make(PropertyScopeResolver::class);

        $this->assertTrue($resolver->hasGrant($subject, (string) $property->id), 'Precondition: the subject holds the scope.');

        $this->service()->revoke($grantor, $subject, $property->id, 'no longer needed', 'corr-rev-2');

        $this->assertSame(
            0,
            $this->sessionCountFor($subject),
            'The session is gone.',
        );

        $this->assertSame(
            1,
            UserPropertyScope::query()
                ->where('user_id', $subject->id)
                ->where('property_id', $property->id)
                ->whereNotNull('revoked_at')
                ->count(),
            'The scope row is retained and marked revoked; a revocation must not delete the trail.',
        );

        $this->assertFalse(
            $this->app->make(PropertyScopeResolver::class)->hasGrant($subject, (string) $property->id),
            'The revoked property is refused by the unchanged scope engine.',
        );

        $this->assertTrue(
            $this->app->make(PropertyScopeResolver::class)->hasGrant($subject, (string) $elsewhere->id),
            'The untouched property is still granted. Revocation of one grant must not revoke them all.',
        );

        $this->expectException(PropertyScopeDenied::class);

        $this->app->make(PropertyScopeResolver::class)->assertGranted($subject, (string) $property->id);
    }

    /**
     * NO PRIVILEGE ESCALATION: revocation must not be usable to take authority
     * away from someone, or to hand it to someone else, as a side effect.
     *
     * The grantor keeps their own session. If the wiring revoked the ACTOR's
     * sessions as well, an administrator granting access would log themselves
     * out — a self-inflicted denial of service on the one action the system
     * exists to support.
     */
    public function test_the_actor_s_session_survives_the_change_they_made(): void
    {
        [$grantor, $subject, $property] = $this->twoParties();
        $this->grantProperty($subject, $property);
        $this->openSessionsFor($grantor, 1);
        $this->openSessionsFor($subject, 1);

        $this->service()->revoke($grantor, $subject, $property->id, 'no longer needed', 'corr-rev-3');

        $this->assertSame(1, $this->sessionCountFor($grantor), 'The actor made the change; their own session is unaffected.');
        $this->assertSame(0, $this->sessionCountFor($subject), 'The subject is the one whose access changed.');
    }

    // =====================================================================
    // 4. Audit behaviour is unchanged and still complete
    // =====================================================================

    /**
     * `ADR-0016`: the change is recorded whether or not the session cut
     * succeeds, and the event names are the ones the API contract already fixes.
     *
     * This is the ordering constraint made visible: revocation happens AFTER
     * the audit row is written, so a revocation that fails loudly still leaves
     * the scope change on the record. An unaudited scope change would be worse
     * than a session that outlived it.
     */
    public function test_the_scope_change_is_audited_and_the_subjects_sessions_are_still_cut(): void
    {
        [$grantor, $subject, $property] = $this->twoParties();
        $this->grantProperty($subject, $property);
        $this->openSessionsFor($subject, 1);

        $this->service()->revoke($grantor, $subject, $property->id, 'no longer needed', 'corr-rev-4');

        $row = DB::table('audit_events')
            ->where('action', AuditAction::ScopeRevoked->value)
            ->where('correlation_id', 'corr-rev-4')
            ->first();

        $this->assertNotNull($row, 'The revocation must be audited.');
        $this->assertSame('scope_revoke', $row->source);
        $this->assertSame((string) $grantor->id, (string) $row->actor_user_id);
        $this->assertSame((string) $subject->id, (string) $row->subject_id);
        $this->assertSame((string) $property->id, (string) $row->property_id);

        $this->assertSame(0, $this->sessionCountFor($subject), 'The session is cut as well.');
    }

    /**
     * A grant is audited the same way, and still cuts the session.
     */
    public function test_a_grant_is_audited_and_the_subjects_sessions_are_still_cut(): void
    {
        [$grantor, $subject, $property] = $this->twoParties();
        $this->openSessionsFor($subject, 1);

        $this->service()->grant(
            $grantor,
            $subject,
            $property->id,
            'covering the new hotel',
            $grantor->id,
            null,
            'corr-grant-2',
        );

        $row = DB::table('audit_events')
            ->where('action', AuditAction::ScopeGranted->value)
            ->where('correlation_id', 'corr-grant-2')
            ->first();

        $this->assertNotNull($row, 'The grant must be audited.');
        $this->assertSame('scope_grant', $row->source);
        $this->assertSame((string) $subject->id, (string) $row->subject_id);

        $this->assertSame(0, $this->sessionCountFor($subject), 'The session is cut as well.');
    }

    // =====================================================================
    // 5. Unrelated changes cause nothing unintended
    // =====================================================================

    /**
     * A change to ONE subject must not touch ANYBODY ELSE'S sessions.
     *
     * `SessionRevoker` deletes by `user_id`, so a missing predicate here would
     * log out every user in the system on any single scope change. The count is
     * asserted on a third party who had nothing to do with it.
     */
    public function test_a_scope_change_does_not_touch_an_unrelated_users_sessions(): void
    {
        [$grantor, $subject, $property] = $this->twoParties();
        $bystander = $this->makeUser('bystander@example.test', Role::FrontDeskAgent);
        $this->grantProperty($subject, $property);

        $this->openSessionsFor($subject, 2);
        $this->openSessionsFor($bystander, 3);
        $this->openSessionsFor($grantor, 1);

        $this->service()->revoke($grantor, $subject, $property->id, 'no longer needed', 'corr-rev-5');

        $this->assertSame(0, $this->sessionCountFor($subject));
        $this->assertSame(3, $this->sessionCountFor($bystander), 'An unrelated users sessions must be untouched.');
        $this->assertSame(1, $this->sessionCountFor($grantor), 'The actor is unaffected.');
    }

    /**
     * A revoke of a grant that is NOT ACTIVE changes nothing, so it must cut
     * nothing.
     *
     * `ScopeGrantService::revoke()` returns early when the scope is missing or
     * already inactive. Wiring that wrongly — for instance by revoking sessions
     * before the early return — would let any user who can name a property and
     * an account log that account out repeatedly, with no state change to show
     * for it. That is a denial of service against a colleague, so the
     * no-op is asserted rather than assumed.
     */
    public function test_revoking_a_grant_that_is_not_active_cuts_nothing(): void
    {
        [$grantor, $subject, $property] = $this->twoParties();
        $this->openSessionsFor($subject, 2);

        // Never granted at all.
        $this->service()->revoke($grantor, $subject, $property->id, 'mistake', 'corr-rev-6a');

        $this->assertSame(2, $this->sessionCountFor($subject), 'Revoking a grant that does not exist is a no-op, not a logout.');

        // Granted, then already revoked once.
        $this->grantProperty($subject, $property);
        $this->service()->revoke($grantor, $subject, $property->id, 'first revocation', 'corr-rev-6b');
        $this->openSessionsFor($subject, 2);
        $this->service()->revoke($grantor, $subject, $property->id, 'second revocation', 'corr-rev-6c');

        $this->assertSame(2, $this->sessionCountFor($subject), 'Revoking an already-inactive grant is a no-op, not a logout.');
    }

    /**
     * A REFUSED change must not cut the subject's sessions.
     *
     * The self-grant rule is checked before any write, and it is rated
     * Critical. If revocation were wired ahead of that check, a user could log
     * THEMSELVES out with a self-grant attempt — and more importantly the
     * audit row for the denial would be the only trace, with a session cut
     * that no authorization decision called for.
     */
    public function test_a_refused_self_grant_cuts_no_sessions(): void
    {
        $actor = $this->makeUser('selfgrant@example.test', Role::GroupManager);
        $property = $this->makeProperty('Self Grant Hotel');
        $somewhereElse = $this->makeProperty('Anchor Hotel');
        $this->grantProperty($actor, $somewhereElse);
        $this->openSessionsFor($actor, 2);

        try {
            $this->service()->grant(
                $actor,
                $actor,
                $property->id,
                'because I said so',
                $actor->id,
                null,
                'corr-self-1',
            );
            $this->fail('Expected a self-grant to be refused.');
        } catch (DomainFailure) {
            // Expected. The assertion that matters is the session count.
        }

        $this->assertSame(
            2,
            $this->sessionCountFor($actor),
            'A refused attempt must not cut the attempters own session.',
        );
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * A Group Manager who holds scope.grant, and a Hotel Manager to administer.
     *
     * The grantor needs a live grant somewhere so they legitimately hold the
     * permission; otherwise every call would fail on the permission rather than
     * exercising the wiring.
     *
     * @return array{0: User, 1: User, 2: Property}
     */
    private function twoParties(): array
    {
        $grantor = $this->makeUser('grantor@example.test', Role::GroupManager);
        $subject = $this->makeUser('subject@example.test', Role::HotelManager);
        $property = $this->makeProperty('Administered Hotel');

        // The grantor needs authority ON THE TARGET PROPERTY, not merely
        // somewhere: `ScopeGrantService` authorizes the actor against the
        // property being administered, so an unrelated anchor would make every
        // call fail on the permission and never reach the revocation.
        $this->grantProperty($grantor, $property);

        return [$grantor, $subject, $property];
    }

    private function service(): ScopeGrantService
    {
        return $this->app->make(ScopeGrantService::class);
    }

    /**
     * Insert N live session rows for a user.
     *
     * Rows are inserted directly rather than by driving a real login. The
     * behaviour under test is "the session row is removed by a scope change",
     * and a row is what `SessionRevoker` matches on — manufacturing a genuine
     * authenticated session here would couple this suite to the whole
     * `SessionSecurity` path and test two things at once.
     */
    private function openSessionsFor(User $user, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('sessions')->insert([
                'id' => (string) Str::ulid(),
                'user_id' => (string) $user->id,
                'ip_address' => '203.0.113.10',
                'user_agent' => 'synthetic',
                'payload' => base64_encode(serialize(['auth.user_id' => (string) $user->id])),
                'last_activity' => time(),
            ]);
        }
    }

    private function sessionCountFor(User $user): int
    {
        return DB::table('sessions')->where('user_id', (string) $user->id)->count();
    }
}
