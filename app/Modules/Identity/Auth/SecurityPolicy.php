<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\AbstractHasher;
use Illuminate\Support\Facades\Hash;

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
    ) {
    }

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = config('security', []);

        return new self($config);
    }

    /**
     * `SEC-007`. The hasher is the framework's own: Laravel ships the algorithm
     * implementations and this only selects WHICH one is used and supplies its
     * cost parameters. No custom cryptographic primitive is introduced — a
     * hand-rolled hasher is exactly what `T-004` forbids.
     *
     * @return array{0: Hasher, 1: string}
     */
    public function passwordHasher(): array
    {
        $algorithm = $this->raw('password_hashing.algorithm');

        if ($algorithm === null) {
            throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
        }

        $options = $this->raw('password_hashing.options');
        $hasher = Hash::manager()->driver($algorithm);

        if (! $hasher instanceof AbstractHasher) {
            // An unknown algorithm name would otherwise be hashed with a
            // silently substituted default, which is the same failure as
            // inventing the value in the first place.
            throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
        }

        $this->applyWorkFactor($hasher, $options);

        return [$hasher, $algorithm];
    }

    /**
     * The algorithm a stored hash was produced with.
     *
     * A stored hash carries its own algorithm and parameters (bcrypt's cost, or
     * Argon2's memory/time lanes), and `Hasher::verify()` reads them from the
     * hash string. So a deployment can later CHANGE the approved algorithm and
     * still authenticate hashes written under the previous one. That is a
     * property of the framework's encoding, used here rather than a policy this
     * task invents.
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
     * The cost parameters of the chosen algorithm.
     *
     * `options` is a `key=value` string so the decision can be recorded in the
     * environment without inventing a per-algorithm array in configuration for
     * an algorithm that has not been chosen. A malformed value is a
     * configuration defect and is reported as one.
     *
     * @return array<string, int>
     */
    private function applyWorkFactor(AbstractHasher $hasher, ?string $options): void
    {
        if ($options === null || trim($options) === '') {
            return;
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

        if ($parsed === []) {
            return;
        }

        $hasher->setOptions($parsed);
    }

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
            throw SecurityPolicyUnresolved::sessionIdleTimeout();
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
