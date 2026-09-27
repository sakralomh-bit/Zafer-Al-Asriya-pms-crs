# ADR-0011: Payment Provider Abstraction and Card-Data Boundary

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-005`, `BUS-003`, `BUS-013`, `ADR-0005`, `ADR-0009`, `ADR-0017`, `docs/STATE-MACHINES.md` §D, §E

## Context

`D-005` fixes the Phase A payment scope: cash, provider-hosted/tokenised terminal card payments, deposits, pre-authorisations, captures, refunds, voids/reversals where supported, payment reconciliation, payment failure handling, and idempotent payment operations. Guest-facing online card capture is deferred to Phase B, behind the **same** provider abstraction.

The card-data boundary is absolute: the PMS MUST NEVER store a PAN, CVV/CVC, magnetic-stripe data, or a full card number in any store, log, analytics system, or telemetry. Only provider-safe references may be stored.

No payment provider has been selected (`B-04`). Per `Prd_Maker.md` §71, its API, endpoints, capabilities, sandbox, and error semantics are **`UNKNOWN` and must not be invented**.

## Decision

### 1. System of record split

| Datum | System of record |
|---|---|
| The actual card transaction — authorization, capture, settlement, chargeback | **The payment provider** |
| Folio balances, charges, payment records, deposits, refund records, allocation of payments to folios, reconciliation status | **The PMS** |

This is a real operational consequence: a dispute about whether a card was actually charged is settled with the **provider**. The PMS's record is that a payment was *recorded*, with a provider reference sufficient to settle it. The system must never present its own record as proof of a card transaction.

### 2. The card-data boundary is structural, not procedural

| Never stored | Permitted |
|---|---|
| PAN / card number | Provider token, where applicable |
| CVV / CVC | Authorization / reference ID |
| Magnetic-stripe track data | Transaction ID |
| Full card number in logs, analytics, or telemetry | Payment status |
| | Amount, currency |
| | Provider response / result code |
| | Timestamps |
| | Related folio and payment identifiers |

**The prohibition is enforced structurally: there is no column for a PAN, CVV, or track data anywhere in the schema.** This matters more than it appears. A procedural rule ("don't write card data to this field") depends on every developer, at every call site, forever. A missing column makes the violation impossible rather than merely discouraged — and it means the next developer who adds a `card_number` column is making a visible, reviewable schema change rather than an invisible one.

### 3. Provider-agnostic adapter

A `PaymentProviderAdapter` interface, owned by the Payments module, supporting:

authentication · per-operation timeouts · bounded retries with backoff and jitter · idempotency keys · duplicate-request protection · provider transaction references · webhook/event handling · **signature verification where the provider supports it** · reconciliation · explicit failure states · manual recovery and retry · audit logging.

**No provider SDK type may appear in the financial domain.** The domain depends on the interface only (`ADR-0017`). This is what makes `B-04` a procurement dependency rather than an architectural one, and it is what allows Phase B to add online capture without redesigning the financial domain (`D-005`).

### 4. The `UNKNOWN_OUTCOME` state is mandatory

`Prd_Maker.md` §33: *"Never retry blindly after an unknown payment or financial outcome."*

If a payment request is sent and the result is not confirmed, the system does **not** know whether the money moved. The correct behaviour:

1. Record `UNKNOWN_OUTCOME` with the idempotency key and any provider reference.
2. **Do not retry.** A retry is the most likely way to turn an unknown outcome into a duplicate charge.
3. Do not treat the folio as paid or unpaid.
4. Raise a reconciliation task against the provider.
5. Return `PAYMENT_OUTCOME_UNKNOWN` with **`retryable: false`** to the operator, so a client cannot naively retry.
6. Resolve only via reconciliation, or unwind via a refund if the provider confirms a capture that must be reversed.

Omitting this state is the most damaging single omission available in a payment machine, and it is invisible in ordinary review because the happy path looks complete without it.

### 5. Idempotency on every money operation

Every payment, capture, refund, and void carries an `Idempotency-Key`. A repeat returns the **original** result and creates no second financial effect. This is the control that makes safe retry possible where retry *is* appropriate — a distinction the `UNKNOWN_OUTCOME` handling depends on: retry with a known outcome and a key is safe; retry without a key is not; retry after an unknown outcome is not safe at all.

### 6. Phase A scope

Cash · terminal card via provider-hosted/tokenised flow · deposits · pre-authorisations · captures · refunds · voids/reversals where supported · reconciliation · failure handling · idempotent operations.

**Deferred to Phase B:** guest-facing online card capture, behind the same adapter interface. The financial domain must not require redesign for it.

### 7. Provider selection requirements

`D-005` requires a provider that supplies: a sandbox/test environment · a documented API · terminal integration capability · deposit/pre-authorisation support where required · refund/void capability · appropriate security and compliance documentation.

**All provider specifics are `UNKNOWN` (`B-04`) and must not be invented.** The adapter interface is designed so that answering these questions plugs into it without change.

## Criteria Applied

Security (the highest-consequence data category is excluded by design), correctness (the unknown-outcome path), reversibility (the provider is replaceable), compliance (PCI scope is minimized — but **not** claimed), testability (a fake provider makes the full failure surface testable), operational simplicity (a single abstraction, not provider-specific logic scattered through finance).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Online capture in Phase A | Deferred to Phase B | Requires a contracted provider and certification up front; broadens PCI and gateway risk before the PMS core is proven (`D-005`) |
| Manual card keying recorded in the PMS | Rejected as the primary path | Keyed card entry is a fraud pattern and a direct path to card data entering the system. Noted as a **possible contingency only** if a provider cannot be contracted, and if used, it requires explicit approval and compensating controls. |
| A specific gateway hard-coded | **Rejected** | `B-04`; provider unselected. Also the wrong architecture regardless. |
| Storing a tokenized PAN for repeat charges | Rejected | Tokenization scope is a provider contract matter; storing a PAN-equivalent expands the sensitive-data footprint for a Phase A requirement (terminal payment) that does not need it. Revisit if recurring charges are required. |
| Treating a successful HTTP response as proof of payment | **Rejected** | An HTTP 200 is not a settlement confirmation. The provider's record governs; reconciliation confirms. |
| Automatic retry on any payment failure | **Rejected** | A blind retry of an unknown outcome produces a duplicate charge — the exact harm this design exists to prevent. |
| Storing the card for chargeback convenience | **Rejected** | Convenience for a rare event does not justify holding the highest-risk data category permanently. |
| Claiming PCI compliance from tokenization | **Rejected** | `Prd_Maker.md` §27: *"Do not claim PCI DSS compliance solely because tokenization is used."* |

## PCI position — stated precisely

This system **reduces** its PCI scope by never storing card data and by using provider-hosted/tokenised flows. **That is not a compliance claim.** Actual PCI DSS scope depends on the final implemented architecture, the provider relationship and contract, the environments involved, the compensating controls in place, and the assessment requirements applicable to the organization. Those are determined by the payment provider's qualified security assessment and the acquirer/bank relationship, and by a qualified assessor — not by this ADR and not by engineering.

**No PCI DSS compliance is claimed anywhere in this document set.**

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A card number reaches a log via a provider error payload | Medium | **Critical** | Error payload redaction; automated log scan (`ADR-0021` §6); no provider payload logged verbatim |
| A developer adds a card column to the schema | Low | **Critical** | No such column exists; schema review; static check |
| An unknown outcome is treated as a failure and retried | Medium | **Critical** | `UNKNOWN_OUTCOME` state; `retryable: false`; reconciliation-only resolution |
| Duplicate charge from a client retry without a key | Medium | High | `Idempotency-Key` required; duplicate returns the original result |
| A webhook is forged or replayed | Medium | High | Signature verification, timestamp tolerance, replay protection — **mechanism `UNKNOWN`** pending provider (`SEC-013`) |
| Provider unavailability blocks checkout | Low | High | `BUS-008`: cash path remains; payment failure handled as a domain outcome, not a system error |
| Pre-authorisation expiry is mishandled | Medium | High | Provider-specific rules `UNKNOWN`; state machine designed to hold `AUTHORIZED` explicitly |
| Reconciliation gap between provider settlement and PMS records | Medium | High | Reconciliation tasks; mismatch alerting; never auto-adjusted |

## Reversibility

**High for the provider, impossible for the harm.** The adapter is replaceable and Phase B can be added without redesign. A duplicate charge, however, is a real financial event: it is corrected with a refund and an audit entry, never erased. That asymmetry — cheap to change the provider, expensive to change a charge that reached a guest — is the reason the unknown-outcome path is specified so carefully.

## References

`D-005`, `BUS-002`, `BUS-003`, `BUS-013`, `BUS-016`, `ADR-0005`, `ADR-0009`, `ADR-0017`, `ADR-0019`, `ADR-0021`, `Prd_Maker.md` §24, §26, §27, §33, §55, §71, `B-04`, `docs/STATE-MACHINES.md` §D, §E, `docs/DATA-MODEL.md` §2.8, `docs/SECURITY.md` §3.
