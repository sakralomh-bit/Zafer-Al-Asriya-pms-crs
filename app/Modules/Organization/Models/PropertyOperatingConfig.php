<?php

declare(strict_types=1);

namespace App\Modules\Organization\Models;

use App\Shared\Domain\DomainModel;
use App\Shared\Tenancy\ScopedToProperty;

/**
 * Versioned, effective-dated operating configuration.
 *
 * EVERY POLICY VALUE IN THIS TABLE IS `TBD` (`C-05`, `C-04`): arrival and
 * departure cut-offs, no-show cut-off, booking window, hold duration, maximum
 * stay, same-day arrival. The columns exist so the model is not retrofitted; the
 * values stay NULL.
 *
 * A row cannot be `ACTIVE` while any value is NULL — enforced by the database
 * CHECK `operating_config_active_requires_every_policy_value`. That is what
 * makes an invented operating default unrepresentable rather than merely
 * discouraged.
 *
 * @see docs/BLOCKER-STATUS.md C-05, C-04
 */
final class PropertyOperatingConfig extends DomainModel
{
    use ScopedToProperty;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $table = 'property_operating_config';

    protected $fillable = [
        'property_id',
        'version',
        'status',
        'arrival_cutoff_time',
        'departure_cutoff_time',
        'no_show_cutoff_time',
        'booking_window_days',
        'hold_duration_minutes',
        'maximum_stay_nights',
        'same_day_arrival_allowed',
        'created_by_user_id',
        'approved_by_user_id',
        'reason',
        'correlation_id',
    ];

    protected $casts = [
        'version' => 'integer',
        'booking_window_days' => 'integer',
        'hold_duration_minutes' => 'integer',
        'maximum_stay_nights' => 'integer',
        'same_day_arrival_allowed' => 'boolean',
        'lock_version' => 'integer',
    ];

    /**
     * Which `C-05` values are still missing. A non-empty list means this
     * configuration cannot be activated.
     *
     * @return list<string>
     */
    public function unresolvedPolicyValues(): array
    {
        $mapping = [
            'arrival_cutoff_time' => $this->arrival_cutoff_time,
            'departure_cutoff_time' => $this->departure_cutoff_time,
            'no_show_cutoff_time' => $this->no_show_cutoff_time,
            'booking_window_days' => $this->booking_window_days,
            'hold_duration_minutes' => $this->hold_duration_minutes,
            'maximum_stay_nights' => $this->maximum_stay_nights,
            'same_day_arrival_allowed' => $this->same_day_arrival_allowed,
        ];

        $missing = [];

        foreach ($mapping as $field => $value) {
            if ($value === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    public function canBeActivated(): bool
    {
        return $this->unresolvedPolicyValues() === [];
    }
}
