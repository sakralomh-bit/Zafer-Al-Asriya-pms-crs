<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/logout` — `API-SPEC.md` §3.11, "End session", authenticated.
 *
 * `AC-T-004-01`: a logout writes `AUTH_LOGOUT`. The service emits it.
 *
 * ============================ WHY THIS IS 200 AND NOT 204 ============================
 * `API-SPEC.md` §1.7 reserves `200`/`201` for success and lists no `204`. The
 * response body is the documented success envelope rather than an empty `204`,
 * because a client that has to distinguish "logged out" from "the request failed"
 * should do so from a status this document fixes, not from a body shape this
 * controller invented.
 *
 * ============================ WHY IT IS IDEMPOTENT ============================
 * `AuthenticationService::logout()` resolves the user BEFORE ending the session,
 * and records `AUTH_LOGOUT` whether or not one was resolved — the actor fields
 * are simply null. That is deliberate: a double-submitted logout, or a logout
 * from a session that has already expired, is a client retrying something that
 * already succeeded, and answering it with `401` would be a lie about state the
 * client can observe directly (its own cookie is gone either way).
 *
 * The route is still behind `EnforceSessionLifetimes`, so an EXPIRED session is
 * refused with `AUTH_REQUIRED` before it reaches here — the middleware destroys
 * it. A client whose session merely expired gets a 401 and is told to sign in
 * again, which is the truth. What this controller tolerates is a second logout
 * within a live session's lifetime.
 */
final class LogoutController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authentication->logout($request, $this->correlationId($request));

        return response()->json(['status' => 'logged_out']);
    }
}
