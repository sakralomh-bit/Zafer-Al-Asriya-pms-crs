# ADR-0010: ZATCA / FATOORA Compliance Adapter Boundary

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-003`, `BUS-010`, `BUS-011`, `ADR-0005`, `ADR-0009`, `ADR-0017`, `docs/STATE-MACHINES.md` §H, §J.1

## Context

`D-003` places ZATCA e-invoicing as a **P0 workstream in Phase A** and requires that invoice issuance be architected as a **pluggable compliance adapter integrated with the PMS financial domain**, with ZATCA-specific logic **not hard-coded throughout the PMS domain**.

`Prd_Maker.md` §50 sets the rules for government platform integrations: use a dedicated adapter/service boundary (§50.1), separate business state from compliance state (§50.2), model submission ID / correlation ID / external status / timestamp / payload version / response code / retry status / final disposition / reconciliation status (§50.3), give compliance failure a deterministic operational path (§50.4), and re-verify current rules before production (§50.5). §23 requires that regulatory adapters be isolated from core business logic where practical, and that compliance configuration be versioned.

There is a second, sharper constraint. `Prd_Maker.md` §71 forbids inventing official API endpoints, credentials, certificates, SDK capabilities, and government approval processes. **The ZATCA integration API specifics have not been read from authoritative ZATCA developer documentation in this engagement.** Inventing a plausible endpoint or certificate flow would produce code that looks authoritative and is wrong, in the most consequential domain on the system.

## Decision

### 1. The boundary

The PMS financial domain owns: the invoice, the credit note, the debit note, the invoice number sequence, and the business facts behind them. It knows **nothing** about FATOORA.

A dedicated **ZATCA compliance adapter** (owned by the Tax/ZATCA module) is an **outbound port** that the financial domain depends on only through an interface. This is the module-boundary rule of `ADR-0017` applied to a regulated integration: the adapter is an owned outbound port, never an inward dependency of the financial domain.

The practical consequence: `B-02` (unknown API details) is a **contained** problem. The financial domain, the invoice model, and the ledger are fully designable now; only the adapter's protocol implementation waits for documentation.

### 2. Verified evidence (recorded, not over-claimed)

| Fact | Source | Verified |
|---|---|---|
| Phase 1 (Generation) enforceable 4 Dec 2021 | `zatca.gov.sa/en/E-Invoicing/Introduction/Pages/Roll-out-phases.aspx` | 2026-09-27 |
| Phase 2 (Integration with FATOORA) in waves from 1 Jan 2023, ≥6 months notice | same | 2026-09-27 |
| Wave 24: VAT revenue > SAR 375,000 → deadline 30 June 2026 | `zatca.gov.sa/en/Pages/news-1426.aspx` | 2026-09-27 |
| Wave 25: > SAR 187,500 (2022–2025) → deadline 1 Feb 2027 | `zatca.gov.sa/en/MediaCenter/News/Pages/default.aspx` | 2026-09-27 |
| Phase 2 requires platform integration, a specified format, and additional fields | `zatca.gov.sa/en/E-Invoicing/Pages/default.aspx` | 2026-09-27 |

### 3. What is `UNKNOWN` — and must remain so

Every one of the following is `UNKNOWN — requires confirmation from the authoritative source before production`:

ZATCA API endpoints · request and response schemas · authentication mechanism · **certificate and CSID requirements** · the **TLV/QR binary structure and its cryptographic signing** · **UBL XML schema constraints** · the **onboarding procedure** · error codes and their meanings · rate limits · sandbox availability · the full Phase 2 invoice field list · the applicable VAT rate.

These are blockers `B-02`. They are recorded so that no engineer infers them from a plausible-looking guess, and so that obtaining ZATCA developer documentation becomes a tracked dependency (`DEP-001`, `DEP-010`).

### 4. The organization's own status is NOT inferred

`B-01` — Zafer Al-Asriya's **wave, VAT threshold status, and current legal compliance status** are `REQUIRES CONFIRMATION BY THE ORGANIZATION'S AUTHORIZED TAX/COMPLIANCE REPRESENTATIVE`.

They are **not** inferred from the number of hotels, from an estimate of group revenue, or from the wave thresholds in §2. That a 10-property group plausibly exceeds the Wave 24 threshold is recorded as an **observation prompting urgent confirmation**, not as a determination. Providing legal advice or declaring the organization compliant or non-compliant is out of scope for engineering, and this document does neither.

This matters beyond this project: if the Wave 24 deadline of 30 June 2026 has passed without integration, a compliance exposure may already exist independently of this platform. That is a matter for the tax representative, and it is one of the first items in `docs/TASKS.md`.

### 5. Business state and compliance state are separate

| State | Meaning |
|---|---|
| Invoice `ISSUED` | A real business and tax fact: the invoice exists and is owed |
| Submission `PENDING` → `SUBMITTED` → `CONFIRMED` | The compliance transmission, which may fail independently |

A failed compliance submission **does not roll back the business operation**. A guest who checked out and received an invoice has checked out and been invoiced, regardless of whether a transmission later fails. The failure is visible, dead-lettered, alerting, and reconcilable (`BUS-010`, `AC-FR-008-01`).

The inverse is equally important: a `CONFIRMED` submission is not evidence that the underlying business transaction was correct. Compliance acceptance and business correctness are separate questions.

### 6. Transactional outbox

The submission record is written **in the same database transaction** as the invoice. This is the outbox pattern (`ADR-0005`) and it is mandatory here: an invoice that exists with no durable intent to submit, or a submission for an invoice that does not exist, are both unrecoverable without a full cross-check.

This is the single most important design decision in this ADR. ZATCA submissions are queued — an operator does not wait on a government platform to complete a checkout.

### 7. Retry, dead letter, reconciliation

