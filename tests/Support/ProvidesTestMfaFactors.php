<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Auth\Mfa\MfaFactorProvider;
use App\Modules\Identity\Models\User;

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
     * @param  array<string, string>  $factors  subject id => secret. Defaults to
     *                                          `FACTOR` for every identity, which
     *                                          is enough for tests about codes
     *                                          rather than about binding.
     */
    public function __construct(array $factors = [])
    {
        $this->factors = $factors;
    }

    /**
     * Give ONE identity a different factor, leaving the rest on the default.
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

    public function factorFor(User $user): string
    {
        $subjectId = (string) $user->id;

        return $this->factors[$subjectId] ?? self::FACTOR;
    }
}
