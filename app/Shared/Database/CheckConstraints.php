<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Adds MySQL CHECK constraints.
 *
 * Laravel's schema builder has no CHECK API, but several rules in this codebase
 * are not safe to leave as application-level validation, because an
 * application-level check can be raced, bypassed, or forgotten on a code path
 * that was never written yet. A CHECK constraint makes the rule a property of
 * the storage, not of a particular caller.
 *
 * The rules currently expressed this way:
 *
 *  - `legal_entities` — a legal entity cannot be VERIFIED while its commercial
 *    registration or VAT registration numbers are unknown (`B-06`).
 *  - `property_operating_config` — a configuration cannot be ACTIVE while any
 *    `C-05` policy value is unknown. This is what makes an invented operating
 *    default unrepresentable rather than merely discouraged.
 *  - `physical_rooms` — an out-of-order or blocked room must carry a reason.
 *  - `housekeeping_tasks` — a rework or cancellation must carry a reason.
 *
 * MySQL 8.0.16+ enforces CHECK constraints (`D-006`, `ADR-0003`).
 */
final class CheckConstraints
{
    public static function add(string $table, string $constraint, string $expression): void
    {
        self::connection()->statement(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `%s` CHECK (%s)',
            $table,
            $constraint,
            $expression,
        ));
    }

    public static function drop(string $table, string $constraint): void
    {
        try {
            self::connection()->statement(sprintf(
                'ALTER TABLE `%s` DROP CONSTRAINT `%s`',
                $table,
                $constraint,
            ));
        } catch (QueryException) {
            // Dropping a constraint that is not there is not an error during a
            // rollback; the table drop that follows is what actually matters.
        }
    }

    private static function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}
