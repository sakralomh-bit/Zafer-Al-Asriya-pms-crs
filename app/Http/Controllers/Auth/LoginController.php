<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Auth\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/login` — `API-SPEC.md` §3.11, "Authenticate", public.
 *
 * `AC-T-004-01`: a successful authentication writes `AUTH_SUCCEEDED`. The event
 * is written by `AuthenticationService::login()` and not here, because the
 * service is where the outcome is decided; a controller that emitted it as well
 * would produce two rows for one login, and a future refactor that moved the
 * service call would leave the route emitting a success for a refused login.
 *
 * ============================ WHY THE RESPONSE CARRIES SO LITTLE ============================
 * The body is `{"user_id", "roles"}` and nothing else. There is deliberately no
 * token, no session identifier, and no property scope.
 *
 * The session is already established by the `web` guard and travels in a cookie
 * the browser holds, so there is no bearer token for a client to mishandle. And
 * `API-SPEC.md` §1.6 prohibits secrets, tokens, and keys in any response — a body
 * echoing a token would be the single most damaging thing this endpoint could
 * do, because it would put that token in every access log between here and the
 * client.
 *
 * `roles` is returned because a client has to render a menu, and
 * `docs/API-SPEC.md` §3.11 gives `GET /api/v1/me` as the fuller identity
 * endpoint. This is the minimum needed to decide what to render next; it is not
 * authorisation. `PropertyScope` and the granted-property list are deliberately
 * absent, because the authoritative answer to "what may this user do" is the
 * server's decision on each request, never a claim the client was handed.
 *
 * ============================ WHAT THE FAILURES MEAN ============================
 * The controller adds no failure handling of its own. Every refusal is raised by
 * the service and rendered by `bootstrap/app.php`:
 *
 *   - `AUTH_FAILED` (401) for wrong, unknown, or inactive credentials. The three
 *     are indistinguishable by construction — see `AuthenticationFailed`.
 *   - `RATE_LIMITED` (429) when an account or address bucket is exhausted, raised
 *     BEFORE the password is examined.
 *   - `SERVICE_UNAVAILABLE` (503) when `SEC-007`, `SEC-008`, or `B-05` is
 *     undecided. A 503 here is the system correctly declining to authenticate
 *     anyone until a human has settled a security value — not a defect.
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authentication,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $email = $this->requiredString($request, 'email');

        // NOT trimmed: whitespace can be part of a chosen password, and removing
        // it would turn a correct credential into a wrong one. The email IS
        // trimmed, because a trailing space in a mailbox is a typo rather than an
        // identity.
        $password = $this->requiredString($request, 'password', trim: false);

        $user = $this->authentication->login(
            $request,
            $email,
            $password,
            $this->correlationId($request),
        );

        return response()->json([
            'user_id' => (string) $user->id,
            'roles' => $user->activeRoles()->map->value->values()->all(),
        ]);
    }
}
