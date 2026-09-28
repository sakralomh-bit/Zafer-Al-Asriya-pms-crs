<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use App\Modules\Identity\Auth\Mfa\MfaMethod;
use App\Modules\Identity\Auth\Mfa\TotpParameters;
use App\Modules\Identity\Authorization\Role;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\ArgonHasher;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * The single place an authentication security value is read.
 *
 * Every value here is an IMPLEMENTED TECHNICAL BASELINE recorded in
 * `config/security.php` and analysed in `docs/SECURITY.md` §12.1 — chosen under
 * delegated technical authority, overridable by environment variable, and NOT a
 * registered decision (`docs/PRD.md` §15 holds `DR-001` … `DR-014` only).
 *
 * This class exists so that reading one has exactly one outcome: the configured
 * value, or a refusal. It never invents one.
 *
 * THE LINE BETWEEN A BASELINE AND AN INVENTED VALUE. Before the baseline was
 * recorded, every value here was `null` and a `null` refused, because a default
 * IS the invention `T-004` §Risks warns against — *"A wrong session lifetime is
 * a security decision made by accident."* That protection is retained where it
 * still means something, and only there:
 *
 *   - A BLANK, NON-NUMERIC, NON-POSITIVE, or wrongly-typed value REFUSES
 *     (503 `SERVICE_UNAVAILABLE`). Somebody broke the configuration; the
 *     baseline is not a licence to paper over it.
 *   - A value explicitly emptied in the environment REFUSES rather than
 *     silently reverting to the default. "Set it to nothing" is an
 *     instruction, and the safe answer to an unfollowable security instruction
 *     is to stop.
 *   - `SEC-008`'s INVARIANT is now enforced: an absolute lifetime that is not
 *     strictly greater than the idle timeout REFUSES.
 *
 * A shipped baseline is a documented engineering choice with a recorded
 * rationale. A coerced or defaulted-at-the-call-site value is an accident. Only
 * one of those is a decision, and this class accepts only the first.
 *
 * Nothing else in the authentication path reads `config/security.php` or
 * `config/session.php` for a policy number. `T-004` is the only task that needs
 * these, and they enter through here.
 *
 * A test may supply values explicitly (see `tests/Support/ResolvesSecurityPolicy`),
 * which is how the mechanisms are proven against deliberately non-baseline
 * numbers. The same arrangement the money code uses for `C-04`: the mechanism
 * is complete, the policy is the caller's.
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

        return [$this->applyWorkFactor($hasher, $algorithm), $algorithm];
    }

    /**
     * Push the configured work factor onto the resolved hasher.
     *
     * WHY THIS IS NEEDED AT ALL, because `Hash::driver()` looks like it should
     * already be configured. It is not:
     *
     *   - `HashManager::createArgon2idDriver()` reads `config('hashing.argon')`,
     *     and THIS PROJECT SHIPS NO `config/hashing.php`. The manager therefore
     *     constructs the driver with an empty option array and ArgonHasher's
     *     own defaults apply: `memory = 1024` KiB.
     *
     * 1 MiB is a DECOY, not a policy. It is Laravel's constructor default, it
     * is an order of magnitude below the OWASP-aligned minimum, and leaving it
     * in place would mean the shipped "Argon2id" is a weak work factor wearing
     * a strong algorithm's name — which is the most misleading configuration
     * available, because it looks correct in review.
     *
     * WHY VERIFICATION IS UNAFFECTED AND CREATION IS NOT. `Hasher::check()`
     * calls `password_verify($value, $hashedValue)` with no options at all; a
     * stored Argon2 hash carries its own `memory_cost` / `time_cost` / `threads`
     * in the hash string, and those are what verification actually costs. So
     * this call has NO effect on the login path and cannot change how an
     * existing password verifies. It governs `make()` and `needsRehash()`,
     * which is where a work factor belongs.
     *
     * The setters are the framework's own public API for exactly this
     * (`ArgonHasher::setMemory` / `setTime` / `setThreads`). A bcrypt driver is
     * left alone: it has a different cost model (`rounds`, no memory lane), and
     * an operator who selects it is selecting a different algorithm whose
     * parameters are their business.
     *
     * `HashManager` caches driver instances, so this mutates the shared
     * instance. That is safe and idempotent — it re-applies the same configured
     * numbers on every call, and the alternative (rebuilding a hasher per
     * request) would trade a connection-pooled singleton for garbage.
     */
    private function applyWorkFactor(Hasher $hasher, string $algorithm): Hasher
    {
        if (! $hasher instanceof ArgonHasher) {
            return $hasher;
        }

        $options = $this->passwordHashingOptions();

        if ($options === []) {
            return $hasher;
        }

        if (isset($options['memory'])) {
            $hasher->setMemory($options['memory']);
        }

        if (isset($options['time'])) {
            $hasher->setTime($options['time']);
        }

        if (isset($options['threads'])) {
            $hasher->setThreads($options['threads']);
        }

        unset($algorithm);

        return $hasher;
    }

    /**
     * The cost parameters of the chosen algorithm, parsed and validated.
     *
     * ============================ READ THIS BEFORE USING IT ============================
     * THIS VALUE HAS NO EFFECT ON VERIFICATION, AND THAT IS NOT A DEFECT.
     * Verification is the only hashing `T-004` performs on the login path, and
     * Laravel IGNORES `$options` there: `AbstractHasher::check()` and
     * `Argon2IdHasher::check()` both call `password_verify($value, $hashedValue)`
     * with no options, and a stored hash carries its own cost. Passing these
     * options to `check()` would be a call that provably does nothing.
     *
     * It DOES govern hash CREATION and `needsRehash()`, and it is applied to the
     * resolved driver by `applyWorkFactor()` above — which matters because this
     * project ships no `config/hashing.php` and Laravel's Argon2id constructor
     * default is 1 MiB. See the comment on that method.
     * =================================================================================
     *
     * KEY SPELLINGS ARE NORMALISED, because the two sets of names differ and only
     * one of them works. `Illuminate\Hashing\ArgonHasher::make()` reads
     * `$options['memory']`, `$options['time']`, and `$options['threads']`, then
     * hands them to `password_hash()` under the names `memory_cost`,
     * `time_cost`, and `threads`. The driver therefore wants `memory` / `time` /
     * `threads`, while the PHP function and most operator documentation speak of
     * `memory_cost` / `time_cost`. Accepting only one set means either a silently
     * ignored configuration or a needlessly obscure one, so both spellings are
     * accepted and the driver's spelling is what comes back.
     *
     * `options` is a `key=value,key=value` string so one environment variable
     * carries an algorithm-specific set. A malformed value is a configuration
     * defect and is reported as one — never coerced, never partially applied.
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

        foreach (explode(',', $options) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || ! str_contains($segment, '=')) {
                throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
            }

            [$key, $value] = array_map(trim(...), explode('=', $segment, 2));

            $normalisedKey = self::normaliseOptionKey($key);
            $normalisedValue = self::normaliseOptionValue($value);

            if ($normalisedKey === null || $normalisedValue === null) {
                throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
            }

            if (array_key_exists($normalisedKey, $parsed)) {
                // `memory=1,time=1,memory=2` is a setting nobody decided which
                // half wins. Last-wins would be a silent coin toss on a
                // security value, so the whole string is refused instead.
                throw SecurityPolicyUnresolved::passwordHashingAlgorithm();
            }

            $parsed[$normalisedKey] = $normalisedValue;
        }

        return $parsed;
    }

    /**
     * The framework's option name for a hashing parameter, or null when the
     * parameter is not one this project recognises.
     *
     * An unrecognised key is REFUSED rather than ignored. Ignoring it would let
     * an operator write `memory_cost=262144`, see no error, and get 1 MiB — the
     * exact silent-substitution failure `SEC-007` exists to prevent.
     */
    private static function normaliseOptionKey(string $key): ?string
    {
        return match (strtolower($key)) {
            'memory', 'memory_cost' => 'memory',
            'time', 'time_cost' => 'time',
            'threads', 'parallelism' => 'threads',
            default => null,
        };
    }

    /**
     * A positive integer, or null.
     *
     * A work factor of zero or a negative value is not "cheap hashing", it is a
     * configuration error that some backends clamp and some reject, and a
     * policy that depends on which is not a policy.
     */
    private static function normaliseOptionValue(string $value): ?int
    {
        if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
            return null;
        }

        $parsed = (int) $value;

        return $parsed < 1 ? null : $parsed;
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
     *
     * `SEC-008` requires the absolute lifetime to EXCEED the idle timeout, and
     * that relationship is now checked rather than assumed.
     *
     * WHY THIS IS A REFUSAL AND NOT A CLAMP. If `absolute <= idle` then one of
     * the two controls can never bind: with `absolute == idle` the two refusals
     * are indistinguishable and the operator cannot tell which one fired, and
     * with `absolute < idle` the absolute lifetime is dead code that looks
     * active. A clamp would hide that by silently rewriting the operator's
     * number into a different one, which is the "decision made by accident" this
     * class exists to prevent. A 503 names the invariant instead.
     *
     * Reading the idle timeout here means a deployment with a broken idle value
     * is reported against `SEC-008` rather than having one bad setting mask the
     * other.
     */
    public function sessionAbsoluteLifetimeSeconds(): int
    {
        $absolute = $this->positiveInteger('session.absolute_lifetime_seconds', SecurityPolicyUnresolved::sessionAbsoluteLifetime(...));
        $idle = $this->sessionIdleTimeoutSeconds();

        if ($absolute <= $idle) {
            throw SecurityPolicyUnresolved::sessionLifetimeInvariant($idle, $absolute);
        }

        return $absolute;
    }

    /**
     * `B-05`. Ceiling of failed attempts inside the decay window, PER ACCOUNT.
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
     * `B-05`. Ceiling of failed attempts inside the decay window, PER CLIENT
     * ADDRESS.
     *
     * A SEPARATE dimension from `authenticationRateLimit()`, with its own
     * ceiling, because combining them into one `email + IP` key gives an attacker
     * the PRODUCT of the two limits instead of the protection of both: N accounts
     * against M addresses produce N×M distinct keys, and an attacker who rotates
     * addresses against a single account gets a fresh key on every request and is
     * never throttled at all.
     *
     * The ceiling is expected to be LOOSER than the account ceiling. It exists to
     * shed a spray and to catch address rotation; it must not be so tight that a
     * property behind one NAT or corporate proxy fails as a single unit.
     *
     * @return array{attempts: int, decay: int}
     */
    public function ipRateLimit(): array
    {
        $attempts = $this->raw('authentication_rate_limit.ip_max_attempts');
        $decay = $this->raw('authentication_rate_limit.ip_decay_seconds');

        if ($attempts === null || $decay === null) {
            throw SecurityPolicyUnresolved::ipRateLimit();
        }

        return [
            'attempts' => $this->positiveInteger('authentication_rate_limit.ip_max_attempts', SecurityPolicyUnresolved::ipRateLimit(...)),
            'decay' => $this->positiveInteger('authentication_rate_limit.ip_decay_seconds', SecurityPolicyUnresolved::ipRateLimit(...)),
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
     *
     * READ INDEPENDENTLY OF SESSION LIFETIME, and that independence is the whole
     * point. A step-up is a recent proof of presence for one specific operation;
     * a session is a general grant. Comparing a step-up against the 12-hour
     * absolute lifetime would let a step-up taken at hour zero authorize a refund
     * at hour eleven, and comparing it against the 15-minute idle timeout would
     * expire it mid-operation. `StepUpGate` reads this window alone.
     */
    public function stepUpFreshnessSeconds(): int
    {
        return $this->positiveInteger('step_up.freshness_seconds', SecurityPolicyUnresolved::stepUpFreshness(...));
    }

    /**
     * `SEC-001` / `ADR-0014` §5. The MFA factor this deployment prefers, which
     * may be stronger than `mfaPrimary()`.
     */
    public function mfaPreferredMethod(): MfaMethod
    {
        return $this->mfaMethod('mfa.preferred', MfaMethod::Passkey);
    }

    /**
     * `SEC-001`. The MFA factor every covered role must satisfy.
     *
     * TOTP is the baseline because it has no network dependency — it works when
     * the PMS is segmented — and no per-message cost. A passkey is preferred
     * where the staff device estate supports it, because it is origin-bound and
     * therefore genuinely phishing-resistant, whereas TOTP codes are replayable
     * by a sophisticated phishing proxy.
     *
     * SMS and email are NOT selectable here. SMS is defeated by SIM swap and SS7
     * interception and is a cost-based denial-of-service vector against the
     * sender; email is ordinarily the same single authenticated session the
     * credential-reset path already uses, so it adds no second factor. Both are
     * refused by name rather than merely left undocumented.
     */
    public function mfaPrimaryMethod(): MfaMethod
    {
        return $this->mfaMethod('mfa.primary', MfaMethod::Totp);
    }

    /**
     * RFC 6238 parameters. `window` is how many periods either side of the
     * current one are accepted, to tolerate clock skew between the server and a
     * staff handset. It is deliberately 1, not more: each extra period is an
     * extra chance to replay a code that has already been observed.
     */
    public function totpParameters(): TotpParameters
    {
        return new TotpParameters(
            digits: $this->boundedInteger('mfa.digits', 6, 6, 8),
            periodSeconds: $this->boundedInteger('mfa.period_seconds', 30, 15, 120),
            window: $this->boundedInteger('mfa.window', 1, 0, 2),
        );
    }

    /**
     * `ADR-0014` §5, read mechanically: a role requires MFA if it can perform one
     * of the seven step-up operations, or any operation that changes
     * authorization, money, or guest identity data.
     *
     * The set is a list of `Role::value` strings, so a role that does not exist
     * cannot be named in it.
     *
     * @return list<string>
     */
    public function mfaRequiredRoles(): array
    {
        $roles = $this->value('mfa.required_roles');

        if (! is_array($roles)) {
            throw SecurityPolicyUnresolved::malformedValue('mfa.required_roles');
        }

        $parsed = [];

        foreach ($roles as $role) {
            if (! is_string($role) || Role::tryFrom($role) === null) {
                throw SecurityPolicyUnresolved::malformedValue('mfa.required_roles');
            }

            $parsed[] = $role;
        }

        return $parsed;
    }

    // ------------------------------------------------------------------
    // Security headers
    // ------------------------------------------------------------------

    public function contentSecurityPolicy(): ?string
    {
        return $this->nullableString('security_headers.content_security_policy');
    }

    public function xContentTypeOptions(): ?string
    {
        return $this->nullableString('security_headers.x_content_type_options');
    }

    public function xFrameOptions(): ?string
    {
        return $this->nullableString('security_headers.x_frame_options');
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
     * `Strict-Transport-Security`, or null when the deployment has not asserted
     * the HTTPS guarantee it requires.
     *
     * The ONLY header in the set that is conditional, and conditional for a
     * reason worth stating: every other header here fails to protect and harms
     * nobody, while HSTS is a PROMISE the browser enforces. Once a browser has
     * seen it, it refuses plaintext for `max-age` and will not let a user click
     * through, and `includeSubDomains` extends that promise to hosts this
     * application does not control. Turning it on before TLS is correct
     * everywhere is not a hardening win, it is an availability decision about the
     * whole estate taken by whoever happened to fill in a config file.
     *
     * So the guarantee is an EXPLICIT, separate switch that ships false, and
     * this returns null until an operator with the deployment in front of them
     * sets it. The guarantee required is: HTTPS on every application host, on
     * every response path, and on redirects and error responses.
     */
    public function strictTransportSecurity(): ?string
    {
        if (! $this->boolean('security_headers.hsts.enabled')) {
            return null;
        }

        $directive = 'max-age='.$this->boundedInteger(
            'security_headers.hsts.max_age',
            31536000,
            1,
            63072000,
        );

        if ($this->boolean('security_headers.hsts.include_sub_domains')) {
            $directive .= '; includeSubDomains';
        }

        if ($this->boolean('security_headers.hsts.preload')) {
            $directive .= '; preload';
        }

        return $directive;
    }

    private function mfaMethod(string $key, MfaMethod $fallback): MfaMethod
    {
        $value = $this->nullableString($key);

        if ($value === null) {
            return $fallback;
        }

        $method = MfaMethod::tryFrom(strtolower($value));

        if ($method === null) {
            throw SecurityPolicyUnresolved::unsupportedMfaMethod($value, $fallback);
        }

        // `Sms` and `Email` ARE cases in the enum, so `tryFrom` succeeds for
        // them — and they must still be refused. Reaching this point with one of
        // them means the value was recognised as a NAME and rejected as a
        // FACTOR, which is a different explanation and deserves a different
        // message than "no such method".
        if (! $method->isAcceptableFactor()) {
            throw SecurityPolicyUnresolved::unsupportedMfaMethod($value, $fallback);
        }

        return $method;
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

    /**
     * A positive integer constrained to a defensible range.
     *
     * Used where a value is a PROTOCOL parameter rather than a house policy: a
     * TOTP digit count outside 6–8 is not a preference, it is a value some
     * authenticator apps do not display; a period outside 15–120 s is either
     * unusable or effectively a PIN. An unbounded integer would let a typo
     * produce a configuration that silently fails every challenge, which is
     * indistinguishable from a broken second factor.
     */
    private function boundedInteger(string $key, int $fallback, int $min, int $max): int
    {
        $raw = $this->raw($key);

        if ($raw === null) {
            return $fallback;
        }

        if (preg_match('/^[0-9]+$/', $raw) !== 1) {
            throw SecurityPolicyUnresolved::malformedValue($key);
        }

        $value = (int) $raw;

        if ($value < $min || $value > $max) {
            throw SecurityPolicyUnresolved::valueOutOfRange($key, $min, $max);
        }

        return $value;
    }

    /**
     * A boolean-ish configuration value.
     *
     * Laravel's `env()` maps the strings `"true"`, `"false"`, `"null"`, and
     * `"empty"` to real PHP types, but a config array may hold a literal, and an
     * operator writing `yes` in an environment file means something. Only the
     * values that are unambiguous are accepted; anything else is a configuration
     * defect rather than a silent `false`, because a misread HSTS switch is
     * indistinguishable from a switch that is off.
     */
    private function boolean(string $key): bool
    {
        $value = $this->value($key);

        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));

            if (in_array($normalised, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalised, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        throw SecurityPolicyUnresolved::malformedValue($key);
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
