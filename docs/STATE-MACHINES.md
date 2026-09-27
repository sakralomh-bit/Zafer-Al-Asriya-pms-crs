# Zafer Al-Asriya v1.0 — State Machine Specification

| Field | Value |
|---|---|
| Document | `docs/STATE-MACHINES.md` |
| Version | 0.1 |
| Status | Draft — specification only. **Nothing is implemented.** |
| Governing framework | `Prd_Maker.md` §1.7, §18, §52 |
| Related | `ADR-0008`, `ADR-0009`, `ADR-0011`, `ADR-0018`, `ADR-0019`, `docs/DATA-MODEL.md`, `docs/PRD.md` §13 |

---

## 0. How to read this document

`Prd_Maker.md` §1.7: *"For every core domain object with a lifecycle, define a state machine before declaring the requirements complete."* §18: *"Do not allow lifecycle logic to be hidden in prose."*

This document specifies state machines. It does **not** implement them. Every state, transition, guard, and error code here is a requirement for `docs/TASKS.md`, not a description of existing behaviour — because no code exists.

**Global rules, applying to every machine below:**

| Rule | Requirement | Source |
|---|---|---|
| G-1 | No transition may be implied or enforced **only** by UI behaviour. Every transition is enforced server-side. | `Prd_Maker.md` §18, §14 |
| G-2 | An invalid transition MUST return a deterministic, machine-readable error code. Never a generic validation error, never a stack trace, never a partial success. | `Prd_Maker.md` §18, `ADR-0019` |
| G-3 | Terminal states are explicit and listed. No transition leaves a terminal state. | `Prd_Maker.md` §18 |
| G-4 | Every transition declares an **actor**, **preconditions**, **authorization**, **side effects**, **audit event**, and **idempotency key**. A transition missing any of these is not specified. | `Prd_Maker.md` §18 |
| G-5 | Retries MUST NOT create duplicate business effects. Every money/inventory/reservation transition is idempotent. | `Prd_Maker.md` §18, §26 |
| G-6 | Concurrent transitions MUST have defined conflict behaviour. "Last write wins" is not acceptable for money, inventory, or reservation state. | `Prd_Maker.md` §18 |
| G-7 | Business state and compliance state are separate. A confirmed reservation and a pending ZATCA submission are different facts. | `Prd_Maker.md` §50.2, `ADR-0010` |
| G-8 | `UNKNOWN` marks a fact that has not been verified against an authoritative source. It is never a placeholder for a guess. | `Prd_Maker.md` §1.1, §71 |

**Authorization roles** referenced below are defined in `ADR-0014`.

**Error codes** are part of the API contract (`ADR-0019`) and MUST NOT be reused with a different meaning.

---

## A. Reservation

### A.1 States

| State | Meaning | Terminal |
|---|---|:-:|
| `DRAFT` | Being entered. Not holding inventory. | No |
| `HELD` | Inventory held, awaiting confirmation. Hold has an expiry. | No |
| `PENDING_PAYMENT` | Confirmed commercially, awaiting payment or deposit. | No |
| `CONFIRMED` | Committed. Counted in the occupancy forecast. | No |
| `CHECKED_IN` | Guest physically on site. A folio is open. | No |
| `CHECKED_OUT` | Guest departed. Folio settled or flagged. | No |
| `COMPLETED` | Stay finished and financially closed. | **Yes** |
| `MODIFICATION_PENDING` | A change has been requested and awaits acceptance/payment. | No |
| `CANCELLED` | Terminated before service. | **Yes** |
| `NO_SHOW` | Guest did not arrive and did not cancel. | **Yes** |
| `EXPIRED` | Hold lapsed without confirmation. | **Yes** |
| `REJECTED` | Declined by the property (capacity, policy, fraud signal). | **Yes** |

**Missing-state note (from the discovery audit).** There is deliberately **no** `PARTIALLY_PAID` state. A reservation with a deposit paid and a balance outstanding is `CONFIRMED` with a folio balance — partial payment is a **folio** fact, not a reservation state. Introducing a payment-derived reservation state would couple the reservation lifecycle to the payment lifecycle and make the two impossible to reason about separately. Recorded here because it is a decision, not an oversight.

### A.2 Transition table

