<?php

declare(strict_types=1);

namespace App\Shared\Audit;

/**
 * The record shape required by `ADR-0016` §2 and `Prd_Maker.md` §32:
 * who, what, when, where, before, after, reason, correlation ID, source, result.
 *
 * This is the single construction path for an audit event. A caller cannot
 * invent a field or omit one, and `before`/`after` payloads are filtered through
 * the redaction list before they are stored.
 */
final class AuditRecord
{
    /**
     * `ADR-0016` §8: document numbers, card data, and secrets must never appear
     * in an audit payload. The audit trail records THAT a sensitive field was
     * revealed and by whom — never the value.
     *
     * @var list<string>
     */
    public const REDACTED_KEYS = [
        'document_number',
        'national_id',
        'iqama_number',
        'passport_number',
        'card_number',
        'pan',
        'cvv',
        'track_data',
        'password',
        'secret',
        'token',
        'api_key',
        'authorization',
    ];

    public const REDACTION_PLACEHOLDER = '[REDACTED]';

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $additionalContext
     */
    private function __construct(
        public readonly AuditAction $action,
        public readonly ?string $actorUserId,
        public readonly ?string $actorRole,
        public readonly ?string $propertyId,
        public readonly ?string $subjectType,
        public readonly ?string $subjectId,
        public readonly ?string $reason,
        public readonly ?string $correlationId,
        public readonly string $source,
        public readonly string $result,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly array $additionalContext,
    ) {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $additionalContext
     */
    public static function of(
        AuditAction $action,
        ?string $actorUserId,
        ?string $actorRole,
        ?string $propertyId,
        ?string $subjectType,
        ?string $subjectId,
        ?string $source,
        ?string $correlationId,
        ?string $reason = null,
        ?array $before = null,
        ?array $after = null,
        array $additionalContext = [],
        string $result = 'SUCCESS',
    ): self {
        return new self(
            action: $action,
            actorUserId: $actorUserId,
            actorRole: $actorRole,
            propertyId: $propertyId,
            subjectType: $subjectType,
            subjectId: $subjectId,
            reason: $reason,
            correlationId: $correlationId,
            source: $source,
            result: $result,
            before: $before === null ? null : self::redact($before),
            after: $after === null ? null : self::redact($after),
            additionalContext: self::redact($additionalContext),
        );
    }

    /**
     * Recursively replace the value of any sensitive key with a placeholder.
     *
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $clean[$key] = self::REDACTION_PLACEHOLDER;

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value);

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Match a key against the sensitive list by SEGMENT, not by substring.
     *
     * The previous implementation used `str_contains`, which is wrong in a way
     * that silently destroys audit data rather than merely over-redacting:
     *
     *     str_contains('occupancy', 'pan')  ===  true
     *
     * Every room occupancy change — reserve, check-in, check-out, release — was
     * therefore written to the audit trail as `occupancy: [REDACTED]`. The
     * redaction worked exactly as designed and destroyed the single most
     * important axis in the PMS, with no error anywhere: redaction is supposed
     * to be invisible, so nothing reported it. `AC-T-005-06` ("every status
     * change is audited") was failing on the content of every occupancy audit
     * row while appearing to pass.
     *
     * A sensitive key is a whole word or an underscore/hyphen-separated segment
     * (`pan`, `card_pan`, `pan-number`), which is what the column names in
     * `DATA-MODEL.md` actually look like. `occupancy`, `company`, and `span` all
     * contain `pan` and none of them is a card number.
     */
    private static function isSensitiveKey(string $key): bool
    {
        // camelCase boundaries must be found BEFORE lowercasing. Lowercasing
        // first turns `cardNumber` into `cardnumber`, and no separator survives
        // to split on — so `cardNumber` sailed past as an ordinary word while
        // `card_number` was correctly caught.
        $segments = preg_split(
            '/[^a-zA-Z0-9]+|(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/',
            $key,
        ) ?: [$key];

        $segments = array_map(strtolower(...), $segments);

        foreach (self::REDACTED_KEYS as $sensitive) {
            $wanted = preg_split('/[^a-z0-9]+/', strtolower($sensitive)) ?: [$sensitive];

            if (self::containsSegmentRun($segments, $wanted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when `$wanted` appears in `$segments` as a CONTIGUOUS run.
     *
     * Contiguous-run matching is what makes compound entries work in both
     * directions:
     *
     *   wanted `card_number` vs segments `guest,card,number`  -> REDACTED
     *   wanted `pan`         vs segments `oc,cu,pan,cy`        -> not redacted
     *
     * Whole-segment equality alone would miss `guest_card_number`, and substring
     * matching would catch it only by also destroying `occupancy`. Matching a
     * run of whole segments is the only rule that does both jobs at once.
     *
     * @param list<string> $segments
     * @param list<string> $wanted
     */
    private static function containsSegmentRun(array $segments, array $wanted): bool
    {
        $segmentCount = count($segments);
        $wantedCount = count($wanted);

        if ($wantedCount === 0 || $wantedCount > $segmentCount) {
            return false;
        }

        for ($offset = 0; $offset <= $segmentCount - $wantedCount; $offset++) {
            $matches = true;

            for ($i = 0; $i < $wantedCount; $i++) {
                if ($segments[$offset + $i] !== $wanted[$i]) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
