<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecord;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\RateLimited;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;

/**
 * Authentication: it establishes WHO is acting. It grants nothing.
 *
 * The division this class exists to enforce, stated once so the boundary is
 * not blurred by a later change:
 *
 *   AUTHENTICATION  proves an identity. Answer: "which user?"
 *   AUTHORIZATION   proves a permission. Answer: "may this user do this, here?"
 *
 * `docs/API-SPEC.md` §2.1 keeps them as different status codes — 401
 * `AUTH_REQUIRED` / `AUTH_FAILED` for authentication, 403
 * `PROPERTY_SCOPE_DENIED` / `PERMISSION_DENIED` for authorization — and
 * `ADR-0014` §4 requires authorization on every request. So this class never
 * calls the authorization service, never consults a role, and never reads a
 * property grant. It returns a `User`; the caller hands that `User` to the
 * unchanged T-003 authorization engine, which decides everything else. A user
 * who authenticates successfully and holds no roles is still denied every
 * operation, which is the required behaviour and is proven in
 * `AuthenticatedAuthorizationTest`.
 *
 * NOTHING SECRET IS LOGGED OR AUDITED HERE. The password, the hash, the
 * algorithm, and the session identifier are never written to the audit trail or
 * to the log. `docs/API-SPEC.md` §1.6 prohibits it, and a credential in an
 * append-only table is a credential that can never be rotated away.
 *
 * `AC-T-004-01`: "Authentication and logout produce audit events." The event
 * names are the ones `docs/API-SPEC.md` §3.11 already fixes —
 * `AUTH_SUCCEEDED` / `AUTH_FAILED` / `AUTH_LOGOUT`.
 *
 * A successful authentication does NOT confer a permission. Every operation
 * this identity attempts is still decided by `Identity\Contracts\AuthorizesRequests`
 * over `ADR-0014` §1's six factors, and that decision is unchanged by anything
 * in this class.
 */
final class AuthenticationService
{
    public function __construct(
        private readonly SecurityPolicy $policy,
        private readonly AuthenticationRateLimiter $rateLimiter,
        private readonly SessionSecurity $sessions,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Authenticate and establish a session.
     *
     *
     * @param  string  $email  the submitted identifier, used only to look the account up and to key the rate limiter
     * @param  string  $password  the submitted secret, used only for verification and never stored, logged, or audited
     *
     * @throws RateLimited when the attempt is throttled or the account is locked
     * @throws AuthenticationFailed when the credentials do not identify an ACTIVE identity
     * @throws SecurityPolicyUnresolved when SEC-007/SEC-008/B-05 are undecided
     */
    public function login(Request $request, string $email, string $password, ?string $correlationId): User
    {
        $ipAddress = $request->ip();

        // Refused before the password is examined, so a locked account is not
        // even verified against — which is the point of locking it.
        $this->rateLimiter->assertAttemptAllowed($email, $ipAddress);

        [$hasher] = $this->policy->passwordHasher();

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // TIMING EQUALISATION. Without this branch the "no such account" path
            // returns after one indexed lookup while the "wrong password" path
            // pays for a key derivation, and the difference is measurable
            // enough to enumerate accounts remotely. So a verification is
            // performed against a throwaway hash of the submitted password and
            // the result is discarded. The hash is generated once per request
            // from a value nobody chose to be secret.
            $hasher->check($password, $this->dummyHash($hasher));

            $this->refuse($request, $email, null, $correlationId, 'no_matching_account');

            throw AuthenticationFailed::invalidCredentials();
        }

        // `Hasher::check()` is the contract method, and it is the constant-time
        // comparison the framework provides. There is no `verify()` on
        // `Illuminate\Contracts\Hashing\Hasher` in this framework version.
        if (! $hasher->check($password, (string) $user->password)) {
            $this->refuse($request, $email, $user, $correlationId, 'password_mismatch');

            throw AuthenticationFailed::invalidCredentials();
        }

        // A successful password does not imply a successful LOGIN. The
        // lifecycle state is checked here, at the boundary, so a suspended
        // account cannot obtain a session even with correct credentials. The
        // refusal is `AUTH_FAILED` and not `ACCOUNT_SUSPENDED` so the response
        // does not confirm the account exists.
        if (! $user->isActive()) {
            $this->refuse($request, $email, $user, $correlationId, 'account_not_active');

            throw AuthenticationFailed::invalidCredentials();
        }

        // `start()` re-checks the lifecycle state; the duplicate check is
        // deliberate — it is the check that decides the AUDIT OUTCOME, and
        // running it twice costs one in-memory comparison.
        $this->sessions->start($request, $user);

        $this->rateLimiter->clear($email, $ipAddress);

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record(AuditRecord::of(
            action: AuditAction::AuthSucceeded,
            // A successful login is the one event whose actor is known
            // unambiguously, so it is attributed.
            actorUserId: (string) $user->id,
            actorRole: $user->activeRoles()->first()?->value,
            propertyId: null,
            subjectType: 'user',
            subjectId: (string) $user->id,
            source: 'auth_login',
            correlationId: $correlationId,
            // The request's origin is recorded because credential stuffing is a
            // pattern across many accounts from one source. The address is
            // infrastructure data, not identity data, and `ADR-0012`'s
            // encryption boundary covers identity documents, not this.
            additionalContext: [
                'ip_address' => $ipAddress,
                'user_agent_hash' => $request->userAgent() === null
                    ? null
                    : hash('sha256', $request->userAgent()),
            ],
        ));

        return $user;
    }

