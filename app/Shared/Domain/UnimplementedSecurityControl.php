<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Throwable;

/**
 * A control that the specification requires and the code does not yet provide.
 *
 * ============================ WHY THIS IS NOT A `BUSINESS_RULE_VIOLATION` ============================
 * `API-SPEC.md` §1.7 draws the line this class sits on. A domain failure that is
 * a normal business outcome is a 4xx with a precise code. A 500 means "the system
 * failed in a way it does not understand" and is alerted and counted.
 *
 * A missing capability is neither. It is a known, recorded, and currently
 * correct state of the system — `H-03` and `C-10` are open by decision, not by
 * accident — and it is not a business outcome the caller did anything to cause.
 * `SERVICE_UNAVAILABLE` (503) says exactly that: the capability is not available
 * right now, and the request was not at fault.
 *
 * The alternative is worse in a specific way. A route that throws a
 * `BindingResolutionException` because a dependency has no implementation becomes
 * `INTERNAL_ERROR` (500) through the catch-all handler in `bootstrap/app.php`,
 * and it does so on EVERY call. An endpoint that alerts continuously trains the
 * team to ignore alerts from that endpoint, which is how a real one gets missed.
 * A 503 is a quiet, correct, countable answer.
 *
 * ============================ IT IS NOT A SILENT DEFAULT ============================
 * The class refuses; it does not substitute. Nothing here fills in a missing
 * value, picks an algorithm, or stands in for an unapproved decision. That is the
 * whole reason the project has a `SecurityPolicy` that raises
 * `SecurityPolicyUnresolved` rather than defaulting, and this class is the same
 * idea applied to a missing class rather than a missing number.
 */
final class UnimplementedSecurityControl extends DomainFailure
{
    /**
     * @param  array<int, array{field: string, message: string}>  $details
     */
    public function __construct(
        ErrorCode $code,
        string $message,
        array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($code, $message, $details, $previous);
    }

    /**
     * A capability the specification names and no code implements.
     */
    public static function forCapability(string $message): self
    {
        return new self(ErrorCode::ServiceUnavailable, $message);
    }
}
