<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Throwable;

/**
 * A documented business rule refused an otherwise-authorized request.
 *
 * This class exists because `DomainFailure` is ABSTRACT, and code that reached
 * for `new DomainFailure(...)` to express "a rule said no" fataled with
 * `Cannot instantiate abstract class`.
 *
 * That is not a cosmetic problem. `ScopeGrantService` used it for the Support
 * time-bound grant rule, which `ADR-0014` §5 makes a security control: a
 * Support grant with no named approver and no expiry must be REFUSED. Instead
 * of a clean `BUSINESS_RULE_VIOLATION` refusal it produced a PHP `Error`, which
 * a caller sees as a `500`. A security control that crashes is not enforcing
 * anything — and a crash is also far harder to notice than a denial, so the
 * failure mode is silent in practice.
 *
 * `DomainFailure` stays abstract so that a failure always names a specific,
 * documented condition: callers can catch `BusinessRuleViolation` and
 * `PermissionDenied` and know which happened, rather than catching a generic
 * base that means nothing.
 */
final class BusinessRuleViolation extends DomainFailure
{
    /**
     * @param array<int, array{field: string, message: string}> $details
     */
    public function __construct(
        ErrorCode $errorCode,
        string $message,
        array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($errorCode, $message, $details, $previous);
    }
}
