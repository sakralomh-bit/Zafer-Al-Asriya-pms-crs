<?php

use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use App\Shared\Domain\ErrorResponseFactory;
use App\Shared\Http\Middleware\AssignCorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
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