| # | From → To | Actor | Preconditions | Side effects | Audit event | Idempotency | Authorization |
|---|---|---|---|---|---|---|---|
| A-T1 | `DRAFT` → `HELD` | Reservation Agent / system | Availability exists; hold duration configured | Inventory hold created; hold expiry set | `RESERVATION_HELD` | `Idempotency-Key` | Reservation create |
| A-T2 | `HELD` → `CONFIRMED` | Reservation Agent / system | Hold not expired; payment policy satisfied | Hold converted to allocation; outbox event | `RESERVATION_CONFIRMED` | `Idempotency-Key` | Reservation confirm |
| A-T3 | `HELD` → `PENDING_PAYMENT` | System | Payment policy requires prepayment | Hold retained; payment intent created | `RESERVATION_PAYMENT_PENDING` | `Idempotency-Key` | Reservation confirm |
| A-T4 | `PENDING_PAYMENT` → `CONFIRMED` | System / agent | Required payment received **and reconciled** | Hold converted to allocation; outbox event | `RESERVATION_CONFIRMED` | `Idempotency-Key` | Reservation confirm |
| A-T5 | `CONFIRMED` → `MODIFICATION_PENDING` | Reservation Agent | Change affects dates, rate, or room | Change request recorded; original retained | `RESERVATION_MODIFICATION_REQUESTED` | `Idempotency-Key` | Reservation modify |
| A-T6 | `MODIFICATION_PENDING` → `CONFIRMED` | Reservation Agent / system | Change validated; inventory available; any price difference settled | Change applied; inventory rebalanced atomically | `RESERVATION_MODIFIED` | `Idempotency-Key` | Reservation modify |
| A-T7 | `MODIFICATION_PENDING` → `CANCELLED` | Reservation Agent | Cancelled during modification | Allocation released | `RESERVATION_CANCELLED` | `Idempotency-Key` | Reservation cancel |
| A-T8 | `CONFIRMED` → `CHECKED_IN` | Front Desk Agent | Guest identity captured; room in an occupiable state; **room status precondition re-evaluated inside the transaction** | Room status → occupied; **folio opened atomically with this transition**; deposit/pre-auth per policy | `GUEST_CHECKED_IN` | `Idempotency-Key` | Check-in |
| A-T9 | `CHECKED_IN` → `CHECKED_OUT` | Front Desk Agent | All charges posted; balance computed | Room status → dirty; outbox for settlement | `GUEST_CHECKED_OUT` | `Idempotency-Key` | Check-out |
| A-T10 | `CHECKED_OUT` → `COMPLETED` | Night Audit / system | Business date closed; folio financially closed | Folio locked; revenue posted; tax/invoice processing triggered | `RESERVATION_COMPLETED` | `Idempotency-Key` | Night audit run |
| A-T11 | `CONFIRMED`/`HELD`/`PENDING_PAYMENT` → `CANCELLED` | Reservation Agent | Cancellation policy satisfied | Inventory released; cancellation charges posted if policy requires; refund initiated per policy | `RESERVATION_CANCELLED` | `Idempotency-Key` | Reservation cancel |
| A-T12 | `CONFIRMED` → `NO_SHOW` | Night Audit / system | No-show cut-off passed; guest not checked in | Inventory released; no-show fee posted if policy requires | `RESERVATION_NO_SHOW` | `Idempotency-Key` | Night audit run |
| A-T13 | `HELD` → `EXPIRED` | System (scheduled) | Hold expiry elapsed | Inventory released | `RESERVATION_HOLD_EXPIRED` | `Idempotency-Key` | System only |
| A-T14 | `HELD` → `CANCELLED` | Reservation Agent | Manual cancel of a hold | Inventory released | `RESERVATION_CANCELLED` | `Idempotency-Key` | Reservation cancel |
| A-T15 | `DRAFT`/`HELD`/`PENDING_PAYMENT` → `REJECTED` | Group Manager / Hotel Manager | Documented reason | No inventory effect (or hold released) | `RESERVATION_REJECTED` | `Idempotency-Key` | Reservation reject |
| A-T16 | `CONFIRMED` → `CANCELLED` (after no-show cut-off, before arrival) | Reservation Agent | Late-arrival rules | Per policy | `RESERVATION_CANCELLED` | `Idempotency-Key` | Reservation cancel + step-up |

**Room transfer is deliberately NOT a reservation transition.** A room transfer changes the allocation and the room, not the reservation's own lifecycle. Modeling it as a reservation transition would put room assignment concerns into the reservation machine and make the concurrency story for room assignment unclear. It is specified in the Room and Inventory machines (C) and in the Front Desk flows.

### A.3 Invalid transitions

| Attempted | Error code | HTTP | `retryable` |
|---|---|:-:|:-:|
| `CANCELLED` → anything | `RESERVATION_STATE_INVALID` | 409 | false |
| `COMPLETED` → anything | `RESERVATION_STATE_INVALID` | 409 | false |
| `EXPIRED` → anything | `RESERVATION_STATE_INVALID` | 409 | false |
| `NO_SHOW` → anything | `RESERVATION_STATE_INVALID` | 409 | false |
| `REJECTED` → anything | `RESERVATION_STATE_INVALID` | 409 | false |
| `HELD` → `CONFIRMED` after hold expiry | `RESERVATION_HOLD_EXPIRED` | 409 | false |
| `HELD` → `CONFIRMED` with no inventory (lost race) | `INVENTORY_UNAVAILABLE` | 409 | false |
| `PENDING_PAYMENT` → `CONFIRMED` without reconciled payment | `PAYMENT_NOT_SETTLED` | 409 | false |
| `CONFIRMED` → `CHECKED_IN` with room not occupiable | `ROOM_NOT_OCCUPIABLE` | 409 | false |
| `CHECKED_IN` → `CHECKED_OUT` with unposted charges | `FOLIO_HAS_UNPOSTED_CHARGES` | 409 | false |
| `CONFIRMED` → `CANCELLED` inside a non-refundable window | `CANCELLATION_POLICY_VIOLATION` | 422 | false |
| `DRAFT` → `CHECKED_IN` (skipping confirmation) | `RESERVATION_STATE_INVALID` | 409 | false |
| Any transition on a reservation outside the caller's granted property | `PROPERTY_SCOPE_DENIED` | 403 | false |

### A.4 Concurrency and conflict behaviour

