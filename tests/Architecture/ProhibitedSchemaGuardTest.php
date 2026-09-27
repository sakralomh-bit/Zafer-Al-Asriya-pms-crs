<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Console\Commands\GuardProhibitedSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `zafer:guard-prohibited-schema` is itself code, and a rule nobody has ever
 * seen fail is not known to work.
 *
 * The same reasoning as `GuardSelfTest`, applied to a different kind of guard:
 * that one plants a FILE and asserts the scan rejects it, this one feeds the
 * rule set a synthetic column list and asserts it rejects that. No real table
 * is created — a test whose subject is "does this string match a prohibition"
 * has no business creating and dropping a table in a shared test database.
 *
 * The rules are asserted in BOTH directions, and the negative direction matters
 * more here. The first version of the card-data rule matched `pan` as a
 * substring, and it rejected `physical_rooms.occupancy_status` and
 * `room_types.max_occupancy` — columns the data model requires. A guard that
 * fires on a compliant schema is a guard the team turns off, so
 * `test_it_accepts_columns_whose_names_merely_contain_banned_letters` is not
 * defensive padding; it pins the fix.
 *
 * @see docs/DATA-MODEL.md §2.6, §2.7, §2.8, §5
 */
final class ProhibitedSchemaGuardTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // R1 — the three room-status axes (DATA-MODEL §2.7)
    // =====================================================================

    public function test_it_rejects_a_unified_room_status_column(): void
    {
        $violations = $this->guard()->violationsFor('physical_rooms', [
            'id' => 'char(26)',
            'property_id' => 'char(26)',
            'status' => 'varchar(32)',
            'occupancy_status' => 'varchar(32)',
            'housekeeping_status' => 'varchar(32)',
            'availability_status' => 'varchar(32)',
        ]);

        $this->assertStringContainsString('unified [status] column', $this->joined($violations));
    }

    public function test_it_rejects_a_room_missing_a_status_axis(): void
    {
        foreach (['occupancy_status', 'housekeeping_status', 'availability_status'] as $missing) {
            $columns = [
                'occupancy_status' => 'varchar(32)',
                'housekeeping_status' => 'varchar(32)',
                'availability_status' => 'varchar(32)',
            ];

            unset($columns[$missing]);

            $violations = $this->guard()->violationsFor('physical_rooms', $columns);

            $this->assertStringContainsString(
                $missing,
                $this->joined($violations),
                "physical_rooms without [{$missing}] must be rejected; §2.7 defines three axes.",
            );
        }
    }

    public function test_it_accepts_the_three_axes_without_a_unified_status(): void
    {
        $this->assertSame([], $this->guard()->violationsFor('physical_rooms', [
            'id' => 'char(26)',
            'property_id' => 'char(26)',
            'occupancy_status' => 'varchar(32)',
            'housekeeping_status' => 'varchar(32)',
            'availability_status' => 'varchar(32)',
        ]));
    }

    /**
     * The axes rule is scoped to rooms on purpose. `housekeeping_tasks.status` is
     * the single documented status of a task (§J.4), so a guard that banned
     * `status` everywhere would be rejecting correct design.
     */
    public function test_the_unified_status_rule_does_not_apply_to_other_tables(): void
    {
        $this->assertSame([], $this->guard()->violationsFor('housekeeping_tasks', [
            'id' => 'char(26)',
            'status' => 'varchar(32)',
        ]));
    }

    // =====================================================================
    // R2 — card data is never stored (DM-9, §2.8)
    // =====================================================================

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function prohibitedCardColumnProvider(): array
    {
        return [
            'card number' => ['payment_provider_references', ['card_number' => 'varchar(19)']],
            'cvv' => ['payment_provider_references', ['cvv' => 'varchar(4)']],
            'cvc2' => ['payment_provider_references', ['cvc2' => 'varchar(4)']],
            'bare pan' => ['payment_provider_references', ['pan' => 'varchar(19)']],
            'prefixed pan' => ['payment_provider_references', ['card_pan' => 'varchar(19)']],
            'track data' => ['payment_provider_references', ['track_data' => 'varchar(40)']],
            'track one' => ['payment_provider_references', ['track_1' => 'varchar(19)']],
            'track two legacy name' => ['payment_provider_references', ['track2' => 'varchar(19)']],
            'cardholder name' => ['payment_provider_references', ['cardholder_name' => 'varchar(80)']],
            'no underscores' => ['payment_provider_references', ['cardnumber' => 'varchar(19)']],
        ];
    }

    /**
     * @param  array<string, string>  $columns
     */
    #[DataProvider('prohibitedCardColumnProvider')]
    public function test_it_rejects_a_card_data_column(string $table, array $columns): void
    {
        $column = (string) array_key_first($columns);

        $violations = $this->guard()->violationsFor($table, $columns);

        $this->assertStringContainsString(
            '['.$column.']',
            $this->joined($violations),
            "{$table}.{$column} is card data and DM-9 states it is never stored.",
        );
    }

    /**
     * Regression for the `pan` substring defect: these are required columns of the
     * current schema, and the guard rejected them.
     */
    public function test_it_accepts_columns_whose_names_merely_contain_banned_letters(): void
    {
        $this->assertSame([], $this->guard()->violationsFor('room_types', [
            'id' => 'char(26)',
            'max_occupancy' => 'tinyint unsigned',
            'code' => 'varchar(32)',
        ]), 'room_types.max_occupancy is a required column, not a PAN.');

        $this->assertSame([], $this->guard()->violationsFor('physical_rooms', [
            'id' => 'char(26)',
            'occupancy_status' => 'varchar(32)',
            'housekeeping_status' => 'varchar(32)',
            'availability_status' => 'varchar(32)',
        ]), 'physical_rooms.occupancy_status contains "pan" and must pass.');
    }

    /**
     * A card expiry is only prohibited where a card could plausibly be stored. A
     * document expiry is legitimate, and a guard that flagged those would be
     * inventing a prohibition the documentation does not state.
     */
    public function test_a_card_expiry_is_only_prohibited_on_a_payment_table(): void
    {
        $this->assertNotSame(
            [],
            $this->guard()->violationsFor('payment_provider_references', ['card_expiry' => 'char(4)']),
            '§2.8 never models a card expiry.',
        );

        $this->assertSame(
            [],
            $this->guard()->violationsFor('guests', ['passport_expiry' => 'date']),
            'A document expiry is a document field, not a card field.',
        );
    }

    // =====================================================================
    // R3 — redacted payloads (DATA-MODEL §5)
    // =====================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function prohibitedAuditColumnProvider(): array
    {
        return [
            'document number' => ['document_number'],
            'national id' => ['national_id'],
            'passport number' => ['passport_number'],
            'iqama number' => ['iqama_number'],
        ];
    }

    #[DataProvider('prohibitedAuditColumnProvider')]
    public function test_it_rejects_an_identity_document_column_on_the_audit_trail(string $column): void
    {
        $violations = $this->guard()->violationsFor('audit_events', [
            'id' => 'char(26)',
            $column => 'varchar(40)',
        ]);

        $this->assertStringContainsString(
            '['.$column.']',
            $this->joined($violations),
            "§5 states the audit payload never contains document numbers, so [{$column}] must be rejected.",
        );
    }

    public function test_the_document_number_rule_is_scoped_to_redacted_payloads(): void
    {
        $this->assertSame(
            [],
            $this->guard()->violationsFor('guests', ['document_number' => 'varchar(40)']),
            'A guest document number is legitimate where it is redacted on the way into the audit trail; '
            .'this guard makes no claim about that table (C-02 is unresolved).',
        );
    }

    // =====================================================================
    // R4 — no inexact storage (DM-2)
    // =====================================================================

    /**
     * @return array<string, array{string}>
     */
    public static function inexactColumnProvider(): array
    {
        return [
            'float' => ['float'],
            'double' => ['double'],
            'real' => ['real'],
        ];
    }

    #[DataProvider('inexactColumnProvider')]
    public function test_it_rejects_an_inexact_money_column(string $sqlType): void
    {
        $violations = $this->guard()->violationsFor('folio_postings', [
            'id' => 'char(26)',
            'amount' => $sqlType,
        ]);

        $this->assertStringContainsString('DM-2', $this->joined($violations));
    }

    /**
     * The precision itself is still `C-04`. The guard must not enforce a scale it
     * has no authority to choose, so any exact decimal passes.
     */
    public function test_it_accepts_an_exact_decimal_column_at_any_precision(): void
    {
        $this->assertSame(
            [],
            $this->guard()->violationsFor('folio_postings', ['amount' => 'decimal(19,4)']),
            'DECIMAL is exact; the precision remains TBD (C-04) and this guard does not invent it.',
        );
    }

    // =====================================================================
    // R5 — derived, not stored (DATA-MODEL §2.6)
    // =====================================================================

    public function test_it_rejects_a_stored_folio_balance(): void
    {
        $violations = $this->guard()->violationsFor('folio_postings', [
            'id' => 'char(26)',
            'amount' => 'decimal(19,4)',
            'balance' => 'decimal(19,4)',
        ]);

        $this->assertStringContainsString('DERIVED', $this->joined($violations));
    }

    // =====================================================================
    // The command against the real schema
    // =====================================================================

    public function test_the_guard_passes_on_the_migrated_schema(): void
    {
        $exitCode = Artisan::call('zafer:guard-prohibited-schema');
        $output = Artisan::output();

        $this->assertSame(
            0,
            $exitCode,
            "Expected the prohibited-schema guard to PASS on the migrated schema. Output was:\n{$output}",
        );
    }

    public function test_the_guard_inspected_a_real_schema_rather_than_nothing(): void
    {
        $exitCode = Artisan::call('zafer:guard-prohibited-schema');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression(
            '/\d+ table\(s\) inspected/',
            $output,
            'The guard reported success without inspecting anything. A rule over an empty table list '
            .'passes vacuously.',
        );
    }

    // =====================================================================

    private function guard(): GuardProhibitedSchema
    {
        return new GuardProhibitedSchema;
    }

    /**
     * @param  list<string>  $violations
     */
    private function joined(array $violations): string
    {
        return implode("\n", $violations);
    }
}
