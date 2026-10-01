<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Auth\StepUpOperation;
use App\Modules\Identity\Auth\StepUpVerifier;
use App\Shared\Domain\ErrorCode;
use App\Shared\Domain\UnimplementedSecurityControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/auth/mfa/verify` — `API-SPEC.md` §3.11, "MFA challenge", authenticated.
 *
 * Audits `MFA_CHALLENGED` on success and `MFA_FAILED` on refusal, which
 * `StepUpVerifier` writes. The route is the first production caller of that
 * class, so these two audit actions stop being vocabulary with no emitter.
 *
 * ============================ THE PROOF NEVER LEAVES THE SERVER ============================
 * A successful verification returns `{"status":"verified"}` and nothing else.
 * The `StepUpProof` is a capability object bound to a subject, an operation, and
 * an instant; serialising it into a response would hand the client a bearer
 * token for a step-up, and `API-SPEC.md` §1.6 prohibits tokens in a response body
 * in any case.
 *
 * The client is expected to follow a successful `/mfa/verify` with
 * `POST /api/v1/auth/step-up`, which verifies a code again and records the
 * step-up. That is a redundant round trip and it is the correct trade: the
 * alternative is a proof in a JSON body, where it would be logged by proxies and
 * replayable by whoever read the log.
 *
 * ============================ WHY THIS ROUTE REFUSES INSTEAD OF VERIFYING ============================
 * `RequireEnrolledFactorStore` middleware answers 503 on this route while
 * `H-03` and `C-10` are open, and it runs before this controller is even
 * constructed — the container would fail to build `StepUpVerifier` without an
 * `MfaFactorProvider`, and that failure would surface as a 500 on every call.
 * See the middleware for the full argument.
 */
final class MfaVerifyController extends Controller
{
    public function __construct(
        private readonly StepUpVerifier $verifier,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $operation = $this->requiredEnum($request, 'operation', StepUpOperation::class);
        $code = $this->requiredString($request, 'code', trim: false);

        $proof = $this->verifier->verifyTotp(
            $operation,
            $code,
            null,
            $this->correlationId($request),
        );

        if ($proof === null) {
            // A uniform refusal. A client learns that the code did not verify and
            // nothing else — not which factor, not which drift window, not
            // whether the account holds one. Differentiating would be a free
            // oracle for guessing codes.
            throw new UnimplementedSecurityControl(
                ErrorCode::AuthFailed,
                'The verification code is not valid.',
            );
        }

        // Note what is NOT returned: the proof, the subject, and the factor. The
        // response confirms possession and stops there.
        return response()->json(['status' => 'verified']);
    }
}