| Race | Behaviour |
|---|---|
| Two agents confirm the same reservation simultaneously | Optimistic version check on the reservation; loser receives `RESERVATION_STATE_INVALID`. Exactly one transition commits. |
| Confirmation racing hold expiry | Hold expiry is evaluated **inside** the confirmation transaction, not by a scheduler alone. A confirmation either commits before expiry or is rejected with `RESERVATION_HOLD_EXPIRED`. |
| Modification racing a check-in | Modification from `CHECKED_IN` is not permitted (`RESERVATION_STATE_INVALID`); a rate change on an in-house stay follows the in-house rate-change flow instead. |
| Cancellation racing check-in | The room-status precondition and the reservation version check are evaluated in one transaction. Exactly one wins; the loser receives a deterministic denial. |
| Duplicate confirm from a retried request | Same `Idempotency-Key` returns the original result; no second allocation. |
| Two bookings for the last unit | See machine C and `ADR-0008`. Loser receives `INVENTORY_UNAVAILABLE`. |

### A.5 Unresolved

| Item | Status |
|---|---|
| Booking window (how far ahead a reservation may be made) | `TBD` |
| Hold duration | `TBD` |
| Arrival/departure cut-off times | `TBD` (`C-05`) |
| No-show cut-off | `TBD` (`C-05`) |
| Maximum stay length | `TBD` |
| Cancellation policy windows and penalty tiers | `TBD` |
| Deposit requirement per rate plan | `TBD` |
| Whether a reservation may be `CONFIRMED` with an unpaid balance at check-in | `TBD` |

None of these are assumed. A reservation machine with invented timings would produce wrong behaviour that passes review.

---

## B. Room

### B.1 Two independent status axes — a deliberate design decision

A common PMS modelling error is a single `status` column. It produces contradictions such as a room that is simultaneously "occupied" and "clean". This specification uses **three orthogonal axes** that cannot contradict each other:

| Axis | Values |
|---|---|
| **Occupancy status** | `VACANT` · `OCCUPIED` · `RESERVED` (held for an arriving guest) |
| **Housekeeping status** | `CLEAN` · `DIRTY` · `INSPECTED` · `IN_PROGRESS` |
| **Availability status** | `SELLABLE` · `OUT_OF_ORDER` (maintenance) · `BLOCKED` (administrative) |

An occupiable room is one where occupancy is `VACANT` **and** housekeeping is `INSPECTED` **and** availability is `SELLABLE`. The check-in precondition (transition A-T8) requires all three, evaluated inside the transaction.

### B.2 Transitions

| Axis | From → To | Actor | Preconditions | Audit | Authorization |
|---|---|---|---|---|---|
| Occupancy | `VACANT` → `RESERVED` | System | Room selected for an allocation | `ROOM_RESERVED` | System |
| Occupancy | `RESERVED` → `OCCUPIED` | Front Desk | Check-in precondition passes (A-T8) | `ROOM_OCCUPIED` | Check-in |
| Occupancy | `OCCUPIED` → `VACANT` | Front Desk / Housekeeping | Check-out complete; housekeeping task raised | `ROOM_VACANT` | Check-out |
| Occupancy | `RESERVED` → `VACANT` | System | Allocation released, hold expired, or reservation cancelled | `ROOM_RELEASED` | System |
| Housekeeping | `CLEAN` → `IN_PROGRESS` | Housekeeping | Task assigned | `HOUSEKEEPING_STARTED` | Housekeeping |
| Housekeeping | `IN_PROGRESS` → `DIRTY` | Housekeeping | — | `HOUSEKEEPING_DIRTY` | Housekeeping |
| Housekeeping | `DIRTY` → `CLEAN` | Housekeeping | Cleaning completed | `HOUSEKEEPING_CLEAN` | Housekeeping |
| Housekeeping | `CLEAN` → `INSPECTED` | Housekeeping / Inspector | Inspection passed | `HOUSEKEEPING_INSPECTED` | Housekeeping |
| Housekeeping | `INSPECTED` → `DIRTY` | Housekeeping | Re-inspection failed; room not occupiable | `HOUSEKEEPING_REINSPECTION_FAILED` | Housekeeping |
| Availability | `SELLABLE` → `OUT_OF_ORDER` | Housekeeping / Maintenance | Reason recorded | `ROOM_OUT_OF_ORDER` | Housekeeping + step-up |
| Availability | `OUT_OF_ORDER` → `SELLABLE` | Housekeeping | Maintenance cleared; housekeeping re-verified | `ROOM_RETURNED_TO_SERVICE` | Housekeeping |
| Availability | `SELLABLE` → `BLOCKED` | Group Manager / Hotel Manager | Reason and duration recorded | `ROOM_BLOCKED` | Room block |
| Availability | `BLOCKED` → `SELLABLE` | Group Manager / Hotel Manager | Block period ended | `ROOM_UNBLOCKED` | Room block |

### B.3 Invalid transitions

| Attempted | Error code | HTTP |
|---|---|:-:|
| `OCCUPIED` → `VACANT` without check-out | `ROOM_OCCUPIED` | 409 |
| `VACANT` → `OCCUPIED` without a check-in transition | `ROOM_STATE_INVALID` | 409 |
| `INSPECTED` → `OCCUPIED` while housekeeping is not `INSPECTED` | `ROOM_NOT_OCCUPIABLE` | 409 |
| Any occupancy change on an `OUT_OF_ORDER` room | `ROOM_OUT_OF_ORDER` | 409 |
| `DIRTY` → `INSPECTED` skipping cleaning | `ROOM_STATE_INVALID` | 409 |
| Room change outside granted property | `PROPERTY_SCOPE_DENIED` | 403 |

