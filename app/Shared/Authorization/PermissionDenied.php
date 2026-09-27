<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;

/**
 * The caller's roles do not include the requested permission.
 *
 * Distinct from `PropertyScopeDenied` on purpose: "you could never reach this
 * property" and "you could reach it but may not do this to it" are different
 * answers, and collapsing them would hide a role misconfiguration behind what
 * looks like a scope problem.
 */
final class PermissionDenied extends DomainFailure
{
    public static function forPermission(string $permissionCode): self
    {
        return new self(
            ErrorCode::PermissionDenied,
            'Your role does not permit this action.',
            [['field' => 'permission', 'message' => $permissionCode]],
        );
    }
}
