# ADR-0009: Financial Ledger and Folio Model

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-003`, `D-005`, `BUS-004`, `BUS-005`, `ADR-0006`, `ADR-0010`, `ADR-0018`, `docs/DATA-MODEL.md` §4

## Context

`Prd_Maker.md` §28 requires, for any product that creates charges or balances, a defined model of account/folio, posting, debit, credit, tax, discount, adjustment, reversal, payment, refund, transfer, and settlement — and requires every financial mutation to be traceable, immutable where required, attributable, timestamped, and linked to its source document or event. It states: *"Avoid destructive updates to posted financial records. Use compensating entries where required."*

A hotel folio is the record a dispute is settled from. A guest who was charged twice, a night that was not refunded after a cancellation, a tax invoice that cannot be reconciled to the charges behind it — each is a real financial harm to either the guest or the hotel. In a group of 10 properties, these are not edge cases; they are the normal weekly workload of finance staff.

The model must also survive **ZATCA obligations** (`D-003`): an invoice is a regulated document, and the records behind it must remain reconstructable for as long as the law requires, which engineering cannot shorten for convenience.

## Decision

### 1. Append-only ledger

Posted financial records are **never** updated and **never** deleted. Corrections are new compensating entries that reference the original. There is no application code path that issues an `UPDATE` or a destructive `DELETE` against a posted record.

### 2. Attribution and linkage

Every posting carries: an immutable reference, the folio, a linked source document or event, an account/category, a signed amount in an explicit currency, an explicit **business date** separate from the creation timestamp, the actor (human or system), the correlation ID, and a posting type.

"Linked to its source document or event" is what makes an invoice traceable to the charges behind it (`AC-FR-008-03`) and a refund traceable to the payment it reverses.

### 3. The balance is derived, never independently mutable

The folio balance is computed from its postings. A separately stored, independently mutable balance is excluded by design, because two things that can be updated independently **will** diverge, and the divergence is discovered during reconciliation — at the worst possible time.

If a cached balance is needed for list-screen performance, it is a **derived projection**: rebuildable, never the source of truth, and verified against the ledger by a scheduled integrity check.

### 4. Corrections are compensating entries

| Situation | Treatment |
|---|---|
| Wrong charge posted | Reversal entry + corrected posting, both linked to the original |
| Guest overcharged and refunded | Original charge retained; refund posted as a separate financial fact |
| Rate adjusted after posting | Adjustment entry referencing the original; original untouched |
| Night audit re-run | Must not re-post. Night audit is **resumable, not restartable** (`ADR-0018`) |
| Bad configuration discovered | Affected postings are identified and corrected individually; the configuration is fixed separately |

An issued invoice is corrected by a **credit note or debit note**, never by editing the invoice (`ADR-0010`).

### 5. Sign convention

A single documented convention applies uniformly: positive = debit to the hotel (a charge), negative = credit to the hotel (a payment or refund), or the inverse — but **one convention, applied everywhere, documented once**. Mixing conventions is the most common source of an apparently-wrong balance in a system of this kind, and it is prevented by a single documented rule plus an invariant test.

### 6. Business date vs calendar date

Every posting carries both. They are not interchangeable. Night audit operates on the business date; a posting created at 02:00 on the 30th for the night of the 29th carries different values. The distinction is `TBD` in its **policy** (`C-05`) but the **modelling** requirement is fixed now, because retrofitting a business date onto historical postings would be a migration of immutable financial records — which is precisely what this model forbids.

### 7. System of record split

| Datum | System of record |
|---|---|
| The actual card transaction (authorization, capture, settlement at the acquirer) | **The payment provider** |
| Folio balances, charges, payment records, deposits, refund records, allocation of payments to folios, reconciliation status | **The PMS** |

The PMS stores provider-safe references only — token, authorization reference, transaction ID, result code, amount, currency, timestamps (`D-005`). It never stores PAN, CVV, or magstripe data.

This split has a practical consequence: a dispute about whether a card was actually charged is settled with the **provider**, not with the PMS. The PMS's record is that a payment was *recorded*, with a reference to settle it.

### 8. ZATCA traceability

Every invoice and every credit/debit note traces to the originating folio and its postings. This is a P0 requirement (`D-003`), and it is why the ledger must be append-only and why invoice numbering is a separate controlled sequence rather than a derived value.

### 9. Scope boundary — Phase A has two posting paths

Phase A posting paths are: (1) **folio posting** (charges, payments, adjustments) and (2) **night audit posting** (revenue, tax, settlement). POS room-charge posting is **deferred to Phase C** (`D-002`).

This is a deliberate scope control with a direct accounting benefit: with POS deferred, there is no third posting path competing for the same folio, and no risk of a room charge posted through a POS order bypassing the folio's tax and reconciliation logic. Adding POS later means adding a path that calls the **folio contract**, not one that writes folio tables (`ADR-0017`).

### 10. Deferred financial concepts

Company folios, group folios, transfers between folios, write-offs, and negotiated rates are **deferred and unscheduled** (`H-06`, `Q-015`). They are not designed here.

The ledger shape is chosen so that adding them is additive rather than a redesign — but that is a **design intent, not a verified claim**, and it must be re-examined when they are scheduled. A group folio in particular changes the balance-ownership model, and assuming today's design trivially extends to it would be exactly the kind of unverified assumption this document set forbids.

## Criteria Applied

Correctness (the ledger is the financial truth), compliance (immutability supports tax and audit obligations), reversibility (compensating entries make corrections safe; destructive edits do not), testability (invariants are directly assertable), operational simplicity (finance staff can explain any figure to a guest).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Mutable folio with a balance field updated per transaction | Rejected | Two independently updatable things (balance and postings) will diverge. Derived balance removes the failure mode. |
| Update the original record on correction | Rejected | Destroys the audit trail and the ability to explain what happened. `Prd_Maker.md` §28. |
| Hard delete on cancellation | Rejected | A cancellation with a financial consequence must remain visible as a charge plus a refund, not vanish. |
| Store the balance only, no posting detail | Rejected | No traceability, no reconciliation, no invoice derivation, no dispute handling. |
| Event sourcing for the ledger | Deferred | The append-only ledger delivers the required immutability and traceability without the modelling and operational cost. Reconsider if audit requirements demand event replay. |
| POS posting in Phase A | Deferred | Would create a third posting path and the accounting-inconsistency risk it is meant to avoid. |
| Ledger per legal entity from day one | Deferred | Depends on `B-06`. The model is designed so the sequence scope is a separate, changeable decision. |

## Unresolved

| Item | Status | Blocks |
|---|---|---|
| VAT inclusive/exclusive, rounding stage, rounding mode, precision/scale | `TBD` (`C-04`) | Every computation and the invoice output |
| Currency: single or multi-currency; whether FX enters the ledger | `TBD` (`C-06`) | Currency columns, exchange rates, rounding across rates |
| Invoicing legal entity (one or several) | `TBD` (`B-06`) | Invoice header, number sequence scope, credential sets |
| Business date, cut-off, reopen policy | `TBD` (`C-05`) | Night audit step placement |
| Refund approval thresholds and reason codes | `TBD` | `REFUND-001` |
| Charge catalogue and auto-posting rules | `TBD` | Folio posting correctness |
| Cashier shift procedure and variance tolerance | `TBD` | Settlement and reconciliation |
| Phase for company/group folios, transfers, write-offs | `TBD` (`H-06`) | Whether §10 becomes designed work |

**None of these are assumed.** An invented rounding mode or shift tolerance becomes indistinguishable from a deliberate financial policy once it is in code and once invoices have been issued under it.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Balance drifts from postings | Low (by design) | High | Balance is derived; periodic integrity reconciliation compares projection to ledger |
| A correction is applied by editing rather than compensating | Medium | High | No update path exists; code review + invariant test |
| Night audit re-run double-posts | Medium | **Critical** | Resumable-not-restartable + per-step idempotency (`ADR-0018`, test `CON-05`) |
| A refund cannot be traced to its payment | Low | High | Source payment is mandatory; `REFUND_SOURCE_REQUIRED` |
| An invoice cannot be traced to its charges | Low | **Critical** | `AC-FR-008-03`; folio reference mandatory |
| Rounding policy is set inconsistently across modules | Medium | High | Single documented policy; one implementation; static check for float; rounding tests |
| Phase C POS bypasses the folio contract | Medium | High | Module boundary rule; POS writes through the folio contract (`ADR-0017`) |

## Reversibility

**Deliberately low, and that is the point.** The ledger's immutability is the property that makes it defensible. It can be *relaxed* only by a policy decision with a legal owner, never by a refactor. The parts that remain changeable: the balance projection (rebuildable), the sign convention (documented, testable), and the deferred concepts (additive, with the caveat in §10).

## References

`D-002`, `D-003`, `D-005`, `BUS-004`, `BUS-005`, `BUS-006`, `ADR-0006`, `ADR-0010`, `ADR-0011`, `ADR-0017`, `ADR-0018`, `ADR-0021`, `Prd_Maker.md` §17, §21, §27, §28, §59, `docs/DATA-MODEL.md` §4, `docs/STATE-MACHINES.md` §F, `docs/COMPLIANCE.md`.