    /**
     * End the session and record that it ended.
     *
     * `AC-T-004-01`.
     *
     * @throws DomainFailure
     */
    public function logout(Request $request, ?string $correlationId): void
    {
        $user = $this->sessions->authenticatedUser();

        $this->sessions->end($request);

        $this->audit->record(AuditRecord::of(
            action: AuditAction::AuthLogout,
            actorUserId: $user?->id === null ? null : (string) $user->id,
            actorRole: $user?->activeRoles()->first()?->value,
            propertyId: null,
            subjectType: 'user',
            subjectId: $user?->id === null ? null : (string) $user->id,
            source: 'auth_logout',
            correlationId: $correlationId,
        ));
    }

    /**
     * Record a failed attempt and then refuse.
     *
     * The audit row is written BEFORE the exception propagates, and it is a
     * denial row: `ADR-0016` requires attempts and denials, not only successes,
     * because a brute-force attack is invisible in a log that records only
     * successful authentication.
     *
     * The `reason` is one of a fixed vocabulary and never contains the
     * submitted email, so the audit trail cannot be used to harvest candidate
     * identifiers. The account id is present when one was found, which is what
     * makes a run of failures against ONE account analysable.
     */
    private function refuse(
        Request $request,
        string $email,
        ?User $user,
        ?string $correlationId,
        string $reason,
    ): void {
        $this->rateLimiter->recordFailure($email, $request->ip());

        $this->audit->recordDenial(
            AuditRecord::of(
                action: AuditAction::AuthFailed,
                actorUserId: $user?->id === null ? null : (string) $user->id,
                actorRole: null,
                propertyId: null,
                subjectType: 'user',
                subjectId: $user?->id === null ? null : (string) $user->id,
                source: 'auth_login',
                correlationId: $correlationId,
                reason: $reason,
                result: 'DENIED',
                additionalContext: [
                    'ip_address' => $request->ip(),
                    'user_agent_hash' => $request->userAgent() === null
                        ? null
                        : hash('sha256', $request->userAgent()),
                ],
            ),
            AuthenticationFailed::invalidCredentials(),
        );
    }

    /**
     * A hash to verify against when no account matched.
     *
     * It is a real hash of a value that is not a credential, produced once per
     * request from a value nobody chose to be secret, so the cost of this branch
     * matches the cost of the real branch.
     */
    private function dummyHash(Hasher $hasher): string
    {
        return $hasher->make('this-value-is-not-a-credential');
    }
}