### B.4 Unresolved

Out-of-order reason codes and service-level targets; inspection checklists and their granularity; blocked-room duration semantics. All `TBD`.

---

## C. Inventory / Allocation

### C.1 States (allocation lifecycle)

| State | Meaning | Terminal |
|---|---|:-:|
| `AVAILABLE` | Sellable for the date | No |
| `HELD` | Provisionally allocated; expires | No |
| `ALLOCATED` | Committed to a reservation | No |
| `RELEASED` | Freed; returns to available | No |
| `EXPIRED` | Hold lapsed | No |
| `REVOKED` | Administratively withdrawn from sale (out-of-order, blocked) | **Yes** |
| `CONSUMED` | Stay completed; not re-sellable for that stay | **Yes** |

### C.2 Transitions

| # | From → To | Actor | Preconditions | Side effects | Audit | Idempotency |
|---|---|---|---|---|---|---|
| C-T1 | `AVAILABLE` → `HELD` | Reservation Agent / system | Sellable unit; no competing hold | Hold record with expiry; **row locked for the property + type + date range** | `INVENTORY_HELD` | `Idempotency-Key` |
| C-T2 | `HELD` → `ALLOCATED` | System | Hold not expired; reservation in an allocatable state | Allocation committed **atomically with the reservation transition** | `INVENTORY_ALLOCATED` | `Idempotency-Key` |
| C-T3 | `HELD` → `EXPIRED` | System (scheduled) | Hold expiry elapsed | Returns to `AVAILABLE` | `INVENTORY_HOLD_EXPIRED` | `Idempotency-Key` |
| C-T4 | `HELD` → `RELEASED` | System / agent | Reservation cancelled or modified away | Returns to `AVAILABLE` | `INVENTORY_RELEASED` | `Idempotency-Key` |
| C-T5 | `ALLOCATED` → `RELEASED` | System | Reservation cancelled before arrival | Returns to `AVAILABLE` | `INVENTORY_RELEASED` | `Idempotency-Key` |
| C-T6 | `ALLOCATED` → `CONSUMED` | Night Audit | Stay completed; business date closed | Not re-sellable for that stay | `INVENTORY_CONSUMED` | `Idempotency-Key` |
| C-T7 | `AVAILABLE` → `REVOKED` | Housekeeping / Manager | Room out of order or blocked | Not sellable | `INVENTORY_REVOKED` | `Idempotency-Key` |
| C-T8 | `REVOKED` → `AVAILABLE` | Housekeeping / Manager | Room returned to service | Sellable again | `INVENTORY_RESTORED` | `Idempotency-Key` |
| C-T9 | `ALLOCATED` → `ALLOCATED` (reassign) | Front Desk | Target room occupiable; occupancy status precondition passes | Room transfer: release + allocate **atomically** | `ROOM_TRANSFERRED` | `Idempotency-Key` |

### C.3 The double-booking guarantee

`ADR-0008` is normative here. **MySQL row locking alone does not guarantee prevention of double booking.** The guarantee is the combination of:

1. an explicit database transaction of short, defined scope;
2. `SELECT ... FOR UPDATE` over the specific inventory rows for the requested property, room type, and date range;
3. a unique constraint as a last-resort backstop that converts a logic defect into a deterministic error;
4. the explicit allocation rule (only `SELLABLE`, non-revoked units);
5. reservation state-machine validation inside the same transaction;
6. the client `Idempotency-Key`;
7. **the mandatory concurrency test CON-01** (`ADR-0021`).

**Acceptance condition:**

> Given one available sellable unit and two concurrent valid booking attempts, no more than one may enter a confirmed state. The successful attempt MAY continue to confirmation. The losing attempt MUST receive `INVENTORY_UNAVAILABLE`. No duplicate confirmed allocation may exist. Both attempts MUST be traceable by request and correlation ID. Repeating either request with the same `Idempotency-Key` MUST NOT create a second allocation.

### C.4 Invalid transitions

| Attempted | Error code | HTTP |
|---|---|:-:|
| `HELD` → `ALLOCATED` after expiry | `RESERVATION_HOLD_EXPIRED` | 409 |
| Any allocation of a `REVOKED` unit | `ROOM_NOT_SELLABLE` | 409 |
| Allocation for a room type with zero sellable units | `INVENTORY_UNAVAILABLE` | 409 |
| Reallocation to a room not occupiable | `ROOM_NOT_OCCUPIABLE` | 409 |
| Allocation outside the booking window | `OUTSIDE_BOOKING_WINDOW` | 422 |
| Allocation violating a minimum-stay restriction | `RESTRICTION_VIOLATION` | 422 |
| Allocation across a granted-property boundary | `PROPERTY_SCOPE_DENIED` | 403 |

### C.5 Concurrency

| Race | Behaviour |
|---|---|
| Two allocations for the last unit | Row lock serializes; one commits, one receives `INVENTORY_UNAVAILABLE`. Gated by test CON-01. |
| Duplicate request, same idempotency key | Original result returned; no second allocation. Gated by CON-02. |
| Hold expiry racing a new allocation | Expiry evaluated inside the allocation transaction, not only by the scheduler. Gated by CON-08. |
| Two different properties allocating concurrently | Lock scope is property-scoped; contention is bounded and intentional. |
| Night audit closing a business date while an allocation is in flight | Per-property-per-business-date run lock; allocation to a closed business date is rejected. Gated by CON-06. |

