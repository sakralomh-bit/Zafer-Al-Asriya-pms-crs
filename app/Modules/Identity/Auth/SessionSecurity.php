<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Models\User;
use App\Shared\Domain\BusinessRuleViolation;
use App\Shared\Domain\ErrorCode;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Session lifecycle: start, validate, end.
 *
 * `AC-T-004-03` requires idle timeout AND absolute lifetime to be enforced.
 * `SEC-008` records the VALUES as TBD, so both come from `SecurityPolicy` and
 * are compared here in seconds since authentication. The VALUES are not read
 * from `config/session.php`'s stock `'lifetime' => 120`, because reusing that
 * number would be choosing a session lifetime by accident — the exact outcome
 * `T-004` §Risks calls out.
 *
 * Three distinct notions of time are tracked, and conflating them is a common
 * source of session bugs:
 *
 *   AUTHENTICATED_AT  absolute lifetime anchor. Activity does NOT extend it.
 *   LAST_ACTIVITY_AT  idle timeout anchor. Activity DOES extend it.
 *   STEP_UP_AT        when the last step-up was performed, for the step-up
 *                      gate. That gate is not implemented (`DR-T004-08` is
 *                      OPEN), so these two keys are written and read by
 *                      nothing yet.
 *
 * Session fixation (`docs/SECURITY.md` `TH-01`): the session identifier is
 * REGENERATED at authentication, so an identifier an attacker planted before
 * login is not the identifier the authenticated session continues under. It is
 * regenerated again on logout, and the CSRF token with it.
 *
 * Laravel's own `SessionGuard` remains the mechanism for "who is signed in".
 * This class does not build a parallel session store: `config/session.php`
 * already selects the `database` driver, and `AC-T-004-03` is satisfied by
 * storing two timestamps in that same session rather than by inventing a
 * second one.
 */
final class SessionSecurity
{
    /**
     * Session keys. Namespaced so they cannot collide with framework or
     * application keys written by anything else.
     */
    private const KEY_USER_ID = 'auth.user_id';

    private const KEY_AUTHENTICATED_AT = 'auth.authenticated_at';

    private const KEY_LAST_ACTIVITY_AT = 'auth.last_activity_at';

    private const KEY_STEP_UP_AT = 'auth.step_up_at';

    private const KEY_STEP_UP_OPERATION = 'auth.step_up_operation';

    public function __construct(
        private readonly SecurityPolicy $policy,
    ) {}

    /**
     * Establish an authenticated session.
     *
     * Order matters: the account state is re-checked HERE, at the moment of
     * authentication, and not merely during authorization. A user suspended
     * between page load and submission must not obtain a session.
     *
     * @throws BusinessRuleViolation when the account is not ACTIVE
     */
    public function start(Request $request, User $user): void
    {
        if (! $user->isActive()) {
            // Re-stated rather than delegated: `AccountNotActive` is the
            // authorization layer's refusal, and a login must not report which
            // lifecycle state the account is in (see `AuthenticationFailed`).
            throw new BusinessRuleViolation(
                ErrorCode::AuthFailed,
                'The email or password is incorrect.',
            );
        }

        $session = $request->session();

        // FIXATION PROTECTION. `regenerate()` mints a new session id and
        // carries the existing data forward, so anything the pre-login session
        // held is preserved for CSRF continuity while the IDENTIFIER changes.
        // The guard is set AFTER the regeneration so the authenticated session
        // is never reachable under the pre-login identifier.
        $session->regenerate();

        $now = now();

        $session->put([
            self::KEY_USER_ID => (string) $user->id,
            self::KEY_AUTHENTICATED_AT => $now->toIso8601String(),
            self::KEY_LAST_ACTIVITY_AT => $now->toIso8601String(),
        ]);

        // A NEW session has no step-up, and says so by omission. Carrying a
        // step-up across authentication would let a session born from a
        // re-login satisfy a sensitive operation the new login never verified.
        $session->forget([self::KEY_STEP_UP_AT, self::KEY_STEP_UP_OPERATION]);

        $this->guard()->login($user);
    }

    /**
     * End the session completely: the identifier, the data, and the CSRF token.
     */
    public function end(Request $request): void
    {
        $this->guard()->logout();

        $session = $request->session();

        $session->invalidate();
        $session->regenerateToken();
    }

