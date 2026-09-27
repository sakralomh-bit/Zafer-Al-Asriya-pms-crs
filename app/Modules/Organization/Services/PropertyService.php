<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Identity\Authorization\Permission;
use App\Modules\Identity\Contracts\Actor;
use App\Modules\Identity\Contracts\AuthorizesRequests;
use App\Modules\Organization\Models\ConfigurationVersion;
use App\Modules\Organization\Models\Property;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditRecord;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\ValidationFailed;
use App\Shared\Domain\DomainFailure;
use App\Shared\Domain\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Property master data and its versioned configuration history.
 *
 * `AC-T-002-01`, `AC-T-002-04`.
 *
 * `AC-T-002-04` requires configuration changes to be "validated,
 * permission-controlled, versioned, recoverable, and audited"
 * (`Prd_Maker.md` §61). Each of those five is a distinct mechanism here:
 *
 *   validated           -> explicit argument checks + the database CHECK on the
 *                          operating config
 *   permission-controlled -> `AuthorizationService`, before any read or write
 *   versioned           -> `configuration_versions`, a new row per change
 *   recoverable         -> `restoreVersion()` writes a NEW row carrying the old
 *                          payload; history is never rewritten or deleted
 *   audited             -> `AuditRecorder`, inside the same transaction
 *
 * `AC-T-002-05` / `ADR-0007` rule 5: a future `organization_id` must be
 * addable without changing business logic. This service takes a `propertyId`
 * and never reaches for an organization literal, so the addition is a schema
 * change and nothing more.
 */