### C.6 Unresolved

Oversell policy (`TBD` — an explicit decision is required; it is **not** defaulted to "never" or "allowed"); hold duration; booking window; cut-off times; allocation strategy (physical room at hold time vs at check-in time) — **this is a significant undecided design point**; maintenance-blocked unit treatment; restriction model. All `TBD`.

---

## D. Payment

`Prd_Maker.md` §27: *"Never collapse all payment states into SUCCESS / FAILED."*

### D.1 States

| State | Meaning | Terminal |
|---|---|:-:|
| `INITIATED` | Payment intent created | No |
| `REQUIRES_ACTION` | Awaiting cardholder action | No |
| `AUTHORIZED` | Funds reserved, not captured | No |
| `CAPTURE_PENDING` | Capture submitted, awaiting confirmation | No |
| `CAPTURED` | Funds captured | No |
| `SETTLEMENT_PENDING` | Submitted for settlement | No |
| `SETTLED` | Provider-confirmed settled | **Yes** |
| `PARTIALLY_REFUNDED` | Some value refunded | No |
| `REFUND_PENDING` | Refund submitted, awaiting confirmation | No |
| `REFUNDED` | Fully refunded | **Yes** |
| `VOIDED` | Authorization released before capture | **Yes** |
| `FAILED` | Provider rejected, definitively | **Yes** |
| `CANCELLED` | Cancelled before authorization | **Yes** |
| **`UNKNOWN_OUTCOME`** | **Request sent, result not confirmed** | **No — must reach a resolved state** |

### D.2 The `UNKNOWN_OUTCOME` state — the most important state in this machine

If the PMS sends an authorisation and the connection fails before a response is received, the system does **not** know whether the charge succeeded. The correct behaviour is:

1. Record `UNKNOWN_OUTCOME` with the idempotency key and the provider request reference, if any.
2. **Do not retry.** `Prd_Maker.md` §33: *"Never retry blindly after an unknown payment or financial outcome."*
3. Do not mark the folio balance as unpaid or as paid.
4. Raise a reconciliation task against the provider.
5. The API response to the operator is `PAYMENT_OUTCOME_UNKNOWN` with **`retryable: false`** (`ADR-0019`), because retrying is exactly what would cause a duplicate charge.
6. The state resolves only via reconciliation to `CAPTURED`/`SETTLED` or to `FAILED`/`VOIDED`, or to `REFUND_PENDING` if the provider confirms a capture that must be unwound.

**Omitting this state is the single most damaging omission available in a payment machine**, and it is called out here because it is invisible in a normal review.

### D.3 Transitions

| # | From → To | Actor | Preconditions | Audit | Idempotency |
|---|---|---|---|---|---|
| D-T1 | `INITIATED` → `REQUIRES_ACTION` | System / client | Provider requires action | `PAYMENT_ACTION_REQUIRED` | `Idempotency-Key` |
| D-T2 | `INITIATED` → `AUTHORIZED` | Provider result | Authorization succeeded; **result confirmed** | `PAYMENT_AUTHORIZED` | `Idempotency-Key` |
| D-T3 | `INITIATED` → `CAPTURE_PENDING` → `CAPTURED` | Provider result | Capture confirmed | `PAYMENT_CAPTURED` | `Idempotency-Key` |
| D-T4 | `AUTHORIZED` → `CAPTURE_PENDING` | System | Capture requested (deposit settlement) | `PAYMENT_CAPTURE_REQUESTED` | `Idempotency-Key` |
| D-T5 | `CAPTURED` → `SETTLEMENT_PENDING` → `SETTLED` | Provider result | Provider settlement confirmed | `PAYMENT_SETTLED` | `Idempotency-Key` |
| D-T6 | `INITIATED`/`REQUIRES_ACTION` → `FAILED` | Provider result | Definitive rejection | `PAYMENT_FAILED` | `Idempotency-Key` |
| D-T7 | `INITIATED` → `CANCELLED` | System / agent | Before authorization | `PAYMENT_CANCELLED` | `Idempotency-Key` |
| D-T8 | `AUTHORIZED` → `VOIDED` | System | Authorization released pre-capture | `PAYMENT_VOIDED` | `Idempotency-Key` |
| D-T9 | `CAPTURED`/`SETTLED` → `REFUND_PENDING` | Finance | Refund approved; reason code; step-up auth | `PAYMENT_REFUND_REQUESTED` | `Idempotency-Key` |
| D-T10 | `REFUND_PENDING` → `REFUNDED` | Provider result | Full refund confirmed | `PAYMENT_REFUNDED` | `Idempotency-Key` |
| D-T11 | `REFUND_PENDING` → `PARTIALLY_REFUNDED` | Provider result | Partial refund confirmed | `PAYMENT_PARTIALLY_REFUNDED` | `Idempotency-Key` |
| D-T12 | `PARTIALLY_REFUNDED` → `REFUNDED` | Provider result | Remaining value refunded | `PAYMENT_REFUNDED` | `Idempotency-Key` |
| D-T13 | any non-terminal → `UNKNOWN_OUTCOME` | System | No confirmed result within timeout | `PAYMENT_OUTCOME_UNKNOWN` | `Idempotency-Key` |
| D-T14 | `UNKNOWN_OUTCOME` → `CAPTURED`/`FAILED`/`VOIDED`/`REFUND_PENDING` | Reconciliation | Provider record resolves the outcome | `PAYMENT_RECONCILED` | Reconciliation ref |
| D-T15 | `CAPTURE_PENDING`/`SETTLEMENT_PENDING` → `UNKNOWN_OUTCOME` | System | No confirmation within timeout | `PAYMENT_OUTCOME_UNKNOWN` | `Idempotency-Key` |

