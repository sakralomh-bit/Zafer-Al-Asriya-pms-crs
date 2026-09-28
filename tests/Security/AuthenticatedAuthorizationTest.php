<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\AuthenticationService;
use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Contracts\AuthorizesRequests;
use App\Modules\Identity\Models\User;
use App\Shared\Authorization\PermissionDenied;
use App\Shared\Authorization\PropertyScopeDenied;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTestFixtures;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * Authentication proves WHO. Authorization proves WHETHER. Neither substitutes
 * for the other, and `docs/API-SPEC.md` §2.1 keeps them as different status
 * codes — 401 for one, 403 for the other — for exactly this reason.
 *
 * `AuthenticationService` names this behaviour as a claim in its own
 * documentation, so it is tested here rather than left as a comment. A user
 * who signs in successfully and holds nothing is still refused everything, and
 * a user who signs in successfully at one property is still refused another.
 *
 * This is also the regression test for `DR-T004-14`: `User` now implements
 * `Illuminate\Contracts\Auth\Authenticatable` so the guard can sign it in, and
 * the thing most likely to break in that change is authorization accidentally
 * becoming easier.
 */
final class AuthenticatedAuthorizationTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    private const PASSWORD = 'synthetic-not-a-real-password';

    private const EMAIL = 'authenticated.staff@example.test';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        config(['session.driver' => 'database']);
        $this->app->forgetInstance('session');
        $this->app->forgetInstance('session.store');
        $this->app->forgetInstance('auth');

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());
        Hash::extend('test_only_hasher', static fn (): Hasher => new BcryptHasher(['rounds' => 4]));

        $this->user = $this->makeUser(self::EMAIL, Role::HotelManager);
        $this->user->forceFill([
            'password' => Hash::driver('test_only_hasher')->make(self::PASSWORD),
        ])->save();
        $this->reread($this->user);
    }

    public function test_a_user_who_authenticates_successfully_still_denies_everything_without_a_grant(): void
    {
        $property = $this->makeProperty('Never Granted');

        // The session exists. That is all it proves.
        $authenticated = $this->authenticate();
        $this->assertTrue($authenticated->is($this->user));

        // The user has a role but no property grant, so every one of the twelve
        // roles' worth of permissions is unreachable.
        $this->expectException(PropertyScopeDenied::class);

        $this->authorizes()->authorize($authenticated, Permission::PropertyRead, (string) $property->id);
    }

    public function test_a_granted_property_does_not_grant_another(): void
    {
        $mine = $this->makeProperty('Granted To Me');
        $theirs = $this->makeProperty('Not Granted');

        $this->grantProperty($this->user, $mine);

        $authenticated = $this->authenticate();

        // The positive case, so the negative one below is not passing merely
        // because the permission is impossible for this role.
        $this->authorizes()->allows($authenticated, Permission::PropertyRead, (string) $mine->id);

        $this->expectException(PropertyScopeDenied::class);

        $this->authorizes()->authorize($authenticated, Permission::PropertyRead, (string) $theirs->id);
    }

    /**
     * A denial is a 403, never a 401. A caller that authenticated successfully
     * is not unauthenticated, and a client that treats the two alike will log
     * the user out on an ordinary authorisation failure.
     */
    public function test_an_authorisation_denial_is_a_403_not_a_401(): void
    {
        $property = $this->makeProperty('Never Granted');

        $authenticated = $this->authenticate();

        try {
            $this->authorizes()->authorize(
                $authenticated,
                Permission::PropertyRead,
                (string) $property->id,
            );
            $this->fail('Expected a scope denial.');
        } catch (PropertyScopeDenied $denied) {
            $this->assertSame(403, $denied->errorCode->httpStatus());
        }
    }

    /**
     * `ADR-0014` §6 separation of duties: the approver of a refund must not be
     * the requester, so `RefundApprove` is withheld from Hotel Manager. A
     * successful login must not confer it, and no MFA or step-up mechanism is
     * in play that could have widened the role either — `DR-T004-08` is OPEN
     * and nothing was built.
     */
    public function test_authentication_does_not_disturb_the_role_matrix(): void
    {
        $property = $this->makeProperty('Matrix Property');
        $this->grantProperty($this->user, $property);

        $authenticated = $this->authenticate();

        $this->assertFalse(
            $this->authorizes()->allows($authenticated, Permission::RefundApprove, (string) $property->id),
            'Signing in must not confer a permission the role does not hold.',
        );

        $this->expectException(PermissionDenied::class);

        $this->authorizes()->authorize(
            $authenticated,
            Permission::RefundApprove,
            (string) $property->id,
        );
    }

    private function authenticate(): User
    {
        $request = Request::create('/api/v1/auth/login', 'POST');

        $store = $this->app['session']->driver();
        $store->start();
        $request->setLaravelSession($store);

        return $this->app->make(AuthenticationService::class)
            ->login($request, self::EMAIL, self::PASSWORD, 'corr-authz-1');
    }

    private function authorizes(): AuthorizesRequests
    {
        return $this->app->make(AuthorizesRequests::class);
    }
}