Bounded retries with exponential backoff **and jitter**; a defined retryable/non-retryable error classification; `DEAD_LETTER` on exhaustion; **never silently dropped** (`Prd_Maker.md` §50.4); manual replay permitted and itself audited; every submission records ID, correlation ID, external status, timestamp, payload version, response code, retry status, final disposition, and reconciliation status (§50.3).

### 8. Controlled, gapless invoice numbering

Invoice numbers come from a **controlled sequence per legal entity** with no gaps and no duplicates, surviving failures, rollbacks, and aborted transactions. The sequence is a dedicated entity rather than a counter, because a counter alone cannot survive a rolled-back transaction without leaving a gap — and a gap in a regulated invoice sequence is itself a defect.

**The sequence scope is `TBD` pending `B-06`.** If the group has more than one legal entity, each needs its own sequence and its own credential set. A 10-property group may well have several legal entities, and this is not a detail to assume.

### 9. Traceability

Every invoice and every credit/debit note is traceable to the originating folio and its postings (`AC-FR-008-03`). This is a direct consequence of the append-only ledger (`ADR-0009`): the invoice is a *derivation* of postings, never a parallel record.

### 10. Compliance configuration is versioned

Tax rates and compliance settings are scoped, validated, audited, versioned, permission-controlled, and recoverable (`Prd_Maker.md` §61). A change to a tax rate must not retroactively alter a past invoice.

### 11. Credential and certificate handling

Certificates and CSID material are stored in the centralized secrets manager (`D-007`), never in source control, never in application configuration, never in logs. Rotation is `TBD` because the mechanism is `UNKNOWN` (`B-02`), but the storage requirement is fixed now and is not contingent on the answer.

## Criteria Applied

Compliance (a regulated obligation, isolated so it cannot corrupt business logic), correctness (outbox guarantees no lost or orphaned submission), security (credentials handled as secrets), testability (adapter is testable against a fake), reversibility (the adapter is replaceable; the financial domain is not coupled to it), operational simplicity (async submission never blocks a guest).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| ZATCA calls inline during checkout | Rejected | A government platform's availability would become a checkout dependency. Violates `BUS-008` and puts a guest at the desk waiting on a network. |
| ZATCA logic inside the folio/financial modules | **Rejected** | `Prd_Maker.md` §50.1. Couples core business logic to a regulated integration, making both harder to change and far harder to test. |
| Third-party fiscal device / external e-invoicing solution as system of record | Not selected | Was raised as an option and not chosen by the project manager. If it were adopted, the PMS would become a source of charges rather than the issuer of invoices — a materially different financial model. Recorded as a considered alternative, not a rejected one. |
| A generic "compliance adapter" with no ZATCA specifics | **Rejected** | A generic interface with no concrete implementation is not a design; it defers the decision indefinitely. The interface is designed *and* the specifics are recorded as a blocker with an owner. |
| Implement the ZATCA protocol from general knowledge | **Rejected** | `Prd_Maker.md` §71. Would produce authoritative-looking code that is wrong. |
| Treat ZATCA as a post-v1.0 concern | **Rejected** | `D-003` made it P0 in Phase A. |
| One global invoice sequence for the group | Rejected | Sequences are per legal entity; a single sequence across entities would produce incorrect, untraceable numbering. Deferred to the `B-06` answer, but the per-entity model is the design. |

## Consequences

- The financial domain is fully designable now; only the adapter's protocol implementation is blocked.
- ZATCA onboarding / CSID issuance is treated as a **long-lead-time dependency** (`DEP-010`) and must start early, because an administrative process can block production readiness even when the code is complete.
- Compliance failure is operationally visible rather than silent — by design, at the cost of a real alerting and manual-replay workload.
- Compliance is a **release gate**, not a stretch goal: a Phase A release without a working, tested submission path with reconciliation is not a release.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| The organization has an unaddressed compliance exposure **today**, independent of this project | **Unknown** | **Critical** | Escalate immediately to the tax representative; `B-01` as the first task in `docs/TASKS.md` |
| Adapter built from unverified assumptions | Medium | **Critical** | Every unknown recorded explicitly; no implementation begins before documentation is read (`B-02`) |
| Submission lost between the invoice write and the queue | Low | **Critical** | Transactional outbox — same transaction |
| Duplicate invoice on a resumed night audit | Medium | **Critical** | Step idempotency + controlled sequence (`ADR-0018`) |
| Sequence gap on a rolled-back transaction | Low | High | Dedicated sequence entity; gaplessness tested by fault injection |
| Wrong sequence scope if multiple legal entities exist | Medium | High | `B-06` resolved before Phase A5 |
| Dead letters accumulate unnoticed | Medium | High | DLQ depth and age monitored and alerted (`KPI-04`) |
| Compliance state confused with business state | Low | High | Separate machines; `AC-FR-008-01` |

## Reversibility

**Moderate for the adapter, deliberately low for the numbering.** The adapter can be replaced if the platform's requirements change. The invoice numbering sequence cannot: numbers are issued to guests, appear on regulated documents, and create a permanent obligation. Getting the sequence scope and the issuing entity right **before** issuing the first invoice is the irreversible moment in this workstream, which is why `B-06` blocks Phase A5.

## References

`D-003`, `D-007`, `BUS-008`, `BUS-010`, `BUS-011`, `BUS-016`, `ADR-0005`, `ADR-0009`, `ADR-0011`, `ADR-0016`, `ADR-0017`, `ADR-0018`, `Prd_Maker.md` §23, §24, §29, §33, §50, §51, §61, §71, `V-01`–`V-05`, `B-01`, `B-02`, `B-06`, `docs/STATE-MACHINES.md` §H, §J.1, `docs/COMPLIANCE.md`, `docs/TASKS.md`.
