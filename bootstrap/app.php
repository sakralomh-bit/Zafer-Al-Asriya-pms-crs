<?php

use App\Modules\Identity\Auth\SecurityPolicy;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use App\Shared\Domain\ErrorResponseFactory;
use App\Shared\Http\Middleware\AssignCorrelationId;
use App\Shared\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withBindings([
        // `SecurityPolicy` takes its values as an `array` constructor argument
        // rather than reaching for the `config()` helper itself. That is
        // deliberate — it is what lets a test hand the class deliberate
        // non-baseline numbers and prove a MECHANISM without depending on a
        // configuration value (see `tests/Support/ResolvesSecurityPolicy`).
        //
        // The cost of that arrangement is that the container cannot auto-wire
        // the class: an `array` parameter is unresolvable, and every consumer
        // reached through the container — `SecurityHeaders` in the global stack
        // below, and anything else that asks for a policy — would fail with an
        // `Unresolvable dependency` error. This binding is that missing edge.
        //
        // A CLOSURE, not a value, so `config('security')` is read at resolution
        // time with the configuration repository fully loaded, and so a policy
        // is rebuilt per resolution instead of frozen at bootstrap.
        //
        // It resolves the real configuration, not a fixture: the shipped
        // IMPLEMENTED TECHNICAL BASELINES in `config/security.php`. Reading a
        // value still refuses rather than inventing one, and a refusal is still
        // `SecurityPolicyUnresolved` (503 `SERVICE_UNAVAILABLE`).
        SecurityPolicy::class => static fn (): SecurityPolicy => SecurityPolicy::fromConfig(),
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `API-SPEC.md` §1.3 makes `X-Correlation-ID` a required request header,
        // and §1.5 requires `request_id` to be present in the response — which
        // `ErrorResponseFactory` reads from the `correlation_id` request
        // attribute this middleware sets. Without it `request_id` is always an
        // empty string, and an error response is not traceable to its audit
        // record or its log line, which is what `ADR-0016` §7 requires.
        //
        // `prepend` so it runs before anything that might fail: a correlation
        // ID assigned after the failure has no value to give the error body.
        // The class already existed and was unreachable.
        $middleware->prepend(AssignCorrelationId::class);

        // `SEC-009` and `docs/API-SPEC.md` §5 both require security headers on
        // ALL responses. "All" is the requirement, and it is why this is
        // appended to the GLOBAL stack rather than added to a route group: a
        // route-scoped middleware misses the responses nobody wrote a controller
        // for — the framework's 404 and 405, the `DomainFailure` renderer, the
        // unhandled-exception handler below, and redirects.
        //
        // Appended AFTER `AssignCorrelationId` on purpose. `prepend` order runs
        // outermost-first, and a header middleware that ran before the
        // correlation ID would be the outer layer here, which is correct for
        // coverage but would put it in front of the thing that guarantees every
        // response has a `request_id`. Global middleware runs in registration
        // order, so appending puts these headers closest to the response.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // `API-SPEC.md` §1.5 — one error shape for every documented code, and
        // §1.7 — a domain failure is a 4xx/5xx with a precise code, never a 500.
        //
        // `ErrorResponseFactory` already implemented exactly that and was
        // referenced by nothing, so a `DomainFailure` from any module reached
        // Laravel's default renderer: `APP_DEBUG=true` answers with a stack
        // trace, which §1.6 prohibits outright.
        $exceptions->render(function (DomainFailure $failure, Request $request): Response {
            return ErrorResponseFactory::make(
                $request,
                $failure->errorCode,
                $failure->getMessage(),
                $failure->details,
            );
        });

        // Anything that is NOT a `DomainFailure` is an unhandled defect, not a
        // business outcome, and `DomainFailure`'s own contract says it becomes
        // `INTERNAL_ERROR` with no detail exposed.
        //
        // The message is fixed text rather than `$e->getMessage()`: an
        // unhandled exception message routinely contains the SQL that failed,
        // the internal hostname, or the path of a class that does not exist in
        // the deployment, and §1.6 lists all three. The correlation ID is the
        // only handle a client gets, and §1.5 is explicit that diagnostic detail
        // goes to the correlated server-side record instead.
        $exceptions->render(function (Throwable $defect, Request $request): Response {
            report($defect);

            return ErrorResponseFactory::make(
                $request,
                ErrorCode::InternalError,
                'An unexpected error occurred. Quote the request_id when reporting this.',
            );
        });
    })->create();
