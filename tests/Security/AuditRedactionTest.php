<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Shared\Audit\AuditRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `ADR-0016` §8: document numbers, card data, and secrets must never appear in
 * an audit payload.
 *
 * Redaction has two failure directions and they are not symmetric:
 *
 *  - TOO LITTLE redaction leaks a secret into an append-only table that anyone
 *    with audit read access can query forever. That is the direction the rule is
 *    written about.
 *  - TOO MUCH redaction is silent. It produces no error, no exception, and no
 *    gap in the record count — just a payload full of placeholders. Nothing
 *    alerts, and a test that only counts rows still passes.
 *
 * This repository had the second failure and it was invisible:
 *
 *     str_contains('occupancy', 'pan')  ===  true
 *
 * `isSensitiveKey()` matched the card-PAN entry as a SUBSTRING, so every room
 * occupancy change in the audit trail — reserve, check-in, check-out, release —
 * was stored as `occupancy: [REDACTED]`. `AC-T-005-06` was failing on the
 * content of every occupancy audit row, with nothing reporting it.
 *
 * The "must NOT be redacted" tests below are as important as the "must be
 * redacted" ones. A redaction rule that redacts everything passes every leak
 * test forever.
 */
final class AuditRedactionTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function sensitiveKeys(): array
    {
        return [
            ['document_number'],
            ['national_id'],
            ['iqama_number'],
            ['passport_number'],
            ['card_number'],
            ['pan'],
        ];
    }

    /**
     * Keys that merely CONTAIN a sensitive word but are not sensitive. This is
     * the regression set: each of these was being destroyed by substring
     * matching.
     *
     * @return list<array{0: string}>
     */
    public static function innocentKeysContainingASensitiveWord(): array
    {
        return [
            ['occupancy'],   // contains "pan"
            ['company'],     // contains "pan"
            ['span'],        // contains "pan"
            ['passenger'],   // contains "pass" (near "passport")
            ['expanded'],    // contains "pan"
            ['nationality'], // near "national_id"
        ];
    }

    #[DataProvider('sensitiveKeys')]
    public function test_a_sensitive_key_is_redacted(string $key): void
    {
        $redacted = AuditRecord::redact([$key => 'SENSITIVE-VALUE']);

        $this->assertSame(
            AuditRecord::REDACTION_PLACEHOLDER,
            $redacted[$key],
            "[{$key}] must be redacted before it reaches the audit trail.",
        );

        $this->assertStringNotContainsString(
            'SENSITIVE-VALUE',
            json_encode($redacted, JSON_THROW_ON_ERROR),
            "[{$key}] leaked its value into the redacted payload.",
        );
    }

    /**
     * The regression that motivated this suite.
     */
    #[DataProvider('innocentKeysContainingASensitiveWord')]
    public function test_an_innocent_key_is_not_redacted(string $key): void
    {
        $redacted = AuditRecord::redact([$key => 'PUBLIC-VALUE']);

        $this->assertSame(
            'PUBLIC-VALUE',
            $redacted[$key],
            "[{$key}] contains a sensitive word as a SUBSTRING but is not itself sensitive. "
            . 'Redacting it destroys real audit data silently.',
        );
    }

    public function test_room_status_audit_payload_survives_redaction(): void
    {
        // The exact shape `RoomStatusService::transition()` records. Before the
        // segment-matching fix every one of these axes was written as
        // [REDACTED] for the occupancy row.
        $payload = AuditRecord::redact([
            'occupancy' => 'VACANT',
            'housekeeping' => 'INSPECTED',
            'availability' => 'SELLABLE',
        ]);

        $this->assertSame(
            [
                'occupancy' => 'VACANT',
                'housekeeping' => 'INSPECTED',
                'availability' => 'SELLABLE',
            ],
            $payload,
            'Room status axes must survive audit redaction intact.',
        );
    }

    public function test_redaction_recurses_into_nested_payloads(): void
    {
        $redacted = AuditRecord::redact([
            'guest' => [
                'name' => 'SYNTHETIC NAME',
                'document_number' => 'SENSITIVE-VALUE',
            ],
        ]);

        $this->assertSame('SYNTHETIC NAME', $redacted['guest']['name']);
        $this->assertSame(
            AuditRecord::REDACTION_PLACEHOLDER,
            $redacted['guest']['document_number'],
            'A nested sensitive key must be redacted too.',
        );
    }

    public function test_compound_sensitive_keys_are_still_caught(): void
    {
        foreach (['card_pan', 'guest_card_number', 'primary-document_number'] as $key) {
            $redacted = AuditRecord::redact([$key => 'SENSITIVE-VALUE']);

            $this->assertSame(
                AuditRecord::REDACTION_PLACEHOLDER,
                $redacted[$key],
                "[{$key}] must be redacted: its segments include a sensitive word.",
            );
        }
    }

    public function test_camel_case_sensitive_keys_are_caught(): void
    {
        $redacted = AuditRecord::redact(['cardNumber' => 'SENSITIVE-VALUE']);

        $this->assertSame(AuditRecord::REDACTION_PLACEHOLDER, $redacted['cardNumber']);
    }
}