final class PropertyService
{
    public function __construct(
        private readonly AuthorizesRequests $authorization,
        private readonly AuditRecorder $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function create(
        Actor $actor,
        string $organizationId,
        string $name,
        string $code,
        string $timezone,
        ?string $currency,
        ?string $taxRateId,
        ?string $address,
        array $settings = [],
        ?string $correlationId = null,
    ): Property {
        $this->assertValidTimezone($timezone);

        if (trim($name) === '' || trim($code) === '') {
            throw DomainRuleViolation::validationFailed(
                field: 'name',
                message: 'A property requires a name and a code.',
            );
        }

        // Creating a property is an organization-level act, and `ADR-0007`
        // names organization-level admin as the single exception to "no query
        // executes without a property scope". The exception still requires the
        // permission, an active identity, at least one live property grant, and
        // an audit record — it is not a bypass.
        $this->authorization->authorizeOrganizationLevel(
            $actor,
            Permission::PropertyCreate,
            $organizationId,
            subjectType: 'organization',
            subjectId: $organizationId,
            correlationId: $correlationId,
        );

        return DB::transaction(function () use (
            $actor,
            $organizationId,
            $name,
            $code,
            $timezone,
            $currency,
            $taxRateId,
            $address,
            $settings,
            $correlationId,
        ): Property {
            $property = new Property();
            $property->forceFill([
                'organization_id' => $organizationId,
                'name' => $name,
                'code' => $code,
                'timezone' => $timezone,
                'currency' => $currency,
                'tax_rate_id' => $taxRateId,
                'address' => $address,
                'settings' => $settings === [] ? null : $settings,
                'is_active' => true,
            ])->save();

            $this->audit->record(AuditRecord::of(
                action: AuditAction::PropertyCreated,
                actorUserId: $actor->identifier(),
                actorRole: ($actor->roles()[0] ?? null)?->value,
                propertyId: (string) $property->id,
                subjectType: 'property',
                subjectId: (string) $property->id,
                source: 'property',
                correlationId: $correlationId,
                after: [
                    'name' => $name,
                    'code' => $code,
                    'timezone' => $timezone,
                    'currency' => $currency,
                    'tax_rate_id' => $taxRateId,
                ],
            ));

            return $property;
        });
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $settings
     */
    public function update(
        Actor $actor,
        Property $property,
        array $attributes,
        ?string $reason,
        ?string $correlationId,
    ): Property {
        $this->authorization->authorize(
            $actor,
            Permission::PropertyUpdate,
            (string) $property->id,
            subjectType: 'property',
            subjectId: (string) $property->id,
            correlationId: $correlationId,
        );

        if (isset($attributes['timezone'])) {
            $this->assertValidTimezone((string) $attributes['timezone']);
        }

        $before = [
            'name' => $property->name,
            'code' => $property->code,
            'timezone' => $property->timezone,
            'currency' => $property->currency,
            'tax_rate_id' => $property->tax_rate_id,
            'is_active' => (bool) $property->is_active,
        ];

        return DB::transaction(function () use ($actor, $property, $attributes, $reason, $correlationId, $before): Property {
            /** @var Property $locked */
            $locked = Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill($attributes);
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            $this->audit->record(AuditRecord::of(
                action: AuditAction::PropertyUpdated,
                actorUserId: $actor->identifier(),
                actorRole: ($actor->roles()[0] ?? null)?->value,
                propertyId: (string) $locked->id,
                subjectType: 'property',
                subjectId: (string) $locked->id,
                source: 'property',
                correlationId: $correlationId,
                reason: $reason,
                before: $before,
                after: [
                    'name' => $locked->name,
                    'code' => $locked->code,
                    'timezone' => $locked->timezone,
                    'currency' => $locked->currency,
                    'tax_rate_id' => $locked->tax_rate_id,
                    'is_active' => (bool) $locked->is_active,
                ],
            ));

            $property->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * Append a new configuration version for a property. The previous ACTIVE
     * version is marked SUPERSEDED and left in place.
     *
     * @param array<string, mixed> $payload
     */
    public function recordConfigurationVersion(
        Actor $actor,
        Property $property,
        string $configKey,
        array $payload,
        ?string $reason,
        ?string $correlationId,
        bool $activate = true,
    ): ConfigurationVersion {
        $this->authorization->authorize(
            $actor,
            Permission::ConfigurationUpdate,
            (string) $property->id,
            subjectType: 'property',
            subjectId: (string) $property->id,
            correlationId: $correlationId,
        );

        return DB::transaction(function () use (
            $actor,
            $property,
            $configKey,
            $payload,
            $reason,
            $correlationId,
            $activate,
        ): ConfigurationVersion {
            $nextVersion = (int) ConfigurationVersion::query()
                ->where('property_id', $property->id)
                ->where('config_key', $configKey)
                ->max('version') + 1;

            if ($activate) {
                ConfigurationVersion::query()
                    ->where('property_id', $property->id)
                    ->where('config_key', $configKey)
                    ->where('status', ConfigurationVersion::STATUS_ACTIVE)
                    ->update(['status' => ConfigurationVersion::STATUS_SUPERSEDED]);
            }

            $version = new ConfigurationVersion();
            $version->forceFill([
                'property_id' => $property->id,
                'config_key' => $configKey,
                'version' => $nextVersion,
                'payload' => $payload,
                'status' => $activate ? ConfigurationVersion::STATUS_ACTIVE : ConfigurationVersion::STATUS_SUPERSEDED,
                'changed_by_user_id' => $actor->identifier(),
                'reason' => $reason,
                'correlation_id' => $correlationId,
            ])->save();

            $this->audit->record(AuditRecord::of(
                action: AuditAction::PropertyConfigurationChanged,
                actorUserId: $actor->identifier(),
                actorRole: ($actor->roles()[0] ?? null)?->value,
                propertyId: (string) $property->id,
                subjectType: 'configuration_version',
                subjectId: (string) $version->id,
                source: 'property_configuration',
                correlationId: $correlationId,
                reason: $reason,
                before: ['active_version' => $nextVersion - 1],
                after: ['active_version' => $nextVersion, 'config_key' => $configKey],
            ));

            return $version;
        });
    }

    /**
     * `AC-T-002-04` "recoverable". Copy-forward: a NEW row carrying the earlier
     * payload. The superseded history stays exactly as it was, so the reason a
     * business rule produced a financial result is still explainable later.
     */
    public function restoreVersion(
        Actor $actor,
        Property $property,
        string $configKey,
        int $version,
        ?string $reason,
        ?string $correlationId,
    ): ConfigurationVersion {
        $this->authorization->authorize(
            $actor,
            Permission::ConfigurationUpdate,
            (string) $property->id,
            subjectType: 'property',
            subjectId: (string) $property->id,
            correlationId: $correlationId,
        );

        $source = ConfigurationVersion::query()
            ->where('property_id', $property->id)
            ->where('config_key', $configKey)
            ->where('version', $version)
            ->first();

        if ($source === null) {
            throw ValidationFailed::field('version', 'That configuration version does not exist for this property.');
        }

        $restored = $this->recordConfigurationVersion(
            $actor,
            $property,
            $configKey,
            $source->payload ?? [],
            $reason,
            $correlationId,
        );

        $restored->forceFill(['restored_from_version' => $version])->save();

        $this->audit->record(AuditRecord::of(
            action: AuditAction::ConfigurationVersionRestored,
            actorUserId: $actor->identifier(),
            actorRole: ($actor->roles()[0] ?? null)?->value,
            propertyId: (string) $property->id,
            subjectType: 'configuration_version',
            subjectId: (string) $restored->id,
            source: 'property_configuration',
            correlationId: $correlationId,
            reason: $reason,
            before: ['restored_from_version' => $version],
            after: ['restored_as_version' => (int) $restored->version],
        ));

        return $restored;
    }

    private function assertValidTimezone(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw DomainRuleViolation::validationFailed(
                field: 'timezone',
                message: 'A property timezone must be a valid IANA timezone identifier.',
            );
        }
    }
}
