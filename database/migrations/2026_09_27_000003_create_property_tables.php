<?php

declare(strict_types=1);

use App\Shared\Database\CheckConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `properties`, `property_operating_config`, `tax_rates`, `configuration_versions`.
 *
 * Requirements: `D-001`, `DR-001`, `FR-001`, `AC-T-002-01`, `AC-T-002-04`,
 * `Prd_Maker.md` §61.
 *
 * `property_id` leads the index on every property-scoped table (`DM-5`).
 *
 * NO MONETARY COLUMN APPEARS IN THIS MIGRATION. `C-04` is NOT CONFIRMED, so
 * there is no approved precision or scale, and `DM-2` forbids a `DECIMAL`
 * without one. `tax_rates` consequently has no `rate` column: the versioned,
 * effective-dated container exists so the model is not retrofitted, and the
 * value cannot be created until the finance/compliance owner decides its type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            // ADR-0007 rule 4 — a property is reached through its organization.
            $table->char('organization_id', 26);
            $table->foreign('organization_id')->references('id')->on('organizations')
                ->restrictOnDelete();

            $table->string('name', 200);
            $table->string('code', 40);
            $table->string('timezone', 64);

            // `C-06` is NOT CONFIRMED. The column is per-property by design; the
            // value is supplied when the property is configured and is never
            // defaulted from an organization-level assumption.
            $table->char('currency', 3)->nullable();

            // FK to the tax profile. Created nullable because the applicable tax
            // policy is `C-04`-blocked; a property may be created before its tax
            // profile is known, and that is a valid state, not a defect.
            $table->char('tax_rate_id', 26)->nullable();

            $table->text('address')->nullable();
            $table->json('settings')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['organization_id', 'code'], 'properties_org_code_uq');
            $table->index(['organization_id', 'is_active'], 'properties_org_active_idx');
        });

        // ----------------------------------------------------------------------
        // property_operating_config
        //
        // Cut-offs, booking window, hold duration, same-day arrival and no-show
        // behaviour are ALL `TBD` (C-05, C-04). The columns exist so the model is
        // not retrofitted. The values stay NULL.
        //
        // The CHECK constraint is the important part: a configuration row may not
        // be marked ACTIVE while any of those values is unknown. An invented
        // default is therefore not merely discouraged, it is unrepresentable as
        // an active policy.
        // ----------------------------------------------------------------------
        Schema::create('property_operating_config', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('property_id', 26);
            $table->foreign('property_id')->references('id')->on('properties')
                ->restrictOnDelete();

            $table->unsignedInteger('version');
            $table->string('status', 16)->default('DRAFT');

            $table->time('arrival_cutoff_time')->nullable();
            $table->time('departure_cutoff_time')->nullable();
            $table->time('no_show_cutoff_time')->nullable();
            $table->unsignedInteger('booking_window_days')->nullable();
            $table->unsignedInteger('hold_duration_minutes')->nullable();
            $table->unsignedInteger('maximum_stay_nights')->nullable();
            $table->boolean('same_day_arrival_allowed')->nullable();

            $table->char('created_by_user_id', 26)->nullable();
            $table->char('approved_by_user_id', 26)->nullable();
            $table->text('reason')->nullable();
            $table->char('correlation_id', 26)->nullable();

            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['property_id', 'version'], 'operating_config_property_version_uq');
            $table->index(['property_id', 'status'], 'operating_config_property_status_idx');
        });

        // The check is the important part: a configuration row may not be marked
        // ACTIVE while any of those values is unknown. An invented default is
        // therefore not merely discouraged, it is unrepresentable as an active
        // policy.
        CheckConstraints::add(
            'property_operating_config',
            'operating_config_active_requires_every_policy_value',
            "status <> 'ACTIVE' OR ("
            .'arrival_cutoff_time IS NOT NULL'
            .' AND departure_cutoff_time IS NOT NULL'
            .' AND no_show_cutoff_time IS NOT NULL'
            .' AND booking_window_days IS NOT NULL'
            .' AND hold_duration_minutes IS NOT NULL'
            .' AND maximum_stay_nights IS NOT NULL'
            .' AND same_day_arrival_allowed IS NOT NULL'
            .')',
        );

        // ----------------------------------------------------------------------
        // tax_rates — versioned, effective-dated, permission-controlled, audited
        // (DATA-MODEL §2.2).
        //
        // DELIBERATELY ABSENT: the `rate` column. `DM-2` requires exact DECIMAL
        // with explicit precision and scale; `C-04` has not chosen them; and
        // `TASKS.md` T-002 states "this task must not invent a precision".
        // `T-019` creates the column when C-04 is answered.
        // ----------------------------------------------------------------------
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('organization_id', 26);
            $table->foreign('organization_id')->references('id')->on('organizations')
                ->restrictOnDelete();

            $table->string('code', 60);
            $table->string('name', 200);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('DRAFT');

            $table->char('created_by_user_id', 26)->nullable();
            $table->text('reason')->nullable();
            $table->char('correlation_id', 26)->nullable();

            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['organization_id', 'code', 'version'], 'tax_rates_code_version_uq');
            $table->index(['organization_id', 'status'], 'tax_rates_org_status_idx');
        });

        // ----------------------------------------------------------------------
        // configuration_versions — the recoverable, append-only configuration
        // history required by `AC-T-002-04` and `Prd_Maker.md` §61.
        //
        // "Recoverable" is implemented as copy-forward: restoring a prior version
        // writes a NEW row carrying that version's values. History is never
        // rewritten, so the explanation for a business result survives.
        // ----------------------------------------------------------------------
        Schema::create('configuration_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            // DM-5: property-scoped table, index leads with property_id.
            $table->char('property_id', 26);
            $table->foreign('property_id')->references('id')->on('properties')
                ->restrictOnDelete();

            $table->string('config_key', 80);
            $table->unsignedInteger('version');
            $table->json('payload');
            $table->string('status', 16)->default('SUPERSEDED');
            $table->char('restored_from_version')->nullable();

            $table->char('changed_by_user_id', 26)->nullable();
            $table->text('reason')->nullable();
            $table->char('correlation_id', 26)->nullable();

            $table->timestamp('created_at', precision: 6);

            $table->unique(['property_id', 'config_key', 'version'], 'config_versions_key_version_uq');
            $table->index(['property_id', 'config_key', 'status'], 'config_versions_active_idx');
        });
    }

    public function down(): void
    {
        CheckConstraints::drop('property_operating_config', 'operating_config_active_requires_every_policy_value');
        Schema::dropIfExists('configuration_versions');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('property_operating_config');
        Schema::dropIfExists('properties');
    }
};
