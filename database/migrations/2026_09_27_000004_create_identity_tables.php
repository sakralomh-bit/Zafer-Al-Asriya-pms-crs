<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity and access tables.
 *
 * Requirements: `DR-002`, `SEC-002`, `SEC-003`, `SEC-004`, `D-001`,
 * `ADR-0014`, `ADR-0007`, `AC-T-003-01` .. `AC-T-003-07`.
 *
 * --------------------------------------------------------------------------------
 * THERE IS NO `is_superadmin`, `is_admin`, `bypass_scope`, OR ANY EQUIVALENT
 * COLUMN ON `users`. `SEC-004` and `ADR-0014` §2 forbid it: a superuser flag is
 * a permanent, unauditable bypass of the scope model that also makes the
 * property-breakout test vacuous, because the superuser path would pass it by
 * construction. Group Manager's access to all 10 properties is 10 explicit rows
 * in `user_property_scope` (`AC-T-003-03`).
 * --------------------------------------------------------------------------------
 *
 * `roles` and `permissions` exist so the deny-by-default decision is stored as
 * data, but they are seeded from a fixed catalogue transcribed from `ADR-0014`
 * §5. `ADR-0014` explicitly rejected a general permission-per-action
 * configuration engine for v1.0, so there is no endpoint that edits them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('email', 254)->unique();
            $table->string('name', 200);
            $table->string('password');

            // docs/STATE-MACHINES.md §J.5
            $table->string('status', 16)->default('INVITED');

            $table->string('locale', 8)->default('en');
            $table->timestamp('email_verified_at', precision: 6)->nullable();
            $table->timestamp('mfa_enrolled_at', precision: 6)->nullable();
            $table->timestamp('last_login_at', precision: 6)->nullable();

            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->index('status', 'users_status_idx');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();

            // POS Cashier is defined so the model is complete, but it has no
            // Phase A permissions (D-001, ADR-0014 §5).
            $table->boolean('is_deferred_phase')->default(false);
            $table->string('deferred_phase', 8)->nullable();

            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('code', 80)->unique();
            $table->string('resource', 60);
            $table->string('action', 40);
            $table->string('description', 500)->nullable();

            // DM-4: every table carries created-at, updated-at, and a
            // concurrency version. `PermissionRecord` extends `DomainModel`,
            // which has Eloquent timestamps enabled, so without these columns
            // the seeder fails outright with
            // "Unknown column 'updated_at' in 'field list'".
            //
            // The catalogue is read-only at runtime (ADR-0014 rejected runtime
            // permission editing), but it is still seeded and re-seeded during
            // deployment, so it is a table with a lifecycle and carries the
            // columns like any other.
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->index(['resource', 'action'], 'permissions_resource_action_idx');
        });

        // A pure join table. It gets DM-4 timestamps but NOT `lock_version`:
        // a pivot row has no independent lifecycle to conflict over — the pair
        // is either present or absent, and a concurrent write resolves by
        // insert-or-cascade rather than by a version check. This is the one
        // place DM-4's concurrency column is deliberately omitted, and it is
        // enumerated in `tests/Architecture/SchemaConventionTest.php` so the
        // omission is a stated exception rather than an oversight.
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->char('role_id', 26);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->char('permission_id', 26);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();

            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->primary(['role_id', 'permission_id'], 'role_permissions_pk');
        });

        // ----------------------------------------------------------------------
        // user_roles — a role is NEVER global. A role assignment always names the
        // property in which it applies (DATA-MODEL §2.1).
        // ----------------------------------------------------------------------
        Schema::create('user_roles', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->char('role_id', 26);
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
            $table->char('property_id', 26);
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();

            $table->char('granted_by_user_id', 26)->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('granted_at', precision: 6);
            $table->timestamp('revoked_at', precision: 6)->nullable();
            $table->char('revoked_by_user_id', 26)->nullable();

            // DM-4. `UserRole` extends `DomainModel` and writes these.
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(
                ['user_id', 'role_id', 'property_id'],
                'user_roles_user_role_property_uq',
            );
            $table->index(['property_id', 'role_id'], 'user_roles_property_role_idx');
            $table->index(['user_id', 'revoked_at'], 'user_roles_active_idx');
        });

        // ----------------------------------------------------------------------
        // user_property_scope — an explicit GRANT, not a filter and not a flag
        // (ADR-0014 §2). Absence of a row means no access.
        //
        // `expires_at` and `approved_by_user_id` exist for the Support role, which
        // ADR-0014 §5 describes as "explicitly scoped, time-bound ... no grant
        // without a named approver and an expiry". They are nullable because
        // ordinary staff grants are not time-bound; the service enforces the
        // requirement per role rather than the schema assuming it for everyone.
        // ----------------------------------------------------------------------
        Schema::create('user_property_scope', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->char('property_id', 26);
            $table->foreign('property_id')->references('id')->on('properties')->restrictOnDelete();

            $table->char('granted_by_user_id', 26);
            $table->char('approved_by_user_id', 26)->nullable();
            $table->text('reason')->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->timestamp('granted_at', precision: 6);
            $table->timestamp('expires_at', precision: 6)->nullable();
            $table->timestamp('revoked_at', precision: 6)->nullable();
            $table->char('revoked_by_user_id', 26)->nullable();

            // DM-4. `UserPropertyScope` extends `DomainModel` and writes these.
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('created_at', precision: 6);
            $table->timestamp('updated_at', precision: 6);

            $table->unique(['user_id', 'property_id'], 'user_property_scope_uq');
            $table->index(['user_id', 'revoked_at', 'expires_at'], 'user_property_scope_active_idx');
            $table->index(['property_id', 'revoked_at'], 'user_property_scope_property_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_property_scope');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
};
