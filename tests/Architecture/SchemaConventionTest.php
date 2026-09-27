<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The data model's structural rules, asserted against the live schema.
 *
 * `DATA-MODEL` DM-4, DM-5, and DM-1 are claims about the DATABASE, so they are
 * tested against the database. Asserting them against the migration source
 * would only prove the migration says what the migration says.
 *
 * This suite exists because four tables violated DM-4 and nothing noticed: the
 * `AuthorizationCatalogueSeeder` could not run at all
 * (`Unknown column 'updated_at' in 'field list'`), so the entire authorization
 * catalogue was unseedable and every authorization test errored in setUp rather
 * than reporting a real failure. A schema rule that is written down but never
 * checked is a rule the first migration quietly broke.
 *
 * The exceptions are ENUMERATED here rather than being discovered. DM-4 says
 * "every table", which is false for append-only tables — an `updated_at` on a
 * table whose UPDATE is rejected by a database trigger is a column that lies
 * about its own table. DM-4 was corrected in `docs/DATA-MODEL.md` to state both
 * exceptions, and this test is where they are pinned.
 */
final class SchemaConventionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Laravel's own tables. They are not domain tables and carry no domain
     * convention.
     *
     * @var list<string>
     */
    private const FRAMEWORK_TABLES = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions',
    ];

    /**
     * Append-only per ADR-0016 (audit trail) and ADR-0009 (posted financial
     * records): UPDATE and DELETE are rejected at the storage layer, so an
     * `updated_at` column could never be written and a `lock_version` would
     * never be compared.
     *
     * @var list<string>
     */
    private const APPEND_ONLY_TABLES = [
        'audit_events',
        'configuration_versions',
    ];

    /**
     * Pure join tables: present-or-absent, with no independent lifecycle to
     * conflict over.
     *
     * @var list<string>
     */
    private const JOIN_TABLES = [
        'role_permissions',
    ];

    /**
     * Tables that are not property-scoped. DM-5 requires `property_id` on every
     * property-scoped table; these are the ones that are scoped elsewhere or
     * are the hierarchy root.
     *
     * `properties` is the ROOT of the property hierarchy and has no `property_id`
     * of its own (ADR-0007 rule 4). The rest resolve tenancy through
     * `organization_id` or are global catalogues.
     *
     * @var list<string>
     */
    private const NOT_PROPERTY_SCOPED = [
        'properties',
        'organizations',
        'legal_entities',
        'tax_rates',
        'roles',
        'permissions',
        'role_permissions',
        'users',
    ];

    // =====================================================================
    // DM-4
    // =====================================================================

    public function test_every_domain_table_carries_a_created_at(): void
    {
        foreach ($this->domainTables() as $table) {
            $this->assertTrue(
                $this->hasColumn($table, 'created_at'),
                "Table [{$table}] has no created_at (DM-4).",
            );
        }
    }

    public function test_every_mutable_domain_table_carries_an_updated_at(): void
    {
        foreach ($this->domainTables() as $table) {
            if (in_array($table, self::APPEND_ONLY_TABLES, true)) {
                continue;
            }

            $this->assertTrue(
                $this->hasColumn($table, 'updated_at'),
                "Table [{$table}] has no updated_at (DM-4).",
            );
        }
    }

    public function test_every_mutable_domain_table_carries_a_lock_version(): void
    {
        foreach ($this->domainTables() as $table) {
            if (in_array($table, self::APPEND_ONLY_TABLES, true) || in_array($table, self::JOIN_TABLES, true)) {
                continue;
            }

            $this->assertTrue(
                $this->hasColumn($table, 'lock_version'),
                "Table [{$table}] has no lock_version (DM-4, ADR-0008).",
            );
        }
    }

    /**
     * The other side of the append-only exception. An `updated_at` on a table
     * whose UPDATE is rejected is worse than no column: it implies the row can
     * change, and any reader who sees it will trust it.
     */
    public function test_an_append_only_table_does_not_claim_to_be_updatable(): void
    {
        foreach (self::APPEND_ONLY_TABLES as $table) {
            $this->assertFalse(
                $this->hasColumn($table, 'updated_at'),
                "Append-only table [{$table}] must not carry updated_at; nothing can ever write it.",
            );
        }
    }

    // =====================================================================
    // DM-1
    // =====================================================================

    /**
     * DM-1: primary keys are stable internal surrogate identifiers — a phone
     * number, email, room number, or property code is never a key. Every domain
     * table uses a 26-character ULID.
     */
    public function test_every_domain_table_uses_a_ulid_primary_key(): void
    {
        foreach ($this->domainTables() as $table) {
            if (in_array($table, self::JOIN_TABLES, true)) {
                continue;
            }

            $this->assertTrue(
                $this->hasColumn($table, 'id'),
                "Table [{$table}] has no id column (DM-1).",
            );

            // `SHOW COLUMNS` reports the full declared type, e.g. `char(26)`.
            // A ULID is a fixed 26-character string; `varchar` or an
            // auto-incrementing integer would both violate DM-1.
            $type = strtolower((string) ($this->column($table, 'id')['Type'] ?? ''));

            $this->assertSame(
                'char(26)',
                $type,
                "Table [{$table}] id must be a fixed 26-character ULID, not a varying or "
                . 'auto-incrementing type (DM-1).',
            );
        }
    }

    /**
     * A natural key used as a PRIMARY KEY is the specific failure DM-1 rules
     * out. These are the columns that must never be unique-and-primary.
     */
    public function test_no_natural_business_key_is_used_as_a_primary_key(): void
    {
        foreach ($this->domainTables() as $table) {
            foreach (['email', 'phone', 'room_number', 'code', 'national_id'] as $natural) {
                if (! $this->hasColumn($table, $natural)) {
                    continue;
                }

                $key = $this->column($table, $natural)['Key'] ?? '';

                $this->assertNotSame(
                    'PRI',
                    $key,
                    "Table [{$table}] makes [{$natural}] a primary key. DM-1 requires a stable "
                    . 'internal surrogate identifier instead.',
                );
            }
        }
    }

    // =====================================================================
    // DM-5
    // =====================================================================

    public function test_every_property_scoped_table_carries_a_property_id(): void
    {
        foreach ($this->domainTables() as $table) {
            if (in_array($table, self::NOT_PROPERTY_SCOPED, true)) {
                continue;
            }

            $this->assertTrue(
                $this->hasColumn($table, 'property_id'),
                "Table [{$table}] is property-scoped but has no property_id (DM-5).",
            );
        }
    }

    /**
     * `Property` is the ROOT of the property hierarchy. A `property_id` on it
     * would be self-referential, and `ADR-0007` rule 4 resolves it through
     * `organization_id` instead. This asserts the exception is real rather than
     * an accident of an unfinished migration.
     */
    public function test_the_property_table_is_the_root_and_has_no_property_id(): void
    {
        $this->assertFalse(
            $this->hasColumn('properties', 'property_id'),
            'Property must not carry its own property_id; it is the root of the hierarchy (ADR-0007 §4).',
        );

        $this->assertTrue(
            $this->hasColumn('properties', 'organization_id'),
            'Property must resolve tenancy through organization_id (ADR-0007 §4).',
        );
    }

    // =====================================================================
    // The rule cannot be checked by a rule that finds nothing
    // =====================================================================

    /**
     * A convention test over an empty table list passes trivially. This asserts
     * the schema is actually present, so a rename or a failed migration cannot
     * turn the whole suite green.
     */
    public function test_the_schema_is_actually_present(): void
    {
        $tables = $this->domainTables();

        $this->assertGreaterThan(
            10,
            count($tables),
            'The schema appears to be missing. Every convention below would pass vacuously.',
        );

        foreach ([
            'users', 'properties', 'physical_rooms', 'housekeeping_tasks',
            'user_property_scope', 'permissions', 'audit_events',
        ] as $expected) {
            $this->assertContains(
                $expected,
                $tables,
                "Expected table [{$expected}] to exist.",
            );
        }
    }

    // =====================================================================

    /**
     * @return list<string>
     */
    private function domainTables(): array
    {
        $tables = [];

        foreach (Schema::getTableListing() as $listing) {
            // `getTableListing()` returns schema-qualified names
            // (`zafer_pms_test.audit_events`) when the connection reports a
            // default schema. Everything below compares bare table names, so
            // the qualifier is stripped rather than worked around.
            $table = strrchr($listing, '.');
            $table = $table === false ? $listing : substr($table, 1);

            if ($table === '' || in_array($table, self::FRAMEWORK_TABLES, true)) {
                continue;
            }

            $tables[] = $table;
        }

        sort($tables);

        return array_values(array_unique($tables));
    }

    private function hasColumn(string $table, string $column): bool
    {
        return array_key_exists($column, $this->columns($table));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function columns(string $table): array
    {
        $rows = DB::select('SHOW COLUMNS FROM `' . $table . '`');

        $columns = [];

        foreach ($rows as $row) {
            $columns[(string) $row->Field] = (array) $row;
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function column(string $table, string $column): ?array
    {
        return $this->columns($table)[$column] ?? null;
    }
}
