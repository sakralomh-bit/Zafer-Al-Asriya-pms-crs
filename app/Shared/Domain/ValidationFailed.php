<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Throwable;

/**
 * A field-level validation refusal, carrying the offending field and a message.
 *
 * Separate from `BusinessRuleViolation` so a caller — an HTTP layer building a
 * `422` response, or a Vue form mapping errors onto inputs — can tell "you sent
 * something malformed" apart from "your request was well-formed and a rule said
 * no". Collapsing the two would make every form render business-rule prose as
 * if it were a field error.
 *
 * As with `BusinessRuleViolation`, this class exists because `DomainFailure` is
 * abstract and `new DomainFailure(...)` fatals. Every site that needed a
 * "reject the input" failure was silently broken until this existed.
 */
final class ValidationFailed extends DomainFailure
{
    /**
     * @param  array<int, array{field: string, message: string}>  $details
     */
    public function __construct(
        string $message,
        array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(ErrorCode::ValidationFailed, $message, $details, $previous);
    }

    /**
     * The common case: exactly one field, one message.
     */
    public static function field(string $field, string $message): self
    {
        return new self($message, [['field' => $field, 'message' => $message]]);
    }

    /**
     * A required field that was absent, blank, or not the expected type.
     *
     * ONE message for all three cases, and the message does not name the expected
     * type. A client that sends `password` as an array and one that omits it get
     * byte-identical answers: an error that distinguished them would be a
     * description of this handler's internals, which `API-SPEC.md` §1.6 prohibits
     * for internal class and shape detail.
     */
    public static function missingField(string $field): self
    {
        return self::field($field, 'This field is required.');
    }
}