### D.4 Invalid transitions

| Attempted | Error code | HTTP | `retryable` |
|---|---|:-:|:-:|
| `SETTLED` → `CAPTURE_PENDING` | `PAYMENT_STATE_INVALID` | 409 | false |
| `REFUNDED` → `REFUND_PENDING` | `PAYMENT_STATE_INVALID` | 409 | false |
| `VOIDED` → `CAPTURE_PENDING` | `PAYMENT_STATE_INVALID` | 409 | false |
| Capture without authorization | `PAYMENT_NOT_AUTHORIZED` | 422 | false |
| Refund exceeding the captured amount | `REFUND_EXCEEDS_PAYMENT` | 422 | false |
| Refund without a reason code | `REFUND_REASON_REQUIRED` | 422 | false |
| Refund without step-up authentication | `STEP_UP_REQUIRED` | 403 | false |
| Any transition on a payment outside granted property | `PROPERTY_SCOPE_DENIED` | 403 | false |
| Retry of an `UNKNOWN_OUTCOME` payment via a new request | `PAYMENT_OUTCOME_UNKNOWN` | 409 | **false** |

### D.5 Concurrency

| Race | Behaviour |
|---|---|
| Two concurrent submissions with the same key | One provider request; all callers receive the original result. Gated by CON-04. |
| Refund racing a second capture | Amount check and state check in one transaction; over-refund rejected. |
| Webhook arriving before the synchronous response | Both map to the same idempotent transition; the state machine is the deduplication point. |
| Duplicate webhook delivery | Deduplicated by provider event reference. |
| Out-of-order webhooks (settled before captured) | Rejected as `PAYMENT_STATE_INVALID` and queued for reconciliation rather than applied out of order. |

### D.6 Unresolved

Provider, provider API, webhook signature scheme, whether webhooks exist at all, rate limits, and settlement timing are all **`UNKNOWN`** (`B-04`). The state machine is designed so that an adapter answering any subset of these questions can be substituted without changing the machine.

---

## E. Refund

Separate from the payment machine because `Prd_Maker.md` §27 requires distinct lifecycles for a refund and a partial refund, and because a refund is a financial action requiring its own authorization and audit.

### E.1 States

`REQUESTED` → `APPROVAL_PENDING` → `APPROVED` → `SUBMITTED` → `PARTIALLY_COMPLETED` → `COMPLETED`
Failure branches: `REJECTED` (not approved) · `FAILED` (provider rejected) · `CANCELLED` (withdrawn before submission) · `ON_HOLD` (suspected fraud or dispute)

Terminal: `COMPLETED`, `REJECTED`, `CANCELLED`.

### E.2 Rules

- A refund MUST reference the originating payment. A refund without a source payment is `REFUND_SOURCE_REQUIRED`.
- Partial refunds MAY sum to at most the captured amount; exceeding it is `REFUND_EXCEEDS_PAYMENT`.
- Refund approval thresholds are `TBD`.
- Refunds to a card require step-up authentication and an audit event (`ADR-0014`).
- A refund is a **compensating financial entry**, not a deletion of the original posting (`ADR-0009`).
- Refunds never remove the original charge from the record. A cancelled booking with a refund still shows the charge and the refund.

### E.3 Invalid transitions

`COMPLETED` → any: `REFUND_STATE_INVALID` (409) · submit without approval: `REFUND_NOT_APPROVED` (422) · exceed amount: `REFUND_EXCEEDS_PAYMENT` (422) · no reason code: `REFUND_REASON_REQUIRED` (422) · no source payment: `REFUND_SOURCE_REQUIRED` (422).

### E.4 Concurrency

Concurrent refunds against one payment are serialized by the payment's remaining refundable amount computed inside the transaction. Duplicate refund submission with the same key is idempotent.

---

## F. Folio

### F.1 States

`OPEN` → `SETTLING` → `SETTLED` (closed with zero balance) or `CLOSED_WITH_BALANCE` → `REOPENED` → `OPEN`

Terminal for a business date: `CLOSED`. `REOPENED` is not terminal; it is visible and audited.

### F.2 Rules

- A folio is opened **atomically with the check-in transition** (A-T8). A checked-in guest without a folio is a corrupt state.
- A folio is closed at check-out, at night audit, or on cancellation, depending on the business date policy (`TBD`).
- **The balance is derived from postings, never independently mutated.** Storing and mutating a separate balance creates drift between the two. The design must make divergence impossible, not merely unlikely.
- Postings are append-only. Corrections are compensating entries linked to the original (`ADR-0009`).
- A folio references exactly one business date at a time for its postings; a re-opened folio gains new postings against the reopened business date, and the original business date's figures remain intact.
- **Company and group folios are deferred** (`H-06`) and are explicitly **not** specified here. Their presence is a known gap, not an oversight.

### F.3 Invalid transitions

`SETTLED` → posting: `FOLIO_CLOSED` (409) · close with unposted charges: `FOLIO_HAS_UNPOSTED_CHARGES` (409) · reopen without authorization: `STEP_UP_REQUIRED` (403) · reopen of a business date not authorized: `BUSINESS_DATE_REOPEN_NOT_PERMITTED` (409).

