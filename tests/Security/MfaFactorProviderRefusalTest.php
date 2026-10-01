<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\Mfa\MfaFactorProvider;
use App\Modules\Identity\Auth\Mfa\StepUpProof;
use App\Modules\Identity\Auth\Mfa\TotpVerifier;
use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Auth\SessionSecurity;
use App\Modules\Identity\Auth\StepUpOperation;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesTestFixtures;
use Tests\Support\ProvidesTestMfaFactors;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * `MfaFactorProvider`'s refusal contract, and the fixture that implements it.
 *
 * ================================================================================
 * WHY THIS SUITE EXISTS
 * ================================================================================
 *
 * `MfaFactorProvider` states: "Implementations MUST refuse rather than return an
 * empty or placeholder secret: an empty secret is a value, and a value verifies
 * against codes computed from it."
 *
 * `ProvidesTestMfaFactors` did the opposite. An identity with no entry in its map
 * received `self::FACTOR`, so every un-enrolled identity silently held a working
 * second factor. Two things were wrong with that, and the second is worse:
 *
 *   1. It violated the interface it implements.
 *   2. `H-03` is open, so this file is the closest thing to a specification an
 *      `H-03` implementer has. A fixture that demonstrates a fallback teaches the
 *      fallback. `H-03` is about how a secret is SEALED — choosing a sealing scheme
 *      because the test fixture made it look routine would be inventing a decision,
 *      which is precisely what this task must not do.
 *
 * ================================================================================
 * WHAT IS NOT HERE, AND WHY
 * ================================================================================
 *
 * There is no test of a REAL store, a real sealing scheme, or a container binding.
 * `H-03` `A`, `B`, and `C` are BLOCKED: `docs/SECURITY.md` row 17 records key
 * management as `BLOCKED — BUSINESS`, and `docs/DEPLOYMENT.md` §H-03 records
 * rotation policy, key hierarchy, and decryption authority as `TBD`. There is no
 * KMS to reference either, because `B-03` names no provider.
 *
 * So a test asserting "the encrypted provider round-trips a sealed secret" would
 * have to invent the encryption. This suite asserts only what the CONTRACT already
 * says, which is the part that can be proven without choosing anything.
 */
final class MfaFactorProviderRefusalTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    /** Synthetic, `.test` is an RFC 2606 reserved domain. Not a credential. */
    private const EMAIL = 'factor.refusal@example.test';

    private User $user;

    private SessionSecurity $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());

        $this->user = $this->makeUser(self::EMAIL, Role::Finance);

        Auth::guard('web')->setUser($this->user);

        $this->sessions = $this->app->make(SessionSecurity::class);
    }

    /**
     * The contract itself: no enrolled factor means a refusal, not a value.
     *
     * Asserted on the EXCEPTION rather than on a returned value, because a
     * provider that returned `''` would satisfy a `?string` return type perfectly
     * well and still be the defect the interface names.
     */
    public function test_an_identity_with_no_enrolled_factor_is_refused(): void
    {
        $provider = new ProvidesTestMfaFactors;

        try {
            $provider->factorFor($this->user);

            $this->fail('An identity with no enrolled factor was served a secret. '
                .'MfaFactorProvider requires a refusal.');
        } catch (DomainFailure $failure) {
            $this->assertSame(
                ErrorCode::MfaRequired,
                $failure->errorCode,
                'The refusal must carry the documented code. API-SPEC.md §2.1 fixes '
                .'MFA_REQUIRED at 403; a new code here would be new vocabulary.',
            );
        }
    }

    /**
     * Not merely "it throws" — it throws INSTEAD OF a placeholder.
     *
     * The old implementation returned `ProvidesTestMfaFactors::FACTOR`. A test that
     * only asserted an exception would have been satisfied by a provider that
     * returned a secret AND threw, so this pins the absence of the value itself:
     * no path through `factorFor()` yields either constant.
     */
    public function test_no_placeholder_secret_is_ever_returned(): void
    {
        $provider = new ProvidesTestMfaFactors;

        $leaked = [];

        foreach ([$this->user, $this->makeUser('other.subject@example.test', Role::FrontDeskAgent)] as $subject) {
            try {
                $leaked[] = $provider->factorFor($subject);
            } catch (DomainFailure) {
                // The refusal is the only acceptable outcome here.
            }
        }

        $this->assertSame(
            [],
            $leaked,
            'A provider served a secret to an identity with no enrolment. That secret is '
            .'a working second factor for anybody who reads this file.',
        );

        $this->assertNotContains(
            ProvidesTestMfaFactors::FACTOR,
            $leaked,
            'The blanket default was returned without an explicit opt-in.',
        );

        $this->assertNotContains(
            ProvidesTestMfaFactors::OTHER_FACTOR,
            $leaked,
            'The cross-subject constant was returned to an un-enrolled identity.',
        );
    }

    /**
     * A refusal must not disclose the secret, the subject, or who HAS one.
     *
     * Two leaks are checked separately because they are different bugs: echoing the
     * secret hands over the second factor itself, and reporting "this user has no
     * factor" is an enrolment oracle that tells an attacker which accounts are worth
     * attacking with a social-engineering call.
     */
    public function test_the_refusal_discloses_no_secret_and_no_subject(): void
    {
        $provider = (new ProvidesTestMfaFactors)
            ->withDefaultFactorFor((string) $this->user->id);

        try {
            $provider->factorFor($this->makeUser('un.enrolled@example.test', Role::FrontDeskAgent));

            $this->fail('An un-enrolled identity was served a secret.');
        } catch (DomainFailure $failure) {
            $message = $failure->getMessage();

            foreach ([ProvidesTestMfaFactors::FACTOR, ProvidesTestMfaFactors::OTHER_FACTOR] as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $message,
                    'A refusal message carried an enrolled MFA secret.',
                );
            }

            $this->assertStringNotContainsString(
                (string) $this->user->id,
                $message,
                'The refusal named the id of an identity that DOES hold a factor, which '
                .'turns the error into an enrolment oracle.',
            );
        }
    }

    /**
     * The same refusal through the MINT, where it matters.
     *
     * `StepUpProof::mintFromVerifiedTotp()` calls `factorFor()` before it checks a
     * code. A provider that substituted a placeholder would let it proceed to
     * verification and return a PROOF — and `StepUpGuard::complete()` accepts a
     * proof. So this asserts the absence of a proof, not the presence of an
     * exception: the proof is the capability, and the capability is what must not
     * exist.
     */
    public function test_the_mint_refuses_and_produces_no_proof_for_an_unenrolled_identity(): void
    {
        $this->app->instance(MfaFactorProvider::class, new ProvidesTestMfaFactors);

        $verifier = $this->app->make(TotpVerifier::class);

        $proof = null;
        $refused = false;

        try {
            // A code computed from the constant the provider USED TO return. If the
            // placeholder fallback still existed, this code would verify and `$proof`
            // would be a completed capability.
            $proof = StepUpProof::mintFromVerifiedTotp(
                $this->sessions,
                $this->app->make(MfaFactorProvider::class),
                $verifier,
                StepUpOperation::Refund,
                $verifier->codeAt(ProvidesTestMfaFactors::FACTOR, $verifier->counterAt(now())),
            );
        } catch (DomainFailure) {
            $refused = true;
        }

        $this->assertTrue($refused, 'The mint did not refuse for an un-enrolled identity.');
        $this->assertNull(
            $proof,
            'A StepUpProof was minted for an identity with no enrolled factor. '
            .'StepUpGuard::complete() accepts a proof, so this is an authorisation bypass.',
        );
    }

    /**
     * The enrolment state of one identity says nothing about another's.
     *
     * This is the property the constant fallback destroyed: with one shared secret
     * for everyone, "A's code does not verify for B" passes trivially. Here A is
     * enrolled and B is not, and B is REFUSED rather than served A's secret.
     */
    public function test_enrolment_is_per_identity_and_does_not_leak_across_subjects(): void
    {
        $enrolled = $this->user;
        $unEnrolled = $this->makeUser('not.enrolled@example.test', Role::FrontDeskAgent);

        $provider = (new ProvidesTestMfaFactors)->withDefaultFactorFor((string) $enrolled->id);

        $this->assertSame(
            ProvidesTestMfaFactors::FACTOR,
            $provider->factorFor($enrolled),
            'An explicitly enrolled identity must be served its factor.',
        );

        $this->expectException(DomainFailure::class);
        $provider->factorFor($unEnrolled);
    }

    /**
     * Nothing is written to the log when a factor is refused or served.
     *
     * Scoped to what the contract supports: this asserts that neither the secret
     * nor the subject id reaches the log on these paths. It is not a claim that
     * logging is otherwise safe, and it does not invent a logging requirement the
     * documents do not state.
     */
    public function test_neither_the_secret_nor_the_subject_reaches_the_log(): void
    {
        $records = [];

        Log::listen(function ($message) use (&$records): void {
            $records[] = $message;
        });

        $provider = (new ProvidesTestMfaFactors)->withDefaultFactorFor((string) $this->user->id);

        $provider->factorFor($this->user);

        try {
            $provider->factorFor($this->makeUser('silent.refusal@example.test', Role::FrontDeskAgent));
        } catch (DomainFailure) {
            // Expected; the point is what reached the log, not that it threw.
        }

        $rendered = implode("\n", array_map(static fn ($message): string => $message->message, $records));

        $this->assertStringNotContainsString(
            ProvidesTestMfaFactors::FACTOR,
            $rendered,
            'An MFA secret was written to the log.',
        );

        $this->assertStringNotContainsString(
            (string) $this->user->id,
            $rendered,
            'The enrolled subject id was written to the log on a factor lookup.',
        );
    }
}
