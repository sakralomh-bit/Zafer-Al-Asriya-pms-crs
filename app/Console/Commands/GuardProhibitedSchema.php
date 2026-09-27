<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `AC-T-001-04` / `DM-*`: fail the build on schema shapes the data model
 * explicitly REJECTS.
 *
 * `composer guard:no-prohibited-schema` is wired to `zafer:guard-prohibited-schema`
 * and this is that command. It exists because the other three guards inspect
 * source, and the prohibitions below are claims about COLUMNS — a rule that only
 * the live database can answer. `docs/DATA-MODEL.md` §2.8 says the absence of
 * card data "should be structural — the table has no column for them, so the
 * prohibition does not depend on developer discipline at every call site". A
 * structural claim needs a structural check, or it is a comment.
 *
 * Every rule below cites the sentence that creates it. Nothing here is invented:
 * if a rule is not in the documentation, it does not belong in this file.
 *
 *   R1  DATA-MODEL §2.7  `physical_rooms` carries three orthogonal status axes
 *                         and NO unified `status` column.
 *   R2  DM-9, §2.8, §5   No table may hold card data: card_number, cvv, cvc,
 *                         track data, pan. `expiry*` is prohibited on
 *                         payment/card tables.
 *   R3  DATA-MODEL §5    `audit_events` and `outbox_messages` never carry
 *                         identity-document numbers or card data.
 *   R4  DM-2             A monetary column is never FLOAT/DOUBLE/REAL.
 *   R5  DATA-MODEL §2.6  `folio_postings` stores no independently mutable
 *                         balance; the balance is derived (T-009).
 *
 * The rule evaluation is a PURE function of (table, columns) so it can be
 * asserted with synthetic column lists. Asserting it by creating a real table
 * with a `cvv` column and migrating it would be a self-inflicted data hazard
 * for a test whose subject is a string comparison.
 *
 * @see docs/DATA-MODEL.md §2.6, §2.7, §2.8, §5
 * @see docs/ADR/0006-money-representation-bcmath-decimal.md
 */
final class GuardProhibitedSchema extends Command
{
    protected $signature = 'zafer:guard-prohibited-schema';

    protected $description = 'Fail if the migrated schema contains a column shape the data model prohibits.';

    /**
     * R1. The three axes of `docs/STATE-MACHINES.md` §B, as column names.
     *
     * @var list<string>
     */
    private const ROOM_STATUS_AXES = [
        'occupancy_status',
        'housekeeping_status',
        'availability_status',
    ];

    /**
     * R2/R3. Card data that is NEVER STORED (`D-005`, `ADR-0011`,
     * `docs/DATA-MODEL.md` §2.8 and §5).
     *
     * Two lists, because a substring is not always the right granularity, and
     * the first version of this guard proved it: `pan` as a substring rejected
     * `physical_rooms.occupancy_status` and `room_types.max_occupancy`, which
     * are correct columns. A guard that fails on a compliant schema gets
     * switched off, and a guard that is switched off protects nothing.
     *
     *   TOKENS     matched against `_`-separated name parts, so `card_pan`,
     *               `pan`, and `cvv_code` fail while `occupancy` does not.
     *   FRAGMENTS  matched anywhere in the name, for the multi-word forms where
     *               the whole phrase is the concept: `card_number`, `track_data`.
     *
     * @var list<string>
     */
    private const CARD_DATA_TOKENS = [
        'pan',
        'cvv',
        'cvc',
        'cvc2',
        'cardholder',
    ];

    /**
     * @var list<string>
     */
    private const CARD_DATA_FRAGMENTS = [
        'card_number',
        'cardnumber',
        'card_no',
        'cardno',
        'track_data',
        'track1',
        'track2',
        'track_1',
        'track_2',
    ];

    /**
     * R2. A card expiry is meaningless outside a payment context — an offer or a
     * document has a legitimate expiry. So these are only prohibited on tables
     * that are themselves payment/card tables, which is what the documentation
     * scopes them to.
     *
     * @var list<string>
     */
    private const CARD_EXPIRY_FRAGMENTS = [
        'expiry',
        'exp_date',
        'exp_month',
        'exp_year',
    ];

    /**
     * R4. `FLOAT`, `DOUBLE`, and `REAL` (`REAL` is a documented MySQL synonym for
     * `DOUBLE`). Matches what `zafer:guard-money` rejects in migration source;
     * this catches a column that reached the database another way.
     *
     * @var array<string, string> SQL type => how it is described to a developer
     */
    private const INEXACT_SQL_TYPES = [
        'FLOAT' => 'float',
        'DOUBLE' => 'double',
        'REAL' => 'double',
    ];

