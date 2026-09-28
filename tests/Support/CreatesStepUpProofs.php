<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Auth\Mfa\StepUpProof;
use App\Modules\Identity\Auth\Mfa\TotpVerifier;
use App\Modules\Identity\Auth\SessionSecurity;
use App\Modules\Identity\Auth\StepUpOperation;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds a `StepUpProof` for a test, EXPLICITLY.
 *
 * ================================================================================
 * THIS IS TEST FIXTURE CONSTRUCTION, AND IT IS NOT A BYPASS.
 * ================================================================================
 *
 * `StepUpProof` is the capability `StepUpGuard` requires before it will record a
 * completed step-up. In production it can only be obtained by presenting a valid
 * code for the factor belonging to the AUTHENTICATED identity — there is no other
 * signature, and `StepUpVerifierTest` proves that by driving real verification
 * with per-identity factors.
 *
 * This trait needs a valid code to exercise the GATE, and `H-03` has not settled
 * how a factor is stored, so this project has no secret store to read one from. It
 * therefore uses `ProvidesTestMfaFactors`, a TEST-ONLY in-memory provider, and
 * goes through the SAME production boundary `StepUpVerifier` uses.
 *
 * The important consequence: this trait cannot mint a proof without a valid code,
 * and cannot mint one for a subject other than the one signed in. There is no
 * fixture shortcut, no "trust me" branch, no boolean, and no subject argument to
 * pass. An earlier version of this file called a mint that took a subject, an
 * operation and a timestamp directly — which meant any test could fabricate a
 * step-up no second factor had authorised, and the gate's behaviour under those
 * conditions was never actually being tested.
 *
 * What this trait does and does not prove:
 *
 *   - It DOES prove the GATE. That a proof produces a satisfied gate, that the
 *     operation binding holds, that the subject binding holds, that freshness
 *     expires, and that the audit event fires.
 *   - It does NOT prove the TRUST BOUNDARY, and it is not asked to. That is
 *     `StepUpVerifierTest`'s job, proven with failing codes, foreign secrets,
 *     cross-subject attempts, and codes from the distant past. Those tests never
 *     touch this file, so the boundary has a suite that would catch a regression
 *     even if every test here kept passing.
 *
 * The factor is synthetic and fixed. It is not a credential for anything: it
 * protects nothing, is not stored, and appears in no configuration.
 */
trait CreatesStepUpProofs
{
    /**
     * A proof bound to a subject and ONE operation, minted from a REAL valid code.
     *
     * It goes through the SAME production boundary `StepUpVerifier` uses, which
     * means it cannot take a secret and a subject side by side: the subject comes
     * from the authenticated session and the factor is fetched for that identity.
     *
     * Time is controlled by moving the CLOCK with `Carbon::setTestNow()` rather
     * than by passing an instant into the API, because the production mint has no
     * timestamp parameter — that absence is the control, and a test-only override
     * on the trust boundary would put it back.
     *
     * Defaults are `now()` and `refund` so a test that does not care about either
     * does not have to reason about them. Tests about binding pass a second
     * operation; tests about expiry move the clock.
     */
    protected function stepUpProofFor(
        User $subject,
        StepUpOperation $operation = StepUpOperation::Refund,
    ): StepUpProof {
        // The guard must already be acting as `$subject`. Enforced rather than
        // assumed: if it is not, the boundary would mint for whoever IS signed in
        // and the test would silently assert against the wrong identity.
        $acting = $this->app->make(SessionSecurity::class)->requireUser();

        $this->assertSame(
            (string) $subject->id,
            (string) $acting->id,
            'The authenticated identity and the subject the proof is wanted for must be '
            .'the same. The production boundary derives the subject from the guard, so a '
            .'mismatch here would silently mint for the wrong user.',
        );

        $totp = new TotpVerifier;
        $provider = new ProvidesTestMfaFactors;

        $now = Carbon::now();

        $proof = StepUpProof::mintFromVerifiedTotp(
            $this->app->make(SessionSecurity::class),
            $provider,
            $totp,
            $operation,
            $totp->codeAt(
                $provider->factorFor($acting),
                $totp->counterAt($now),
            ),
        );

        // Not defensively coded away. If this is null the minting path is broken
        // and every gate test below would be asserting against a fixture that does
        // not exist.
        $this->assertNotNull($proof, 'A valid synthetic code must mint a proof.');

        return $proof;
    }
}
