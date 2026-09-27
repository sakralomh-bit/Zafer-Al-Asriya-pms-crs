<?php

declare(strict_types=1);

use App\Shared\Database\CheckConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `organizations` and `legal_entities` — the root of the `D-001` hierarchy.
 *
 * Requirements: `D-001`, `DR-001`, `ADR-0007`, `AC-T-002-02`, `AC-T-002-03`.
 *
 * Keys are ULID surrogates (`ADR-0007` rule 1, `DATA-MODEL` DM-1). A property
 * code, a room number, or an email is never a primary key.
 *
 * `Property` is resolved THROUGH the organization, not as a root entity
 * (`DATA-MODEL` §1.2 rule 4), so a future `organization_id` is a column and an
 * index rather than a re-keying exercise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('name', 200);
            $table->string('code', 40)->unique();

            // DECIDED: a stable IANA timezone identifier. Data, not logic.
            $table->string('timezone', 64);

            // NOT a DECIDED value. `C-06` (single vs multi-currency, and whether
            // FX enters the ledger) is NOT CONFIRMED, so no group currency is
            // declared. The column exists so the model is not retrofitted; the
            // value stays NULL until C-06 is answered.
            $table->char('default_currency', 3)->nullable();

            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);
        });

        Schema::create('legal_entities', function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            // ADR-0007 rule 4: an organization is the root; a legal entity is
            // reached through it.
            $table->char('organization_id', 26);
            $table->foreign('organization_id')->references('id')->on('organizations')
                ->restrictOnDelete();

            $table->string('name', 200);
            $table->char('code', 40);

            // ------------------------------------------------------------------
            // B-06 IS NOT CONFIRMED (docs/BLOCKER-STATUS.md).
            //
            // `AC-T-002-03` requires these columns to exist NOW even though their
            // values are unknown, so the model is not retrofitted later. The
            // columns are therefore present and NULLABLE, and a legal entity
            // CANNOT be marked REGISTRATION_VERIFIED while either is NULL.
            //
            // No placeholder, no example number, no assumed country, and no
            // assumed count of legal entities.
            // ------------------------------------------------------------------
            $table->string('commercial_registration_number', 64)->nullable();
            $table->string('vat_registration_number', 64)->nullable();

            $table->string('registration_status', 32)->default('UNCONFIRMED');
            $table->string('timezone', 64);
            $table->char('currency', 3)->nullable();

            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['organization_id', 'code'], 'legal_entities_org_code_uq');
            $table->index(['organization_id', 'registration_status'], 'legal_entities_org_status_idx');
        });

        // `AC-T-002-03` is a structural requirement, so it is also a storage
        // requirement: a legal entity whose registration numbers are unknown
        // cannot be declared verified. This is what stops an unconfirmed B-06
        // from being papered over with a placeholder value.
        CheckConstraints::add(
            'legal_entities',
            'legal_entities_verified_requires_registration_numbers',
            "registration_status <> 'VERIFIED' "
            .'OR (commercial_registration_number IS NOT NULL AND vat_registration_number IS NOT NULL)',
        );
    }

    public function down(): void
    {
        CheckConstraints::drop('legal_entities', 'legal_entities_verified_requires_registration_numbers');
        Schema::dropIfExists('legal_entities');
        Schema::dropIfExists('organizations');
    }
};
