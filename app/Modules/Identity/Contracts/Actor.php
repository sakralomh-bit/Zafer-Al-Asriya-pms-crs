<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Authorization\Role;

/**
 * The identity an operation is performed as.
 *
 * Exists so that modules other than Identity can name "who is acting" without
 * importing `Modules\Identity\Models\User`, which would be exactly the
 * cross-module coupling `ADR-0017` §2 forbids. Identity owns the implementation;
 * every other module depends on this interface.
 */
interface Actor
{
    /**
     * The actor's stable surrogate id. Named `identifier()` rather than
     * `getKey()` so it does not collide with Eloquent's `Model::getKey()`.
     */
    public function identifier(): string;

    public function isActive(): bool;

    /**
     * The raw lifecycle state (`INVITED`, `ACTIVE`, `SUSPENDED`, `DISABLED` —
     * `docs/STATE-MACHINES.md` §J.5), so a refusal can say which one applied
     * rather than only that it did.
     */
    public function accountStatus(): string;

    /**
     * @return list<string>
     */
    public function grantedPropertyIds(): array;

    /**
     * @return list<Role>
     */
    public function roles(): array;

    public function holds(Permission $permission): bool;
}
