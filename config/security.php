<?php

declare(strict_types=1);

/**
 * The security policy — the ONE place a security value is declared.
 *
 * ================================================================================
 * READ THIS BEFORE CHANGING A NUMBER IN THIS FILE.
 * ================================================================================
 * Every value below is an IMPLEMENTED TECHNICAL BASELINE, chosen by the AI
 * Project Lead under delegated technical authority and documented in
 * `docs/SECURITY.md` §12.1 with its rationale, its trade-off, and its
 * dependency.
 *
 * A BASELINE IS NOT A REGISTERED DECISION. `docs/PRD.md` §15 holds `DR-001` …
 * `DR-014` and nothing else, and it is unchanged by this file. No identifier of
 * the form `DR-T004-*` exists or is implied. Setting a value here makes the
 * software USE a value; it does not make the Security Owner (still `TBD`,
 * `C-10`) have APPROVED it, and it does not resolve `B-05`, `H-03`, or any
 * `BLOCKED` row in §12.1.1.
 *
 * Each baseline is overridable by environment variable so the security owner
 * can replace it without a code change. The SHIPPED value is the default, and
 * that default is a documented engineering choice — not a value anybody
 * signed off, and not a value that survives contact with a real capacity
 * measurement (see the Argon2 note below).
 *
 * WHAT IS STILL REFUSED RATHER THAN DEFAULTED. `SecurityPolicy` continues to
 * raise `SecurityPolicyUnresolved` (503) when a value is blank, non-numeric, or
 * non-positive, and when the two session lifetimes violate the `SEC-008`
 * invariant. An explicitly emptied environment variable is a configuration
 * error, not a request for the default. That fail-closed property is
 * unchanged: it is the difference between a value nobody chose and a value
 * somebody broke.
 *
 * | Config key                            | Decision   | Baseline shipped            | Status in `docs/SECURITY.md` §12.1.1 |
 * |---------------------------------------|------------|-----------------------------|--------------------------------------|
 * | `password_hashing.algorithm`          | `SEC-007`  | `argon2id`                  | Row 1 — `PROPOSED — SECURITY`        |
 * | `password_hashing.options`            | `SEC-007`  | `memory=65536,time=2,threads=1` | Row 2 — `BLOCKED — BUSINESS` (capacity) |
 * | `session.idle_timeout_seconds`        | `SEC-008`  | `900`                       | Row 3 — `PROPOSED — SECURITY`        |
 * | `session.absolute_lifetime_seconds`   | `SEC-008`  | `43200`                     | Row 4 — `PROPOSED — SECURITY`        |
 * | `authentication_rate_limit.*`         | `B-05`     | `5` / `300`                 | Row 8 — `BLOCKED — BUSINESS`         |
 * | `authentication_rate_limit.ip_*`      | `B-05`     | `30` / `300`                | Rows 8, 11 — `PROPOSED — SECURITY` keying |
 * | `lockout.*`                           | `B-05`     | `5` / `900`                 | Row 9 — `BLOCKED — BUSINESS`         |
 * | `step_up.freshness_seconds`           | —          | `300`                       | Row 5 — `PROPOSED — SECURITY`. It has **no registered Decision ID**; the §12 row is recorded with `—` in the ID column, as are the other never-assigned open items |
 * | `security_headers.*`                  | —          | see below                   | Rows 6, 7 — `PROPOSED — SECURITY` / `PROPOSED — ENGINEERING` |
 *
 * A value is a SECURITY DECISION BY ACCIDENT if it is chosen at a call site.
 * These are all in this file for that reason.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | SEC-007 — password hashing
    |--------------------------------------------------------------------------
    |
    | `AC-T-004-02`: "Password hashing uses an algorithm approved by the
    | security owner." The approval has not happened; the baseline is Argon2id
    | and the owner can replace it with `SECURITY_PASSWORD_ALGORITHM`.
    |
    | WHY ARGON2ID, and what `ADR-0002` does and does not prove.
    | `ADR-0002:18` lists "hashing (argon2id)" as a reason to choose Laravel. That
    | is CAPABILITY and RATIONALE evidence for the backend stack. It is NOT prior
    | formal approval of `SEC-007` — `SEC-007` was still recorded `TBD` in the same
    | document set, and a stack-rationale line cannot decide a security value the
    | Security Owner owns. The baseline is justified on its own terms: Argon2id is
    | memory-hard, so a GPU or ASIC attacker cannot trade cheap parallelism for
    | password throughput the way it can against bcrypt's cost factor.
    |
    | ONE ALGORITHM ONLY. There is no second mechanism, no fallback chain, and no
    | per-request tuning. `SecurityPolicy` resolves this name through
    | `Hash::driver()`, so a driver registered elsewhere with `Hash::extend()`
    | remains visible, and an unregistered name still fails closed.
    |
    | NO CUSTOM PRIMITIVE. The implementation is the framework's own
    | `Illuminate\Hashing\Argon2IdHasher`. A hand-rolled hasher is what `T-004`
    | forbids.
    |
    | The OPTION KEYS are the ones `Illuminate\Hashing\ArgonHasher` reads, not
    | the ones `password_hash()` takes. That is not pedantry: `make()` builds its
    | `password_hash()` argument from `$options['memory']`, `$options['time']`, and
    | `$options['threads']`, and its own docblock names the resulting keys
    | `memory_cost` / `time_cost` / `threads`. Passing the `password_hash()`
    | spellings would be silently ignored and the driver would fall back to its
    | constructor defaults. `SecurityPolicy` therefore normalises both spellings.
    |
    | WHY THE WORK FACTOR IS A BASELINE AND NOT A CAPACITY RESULT. Argon2id is
    | memory-hard, so the binding constraint is per-process MEMORY, not CPU: at
    | `memory=65536` each concurrent login holds 64 MiB. `B-05` records peak
    | concurrency and staffing as `NOT CONFIRMED` with inferred value `NONE`, so
    | no measured capacity number exists to justify a higher cost and the
    | OWASP-aligned minimum is shipped instead. It is a FLOOR, not an optimum,
    | and it is the single most likely value to need raising once operations
    | supplies the deployment's real concurrency and memory ceiling. Treat it as
    | the open item it is, not as a settled decision.
    |
    | `SECURITY_PASSWORD_OPTIONS` is a `key=value,key=value` string so one
    | environment variable carries an algorithm-specific set. A malformed value
    | is a configuration defect and is refused by name, never coerced.
    */

    'password_hashing' => [
        'algorithm' => env('SECURITY_PASSWORD_ALGORITHM', 'argon2id'),
        'options' => env('SECURITY_PASSWORD_OPTIONS', 'memory=65536,time=2,threads=1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SEC-008 — session idle timeout and absolute lifetime
    |--------------------------------------------------------------------------
    |
    | `AC-T-004-03`: "Session idle timeout and absolute lifetime are enforced."
    | Both are enforced in `SessionSecurity::enforceLifetimes()`; these are the
    | numbers it compares against.
    |
    | Two anchors, two different attacks, and conflating them is the usual bug:
    |
    |   IDLE (900 s)     bounds the PRESENT-BUT-UNATTENDED workstation. Activity
    |                   extends it, so this is the control that caps a
    |                   walk-away-from-the-desk session. In a back office with a
    |                   shared or open station this is the ordinary case, not the
    |                   exotic one.
    |   ABSOLUTE (43200) bounds the CONTINUOUSLY-ACTIVE session. Activity does
    |                   NOT extend it. Without it, an attacker who keeps a
    |                   stolen cookie active never trips the idle timeout and the
    |                   session is good for as long as they care to send requests.
    |                   12 hours is a shift, not a week.
    |
    | `SEC-008` REQUIRES absolute > idle, and `SecurityPolicy` now REFUSES a
    | configuration that inverts or equals them: with absolute <= idle one of the
    | two controls can never bind, and which one goes dead is decided by a
    | mistyped number. That refusal is a new check, not a weakened one.
    |
    | Neither value is read from `config/session.php`, whose stock
    | `'lifetime' => 120` remains a decoy: reusing that number would choose a
    | session lifetime by accident, which is the exact outcome `T-004` §Risks
    | names. `test_laravels_stock_session_lifetime_is_not_adopted` holds the line.
    |
    | SECONDS, not minutes: a sub-minute policy cannot be expressed in minutes
    | without rounding, and a rounded security value is a value nobody chose.
    */

    'session' => [
        'idle_timeout_seconds' => env('SECURITY_SESSION_IDLE_TIMEOUT_SECONDS', 900),
        'absolute_lifetime_seconds' => env('SECURITY_SESSION_ABSOLUTE_LIFETIME_SECONDS', 43200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting and lockout — baseline pending B-05
    |--------------------------------------------------------------------------
    |
    | `AC-T-004-06` requires rate limiting AND defined lockout behaviour, and
    | `SEC-010` requires rate limiting on authentication. Both mechanisms are
    | implemented in `AuthenticationRateLimiter`.
    |
    | THE VALUES ARE TUNING, NOT TRUTH. `B-05` is `NOT CONFIRMED`, owned by PM +
    | operations, with inferred value `NONE`. A ceiling is a direct function of
    | how many staff authenticate from how many addresses at shift change, and
    | this repository does not hold that number. So these are the
    | `docs/SECURITY.md` §12.1.4 baselines, shipped so the architecture can run
    | and be tested, and they remain operationally unvalidated.
    |
    | | key                | value | what it bounds                                       |
    | |--------------------|-------|------------------------------------------------------|
    | | `max_attempts`     | 5     | failures per ACCOUNT inside `decay_seconds`           |
    | | `decay_seconds`    | 300   | the account window                                    |
    | | `ip_max_attempts`  | 30    | failures per CLIENT ADDRESS inside `ip_decay_seconds` |
    | | `ip_decay_seconds` | 300   | the address window                                    |
    | | `threshold`        | 5     | consecutive account failures that trigger a lockout   |
    | | `seconds`          | 900   | the lockout window                                    |
    |
    | WHY THE IP CEILING IS HIGHER THAN THE ACCOUNT CEILING, and why the IP
    | dimension does not lock out. They answer different questions:
    |
    |   ACCOUNT  "is someone guessing THIS identity?" A 5-per-5-minutes ceiling
    |            turns a spray of 100 leaked credentials at 20 accounts from an
    |            hours-long attack into a multi-day one. It is also the dimension
    |            that lockout applies to, because a lockout that removes one
    |            identity from service is recoverable by that identity's owner.
    |   ADDRESS  "is ONE source spraying many identities, or rotating to defeat
    |            the account ceiling?" It must be looser than the account
    |            ceiling, or a shared egress fails as one unit — and a 10-property
    |            group behind a single NAT or corporate proxy would then lock
    |            itself out entirely.
    |
    | The address dimension THROTTLES and does NOT LOCK OUT, and that asymmetry is
    | deliberate. A lockout is the strongest response and it is also the one an
    | attacker can aim at a colleague, so it is reserved for the account, where
    | the blast radius is one identity. The address dimension gets a ceiling
    | that sheds a spray without removing a whole property from service. A pure
    | per-account limit would let anyone lock out any known username; a pure
    | per-address limit would let one spray punish an office. Both dimensions
    | together bound the attack without either failure mode.
    |
    | `B-05` still gates the VALUES. It does not gate the architecture.
    */

    'authentication_rate_limit' => [
        'max_attempts' => env('SECURITY_AUTH_MAX_ATTEMPTS', 5),
        'decay_seconds' => env('SECURITY_AUTH_ATTEMPT_DECAY_SECONDS', 300),
        'ip_max_attempts' => env('SECURITY_AUTH_IP_MAX_ATTEMPTS', 30),
        'ip_decay_seconds' => env('SECURITY_AUTH_IP_DECAY_SECONDS', 300),
    ],

    'lockout' => [
        'threshold' => env('SECURITY_AUTH_LOCKOUT_THRESHOLD', 5),
        'seconds' => env('SECURITY_AUTH_LOCKOUT_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Step-up freshness window
    |--------------------------------------------------------------------------
    |
    | `AC-T-004-05` requires step-up on the canonical seven operations
    | (`docs/SECURITY.md` §6.1, cross-referenced by `docs/API-SPEC.md` §3.11.1
    | and `PRD.md` `SEC-018`). How long a completed step-up stays valid is a
    | policy value: 300 seconds.
    |
    | WHY A SEPARATE CLOCK, NOT THE SESSION IDLE TIMEOUT. A step-up is a RECENT
    | proof of presence for a SPECIFIC operation; a session is a general grant.
    | Using one as the other is the bug this value exists to prevent: a 12-hour
    | session must not let a step-up taken once authorize a refund at hour 11,
    | and a 15-minute idle timeout must not expire a step-up in the middle of
    | the operation it was taken for. `StepUpGate` compares this window against
    | `auth.step_up_at` alone.
    |
    | WHY IT IS BOUND TO ONE OPERATION. `auth.step_up_operation` stores which
    | operation was satisfied, and the gate compares it against the operation
    | being attempted. A step-up taken to reveal a masked document number must
    | not authorize a refund. Cross-operation reuse is refused, not merely
    | time-limited.
    |
    | The freshness window has NO registered Decision ID. `SEC-008` covers the
    | two session lifetimes; no `SEC-008-C` exists, and none is created here.
    |
    | The mechanism that SATISFIES a step-up is a separate question. This value
    | is required either way — a gate that compares against an unset window
    | would either refuse every sensitive operation or silently allow all of
    | them, and choosing between those is the decision this file records.
    */

    'step_up' => [
        'freshness_seconds' => env('SECURITY_STEP_UP_FRESHNESS_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | MFA — mechanism and coverage
    |--------------------------------------------------------------------------
    |
    | `SEC-001` requires MFA to be AVAILABLE, `ADR-0016:23` requires "MFA
    | challenge and failure" to be audited, and `docs/SECURITY.md` §6 records
    | that MFA is "required for privileged roles and step-up flows".
    |
    | `primary` is TOTP (RFC 6238) and `preferred` is passkey / WebAuthn, which
    | is origin-bound and therefore genuinely phishing-resistant. SMS and email
    | are NOT offered: SMS is defeated by SIM swap and SS7 and is a cost-DoS
    | vector against the sender, and email is usually the same single
    | authenticated session the credential-reset path already uses, so it is not
    | a second factor in the sense that matters.
    |
    | `required_roles` is DERIVED, not ranked. The rule is mechanical: a role
    | requires MFA if `ADR-0014` §5 gives it the power to perform one of the
    | seven step-up operations, or any operation that changes authorization,
    | money, or guest identity data. Eight of the twelve roles qualify. The
    | remaining four — Front Desk Agent, Reservation Agent, Housekeeping, POS
    | Cashier — are each explicitly excluded from those powers in §5, and POS
    | Cashier holds no Phase A permissions at all.
    |
    | `Auditor` and `Support` are INCLUDED on the power they hold, not on trust.
    | §5 gives Support "no grant without a named approver and an expiry" and
    | gives Auditor read access to everything in scope including the audit
    | trail and guest identity documents.
    |
    | ENROLMENT AND RECOVERY ARE NOT CONFIGURED HERE, AND THAT IS A REPORTED GAP
    | rather than an omission. A recovery workflow needs an accountable decision:
    | who may reset a second factor, how a staff member who lost their authenticator
    | is identified at 02:00, and what a recovery event emits. `C-10` has no named
    | Security Owner to answer that, so `docs/SECURITY.md` §12.1.1 records it as
    | `BLOCKED — ACCOUNTABILITY`. Inventing an operator, an approval path, and a
    | queue would fabricate an accountability assignment and a security workflow.
    | `MfaPolicy` and `TotpVerifier` are therefore built and tested as reusable
    | primitives with NO enrolment endpoint, NO secret store, and NO recovery
    | path. `docs/DATA-MODEL.md` §2 reserves `mfa_secrets`; no migration creates
    | it here, because a table with no governed lifecycle behind it is a schema
    | that records secrets nobody has decided how to protect.
    */

    'mfa' => [
        'primary' => env('SECURITY_MFA_PRIMARY', 'totp'),
        'preferred' => env('SECURITY_MFA_PREFERRED', 'passkey'),
        'digits' => 6,
        'period_seconds' => 30,
        'window' => 1,
        'required_roles' => [
            'GROUP_MANAGER',
            'HOTEL_MANAGER',
            'FINANCE',
            'NIGHT_AUDITOR',
            'COMPLIANCE_OFFICER',
            'REVENUE_MANAGER',
            'AUDITOR',
            'SUPPORT',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security headers
    |--------------------------------------------------------------------------
    |
    | `SEC-009`: "security headers on ALL responses". `API-SPEC.md` §5 requires
    | them on all responses. `SecurityHeaders` middleware is registered
    | GLOBALLY in `bootstrap/app.php`, so the set is applied once and reaches
    | every surface: successes, 4xx, the framework's own 404 and 405, the
    | `DomainFailure` renderer, and unhandled exceptions. A per-endpoint or
    | per-controller approach cannot satisfy "all responses" — it misses the
    | responses nobody wrote a controller for.
    |
    | Headers are applied to the RESPONSE OBJECT after generation, which is why an
    | error response is covered too: a framed 500 is still a clickjacking target
    | and a `nosniff`-less error body is still a type-confusion surface.
    |
    | THE CSP IS A BASELINE, NOT A SURPRISE. `default-src 'self'` with
    | `frame-ancestors 'none'`, `base-uri 'self'`, and `object-src 'none'`. This
    | application is a JSON API plus server-rendered error bodies and loads no
    | third-party origin, so a `'self'`-only default is compatible; asset
    | hosting is not yet chosen (`B-03`), and if a chosen CDN later requires an
    | origin it is added HERE — explicitly, reviewed, and never by mutating a
    | header at a call site. `'unsafe-inline'` and `'unsafe-eval'` are NOT
    | enabled, because a policy that permits them is not a policy.
    |
    | HSTS IS CONDITIONAL AND OFF BY DEFAULT. `Strict-Transport-Security` is the
    | one header here that can CAUSE an outage rather than prevent one: once a
    | browser has seen it, it refuses plaintext for `max-age` and will not let
    | the user click through, and `includeSubDomains` extends that to hosts this
    | application does not control. Emitting it before TLS is correct EVERYWHERE
    | is an availability decision about the whole estate, not a constant. The
    | deployment guarantee is `B-03` and it has not been established, so
    | `hsts.enabled` ships `false` and the header is simply absent. An operator
    | who HAS the guarantee sets `SECURITY_HSTS_ENABLED=true` and nothing else
    | changes.
    |
    | `x_content_type_options`, `x_frame_options`, and `referrer_policy` are NOT
    | conditional. `nosniff` stops a browser re-interpreting a served type;
    | `DENY` is the legacy companion to `frame-ancestors 'none'` for older
    | agents; `no-referrer` keeps guest-identity URLs out of a `Referer` on
    | outbound navigation. `permissions_policy` denies three capabilities this
    | application never requests, which removes the prompt surface entirely.
    */

    'security_headers' => [
        'content_security_policy' => env(
            'SECURITY_CSP',
            "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
        ),
        'x_content_type_options' => env('SECURITY_X_CONTENT_TYPE_OPTIONS', 'nosniff'),
        'x_frame_options' => env('SECURITY_X_FRAME_OPTIONS', 'DENY'),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'no-referrer'),
        'permissions_policy' => env(
            'SECURITY_PERMISSIONS_POLICY',
            'geolocation=(), camera=(), microphone=()',
        ),
        'hsts' => [
            // OFF until the deployment can guarantee HTTPS on every host, every
            // response path, redirects, and errors. See the block comment above.
            'enabled' => env('SECURITY_HSTS_ENABLED', false),
            'max_age' => env('SECURITY_HSTS_MAX_AGE', 31536000),
            'include_sub_domains' => env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),
            'preload' => env('SECURITY_HSTS_PRELOAD', false),
        ],
    ],

];