    /**
     * R2. A table is treated as payment/card-scoped when its own name says so.
     *
     * @var list<string>
     */
    private const PAYMENT_TABLE_MARKERS = [
        'payment',
        'card',
        'refund',
    ];

    /**
     * R3. `docs/DATA-MODEL.md` §5: the audit payload "never contains document
     * numbers, card data, or secrets. Redacted, not raw".
     *
     * @var list<string>
     */
    private const DOCUMENT_NUMBER_FRAGMENTS = [
        'document_number',
        'document_no',
        'national_id',
        'id_number',
        'iqama',
        'passport',
    ];

    /**
     * R3. Tables whose payload is explicitly documented as redacted.
     *
     * @var list<string>
     */
    private const REDACTED_PAYLOAD_TABLES = [
        'audit_events',
        'outbox_messages',
    ];

    /**
     * R5. A separately stored, independently mutable balance is a drift source
     * and is excluded by design. The table is `T-009`; the rule is stated now so
     * the model is not retrofitted around it.
     *
     * @var array<string, list<string>>
     */
    private const DERIVED_ONLY_COLUMNS = [
        'folio_postings' => ['balance', 'running_balance', 'current_balance'],
    ];

    public function handle(): int
    {
        try {
            $schema = $this->readSchema();
        } catch (Throwable $exception) {
            $this->error('The schema could not be read: '.$exception->getMessage());
            $this->error('This guard asserts claims about COLUMNS, so it needs a migrated database.');
            $this->error('Run `php artisan migrate` first, or let the test suite migrate it.');

            return self::FAILURE;
        }

        if ($schema === []) {
            $this->error('The database has no tables. Run `php artisan migrate` before this guard.');

            return self::FAILURE;
        }

        $violations = [];

        foreach ($schema as $table => $columns) {
            foreach ($this->violationsFor($table, $columns) as $violation) {
                $violations[] = $violation;
            }
        }

        if ($violations !== []) {
            $this->error(sprintf('Prohibited-schema guard failed with %d violation(s):', count($violations)));
            foreach ($violations as $violation) {
                $this->line('  - '.$violation);
            }
            $this->error('See docs/DATA-MODEL.md. A prohibited column is a defect, not a configuration choice.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'prohibited-schema guard: %d table(s) inspected, no prohibited column shape found.',
            count($schema),
        ));

        return self::SUCCESS;
    }

    /**
     * The rule set, as a pure function of a table name and its columns.
     *
     * `$columns` maps a column name to its SQL type (`char(26)`, `varchar(255)`,
     * `decimal(19,4)`), because R4 needs the type. A plain list of names is also
     * accepted for the rules that only care about names.
     *
     * @param  array<string, string>  $columns  column name => SQL type
     * @return list<string>
     */
    public function violationsFor(string $table, array $columns): array
    {
        $violations = [];
        $names = array_map(strtolower(...), array_keys($columns));

        foreach ($this->roomStatusAxisViolations($table, $names) as $violation) {
            $violations[] = $violation;
        }

        foreach ($this->cardDataViolations($table, $names) as $violation) {
            $violations[] = $violation;
        }

        foreach ($this->derivedOnlyViolations($table, $names) as $violation) {
            $violations[] = $violation;
        }

        foreach ($this->inexactTypeViolations($table, $columns) as $violation) {
            $violations[] = $violation;
        }

        return $violations;
    }