    /**
     * The signed-in user, or null when there is no session.
     *
     * Only proves AUTHENTICATION. Every authorization decision still goes
     * through `Identity\Contracts\AuthorizesRequests`; this method is the
     * identity half of the pair and is never sufficient on its own.
     */
    public function authenticatedUser(): ?User
    {
        $user = $this->guard()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The signed-in user or `AUTH_REQUIRED`.
     *
     * @throws BusinessRuleViolation
     */
    public function requireUser(): User
    {
        $user = $this->authenticatedUser();

        if ($user === null) {
            throw new BusinessRuleViolation(ErrorCode::AuthRequired, 'Authentication is required.');
        }

        return $user;
    }

    /**
     * Enforce the two `SEC-008` lifetimes and refresh the idle anchor.
     *
     * An expired session is DESTROYED before the refusal is raised. A 401 that
     * leaves the session in place would let the caller retry into a session the
     * server has already decided is too old.
     *
     * @throws BusinessRuleViolation `AUTH_REQUIRED` (401) when there is no
     *                               session, no anchor, or an expired one
     * @throws SecurityPolicyUnresolved when `SEC-008` is undecided, so no
     *                                  lifetime can be compared at all
     */
    public function enforceLifetimes(Request $request): void
    {
        // Proves a session exists at all, then the two ages below prove it is
        // current. Both must hold.
        $this->requireUser();

        $session = $request->session();

        $authenticatedAt = $this->readTimestamp($session->get(self::KEY_AUTHENTICATED_AT));
        $lastActivityAt = $this->readTimestamp($session->get(self::KEY_LAST_ACTIVITY_AT));

        // A session that is authenticated but carries neither anchor is a
        // session this class did not create — a legacy row, a hand-built test
        // session, or one written by something else. Refusing is the only safe
        // reading: an unanchored session cannot be shown to be within any
        // lifetime, and AC-T-004-03 is about proving the lifetime.
        if ($authenticatedAt === null || $lastActivityAt === null) {
            $this->end($request);

            throw new BusinessRuleViolation(
                ErrorCode::AuthRequired,
                'The session is missing its authentication record; sign in again.',
            );
        }

        $idleLimit = $this->policy->sessionIdleTimeoutSeconds();
        $absoluteLimit = $this->policy->sessionAbsoluteLifetimeSeconds();

        $now = now();

        // `diffInSeconds()` is SIGNED by default in this Carbon version: called
        // as `$now->diffInSeconds($earlier)` it returns a negative number, so a
        // bare `>=` comparison against a positive limit is never true and the
        // session would live forever. The magnitude is requested explicitly.
        $absoluteAge = $now->diffInSeconds($authenticatedAt, absolute: true);
        $idleAge = $now->diffInSeconds($lastActivityAt, absolute: true);

        if ($absoluteAge >= $absoluteLimit) {
            $this->end($request);

            throw new BusinessRuleViolation(
                ErrorCode::AuthRequired,
                'The session reached its maximum lifetime; sign in again.',
            );
        }

        if ($idleAge >= $idleLimit) {
            $this->end($request);

            throw new BusinessRuleViolation(
                ErrorCode::AuthRequired,
                'The session was idle for too long; sign in again.',
            );
        }

        // Only now, once the session has been shown to be live, is activity
        // recorded. Writing the anchor before the checks would let an expired
        // session renew itself by making a request.
        $session->put(self::KEY_LAST_ACTIVITY_AT, $now->toIso8601String());
    }

    /**
     * When the current session last completed a step-up, or null.
     */
    public function stepUpAt(Request $request): ?Carbon
    {
        return $this->readTimestamp($request->session()->get(self::KEY_STEP_UP_AT));
    }

    /**
     * Record that a step-up was just performed for an operation.
     *
     * Nothing calls this yet, and nothing reads it. The gate that would read it
     * is deliberately not implemented: the mechanism that SATISFIES a step-up
     * is unspecified (`DR-T004-08`, OPEN). This method exists so the state has
     * one shape that the eventual mechanism writes and the eventual gate reads,
     * and so neither has to invent its own.
     *
     * `DR-T004-09` fixes the seven operations that require a step-up. This
     * method does not validate the operation name: the operation set is a
     * decision about which operations require a step-up, not a whitelist this
     * layer may enforce without inventing a rule.
     */
    public function recordStepUp(Request $request, string $operation): void
    {
        $request->session()->put([
            self::KEY_STEP_UP_AT => now()->toIso8601String(),
            self::KEY_STEP_UP_OPERATION => $operation,
        ]);
    }

    /**
     * The framework's session guard. `StatefulGuard` is the interface that
     * guarantees `login()`/`logout()` exist, so a stateless guard (an API-token
     * guard, say) fails loudly here instead of silently not signing anyone in.
     */
    private function guard(): StatefulGuard
    {
        $guard = Auth::guard('web');

        if (! $guard instanceof StatefulGuard) {
            throw new BusinessRuleViolation(
                ErrorCode::ServiceUnavailable,
                'The [web] guard is not stateful, so sessions cannot be managed.',
            );
        }

        return $guard;
    }

    private function readTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
