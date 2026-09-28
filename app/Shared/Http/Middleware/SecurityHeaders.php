<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Modules\Identity\Auth\SecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `SEC-009`: security headers on ALL responses.
 *
 * GLOBAL, AND THAT IS THE REQUIREMENT RATHER THAN A CONVENIENCE. `SEC-009` and
 * `docs/API-SPEC.md` §5 both say "all responses", and a per-endpoint or
 * per-controller approach cannot deliver that: it misses the responses nobody
 * wrote a controller for. The framework's own 404, its 405, a `DomainFailure`
 * rendered by `ErrorResponseFactory`, an unhandled exception rendered by the
 * `Throwable` handler in `bootstrap/app.php`, and a redirect are all responses,
 * and a header applied inside a controller never reaches any of them. This
 * middleware is registered once in the application's global stack, so a new
 * endpoint inherits the control by existing.
 *
 * ============================ WHY ERRORS ARE COVERED TOO ============================
 * `$next($request)` is called first and the headers are applied to whatever comes
 * back, so an exception path that is caught and rendered further up the pipeline
 * still passes through here. A framed 500 is still a clickjacking target, an
 * error body without `nosniff` is still a type-confusion surface, and an error
 * page that leaks the referer of a guest-identity URL is still a leak.
 *
 * ============================ WHAT EACH HEADER IS FOR ============================
 * The full reasoning, the compatibility constraint, and the HSTS caveat are in
 * `config/security.php`. In short:
 *
 *   - `Content-Security-Policy` with `default-src 'self'` and
 *     `frame-ancestors 'none'`. A `'self'`-only default is what stops an XSS
 *     payload exfiltrating to an arbitrary host; `frame-ancestors 'none'` is the
 *     clickjacking control. `'unsafe-inline'` and `'unsafe-eval'` are NOT
 *     enabled — a policy that permits them is not a policy.
 *   - `X-Content-Type-Options: nosniff`. Stops the browser re-interpreting a
 *     served type. This is the standard defence for a JSON API.
 *   - `X-Frame-Options: DENY`. The legacy companion to `frame-ancestors`, kept
 *     for agents that predate CSP.
 *   - `Referrer-Policy: no-referrer`. Guest-identity URLs must not appear in a
 *     `Referer` on outbound navigation.
 *   - `Permissions-Policy`. Denies three capabilities this application never
 *     requests, which removes the prompt surface rather than merely ignoring it.
 *   - `Strict-Transport-Security`. **CONDITIONAL, and off by default.** This is
 *     the one header here that can cause an outage: once a browser has seen it,
 *     it refuses plaintext for `max-age` with no override, and `includeSubDomains`
 *     extends that to hosts this application does not control. The deployment
 *     guarantee it requires — HTTPS on every host, every response path, and on
 *     redirects and errors — has not been established, so the header is absent
 *     until an operator asserts it.
 *
 * ============================ A HEADER IS NEVER TWICE OVERWRITTEN ============================
 * A value already present on the response is left alone. Something upstream in
 * the edge or the framework may have set a stricter value deliberately, and
 * replacing it with this application's default would be this middleware silently
 * WEAKENING a control it does not own.
 *
 * Nothing secret is written into a header, and no configuration value is echoed
 * beyond the header values themselves — no environment names, no policy numbers,
 * no internal hostnames (`API-SPEC.md` §1.6).
 */
final class SecurityHeaders
{
    public function __construct(
        private readonly SecurityPolicy $policy,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $this->apply($response, 'Content-Security-Policy', $this->policy->contentSecurityPolicy());
        $this->apply($response, 'X-Content-Type-Options', $this->policy->xContentTypeOptions());
        $this->apply($response, 'X-Frame-Options', $this->policy->xFrameOptions());
        $this->apply($response, 'Referrer-Policy', $this->policy->referrerPolicy());
        $this->apply($response, 'Permissions-Policy', $this->policy->permissionsPolicy());
        $this->apply($response, 'Strict-Transport-Security', $this->policy->strictTransportSecurity());

        return $response;
    }

    /**
     * Set a header, or leave an existing one alone.
     *
     * `has()` is checked before `set()` so this can never replace a value some
     * other layer chose. `null` means the policy did not decide this header, and
     * an undecided header is OMITTED rather than emitted empty — an empty
     * `Content-Security-Policy` is a malformed policy, not a permissive one, and
     * treating it as absent would be a different decision from making it absent.
     */
    private function apply(Response $response, string $name, ?string $value): void
    {
        if ($value === null || $value === '' || $response->headers->has($name)) {
            return;
        }

        $response->headers->set($name, $value);
    }
}
