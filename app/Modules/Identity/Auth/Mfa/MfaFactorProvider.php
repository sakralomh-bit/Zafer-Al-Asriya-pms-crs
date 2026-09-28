<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth\Mfa;

use App\Modules\Identity\Models\User;
use App\Shared\Domain\DomainFailure;

/**
 * Supplies the enrolled second factor FOR A GIVEN IDENTITY.
 *
 * ============================ WHY THE SECRET IS LOOKED UP, NOT PASSED ============================
 * The verification primitive took a secret and a subject id as two independent
 * arguments, and that is precisely the defect this interface exists to remove. If
 * a caller supplies both, a caller holding User A's factor can name User B and get
 * a proof that says B re-authenticated. No amount of comment discourages that; the
 * API has to make it impossible.
 *
 * The fix is to make the two values stop being independent. The secret is derived
 * FROM the identity — `factorFor($user)` — so the subject and the factor are
 * bound at the moment the factor is fetched, and there is no argument a caller can
 * use to pair one identity's factor with another identity's proof.
 *
 * The trust boundary therefore becomes: a proof is only obtainable for whoever is
 * authenticated right now, using that identity's own enrolled factor. Verified
 * possession of somebody else's factor produces nothing, because the lookup is
 * keyed on the acting identity and the code will not match a different secret.
 *
 * ============================ THERE IS NO PRODUCTION IMPLEMENTATION, ON PURPOSE ============================
 * `docs/DATA-MODEL.md` §2 reserves `mfa_secrets` and no migration creates it.
 * `H-03` — how a secret is sealed, with which key, under which rotation — is
 * unresolved, and `C-10` names no Security Owner to answer it.
 *
 * So this interface has NO production implementation and none is added here.
 * Writing one would mean choosing a key-management scheme, deciding where the
 * ciphertext lives, and deciding who may read it: three decisions this project
 * does not have the authority to make, and a "temporary" scheme that ships is far
 * harder to remove than one that was never written.
 *
 * The consequence is stated rather than hidden: a production deployment cannot
 * yet satisfy a step-up through the verifier, because it has nowhere to keep a
 * factor. That is the correct state of the system while `H-03` is open — the gate
 * refuses, and refusing is what a missing security decision should produce. Tests
 * supply an implementation in `tests/Support/ProvidesTestMfaFactors.php`, which is
 * test-only code and guards nothing.
 *
 * That path is named in prose rather than imported. A `use` statement pointing
 * from `app/` into `tests/` resolves while dev dependencies are installed and
 * dangles in a `--no-dev` production build, so the reference would be a lie in
 * exactly the environment that matters.
 */
interface MfaFactorProvider
{
    /**
     * The Base32 secret enrolled for this identity.
     *
     * @throws DomainFailure when the identity has no enrolled
     *                       factor. Implementations MUST
     *                       refuse rather than return an
     *                       empty or placeholder secret: an
     *                       empty secret is a value, and a
     *                       value verifies against codes
     *                       computed from it.
     */
    public function factorFor(User $user): string;
}