    /**
     * R1 — `docs/DATA-MODEL.md` §2.7.
     *
     * A single `status` column on a room is REJECTED by the documentation, not
     * merely discouraged: it "produces the contradiction 'occupied and clean'".
     * All three axes are required, not optional, because a room that is missing
     * an axis cannot answer "can this room be sold right now" at all.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function roomStatusAxisViolations(string $table, array $names): array
    {
        if ($table !== 'physical_rooms') {
            return [];
        }

        $violations = [];

        foreach (self::ROOM_STATUS_AXES as $axis) {
            if (! in_array($axis, $names, true)) {
                $violations[] = sprintf(
                    'physical_rooms has no [%s] column; §2.7 defines three orthogonal status axes.',
                    $axis,
                );
            }
        }

        if (in_array('status', $names, true)) {
            $violations[] = 'physical_rooms carries a unified [status] column; §2.7 rejects it in favour of '
                .'occupancy_status, housekeeping_status, and availability_status.';
        }

        return $violations;
    }

    /**
     * R2 and R3.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function cardDataViolations(string $table, array $names): array
    {
        $violations = [];
        $isPaymentTable = $this->isPaymentTable($table);
        $isRedactedPayload = in_array($table, self::REDACTED_PAYLOAD_TABLES, true);

        foreach ($names as $name) {
            if ($this->matchesAnyToken($name, self::CARD_DATA_TOKENS)
                || $this->matchesAnyFragment($name, self::CARD_DATA_FRAGMENTS)) {
                $violations[] = sprintf(
                    '%s carries [%s]; DM-9 and §2.8 state card data is NEVER STORED and no column '
                    .'should exist that could hold it.',
                    $table,
                    $name,
                );

                continue;
            }

            if ($isPaymentTable) {
                foreach (self::CARD_EXPIRY_FRAGMENTS as $fragment) {
                    if (str_contains($name, $fragment)) {
                        $violations[] = sprintf(
                            '%s carries [%s]; §2.8 never models a card expiry — a payment reference is a '
                            .'token, not a card.',
                            $table,
                            $name,
                        );

                        continue;
                    }
                }
            }

            if ($isRedactedPayload) {
                foreach (self::DOCUMENT_NUMBER_FRAGMENTS as $fragment) {
                    if ($this->matchesAnyToken($name, [$fragment]) || $this->matchesAnyFragment($name, [$fragment])) {
                        $violations[] = sprintf(
                            '%s carries [%s]; §5 states this payload never contains document numbers. '
                            .'Redacted, not raw.',
                            $table,
                            $name,
                        );

                        continue;
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * Whole-token match on the `_`-separated parts of a column name.
     *
     * @param  list<string>  $tokens
     */
    private function matchesAnyToken(string $name, array $tokens): bool
    {
        $parts = explode('_', $name);

        foreach ($tokens as $token) {
            if (in_array($token, $parts, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $fragments
     */
    private function matchesAnyFragment(string $name, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * R5.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function derivedOnlyViolations(string $table, array $names): array
    {
        $prohibited = self::DERIVED_ONLY_COLUMNS[$table] ?? null;

        if ($prohibited === null) {
            return [];
        }

        $violations = [];

        foreach ($names as $name) {
            if (in_array($name, $prohibited, true)) {
                $violations[] = sprintf(
                    '%s carries [%s]; the folio balance is DERIVED from the append-only postings and a '
                    .'stored mutable balance is excluded by design.',
                    $table,
                    $name,
                );
            }
        }

        return $violations;
    }

    /**
     * R4 — `DM-2`. Matched on the DECLARED type, never on the column name, so a
     * column called `amount` written as `decimal(19,4)` is the point rather than
     * the offence.
     *
     * @param  array<string, string>  $columns
     * @return list<string>
     */
    private function inexactTypeViolations(string $table, array $columns): array
    {
        $violations = [];

        foreach ($columns as $name => $type) {
            $type = strtolower(trim($type));

            if (str_contains($type, 'decimal') || str_contains($type, 'char') || str_contains($type, 'int')) {
                continue;
            }

            $inexact = self::INEXACT_SQL_TYPES[strtoupper(strtok($type, '(') ?: $type)] ?? null;

            if ($inexact === null) {
                continue;
            }

            $violations[] = sprintf(
                '%s.%s is declared %s; DM-2 requires exact DECIMAL with explicit precision and scale. '
                .'The precise precision and scale are TBD (C-04) and must not be invented.',
                $table,
                $name,
                $inexact,
            );
        }

        return $violations;
    }

    private function isPaymentTable(string $table): bool
    {
        foreach (self::PAYMENT_TABLE_MARKERS as $marker) {
            if (str_contains($table, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole schema in one query, scoped to the CONNECTED database.
     *
     * `information_schema` is queried directly, with `table_schema = DATABASE()`,
     * for two reasons:
     *
     *   1. Scope. `Schema::getTableListing()` was observed returning table names
     *      for a schema other than the one the connection was using, which made
     *      this guard fail with `Table 'zafer_pms.audit_events' doesn't exist` on
     *      a genuinely empty development database. A guard must report the
     *      database it inspected.
     *   2. Cost. One round trip instead of `SHOW COLUMNS` per table, which
     *      matters because CI runs this on every build.
     *
     * `DATABASE()` returns the database the current connection is actually
     * using, so `.env` and the test configuration are each inspected correctly
     * without this command knowing anything about either.
     *
     * @return array<string, array<string, string>> table name => [column name => SQL type]
     */
    private function readSchema(): array
    {
        $rows = DB::select(
            'SELECT table_name, column_name, column_type FROM information_schema.columns '
            .'WHERE table_schema = DATABASE() ORDER BY table_name, ordinal_position',
        );

        $schema = [];

        foreach ($rows as $row) {
            // MySQL returns `information_schema` column labels in upper case on
            // this server, so the row is normalised rather than guessed at.
            $row = array_change_key_case((array) $row, CASE_LOWER);

            $schema[(string) $row['table_name']][(string) $row['column_name']] = (string) $row['column_type'];
        }

        ksort($schema);

        return $schema;
    }
}
