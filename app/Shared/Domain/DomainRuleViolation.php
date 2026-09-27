<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * A named business rule was not satisfied.
 *
 * `BUSINESS_RULE_VIOLATION` (422) is the generic carrier documented in
 * `docs/API-SPEC.md` §2.4. It is used only where the specification does not
 * define a dedicated code; a dedicated code always wins.
 */
final class DomainRuleViolation extends DomainFailure
{
    /**
     * @param array<int, array{field: string, message: string}> $details
     */
    public static function businessRuleViolation(string $code, string $message, array $details = []): self
    {
        return new self(ErrorCode::BusinessRuleViolation, $message, $details);
    }

    /**
     * @param array<int, array{field: string, message: string}> $details
     */
    public static function validationFailed(string $field, string $message, array $details = []): self
    {
        /** @var array<int, array{field: string, message: string}> $allDetails */
        $allDetails = array_merge([['field' => $field, 'message' => $message]], $details);

        return new self(ErrorCode::ValidationFailed, $message, $allDetails);
    }
}
