<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Auth\StepUpGuard;
use App\Modules\Identity\Auth\StepUpOperation;
use App\Modules\Identity\Auth\StepUpVerifier;
use App\Shared\Domain\ErrorCode;
use App\Shared\Domain\UnimplementedSecurityControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/step-up` — `API-SPEC.md` §3.11, "Re-authenticate for a
 * sensitive action", authenticated. Audits `STEP_UP_PERFORMED`.
 *
 * This is the emitter `API-SPEC.md` §3.11 records as missing. That line read:
 * "There is no `StepUpGuard`, no `/api/v1/auth/step-up` route, and no MFA
 * challenge handler in the codebase, so `STEP_UP_PERFORMED` is a reserved audit
 * action with no emitter." `StepUpGuard` and `StepUpVerifier` now exist, and
 * this route is the third piece.
 *
 * ============================ THE ORDER OF THE TWO CALLS IS THE POINT ============================
 * Verify FIRST, then complete. The reverse order would record a step-up and then
 * discover the code was wrong, and `StepUpGuard` would have to undo an audit
 * record — which is not something an append-only trail supports
 * (`ADR-0016`).
 *
 * Both calls are needed and neither is redundant. `StepUpVerifier` produces a
 * proof that a real second factor was checked, which is the only thing that
 * makes a step-up a step-up rather than an assertion. `StepUpGuard::complete()`
 * is what re-checks the proof's subject and operation against the identity
 * actually acting, and it is what writes the sealed session state a later
 * sensitive request reads. Trusting either alone would be a hole: the verifier
 * alone would prove a factor without recording anything, and the guard alone
 * would record a step-up whose origin it never checked.
 *
 * ============================ THE OPERATION IS REQUIRED, NOT INFERRED ============================
 * The seven operations of `docs/SECURITY.md` §6.1 are a closed set, and the
 * client names which one it wants to authorise. It is not derived from the path,
 * because this route serves all seven; a single `/step-up` that guessed the
 * operation from a referrer or a body field it did not validate would let a
 * caller spend a proof on a different action than the one they were checked for.
 *
 * `StepUpGuard` re-checks the match itself, so a mismatched operation is refused
 * with `STEP_UP_REQUIRED` (403) rather than accepted.
 *
 * ============================ WHY THIS ROUTE REFUSES INSTEAD OF VERIFYING ============================
 * The same `MfaFactorProvider` gap as `MfaVerifyController`, for the same
 * reasons: `H-03` and `C-10` are open, and the container cannot build
 * `StepUpVerifier` without a production implementation of that interface. See
 * that controller for the full argument; the short form is that a route which
 * cannot work answers 503 `SERVICE_UNAVAILABLE` rather than 500
 * `INTERNAL_ERROR`, because §1.7 reserves a 500 for defects and an endpoint
 * that raises one on every call is an alarm nobody will read.
 */
final class StepUpController extends Controller
{
    public function __construct(
        private readonly StepUpVerifier $verifier,
        private readonly StepUpGuard $guard,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $operation = $this->requiredEnum($request, 'operation', StepUpOperation::class);
        $code = $this->requiredString($request, 'code', trim: false);
        $correlationId = $this->correlationId($request);

        // 1. Does a real second factor verify? A null here has already written
        //    `MFA_FAILED`, so the refusal below is not the only trace of the
        //    attempt.
        $proof = $this->verifier->verifyTotp($operation, $code, null, $correlationId);

        if ($proof === null) {
            throw new UnimplementedSecurityControl(
                ErrorCode::AuthFailed,
                'The verification code is not valid.',
            );
        }

        // 2. Bind it to the identity acting, and record the step-up. This writes
        //    `STEP_UP_PERFORMED` and seals the session state.
        $this->guard->complete($request, $operation, $proof, $correlationId);

        return response()->json([
            'status' => 'stepped_up',
            'operation' => $operation->value,
        ]);
    }
}
