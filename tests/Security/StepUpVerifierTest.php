<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Identity\Auth\Mfa\MfaFactorProvider;
use App\Modules\Identity\Auth\Mfa\MfaMethod;
use App\Modules\Identity\Auth\Mfa\StepUpProof;
use App\Modules\Identity\Auth\Mfa\TotpVerifier;
use App\Modules\Identity\Auth\SecurityPolicy;
use App\Modules\Identity\Auth\SessionSecurity;
use App\Modules\Identity\Auth\StepUpGuard;
use App\Modules\Identity\Auth\StepUpOperation;
use App\Modules\Identity\Auth\StepUpRequired;
use App\Modules\Identity\Auth\StepUpVerifier;
use App\Modules\Identity\Authorization\Role;
use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditAction;
use Database\Seeders\AuthorizationCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestFixtures;
use Tests\Support\ProvidesTestMfaFactors;
use Tests\Support\ResolvesSecurityPolicy;
use Tests\TestCase;

/**
 * THE TRUST BOUNDARY.
 *
 * ================================================================================
 * This suite proves the property that `StepUpGuardTest` deliberately does not:
 * that an UNTRUSTED CALLER CANNOT PRODUCE A COMPLETED STEP-UP.
 * ================================================================================
 *
 * `StepUpGuardTest` builds its proofs through `tests/Support/CreatesStepUpProofs`,
 * which mints them directly. That is necessary there — the gate's four conditions
 * are about what the gate does with a proof, and testing them through a real
 * secret would test TOTP instead. But it means every test in that file would
 * still pass if verification were broken to the point of always succeeding.
 *
 * So the boundary gets its own suite, and this one NEVER calls the mint helper.
 * Every proof used here comes out of `StepUpVerifier`, which runs RFC 6238
 * verification against a real secret:
 *
 *     untrusted caller
 *         -> cannot create a completed step-up
 *
 *     successful trusted MFA proof
 *         -> trusted step-up completion
 *         -> operation + subject bound
 *         -> audit
 *         -> StepUpGuard::assertSatisfied()
 *         -> privileged operation allowed
 *
 * A REAL SECRET IS USED, not a stubbed verifier. `TotpVerifier` is real
 * arithmetic against a real Base32 secret, and the codes are produced by the
 * verifier's own `codeAt()` — the same function an authenticator app's display
 * digits come from. A test that mocked the verifier would pass while the
 * boundary was open, which is the failure this file exists to prevent.
 */
final class StepUpVerifierTest extends TestCase
{
    use CreatesTestFixtures;
    use RefreshDatabase;
    use ResolvesSecurityPolicy;

    /**
     * A real 20-byte Base32 factor. Not a placeholder: `TotpVerifier` decodes it
     * and derives codes from it, so a fake that happened to be invalid Base32
     * would make every verification fail for the wrong reason and the positive
     * tests would prove nothing.
     */
    private const SECRET = ProvidesTestMfaFactors::FACTOR;

    private User $user;

    private SessionSecurity $sessions;

    private StepUpGuard $guard;

    private StepUpVerifier $verifier;

    private TotpVerifier $totp;

