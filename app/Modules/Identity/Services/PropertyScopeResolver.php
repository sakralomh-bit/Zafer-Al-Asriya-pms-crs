<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\Actor;
use App\Shared\Authorization\PropertyScopeDenied;

/**
 * Answers "which properties may this actor reach?".
 *
 * `ADR-0007` "Consequences": "Every repository method must accept `property_id`.
 * Static analysis rule: `PropertyScopeRequired` ... PHPStan flags missing
 * `property_id` parameter", and the mitigation for scope leakage is that "all
 * queries go through" a single applying seam. This class is that seam, and
 * `App\Shared\Tenancy\ScopedToProperty` is its query-side counterpart.
 *
 * There is exactly ONE source of truth for a property id: the grant rows. A user
 * with no grant has no properties, full stop. There is no default, no fallback,
 * and deliberately no "if no grants then all properties" — that last one being
 * the precise shape of the bug this whole model exists to prevent.
 */
final class PropertyScopeResolver
{
    /**
     * @var array<string, list<string>>
     */
    private array $cache = [];

    /**
     * @return list<string>
     */
    public function grantedPropertyIds(Actor $actor): array
    {
        $key = $actor->identifier();

        if (! array_key_exists($key, $this->cache)) {
            $granted = $actor->grantedPropertyIds();
            sort($granted);
            $this->cache[$key] = array_values(array_unique($granted));
        }

        return $this->cache[$key];
    }

    public function hasGrant(Actor $actor, string $propertyId): bool
    {
        return in_array($propertyId, $this->grantedPropertyIds($actor), true);
    }

    /**
     * @throws PropertyScopeDenied when there is no active grant
     */
    public function assertGranted(Actor $actor, string $propertyId): void
    {
        if (! $this->hasGrant($actor, $propertyId)) {
            throw PropertyScopeDenied::forProperty($propertyId, $actor->identifier());
        }
    }

    /**
     * Forget cached grants. Called after a grant or revoke so a change of scope
     * takes effect immediately rather than at next login — `ADR-0014` §6 and
     * `AC-T-004-04` require revocation not to wait for session expiry.
     *
     * With an actor, only that actor's entry is dropped. Without one, the whole
     * cache is dropped. Both forms are used deliberately: a grant or revoke
     * invalidates exactly one identity, and a test that needs a guaranteed cold
     * read asks for the whole cache.
     *
     * A grant that is not invalidated here stays valid in this cache for the
     * lifetime of a long-lived worker, which is why the grant path calls it
     * rather than relying on the request boundary.
     */
    public function forget(?Actor $actor = null): void
    {
        if ($actor === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$actor->identifier()]);
    }
}
