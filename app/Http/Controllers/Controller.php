<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Shared\Domain\ValidationFailed;
use App\Shared\Http\Middleware\AssignCorrelationId;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * Base for the authentication controllers in `App\Http\Controllers\Auth`.
 *
 * ============================ WHY THESE HAVE A BASE CLASS AT ALL ============================
 * Three things every one of them must do identically, and each of them is a
 * security property rather than a convenience:
 *
 *   1. READ THE CORRELATION ID FROM THE MIDDLEWARE, never from the request body
 *      and never by generating a fresh one. `ADR-0016` §7 requires an action to be
 *      reconstructable end to end, and the ID in the audit row has to be the ID
 *      the client saw. A controller that minted its own would put two different
 *      IDs in the trail and the log line for one request.
 *   2. TURN A MISSING OR MALFORMED FIELD INTO THE DOCUMENTED ERROR SHAPE, rather
 *      than letting a `TypeError` escape. An unhandled exception becomes
 *      `INTERNAL_ERROR` (500) with no detail, which `API-SPEC.md` §1.7 reserves
 *      for "a defect signal" — a client that forgot a field is not a defect.
 *   3. NEVER let a submitted secret reach a response body or a log. `API-SPEC.md`
 *      §1.6 prohibits secrets, tokens, and keys in any response.
 *
 * ============================ WHY NOT A TRAIT OR A HELPER ============================
 * Because it is a base class, a controller that forgets to extend it is a type
 * error at the point someone tries to reuse its behaviour, rather than a
 * controller that quietly omits the correlation ID. The failure mode of the
 * alternatives is silence.
 *
 * It lives in `App\Http\Controllers` rather than in the `Identity` module
 * because it is HTTP concern, not domain concern: the module knows nothing about
 * requests, and the cross-module import guard is unaffected either way.
 */
abstract class Controller
{
    /**
     * The correlation ID assigned by `AssignCorrelationId`, which
     * `bootstrap/app.php` prepends to the global stack so it runs before anything
     * that can fail.
     */
    final protected function correlationId(Request $request): ?string
    {
        $value = $request->attributes->get(AssignCorrelationId::ATTRIBUTE);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A required string field, or the documented validation failure.
     *
     * Returns the field TRIMMED of surrounding whitespace and refuses an empty
     * result. A password is not trimmed — leading and trailing whitespace can be
     * part of a chosen password, and silently removing it turns a correct
     * credential into a wrong one. That asymmetry is why this helper is used for
     * identifiers and never for secrets.
     *
     * @throws ValidationFailed when the field is absent, not a string, or blank
     */
    final protected function requiredString(Request $request, string $field, bool $trim = true): string
    {
        $value = $request->input($field);

        if (! is_string($value)) {
            throw ValidationFailed::missingField($field);
        }

        if ($trim) {
            $value = trim($value);
        }

        if ($value === '') {
            throw ValidationFailed::missingField($field);
        }

        return $value;
    }

    /**
     * A required field naming one member of a closed enum.
     *
     * Refuses an UNRECOGNISED value with the same refusal a missing value gets,
     * and the message does not name the valid options. The set is closed, so an
     * unknown value is a client that guessed — and a client that guessed is a
     * client that would benefit from an error listing every legal operation.
     *
     * The generic binding is load-bearing rather than decorative. Without it the
     * return type is the bare `BackedEnum`, so every caller handing the result to
     * a method that wants a specific case — `StepUpOperation`, say — is a type
     * error, and the obvious "fix" at the call site is a cast. The cast would be
     * the thing that could actually be wrong: `tryFrom()` cannot return a case of
     * a different enum, and the type says so only because of this annotation.
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     */
    final protected function requiredEnum(Request $request, string $field, string $enum): BackedEnum
    {
        $value = $this->requiredString($request, $field);

        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw ValidationFailed::missingField($field);
        }

        return $case;
    }
}
