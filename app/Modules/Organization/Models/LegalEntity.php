<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Shared\Domain\DomainModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The invoicing entity (`DATA-MODEL` §2.2).
 *
 * `B-06` — whether the group has ONE legal entity or SEVERAL — is NOT CONFIRMED
 * as of 2026-09-27. This model therefore exists and is complete in structure
 * (per `AC-T-002-03`, "the model must not be retrofitted"), while the values it
 * would carry are absent:
 *
 *  - `commercial_registration_number` and `vat_registration_number` are NULL.
 *  - `registration_status` starts at `UNCONFIRMED`.
 *  - A database CHECK constraint prevents `VERIFIED` while either number is NULL.
 *
 * Nothing here infers a country, a registration format, or a count of entities.
 *
 * @see docs/BLOCKER-STATUS.md B-06
 */
final class LegalEntity extends DomainModel
{
    public const STATUS_UNCONFIRMED = 'UNCONFIRMED';

    public const STATUS_VERIFIED = 'VERIFIED';

    protected $table = 'legal_entities';

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'commercial_registration_number',
        'vat_registration_number',
        'registration_status',
        'timezone',
        'currency',
    ];

    /**
     * True when `B-06` still stands unanswered for this entity. Any code that
     * needs a registration number must check this rather than assume the value
     * is present.
     */
    public function hasConfirmedRegistration(): bool
    {
        return $this->registration_status === self::STATUS_VERIFIED
            && $this->commercial_registration_number !== null
            && $this->commercial_registration_number !== ''
            && $this->vat_registration_number !== null
            && $this->vat_registration_number !== '';
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }
}
