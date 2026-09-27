# ADR-0008: Inventory Concurrency and Allocation Strategy

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-006`, `BUS-001`, `BUS-002`, `docs/STATE-MACHINES.md` §C, `docs/DATA-MODEL.md` §3, `ADR-0021`, `ADR-0003`

## Context

A hotel has a fixed number of physical rooms. A reservation consumes one of them for a range of nights. Two agents, a phone booking, and an online request can all target the last available room at the same moment. If both succeed, the hotel has sold the same room twice, and the recovery is not a database fix — it is a guest standing at a desk with a confirmed reservation and no room.

`Prd_Maker.md` §34 requires the specification to define how two concurrent requests for the final unit are handled, and gives the example acceptance condition.

`D-006` carries an explicit standing rule:

> **MySQL row locking alone does not guarantee prevention of double booking.**

This is stated plainly because the failure mode is subtle. A `SELECT ... FOR UPDATE` inside a transaction *is* the textbook answer, it *looks* correct, and it satisfies a code reviewer. What it does not do is prove the system behaves correctly under real concurrency — real isolation configuration, real commit ordering, real lock waits, real timeouts, real connection pool behaviour, and real deadlocks. A guarantee that has never been executed is a belief, not a guarantee.

**No such guarantee exists today, because there is no code.** This ADR specifies what must be built and what must be proven.

## Decision

Inventory correctness is enforced by the **combination of seven controls**. Any one of them alone is insufficient, and the guarantee is only as strong as the weakest.

### Control 1 — Explicit database transaction of short, defined scope

The allocation operation runs inside one explicit transaction whose scope is the stay: the inventory rows for every night of the requested range, the reservation row, and the outbox row. Nothing unrelated is done inside it. A long transaction holding a row lock while doing work is a lock-contention incident.

### Control 2 — Row-level locking, scoped to the stay

`SELECT ... FOR UPDATE` over the `inventory` rows for the requested property, room type, and **every night in the stay**, ordered deterministically to prevent deadlocks between two multi-night requests that acquire rows in different orders.

**The lock is per stay, not per property.** A property-wide lock would serialize unrelated bookings across 10 properties' worth of activity and would make peak check-in time unacceptable. Stay-scoped locking keeps contention proportional to actual conflict. The trade-off — more rows locked for longer stays, and a residual risk of deadlock between overlapping stays — is accepted, and deadlocks are handled as a bounded, retried, idempotent condition rather than treated as exceptional.

Deterministic row ordering is not optional. Two requests for overlapping date ranges that lock in different orders can deadlock, and an unordered `FOR UPDATE` makes the frequency of that dependent on the query plan.

### Control 3 — Unique constraint as a last-resort backstop

A uniqueness constraint on the allocation-relevant key ensures the same unit-night cannot be represented twice. Its role is **not** to be the primary control: relying on it alone surfaces contention as failed transactions, which is correct but gives legitimate bookings a poor failure mode. Its value is converting a concurrency defect from **silent duplicate allocation** — the catastrophic outcome — into a **deterministic error**. A unique-constraint violation is a loud, attributable, testable defect.

### Control 4 — Explicit allocation rule

Only units whose availability status is `SELLABLE` are allocatable. Units that are `OUT_OF_ORDER` (maintenance) or `BLOCKED` (administrative) are excluded. The rule is evaluated inside the transaction, not by the query that fetched the availability list — the list is a hint, not a guarantee.

### Control 5 — Reservation state-machine validation inside the same transaction

The reservation must be in a state from which allocation is permitted. This prevents allocating inventory to a cancelled, expired, or already-completed reservation, and it means an invalid state produces a deterministic error rather than a corrupted allocation.

### Control 6 — Client idempotency key

Every create/confirm/allocate operation carries an `Idempotency-Key`. A repeat with the same key returns the **original** result and creates no second allocation. This handles the most common real-world duplication: a client retrying after a timeout, or a user double-clicking.

Note the distinction: idempotency protects against a **repeated request**, and row locking protects against a **concurrent request**. They are not substitutes, and both are required.

### Control 7 — A mandatory concurrency test

See `ADR-0021`. This is the control that converts the other six from a design into a **verified** guarantee.

## Criteria Applied

Correctness (the primary requirement), reversibility (locking is a local, adjustable mechanism), testability (the guarantee is directly executable in a test), operational simplicity (no distributed coordination), performance (lock scope kept proportional to conflict).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Row locking alone, no other controls | **Rejected** | Explicitly prohibited by `D-006`. Correct-looking but unproven, and a unique-constraint mistake elsewhere would still corrupt data. |
| Check-then-insert without a lock | Rejected | The classic time-of-check-to-time-of-use defect. Two transactions both read "available" before either writes. |
| Optimistic concurrency (version column) alone | Rejected as primary | Works, but forces retry-and-re-read paths in a booking flow where a lost race should fail fast rather than retry — the guest wants an answer, not a wait. Viable as a secondary control for non-critical updates. |
| Pessimistic lock on the property | Rejected | Correct but over-broad. Serializes unrelated bookings and is unusable at peak. |
| Application-level mutex (e.g. Redis lock) | Rejected | Adds a distributed failure mode to a problem solvable locally, and a Redis lock without fencing tokens can still permit a stale write. |
| Read replica for availability checks | Rejected | Replica lag produces stale availability. Availability may be read from a replica for *display*; **allocation always reads the primary**. |
| SERIALIZABLE isolation as the primary control | Rejected as default | Would provide a guarantee, but with materially higher abort rates under contention. Explicit row locking at READ COMMITTED gives the same guarantee with a better failure mode. Available as an escalation if measurement (`B-05`) shows a need. |
| Allocation at check-in rather than at booking | Deferred | Changes the product (a booking would not guarantee a room). Not a technical rejection — a business decision, currently `TBD`. |
| Allow oversell deliberately | Deferred | A real commercial strategy some hotels use. **Not assumed either way** — flagged `TBD` so it is decided rather than defaulted. |

## Consequences

- Allocation is the most heavily tested code in the system, and it must be written knowing it is.
- Deadlocks are an expected condition, not an anomaly: they are caught, retried within a bounded budget, and are safe to retry because the operation is idempotent.
- Lock scope is a per-stay decision, which means the implementation must be careful with multi-night bookings and deterministic row ordering.
- Availability **search** and availability **allocation** are deliberately different code paths. Search may read a replica and may be approximate; allocation is exact and authoritative. Conflating them is how overselling happens.
- The concurrency suite becomes a merge gate, which is a permanent CI cost. This is cheaper than a double booking.

## Acceptance condition

> Given one available sellable unit and two concurrent valid booking attempts, no more than one may enter a confirmed state. The successful attempt MAY continue to confirmation. The losing attempt MUST receive `INVENTORY_UNAVAILABLE`. No duplicate confirmed allocation may exist. Both attempts MUST be traceable by request and correlation ID. Repeating either request with the same `Idempotency-Key` MUST NOT create a second allocation.

Mandatory tests: `CON-01`, `CON-02`, `CON-08` (`ADR-0021` §2).

**The removal test.** Each of `CON-01`, `CON-02`, and `CON-08` MUST be demonstrated to fail when its corresponding control is removed. A concurrency test that passes with its control removed is evidence of nothing and is treated as a defect.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A control is removed during a refactor without a test noticing | Medium | **Critical** | Removal test; concurrency suite in CI as a merge gate |
| Lock scope is widened, degrading peak performance | Low | High | Deterministic ordering; measurement once `B-05` provides scale |
| Deadlocks surface as user-visible errors | Medium | Medium | Bounded retry; idempotent operation; deadlock surfaced only after the budget is exhausted |
| Isolation level is not what the design assumes in production | **Medium** | **Critical** | Verify the actual deployed MySQL configuration, not the intent; assert in deployment checks (`ADR-0013`) |
| Availability is read from a replica during allocation | Low | **Critical** | Code review rule; static check; replica reads forbidden on the allocation path |
| Oversell policy is left implicit | Medium | High | Flagged `TBD` here and in `docs/STATE-MACHINES.md` §C.6 so it must be decided |

## Reversibility

**High for the mechanism, impossible for the harm.** The locking strategy can be changed, escalated, or replaced. A double booking that reaches a guest cannot be reversed by any change to the system. That asymmetry is the reason this control set is specified before any code exists rather than discovered after the first incident.

## References

`D-006`, `ADR-0003`, `ADR-0006` (transaction boundaries for money operations), `ADR-0017` (allocation and reservation are the canonical shared-transaction exception), `ADR-0021`, `Prd_Maker.md` §26, §34, §52, `docs/STATE-MACHINES.md` §C, `docs/DATA-MODEL.md` §3, `docs/TEST-STRATEGY.md` §6.