### F.4 Concurrency

Postings and refunds racing on one folio are serialized by row-level locking of the folio aggregate within a short transaction. This is precisely the case where an append-only model with a derived balance is safer than a mutable balance field, because there is nothing to lose.

---

## G. Night Audit

Specified in full in `ADR-0018`. Summarised here.

### G.1 Run states

`NOT_STARTED` → `RUNNING` → `COMPLETED`
`RUNNING` → `PAUSED` → `RUNNING`
`RUNNING` → `FAILED`
`COMPLETED` → `REOPENED` → `RUNNING`

Terminal for a run: `COMPLETED`, `FAILED`. Terminal for a business date: `CLOSED`.

### G.2 Rules

- One run per property + business date. Never global.
- **Resumable, not restartable.** Every step is idempotent; re-running a completed step MUST NOT double-post revenue, double-generate an invoice, or advance a number sequence twice.
- **Fail-stop.** A failed step halts the run and requires manual intervention. It does not proceed to the next step. An ambiguous business date is the worst possible outcome and the design must make it impossible.
- Single-run enforcement is a database-level guarantee, not a scheduler assumption.
- The run records which steps completed, with timestamps and actor.

### G.3 Invalid transitions

`COMPLETED` → `RUNNING` without an authorized reopen: `BUSINESS_DATE_REOPEN_NOT_PERMITTED` (409) · two concurrent runs: `NIGHT_AUDIT_ALREADY_RUNNING` (409) · resume of a `NOT_STARTED` run: `NIGHT_AUDIT_NOT_RESUMABLE` (409) · step replay without override: `NIGHT_AUDIT_STEP_NOT_REPLAYABLE` (409).

### G.4 Concurrency

Concurrent runs for the same property + date are prevented by a database-level guarantee (test CON-05). Runs for different properties are independent — one property's failure MUST NOT block or corrupt another's. In-flight allocations, check-ins and payments for the same property are a documented precondition of each step (test CON-06).

### G.5 Unresolved

**Business date definition, cut-off time, same-day arrival handling, late-checkout policy, and the reopen policy are all `TBD` (`C-05`).** A 14:00 checkout convention is **not** assumed. The step order is therefore provisional.

---

## H. External Integration (submission lifecycle)

This machine governs **every outbound submission to an external system**: ZATCA/FATOORA and the payment provider. It is deliberately generic so that a machine does not have to be invented per provider.

### H.1 States

`PENDING` (transactionally written with the business operation) · `DISPATCHED` (worker has taken it) · `IN_FLIGHT` (request sent, awaiting response) · `ACKNOWLEDGED` (provider accepted) · `CONFIRMED` (provider validated and accepted the final state) · `REJECTED` (provider definitively rejected) · `RETRY_SCHEDULED` (transient failure) · `DEAD_LETTER` (retries exhausted) · `UNKNOWN_OUTCOME` · `RECONCILIATION_PENDING` · `RECONCILED` · `MANUAL_REVIEW`

### H.2 Rules

- **The submission record is created in the same database transaction as the business write.** This is the transactional outbox (`ADR-0005`). A business operation that commits MUST have a durable intent to submit, or the two can diverge unrecoverably.
- Retries are **bounded**, with exponential backoff **and jitter**, a defined maximum attempt count, and a defined set of retryable versus non-retryable error classes.
- An exhausted retry budget moves the submission to `DEAD_LETTER`. A dead letter MUST NOT be silently dropped (`Prd_Maker.md` §50.4).
- Dead letters are manually replayable, and the replay is itself audited.
- `UNKNOWN_OUTCOME` goes to reconciliation, never to blind retry.
- **Business state and compliance state are separate** (`G-7`). A confirmed reservation and a pending ZATCA submission are different facts and are tracked by different machines.
- Integration failure MUST NEVER take down the core PMS (`D-006`).

### H.3 Invalid transitions

`DEAD_LETTER` → `DISPATCHED` without an authorized manual replay: `MANUAL_REPLAY_NOT_AUTHORIZED` (403) · replay of a `CONFIRMED` submission: `SUBMISSION_ALREADY_CONFIRMED` (409) · retry of a `REJECTED` submission without a data correction: `SUBMISSION_REJECTED` (409) · out-of-order provider response: `SUBMISSION_STATE_INVALID` (409).

### H.4 Concurrency

Duplicate delivery of the same submission is prevented by the outbox's uniqueness guarantee. A provider callback racing the synchronous response converges on the same idempotent transition. Out-of-order callbacks are rejected and routed to reconciliation rather than applied.

### H.5 ZATCA-specific

**All ZATCA-specific behaviour is `UNKNOWN — requires confirmation from the authoritative source before production`** (`B-02`): endpoints, request and response schemas, certificate and CSID requirements, the TLV/QR binary structure and signing, UBL schema constraints, the onboarding procedure, error codes, rate limits, and sandbox availability.

