<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Auth\Mfa\MfaFactorProvider;
use App\Modules\Identity\Models\User;
use App\Shared\Domain\BusinessRuleViolation;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * A TEST-ONLY `MfaFactorProvider` backed by an in-memory map.
 *
 * ================================================================================
 * THIS STANDS IN FOR A SECRET STORE THAT DOES NOT EXIST, AND THAT IS THE POINT.
 * ================================================================================
 *
 * The production trust boundary fetches the factor FOR the authenticated identity
 * rather than accepting a secret and a subject id side by side — because a factory
 * taking both lets a caller holding User A's factor mint a proof naming User B.
 * That binding is only real if something can supply a per-identity factor, and
 * `H-03` is unresolved: `docs/DATA-MODEL.md` §2 reserves `mfa_secrets` and no
 * migration creates it, so there is no production implementation of
 * `MfaFactorProvider` and none is written here.
 *
 * Consequences, stated rather than hidden:
 *
 *   - A production deployment CANNOT complete a step-up yet, because it has
 *     nowhere to keep a factor. That is the correct state while `H-03` is open:
 *     the gate refuses, and refusing is what a missing security decision should
 *     produce. An invented "temporary" scheme would be far harder to remove later
 *     than one that was never written.
 *   - The SUBJECT BINDING can still be tested, and is, because the binding lives
 *     in the API shape (the factor is fetched for the resolved identity) rather
 *     than in the storage. This class supplies a DIFFERENT secret per user, which
 *     is exactly what makes "User A's code cannot produce a proof for User B"
 *     meaningful: a provider that returned one constant secret for everyone would
 *     let that test pass for the wrong reason.
 *
 * This class lives in `tests/` and guards nothing.
 *
 * ================================================================================
 * WHY `factorFor()` REFUSES INSTEAD OF SUBSTITUTING A DEFAULT
 * ================================================================================
 *
 * `MfaFactorProvider` requires that an implementation refuse rather than return an
 * empty or placeholder secret, and this class used to do the opposite: an identity
 * with no entry in the map silently received `self::FACTOR`. That is the exact
 * anti-pattern the interface names, because an empty secret is a value and a value
 * verifies against codes computed from it — a provider that invents a factor hands
 * every un-enrolled identity a working second factor.
 *
 * It was also the wrong thing to copy. `H-03` is still open, so this file is the
 * closest thing to a specification an `H-03` implementer has, and a specification
 * that teaches a fallback is worse than no specification at all.
 *
 * The default is therefore not gone — it is EXPLICIT. A test that wants "everyone
 * shares one secret" says so by name, and a test that wants one identity enrolled
 * says that instead. Neither is the absence of a decision.
 */
final class ProvidesTestMfaFactors implements MfaFactorProvider
{
    /**
     * A synthetic Base32 secret, not a credential: it protects nothing, is stored
     * nowhere, and appears in no configuration.
     */
    public const FACTOR = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /**
     * A second, deliberately DIFFERENT secret.
     *
     * Used for the cross-subject tests. With one shared secret, "A's code cannot
     * verify for B" would pass trivially and prove nothing about the binding.
     */
    public const OTHER_FACTOR = 'KRUGS4ZANFZSAYJAOZQWGZL2KNYVC5DJA';

    /** @var array<string, string> subject id => Base32 secret */
    private array $factors;

    /**
     * Whether an identity with no entry is treated as enrolled in `FACTOR`.
     *
     * OPT-IN, and named for what it does rather than for how it is reached. The
     * previous constructor took an empty array and silently meant "yes" — a test
     * that forgot to enrol anyone still got a working factor for them, so a broken
     * enrolment fixture looked like a passing one.
     */
    private readonly bool $enrolEveryUnregisteredIdentity;

    /**
     * @param  array<string, string>  $factors  subject id => secret, for identities
     *                                          that HAVE an enrolled factor
     * @param  bool  $enrolEveryUnregisteredIdentity  grant `FACTOR` to any identity
     *                                                not named in `$factors`
     */
    public function __construct(array $factors = [], bool $enrolEveryUnregisteredIdentity = false)
    {
        $this->factors = $factors;
        $this->enrolEveryUnregisteredIdentity = $enrolEveryUnregisteredIdentity;
    }

    /**
     * Enrol ONE identity in `FACTOR`.
     *
     * Preferred over the blanket constructor flag, because it names the identity
     * that is enrolled. A test using this cannot accidentally enrol somebody it
     * never thought about.
     */
    public function withDefaultFactorFor(string $subjectId): self
    {
        return $this->withFactorFor($subjectId, self::FACTOR);
    }

    /**
     * Enrol ONE identity in a specific factor.
     *
     * Unrelated to the constructor flag: this names an identity, that one applies to
     * identities nobody named. A test that overrides one subject keeps the blanket
     * grant for the others only if it asked for the blanket grant.
     *
     * Returns `$this` mutated rather than a clone. The provider is rebuilt for
     * each test anyway, and a fluent "clone and return" shape invites a caller to
     * keep the original by mistake — which would silently run the whole test
     * against one shared factor and make the cross-subject assertions pass for the
     * wrong reason.
     */
    public function withFactorFor(string $subjectId, string $factor): self
    {
        $this->factors[$subjectId] = $factor;

        return $this;
    }

    /**
     * The enrolled factor for THIS identity, or a refusal.
     *
     * The refusal is the whole point of the method, and it is a
     * `BusinessRuleViolation` carrying `MFA_REQUIRED` — the code
     * `docs/API-SPEC.md` §2.1 already fixes at 403, so this introduces no new
     * vocabulary and no new exception type. `BusinessRuleViolation` is what
     * `SessionSecurity::requireUser()` already raises for the parallel case of
     * "nobody is signed in", so the shape is the codebase's own.
     *
     * The message names no secret, no subject id, and no other identity. It does
     * not even say whether the map is empty, so it cannot be used to enumerate who
     * holds a factor.
     *
     * @throws DomainFailure when this identity has no enrolled factor
     */
    public function factorFor(User $user): string
    {
        $subjectId = (string) $user->id;

        $factor = $this->factors[$subjectId]
            ?? ($this->enrolEveryUnregisteredIdentity ? self::FACTOR : null);

        if ($factor === null || $factor === '') {
            throw new BusinessRuleViolation(
                ErrorCode::MfaRequired,
                'No second factor is enrolled for this identity.',
            );
        }

        return $factor;
    }
}
