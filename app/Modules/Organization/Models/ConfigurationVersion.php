<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Shared\Domain\DomainModel;
use App\Shared\Tenancy\ScopedToProperty;

/**
 * Append-only configuration history (`AC-T-002-04`, `Prd_Maker.md` §61).
 *
 * "Recoverable" is implemented as COPY-FORWARD. Restoring an earlier version
 * writes a NEW row carrying that version's payload; it never rewrites or deletes
 * history. A configuration that produced a financial result must remain
 * explainable afterwards, which a mutable history could not guarantee.
 */
final class ConfigurationVersion extends DomainModel
{
    use ScopedToProperty;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $table = 'configuration_versions';

    protected $fillable = [
        'property_id',
        'config_key',
        'version',
        'payload',
        'status',
        'restored_from_version',
        'changed_by_user_id',
        'reason',
        'correlation_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'version' => 'integer',
        'restored_from_version' => 'integer',
    ];

    public function createdAt(): string
    {
        return $this->created_at->toIso8601String();
    }
}
