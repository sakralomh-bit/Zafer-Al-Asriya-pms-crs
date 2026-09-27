# ADR-0017: Module Boundaries Within the Modular Monolith

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-001`, `D-002`, `D-006`, `ADR-0001`, `docs/ARCHITECTURE.md` §3

## Context

`D-006` fixes a modular monolith: one deployable, one relational database, one team, clear domain/module boundaries, and no premature microservices. `Prd_Maker.md` §72 explicitly forbids adding architecture because it is fashionable — microservices, Kubernetes, event sourcing, CQRS, service mesh, and multi-region active-active are not required by any documented requirement.

But a monolith without boundaries is a single large codebase where any module can read any table, and correctness degrades over time. The boundaries must be real at the code level even though the process and data boundaries are shared.

The specific risk in this domain: inventory allocation, reservation confirmation, folio posting, and the outbox insert are **interdependent facts that must commit together**. A boundary that forbids them from sharing a transaction is a boundary that is wrong for this product.

## Decision

### 1. The fourteen modules

| # | Module | Phase | Owns |
|---|---|---|---|
| 1 | Identity / Access | A | users, roles, permissions, property scope, sessions, MFA |
| 2 | Organization / Properties | A | organization, property, timezone, currency, tax profile, operating configuration |
| 3 | Rooms / Inventory | A | room types, physical rooms, room status, inventory, allocation, holds |
| 4 | Reservations | A | reservation, reservation state machine, stay, allocation references |
| 5 | Guests | A | guest profile, identity-document fields, guest history references |
| 6 | Front Desk | A | check-in, check-out, room transfer, stay extension, no-show, arrival/departure |
| 7 | Housekeeping | A | housekeeping task, room cleanliness, out-of-order handling |
| 8 | Folio / Financials | A | folio, postings, charges, taxes, adjustments, reversals, balance, business date |
| 9 | Payments | A | payment, refund, deposit, pre-authorisation, provider adapter port, reconciliation |
| 10 | Tax / ZATCA Compliance | A | tax rate, VAT calculation, invoice, credit/debit note, **ZATCA compliance adapter port** |
| 11 | Night Audit | A | night audit run, its state machine, resumable steps, reopen control |
| 12 | CRS | B | availability search, booking engine, guest-facing booking, online capture |
| 13 | Channel Manager | C | OTA adapters, channel mapping, sync health, reconciliation |
| 14 | POS | C | menu, orders, tables, POS cashier, room-charge posting |

### 2. Table ownership

Each module **owns its tables**. A module may not read or write another module's tables directly. Cross-module access goes through an explicit contract interface owned by the providing module.

The test for a boundary violation is simple and mechanical: if adding `use App\Finance\` to a file in `Modules\Rooms\` would not be obviously wrong to a reviewer, the boundary is not real.

### 3. The shared-transaction exception, and its exhaustive list

Most cross-module interactions are asynchronous or use a local transaction. A **small, explicitly enumerated set** of operations requires multiple modules to commit in **one database transaction** because the facts are not true independently:

| Transaction | Modules | Why atomicity is required |
|---|---|---|
| Inventory allocation → reservation confirm | Rooms/Inventory + Reservations | An allocation without a reservation, or a confirmed reservation without an allocation, is a corrupt state that no later repair can distinguish from a legitimate one. This is the double-booking guarantee. |
| Reservation transition → folio opening | Reservations + Folio | A check-in that has no folio, or a folio with no checked-in stay, is unrecoverable. |
| Charge posting → outbox insert | Folio + (outbox) | The posting and the intent to notify/invoice must not diverge. |
| Payment → outbox insert | Payments + (outbox) | A payment and its reconciliation intent must not diverge. |
| Invoice issue → outbox insert | Tax + (outbox) | An invoice that is never submitted to FATOORA, and a submission for an invoice that does not exist, are both unrecoverable without a full cross-check. |

**No other cross-module write may share a transaction.** Anything not on this list uses a domain event through the outbox. If a new atomic case is needed, it is added to this list with a justification — the list is a governed artefact, not a convenience.

### 4. Outbound ports are owned, never inward

The **ZATCA compliance adapter** (module 10) and the **payment provider adapter** (module 9) are outbound ports. The financial domain (module 8) depends on the *interface*, never on the adapter implementation and never on a provider SDK. This is what makes `B-04` (no provider selected) a contained problem rather than an architectural one, and it is why no provider type may appear in the financial domain.

### 5. Phase B/C seams are designed now

- The **CRS** (module 12) must reuse the *same* inventory, reservation, and payment abstractions. It is a new ingress path onto existing services, not a parallel booking engine. A guest booking online and a phone booking must contend for the same last unit through the same allocator.
- The **Channel Manager** (module 13) consumes and produces events. It is **not** a system of record: the PMS remains the authority for reservations and inventory, and an OTA message is a proposal to be validated and applied, not a fact to be trusted.
- The **POS** (module 14) posts room charges through the folio contract, not by writing folio tables. This is why deferring POS from Phase A is a genuine scope control: Phase A therefore has exactly **two** posting paths (folio, night audit) instead of three.

### 6. Prohibited

No distributed transactions. No sagas or two-phase commit. No service mesh. No event sourcing. No CQRS split. No inter-module schema sharing. No module reading another module's tables "just for a read".

## Criteria Applied

Correctness (atomic allocation stays a local ACID guarantee), operational simplicity (one deployment, one database, one on-call story), testability (module boundaries are testable seams), reversibility (a boundary mistake is a refactor, not a migration), cost (no distributed infrastructure to operate).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Microservices per domain | Rejected | Converts the double-booking guarantee into a distributed-consensus problem requiring sagas and compensating transactions. No documented requirement justifies it. `Prd_Maker.md` §72. |
| Single unstructured codebase | Rejected | No boundaries means silent coupling; correctness decays as the team grows. |
| Per-module database (even in one process) | Rejected | Destroys the single-transaction guarantee for allocation and folio posting. |
| Full event sourcing for the financial ledger | Deferred | Defensible long-term, expensive now, and not required by any current requirement. An append-only ledger gives the immutability benefit without the modelling cost. |
| CQRS with separate read models | Deferred | Read models can be added later if reporting load justifies it. Not justified by `B-05` yet. |
| Shared-schema "modular" approach | Rejected | Shared tables produce shared writes and untraceable coupling. |

## Consequences

- Module contracts must be designed and versioned like APIs, because they are the only cross-module surface.
- A new cross-module atomic case requires an explicit list amendment. This friction is intentional.
- The asynchronous seams (outbox, events) are the natural extension points for Phase B and C, so those phases add ingress paths rather than rewriting the core.
- Contract tests between modules are required; a module's public contract is tested by the consuming module's tests.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Boundary erosion via "temporary" direct table reads | High | High | Automated check for cross-module table access in CI; code review rule |
| The atomic-transaction list grows without limit | Medium | High | List is a governed artefact; growth requires explicit justification and review |
| A provider SDK type leaks into the financial domain | Medium | High | Outbound port ownership; no provider package in the finance module's dependency list |
| CRS later needs a real-time read model and the design resists it | Low | Medium | Read models are an additive change; not foreclosed |
| Module boundaries slow early delivery | Medium | Low | Accepted: the cost is front-loaded, the benefit accrues over the whole product |

## Reversibility

**Moderate to high.** Module boundaries are code-level, so they can be adjusted by refactoring. The genuinely hard-to-reverse item is the **atomic-transaction list** and the table ownership map, because data will be stored according to them. Those are therefore governed and reviewed rather than decided per-feature.

## References

`D-001`, `D-002`, `D-006`, `ADR-0001`, `ADR-0005`, `ADR-0009`, `ADR-0010`, `ADR-0011`, `Prd_Maker.md` §1.8 (one source of truth), §26 (idempotency/concurrency), §34 (inventory), §72 (anti-overengineering).
