<?php

declare(strict_types=1);

namespace App\Modules\Identity\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Revokes a user's live sessions when what they are allowed to do changes.
 *
 * `AC-T-004-04`: "A session is revoked when the user's role or property scope
 * changes, without waiting for expiry." `SEC-008` lists revocation as one of
 * the three session controls, and `docs/DATA-MODEL.md` §2 records the
 * `sessions` table as carrying revocation state.
 *
 * Why this is not merely a cache clear: `PropertyScopeResolver` already
 * forgets a user's grants in memory the moment they change, so the NEXT request
 * is denied. But a session that is still valid is still an authenticated
 * session — it still carries an actor, still satisfies any endpoint that only
 * asks "is this authenticated", and still reaches code that has not been given
 * a scope to check. Revoking the session closes that gap rather than narrowing
 * it, and it is the difference between "the next check fails" and "there is no
 * next check, because there is no session".
 *
 * THE DRIVER IS CHECKED. Revocation here deletes rows from the `sessions`
 * table. If the session driver were `file`, `cookie`, `redis`, or anything
 * else, that delete would affect nothing while appearing to succeed — the
 * exact silent-failure mode `AC-T-004-04` exists to prevent. So an unsupported
 * driver raises a `SERVICE_UNAVAILABLE` rather than returning quietly. Anyone
 * changing `SESSION_DRIVER` will find out on the first revocation.
 */
final class SessionRevoker
{
    /**
     * Revoke every live session for a user.
     *
     * WIRED FOR PROPERTY SCOPE, NOT FOR ROLE ASSIGNMENT. `ScopeGrantService`
     * calls this from both `grant()` and `revoke()` after the audit row is
     * written. An earlier revision of this docblock claimed nothing in `app/`
     * called this at all; that was wrong, and `docs/TASKS.md` §13.1.4 records
     * the correction. The role half of `AC-T-004-04` is still unwired: there is
     * no role-assignment service in the repository to call it from, only the
     * `User::roles()` relation, so `AC-T-004-04` is PARTIAL — met for property
     * scope, deferred for roles.
     *
     * Callers must invoke this AFTER the change is committed in memory, so the
     * very next request is both unscoped and unsessioned.
     */
    public function revokeAllFor(string $userId): int
    {
        $this->assertDatabaseDriver();

        $revoked = DB::table('sessions')->where('user_id', $userId)->delete();

        if ($revoked > 0) {
            // The audit trail already records WHY access changed (SCOPE_GRANTED,
            // SCOPE_REVOKED, ROLE_ASSIGNED). This line records the SECOND
            // consequence — that live sessions were cut — which a reviewer
            // asking "was the removed employee's access still usable?" cannot
            // otherwise answer. It carries a user id and a count and nothing
            // else.
            Log::info('auth.sessions_revoked', [
                'user_id' => $userId,
                'sessions_revoked' => $revoked,
            ]);
        }

        return $revoked;
    }

    /**
     * @throws SessionRevocationUnsupported when the driver cannot be revoked
     */
    private function assertDatabaseDriver(): void
    {
        $driver = config('session.driver');

        if ($driver === 'database') {
            return;
        }

        throw SessionRevocationUnsupported::forDriver((string) $driver);
    }
}
