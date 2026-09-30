<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Modules\Identity\Auth\Mfa\MfaFactorProvider;
use App\Shared\Domain\ErrorCode;
use App\Shared\Domain\UnimplementedSecurityControl;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a second-factor route when no enrolled-factor store is configured.
 *
 * ============================ WHY THIS IS MIDDLEWARE AND NOT A CONTROLLER CHECK ============================
 * This started as a guard inside the controller and it did not work, which is
 * worth recording because the reason is not obvious.
 *
 * A controller's dependencies are constructed by the container BEFORE
 * `__invoke()` runs. `StepUpVerifier` takes an `MfaFactorProvider`, so asking
 * "is one bound?" from inside the controller means asking after the container has
 * already tried — and failed — to build one. The request never reached the check:
 * it died in the container with a `BindingResolutionException`, which
 * `bootstrap/app.php`'s catch-all rendered as `INTERNAL_ERROR` (500).
 *
 * That is precisely the outcome the guard was written to prevent. The test that
 * asserts a 503 caught it on the first run.
 *
 * Middleware runs before the route's controller is resolved, so this is the
 * first layer that can actually see the absence and answer it honestly.
 *
 * ============================ WHY IT MATTERS THAT THE ANSWER IS 503 ============================
 * `API-SPEC.md` §1.7 reserves `500` for "the system failed in a way it does not
 * understand", and that status is alerted and counted. A route that cannot work
 * raises one on every single call, which trains a team to ignore alerts from
 * that route — and that is how the one real alert gets missed.
 *
 * `SERVICE_UNAVAILABLE` (503) is the same answer `SEC-007`, `SEC-008`, and
 * `B-05` give while they are undecided: the capability is not available, and the
 * request was not at fault. Here the cause is `H-03` (how a secret is sealed,
 * with which key, under which rotation) and `C-10` (who may enrol), both open by
 * decision rather than by accident.
 *
 * ============================ IT REFUSES; IT DOES NOT SUBSTITUTE ============================
 * Nothing here stands in for the missing store. There is no in-memory factor
 * cache, no plaintext fallback, and no "enrol on first use", because each of
 * those would be a secret-handling decision that nobody has made — and a
 * temporary scheme that ships is far harder to remove than one never written.
 *
 * When `H-03` is settled and a provider is bound in `bootstrap/app.php`, these
 * routes begin to work with no change here. This middleware becomes a no-op and
 * can be deleted.
 */
final class RequireEnrolledFactorStore
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->container->bound(MfaFactorProvider::class)) {
            throw new UnimplementedSecurityControl(
                ErrorCode::ServiceUnavailable,
                'Second-factor verification is not available: no enrolled-factor store is '
                .'configured. This is a known gap, not a fault.',
            );
        }

        return $next($request);
    }
}
