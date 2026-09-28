<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Auth\Mfa\MfaMethod;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * A security value the deployment cannot use as configured.
 *
 * This exists so that an unusable security decision is a loud, typed, testable
 * refusal rather than a silent fallback. It now covers three distinct situations,
 * and they are NOT the same thing:
 *
 *   1. A value that is BLANK, MALFORMED, NON-POSITIVE, or the WRONG SHAPE. The
 *      shipped baselines in `config/security.php` mean an ordinary
 *      unconfigured deployment no longer lands here — but somebody broke the
 *      configuration, and the safe answer to a broken security instruction is to
 *      stop rather than to guess.
 *   2. A value that VIOLATES A NORMATIVE INVARIANT, which is its own case and
 *      now has its own factory: `SEC-008` requires the absolute session lifetime
 *      to exceed the idle timeout, and a configuration that inverts them leaves
 *      one control dead.
 *   3. A value that names a capability the project REFUSES — currently an SMS or
 *      email MFA factor. The refusal explains itself, because "unrecognised
 *      value" would send an operator hunting for a typo when the real answer is
 *      that the option will not be offered.
 *
 * The error code is `SERVICE_UNAVAILABLE` and not a bespoke code. `SEC-009`
 * specifies CSRF and headers but says nothing about an unconfigured-security
 * error, and inventing a new code would contradict `ADR-0019` (the code
 * vocabulary is fixed by `API-SPEC.md` §2). 503 is also the honest HTTP answer:
 * the server CANNOT correctly perform this operation right now, which is
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
            message: 'Authentication is unavailable: the password hashing algorithm is unset, names '
                .'an algorithm this build does not register, or its work-factor options are '
                .'malformed (SEC-007). Nothing is substituted for the configured algorithm.',
        );
    }

    public static function sessionIdleTimeout(): self
    {
        return new self(
            decision: 'SEC-008',
            setting: 'security.session.idle_timeout_seconds',
            message: 'Authentication is unavailable: the session idle timeout is unset or is not a '
                .'positive number of seconds (SEC-008). A session lifetime is never guessed.',
        );
    }

    public static function sessionAbsoluteLifetime(): self
    {
        return new self(
            decision: 'SEC-008',
            setting: 'security.session.absolute_lifetime_seconds',
            message: 'Authentication is unavailable: the session absolute lifetime is unset or is not '
                .'a positive number of seconds (SEC-008). A session lifetime is never guessed.',
        );
    }

    /**
     * `SEC-008`'s invariant, enforced rather than assumed.
     *
     * The two numbers are reported back so the operator can see which setting to
     * change. That is safe: both are configuration values, not secrets, and
     * `API-SPEC.md` §1.6 prohibits stacks, SQL, hostnames, and secrets rather
     * than a restatement of the operator's own policy.
     *
     * The absolute lifetime is NOT silently raised to make the pair consistent.
     * Clamping would resolve the error by replacing a number the operator typed
     * with a different number they did not, which is the "security decision made
     * by accident" this whole class exists to prevent.
     */
    public static function sessionLifetimeInvariant(int $idle, int $absolute): self
    {
        return new self(
            decision: 'SEC-008',
            setting: 'security.session.absolute_lifetime_seconds',
            message: 'Authentication is unavailable: SEC-008 requires the session absolute lifetime '
                ."({$absolute}s) to be strictly greater than the idle timeout ({$idle}s). One of the "
                .'two controls cannot bind under this configuration, and the value is not adjusted '
                .'automatically.',
        );
    }

    public static function authenticationRateLimit(): self
    {
        return new self(
            decision: 'B-05',
            setting: 'security.authentication_rate_limit.max_attempts',
            message: 'Authentication is unavailable: the per-account attempt ceiling is unset or is '
                .'not a positive number (B-05). An unconfigured attempt ceiling is not a safe '
                .'ceiling, so authentication is refused rather than unlimited.',
        );
    }

    public static function ipRateLimit(): self
    {
        return new self(
            decision: 'B-05',
            setting: 'security.authentication_rate_limit.ip_max_attempts',
            message: 'Authentication is unavailable: the per-address attempt ceiling is unset or is '
                .'not a positive number (B-05). Without it, a caller rotating source addresses '
                .'against one account is never throttled.',
        );
    }

    public static function lockout(): self
    {
        return new self(
            decision: 'B-05',
            setting: 'security.lockout.threshold',
            message: 'Authentication is unavailable: lockout values are unset or are not positive '
                .'numbers (B-05).',
        );
    }

    public static function stepUpFreshness(): self
    {
        return new self(
            decision: 'SEC-008',
            setting: 'security.step_up.freshness_seconds',
            message: 'Step-up authentication is unavailable: the freshness window is unset or is not '
                .'a positive number of seconds. A gate that compares against an unset window would '
                .'either refuse every sensitive operation or allow all of them.',
        );
    }

    /**
     * A second factor this project will not accept, including SMS and email.
     *
     * The refusal carries the REASON, because a bare "invalid value" for
     * something an operator deliberately chose is the most frustrating possible
     * answer. The reason names the attack it exists to defeat, and the fallback
     * so the operator has somewhere to go.
     */
    public static function unsupportedMfaMethod(string $requested, MfaMethod $fallback): self
    {
        $refusal = MfaMethod::tryFrom(strtolower($requested))?->refusalReason();

        $message = $refusal ?? sprintf(
            '"%s" is not a second factor this project recognises. Supported: %s.',
            $requested,
            implode(', ', array_map(
                static fn (MfaMethod $method): string => $method->value,
                array_filter(MfaMethod::cases(), static fn (MfaMethod $m): bool => $m->isAcceptableFactor()),
            )),
        );

        return new self(
            decision: 'SEC-001',
            setting: 'security.mfa.primary',
            message: $message.' Fall back to the configured method ('.$fallback->value.').',
        );
    }

    /**
     * A value is present but is not a shape this policy can read.
     *
     * Distinct from "unresolved": something HAS been set, and it is not a
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

    /**
     * A numeric value that is a valid number in the wrong range.
     *
     * Separate from `malformedValue` because the operator's fix is different: the
     * type is right and the magnitude is not, so the response has to say what
     * range is defensible rather than only that the shape is wrong.
     */
    public static function valueOutOfRange(string $key, int $min, int $max): self
    {
        return new self(
            decision: 'SEC-007/SEC-008/B-05',
            setting: 'security.'.$key,
            message: 'A security policy value is outside the range this control can work in '
                ."({$min}–{$max}). The value is not clamped: clamping would decide a security "
                .'parameter without the owner.',
        );
    }
}
