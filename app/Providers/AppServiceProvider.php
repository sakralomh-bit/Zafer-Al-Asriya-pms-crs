<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Identity\Contracts\AuthorizesRequests;
use App\Modules\Identity\Services\AuthorizationService;
use App\Modules\Identity\Services\PropertyScopeResolver;
use App\Modules\Rooms\Contracts\RoomStatusPort;
use App\Modules\Rooms\Services\RoomStatusService;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * `PropertyScopeResolver` CACHES an actor's grants in memory, and
     * `forget()` exists so a grant or revoke takes effect immediately rather
     * than at next login (`ADR-0014` §6, `AC-T-004-04`).
     *
     * `forget()` is only meaningful if every consumer shares ONE resolver
     * instance. Without these bindings, `make()` returns a fresh resolver each
     * time, so the cache is per-instance: `forget()` inside `ScopeGrantService`
     * clears a different object from the one `AuthorizationService` holds. The
     * current wiring happens to stay correct because a fresh instance starts
     * with an empty cache — but that is luck, not design, and the moment this
     * is bound as a singleton for a long-lived worker (Octane, a queue listener)
     * the cache goes stale and revocation silently stops working. That is
     * precisely the failure `AC-T-004-04` exists to prevent.
     *
     * Binding it as a singleton makes the invalidation contract true by
     * construction.
     */
    public function register(): void
    {
        $this->app->singleton(PropertyScopeResolver::class);

        // Stateless collaborators. Singletons for consistency with the resolver
        // they share, and so a single audit seam is used across a request.
        $this->app->singleton(AuthorizationService::class);
        $this->app->singleton(AuditRecorder::class);

        $this->bindContracts();
    }

    /**
     * Bind each module's CONTRACT interface to the implementation that owns it.
     *
     * This is the binding that makes `ADR-0017` §1 real rather than aspirational.
     * "Cross-module access goes through an explicit contract interface owned by
     * the providing module" is only true if something maps the interface to an
     * implementation. Without these two lines the container tries to instantiate
     * the interface itself and `RoomStatusService` — the whole room state
     * machine — fails to build with:
     *
     *     Target [AuthorizesRequests] is not instantiable while building
     *     [RoomStatusService].
     *
     * Nothing caught it because no test had ever constructed the service. The
     * interface existed, the class existed, the guard confirmed the module
     * imported only the interface, and the feature was still completely
     * unusable at runtime.
     *
     * `Actor` is deliberately NOT bound: it is implemented by the `User` MODEL,
     * and the model is what reaches the authorization service. A container
     * binding for it would imply an `Actor` implementation service exists, which
     * would be a second, divergent way to be an actor.
     */
    private function bindContracts(): void
    {
        $this->app->singleton(AuthorizesRequests::class, AuthorizationService::class);
        $this->app->singleton(RoomStatusPort::class, RoomStatusService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
