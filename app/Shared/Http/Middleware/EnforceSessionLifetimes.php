<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Modules\Identity\Auth\SessionSecurity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforce the `SEC-008` session lifetimes on every request that reaches a route.
 *
 * ============================ WHY A MIDDLEWARE AND NOT A CONTROLLER CALL ============================
 * `SessionSecurity::enforceLifetimes()` existed and was tested, and nothing in
 * `app/`, `routes/`, or `bootstrap/` called it. `AC-T-004-03` says the lifetimes
 * are "enforced"; a method that no request reaches enforces nothing.
 *
 * It is here rather than in a base controller because a base controller is
 * opt-out: a new controller that forgets to extend it has no lifetimes, and the
 * omission is invisible in review. Middleware is opt-in for a ROUTE, and the
 * route is where the security claim is made.
 *
 * This is the same reasoning that put `SecurityHeaders` in the global stack: the
 * control belongs where a new endpoint inherits it by existing, and the failure
 * mode of the alternative is a silent hole rather than a loud error.
 *
 * ============================ WHY IT IS NOT GLOBAL ============================
 * A global lifetime check would refuse `POST /auth/login` with `AUTH_REQUIRED`
 * on every call, because a login is precisely the request that has no session
 * yet. The correct placement is the authenticated route group, which is what
 * `routes/api.php` applies — `login` opts out explicitly rather than the
 * middleware sniffing the path and guessing.
 *
 * `routes/web.php`'s `/` is also unaffected, since the group is not global.
 *
 * ============================ WHAT IT DOES ON FAILURE ============================
 * `enforceLifetimes()` DESTROYS an expired session before it raises, so a refusal
 * here leaves no session to retry into. The exception it raises is a
 * `BusinessRuleViolation` with `AUTH_REQUIRED`, which `bootstrap/app.php` renders
 * through `ErrorResponseFactory` as the documented `API-SPEC.md` §1.5 shape — a
 * 401 with a code, a `request_id`, and no detail about which lifetime expired.
 *
 * That last part matters: the two lifetimes are not distinguished in the
 * response. A caller learns that the session is over, not whether it was idle
 * or absolute, because the finer distinction is of no use to a legitimate client
 * and is a small oracle to an attacker holding a stolen cookie.
 *
 * An UNRESOLVED `SEC-008` propagates `SecurityPolicyUnresolved` and becomes a 503
 * `SERVICE_UNAVAILABLE`. That is the correct outcome: the system does not know
 * how long a session may live, and serving requests anyway would be the exact
 * silent default this project refuses elsewhere.
 */
final class EnforceSessionLifetimes
{
    public function __construct(
        private readonly SessionSecurity $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sessions->enforceLifetimes($request);

        return $next($request);
    }
}
