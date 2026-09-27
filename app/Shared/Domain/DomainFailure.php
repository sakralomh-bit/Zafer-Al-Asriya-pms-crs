<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use RuntimeException;
use Throwable;

/**
 * Base for every expected, business-level failure.
 *
 * A domain failure that is a normal business outcome is a `4xx` with a precise
 * code, never a `500` (`docs/API-SPEC.md` §1.7). This hierarchy is what keeps
 * that discipline mechanical: anything thrown as a `DomainFailure` is mapped to
 * a documented code; anything else is an unhandled defect and becomes
 * `INTERNAL_ERROR` with no detail exposed.
 */
abstract class DomainFailure extends RuntimeException
{
    /**
     * @param  array<int, array{field: string, message: string}>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode->httpStatus(), $previous);
    }

    public function retryable(): bool
    {
        return $this->errorCode->retryable();
    }
}