The **organization's own wave, VAT threshold status, and current legal compliance status** are `REQUIRES CONFIRMATION BY THE ORGANIZATION'S AUTHORIZED TAX/COMPLIANCE REPRESENTATIVE` and are **not** inferred from the number of hotels or any revenue estimate (`B-01`).

What *is* decided: invoice numbering must be controlled and gapless within a defined sequence scope; every invoice and credit/debit note must be traceable to the originating folio and its transactions; a submission failure is visible, dead-lettered, and reconcilable.

---

## I. Channel Synchronization — DEFERRED (Phase C)

**No channel synchronization state machine is specified.**

Per `D-002`, the Channel Manager is Phase C and is implemented only after Phase A and Phase B pass their quality gates. Every partner's actual protocol — booking ingestion semantics, acknowledgement behaviour, modification and cancellation handling, rate and restriction mapping, retry expectations, reconciliation rules, virtual card handling, sync health definitions — is **`UNKNOWN`** and **must not be invented** (`Prd_Maker.md` §71).

What can be committed now is the **constraint**: the PMS remains the system of record for reservations and inventory. An OTA message is a **proposal to be validated and applied**, never a fact to be trusted. Channel synchronization will therefore reuse machine A (Reservation) and machine C (Inventory) as its target states, with the channel adapter acting only as a translation and mapping layer, plus an ingestion state machine of its own whose states remain `TBD` until a partner is contracted and its documentation is read.

**No partner-specific state, error code, or transition is defined in this document set.**

---

## J. Additional machines implied by the decisions

### J.1 Invoice

`DRAFT` → `ISSUED` → `SUBMISSION_PENDING` → `SUBMITTED` → `CONFIRMED` / `REJECTED` / `DEAD_LETTER` / `RECONCILIATION_PENDING`
Branches: `CANCELLED`, `SUPERSEDED`.

**Compliance state is separate from invoice state.** An invoice can be `ISSUED` (a real business and tax fact) while its compliance submission is `SUBMISSION_PENDING`. The invoice is not invalid merely because submission failed.

Numbering is **controlled and gapless within a defined sequence scope**. The scope is **`TBD`** pending `B-06` (one legal entity or several) — this is not a detail, because a group with multiple legal entities requires a separate sequence and a separate credential set per entity.

### J.2 Credit Note / Debit Note

`DRAFT` → `ISSUED` → same submission sub-machine as the invoice. MUST reference the originating invoice. Corrections are never made by editing an issued invoice (`ADR-0009`).

### J.3 Cashier Shift

`OPEN` → `RECONCILIATION_PENDING` → `CLOSED` · branches `CLOSED_WITH_VARIANCE` (requires Finance approval and audit) · `FORCED_CLOSE` (step-up + audit).

Shift open/close procedure, variance tolerance, and deposit-in-drawer handling are `TBD`.

### J.4 Housekeeping Task

`CREATED` → `ASSIGNED` → `IN_PROGRESS` → `COMPLETED` → `INSPECTED` · branches `REWORK_REQUIRED`, `CANCELLED`.

### J.5 User / Session

User: `INVITED` → `ACTIVE` → `SUSPENDED` → `DISABLED`. Session: `ACTIVE` → `IDLE_TIMEOUT` / `EXPIRED` / `REVOKED` / `LOCKED` (failed attempts). Session duration, idle timeout, and lockout thresholds are `TBD`.

### J.6 Guest Profile

`PROVISIONAL` (created during booking, not yet verified) → `VERIFIED` (checked in, identity captured) · `MERGED` (duplicate resolution). Merge rules are `TBD`.

---

## K. Machine index and gate status

| § | Machine | Phase | Specified | Invalid transitions | Concurrency | Unresolved policy |
|---|---|:-:|:-:|:-:|:-:|---|
| A | Reservation | A | ✔ | ✔ | ✔ | Yes |
| B | Room | A | ✔ | ✔ | ✔ | Yes |
| C | Inventory / Allocation | A | ✔ | ✔ | ✔ | Yes |
| D | Payment | A | ✔ | ✔ | ✔ | Provider `UNKNOWN` |
| E | Refund | A | ✔ | ✔ | ✔ | Yes |
| F | Folio | A | ✔ (guest folio) | ✔ | ✔ | Yes |
| G | Night Audit | A | ✔ | ✔ | ✔ | Yes (`C-05`) |
| H | External Integration | A | ✔ | ✔ | ✔ | Provider `UNKNOWN` |
| I | Channel synchronization | C | Deferred | Deferred | Deferred | All `TBD` |
| J.1 | Invoice | A | ✔ | partial | partial | Yes (`B-06`) |
| J.2 | Credit / Debit Note | A | ✔ | partial | partial | Yes |
| J.3 | Cashier Shift | A | ✔ | partial | — | Yes |
| J.4 | Housekeeping Task | A | ✔ | partial | — | Yes |
| J.5 | User / Session | A | ✔ | partial | — | Yes |
| J.6 | Guest Profile | A | ✔ | partial | — | Yes |

---

## L. Outstanding work on this document

Every state, transition, guard, and error code in machines A–H and J is a **specification**, not an implementation. Before it can be used as a test oracle it requires:

1. Business-rule confirmation for every `TBD` policy (blocking items `B-01`, `B-04`, `B-05`, `B-06`, `C-04`, `C-05`).
2. Translation into concrete acceptance criteria in `docs/PRD.md` §35.
3. Generation of the state-machine test matrix required by `ADR-0021` §3.
4. Review by hotel operations, finance, and compliance — a state machine that satisfies engineering can still be operationally wrong, and `Prd_Maker.md` §77 requires a competent operations and finance owner to be able to work from it.

**No state machine in this document may be implemented before its unresolved policies are answered**, because an invented default — a hold duration, a cut-off time, a no-show threshold — is indistinguishable from a deliberate decision once it is in code.
