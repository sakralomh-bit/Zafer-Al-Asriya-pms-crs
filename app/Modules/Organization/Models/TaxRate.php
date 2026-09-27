<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Shared\Domain\DomainModel;

/**
 * Versioned, effective-dated tax rate (`DATA-MODEL` §2.2, `Prd_Maker.md` §61).
 *
 * --------------------------------------------------------------------------------
 * THERE IS NO `rate` COLUMN, AND THAT IS DELIBERATE.
 *
 * `C-04` (VAT inclusive/exclusive, rounding stage, rounding mode, storage
 * precision and scale) is NOT CONFIRMED. `DM-2` requires exact `DECIMAL` with
 * explicit precision and scale for any monetary value, so creating the column
 * now would require inventing the precision — which `docs/TASKS.md` T-002
 * explicitly forbids: "Tax rate precision cannot be chosen until C-04 — this
 * task must not invent a precision."
 *
 * The versioned, effective-dated, permission-controlled container exists so
 * that `T-019` can add the column when the accountable owner has decided,
 * without a redesign.
 * --------------------------------------------------------------------------------
 *
 * @see docs/BLOCKER-STATUS.md C-04
 */
final class TaxRate extends DomainModel
{
    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $table = 'tax_rates';

    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'effective_from',
        'effective_to',
        'version',
        'status',
        'created_by_user_id',
        'reason',
        'correlation_id',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'version' => 'integer',
        'lock_version' => 'integer',
    ];

    /**
     * Always true in v1.0. Present so calling code is forced to confront the
     * blocker rather than reach for a rate that does not exist.
     */
    public function hasRate(): bool
    {
        return false;
    }
}
