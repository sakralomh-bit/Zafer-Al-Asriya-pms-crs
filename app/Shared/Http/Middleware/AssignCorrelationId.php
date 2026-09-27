<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the correlation ID that ties a request to its audit records, log
 * lines, jobs, and any eventual external call.
 *
 * `ADR-0016` §7 and `Prd_Maker.md` §32 require that an action be reconstructable
 * end to end. `docs/API-SPEC.md` §1.3 lists it as a required request header.
 *
 * A client may supply the value; a client may NOT suppress it. If the header is
 * absent or is not a plausible ULID, one is generated.
 */
final class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public const ATTRIBUTE = 'correlation_id';

    public const ULID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $supplied = $request->headers->get(self::HEADER);
        $correlationId = is_string($supplied) && preg_match(self::ULID_PATTERN, $supplied) === 1
            ? $supplied
            : (string) Str::ulid();

        $request->attributes->set(self::ATTRIBUTE, $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
