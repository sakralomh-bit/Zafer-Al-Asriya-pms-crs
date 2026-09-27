<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base for every domain model.
 *
 * `ADR-0007` rule 1 and `DATA-MODEL` DM-1: primary keys are stable internal
 * surrogate identifiers. A phone number, an email, a name, a room number, or a
 * property code is never a key. ULIDs are used so that ids are sortable by
 * creation time, which keeps index locality good without a database sequence.
 */
abstract class DomainModel extends Model
{
    use HasUlids;

    /**
     * DM-4: every table carries a concurrency version for optimistic checks
     * (`ADR-0008`).
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * `DM-7`: timestamps are stored in one canonical representation (UTC).
     * A business date, where one applies, is a SEPARATE column and is never
     * derived from these.
     */
    protected $casts = [
        'lock_version' => 'integer',
    ];
}