    private ProvidesTestMfaFactors $factors;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AuthorizationCatalogueSeeder::class);

        $this->app->instance(SecurityPolicy::class, $this->securityPolicyWith());

        $this->user = $this->makeUser('verifier.subject@example.test', Role::Finance);
        $this->sessions = $this->app->make(SessionSecurity::class);
        $this->guard = $this->app->make(StepUpGuard::class);
        $this->totp = $this->app->make(TotpVerifier::class);

        // The provider is bound per test because the cross-subject tests need
        // DIFFERENT factors for different identities. With one shared secret,
        // "User A's code does not verify for User B" would pass for the wrong
        // reason and prove nothing about the binding.
        $this->factors = new ProvidesTestMfaFactors;

        $this->app->instance(MfaFactorProvider::class, $this->factors);
        $this->verifier = $this->app->make(StepUpVerifier::class);
    }

    /**
     * A code for a given factor, as an authenticator app would display it now.
     */
    private function codeFor(string $secret, ?Carbon $at = null): string
    {
        $at ??= Carbon::now();

        return $this->totp->codeAt($secret, $this->totp->counterAt($at));
    }

    // =====================================================================
    // A. An untrusted caller cannot manufacture a completed step-up
    // =====================================================================

    /**
     * THE HEADLINE PROPERTY. There is no way to obtain a valid step-up without a
     * verified second factor.
     *
     * Four separate bypasses are tried, because "the control is present" and
     * "the control is unreachable without a proof" are different claims and only
     * the second one is worth anything:
     *
     *   1. The old `perform()` is GONE, not deprecated. Its presence would itself
     *      be the bypass.
     *   2. `SessionSecurity::recordStepUp()` requires a proof — a caller cannot
     *      write the state directly.
     *   3. Writing the three session keys BY HAND does not satisfy the gate, even
     *      with a fresh timestamp and the correct operation and subject. This is
     *      the property that matters most, because the keys are ordinary session
     *      data and a caller with the session object can write any string.
     *   4. `StepUpProof`'s constructor is private, so it cannot be built with
     *      `new` at a call site.
     */
    public function test_an_untrusted_caller_cannot_manufacture_a_completed_step_up(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        // --- 1. The generic entry point no longer exists. -----------------
        // Asserted by SIGNATURE rather than by name. A name check would pass if
        // the method were renamed and left just as callable, and it would also
        // be a check the analyser can evaluate statically — which is the point at
        // which a test stops testing anything. What matters is structural: no
        // public method here can be invoked without a proof in hand.
        $reflection = new \ReflectionClass($this->guard);

        $legacy = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $method): bool => strtolower($method->getName()) === 'perform',
        );

        $this->assertSame(
            [],
            array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                $legacy,
            ),
            'StepUpGuard::perform() recorded a step-up while verifying nothing. Holding '
            .'the guard was sufficient to authorise a privileged action. It must not exist '
            .'at all, deprecated or otherwise.',
        );

        $recorders = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => str_contains(
                strtolower($method->getName()),
                'complete',
            ),
        );

        $this->assertNotEmpty($recorders, 'A completion path must exist to be constrained.');

        // Every completion path must require a proof. If a future method appears
        // that records a step-up without one, this fails.
        foreach ($recorders as $method) {
            $this->assertTrue(
                $this->acceptsProof($method),
                $method->getName().'() can record a step-up without requiring a StepUpProof. '
                .'Every completion path must take one.',
            );
        }

        // --- 2. The session layer cannot be used as the bypass. -----------
        $recordStepUp = $reflection->getMethod('complete');

        $this->assertTrue(
            $this->acceptsProof($recordStepUp),
            'complete() must require a StepUpProof.',
        );

        $sessionReflection = new \ReflectionClass($this->sessions);
        $sessionRecord = $sessionReflection->getMethod('recordStepUp');

        $this->assertTrue(
            $this->acceptsProof($sessionRecord),
            'SessionSecurity::recordStepUp() must require a StepUpProof. Taking an '
            .'operation made the session layer a generic step-up manufacturer, and '
            .'the guard then found all four of its conditions satisfied.',
        );

        // --- 3. Direct session writes do not satisfy the gate. ------------
        // Fresh timestamp, the right operation, the right subject: every value a
        // genuine step-up would have written. The gate must still refuse.
        $request->session()->put([
            'auth.step_up_at' => Carbon::now()->toIso8601String(),
            'auth.step_up_operation' => StepUpOperation::Refund->value,
            'auth.step_up_subject' => (string) $this->user->id,
        ]);

        $this->assertFalse(
            $this->guard->isSatisfied($request, StepUpOperation::Refund),
            'Writing the step-up session keys by hand satisfied the gate. The gate '
            .'must require a proof to have passed through SessionSecurity, not '
            .'merely find three consistent strings in the session.',
        );

        // --- 4. The proof cannot be constructed with `new`. ---------------
        $proofReflection = new \ReflectionClass(StepUpProof::class);
        $constructor = $proofReflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue(
            $constructor->isPrivate(),
            'StepUpProof\'s constructor must be private so it cannot be built with `new`.',
        );
    }

    /**
     * ============================ NO SIGNATURE YIELDS A PROOF WITHOUT A VERIFIED CODE ============================
     * This is the check an earlier version of `StepUpProof` would have FAILED, and
     * it is here because a REVIEW found that hole rather than a test finding it.
     * That version exposed a public static factory taking a subject, an operation,
     * a timestamp, and a method name — four values, no factor and no submitted
     * code, and no verification anywhere in the call. Any caller could fabricate a
     * proof and `StepUpGuard::complete()` would accept it.
     *
     * A LATER version was also forgeable in a subtler way: it required a secret
     * and a code, so verification was genuine, but it still took a `$subjectId`
     * and a `$at` as free arguments. A caller holding User A's factor could
     * therefore produce a proof naming User B, or date it in the future so it read
     * as fresh. The secret and the code being real was not enough, because the
     * subject they were real FOR was chosen by the caller.
     *
     * The old signatures are deliberately NOT reproduced here, in prose or in a
     * code block. A copy-pasteable forgery signature sitting in a docblock has
     * already been read as live code once during a security review, which is a
     * cost the historical record is not worth. What matters is the rule, and the
     * rule is checkable mechanically.
     *
     * THE RULES, each enforced against the signature rather than against intent:
     *
     *   1. Every public factory must require a SUBMITTED CODE. Without one, a proof
     *      can be produced with no second factor involved.
     *   2. Every public factory must require SECOND-FACTOR MATERIAL. The factor now
     *      arrives through `MfaFactorProvider`, so the parameter is the provider —
     *      a factory that took a bare secret string would let a caller pair any
     *      factor with any subject, which is the defect this rule exists to stop.
     *   3. No public factory may take a SUBJECT ID. It is derived from the
     *      authenticated guard instead, so it cannot be substituted.
     *   4. No public factory may take a TIMESTAMP. It is read from the clock inside
     *      the mint, so it cannot be set to a future instant and used to extend the
     *      freshness window.
     *
     * Matched on the prefix `mint`, so renaming the method does not quietly remove
     * it from the sweep, and the factory set is asserted non-empty, so a sweep
     * that matches nothing cannot pass vacuously.
     */
    public function test_no_public_factory_yields_a_proof_without_verifying_a_code(): void
    {
        $reflection = new \ReflectionClass(StepUpProof::class);

        $factories = array_values(array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC),
            static fn (\ReflectionMethod $method): bool => str_starts_with(
                strtolower($method->getName()),
                'mint',
            ),
        ));

        $this->assertNotEmpty(
            $factories,
            'No minting factory found on StepUpProof. The minting path is the whole point of '
            .'this class, and a sweep that matches nothing would pass while the real one sat '
            .'under a different name.',
        );

        foreach ($factories as $factory) {
            $takesCode = false;
            $takesFactorMaterial = false;
            $takesSubject = false;
            $takesTimestamp = false;

            foreach ($factory->getParameters() as $parameter) {
                $name = strtolower($parameter->getName());

                if (str_contains($name, 'code')) {
                    $takesCode = true;
                }

                if (str_contains($name, 'factor') || str_contains($name, 'secret')) {
                    $takesFactorMaterial = true;
                }

                if (str_contains($name, 'subject') || str_contains($name, 'userid')) {
                    $takesSubject = true;
                }

                // Matched on a whole name or a name SUFFIX, not a substring. A loose
                // `'at' in $name` matches `operATion`, which is neither a timestamp
                // nor a defect — and a check that cries wolf is a check that gets
                // weakened, so the pattern is precise even at the cost of needing
                // an update when a new time-ish name appears.
                $isTimeName = in_array($name, ['at', 'now', 'time', 'timestamp', 'verifiedat', 'asof'], true)
                    || str_ends_with($name, 'at')
                    || str_ends_with($name, 'time')
                    || str_ends_with($name, 'timestamp');

                if ($isTimeName) {
                    $takesTimestamp = true;
                }
            }

            $this->assertTrue(
                $takesCode,
                $factory->getName().'() does not take a submitted code, so it cannot verify '
                .'anything. A proof minted without one was never checked against a second factor.',
            );

            $this->assertTrue(
                $takesFactorMaterial,
                $factory->getName().'() does not take second-factor material. It must arrive '
                .'through MfaFactorProvider, fetched for the authenticated identity; a bare '
                .'secret parameter would let a caller pair one identity\'s factor with another '
                .'identity\'s proof.',
            );

            $this->assertFalse(
                $takesSubject,
                $factory->getName().'() takes a subject id. The subject must be derived from the '
                .'authenticated guard inside the mint, or a caller holding User A\'s factor can '
                .'mint a proof naming User B.',
            );

            $this->assertFalse(
                $takesTimestamp,
                $factory->getName().'() takes a timestamp. The verification instant must be read '
                .'from the clock inside the mint, or a caller can date a proof in the future and '
                .'extend the freshness window at will.',
            );
        }
    }

    /**
     * The test fixture cannot bypass the minting path either.
     *
     * `tests/Support/CreatesStepUpProofs` mints through the SAME production
     * boundary `StepUpVerifier` uses, because `H-03` has not settled how a factor
     * is stored. If that trait held a shortcut, every gate test would be
     * exercising a step-up that no code ever authorised — and the suite would look
     * exactly as green.
     *
     * So the fixture holds a synthetic factor and a genuine valid code, and this
     * asserts both halves: a valid code mints, and nothing else does.
     */
    public function test_the_test_fixture_mints_only_from_a_real_valid_code(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $valid = $this->codeFor(self::SECRET);

        $minted = StepUpProof::mintFromVerifiedTotp(
            $this->sessions,
            $this->factors,
            $this->totp,
            StepUpOperation::Refund,
            $valid,
        );

        $this->assertNotNull($minted, 'A valid code must mint a proof.');

        // And there is no arrangement that mints without one.
        $this->assertNull(StepUpProof::mintFromVerifiedTotp(
            $this->sessions,
            $this->factors,
            $this->totp,
            StepUpOperation::Refund,
            $this->wrongCode(),
        ));

        $this->assertNull(StepUpProof::mintFromVerifiedTotp(
            $this->sessions,
            $this->factors,
            $this->totp,
            StepUpOperation::Refund,
            '',
        ));
    }

    /**
     * Does this method take a `StepUpProof`?
     *
     * Inspects the signature rather than counting, because a completion method
     * that accepted a proof as an OPTIONAL argument, or as a loosely typed mixed,
     * would be just as bypassable as one with no proof at all.
     */
    private function acceptsProof(\ReflectionMethod $method): bool
    {
        $takesProof = false;

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getType() instanceof \ReflectionNamedType
                && $parameter->getType()->getName() === StepUpProof::class) {
                $takesProof = true;

                // A proof that may be omitted is not a requirement.
                $this->assertFalse(
                    $parameter->isOptional(),
                    $method->getName().'() takes an optional StepUpProof, so it can be called without one.',
                );
            }
        }

        return $takesProof;
    }

    /**
     * No signature anywhere in the auth layer records a step-up from an operation
     * alone.
     *
     * `recordStepUp()` was the specific hole, and a targeted test for it would
     * pass while a second method with the same weakness was added next to it.
     * This sweeps the layer instead.
     */
    public function test_no_method_in_the_auth_layer_manufactures_a_step_up_without_a_proof(): void
    {
        $suspicious = [];

        foreach ($this->authLayerClasses() as $class) {
            $reflection = new \ReflectionClass($class);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (! $this->mentionsStepUpState($method, $reflection)) {
                    continue;
                }

                if (! $this->acceptsProof($method)) {
                    $suspicious[] = $class.'::'.$method->getName().'()';
                }
            }
        }

        $this->assertSame(
            [],
            $suspicious,
            'A public method writes step-up state without requiring a StepUpProof: '
            .implode(', ', $suspicious),
        );
    }

    /**
     * The auth layer's classes, found on disk rather than listed by hand.
     *
     * Listing them would let a new class escape the sweep simply by not being
     * added to the list, which is the same way the original defect survived.
     *
     * @return list<class-string>
     */
    private function authLayerClasses(): array
    {
        $directory = $this->app->basePath('app/Modules/Identity/Auth');

        if (! is_dir($directory)) {
            $this->fail('The auth layer directory is missing; the sweep would pass vacuously.');
        }

        $classes = [];

        // `realpath()` on the app root, because the iterator hands back the
        // relative directory it was given, so a relative `$root` would never be a
        // prefix of the paths that come back and the sweep would silently find
        // nothing. That failure mode is exactly the one asserted against below.
        $root = (string) realpath($this->app->basePath('app'));
        $root = rtrim($root, '\\/').DIRECTORY_SEPARATOR;
        $prefix = 'App\\';

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = (string) realpath($file->getPathname());

            if ($path === '' || ! str_starts_with($path, $root)) {
                continue;
            }

            $relative = substr($path, strlen($root));
            $class = $prefix.str_replace(['\\', '/'], '\\', substr($relative, 0, -4));

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        $this->assertNotEmpty(
            $classes,
            'No auth layer classes were discovered under '.$directory
            .'. A sweep that matches nothing proves nothing.',
        );

        return $classes;
    }

    /**
     * Does this method WRITE step-up state, as opposed to reading or clearing it?
     *
     * Only writers are held to the proof requirement. A reader returning null for
     * a missing step-up, and a method that CLEARS one, both do the right thing
     * with no proof in hand — requiring one of those to take a proof would make
     * the check meaningless by forcing a fake exemption list into the test, and
     * that list is exactly the thing that goes stale.
     *
     * The verb is matched on the METHOD NAME, and the writing is confirmed
     * against the method's own source rather than the class's, so `stepUpAt()` is
     * not mistaken for a writer just because it lives beside one. The name list
     * covers the verbs that could plausibly SET state; anything genuinely new
     * would have to be added here, which is a visible change.
     *
     * @param  \ReflectionClass<object>  $class
     */
    private function mentionsStepUpState(\ReflectionMethod $method, \ReflectionClass $class): bool
    {
        $name = strtolower($method->getName());

        foreach (['record', 'complete', 'perform', 'mark', 'write', 'set', 'establish'] as $verb) {
            if (str_contains($name, $verb)) {
                // Confirmed against the class source: a class that merely has a
                // "record*" method (an audit recorder, say) is not a step-up
                // writer, and flagging it would be noise that trains a reviewer
                // to ignore the sweep.
                $source = (string) file_get_contents((string) $class->getFileName());

                return str_contains($source, 'KEY_STEP_UP_AT');
            }
        }

        return false;
    }

    // =====================================================================
    // B. A valid proof establishes the step-up, bound and audited
    // =====================================================================

    /**
     * THE POSITIVE PATH, END TO END, THROUGH REAL VERIFICATION.
     *
     * A code generated from the real secret is verified, the resulting proof
     * completes a step-up, the gate is satisfied, and the audit trail records it.
     * Nothing here is stubbed.
     */
    public function test_a_valid_second_factor_establishes_a_bound_and_audited_step_up(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:00:00Z'));

        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $proof = $this->verifier->verifyTotp(
            StepUpOperation::Refund,
            $this->validCode(),
        );

        $this->assertNotNull($proof, 'A correct TOTP code must verify.');
        $this->assertSame(MfaMethod::Totp, $proof->method);
        $this->assertSame(StepUpOperation::Refund, $proof->operation);
        $this->assertSame((string) $this->user->id, $proof->subjectId);

        $this->guard->complete($request, StepUpOperation::Refund, $proof, 'corr-ok');

        // The gate is satisfied — this is the privileged operation being allowed.
        $this->guard->assertSatisfied($request, StepUpOperation::Refund);

        $this->assertSame(StepUpOperation::Refund->value, $this->sessions->stepUpOperation($request));
        $this->assertSame((string) $this->user->id, $this->sessions->stepUpSubject($request));

        // Exactly one step-up was audited, and it named the factor.
        $stepUp = DB::table('audit_events')
            ->where('action', AuditAction::StepUpPerformed->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($stepUp);
        $this->assertSame('corr-ok', $stepUp->correlation_id);
        $this->assertIsString($stepUp->context);
        $this->assertStringContainsString('totp', $stepUp->context);

        Carbon::setTestNow();

        $this->addToAssertionCount(1);
    }

    /**
     * A valid code does not stop at the verifier: the resulting proof must be
     * bound to exactly ONE operation, and the others stay refused.
     *
     * Requirement B says "exactly one requested operation and subject", and this
     * is the half of that about operations. The subject half is `test_c`.
     */
    public function test_a_verified_proof_authorises_exactly_one_operation(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $proof = $this->verifier->verifyTotp(
            StepUpOperation::IdentityDocumentReveal,
            $this->validCode(),
        );

        $this->assertNotNull($proof);
        $this->guard->complete($request, StepUpOperation::IdentityDocumentReveal, $proof, 'corr-1');

        $this->assertTrue($this->guard->isSatisfied($request, StepUpOperation::IdentityDocumentReveal));

        foreach (StepUpOperation::cases() as $other) {
            if ($other === StepUpOperation::IdentityDocumentReveal) {
                continue;
            }

            $this->assertFalse(
                $this->guard->isSatisfied($request, $other),
                "A proof verified for identity_document_reveal authorised {$other->value}.",
            );
        }
    }

    // =====================================================================
    // C. Wrong operation
    // =====================================================================

    /**
     * A proof verified for one operation cannot be SPENT on another, and the
     * mismatch is caught at completion rather than by the gate later.
     *
     * This is the check `complete()` performs. Without it, a caller could verify
     * a factor for the operation they find least annoying and then request the
     * one they actually wanted.
     */
    public function test_a_proof_cannot_be_spent_on_a_different_operation(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $proof = $this->verifier->verifyTotp(
            StepUpOperation::Export,
            $this->validCode(),
        );

        $this->assertNotNull($proof);
        $this->assertSame(StepUpOperation::Export, $proof->operation);

        try {
            $this->guard->complete($request, StepUpOperation::Refund, $proof, 'corr-1');
            $this->fail('A proof for export completed a refund step-up.');
        } catch (StepUpRequired $refusal) {
            $this->assertSame(403, $refusal->errorCode->httpStatus());
        }

        // And nothing was written, so a later check cannot find a step-up.
        $this->assertFalse(
            $this->guard->isSatisfied($request, StepUpOperation::Refund),
            'A refused completion still left a usable step-up behind.',
        );
        $this->assertFalse(
            $this->guard->isSatisfied($request, StepUpOperation::Export),
        );
    }

    // =====================================================================
    // D. Wrong subject
    // =====================================================================

    /**
     * A proof verified for one identity cannot be spent by another.
     *
     * A user who satisfies MFA for themselves, then has the session re-associated
     * with a different account, must not carry the proof across. This is the
     * case the subject binding exists for, and it is checked twice: once at
     * completion and once at the gate.
     */
    public function test_a_proof_cannot_be_spent_by_a_different_subject(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $proof = $this->verifier->verifyTotp(
            StepUpOperation::Refund,
            $this->validCode(),
        );

        $this->assertNotNull($proof);

        $other = $this->makeUser('a.different.person@example.test', Role::Finance);

        Auth::guard('web')->setUser($other);

        $this->assertSame(
            (string) $other->id,
            (string) $this->sessions->requireUser()->id,
            'Precondition: the acting identity really has changed.',
        );

        try {
            $this->guard->complete($request, StepUpOperation::Refund, $proof, 'corr-1');
            $this->fail('A proof verified by one identity completed a step-up for another.');
        } catch (StepUpRequired $refusal) {
            $this->assertSame(403, $refusal->errorCode->httpStatus());
        }

        $this->assertNull(
            $this->sessions->stepUpAt($request),
            'A refused completion still recorded a step-up.',
        );
    }

    /**
     * A valid step-up stops satisfying the gate once the acting identity changes.
     *
     * Distinct from the test above: there the proof had not yet been spent, here
     * it HAS been completed and the session then re-associates. The subject
     * binding has to hold on a completed step-up too, not only on a fresh one.
     */
    public function test_a_completed_step_up_stops_satisfying_the_gate_for_another_subject(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $proof = $this->verifier->verifyTotp(
            StepUpOperation::Refund,
            $this->validCode(),
        );

        $this->assertNotNull($proof);
        $this->guard->complete($request, StepUpOperation::Refund, $proof, 'corr-1');

        $this->assertTrue($this->guard->isSatisfied($request, StepUpOperation::Refund));

        Auth::guard('web')->setUser(
            $this->makeUser('yet.another.person@example.test', Role::Finance)
        );

        $this->assertFalse(
            $this->guard->isSatisfied($request, StepUpOperation::Refund),
            'A completed step-up followed a session to a different identity.',
        );
    }

    // =====================================================================
    // E. Expiry
    // =====================================================================

    /**
     * A proof is only good for the freshness window, measured from when the
     * factor was VERIFIED.
     *
     * The anchor is `verifiedAt`, not the moment the state was written. A test
     * that verified now and recorded later would pass under a `now()` anchor and
     * fail here, which is the distinction worth having: it means a caller cannot
     * widen the window by sitting on a valid code.
     */
    public function test_a_proof_expires_on_the_freshness_window_from_its_verification(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:00:00Z'));

        $verifiedAt = Carbon::parse('2026-01-01T12:00:00Z');
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $proof = $this->verifier->verifyTotp(
            StepUpOperation::Refund,
            $this->codeAt($verifiedAt),
        );

        $this->assertNotNull($proof);

        // The caller sits on the proof for four minutes before spending it.
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:04:00Z'));

        $this->guard->complete($request, StepUpOperation::Refund, $proof, 'corr-1');

        $this->assertSame(
            $verifiedAt->toIso8601String(),
            $this->sessions->stepUpAt($request)?->toIso8601String(),
            'The freshness anchor must be the moment the factor was verified, not the '
            .'moment the state was written. Otherwise a caller can extend the window '
            .'by delaying the write.',
        );

        // 60 s later the total age is 5 minutes, so the step-up is out of window
        // even though the write itself was recent.
        Carbon::setTestNow(Carbon::parse('2026-01-01T12:05:01Z'));

        $this->assertFalse(
            $this->guard->isSatisfied($request, StepUpOperation::Refund),
            'A step-up 301 s after its VERIFICATION still satisfied the gate.',
        );

        Carbon::setTestNow();

        $this->addToAssertionCount(1);
    }

    // =====================================================================
    // F. Failure
    // =====================================================================

    /**
     * A WRONG CODE PRODUCES NO PROOF, and therefore no step-up and no
     * `STEP_UP_PERFORMED` event.
     *
     * The audit assertion matters as much as the state one: a failed challenge
     * that still emitted `STEP_UP_PERFORMED` would put a false success in an
     * append-only trail, which is worse than a missing record because it cannot
     * be corrected later.
     */
    public function test_a_wrong_code_produces_no_proof_and_no_step_up(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $stepUpsBefore = $this->countStepUpAudits();

        $proof = $this->verifier->verifyTotp(
            StepUpOperation::Refund,
            $this->wrongCode(),
        );

        $this->assertNull($proof, 'A wrong TOTP code produced a proof.');

        $this->assertFalse($this->guard->isSatisfied($request, StepUpOperation::Refund));
        $this->assertNull($this->sessions->stepUpAt($request));

        $this->assertSame(
            $stepUpsBefore,
            $this->countStepUpAudits(),
            'A refused second factor emitted STEP_UP_PERFORMED. An append-only trail '
            .'containing a false success is worse than a missing record.',
        );
    }

    /**
     * Several wrong codes in a row never accumulate into a valid one.
     *
     * Guards against a verifier that ORs its results, or one whose window logic
     * eventually accepts something.
     */
    public function test_repeated_wrong_codes_never_establish_a_step_up(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        foreach (['000000', '111111', '999999', '000001'] as $attempt) {
            $this->assertNull(
                $this->verifier->verifyTotp(
                    StepUpOperation::Refund,
                    $attempt,
                ),
                "The code {$attempt} was accepted.",
            );
        }

        $this->assertFalse($this->guard->isSatisfied($request, StepUpOperation::Refund));
    }

    /**
     * A code from a DIFFERENT secret does not verify.
     *
     * Otherwise "verified" would mean nothing: any code shape would be accepted
     * as long as it was six digits.
     */
    public function test_a_code_from_a_different_secret_does_not_verify(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->assertNull(
            $this->verifier->verifyTotp(
                StepUpOperation::Refund,
                $this->codeFor(ProvidesTestMfaFactors::OTHER_FACTOR),
            ),
            'A code derived from a different secret was accepted.',
        );
    }

    /**
     * A code for the right secret but the WRONG TIME is refused.
     *
     * The time window is the only thing standing between a recorded code and a
     * reusable credential, so an implementation that ignored it would still pass
     * every other test in this file.
     */
    public function test_a_code_for_a_distant_past_is_refused(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $ancient = Carbon::parse('2020-01-01T00:00:00Z');

        $this->assertNull(
            $this->verifier->verifyTotp(
                StepUpOperation::Refund,
                $this->codeFor(self::SECRET, $ancient),
            ),
            'A five-year-old TOTP code was accepted.',
        );
    }

    // =====================================================================
    // Audit of the challenge itself
    // =====================================================================

    /**
     * A stale VALID seal cannot be paired with rewritten values.
     *
     * This is the case that a "just check the values" implementation gets wrong.
     * A caller completes one legitimate step-up, keeps the seal that was written
     * with it, then overwrites the three keys with different values. If the read
     * verified only that a seal was PRESENT, the rewritten triple would pass.
     * Verifying the seal AGAINST THE CURRENT VALUES is what makes it an integrity
     * check rather than a presence check.
     */
    public function test_a_retained_seal_cannot_authorise_rewritten_step_up_values(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        // A genuine step-up for one operation, which writes a genuine seal.
        $proof = $this->verifier->verifyTotp(
            StepUpOperation::Export,
            $this->validCode(),
        );

        $this->assertNotNull($proof);
        $this->guard->complete($request, StepUpOperation::Export, $proof, 'corr-1');

        $this->assertTrue($this->guard->isSatisfied($request, StepUpOperation::Export));

        $seal = $request->session()->get('auth.step_up_seal');
        $this->assertIsString($seal, 'Precondition: a real step-up wrote a real seal.');

        // Now rewrite the three values, keeping the seal exactly as it was.
        $request->session()->put([
            'auth.step_up_at' => Carbon::now()->toIso8601String(),
            'auth.step_up_operation' => StepUpOperation::Refund->value,
            'auth.step_up_subject' => (string) $this->user->id,
        ]);

        $this->assertSame(
            $seal,
            $request->session()->get('auth.step_up_seal'),
            'Precondition: the seal was not touched.',
        );

        $this->assertFalse(
            $this->guard->isSatisfied($request, StepUpOperation::Refund),
            'Rewritten step-up values were accepted under a stale seal. The seal must be '
            .'verified against the values it covers, not merely checked for presence.',
        );
        $this->assertFalse($this->guard->isSatisfied($request, StepUpOperation::Export));
    }

    /**
     * `ADR-0016:23` requires MFA challenge and failure to be auditable, and the
     * two are separate actions for a reason stated on `AuditAction`.
     */
    public function test_a_challenge_is_audited_as_accepted_and_a_refusal_as_failed(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->verifier->verifyTotp(
            StepUpOperation::Refund,
            $this->wrongCode(),
        );

        $failed = DB::table('audit_events')
            ->where('action', AuditAction::MfaFailed->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($failed, 'A refused second factor was not audited.');
        $this->assertSame((string) $this->user->id, $failed->actor_user_id);

        $this->verifier->verifyTotp(
            StepUpOperation::Refund,
            $this->validCode(),
        );

        $challenged = DB::table('audit_events')
            ->where('action', AuditAction::MfaChallenged->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($challenged, 'An accepted second factor was not audited.');

        // Distinct actions, so a reviewer can tell them apart.
        $this->assertNotSame(
            AuditAction::MfaFailed->value,
            AuditAction::MfaChallenged->value,
        );
    }

    /**
     * No code and no secret ever reaches the trail.
     *
     * The trail is append-only, so this is permanent in a way an ordinary log
     * line is not. `API-SPEC.md` §1.6 prohibits it outright.
     */
    public function test_neither_the_code_nor_the_secret_reaches_the_audit_trail(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $code = $this->validCode();

        $this->verifier->verifyTotp(StepUpOperation::Refund, $code);
        $this->verifier->verifyTotp(StepUpOperation::Refund, $this->wrongCode());

        $rows = DB::table('audit_events')
            ->whereIn('action', [AuditAction::MfaChallenged->value, AuditAction::MfaFailed->value])
            ->get();

        $this->assertNotEmpty($rows, 'Neither challenge was audited, so this proves nothing.');

        foreach ($rows as $row) {
            $context = (string) $row->context;

            $this->assertStringNotContainsString($code, $context);
            $this->assertStringNotContainsString(self::SECRET, $context);
            $this->assertStringNotContainsString('secret', strtolower($context));
        }
    }

    // =====================================================================
    // The subject and the timestamp are derived, not supplied
    // =====================================================================

    /**
     * A. A FACTOR AND CODE FOR USER A CANNOT PRODUCE A PROOF FOR USER B.
     *
     * This is the finding that forced the redesign, so it gets the first position
     * and the strongest fixture: A and B have DIFFERENT enrolled factors, A's
     * session is live, and A's code is submitted. The proof that comes back names
     * A.
     *
     * With a single shared secret this test would pass for the wrong reason — the
     * code would verify no matter whose it was — so `ProvidesTestMfaFactors` is
     * given a distinct factor for the second identity. A caller holding A's factor
     * therefore cannot produce B's proof, because B's factor is what the boundary
     * fetches, and A's code does not match it.
     */
    public function test_a_factor_and_code_for_one_user_cannot_produce_a_proof_for_another(): void
    {
        $other = $this->makeUser('a.different.person@example.test', Role::Finance);

        // A and B hold genuinely different factors.
        $this->app->instance(
            MfaFactorProvider::class,
            (new ProvidesTestMfaFactors)->withFactorFor((string) $other->id, ProvidesTestMfaFactors::OTHER_FACTOR),
        );
        $verifier = $this->app->make(StepUpVerifier::class);

        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $aCode = $this->codeFor(self::SECRET);

        $proof = $verifier->verifyTotp(StepUpOperation::Refund, $aCode);

        $this->assertNotNull($proof, 'A valid code for the acting user must verify.');
        $this->assertSame(
            (string) $this->user->id,
            $proof->subjectId,
            'The proof must name the identity whose factor was actually checked.',
        );
        $this->assertNotSame(
            (string) $other->id,
            $proof->subjectId,
            'A proof for one user was minted naming another.',
        );

        // And A's code does not verify B's factor, so the reverse is equally
        // refused: B cannot be proven by A's possession.
        $this->assertNull(
            StepUpProof::mintFromVerifiedTotp(
                $this->sessionsFor($other),
                new ProvidesTestMfaFactors([(string) $other->id => ProvidesTestMfaFactors::OTHER_FACTOR]),
                $this->totp,
                StepUpOperation::Refund,
                $aCode,
            ),
            "User A's code verified against User B's factor.",
        );
    }

    /**
     * B. THE PRODUCTION PATH BINDS THE SUBJECT TO THE AUTHENTICATED USER.
     *
     * Asserted as a property of `verifyTotp()`'s signature rather than of one
     * test's outcome: the method must not ACCEPT a subject at all, because a
     * parameter is a thing a caller can fill in with somebody else's id.
     */
    public function test_the_production_path_cannot_be_given_a_subject(): void
    {
        $verify = new \ReflectionMethod(StepUpVerifier::class, 'verifyTotp');

        foreach ($verify->getParameters() as $parameter) {
            $name = strtolower($parameter->getName());
            $type = $parameter->getType();

            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            $this->assertNotSame(
                User::class,
                $typeName,
                'StepUpVerifier::verifyTotp() accepts a User. The subject must be resolved from '
                .'the guard, not handed in, or a caller can name a different identity.',
            );

            $this->assertFalse(
                str_contains($name, 'subject'),
                'StepUpVerifier::verifyTotp() accepts a subject parameter.',
            );
        }
    }

    /**
     * C. A CALLER CANNOT SELECT A FUTURE VERIFICATION TIMESTAMP.
     *
     * Two halves, because either alone would be insufficient. The API must not
     * accept a time, and the recorded timestamp must be the clock's — asserted by
     * moving the clock and checking the proof follows it, which is also how every
     * freshness test gets a fixed moment without an override on the API.
     */
    public function test_the_verification_timestamp_cannot_be_chosen_by_a_caller(): void
    {
        // The mint takes no time parameter at all.
        $mint = new \ReflectionMethod(StepUpProof::class, 'mintFromVerifiedTotp');

        foreach ($mint->getParameters() as $parameter) {
            $this->assertFalse(
                in_array(strtolower($parameter->getName()), ['at', 'now', 'time', 'timestamp', 'verifiedat'], true),
                'The production mint takes a time parameter. A caller must not be able to date a '
                .'proof in the future and extend the freshness window.',
            );
        }

        $frozen = Carbon::parse('2026-03-15T08:30:00Z');
        Carbon::setTestNow($frozen);

        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $proof = $this->verifier->verifyTotp(StepUpOperation::Refund, $this->codeFor(self::SECRET, $frozen));

        $this->assertNotNull($proof);
        $this->assertTrue(
            $proof->verifiedAt->equalTo($frozen),
            'The proof must carry the clock\'s time, so a caller cannot fabricate freshness.',
        );

        Carbon::setTestNow();

        $this->addToAssertionCount(1);
    }

    /**
     * D. A VALID CODE FOR THE AUTHENTICATED USER'S FACTOR PRODUCES A PROOF FOR
     * EXACTLY THAT USER, across every canonical operation.
     *
     * All seven, because a binding that held for one operation would be a binding
     * written for one endpoint.
     */
    public function test_a_valid_code_produces_a_proof_for_exactly_the_authenticated_user(): void
    {
        foreach (StepUpOperation::cases() as $operation) {
            $request = $this->request();
            $this->sessions->start($request, $this->user);

            $proof = $this->verifier->verifyTotp($operation, $this->codeFor(self::SECRET));

            $this->assertNotNull($proof, "A valid code for {$operation->value} must verify.");
            $this->assertSame((string) $this->user->id, $proof->subjectId);
            $this->assertSame($operation, $proof->operation);

            // Exactly one: the other six operations are not authorised by it.
            foreach (StepUpOperation::cases() as $other) {
                if ($other === $operation) {
                    continue;
                }

                $this->assertFalse(
                    $this->guard->isSatisfied($request, $other),
                    "A proof for {$operation->value} authorised {$other->value}.",
                );
            }
        }
    }

    /**
     * H. A FOREIGN FACTOR PRODUCES NO PROOF FOR THE AUTHENTICATED USER.
     *
     * Distinct from the wrong-code case: here the code is a perfectly valid TOTP,
     * computed from a secret the user does not hold. Only the subject-keyed lookup
     * stops it.
     */
    public function test_a_foreign_factor_produces_no_proof_for_the_authenticated_user(): void
    {
        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $this->assertNull(
            $this->verifier->verifyTotp(
                StepUpOperation::Refund,
                $this->codeFor(ProvidesTestMfaFactors::OTHER_FACTOR),
            ),
            "A code from another user's factor was accepted.",
        );

        $this->assertFalse($this->guard->isSatisfied($request, StepUpOperation::Refund));
    }

    /**
     * G. THE AUDITED INSTANT IS THE PROOF'S OWN INSTANT.
     *
     * A previous version read the clock in the verifier for the audit and again
     * inside the mint for the proof, then recorded the FIRST read. Nothing forced
     * the two to agree, so the audit trail could say a factor was verified at a
     * moment other than the one the proof's freshness window is measured from —
     * and of the two, the one an investigator reads is the one in the trail.
     *
     * A FROZEN CLOCK CANNOT DETECT THIS. Both reads return the same value while
     * the clock is held still, so the test would pass whether the audit carried
     * the proof's instant or its own. The clock is therefore advanced by exactly
     * one TOTP period partway through the mint, through a factor provider that
     * moves it on lookup:
     *
     *     verifier reads the clock ......... T0
     *     mint fetches the factor .......... clock jumps to T0 + 30s
     *     mint reads the clock ............. T0 + 30s
     *
     * An audit carrying the proof's instant is stamped T0 + 30s. One carrying its
     * own read is stamped T0. The two differ by a full period and cannot be
     * confused.
     *
     * Thirty seconds is also the LARGEST usable skew. The policy allows one period
     * either side (`TotpParameters::$window = 1`), and the code below is computed
     * at T0, so any larger jump would push the verification outside the window
     * and the mint would correctly refuse — a test that failed for that reason
     * would prove nothing about the audit.
     */
    public function test_the_audited_instant_is_the_proofs_own_instant(): void
    {
        $frozen = Carbon::parse('2026-03-15T08:30:00Z');
        $skewed = $frozen->copy()->addSeconds(30);
        Carbon::setTestNow($frozen);

        // Advances the clock as a side effect of the lookup the mint performs
        // before it reads the clock for itself.
        $this->app->instance(MfaFactorProvider::class, new class(self::SECRET, $skewed) implements MfaFactorProvider
        {
            public function __construct(
                private readonly string $secret,
                private readonly Carbon $jumpTo,
            ) {}

            public function factorFor(User $user): string
            {
                Carbon::setTestNow($this->jumpTo);

                return $this->secret;
            }
        });

        $request = $this->request();
        $this->sessions->start($request, $this->user);

        $proof = $this->app->make(StepUpVerifier::class)->verifyTotp(
            StepUpOperation::Refund,
            // Computed at T0, and the mint's own window check at T0 + 1h still
            // accepts it, so a fresh code is what verifies here.
            $this->codeFor(self::SECRET, $frozen),
        );

        $this->assertNotNull($proof, 'A code within the skew window must still verify.');

        // The proof carries the instant the mint read — the LATER one.
        $this->assertTrue(
            $proof->verifiedAt->equalTo($skewed),
            'The proof did not carry the instant the mint read.',
        );

        $this->assertTrue(
            $this->auditFor(AuditAction::MfaChallenged, 'verified_at')->equalTo($proof->verifiedAt),
            'The trail records a different verification instant from the one the proof carries.',
        );

        Carbon::setTestNow();

        $this->addToAssertionCount(1);
    }

    /**
     * A `SessionSecurity` acting as a DIFFERENT user, for the reverse-direction
     * test above.
     */
    private function sessionsFor(User $user): SessionSecurity
    {
        Auth::guard('web')->setUser($user);

        return $this->sessions;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * The code an authenticator app would be DISPLAYING right now.
     *
     * Produced by the verifier's own `codeAt()` against the real secret and the
     * current counter — the same arithmetic, not a hand-written constant that
     * would drift the moment the time window moved.
     */
    private function validCode(): string
    {
        return $this->codeAt(Carbon::now());
    }

    private function codeAt(Carbon $at): string
    {
        return $this->codeFor(self::SECRET, $at);
    }

    /**
     * A code that is correct in shape and wrong in value.
     *
     * Generated by asking the verifier for a code at a distant counter and using
     * its DIGITS, rather than a literal like `'000000'` — a literal is a guess,
     * and a guess that happened to be right would make the negative tests
     * meaningless.
     */
    private function wrongCode(): string
    {
        $correct = $this->validCode();

        // Deterministically different, same length, and asserted so a future
        // change to the code format cannot quietly make this a valid code.
        $wrong = $correct === '000000' ? '111111' : '000000';

        $this->assertNotSame($correct, $wrong, 'The "wrong" code equals the valid one.');

        return $wrong;
    }

    private function countStepUpAudits(): int
    {
        return DB::table('audit_events')
            ->where('action', AuditAction::StepUpPerformed->value)
            ->count();
    }

    /**
     * When the given action was recorded, as the trail actually stored it.
     *
     * `$field` selects WHICH time. `audit_events` carries two, and they mean
     * different things:
     *
     *   - `occurred_at` is a COLUMN, written as `now()` by `AuditRecorder` at the
     *     moment the row is inserted. It is when the fact was written down.
     *   - `verified_at` / `attempted_at` live in the `context` JSON and are the
     *     instant the CALLER passed down. It is when the thing happened.
     *
     * A test that conflates them checks the wrong one, and would have reported this
     * defect as absent: the column is stamped by the recorder and is correct either
     * way, so it looks right while the context field carries the wrong instant.
     */
    private function auditFor(AuditAction $action, string $field = 'occurred_at'): Carbon
    {
        $row = DB::table('audit_events')
            ->where('action', $action->value)
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($row, "No {$action->value} audit row exists.");

        $context = json_decode((string) $row->context, true) ?: [];

        $recorded = $field === 'occurred_at' ? $row->occurred_at : ($context[$field] ?? null);

        $this->assertNotNull(
            $recorded,
            "The {$action->value} row carries no `{$field}`. An absent time is not the same "
            .'as a correct one, and this test exists to catch the difference.',
        );

        return Carbon::parse((string) $recorded);
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/folios/1/adjustments', 'POST');

        $store = $this->app['session']->driver();
        $store->start();
        $request->setLaravelSession($store);

        return $request;
    }
}
