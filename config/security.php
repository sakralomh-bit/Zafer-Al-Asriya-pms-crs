<?php

declare(strict_types=1);

/**
 * The security policy — and the place where the OPEN decisions are visible.
 *
 * This file exists because the values required by T-004 are recorded as
 * undecided in the governing documents, and "undecided" must be visible in one
 * obvious place rather than guessed at a call site. The table below lists all
 * seven groups of keys, not four:
 *
 *   | Config key                          | Decision | Recorded as |
 *   |-------------------------------------|----------|-------------|
 *   | `password_hashing.algorithm`        | `SEC-007`| `SECURITY.md` §12 — owner: Security |
 *   | `session.idle_timeout_seconds`      | `SEC-008`| `SECURITY.md` §12 — owner: Security |
 *   | `session.absolute_lifetime_seconds` | `SEC-008`| `SECURITY.md` §12 — owner: Security |
 *   | `authentication_rate_limit.*`       | rate limits | `SECURITY.md` §12 — needs `B-05` |
 *   | `lockout.*`                         | lockout values | `SECURITY.md` §12 — needs `B-05` |
 *   | `step_up.freshness_seconds`         | step-up window | `SEC-008`, undecided — tracked in `docs/SECURITY.md` §6.1 and `docs/TASKS.md` §13.1.4. There is **no** step-up row in `SECURITY.md` §12 |
 *   | `security_headers.*`                | exact header set | `API-SPEC.md` §5 — `TBD` |
 *
 * `docs/API-SPEC.md` §6 lists the first four as "Unresolved before
 * implementation", and `T-004` §Risks says it plainly: *"SEC-007 and SEC-008
 * are undefined, so this task is blocked for those specific values — implement
 * the mechanism, and do not invent the parameters. A wrong session lifetime is
 * a security decision made by accident."*
 *
 * THEREFORE: every value below is `null` by default, and `null` MEANS
 * "unresolved". `App\Modules\Identity\Auth\SecurityPolicy` refuses to use an
 * unresolved value and the authentication endpoints fail CLOSED with
 * `SERVICE_UNAVAILABLE` rather than falling back to a default.
 *
 * A default is NOT provided here on purpose. `config/session.php` ships
 * Laravel's stock `'lifetime' => 120`, and it would be trivial to reuse that
 * number for the idle timeout — that is exactly the accident the T-004 risk
 * note warns about, so nothing in the authentication path reads it.
 *
 * To resolve one, the security owner sets the environment variable; no code
 * change is needed. Setting an algorithm here does NOT mean it was approved —
 * approval is the owner's act, recorded by them setting the value.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | SEC-007 — password hashing algorithm
    |--------------------------------------------------------------------------
    |
    | `AC-T-004-02`: "Password hashing uses an algorithm approved by the
    | security owner. THE ALGORITHM IS TBD (SEC-007) and must not be chosen
    | implicitly."
    |
    | No value is shipped. `AuthenticationService` obtains its hasher through
    | `SecurityPolicy`, which throws `SecurityPolicyUnresolved` rather than
    | hashing with an algorithm nobody approved. `options` is an algorithm's
    | cost/work parameters, which belong to the same decision.
    |
    */

    'password_hashing' => [
        'algorithm' => env('SECURITY_PASSWORD_ALGORITHM'),
        'options' => env('SECURITY_PASSWORD_OPTIONS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SEC-008 — session idle timeout and absolute lifetime
    |--------------------------------------------------------------------------
    |
    | `AC-T-004-03`: "Session idle timeout and absolute lifetime are enforced.
    | VALUES ARE TBD (SEC-008) and require the security owner's decision."
    |
    | Enforcement is implemented; the numbers are not invented. Idle timeout is
    | measured from the last activity, absolute lifetime from authentication.
    | Note that these are SECONDONDS because an absolute lifetime expressed in
    | minutes cannot represent a sub-minute policy without rounding, and a
    | rounded security value is a value nobody chose.
    |
    */

    'session' => [
        'idle_timeout_seconds' => env('SECURITY_SESSION_IDLE_TIMEOUT_SECONDS'),
        'absolute_lifetime_seconds' => env('SECURITY_SESSION_ABSOLUTE_LIFETIME_SECONDS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting and lockout — values TBD, blocked on B-05
    |--------------------------------------------------------------------------
    |
    | `AC-T-004-06` requires rate limiting AND defined lockout behaviour.
    | `docs/SECURITY.md` §12 records the values as `TBD (needs B-05)`, because
    | "how many attempts is too many" depends on the operating scale that B-05
    | has not established. Both the attempt ceiling and the lockout rule are
    | null, so an unconfigured deployment refuses authentication attempts
    | rather than limiting them by an invented number.
    |
    */

    'authentication_rate_limit' => [
        'max_attempts' => env('SECURITY_AUTH_MAX_ATTEMPTS'),
        'decay_seconds' => env('SECURITY_AUTH_ATTEMPT_DECAY_SECONDS'),
    ],

    'lockout' => [
        'threshold' => env('SECURITY_AUTH_LOCKOUT_THRESHOLD'),
        'seconds' => env('SECURITY_AUTH_LOCKOUT_SECONDS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Step-up freshness window
    |--------------------------------------------------------------------------
    |
    | `AC-T-004-05` requires step-up on the canonical operation set — the seven
    | operations enumerated in `docs/SECURITY.md` §6.1. How long a completed
    | step-up remains valid is a policy value and is TBD.
    |
    | NO GATE EXISTS, in either direction. There is no `StepUpGuard`, no
    | middleware, and no endpoint that consults this value; nothing reads
    | `step_up.freshness_seconds` at runtime. `StepUpRequired` is a refusal with
    | no producer. The value is present because the freshness window is a real
    | decision that has to be made BEFORE the gate is built — building a gate
    | that compares against an unset window would either refuse every sensitive
    | operation or silently allow all of them, and choosing between those is
    | exactly the decision that has not been made.
    |
    | The mechanism that would SATISFY a step-up is separately unspecified: no
    | MFA mechanism is named in any governing document, and choosing one
    | (TOTP, SMS, email, passkey) would be inventing a security architecture
    | (`DR-T004-08`, OPEN).
    |
    */

    'step_up' => [
        'freshness_seconds' => env('SECURITY_STEP_UP_FRESHNESS_SECONDS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security headers — the exact set is TBD
    |--------------------------------------------------------------------------
    |
    | `SEC-009` requires "security headers on all responses"; `API-SPEC.md` §5
    | marks the exact set `TBD`, and `docs/SECURITY.md` §12 carries "Exact
    | security header set — Security (`TBD`)" as an OPEN decision.
    |
    | NOTHING SETS THESE HEADERS. There is no `SecurityHeaders` class, no
    | middleware, and no response pipeline that reads this block; the four keys
    | below are read by no production code. `SecurityPolicy` exposes typed
    | accessors for them so that the decision has one place to land, and so
    | `UnresolvedSecurityPolicyTest` can prove every one is `null` today.
    |
    | No "always-safe floor" is applied. A baseline header set is itself a
    | security decision — it fixes the `Referrer-Policy`, the `Permissions-Policy`
    | posture, and the CSP origins for the whole application — and the CSP in
    | particular must enumerate the origins the built application actually
    | loads, which depend on asset hosting that `B-03` has not chosen.
    |
    | `strict_transport_security` is called out because it is the one that
    | invites an "obvious" default: sending HSTS over plain HTTP is ignored by
    | browsers, and sending it before TLS is correct can lock a developer out of
    | a local environment. That is still a deployment decision, not a constant.
    |
    */

    'security_headers' => [
        'content_security_policy' => env('SECURITY_CSP'),
        'strict_transport_security' => env('SECURITY_HSTS'),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY'),
        'permissions_policy' => env('SECURITY_PERMISSIONS_POLICY'),
    ],

];
