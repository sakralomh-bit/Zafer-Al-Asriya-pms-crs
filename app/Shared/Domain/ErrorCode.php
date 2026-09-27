<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * The error code registry.
 *
 * Every case here is transcribed from `docs/API-SPEC.md` §2. No code has been
 * invented, and a code is never reused with a different meaning
 * (`ADR-0019`).
 *
 * @see docs/API-SPEC.md §2
 */
enum ErrorCode: string
{
    // §2.1 Authorization / authentication
    case AuthRequired = 'AUTH_REQUIRED';
    case AuthFailed = 'AUTH_FAILED';
    case MfaRequired = 'MFA_REQUIRED';
    case StepUpRequired = 'STEP_UP_REQUIRED';
    case PropertyScopeDenied = 'PROPERTY_SCOPE_DENIED';
    case PermissionDenied = 'PERMISSION_DENIED';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case ImpersonationDenied = 'IMPERSONATION_DENIED';

    // §2.2 Inventory / reservation
    case InventoryUnavailable = 'INVENTORY_UNAVAILABLE';
    case RoomNotSellable = 'ROOM_NOT_SELLABLE';
    case RoomNotOccupiable = 'ROOM_NOT_OCCUPIABLE';
    case RoomStateInvalid = 'ROOM_STATE_INVALID';
    case RoomOccupied = 'ROOM_OCCUPIED';
    case RoomOutOfOrder = 'ROOM_OUT_OF_ORDER';
    case ReservationStateInvalid = 'RESERVATION_STATE_INVALID';
    case ReservationHoldExpired = 'RESERVATION_HOLD_EXPIRED';
    case OutsideBookingWindow = 'OUTSIDE_BOOKING_WINDOW';
    case RestrictionViolation = 'RESTRICTION_VIOLATION';
    case CancellationPolicyViolation = 'CANCELLATION_POLICY_VIOLATION';

    // §2.3 Financial
    case FolioClosed = 'FOLIO_CLOSED';
    case FolioHasUnpostedCharges = 'FOLIO_HAS_UNPOSTED_CHARGES';
    case BusinessDateReopenNotPermitted = 'BUSINESS_DATE_REOPEN_NOT_PERMITTED';
    case PaymentStateInvalid = 'PAYMENT_STATE_INVALID';
    case PaymentNotAuthorized = 'PAYMENT_NOT_AUTHORIZED';
    case PaymentOutcomeUnknown = 'PAYMENT_OUTCOME_UNKNOWN';
    case RefundExceedsPayment = 'REFUND_EXCEEDS_PAYMENT';
    case RefundReasonRequired = 'REFUND_REASON_REQUIRED';
    case RefundSourceRequired = 'REFUND_SOURCE_REQUIRED';
    case RefundNotApproved = 'REFUND_NOT_APPROVED';
    case RefundStateInvalid = 'REFUND_STATE_INVALID';
    case TaxPolicyNotConfigured = 'TAX_POLICY_NOT_CONFIGURED';
    case CurrencyNotSupported = 'CURRENCY_NOT_SUPPORTED';

    // §2.4 Integrity and processing
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case ValidationFailed = 'VALIDATION_FAILED';
    case RateLimited = 'RATE_LIMITED';
    case ConcurrencyConflict = 'CONCURRENCY_CONFLICT';
    case BusinessRuleViolation = 'BUSINESS_RULE_VIOLATION';

    // §2.5 Internal
    case InternalError = 'INTERNAL_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    /**
     * `docs/API-SPEC.md` §1.5: `retryable` is a server assertion about whether the
     * SAME request may safely be re-sent. It is a correctness control, not a
     * convenience. Only a `CONCURRENCY_CONFLICT` is retryable today; a lost
     * allocation race and an unknown payment outcome are explicitly not.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::AuthRequired, self::AuthFailed => 401,
            self::MfaRequired, self::StepUpRequired, self::PropertyScopeDenied,
            self::PermissionDenied, self::AccountSuspended, self::ImpersonationDenied => 403,
            self::InventoryUnavailable, self::RoomNotSellable, self::RoomNotOccupiable,
            self::RoomStateInvalid, self::RoomOccupied, self::RoomOutOfOrder,
            self::ReservationStateInvalid, self::ReservationHoldExpired,
            self::FolioClosed, self::FolioHasUnpostedCharges,
            self::BusinessDateReopenNotPermitted, self::PaymentStateInvalid,
            self::PaymentOutcomeUnknown, self::IdempotencyKeyReused,
            self::ConcurrencyConflict => 409,
            self::ValidationFailed, self::OutsideBookingWindow, self::RestrictionViolation,
            self::CancellationPolicyViolation, self::PaymentNotAuthorized,
            self::RefundExceedsPayment, self::RefundReasonRequired, self::RefundSourceRequired,
            self::RefundNotApproved, self::RefundStateInvalid, self::TaxPolicyNotConfigured,
            self::CurrencyNotSupported, self::BusinessRuleViolation => 422,
            self::RateLimited => 429,
            self::ServiceUnavailable => 503,
            self::InternalError => 500,
        };
    }

    /**
     * Safe to re-send the identical request? True only where re-sending is known
     * to be safe and the client should re-read and try again.
     */
    public function retryable(): bool
    {
        return $this === self::ConcurrencyConflict;
    }
}
