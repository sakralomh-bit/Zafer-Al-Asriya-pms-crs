<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * The single place an authentication security value is read.
 *
 * Every value here is recorded as UNDECIDED in the governing documents
 * (`config/security.php` documents each one and where it comes from). This
 * class exists so that reading one has exactly one outcome: the approved value,
 * or a refusal. It never returns a default, because a default IS the invention
 * the `T-004` risk note warns against — *"A wrong session lifetime is a
 * security decision made by accident."*
 *
 * Nothing else in the authentication path reads `config/security.php` or
 * `config/session.php` for a policy number. `T-004` is the only task that needs
 * these, and they enter through here.
 *
 * A test may supply values explicitly (see `tests/Support/ResolvesSecurityPolicy`),
 * which is how the mechanisms are proven without asserting a production value
 * that the security owner has not made. That is the same arrangement the money
 * code uses for `C-04`: the mechanism is complete, the policy is the caller's,
 * and no test result is allowed to stand in for the decision.
 */
final class SecurityPolicy
{
    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('security', []);

        return new self($config);
    }

    /**
     * `SEC-007`. The hasher is the framework's own: Laravel ships the algorithm
     * implementations and this only selects WHICH one is used. No custom
     * cryptographic primitive is introduced — a hand-rolled hasher is exactly
     * what `T-004` forbids.
     *
     * AN UNREGISTERED NAME IS A POLICY FAILURE, NOT A PROGRAMMING ERROR.
     * `Illuminate\Support\Manager::createDriver()` throws a raw
     * `\InvalidArgumentException` for a driver it cannot build, so a
     * misspelled or unregistered algorithm name would otherwise escape this
     * class as a framework exception. That is the wrong shape for this policy:
     * a bad algorithm name is `SEC-007` being set incorrectly, and the answer
     * must be the same fail-closed `SERVICE_UNAVAILABLE` as every other
     * unresolved value — not a 500, and not a stack trace on the way out.
     *
     * This is NOT a fallback. No algorithm is substituted for the unknown one,
     * `driver()` is not retried with a different name, and authentication
     * still cannot proceed.
     *
     * The manager is taken as the concrete `HashManager` rather than through
     * `Hash::driver()`, for two reasons that are not stylistic:
     *
     *  - `Manager::driver()` is annotated `@throws \InvalidArgumentException`.
     *    The `Hash` FACADE's generated `@method` proxy is not, so a call
     *    through the facade hides the throw from static analysis and the
     *    `catch` below reads as unreachable. The exception is real — the
     *    annotation is on the method that actually gets called.
     *  - `Manager::driver()` is declared `@return mixed`, so the `instanceof
     *    Hasher` check below is a live narrowing, not defensive dead code.
     *
     * It is the SAME singleton either way (`Hash::getFacadeRoot()` returns the
     * `'hash'` binding), so any driver registered with `Hash::extend()` is
     * still visible here.
     *
     * @return array{0: Hasher, 1: string}
     */
    public function passwordHasher(): array
    {
        $algorithm = $this->raw('password_hashing.algorithm');

        if ($algorithm === null) {
            throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
        }

        $manager = Hash::getFacadeRoot();

        if (! $manager instanceof HashManager) {
            // The `hash` binding resolving to something else would mean the
            // hashing subsystem is not what this policy was written against.
            // Refusing is the only safe reading.
            throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
        }

        try {
            $hasher = $manager->driver($algorithm);
        } catch (InvalidArgumentException) {
            // Restated in the policy's own vocabulary and named as `SEC-007`, so
            // the operator is sent to the decision record rather than to a
            // framework trace. The message does not quote the rejected name:
            // `SecurityPolicyUnresolved` names the SETTING, which is what has
            // to be corrected.
            throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
        }

        if (! $hasher instanceof Hasher) {
            // Live, not defensive: `Manager::driver()` returns `mixed`. A hasher
            // that cannot verify a password is not something to hand to the
            // login path.
            throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
        }

        return [$hasher, $algorithm];
    }

    /**
     * The cost parameters of the chosen algorithm, parsed and validated.
     *
     * ============================ READ THIS BEFORE USING IT ============================
     * THIS VALUE HAS NO EFFECT AT RUNTIME IN `T-004`. `T-004` contains no
     * password-CREATION path, and a work factor is only consumed when a hash is
     * created. On the verification path — the only hashing `T-004` performs —
     * Laravel IGNORES the `$options` argument entirely: `AbstractHasher::check()`
     * and `BcryptHasher::check()` both call `password_verify($value, $hashedValue)`
     * with no options, and a stored hash carries its own cost. Passing these
     * options to `check()` would therefore be a call that provably does nothing.
     * =================================================================================
     *
     * It exists for two honest reasons, and neither is "the work factor is
     * applied here":
     *
     *  1. It is the ONE place the value is parsed, so a malformed setting is
     *     refused loudly and by name instead of being silently ignored by every
     *     caller that does not care about it.
     *  2. When a creation, provisioning, or hash-upgrade path is ever decided
     *     and built, it has a single source of truth rather than a second
     *     parser written at that time.
     *
     * Whether `T-004` should OWN such a path at all is not settled: creating
     * passwords is not in `AC-T-004-01`…`09`, and adding one to give this
     * method a caller would be inventing a security capability this task was
     * not asked for. No such flow is built here.
     *
     * `options` is a `key=value` string so the decision can be recorded in the
     * environment without inventing a per-algorithm array in configuration for
     * an algorithm that has not been chosen. A malformed value is a
     * configuration defect and is reported as one.
     *
     * Returns an EMPTY array when the value is unresolved, so that a caller has
     * nothing to decide. That is not a default: nothing here chooses an
     * algorithm or a work factor.
     *
     * @return array<string, int>
     *
     * @throws SecurityPolicyUnresolved when the value is malformed
     */
    public function passwordHashingOptions(): array
    {
        $options = $this->raw('password_hashing.options');

        if ($options === null || trim($options) === '') {
            return [];
        }

        $parsed = [];

        foreach (explode(',', $options) as $pair) {
            if (! str_contains($pair, '=')) {
                throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
            }

            [$key, $value] = array_map(trim(...), explode('=', $pair, 2));

            if ($key === '' || $value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
                throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
            }

            $parsed[$key] = (int) $value;
        }

        return $parsed;
    }

    /**
     * The algorithm a stored hash was produced with.
     *
     * A stored hash carries its own algorithm and parameters (bcrypt's cost, or
     * Argon2's memory/time lanes), and `Hasher::check()` reads them from the
     * hash string. So a deployment can later CHANGE the approved algorithm and
     * still authenticate hashes written under the previous one. That is a
     * property of the framework's encoding, used here rather than a policy this
     * task invents.
     *
     * Note there is no `Hasher::verify()` in this framework version; the
     * contract method is `check()`. An earlier revision of this file named the
     * non-existent method, and an annotation can send an implementer looking
     * for an API that is not there.
     */
    public function storedHashAlgorithm(): ?string
    {
        return $this->raw('password_hashing.algorithm');
    }

    /**
     * `SEC-008`. Seconds since the last activity.
     */
    public function sessionIdleTimeoutSeconds(): int
    {
        return $this->positiveInteger('session.idle_timeout_seconds', SecurityPolicyUnresolved::sessionIdleTimeout(...));
    }

    /**
     * `SEC-008`. Seconds since authentication, regardless of activity.
     */
    public function sessionAbsoluteLifetimeSeconds(): int
    {
        return $this->positiveInteger('session.absolute_lifetime_seconds', SecurityPolicyUnresolved::sessionAbsoluteLifetime(...));
    }

    /**
     * `B-05`. Ceiling of failed attempts inside the decay window.
     *
     * @return array{attempts: int, decay: int}
     */
    public function authenticationRateLimit(): array
    {
        $attempts = $this->raw('authentication_rate_limit.max_attempts');
        $decay = $this->raw('authentication_rate_limit.decay_seconds');

        if ($attempts === null || $decay === null) {
            throw SecurityPolicyUnresolved::authenticationRateLimit();
        }

        return [
            'attempts' => $this->positiveInteger('authentication_rate_limit.max_attempts', SecurityPolicyUnresolved::authenticationRateLimit(...)),
            'decay' => $this->positiveInteger('authentication_rate_limit.decay_seconds', SecurityPolicyUnresolved::authenticationRateLimit(...)),
        ];
    }

    /**
     * @return array{threshold: int, seconds: int}
     */
    public function lockout(): array
    {
        $threshold = $this->raw('lockout.threshold');
        $seconds = $this->raw('lockout.seconds');

        if ($threshold === null || $seconds === null) {
            throw SecurityPolicyUnresolved::lockout();
        }

        return [
            'threshold' => $this->positiveInteger('lockout.threshold', SecurityPolicyUnresolved::lockout(...)),
            'seconds' => $this->positiveInteger('lockout.seconds', SecurityPolicyUnresolved::lockout(...)),
        ];
    }

    /**
     * How long a completed step-up satisfies a sensitive operation.
     */
    public function stepUpFreshnessSeconds(): int
    {
        return $this->positiveInteger('step_up.freshness_seconds', SecurityPolicyUnresolved::stepUpFreshness(...));
    }

    public function contentSecurityPolicy(): ?string
    {
        return $this->nullableString('security_headers.content_security_policy');
    }

    public function strictTransportSecurity(): ?string
    {
        return $this->nullableString('security_headers.strict_transport_security');
    }

    public function referrerPolicy(): ?string
    {
        return $this->nullableString('security_headers.referrer_policy');
    }

    public function permissionsPolicy(): ?string
    {
        return $this->nullableString('security_headers.permissions_policy');
    }

    /**
     * Read a raw policy value as a string.
     *
     * Integers are accepted because environment variables are strings but a
     * config array may hold a literal integer, and treating those two as
     * different would be a surprise rather than a safety property. Any OTHER
     * type is a configuration defect and is named as one, rather than being
     * coerced into a value nobody decided.
     */
    private function raw(string $key): ?string
    {
        $value = $this->value($key);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw SecurityPolicyUnresolved::malformedValue($key);
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->value($key);

        if ($value === null) {
            return null;
        }

        $value = is_string($value) ? trim($value) : (string) $value;

        return $value === '' ? null : $value;
    }

    private function positiveInteger(string $key, callable $onMissing): int
    {
        $raw = $this->raw($key);

        if ($raw === null || preg_match('/^[0-9]+$/', $raw) !== 1) {
            throw $onMissing();
        }

        $value = (int) $raw;

        if ($value < 1) {
            throw $onMissing();
        }

        return $value;
    }

    private function value(string $key): mixed
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
