<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Renders any `DomainFailure` in the one documented error shape.
 *
 * `docs/API-SPEC.md` §1.5:
 *
 *     { code, message, request_id, details, retryable }
 *
 * `code` is the contract. `message` is human-facing and is NOT part of it.
 *
 * §1.6 prohibits a stack trace, SQL, an internal hostname, a secret, or an
 * identity-document number in any response body. Nothing beyond the domain
 * failure's own message and field-level details is ever emitted, which is why
 * this is a separate, deliberately small class.
 */
final class ErrorResponseFactory
{
    /**
     * @param  array<int, array{field: string, message: string}>  $details
     */
    public static function make(Request $request, ErrorCode $code, string $message, array $details = []): JsonResponse
    {
        return response()->json([
            'code' => $code->value,
            'message' => $message,
            'request_id' => (string) $request->attributes->get('correlation_id', ''),
            'details' => $details,
            'retryable' => $code->retryable(),
        ], $code->httpStatus());
    }
}
