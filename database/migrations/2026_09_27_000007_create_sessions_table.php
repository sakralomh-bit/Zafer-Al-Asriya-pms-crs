<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side session state.
 *
 * `config/session.php` already ships `driver => 'database'` as this project's
 * default, and `docs/DATA-MODEL.md` §2 lists `sessions` as a table — but no
 * migration created it, so any request that touched the session failed on a
 * missing table. T-004 needs sessions to exist to implement the documented
 * session controls at all.
 *
 * The columns are the contract Laravel's database session handler reads and
 * writes (`id`, `user_id`, `ip_address`, `user_agent`, `payload`,
 * `last_activity`). Nothing beyond that is added:
 *
 *  - An idle-timeout column would be a TBD value (`SEC-008`), and a column
 *    whose semantics are undecided is worse than no column. Idle and absolute
 *    enforcement is driven by the `last_activity` value already here.
 *  - A lockout-state column belongs with the lockout VALUES, which are TBD and
 *    blocked on `B-05`. Adding an empty `locked_until` now would imply a policy
 *    nobody has approved.
 *
 * Revocation needs no column either: revoking a session means deleting its row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();

            // Nullable: a session row exists before the identity is known, and
            // Laravel's handler writes `null` until login resolves. This is the
            // ONLY relation to the identity table that may be nullable, because
            // "not yet known" is a real state here and not a defect.
            $table->char('user_id', 26)->nullable()->index('sessions_user_id_idx');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index('sessions_last_activity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
