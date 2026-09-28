<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * A security value the governing documents record as UNDECIDED, is required to
 * proceed, and has therefore not been invented.
 *
 * This exists so that an unresolved decision is a loud, typed, testable refusal
 * rather than a silent default. `docs/API-SPEC.md` §6 lists four such values
 * under "Unresolved before implementation", and `T-004` §Risks says: *"SEC-007
 * and SEC-008 are undefined, so this task is blocked for those specific values
 * — implement the mechanism, and do not invent the parameters."*
 *
 * The error code is `SERVICE_UNAVAILABLE` and not a bespoke code. `SEC-009`
 * specifies CSRF and headers but says nothing about an unconfigured-security
 * error, and inventing a new code would contradict `ADR-0019` (the code
 * vocabulary is fixed by `API-SPEC.md` §2). 503 is also the honest HTTP
 * answer: the server CANNOT correctly perform this operation right now, which is
 * different from refusing the caller (401/403) and different from the caller
 * being wrong (422).
 *
 * The message names the unresolved decision and the owner. That is deliberate
 * and safe: the decision ids and owners are already published in
 * `docs/SECURITY.md` §12, and an operator seeing `SEC-007` learns which meeting
 * to book. Nothing secret is disclosed — `API-SPEC.md` §1.6 prohibits stacks,
 * SQL, hostnames, and secrets, not a reference to an internal decision record.
 */
final class SecurityPolicyUnresolved extends DomainFailure
{
    private function __construct(
        public readonly string $decision,
        public readonly string $setting,
        string $message,
    ) {
        parent::__construct(ErrorCode::ServiceUnavailable, $message);
    }

    public static function passwordHashingAlgorithm(): self
    {
        return new self(
            decision: 'SEC-007',
            setting: 'security.password_hashing.algorithm',
            message: 'Authentication is unavailable: the password hashing algorithm has not been '
                .'approved by the security owner (SEC-007). This deployment cannot authenticate '
                .'anyone until that decision is recorded.',
        );
    }

    public static function sessionIdleTimeout(): self
    {
        return new self(
            decision: 'SEC-008',
            setting: 'security.session.idle_timeout_seconds',
            message: 'Authentication is unavailable: the session idle timeout has not been set by '
                .'the security owner (SEC-008). This deployment cannot start sessions until that '
                .'decision is recorded.',
        );
    }

    public static function sessionAbsoluteLifetime(): self
    {
        return new self(
            decision: 'SEC-008',
            setting: 'security.session.absolute_lifetime_seconds',
            message: 'Authentication is unavailable: the session absolute lifetime has not been set '
                .'by the security owner (SEC-008). This deployment cannot start sessions until that '
                .'decision is recorded.',
        );
    }

    public static function authenticationRateLimit(): self
    {
        return new self(
            decision: 'B-05',
            setting: 'security.authentication_rate_limit.max_attempts',
            message: 'Authentication is unavailable: rate-limit values have not been set (they depend '
                .'on the operating scale, B-05). An unconfigured attempt ceiling is not a safe '
                .'ceiling, so authentication is refused rather than unlimited.',
        );
    }

    public static function lockout(): self
    {
        return new self(
            decision: 'B-05',
            setting: 'security.lockout.threshold',
            message: 'Authentication is unavailable: lockout values have not been set (they depend '
                .'on the operating scale, B-05).',
        );
    }

    public static function stepUpFreshness(): self
    {
        return new self(
            decision: 'SEC-008',
            setting: 'security.step_up.freshness_seconds',
            message: 'Step-up authentication is unavailable: how long a completed step-up remains '
                .'valid has not been decided (SEC-008).',
        );
    }

    /**
     * A value is present but is not a shape this policy can read.
     *
     * Distinct from "unresolved": the owner HAS set something, and it is not a
     * number or a string. Reporting it as unresolved would send an operator to
     * the decision record when the actual fix is the environment value itself.
     */
    public static function malformedValue(string $key): self
    {
        return new self(
            decision: 'SEC-007/SEC-008/B-05',
            setting: 'security.'.$key,
            message: 'A security policy value is set to an unusable shape. The setting is named in '
                .'this response; the decision itself has not been changed.',
        );
    }
}
