<?php

declare(strict_types=1);

/**
 * The authentication HTTP surface — `docs/API-SPEC.md` §3.11.
 *
 * ============================ WHY THIS FILE EXISTS NOW ============================
 * `API-SPEC.md` §3.11 records these four routes as "specified, not implemented",
 * and adds: "There is no `StepUpGuard`, no `/api/v1/auth/step-up` route, and no
 * MFA challenge handler in the codebase, so `STEP_UP_PERFORMED` is a reserved
 * audit action with no emitter."
 *
 * Two of those three statements are now false. `StepUpGuard` and
 * `StepUpVerifier` were built in Stage 1/2, and this file registers the routes
 * that emit the events. `AC-T-004-01` (audit), `AC-T-004-03` (lifetimes), and
 * `AC-T-004-06` (rate limiting) all had mechanisms that nothing called: the code
 * existed and was tested, and the routes did not, so none of it was reachable.
 *
 * ============================ NO NEW CONTRACT IS INVENTED HERE ============================
 * Every path, method, authorization level, and audit action below is transcribed
 * from `API-SPEC.md` §3.11. Nothing is added, renamed, or re-scoped. The two
 * endpoints `API-SPEC.md` §3.11 also lists and that are NOT registered here are
 * the ones whose mechanism is genuinely absent, and the reasons are recorded in
 * `docs/SECURITY.md`:
 *
 *   - `/api/v1/me`, `/api/v1/users`, and the two property-scope routes belong to
 *     T-003 and are not authentication.
 *   - There is NO impersonation endpoint, and adding one is a different decision
 *     (`API-SPEC.md` §3.11: "Adding one requires explicit approval and an audit
 *     design"). Its absence IS the control.
 *   - There is NO `/api/v1/auth/mfa/enrol` route. Enrolment needs somewhere to
 *     store a secret, and `docs/DATA-MODEL.md` §2 reserves `mfa_secrets` while no
 *     migration creates it — `H-03` (how a secret is sealed, with which key, under
 *     which rotation) is a release gate, and `C-10` has no named owner to say who
 *     may enrol or who authorises a recovery. A route that accepted a secret and
 *     stored it in an invented scheme would be worse than no route.
 *
 * ============================ WHY THE AUTH ROUTES CARRY `web`, NOT `api` ============================
 * This is the load-bearing decision in this file and it is easy to get backwards.
 *
 * `docs/API-SPEC.md` §1 says the Staff API is "Session-based with CSRF
 * protection". Authentication here is a session cookie on the `web` guard, and
 * `AC-T-004-07` requires CSRF on "cookie-authenticated state-changing requests".
 * Laravel's `api` middleware group has no session, no cookies, and no CSRF
 * middleware — a route there is stateless by construction and cannot be protected
 * by a CSRF token, because there is no session for the token to belong to.
 *
 * So these four routes are on the `web` group. That is not a deviation from the
 * `/api/v1` path prefix; the prefix is a URL convention and the middleware group
 * is a security control, and here the control has to win. Putting a
 * cookie-authenticated login on the stateless group would make `AC-T-004-07`
 * unsatisfiable rather than satisfied.
 *
 * The consequence is stated rather than discovered later: these endpoints are
 * CSRF-protected and session-based, so they are not callable from a
 * cross-origin JavaScript client that cannot first obtain a token. A mobile or
 * SPA deployment would need a different authentication design, and that is a
 * decision nobody has taken.
 *
 * ============================ RATE LIMITING IS NOT A MIDDLEWARE HERE ============================
 * `AuthenticationService::login()` already calls
 * `AuthenticationRateLimiter::assertAttemptAllowed()` before it examines a
 * password, and `recordFailure()` on every refusal. It is deliberately INSIDE the
 * service, not wrapped around it, because the limiter must refuse BEFORE the
 * password is verified — a middleware that ran first could not, and a limiter
 * applied after authentication would count nothing useful.
 *
 * `AC-T-004-06` is therefore satisfied by the service call that already exists, and
 * this file does not add a second limiter over the same routes. Two limiters on
 * one path is two sets of counters that can disagree, and the stricter one wins
 * while the other is silently dead.
 *
 * ============================ WHERE LIFETIMES ARE ENFORCED ============================
 * `EnforceSessionLifetimes` is applied to the GROUP, and `login` opts out
 * explicitly with `withoutMiddleware()`. The web group does not carry it — it is
 * this application's own control (`SEC-008`) and has no framework equivalent.
 *
 * Group-then-opt-out rather than per-route because the omission is the dangerous
 * direction: a new authenticated route added to this group inherits the `SEC-008`
 * check by existing, and the one route that must not have it names itself. A
 * per-route list would have to be extended correctly by hand each time, and a
 * forgotten entry is a hole that no test notices.
 */

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MfaVerifyController;
use App\Http\Controllers\Auth\StepUpController;
use App\Shared\Http\Middleware\EnforceSessionLifetimes;
use App\Shared\Http\Middleware\RequireEnrolledFactorStore;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| `API-SPEC.md` §3.11, transcribed. The audit action each route is required to
| emit is named beside it below, and is emitted by the service it calls rather than
| by the route — a route that emitted the event itself would let a future refactor
| move the two apart.
|
| `EnforceSessionLifetimes` is applied to the whole group and `login` removes it
| by name, for the reason argued in the file header. It is called out here because
| that opt-out is the only place in this file where a middleware is SUBTRACTED, and
| a reader auditing the file should find the subtraction rather than infer it.
|
*/

Route::prefix('v1')->middleware(['web', EnforceSessionLifetimes::class])->group(function (): void {
    // `AUTH_SUCCEEDED` / `AUTH_FAILED`. Public, and the only route here that is:
    // every other one starts by requiring an authenticated subject.
    Route::post('auth/login', LoginController::class)
        ->withoutMiddleware([EnforceSessionLifetimes::class]);

    // `AUTH_LOGOUT`.
    Route::post('auth/logout', LogoutController::class);

    // `MFA_*`. Verifies a submitted second factor and audits the outcome. The
    // proof itself never leaves the server — see the controller.
    //
    // `RequireEnrolledFactorStore` is listed explicitly rather than assumed, and
    // it MUST be middleware: the container builds a controller's dependencies
    // before the controller runs, so a guard inside the controller would execute
    // only after the container had already failed to build `StepUpVerifier` —
    // surfacing as a 500 on every call instead of a 503.
    Route::post('auth/mfa/verify', MfaVerifyController::class)
        ->middleware(RequireEnrolledFactorStore::class);

    // `STEP_UP_PERFORMED`. Requires the operation being authorised, because a
    // proof for a refund does not authorise a document reveal. Same gate, same
    // reason.
    Route::post('auth/step-up', StepUpController::class)
        ->middleware(RequireEnrolledFactorStore::class);
});
