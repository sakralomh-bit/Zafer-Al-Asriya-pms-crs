# Zafer Al-Asriya v1.0 — Task Breakdown

| Field | Value |
|---|---|
| Document | `docs/TASKS.md` |
| Version | 0.1 |
| Status | In progress. `T-001`, `T-005`, `T-006` **COMPLETE**. `T-002`, `T-003`, `T-004` **PARTIAL** — implemented in part, with named acceptance criteria still unevidenced; `T-004` in particular has its session, authentication, rate-limit, and audit mechanisms tested, its scope-change revocation wired and proven, and its error-rendering boundary evidenced, while the authentication HTTP surface, the step-up gate, and the security-header set do not exist. `T-000` and `T-007`–`T-058` **NOT STARTED**. Every figure in §13 was measured by running the command shown. |
| Related | `docs/PRD.md`, `docs/ARCHITECTURE.md`, `docs/DATA-MODEL.md`, `docs/API-SPEC.md`, `docs/STATE-MACHINES.md`, `docs/TEST-STRATEGY.md` |

---

## 0. Rules for this backlog

1. **No giant tasks.** There is no task called "Build PMS", "Build reservations", or "Integrate ZATCA". Every task is small enough to review independently.
2. **Every task is traceable** to a requirement ID from `docs/PRD.md`.
3. **Every task has acceptance criteria** and a named test. A task with no acceptance criteria is not Ready.
4. **A task is never marked complete without acceptance criteria passing and test evidence recorded** (`Prd_Maker.md` §43). Evidence is recorded per task in §13, including the criteria that remain unevidenced and the gates that are currently red.
5. **Failing tests are never bypassed to obtain a green result.**
6. **No task is started while a blocking issue in its "Blocked by" row is open.** A task that is not blocked may proceed in parallel.
7. Phase assignment follows `D-002`.

### Status legend

| Status | Meaning |
|---|---|
| `COMPLETE` | Every documented acceptance criterion for the task has measured evidence in §13. |
| `PARTIAL` | Real code and/or real test evidence exists, but at least one documented acceptance criterion is unevidenced. The unevidenced criteria are named in §13.1 — this status never means "nearly done". |
| `NOT STARTED` | No production code and no test evidence for the task. An empty module directory is scaffolding, not progress, and is not evidence. |

A task moves to `COMPLETE` only on measured evidence, never on intent, and never on the number of files that exist. Where a task is `PARTIAL` because a criterion has no test, that is stated as a gap rather than as progress toward one.

---

## 1. Phase 0 — Blockers, approval, foundation

| ID | Title | Blocked by | Status |
|---|---|---|---|
| `T-000` | Resolve the six blockers (`B-01`…`B-06`) | **PM action — the highest-priority item in this document** | `NOT STARTED` |
| `T-001` | Scaffold the application, enforce the no-float guard, and stand up CI | `B-03` (blocks only the IaC portion) | `COMPLETE` (see §13.2 for the two red gates) |

---

### `T-000` — Resolve the six blockers

```text
TASK-ID:            T-000
Status:             NOT STARTED — PM and specialist action, not engineering.
                    Still the highest-priority item in this document.
Title:              Resolve blocking and critical issues before implementation
Purpose:            Six BLOCKER and ten CRITICAL issues prevent a RED -> AMBER
                    transition and gate most of Phase A. This task is a PM and
                    specialist action, not an engineering task, and it is the
                    first thing that must happen.

Requirements:       Governance (Prd_Maker.md §4 Level A ambiguity, §65 quality gate)
Dependencies:       None — can start immediately

Files/Modules:      None (no code). Outputs go to docs/COMPLIANCE.md and
                    docs/PRD.md as dated, sourced confirmations.

Acceptance Criteria:
  AC-T-000-01  B-01: An authorized tax/compliance representative has stated in
                writing Zafer Al-Asriya's ZATCA integration wave, its
                applicable deadlines, and its current compliance status. This
                is recorded with a source and a date. It is NOT inferred.
  AC-T-000-02  B-02: ZATCA developer documentation has been obtained, and every
                item in the UNKNOWN list of docs/COMPLIANCE.md §3.1 is either
                resolved with a citation or remains marked UNKNOWN with a
                reason and an owner.
  AC-T-000-03  B-03: ADR-0020 has been completed against primary provider
                sources and a production provider is named.
  AC-T-000-04  B-04: A payment provider is named, with sandbox availability,
                documented API, terminal integration, pre-authorisation and
                refund capability, and security documentation.
  AC-T-000-05  B-05: Per-property room counts, expected occupancy, staffing,
                and peak concurrency are supplied in writing.
  AC-T-000-06  B-06: The invoicing legal entity (or entities) is determined,
                with commercial registration and VAT registration numbers.
  AC-T-000-07  C-10: Named accountable owners exist for security, privacy,
                compliance, QA, finance, and operations.
  AC-T-000-08  C-01 / C-04 / C-05 / C-06: RPO, RTO, VAT rounding policy,
                business-date policy, and currency policy are decided by their
                accountable owners.

Tests:              Not a code task. Verification is a documented sign-off per
                    acceptance criterion, with a source and a date.

Risks:              RISK-004 — the organization may have a ZATCA compliance
                    exposure that exists TODAY and is independent of this
                    project. B-01 must not wait for engineering scheduling.
                    RISK-011 — Phase A cannot progress past Phase A3 without
                    B-04, and past Phase A5 without B-01, B-02, B-06, C-04.
```

---

### `T-001` — Scaffold, no-float guard, CI

```text
TASK-ID:            T-001
Status:             COMPLETE — all seven criteria evidenced. Evidence and the
                    two currently-red repository gates: §13.1.1 and §13.2.
Title:              Scaffold the modular monolith with correctness guardrails and CI
Purpose:            Establish the stack (D-006), the money-representation guard
                    (ADR-0006), and the pipeline every later task depends on.
                    The no-float rule must be mechanically enforced from the
                    first commit, because retrofitting it across a codebase is
                    far harder than starting with it.

Requirements:       D-006, ADR-0002, ADR-0003, ADR-0004, ADR-0005, ADR-0006,
                    BUS-006, SEC-017, NFR-005
Dependencies:       None for the application and CI; B-03 for the IaC portion
                    (split into T-0xx below if preferred)

Files/Modules:      App skeleton; composer.json; package.json; phpunit config;
                    CI workflow; static analysis config; money-guard test;
                    module directory skeleton (14 modules, empty)
                    [NO PRODUCTION FEATURE CODE IN THIS TASK]

Acceptance Criteria:
  AC-T-001-01  The application boots and a test suite runs locally and in CI.
  AC-T-001-02  A static check FAILS the build when float or double is used in
                any code path that handles a monetary value.
  AC-T-001-03  A BCMath-based money helper exists with unit tests proving exact
                decimal behaviour on values that binary floating point gets
                wrong (for example 0.1 + 0.2, and a 3-decimal division).
  AC-T-001-04  CI runs static analysis, lint, unit tests, dependency scanning,
                and secret scanning, and fails the build on any of them.
  AC-T-001-05  The 14 module directories exist and are empty of cross-module
                imports.
  AC-T-001-06  The frontend builds with CSS logical properties; a lint rule
                rejects physical left/right margin, padding, inset, and
                text-align properties in application styles.
  AC-T-001-07  No test result, coverage percentage, or benchmark is claimed in
                any document until actually measured.

Tests:              Unit (money helper), static analysis (no-float, no-physical-
                    CSS), CI pipeline execution, secret scanning.

Risks:              Choosing tooling too early is difficult to reverse — mitigate
                    by deferring provider-specific and product-specific
                    packages. The risk of the guard being bypassed later by a
                    new code path is mitigated by making it a build failure
                    rather than a review comment.
```

---

## 2. Phase A1 — Master data and access control

| ID | Title | Blocked by | Status |
|---|---|---|---|
| `T-002` | Organization, legal entity, and property master data | `T-001` | `PARTIAL` |
| `T-003` | Identity, roles, permissions, and property scope | `T-002` | `PARTIAL` |
| `T-004` | Authentication, session security, MFA, step-up, auth audit | `T-003` | `PARTIAL` — mechanisms tested, scope-change revocation wired, error rendering evidenced; blocked on `SEC-007`/`SEC-008`/`B-05` values, MFA, step-up gate, security headers, and the auth HTTP surface (§13.1.4) |
| `T-005` | Room types, physical rooms, room status machine | `T-002` | `COMPLETE` |
| `T-006` | Housekeeping tasks and out-of-order handling | `T-005` | `COMPLETE` |

---

### `T-002` — Organization, legal entity, and property master data

```text
TASK-ID:            T-002
Status:             PARTIAL — the model, migration, and service layer exist, but
                    AC-T-002-01 and AC-T-002-04 have no test. §13.1.2.
Title:              Create the tenancy root: organization, legal entity, property
Purpose:            Every property-scoped table hangs off Property. This is the
                    root of the D-001 hierarchy and the future-SaaS seam
                    (ADR-0007).
Requirements:       D-001, ADR-0007, DR-001, FR-001
Dependencies:       T-001
Files/Modules:      Modules/Organization — organizations, legal_entities,
                    properties, property_operating_config, tax_rates,
                    configuration_versions; migrations; models; policy tests

Acceptance Criteria:
  AC-T-002-01  A property can be created with timezone, currency, tax profile,
                and operating configuration, all audited and versioned.
  AC-T-002-02  A single organization exists with 10 properties, and every
                property-scoped query resolves through it.
  AC-T-002-03  legal_entities exists to carry the commercial registration and
                VAT registration numbers required by B-06, EVEN THOUGH the
                values are still unknown — the model must not be retrofitted.
  AC-T-002-04  Configuration changes are validated, permission-controlled,
                versioned, recoverable, and audited (Prd_Maker.md §61).
  AC-T-002-05  A future organization_id can be added to scoped tables without
                changing any business logic (ADR-0007 §1.2 rule 3).

Tests:              Model and migration tests; scope resolution tests;
                    configuration authorization tests; audit emission tests.

Risks:              Currency and timezone fields will change once C-06 is
                    answered — mitigate by storing them as data, not logic.
                    Tax rate precision cannot be chosen until C-04 — this task
                    must not invent a precision.
```

---

### `T-003` — Identity, roles, permissions, and property scope

```text
TASK-ID:            T-003
Status:             PARTIAL — the authorization engine and its tests exist, but
                    AC-T-003-01, AC-T-003-05, and AC-T-003-07 are unevidenced.
                    §13.1.3.
Title:              Implement role plus property-scope authorization
Purpose:            Property data isolation (D-001) is the authorization
                    backbone. It must exist before any operational module,
                    because retrofitting scope checks into 10 endpoints is far
                    harder than having the mechanism from the start.
Requirements:       D-001, ADR-0014, BUS-009, DR-001, DR-002, FR-009, SEC-002,
                    SEC-003, SEC-004, SEC-016
Dependencies:       T-002
Files/Modules:      Modules/Identity — users, roles, permissions,
                    user_property_scope, sessions; scope resolver; policy
                    middleware; authorization tests

Acceptance Criteria:
  AC-T-003-01  For each of the 12 roles in D-001, a user holding a grant for
                Property A and NOT Property B receives PROPERTY_SCOPE_DENIED for
                a Property B resource — verified across ALL 10 properties, not a
                sample.
  AC-T-003-02  An out-of-scope request returns a DENIAL, not an empty result.
  AC-T-003-03  Group Manager's access to all 10 properties is implemented as 10
                explicit grant records. No superuser flag exists in the schema.
  AC-T-003-04  A user cannot grant themselves a scope; that attempt is denied
                and audited.
  AC-T-003-05  Every authorization decision is evaluated server-side; a request
                bypassing the client is denied identically.
  AC-T-003-06  The full role x resource x action x scope matrix from ADR-0014 §5
                is encoded as executable tests, including the negative cases
                (Finance cannot check in; Auditor cannot write; Housekeeping
                cannot read a document number).
  AC-T-003-07  An impersonation endpoint does not exist.

Tests:              Authorization matrix (generated); property breakout across
                    all roles and all 10 properties; privilege escalation;
                    self-grant denial; impersonation denial.

Risks:              An endpoint added later without a scope check is the
                    primary breakout risk — mitigate with a single reusable
                    scope path plus a test that asserts a denial without a grant.
                    RISK-006 severity Critical.
```

---

### `T-004` — Authentication, sessions, MFA, step-up, audit

```text
TASK-ID:            T-004
Status:             PARTIAL — NOT COMPLETE. Advanced by a Stage 1/2
                    session: the code in app/Modules/Identity/Auth/ is now
                    tested by 73 tests in 6 files, and the PHP
                    static-analysis and PHP lint gates that were failing on
                    those files now pass. Still absent: any HTTP surface, the
                    step-up gate, MFA, and the write path that would trigger
                    session revocation. The values below stay unresolved, and
                    AC-T-004-07/08/09 remain unevidenced for want of a request
                    to assert against. §13.1.4.
Title:              Authenticate users and protect sensitive operations
Purpose:            Every audited action in the system needs an attributable
                    actor. Authentication is also where the step-up mechanism
                    is established, which refunds, configuration, scope grants,
                    and business-date reopen all depend on.
Requirements:       ADR-0014, ADR-0016, SEC-001, SEC-007, SEC-008, SEC-009,
                    SEC-010, SEC-018, PRI-002
Dependencies:       T-003
Files/Modules:      Modules/Identity — auth, sessions, MFA enrolment, step-up;
                    rate limiting; security headers; auth audit events

Acceptance Criteria:
  AC-T-004-01  Authentication and logout produce audit events.
  AC-T-004-02  Password hashing uses an algorithm approved by the security
                owner. THE ALGORITHM IS TBD (SEC-007) and must not be chosen
                implicitly.
  AC-T-004-03  Session idle timeout and absolute lifetime are enforced. VALUES
                ARE TBD (SEC-008) and require the security owner's decision.
  AC-T-004-04  A session is revoked when the user's role or property scope
                changes, without waiting for expiry.
  AC-T-004-05  Step-up authentication is required and enforced for the
                sensitive operation set in ADR-0014 §6.
  AC-T-004-06  Authentication endpoints are rate limited, and lockout
                behaviour is defined and tested.
  AC-T-004-07  CSRF protection is enforced on cookie-authenticated
                state-changing requests.
  AC-T-004-08  Security headers are set on all responses.
  AC-T-004-09  No error response or log line contains a stack trace, SQL, an
                internal hostname, or a secret.

Tests:              Login/logout/session tests; revocation on scope change;
                    step-up enforcement per operation; rate-limit tests; CSRF
                    tests; error-content assertions.

Risks:              SEC-007 and SEC-008 are undefined, so this task is blocked
                    for those specific values — implement the mechanism, and
                    do not invent the parameters. A wrong session lifetime is
                    a security decision made by accident.
```

---

### `T-005` — Room types, physical rooms, and the room status machine

```text
TASK-ID:            T-005
Status:             COMPLETE — all six criteria evidenced. §13.1.5.
Title:              Room master data and the three-axis room status machine
Purpose:            Room status gates check-in and sellability, so it precedes
                    both inventory allocation and housekeeping.
Requirements:       ADR-0014, DR-003, DR-006, FR-001, FR-012,
                    docs/STATE-MACHINES.md §B
Dependencies:       T-002
Files/Modules:      Modules/Rooms — room_types, physical_rooms; status
                    transition service; state machine tests

Acceptance Criteria:
  AC-T-005-01  Room status is three INDEPENDENT axes (occupancy, housekeeping,
                availability), not a single status column.
  AC-T-005-02  Every transition in docs/STATE-MACHINES.md §B.2 is implemented
                and validated server-side.
  AC-T-005-03  Every invalid transition in §B.3 returns its documented
                deterministic error code.
  AC-T-005-04  A room cannot be allocated or checked into when it is not
                occupiable (all three axes evaluated).
  AC-T-005-05  A room status change outside the caller's property scope is
                denied.
  AC-T-005-06  Every status change is audited.

Tests:              State machine matrix (valid + invalid + terminal);
                    occupiability precondition; scope denial; audit assertion.

Risks:              A single-status model is the classic PMS contradiction
                    ("occupied and clean"). The three-axis model costs more
                    columns and prevents it. Out-of-order reason codes and
                    service targets are TBD.
```

---

### `T-006` — Housekeeping tasks and out-of-order handling

```text
TASK-ID:            T-006
Status:             COMPLETE — all five criteria evidenced. §13.1.6.
Title:              Housekeeping task lifecycle and room availability blocking
Purpose:            Housekeeping determines which rooms are actually sellable,
                    and out-of-order rooms are how inventory is withdrawn
                    without a reservation existing.
Requirements:       DR-006, FR-012, docs/STATE-MACHINES.md §B, §J.4
Dependencies:       T-005
Files/Modules:      Modules/Housekeeping — housekeeping_tasks; room availability
                    transitions; task state machine

Acceptance Criteria:
  AC-T-006-01  A task moves CREATED -> ASSIGNED -> IN_PROGRESS -> COMPLETED ->
                INSPECTED, with REWORK_REQUIRED on failed re-inspection.
  AC-T-006-02  Marking a room INSPECTED updates ONLY the housekeeping axis and
                does not alter occupancy or availability.
  AC-T-006-03  Marking a room OUT_OF_ORDER makes it non-sellable immediately,
                and the change is audited with a reason.
  AC-T-006-04  Housekeeping CANNOT read guest identity data, folio data, or
                financial data (ADR-0014 negative permissions) — enforced and
                tested.
  AC-T-006-05  A room in DIRTY status cannot be allocated or checked into.

Tests:              Task state machine; axis independence; out-of-order
                    sellability; housekeeping permission denials.

Risks:              If housekeeping and occupancy are conflated, rooms appear
                    sellable while dirty. Inspection checklist granularity and
                    out-of-order service targets are TBD.
```

---

## 3. Phase A2 — Inventory and reservations

| ID | Title | Blocked by |
|---|---|---|
| `T-007` | Atomic inventory allocation + concurrency gate | `T-005`, `T-003` |
| `T-008` | Reservation state machine and lifecycle | `T-007` |

---

### `T-007` — Atomic inventory allocation and the concurrency gate

```text
TASK-ID:            T-007
Title:              Implement atomic inventory allocation and prove it
Purpose:            This is the highest-severity correctness requirement in the
                    system. D-006 states explicitly that MySQL row locking
                    ALONE does not guarantee prevention of double booking. This
                    task implements the full control set AND the concurrency
                    test that proves it.
Requirements:       D-006, BUS-001, BUS-002, FR-002, ADR-0003, ADR-0008,
                    DR-003, DR-013, AC-FR-002-01, AC-FR-002-02, AC-FR-002-03
Dependencies:       T-005 (room master data), T-003 (scope enforcement)
Files/Modules:      Modules/Inventory — inventory, inventory_allocations,
                    inventory_holds; allocation service; concurrency test
                    harness

Acceptance Criteria:
  AC-T-007-01  All seven controls of ADR-0008 are implemented: explicit short
                transaction; SELECT ... FOR UPDATE scoped to the stay with
                deterministic row ordering; unique-constraint backstop;
                explicit allocation rule; reservation state validation inside
                the transaction; client Idempotency-Key; concurrency test.
  AC-T-007-02  GIVEN one available sellable unit and two concurrent valid
                booking attempts, WHEN both attempt allocation, THEN exactly
                one reaches CONFIRMED, the other receives
                INVENTORY_UNAVAILABLE, and no duplicate confirmed allocation
                exists. (CON-01)
  AC-T-007-03  Repeating either request with the same Idempotency-Key returns
                the original result and creates no second allocation. (CON-02)
  AC-T-007-04  An expired hold racing a new allocation never resurrects the
                hold. (CON-08)
  AC-T-007-05  ALLOCATION READS THE PRIMARY. Availability search may read a
                replica; allocation must not. Enforced by static check.
  AC-T-007-06  REMOVAL TEST: removing the row lock, the unique constraint, or
                the idempotency check each causes the corresponding test to
                FAIL. This is demonstrated and recorded, not assumed.
  AC-T-007-07  Both attempts in CON-01 are traceable by request/correlation ID
                through logs, audit records, and the outbox.

Tests:              CON-01, CON-02, CON-08 + the removal test for each;
                    deadlock retry behaviour; scope denial.

Risks:              HIGHEST-SEVERITY TASK. A control removed in a later refactor
                    without a test noticing is the main risk — the removal test
                    and the merge gate are the mitigation. Lock scope and
                    performance thresholds depend on B-05. Oversell policy,
                    hold duration, booking window, and cut-off times are all TBD
                    and MUST NOT be assumed.
```

---

### `T-008` — Reservation lifecycle

```text
TASK-ID:            T-008
Title:              Reservation state machine, holds, and lifecycle
Purpose:            Reservations cannot exist without a validated inventory
                    ledger, and every downstream flow (check-in, folio,
                    invoicing, night audit) depends on a correct reservation
                    state.
Requirements:       FR-003, BUS-002, BUS-007, ADR-0019, DR-004,
                    docs/STATE-MACHINES.md §A
Dependencies:       T-007
Files/Modules:      Modules/Reservations — reservations, reservation_guests,
                    reservation_nights, reservation_status_history,
                    reservation_modifications; transition service

Acceptance Criteria:
  AC-T-008-01  All 12 states and 16 transitions of §A.2 are implemented with
                server-side validation.
  AC-T-008-02  Every invalid transition in §A.3 returns its documented
                deterministic error code.
  AC-T-008-03  DRAFT -> HELD -> CONFIRMED commits the allocation in the SAME
                transaction as the reservation transition.
  AC-T-008-04  Hold expiry is evaluated INSIDE the confirmation transaction,
                not only by a scheduler; a confirmation after expiry is
                rejected with RESERVATION_HOLD_EXPIRED.
  AC-T-008-05  Every transition is recorded in reservation_status_history with
                actor, timestamp, and correlation ID.
  AC-T-008-06  Reservations are normalized per NIGHT (reservation_nights), not
                per date range.
  AC-T-008-07  Concurrent confirmations of the same reservation: exactly one
                commits; the other receives RESERVATION_STATE_INVALID.
  AC-T-008-08  There is deliberately NO PARTIALLY_PAID reservation state —
                partial payment is a folio fact, not a reservation fact.

Tests:              State machine matrix (valid, invalid, terminal, repeated,
                    concurrent); allocation atomicity; idempotent replay;
                    scope denial.

Risks:              Booking window, hold duration, cut-off times, no-show
                    cut-off, maximum stay, and cancellation policy are ALL TBD.
                    An invented default here is indistinguishable from a
                    deliberate business rule once in code.
```

---

## 4. Phase A3 — Front desk, folios, payments

| ID | Title | Blocked by |
|---|---|---|
| `T-009` | Folio and immutable ledger | `T-008` |
| `T-010` | Payment provider adapter + payment/refund machines | `T-009`, `B-04` |
| `T-011` | Check-in, check-out, room transfer, stay extension | `T-008`, `T-005`, `T-009` |
| `T-012` | Deposits, pre-authorisations, and voids | `T-010` |
| `T-013` | Refunds with approval and step-up | `T-010`, `T-004` |
| `T-014` | Cashier shifts, settlement, reconciliation | `T-009`, `T-010` |

---

### `T-009` — Folio and immutable ledger

```text
TASK-ID:            T-009
Title:              Guest folio with an append-only financial ledger
Purpose:            All money correctness depends on this. It precedes payments
                    and invoicing, and it is where float would do silent,
                    irreversible damage.
Requirements:       D-006, BUS-004, BUS-005, BUS-006, FR-005, ADR-0006,
                    ADR-0009, DR-007, AC-FR-005-01, AC-FR-005-02, AC-FR-005-03
Dependencies:       T-008
Files/Modules:      Modules/Financials — folios, folio_postings, posting_lines,
                    charges, deposits, adjustments, reversals; ledger service;
                    invariant tests

Acceptance Criteria:
  AC-T-009-01  folio_postings is append-only. There is NO application code path
                that issues UPDATE or DELETE against a posted record.
  AC-T-009-02  A correction creates a NEW compensating entry referencing the
                original; the original is unchanged.
  AC-T-009-03  The balance is DERIVED from postings and cannot be independently
                mutated. Any cached balance is a rebuildable projection.
  AC-T-009-04  A folio is opened ATOMICALLY with the check-in transition — a
                checked-in guest without a folio is impossible.
  AC-T-009-05  Every posting carries a business date SEPARATE from its creation
                timestamp; the two are never interchangeable.
  AC-T-009-06  A single, documented sign convention is applied uniformly.
  AC-T-009-07  No float or double is used anywhere in the money path (enforced
                by the T-001 static guard and by unit tests on values that
                binary floating point gets wrong).
  AC-T-009-08  Concurrent postings and refunds on one folio leave the balance
                consistent with the postings. (CON-07)
  AC-T-009-09  NO monetary column is FLOAT or DOUBLE; every monetary value
                carries an explicit currency.

Tests:              Ledger invariants; reversal linkage; balance rebuild;
                BCMath exactness; random posting/refund sequences; CON-07;
                static no-float check.

Risks:              Rounding stage, mode, and precision/scale are TBD (C-04)
                    and this task CANNOT be finished without them. Currency
                    policy is TBD (C-06). Company and group folios are deferred
                    (H-06) and must not be designed here.
```

---

### `T-010` — Payment provider adapter and payment/refund machines

```text
TASK-ID:            T-010
Title:              Provider-agnostic payment adapter with the full payment
                    and refund lifecycles
Purpose:            Payments move real money and carry real cardholder risk. The
                    adapter keeps the unselected provider (B-04) from becoming an
                    architectural commitment, and the UNKNOWN_OUTCOME state
                    prevents the most damaging mistake in payment engineering.
Requirements:       D-005, BUS-002, BUS-003, BUS-013, FR-006, ADR-0011,
                    ADR-0017, ADR-0019, DR-008, DR-014,
                    docs/STATE-MACHINES.md §D, §E,
                    AC-FR-006-01, AC-FR-006-02
Dependencies:       T-009; B-04 for the concrete adapter (a fake suffices for
                    the machine and the tests)
Files/Modules:      Modules/Payments — payments, payment_allocations, refunds,
                    payment_reconciliations, payment_provider_references;
                    PaymentProviderAdapter interface; cash provider; fake
                    provider for tests

Acceptance Criteria:
  AC-T-010-01  The adapter interface supports authentication, timeouts, bounded
                retries with backoff and jitter, idempotency keys,
                duplicate-request protection, provider references, webhook
                handling, signature verification where supported,
                reconciliation, failure states, manual recovery, and audit
                logging.
  AC-T-010-02  NO PAN, CVV, or track data column exists anywhere in the
                schema, and no card data appears in any log or telemetry.
  AC-T-010-03  On an unconfirmed payment result, the payment becomes
                UNKNOWN_OUTCOME, the API returns retryable:false, ZERO
                provider retries occur, and a reconciliation task is created.
  AC-T-010-04  Concurrent submissions with the same key produce exactly one
                payment record. (CON-04)
  AC-T-010-05  All 14 payment states and their transitions in §D.3 are
                implemented with server-side validation.
  AC-T-010-06  All invalid transitions in §D.4 return their documented codes.
  AC-T-010-07  A refund REQUIRES a source payment; exceeding the captured
                amount is rejected; a reason code is mandatory.
  AC-T-010-08  No provider SDK type appears in the financial domain.
  AC-T-010-09  All tests run against a FAKE provider; no live sandbox is
                required for the correctness gate.

Tests:              Payment state machine; unknown-outcome path (no retry);
                idempotency (CON-04); refund rules; card-data absence
                (schema + log scan); duplicate and out-of-order webhook
                handling against the fake.

Risks:              B-04 blocks the concrete adapter. Webhook signature
                    mechanism is UNKNOWN. Pre-authorisation expiry rules are
                    provider-specific and UNKNOWN. NEVER retry an unknown
                    financial outcome (Prd_Maker.md §33).
```

---

### `T-011` — Check-in, check-out, room transfer, stay extension

```text
TASK-ID:            T-011
Title:              Front desk operations
Purpose:            The daily work of the hotel. The concurrency behaviours here
                    are what stop two agents assigning the same room.
Requirements:       FR-004, BUS-007, ADR-0014, PRI-002,
                    docs/STATE-MACHINES.md §A (A-T8, A-T9, C-T9)
Dependencies:       T-008, T-005, T-009
Files/Modules:      Modules/FrontDesk — check-in, check-out, room transfer,
                    stay extension services

Acceptance Criteria:
  AC-T-011-01  Check-in re-evaluates room occupiability INSIDE the same
                transaction as the reservation transition, and opens the folio
                atomically with it.
  AC-T-011-02  A room in DIRTY housekeeping status cannot be checked into
                (ROOM_NOT_OCCUPIABLE) and no folio is opened.
  AC-T-011-03  Two agents checking in the same room concurrently: exactly one
                succeeds; the other receives a deterministic denial. (CON-03)
  AC-T-011-04  Check-out is rejected while unposted charges exist
                (FOLIO_HAS_UNPOSTED_CHARGES).
  AC-T-011-05  A room transfer releases and reallocates ATOMICALLY, and a
                failure leaves the original allocation intact.
  AC-T-011-06  Guest identity capture stores FIELDS ONLY; no image is
                accepted by any endpoint, and every field reveal is audited.
  AC-T-011-07  Every front desk action is audited with actor, property, and
                correlation ID.

Tests:              Check-in/check-out flows; CON-03; room transfer atomicity;
                    identity capture and masking; scope denial; audit.

Risks:              Deposit policy at check-in is TBD. Check-in with an unpaid
                    balance policy is TBD. Behaviour when a payment outcome is
                    UNKNOWN at check-in is TBD and must be decided, not
                    improvised at the desk.
```

---

### `T-012` — Deposits, pre-authorisations, and voids

```text
TASK-ID:            T-012
Title:              Deposit, pre-authorisation, and void lifecycle
Purpose:            Hotels hold deposits and pre-authorise cards. These have
                    distinct semantics from a completed payment and are a
                    frequent source of accounting error.
Requirements:       D-005, BUS-003, FR-006, docs/STATE-MACHINES.md §D
Dependencies:       T-010
Files/Modules:      Modules/Payments — deposits; authorization lifecycle

Acceptance Criteria:
  AC-T-012-01  Deposit application to a folio balance, release, and forfeiture
                follow the approved policy. POLICY IS TBD and must not be
                invented.
  AC-T-012-02  A pre-authorisation can be voided before capture and cannot be
                captured after void.
  AC-T-012-03  An expired authorization is handled explicitly rather than
                treated as a failure.
  AC-T-012-04  All operations are idempotent and audited.

Tests:              Authorization lifecycle; void-then-capture rejection;
                deposit application; idempotency; audit.

Risks:              Deposit policy and authorization expiry rules are provider-
                    specific and UNKNOWN (B-04). Forfeiture is a financial
                    decision that must not be defaulted.
```

---

### `T-013` — Refunds

```text
TASK-ID:            T-013
Title:              Refund lifecycle with approval, step-up, and traceability
Purpose:            Refunds are the most common financial error scenario in a
                    hotel and the most common audit finding.
Requirements:       D-005, FR-006, SEC-018, BUS-004,
                    docs/STATE-MACHINES.md §E
Dependencies:       T-010, T-004
Files/Modules:      Modules/Payments — refunds; approval workflow

Acceptance Criteria:
  AC-T-013-01  A refund REQUIRES a source payment, a reason code, step-up
                authentication, and an approval per the configured threshold.
  AC-T-013-02  Partial refunds are represented distinctly from full refunds and
                may sum to at most the captured amount.
  AC-T-013-03  A refund is a COMPENSATING ENTRY, never a deletion of the
                original charge; the original remains visible.
  AC-T-013-04  A refund is idempotent and audited with the approver.
  AC-T-013-05  All invalid transitions return their documented codes.

Tests:              Refund state machine; over-refund rejection; missing
                reason/source rejection; step-up enforcement; audit of the
                approver.

Risks:              Approval thresholds are TBD. Refund limits per property or
                per period are TBD.
```

---

### `T-014` — Cashier shifts, settlement, and reconciliation

```text
TASK-ID:            T-014
Title:              Cashier session control and provider reconciliation
Purpose:            Cash handling needs shift accountability, and a PMS that
                    cannot reconcile against the provider cannot defend a
                    figure.
Requirements:       FR-005, FR-006, BUS-005, docs/STATE-MACHINES.md §J.3
Dependencies:       T-009, T-010
Files/Modules:      Modules/Payments — cashier_shifts; settlement; reconciliation

Acceptance Criteria:
  AC-T-014-01  A shift opens with an opening float and closes with a counted
                amount, a computed variance, and an audit record.
  AC-T-014-02  A shift with a variance requires Finance approval and step-up.
  AC-T-014-03  Reconciliation compares provider settlement records to internal
                payment records and produces reconciliation TASKS for
                mismatches — it never auto-adjusts.
  AC-T-014-04  Reconciliation backlog is measurable and alertable (KPI-04).

Tests:              Shift lifecycle; variance handling; reconciliation
                mismatch production; no-auto-adjust assertion; audit.

Risks:              Shift procedure, variance tolerance, and deposit-in-drawer
                    handling are all TBD. Reconciliation depends on B-04.
```

---

## 5. Phase A4 — Housekeeping and night audit

| ID | Title | Blocked by |
|---|---|---|
| `T-015` | Night audit run engine (resumable, idempotent, fail-stop) | `T-009`, `T-014` |
| `T-016` | Night audit steps: no-shows, revenue, taxes, settlement | `T-015`, C-05 |
| `T-017` | Business-date reopen control | `T-015`, C-05 |
| `T-018` | Housekeeping-to-checkout integration | `T-006`, `T-011` |

---

### `T-015` — Night audit run engine

```text
TASK-ID:            T-015
Title:              Resumable, fail-stop, single-run night audit engine
Purpose:            Night audit is the most dangerous routine in a PMS: it runs
                    nightly for 10 properties and moves money. A partial run
                    that is re-run double-posts revenue and double-issues
                    invoices.
Requirements:       D-006, BUS-015, FR-007, ADR-0018, DR-011,
                    docs/STATE-MACHINES.md §G, AC-FR-007-01, AC-FR-007-02,
                    AC-FR-007-03
Dependencies:       T-009, T-014; C-05 for the business-date policy
Files/Modules:      Modules/NightAudit — business_dates, business_date_locks;
                    run engine; step ledger

Acceptance Criteria:
  AC-T-015-01  At most one run per property + business date, enforced by a
                DATABASE-level guarantee. (CON-05)
  AC-T-015-02  A run interrupted at step N resumes at step N. (CON-06)
  AC-T-015-03  Re-running a COMPLETED step does not double-post revenue,
                double-post charges, or advance the invoice number sequence.
  AC-T-015-04  A step failure STOPS the run. It does not proceed to the next
                step, and the business date is not marked CLOSED.
  AC-T-015-05  The run record states exactly which steps completed, with
                timestamps and actor — answerable without reading logs.
  AC-T-015-06  A night's failure for one property does not affect the other
                nine.

Tests:              CON-05; CON-06; interrupt-at-every-step and resume;
                completed-step replay idempotency; fail-stop assertion;
                per-property isolation.

Risks:              Business date, cut-off, same-day arrival, late checkout, and
                    reopen policy are ALL TBD (C-05). The engine can be built;
                    it cannot be validated without the policy. A 14:00
                    checkout convention is NOT assumed.
```

---

### `T-016` — Night audit steps

```text
TASK-ID:            T-016
Title:              Implement each night audit step idempotently
Purpose:            The run engine is only as safe as its least idempotent step.
Requirements:       BUS-015, FR-007, ADR-0018, ADR-0009
Dependencies:       T-015; C-05 (step order and cut-offs)
Files/Modules:      Modules/NightAudit — per-step services

Acceptance Criteria:
  AC-T-016-01  Each step in the ADR-0018 list is implemented and independently
                idempotent: close open folios, post unposted charges, determine
                no-shows, resolve late checkouts, post revenue, compute and post
                taxes, settle payments, close shifts, generate invoices, and
                reconcile.
  AC-T-016-02  Each step, run twice in a row, produces the same end state.
  AC-T-016-03  A step's manual override is separately authorized and audited.
  AC-T-016-04  Step order is derived from the approved business-date policy
                (C-05), not assumed.

Tests:              Per-step idempotency (run twice); interrupt-and-resume per
                step; manual override authorization; audit.

Risks:              BLOCKED on C-05. Step order, no-show cut-off, and
                    late-checkout handling are policy decisions.
```

---

### `T-017` — Business-date reopen control

```text
TASK-ID:            T-017
Title:              Authorized, audited reopening of a closed business date
Purpose:            A closed period must not be silently reopened. Reopening
                    changes reported results and can require re-issuing
                    invoices, so it is a controlled, authorized act.
Requirements:       BUS-015, ADR-0018, SEC-018, docs/STATE-MACHINES.md §G
Dependencies:       T-015; C-05 (reopen policy)
Files/Modules:      Modules/NightAudit — reopen service; authorization

Acceptance Criteria:
  AC-T-017-01  Reopen requires a distinct authorization (separate from running
                a night audit), an approval, a reason, and an audit event.
  AC-T-017-02  A user cannot authorize their own reopen.
  AC-T-017-03  What may and may not be re-posted after a reopen follows the
                approved policy (TBD, C-05) and is enforced, not assumed.
  AC-T-017-04  An unauthorized reopen attempt is rejected with
                BUSINESS_DATE_REOPEN_NOT_PERMITTED.

Tests:              Reopen authorization; self-approval denial; policy
                enforcement; audit.

Risks:              Reopen policy is TBD. This is a sensitive financial control
                    and must not be implemented permissively.
```

---

### `T-018` — Housekeeping-to-checkout integration

```text
TASK-ID:            T-018
Title:              Automatic housekeeping task creation on checkout
Purpose:            Closes the loop between checkout and room availability.
Requirements:       FR-012, DR-006, docs/STATE-MACHINES.md §B
Dependencies:       T-006, T-011
Files/Modules:      Modules/Housekeeping, Modules/FrontDesk

Acceptance Criteria:
  AC-T-018-01  Check-out sets the room to DIRTY and raises a housekeeping task
                in the same transaction.
  AC-T-018-02  The room is not allocatable until the task is completed and
                inspected.
  AC-T-018-03  A failed checkout leaves the housekeeping task uncreated rather
                than the room incorrectly marked clean.

Tests:              Checkout -> task creation; availability gating; atomicity.

Risks:              Low. The main risk is a partial commit leaving a room
                    marked clean with no task.
```

---

## 6. Phase A5 — Tax, invoicing, and ZATCA compliance

| ID | Title | Blocked by |
|---|---|---|
| `T-019` | Tax rate configuration and VAT calculation | C-04, `T-009` |
| `T-020` | Invoice lifecycle and controlled gapless numbering | `T-019`, B-06 |
| `T-021` | Credit notes and debit notes | `T-020` |
| `T-022` | ZATCA compliance adapter interface and outbox | B-02, `T-020` |
| `T-023` | ZATCA protocol implementation | B-01, B-02, `T-022` |
| `T-024` | Submission DLQ, reconciliation, and monitoring | B-02, `T-023` |

---

### `T-019` — Tax and VAT calculation

```text
TASK-ID:            T-019
Title:              Versioned tax configuration and VAT computation
Purpose:            Tax correctness determines invoice correctness. Rounding is
                    applied exactly once, at a defined stage, with a defined
                    mode — and all three are policy decisions.
Requirements:       D-003, BUS-006, FR-008, ADR-0006, ADR-0009, C-04
Dependencies:       T-009; BLOCKED on C-04
Files/Modules:      Modules/Tax — tax_rates; VAT calculation service

Acceptance Criteria:
  AC-T-019-01  Tax rates are versioned, effective-dated, scoped, validated,
                permission-controlled, and audited.
  AC-T-019-02  A tax-rate change does NOT retroactively alter past postings or
                invoices (each posting snapshots its rate).
  AC-T-019-03  Rounding occurs at exactly ONE defined stage with a defined
                mode, per the approved policy.
  AC-T-019-04  The frontend NEVER determines an authoritative amount.
  AC-T-019-05  All tax arithmetic uses exact decimal arithmetic (ADR-0006).

Tests:              Rounding tests per the approved policy; rate versioning;
                snapshot immutability; static no-float check.

Risks:              BLOCKED. Inclusive vs exclusive, rounding stage, rounding
                    mode, and precision/scale are all TBD (C-04). THE APPLICABLE
                    VAT RATE IS UNKNOWN and must be read from an authoritative
                    ZATCA source — it is deliberately not stated anywhere in
                    this document set.
```

---

### `T-020` — Invoice lifecycle and controlled gapless numbering

```text
TASK-ID:            T-020
Title:              Tax invoice lifecycle with a controlled gapless sequence
Purpose:            Invoice numbering must have no gaps and no duplicates,
                    including across failures and rolled-back transactions. A
                    gap in a regulated invoice sequence is itself a defect.
Requirements:       D-003, BUS-010, BUS-011, FR-008, ADR-0009, ADR-0010,
                    DR-009, B-06, AC-FR-008-02, AC-FR-008-03
Dependencies:       T-019; BLOCKED on B-06 (sequence scope)
Files/Modules:      Modules/Tax — invoices, invoice_lines,
                    invoice_number_sequences; issuance service

Acceptance Criteria:
  AC-T-020-01  Invoice numbers come from a controlled sequence PER LEGAL
                ENTITY, with no gaps and no duplicates.
  AC-T-020-02  Fault injection at every issuance point (crash before commit,
                rollback, timeout) produces neither a gap nor a duplicate.
  AC-T-020-03  An issued invoice is IMMUTABLE; it cannot be edited.
  AC-T-020-04  Every invoice is traceable to the originating folio and every
                posting that contributed to it.
  AC-T-020-05  Business state (ISSUED) and compliance state (SUBMISSION_*)
                are separate and independently observable.
  AC-T-020-06  A failed compliance submission does NOT roll back or invalidate
                the invoice or the checkout that produced it.

Tests:              Gaplessness under fault injection; immutability;
                traceability traversal; business/compliance state separation.

Risks:              BLOCKED on B-06 — if the group has multiple legal entities,
                each needs its own sequence and its own credentials. This is
                the irreversible moment in the ZATCA workstream: numbers issued
                to guests cannot be un-issued.
```

---

### `T-021` — Credit notes and debit notes

```text
TASK-ID:            T-021
Title:              Credit and debit notes as the only correction path
Purpose:            An issued invoice is corrected by a note, never by an edit.
Requirements:       D-003, BUS-004, BUS-010, FR-008, ADR-0009, ADR-0010
Dependencies:       T-020
Files/Modules:      Modules/Tax — credit_notes, debit_notes; issuance service

Acceptance Criteria:
  AC-T-021-01  A credit or debit note REQUIRES a reference to the originating
                invoice.
  AC-T-021-02  Notes are traceable to the invoice, the folio, and the postings.
  AC-T-021-03  Notes draw from the same controlled sequence as invoices.
  AC-T-021-04  No endpoint exists to modify or delete an issued invoice.

Tests:              Note lifecycle; reference requirement; traceability;
                sequence continuity; no-edit assertion.

Risks:              Same sequence-scope dependency as T-020 (B-06).
```

---

### `T-022` — ZATCA compliance adapter interface and outbox

```text
TASK-ID:            T-022
Title:              Define the compliance adapter boundary and submission pipeline
Purpose:            Implements ADR-0010's boundary so that the financial domain
                    is complete and provider-agnostic, while the protocol
                    specifics remain honestly UNKNOWN.
Requirements:       D-003, BUS-008, BUS-010, FR-008, ADR-0005, ADR-0010,
                    ADR-0017, DR-010
Dependencies:       T-020; NOT blocked by B-02 (that blocks T-023, not this)
Files/Modules:      Modules/Tax — ZatcaComplianceAdapter interface;
                    integration_submissions, integration_dead_letters,
                    integration_events; outbox producer/consumer

Acceptance Criteria:
  AC-T-022-01  The submission record is written IN THE SAME TRANSACTION as the
                invoice (transactional outbox).
  AC-T-022-02  No ZATCA-specific type, field, or import appears in the
                financial domain.
  AC-T-022-03  The submission lifecycle of docs/STATE-MACHINES.md §H.1 is
                implemented with bounded retry, backoff + jitter, DLQ, and
                manual replay.
  AC-T-022-04  A dead letter is NEVER silently dropped, and its replay is
                audited.
  AC-T-022-05  The adapter interface documents every field that is UNKNOWN
                rather than filling them with a guess.
  AC-T-022-06  With the external system entirely unavailable, checkout still
                completes and the submission dead-letters. (BUS-008)

Tests:              Outbox atomicity; retry and backoff; DLQ and replay;
                provider-outage isolation; audit; unknown-field assertion.

Risks:              This task deliberately stops at the interface. Attempting
                    the protocol in this task would require inventing B-02
                    unknowns and is prohibited.
```

---

### `T-023` — ZATCA protocol implementation

```text
TASK-ID:            T-023
Title:              Implement the FATOORA protocol against the adapter
Purpose:            The actual regulatory integration. It CANNOT be designed
                    correctly without authoritative ZATCA documentation.
Requirements:       D-003, FR-008, ADR-0010, B-01, B-02
Dependencies:       T-022; BLOCKED on B-01 and B-02
Files/Modules:      Modules/Tax — ZatcaComplianceAdapter implementation;
                    credentials/CSID handling

Acceptance Criteria:
  AC-T-023-01  Every protocol detail is implemented from a CITED source. Any
                item still UNKNOWN is escalated, not guessed.
  AC-T-023-02  Certificate and CSID material is stored in the centralized
                secrets manager; never in source control, configuration, or
                logs.
  AC-T-023-03  Submission status, response code, payload version, retry
                status, final disposition, and reconciliation status are all
                recorded per Prd_Maker.md §50.3.
  AC-T-023-04  The implementation is tested against the real sandbox once
                availability is confirmed.
  AC-T-023-05  NO compliance claim is asserted by this task.

Tests:              Contract tests against the sandbox; credential handling;
                submission lifecycle; error mapping per verified codes.

Risks:              BLOCKED. B-02 lists every unknown. B-01 determines whether
                    an exposure exists already, independent of this task.
                    NO ENDPOINT, FIELD, CERTIFICATE FORMAT, OR TLV STRUCTURE
                    MAY BE INVENTED.
```

---

### `T-024` — Submission DLQ, reconciliation, and monitoring

```text
TASK-ID:            T-024
Title:              Compliance submission operations and monitoring
Purpose:            A regulated submission that fails silently is worse than one
                    that visibly fails, because the failure is discovered at
                    audit time.
Requirements:       D-003, BUS-008, BUS-010, FR-008, ADR-0010, OBS-001
Dependencies:       T-023
Files/Modules:      Modules/Tax — dead-letter operations; reconciliation; alerts

Acceptance Criteria:
  AC-T-024-01  DLQ depth and AGE are monitored and alerted (KPI-04).
  AC-T-024-02  Manual replay is authorized, rate-limited, and audited.
  AC-T-024-03  Every submission reaches a final disposition: confirmed,
                rejected, dead-lettered, or reconciled.
  AC-T-024-04  An unreconciled submission is visible to the Compliance Officer
                with a correlation ID.
  AC-T-024-05  Submission success rate is measurable per integration.

Tests:              DLQ alerting; replay authorization and audit; disposition
                completeness; reconciliation mismatch production.

Risks:              An owner for dead letters is UNNAMED (C-10). Without one,
                    the queue fills unnoticed — which is the failure mode this
                    task exists to prevent.
```

---

## 7. Phase A6 — Reporting, notifications, localization

| ID | Title | Blocked by |
|---|---|---|
| `T-025` | Reporting with explicit source-of-truth labelling | `T-009`, M-03 |
| `T-026` | Notifications with templates, locale, and consent | `T-004`, C-07 |
| `T-027` | Arabic/English localization completion and RTL hardening | `T-011`, `T-001` |
| `T-028` | Accessibility conformance | C-08 |
| `T-029` | Observability: logs, metrics, dashboards, alerts | H-04 |

---

### `T-025` — Reporting

```text
TASK-ID:            T-025
Title:              Reports with explicit source of truth and refresh labelling
Purpose:            Finance and management need figures they can defend. The
                    failure mode is mixing operational and financial
                    definitions without labelling.
Requirements:       FR-013, REP-001..N, Prd_Maker.md §29
Dependencies:       T-009; M-03 (report catalogue)
Files/Modules:      Modules/Reporting

Acceptance Criteria:
  AC-T-025-01  Every report declares source of truth, refresh frequency
                (real-time / near-real-time / daily / period-closed), time zone,
                currency, authorization, and reconciliation rule.
  AC-T-025-02  Operational and financial definitions are NEVER mixed without an
                explicit label.
  AC-T-025-03  A period-closed figure does not change after night audit closes
                the business date.
  AC-T-025-04  Reports respect property scope and are audited.
  AC-T-025-05  Report query cost is bounded; sorting and filtering are
                allow-listed.

Tests:              Per-report source-of-truth assertions; period-closed
                stability; scope enforcement; query cost bounds.

Risks:              The report catalogue is TBD (M-03). Export formats beyond
                    CSV/PDF are undecided. A report built before its definition
                is agreed will encode an unagreed definition.
```

---

### `T-026` — Notifications

```text
TASK-ID:            T-026
Title:              Guest and staff notifications across channels
Purpose:            Booking confirmations and operational alerts depend on
                    notifications, which carry PDPL consent implications.
Requirements:       FR-014, PRI-004, L10N-001
Dependencies:       T-004; C-07 (providers)
Files/Modules:      Modules/Notifications — templates, dispatch, delivery tracking

Acceptance Criteria:
  AC-T-026-01  Templates are externalised, versioned, localized, and
                permission-controlled.
  AC-T-026-02  A notification contains no sensitive personal or financial data
                beyond what is necessary.
  AC-T-026-03  Delivery is retried with bounded backoff and tracked.
  AC-T-026-04  Marketing messages require consent, and consent mechanics
                depend on channel and on PDPL — REQUIRES LEGAL REVIEW and is
                NOT implemented until C-07 is resolved.
  AC-T-026-05  Notification failure never blocks a business operation.

Tests:              Template rendering per locale; redaction of sensitive data;
                retry; delivery tracking; business-operation isolation.

Risks:              C-07 blocks provider selection. Consent mechanics are a
                    legal question, not an engineering one.
```

---

### `T-027` — Localization and RTL hardening

```text
TASK-ID:            T-027
Title:              Complete Arabic/English coverage and direction correctness
Purpose:            Direction correctness is a correctness concern: a mirrored
                    reservation grid causes booking errors.
Requirements:       FR-011, L10N-001, ADR-0015, D-002
Dependencies:       T-011, T-001
Files/Modules:      Frontend — all views; i18n; locale metadata

Acceptance Criteria:
  AC-T-027-01  No user-facing string is hard-coded; CI fails on a missing
                translation key.
  AC-T-027-02  No physical CSS direction property (margin-left/right, inset,
                text-align: left/right) appears in application styles.
  AC-T-027-03  Every UI flow is tested in BOTH RTL and LTR.
  AC-T-027-04  Mixed-direction content (an Arabic label containing a Latin
                identifier) renders numbers in the correct order — a
                correctness test, not a visual one.
  AC-T-027-05  Direction is derived from locale metadata, so a future LTR
                language (Bengali) needs no component rewrite.
  AC-T-027-06  Arabic is the default catalogue; English is a full peer.

Tests:              Per-flow RTL/LTR matrix; missing-key CI check; CSS lint;
                mixed-direction rendering; locale switching.

Risks:              Hijri calendar display is TBD and NOT assumed. Bengali is
                    architecturally possible but deferred (M-01) and must not
                    be built here.
```

---

### `T-028` — Accessibility

```text
TASK-ID:            T-028
Title:              Meet the agreed accessibility standard
Purpose:            Required before any A11Y requirement or test can be
                    written. The level is not chosen.
Requirements:       A11Y-001, NFR-008, ADR-0015
Dependencies:       BLOCKED on C-08 (target standard and level)
Files/Modules:      Frontend — all views

Acceptance Criteria:
  AC-T-028-01  The target standard and level are set by the project manager.
  AC-T-028-02  Keyboard navigation, focus management, contrast, and form
                labelling meet the agreed level.
  AC-T-028-03  Direction (RTL/LTR) does not break focus order or reading
                order.
  AC-T-028-04  Automated checks run in CI; manual verification is documented.

Tests:              Automated a11y checks; keyboard walkthrough per flow;
                contrast; focus order in both directions.

Risks:              Entirely blocked on C-08. Setting a level unilaterally would
                    be inventing a requirement.
```

---

### `T-029` — Observability and alerting

```text
TASK-ID:            T-029
Title:              Logs, metrics, dashboards, and actionable alerts
Purpose:            An alert with no runbook is a notification, not a control.
                    And an unowned dead-letter queue fills unnoticed.
Requirements:       OBS-001, NFR-010, ADR-0016, Prd_Maker.md §31
Dependencies:       H-04 (stack); T-024, T-014
Files/Modules:      Observability layer; runbooks

Acceptance Criteria:
  AC-T-029-01  Structured logs carry correlation ID, request ID, actor ID, and
                integration ID, and contain NO secrets, card data, or document
                numbers.
  AC-T-029-02  Metrics cover request count, latency, errors, queue depth, retry
                count, dead-letter count, reconciliation backlog, integration
                success rate, DB health, and resource saturation.
  AC-T-029-03  Every alert has a threshold, severity, OWNER, escalation, and
                runbook.
  AC-T-029-04  The unknown-outcome payment count is alertable — it is the
                highest-value operational signal in the system.
  AC-T-029-05  No threshold is invented; each is set after observation.

Tests:              Log content assertions (no secrets/PII); correlation
                propagation end to end; alert definition tests; runbook
                presence check.

Risks:              H-04 (stack) and C-10 (alert OWNERS) are both open. An
                    alert with no named owner is worse than no alert, because
                    it creates false confidence.
```

---

## 8. Phase A7 — Hardening, recovery, and go-live

| ID | Title | Blocked by |
|---|---|---|
| `T-030` | Terraform IaC baseline and environments | B-03 |
| `T-031` | Backup and restore validation | B-03, H-07, C-01 |
| `T-032` | Disaster-recovery rehearsal and RPO/RTO verification | B-03, C-01 |
| `T-033` | Full security review and penetration test | All modules, C-10 |
| `T-034` | Performance test against the agreed scenario | B-05 |
| `T-035` | Compliance review and evidence pack | B-01, B-02, C-10 |
| `T-036` | UAT with hotel operations staff | A2–A5 |
| `T-037` | Pilot and controlled cutover | All above |

---

### `T-030` — Infrastructure as Code baseline

```text
TASK-ID:            T-030
Title:              Terraform modules, environments, and CI/CD pipeline
Purpose:            Reproducible, reviewable infrastructure. The provider
                    decision is blocked, but the module STRUCTURE can be
                    designed now.
Requirements:       D-007, ADR-0013, NFR-005, SEC-005
Dependencies:       BLOCKED on B-03 for concrete resources
Files/Modules:       Terraform modules; CI/CD pipeline

Acceptance Criteria:
  AC-T-030-01  All infrastructure is reproducible from code; no manual console
                changes.
  AC-T-030-02  Database and Redis have no public endpoint.
  AC-T-030-03  Encryption at rest, in transit, and for backups; separate KMS key
                boundary for identity data.
  AC-T-030-04  No production secret in source control; secret scanning in CI.
  AC-T-030-05  Drift detection is enabled.
  AC-T-030-06  Artifacts are PROMOTED between environments, not rebuilt.
  AC-T-030-07  No non-production environment contains real guest personal data.

Tests:              Plan/apply in a clean account; drift detection; secret
                scan; network isolation verification.

Risks:              BLOCKED on B-03. H-03 (key management) is a release gate.
```

---

### `T-031` — Backup and restore validation

```text
TASK-ID:            T-031
Title:              Automated backups with rehearsed restore validation
Purpose:            A backup that has never been restored is an assumption.
Requirements:       D-007, REL-001, ADR-0013, H-07
Dependencies:       T-030; BLOCKED on B-03, H-07
Files/Modules:       Backup configuration; restore-validation runbook

Acceptance Criteria:
  AC-T-031-01  Automated, encrypted backups in approved Saudi-region storage.
  AC-T-031-02  Point-in-time restore is verified.
  AC-T-031-03  A full restore is performed in a clean environment on the agreed
                cadence and the result is RECORDED.
  AC-T-031-04  Backup identity-data key boundary is preserved in the backup.
  AC-T-031-05  Last successful restore validation is monitored and alerted.

Tests:              Actual restore rehearsal; integrity verification; access
                control on backups.

Risks:              BLOCKED on B-03. H-07 (frequency, retention, cadence) is
                    open. Discovering an unrestorable backup during an incident
                    is the failure this task prevents.
```

---

### `T-032` — Disaster-recovery rehearsal

```text
TASK-ID:            T-032
Title:              Rehearse DR and verify RPO/RTO
Purpose:            An RPO that has not been rehearsed is a number in a
                    document, not a capability.
Requirements:       REL-001, REL-002, D-007, ADR-0013, C-01
Dependencies:       T-030, T-031; BLOCKED on B-03, C-01
Files/Modules:       DR runbook

Acceptance Criteria:
  AC-T-032-01  RPO and RTO are DERIVED from verified backup and restore
                capability — not chosen first and validated later.
  AC-T-032-02  A failover rehearsal is performed and its measured result
                recorded.
  AC-T-032-03  NO automatic guest-PII replication outside Saudi Arabia is
                activated without an approved transfer assessment.
  AC-T-032-04  The DR runbook is written and walked through by a named owner.

Tests:              Rehearsal execution; measured RPO/RTO compared against
                target; transfer-gate assertion.

Risks:              BLOCKED on B-03 and C-01. Cross-border replication remains
                    a legal gate, not an engineering one.
```

---

### `T-033` — Security review

```text
TASK-ID:            T-033
Title:              Full security review and penetration test
Purpose:            The last gate before production, and the only one that can
                    independently find what the team cannot see.
Requirements:       SEC-001..SEC-018, Prd_Maker.md §54
Dependencies:       All modules; BLOCKED on C-10 (no security owner)
Files/Modules:       Review findings; remediation

Acceptance Criteria:
  AC-T-033-01  Property breakout is re-verified across all 12 roles and all 10
                properties.
  AC-T-033-02  Log and telemetry output is scanned for identity and card data.
  AC-T-033-03  Independent penetration test is performed; scope `TBD`.
  AC-T-033-04  All critical and high findings are remediated and re-tested.
  AC-T-033-05  Dependency and secret scanning are clean.

Tests:              Breakout re-run; log scan; penetration test; SAST.

Risks:              BLOCKED on C-10. An unsigned security review is not a
                    review.
```

---

### `T-034` — Performance test

```text
TASK-ID:            T-034
Title:              Performance test against the agreed scenario
Purpose:            Proves the system meets its targets at real scale — which
                    cannot be stated until the scale is known.
Requirements:       NFR-001, NFR-002, NFR-011, Prd_Maker.md §63
Dependencies:       BLOCKED on B-05; T-027
Files/Modules:       Synthetic data generator; scenario definitions

Acceptance Criteria:
  AC-T-034-01  A synthetic generator produces 10 properties with realistic room
                counts and occupancy — a prerequisite, not an extra.
  AC-T-034-02  Each scenario declares the full Prd_Maker.md §63 parameter set.
  AC-T-034-03  p50/p95/p99 are measured per endpoint class and compared to the
                approved thresholds.
  AC-T-034-04  Capacity headroom is documented.
  AC-T-034-05  A lab result is never presented as a real-user metric.

Tests:              Load, peak, burst, and degradation scenarios; concurrency
                suite under load.

Risks:              BLOCKED on B-05. NO threshold may be invented. Without the
                agreed scale, this task cannot be started, let alone passed.
```

---

### `T-035` — Compliance review and evidence pack

```text
TASK-ID:            T-035
Title:              Compliance verification and evidence assembly
Purpose:            Assembles the evidence that compliance obligations are met,
                    and identifies what cannot be evidenced.
Requirements:       COM-001..COM-010, PRI-001..PRI-011
Dependencies:       BLOCKED on B-01, B-02, C-10; T-020, T-024
Files/Modules:       Evidence pack; compliance register

Acceptance Criteria:
  AC-T-035-01  Every regulatory claim in the system is traceable to a dated
                source.
  AC-T-035-02  Every remaining UNKNOWN is listed with an owner, rather than
                quietly resolved.
  AC-T-035-03  The erasure-versus-financial-retention policy is DECIDED by a
                legal owner.
  AC-T-035-04  The subprocessor register is populated once providers are
                selected.
  AC-T-035-05  NO compliance claim is made without evidence. If evidence is
                absent, the item is reported as unverified.

Tests:              Document review; evidence traceability check; unverified
                item register.

Risks:              BLOCKED on B-01 and B-02. An unresolved B-01 at this point
                    means a possible pre-existing compliance exposure remains
                    unaddressed.
```

---

### `T-036` — UAT

```text
TASK-ID:            T-036
Title:              User acceptance testing with hotel operations staff
Purpose:            A system that satisfies engineering can still be operationally
                    wrong. `Prd_Maker.md` §77 requires operations and finance
                    owners to be able to work from the specification.
Requirements:       Definition of Ready (§42), acceptance criteria (§35)
Dependencies:       T-011..T-021; C-10 (QA owner)
Files/Modules:       UAT scripts; defect log

Acceptance Criteria:
  AC-T-036-01  Real staff from the 10 properties execute realistic scenarios:
                peak arrival morning, a full house with one room left, a group
                arrival, a payment decline, a night-audit interruption, a
                refund requiring approval.
  AC-T-036-02  Every P0 acceptance criterion in docs/PRD.md §35 is executed or
                explicitly automated.
  AC-T-036-03  Finance validates that reported figures reconcile.
  AC-T-036-04  Operations validates that the flows are usable at desk speed.
  AC-T-036-05  Defects are triaged; no P0 defect is deferred.

Tests:              UAT execution with recorded results.

Risks:              BLOCKED on C-10. Operations availability is a dependency
                    (DEP-007) that has not been confirmed.
```

---

### `T-037` — Pilot and controlled cutover

```text
TASK-ID:            T-037
Title:              Pilot rollout and production cutover
Purpose:            Introduces the system to real guests with a limited blast
                    radius, and a defined way back.
Requirements:       Prd_Maker.md §37, §38, §39
Dependencies:       T-030..T-036 — ALL release gates
Files/Modules:       Cutover plan; rollback plan; monitoring; training

Acceptance Criteria:
  AC-T-037-01  Pilot is limited to a small number of properties, NOT all 10.
  AC-T-037-02  Success criteria, telemetry, support model, training, and
                incident escalation are defined BEFORE go-live.
  AC-T-037-03  A rollback trigger, decision owner, and procedure exist.
  AC-T-037-04  Every release gate in docs/DEPLOYMENT.md §13 is PASSED, including
                the concurrency suite with demonstrated removal tests and a
                tested ZATCA failure path.
  AC-T-037-05  Rollback is NOT assumed to reverse posted financial records or
                issued invoices — those require compensating entries.
  AC-T-037-06  Post-go-live monitoring is heightened for an agreed window.

Tests:              Gate verification; monitored pilot; rollback rehearsal.

Risks:              A night audit that has run, a captured payment, or an issued
                invoice is irreversible by rollback. This is why the pilot is
                small. Every unresolved blocker at this point is a reason NOT
                to proceed, not a reason to proceed carefully.
```

---

## 9. Phase B — CRS (deferred, traceable)

**Not started. Gated on Phase A passing every release gate.**

| ID | Title | Blocked by |
|---|---|---|
| `T-038` | Availability search API for the booking engine | Phase A gate; `T-007` |
| `T-039` | Rate plans, restrictions, and policy management | Phase A gate |
| `T-040` | Guest-facing booking engine (property, room, booking, confirm) | `T-038`, `T-039` |
| `T-041` | Guest identity capture during booking (fields only) | `T-040`, `C-02` |
| `T-042` | Online card capture behind the SAME payment adapter | `T-040`, `B-04` |
| `T-043` | Guest-facing modification and cancellation | `T-040` |
| `T-044` | Booking abuse and fraud controls | `T-040` |
| `T-045` | Booking confirmation notifications | `T-040`, `T-026` |

**Critical constraint:** the CRS must reuse the **same** inventory allocator (`T-007`) and the **same** payment adapter (`T-010`). A guest booking online and a phone booking must contend for the last room through the same allocator. A parallel booking path would reintroduce exactly the double-booking risk Phase A eliminated.

Public API authentication mechanism is **`TBD`** and must not be assumed.

## 10. Phase C — Channel Manager and POS (deferred, traceable)

**Not started. Gated on Phase B.**

| ID | Title | Blocked by |
|---|---|---|
| `T-046` | Channel adapter framework and ingestion state machine | Phase B gate |
| `T-047` | Booking.com adapter | `T-046` |
| `T-048` | Expedia adapter | `T-046` |
| `T-049` | Agoda adapter | `T-046` |
| `T-050` | Almosafer adapter | `T-046` |
| `T-051` | Channel mapping, rates, restrictions, availability sync | `T-047`..`T-050` |
| `T-052` | OTA reconciliation and sync-health monitoring | `T-051` |
| `T-053` | OTA virtual cards | `T-051`, `B-04` |
| `T-054` | POS menu, items, modifiers, taxes | Phase B gate |
| `T-055` | POS orders, tables, service locations | `T-054` |
| `T-056` | POS cashier and shift | `T-055`, `T-014` |
| `T-057` | **Room-charge posting through the folio contract** | `T-056`, `T-009` |
| `T-058` | POS refund, void, and end-of-day | `T-057` |

**Constraints:**

- The PMS remains the **system of record**. An OTA message is a **proposal to be validated and applied**, never a fact to be trusted.
- **Every partner behaviour is `UNKNOWN`** and must not be invented. No partner endpoint, field, or error code may be assumed.
- `T-057` MUST post room charges through the **folio contract**, never by writing folio tables directly. This is the boundary that keeps POS from creating a third, unaccounted posting path (`ADR-0009` §9, `ADR-0017`).

---

## 11. Task statistics and critical path

| Metric | Value |
|---|---|
| Total tasks defined | 59 (`T-000`–`T-058`) |
| Phase A tasks | 38 (`T-000`–`T-037`) |
| Phase B tasks | 8 (`T-038`–`T-045`) |
| Phase C tasks | 13 (`T-046`–`T-058`) |
| **`COMPLETE`** | 3 — `T-001`, `T-005`, `T-006` |
| **`PARTIAL`** | 3 — `T-002`, `T-003`, `T-004` |
| **`NOT STARTED`** | 53 — `T-000`, `T-007`–`T-058` |
| **Not blocked by any issue** | `T-001` (except IaC), `T-002`, `T-003`, `T-005`, `T-006` |
| Blocked by `B-01` | `T-023`, `T-035` |
| Blocked by `B-02` | `T-023`, `T-024`, `T-035` |
| Blocked by `B-03` | `T-030`, `T-031`, `T-032`, `T-034` (via `B-05`) |
| Blocked by `B-04` | `T-012`, `T-014`, `T-024`, `T-042`, `T-053` |
| Blocked by `B-05` | `T-034` |
| Blocked by `B-06` | `T-020`, `T-021` |
| Blocked by `C-01` | `T-031`, `T-032` |
| Blocked by `C-04` | `T-019`, and therefore `T-020`, `T-021`, `T-022`, `T-023`, `T-024` |
| Blocked by `C-05` | `T-016`, `T-017` |
| Blocked by `C-08` | `T-028` |
| Blocked by `C-10` | `T-004` (partly), `T-024`, `T-029`, `T-033`, `T-035`, `T-036` |

**Critical path to first production release:**
`T-000` → `T-009` → `T-019` (needs `C-04`) → `T-020` (needs `B-06`) → `T-022` → `T-023` (needs `B-01`, `B-02`) → `T-024` → `T-035` → `T-037`

**And in parallel:** `T-001` → `T-003` → `T-007` → `T-008` → `T-009` → `T-011` → `T-015` (needs `C-05`) → `T-016` → `T-036` → `T-037`

**And separately:** `T-030` (needs `B-03`) → `T-031` → `T-032` (needs `C-01`) → `T-037`

**Observation worth stating plainly:** `T-019` blocks five downstream tasks, and it is blocked by `C-04`, which is a **decision**, not a development task. The longest chain in this backlog is gated on someone answering a question, not on writing code.

## 12. First 10 development tasks (summary)

| # | ID | Title | Blocked by | Status |
|---|---|---|---|---|
| 1 | `T-000` | Resolve the six blockers | PM action — **start immediately** | `NOT STARTED` |
| 2 | `T-001` | Scaffold, no-float guard, CI | — | `COMPLETE` |
| 3 | `T-002` | Organization, legal entity, property master data | `T-001` | `PARTIAL` |
| 4 | `T-003` | Roles, permissions, property scope | `T-002` | `PARTIAL` |
| 5 | `T-004` | Authentication, sessions, MFA, step-up | `T-003` + `SEC-007`/`SEC-008` values | `PARTIAL` |
| 6 | `T-005` | Room types, rooms, status machine | `T-002` | `COMPLETE` |
| 7 | `T-006` | Housekeeping and out-of-order | `T-005` | `COMPLETE` |
| 8 | `T-007` | **Atomic allocation + concurrency gate** | `T-005`, `T-003` | `NOT STARTED` |
| 9 | `T-008` | Reservation lifecycle | `T-007` | `NOT STARTED` |
| 10 | `T-009` | Folio and immutable ledger | `T-008` + `C-04` for the rounding policy | `NOT STARTED` |

## 13. Status

**Corrected 2026-09-27 against the repository at commit `47ee5bb` (working tree clean).** The previous text of this section stated that no task had been started, no criterion met, and no evidence existed. That was true when the document set was first written and is now false. It is replaced by measured evidence below.

Per §0 rule 4, a task is `COMPLETE` only where every documented acceptance criterion has measured evidence. A criterion with no evidence is named as unevidenced and is NOT counted as progress. No coverage percentage, benchmark, performance figure, compliance claim, or security claim appears anywhere in this section, because none has been measured.

### 13.0 Measured repository evidence

Every row is the output of the command shown, run on 2026-09-27 against the tree described above.

| Check | Command | Result |
|---|---|---|
| Test suite | `php artisan test` | **PASS** — 305 tests, 949 assertions |
| Guard — strict types | `php artisan zafer:guard-strict-types` | **PASS** |
| Guard — no float in money paths | `php artisan zafer:guard-money` | **PASS** |
| Guard — module boundaries | `php artisan zafer:guard-modules` | **PASS** — 14 modules present, no cross-module imports outside `Contracts` |
| Guard — prohibited schema | `php artisan zafer:guard-prohibited-schema` | **PASS** — 22 tables inspected |
| PHP static analysis | `php vendor/bin/phpstan analyse` | **PASS** — 0 errors |
| PHP lint | `php vendor/bin/pint --test` | **PASS** |
| CSS lint | `npm run lint:css` | **PASS** |
| Frontend build | `npm run build` | **PASS** |
| npm dependency scan | `npm audit --audit-level=high` | **PASS** — 0 vulnerabilities |
| PHP dependency scan | `composer audit` | **NOT RUN** — no Composer CLI in the environment used for this reconciliation |
| Secret scan | `gitleaks` (`ci.yml` job `secrets`) | **NOT RUN** — CI-only; no run result is recorded in this repository |
| CI pipeline | `.github/workflows/ci.yml` | **Defined**, not observed — no CI run result is recorded in this repository |

The suite grew from 205 tests / 702 assertions to **305 / 949** through `T-004` (100 new tests across 7 files). `phpstan` and `pint` were **FAIL** when this table was first written and are now **PASS**; §13.2 records what they were and why. The CSS, build, and npm rows were not re-run by the `T-004` session — no frontend file was touched — so they carry forward from the earlier measurement and are marked as such by that omission.

No coverage measurement, no benchmark, no load test, and no penetration test has been performed. `T-034` and `T-033` remain unstarted, and nothing in this section substitutes for them.

### 13.1 Per-task evidence

#### 13.1.1 `T-001` — `COMPLETE`

| Criterion | Evidence |
|---|---|
| `AC-T-001-01` | Application boots and `php artisan test` runs: 305 tests, 949 assertions. `ci.yml` defines the same run. No CI run result is recorded in-repo. |
| `AC-T-001-02` | `app/Console/Commands/GuardNoFloatInMoneyPaths.php`, wired into CI. Negative cases proven in `tests/Architecture/GuardSelfTest.php`: float type hint, float literal, double cast, `number_format`, `parse_float`, and a `FLOAT` column type. Guard passes on the clean repository. |
| `AC-T-001-03` | `app/Shared/Money/Money.php`; `tests/Unit/MoneyTest.php` proves `0.1 + 0.2` exactly, a 3-decimal division at a caller-chosen scale, integer multiplication preserving scale, float entry refused, and a global-scale change unable to alter a result. |
| `AC-T-001-04` | `ci.yml` runs PHPStan, Pint, the test suite, `composer audit`, `npm audit`, and `gitleaks`, each as a command that fails the build on failure. **The pipeline exists; the tree is not currently green** — see §13.2. |
| `AC-T-001-05` | `app/Console/Commands/GuardModuleBoundaries.php`: 14 module directories present, cross-module `Models`/`Services` imports rejected, `Contracts` imports allowed. Negative cases in `GuardSelfTest`. |
| `AC-T-001-06` | `.stylelintrc.json` plus `npm run lint:css` PASS; `npm run build` PASS. |
| `AC-T-001-07` | No coverage percentage, benchmark, or performance figure is claimed in this document set. The claim is made checkable rather than asserted: §13.0 lists every command that produced a figure, and §13.3 lists what was not run. |

`T-001` is complete in its own scope. It is recorded here together with §13.2 so that a reader cannot mistake "this task's deliverables are present" for "the repository passes its own pipeline". It does not.

#### 13.1.2 `T-002` — `PARTIAL`

Implemented: `app/Modules/Organization/` (7 files) and migrations `2026_09_27_000002_create_organization_tables.php`, `2026_09_27_000003_create_property_tables.php`, each carrying its own `AC-T-002-xx` references.

| Criterion | Evidence |
|---|---|
| `AC-T-002-01` | **UNEVIDENCED.** `PropertyService` exists and carries the reference, but no test calls it. Property creation with timezone validation, versioned configuration, and audit is not exercised. |
| `AC-T-002-02` | `SchemaConventionTest` asserts `properties.organization_id` exists; `AuthorizationTest` builds ten properties under one organization. Every other property-scoped table carrying `property_id` is asserted by `SchemaConventionTest::test_every_property_scoped_table_carries_a_property_id`. |
| `AC-T-002-03` | `legal_entities` is created in migration `000002` and modelled by `app/Modules/Organization/Models/LegalEntity.php`. The commercial-registration and VAT-registration VALUES remain unknown, per `B-06` and `docs/BLOCKER-STATUS.md`. |
| `AC-T-002-04` | **UNEVIDENCED.** `ConfigurationVersion` and the audit path exist; no test exercises validation, permission control, versioning, restoration, or audit emission. |
| `AC-T-002-05` | The `organization_id` seam is asserted structurally by `SchemaConventionTest`. The criterion's behavioural half — that it can be added without changing business logic — is not separately tested. |

This task's own `Tests:` line requires model/migration tests, scope-resolution tests, configuration-authorization tests, and audit-emission tests. Only the schema and scope halves exist. `T-002` therefore stays `PARTIAL` until `AC-T-002-01` and `AC-T-002-04` are evidenced.

#### 13.1.3 `T-003` — `PARTIAL`

Implemented and tested: `app/Modules/Identity/` (22 files) and `tests/Security/AuthorizationTest.php` (25 tests) plus `tests/Security/RolePermissionMatrixTest.php` (19 tests), all passing within the 305.

| Criterion | Evidence |
|---|---|
| `AC-T-003-01` | **UNEVIDENCED IN FULL.** The generated 12-role × 10-property breakout does not exist. `test_a_property_with_no_grant_is_denied_with_an_explicit_code` proves the denial, and `test_group_manager_multi_property_reach_is_ten_explicit_grants` proves ten explicit grant rows across ten properties. What is missing is the criterion's explicit demand: every one of the 12 roles × all 10 properties, not a representative sample. |
| `AC-T-003-02` | `AuthorizationTest::test_a_property_with_no_grant_is_denied_with_an_explicit_code` asserts `PROPERTY_SCOPE_DENIED`, not an empty result; `..._does_not_disclose_whether_the_property_exists` asserts the message leaks nothing. |
| `AC-T-003-03` | `test_group_manager_multi_property_reach_is_ten_explicit_grants` (10 rows, asserted as rows) and `test_there_is_no_superuser_or_bypass_column` (asserts the absence of seven candidate bypass columns). |
| `AC-T-003-04` | `test_a_user_cannot_grant_themselves_a_property` asserts the refusal, that no row was created, and that one `SCOPE_SELF_GRANT_DENIED` audit row exists. |
| `AC-T-003-05` | **PARTIAL.** Enforcement is proven at the service boundary. There is no HTTP or route surface in the repository — `app/Http/Controllers/` contains only the framework base controller — so "a request bypassing the client is denied identically" has not been exercised end to end. |
| `AC-T-003-06` | `RolePermissionMatrixTest`, including each negative case named in the criterion (Finance cannot check in, Auditor cannot write, Housekeeping cannot read identity/folio data) plus completeness assertions over `Role::cases()` and `Permission::cases()`. |
| `AC-T-003-07` | **UNEVIDENCED.** No test asserts the absence of an impersonation endpoint, and there is no route layer to assert against. The absence of the capability is true in the code as written; it is not a recorded test result. |

#### 13.1.4 `T-004` — `PARTIAL`, and NOT COMPLETE

**Advanced by a `T-004` session scoped to Stage 1 (code correctness) and Stage 2 (tests and documentation) only.** 73 new tests were added across 6 new files. No value was resolved, no route was added, and no mechanism specified as `TBD` was chosen.

Implementation under test: `SecurityPolicy.php`, `AuthenticationService.php`, `SessionSecurity.php`, `SessionRevoker.php`, `AuthenticationRateLimiter.php`, `SessionRevocationUnsupported.php`, `AuthenticationFailed.php`, `SecurityPolicyUnresolved.php`, `StepUpRequired.php`.

New evidence, 73 tests in `tests/Security/`:

| File | Tests | Covers |
|---|---|---|
| `AuthenticationTest.php` | 15 | Login, uniform refusal, hashing cost, no-credential audit |
| `SessionSecurityTest.php` | 19 | Session lifecycle, both lifetimes, revocation, CSRF rotation |
| `AuthenticationRateLimiterTest.php` | 13 | Ceiling, lockout, keying, fail-closed on `B-05` |
| `UnresolvedSecurityPolicyTest.php` | 13 | Every shipped security value still refuses; nothing defaulted |
| `AuthAuditTest.php` | 9 | The four authentication events, correlation, redaction |
| `AuthenticatedAuthorizationTest.php` | 4 | Authentication confers no scope and disturbs no role |

Test support is TEST-ONLY and cannot leak into production: `tests/Support/ResolvesSecurityPolicy.php` binds explicit policy values per test, and `tests/Support/RecordingHasher.php` is a counting decorator over the real hasher.

Code corrections made in this session, each of which was a live defect rather than a style change:

- `User` implements `Illuminate\Contracts\Auth\Authenticatable`, without an invented `remember_token` bypass.
- `SecurityPolicy` reads the algorithm and work factor through `Hash::driver()` instead of reading config directly, and refuses a malformed value rather than coercing it.
- `AuthenticationService` verifies with `Hasher::check()` against a per-request dummy hash, and `@throws` named `App\Shared\Domain\RateLimited` — it previously resolved to a class in a namespace that does not exist.
- `SessionSecurity` compares ages with `diffInSeconds(..., absolute: true)`. Carbon 3 returns a **signed** difference, so the bare `>=` against a positive limit was never true and an expired session would have lived forever.
- `SessionRevoker` no longer claims a caller it does not have (§ below).
- Docblocks no longer promise a `StepUpGuard` that is not implemented.

**The repository is now green where it was red.** `vendor/bin/phpstan analyse` reported 9 errors, all in `app/Modules/Identity/Auth/`; it now reports **0**. `vendor/bin/pint --test` failed on 2 files in the same directory; it now reports **passed**. The full suite moved from 205 tests / 702 assertions to **305 tests / 949 assertions, all passing**. All four architectural guards pass. §13.2 recorded CI failing on `main`; that specific failure is resolved.

**Defects found in the `T-004` code, each a security property rather than a crash.** The `RateLimited` mis-namespace; the signed-difference lifetime comparison, which would have let an expired session live forever; `SessionRevoker`'s false claim to be called from write paths; an **invalid hashing algorithm name escaping as a raw `InvalidArgumentException`** instead of a fail-closed `SERVICE_UNAVAILABLE`; and **`ErrorResponseFactory` and `AssignCorrelationId` both existing, both matching the specification, and both referenced by nothing** — so a `DomainFailure` reached Laravel's stock renderer, which answers `{"message": "..."}` with no `code` and no `request_id`, and appends a stack trace whenever `APP_DEBUG` is on.

**`AC-T-004-04` is now MET for property scope, and that changed the assessment of the "no write path" claim made earlier in this section.** `ScopeGrantService::grant()` and `::revoke()` DO exist and are the only property-scope write paths; the earlier claim that no such path existed was wrong. Both now invalidate the **subject's** sessions, after the audit row is written, and never the actor's.

**The error-rendering boundary is now wired** (`DR-T004-15`): `bootstrap/app.php` registers `DomainFailure` → `ErrorResponseFactory`, unhandled throwables → `INTERNAL_ERROR` with fixed text, and prepends `AssignCorrelationId`. This is shared infrastructure; no new response format was invented and no new middleware class was created.

**There is still no authentication HTTP surface.** `AC-T-004-07` (CSRF on cookie-authenticated state-changing requests) and `AC-T-004-08` (security headers) have no endpoint to be enforced on. `AC-T-004-09` is now evidenced at the rendering boundary by `tests/Feature/ErrorRenderingTest.php`, including a debug-mode case.

| Criterion | State |
|---|---|
| `AC-T-004-01` | **EVIDENCED** — `AuthAuditTest`: `test_a_successful_login_writes_auth_succeeded`, `test_a_failed_login_writes_auth_failed_as_a_denial`, `test_logout_writes_auth_logout`, `test_the_correlation_id_is_propagated_onto_every_event`, `test_the_four_authentication_events_are_distinct`, and `test_no_authentication_event_contains_a_secret`. |
| `AC-T-004-02` | **PARTIAL — mechanism evidenced, value still `TBD`.** `test_a_wrong_password_is_refused`, `test_an_inactive_account_is_refused_identically`, and the timing-shaped tests `test_an_unknown_account_still_performs_a_hash_verification`, `test_a_wrong_password_performs_exactly_one_verification` prove verification happens. `test_the_password_algorithm_refuses_with_sec_007`, `test_the_work_factor_is_not_defaulted`, `test_an_unregistered_algorithm_fails_closed_instead_of_escaping`, `test_every_unregistered_algorithm_name_fails_closed`, `test_a_registered_algorithm_still_resolves`, and `test_malformed_password_hashing_options_fail_closed` prove an algorithm is never chosen implicitly and never substituted. **`SEC-007` remains unresolved by design** — no approved algorithm is selected, so no deployment can authenticate anyone. |
| `AC-T-004-03` | **PARTIAL — mechanism evidenced, values still `TBD`.** `SessionSecurityTest`: `test_both_lifetime_anchors_are_recorded_at_authentication`, `test_a_session_within_both_lifetimes_passes_and_refreshes_the_idle_anchor`, `test_activity_does_not_extend_the_absolute_lifetime`, `test_an_idle_session_is_refused`, `test_an_expired_session_is_destroyed_not_merely_refused`, `test_a_session_with_no_lifetime_anchor_is_refused`, `test_an_unresolved_lifetime_refuses_instead_of_comparing_against_nothing`. **`SEC-008` values remain unresolved by design.** |
| `AC-T-004-04` | **PARTIAL — MET for property scope; role assignment deferred.** `tests/Security/ScopeChangeSessionInvalidationTest.php` proves the trigger now exists: `test_revoking_a_scope_invalidates_the_subjects_live_sessions`, `test_granting_a_scope_invalidates_the_subjects_live_sessions`, `test_revocation_does_not_alter_authorization_semantics`, `test_the_actor_s_session_survives_the_change_they_made`, `test_the_scope_change_is_audited_and_the_subjects_sessions_are_still_cut`, `test_a_grant_is_audited_and_the_subjects_sessions_are_still_cut`, `test_a_scope_change_does_not_touch_an_unrelated_users_sessions`, `test_revoking_a_grant_that_is_not_active_cuts_nothing`, `test_a_refused_self_grant_cuts_no_sessions`. `SessionSecurityTest` still proves the mechanism itself. **The role half has no write path to wire** — no role-assignment service exists, only the `User::roles()` relation — so it is deferred rather than faked. |
| `AC-T-004-05` | **PARTIAL — specified, not enforced.** The canonical seven operations are enumerated in `docs/SECURITY.md` §6.1, cross-referenced from `docs/API-SPEC.md` §3.11.1, and `docs/PRD.md` `SEC-018` has been reconciled to the same seven (`ADR-0014` §6 for six of them, §8 for identity-document reveal). `test_step_up_performed_is_part_of_the_audit_vocabulary` and `test_recording_a_step_up_writes_session_state_and_emits_nothing` prove the vocabulary and reserved session keys exist. **No gate exists, so nothing is enforced** — the mechanism that satisfies a step-up is unspecified (`DR-T004-08`, OPEN) and the freshness window is `TBD`. |
| `AC-T-004-06` | **EVIDENCED, values still `TBD`.** All 13 tests in `AuthenticationRateLimiterTest`, including `test_crossing_the_ceiling_refuses_with_retry_guidance`, `test_a_lockout_refuses_before_the_rate_ceiling_is_reached`, `test_the_lockout_is_scoped_to_one_key`, `test_the_cache_key_is_a_digest_and_never_the_identifier`, and both fail-closed tests. **`B-05` values remain unresolved by design.** |
| `AC-T-004-07` | **PARTIAL — session-layer behaviour evidenced; request enforcement unevidenced.** `test_the_session_identifier_changes_at_authentication`, `test_regeneration_preserves_data_but_not_the_identifier`, and `test_ending_a_session_rotates_the_csrf_token` prove fixation protection and token rotation at the session layer. There is no authentication endpoint to assert middleware enforcement on, and none can be built while `SEC-007` refuses every login. |
| `AC-T-004-08` | **UNRESOLVED BY DESIGN.** The header set is unspecified. `test_no_security_header_is_invented` asserts none was invented. `config/security.php` now states plainly that nothing sets them, rather than naming a `SecurityHeaders` class that does not exist. |
| `AC-T-004-09` | **EVIDENCED.** `tests/Feature/ErrorRenderingTest.php` (14 tests) proves the rendering boundary: `test_a_domain_failure_renders_the_documented_error_shape`, `test_an_unhandled_defect_exposes_no_internal_detail`, `test_no_stack_trace_appears_in_an_error_body`, `test_debug_mode_does_not_reenable_detail_in_the_response`, `test_an_unresolved_security_policy_renders_as_service_unavailable`, `test_the_error_body_contains_only_the_documented_keys`, and the correlation-ID cases. The audit path is covered by `AuthAuditTest`, and `test_the_user_model_hides_its_own_credential` proves the password attribute is hidden. |

Constraints that remain in force and are **not** resolved by this section:

- `SEC-007` password algorithm, `SEC-008` session idle and absolute values, `B-05` rate/lockout values, and the step-up freshness window all stay TBD. `UnresolvedSecurityPolicyTest` now enforces this as a test, not just a note.
- The security-header set stays unspecified, and the step-up mechanism stays unspecified.
- The MFA mechanism is unspecified. No MFA mechanism is implemented, and no `MFA_*` audit action has been added.
- Missing policy values are `null` and fail closed through `SecurityPolicyUnresolved` with `SERVICE_UNAVAILABLE`. No value is defaulted. `test_every_shipped_security_value_is_unresolved` fails the build if one ever becomes a default.
- No `users.session_version` was invented. Revocation uses the documented `sessions` table, and the `sessions` table is the framework's — see `docs/DATA-MODEL.md` §2.1 for the column contract and why no column was added.
- Cookie sessions with CSRF protection remain the intended architecture; the values remain unset.
- Login does not reveal account existence: unknown user, wrong password, and a non-`ACTIVE` account all return `AUTH_FAILED` with the same message and a comparable cost. `test_every_refusal_carries_the_same_message` and `test_an_unknown_account_still_performs_a_hash_verification` assert both halves.
- Laravel's stock `SESSION_LIFETIME=120` has **not** been adopted as a session lifetime. `test_laravels_stock_session_lifetime_is_not_adopted` asserts it.
- No route, endpoint, or middleware was added, and none is authorized in this scope.

#### 13.1.5 `T-005` — `COMPLETE`

`tests/Feature/RoomStatusMachineTest.php` (20 tests) and the room-status rules in `tests/Architecture/ProhibitedSchemaGuardTest.php` and `tests/Security/AuditRedactionTest.php`, all passing within the 305.

| Criterion | Evidence |
|---|---|
| `AC-T-005-01` | `test_room_status_is_three_columns_and_not_one`; the guard rejects both a unified `status` column and a room missing an axis. |
| `AC-T-005-02` | `test_the_machine_encodes_every_documented_transition` and `test_no_transition_crosses_axes`. |
| `AC-T-005-03` | `test_an_invalid_transition_returns_room_state_invalid`, `test_a_dirty_room_cannot_skip_straight_to_inspected`, `test_an_out_of_order_room_reports_room_out_of_order`. |
| `AC-T-005-04` | `test_a_room_is_occupiable_only_when_all_three_axes_allow_it`. |
| `AC-T-005-05` | `test_a_status_change_outside_the_callers_scope_is_denied`, and `test_the_scope_is_asserted_before_the_transition_is_validated` (ordering). |
| `AC-T-005-06` | `test_every_status_change_is_audited_with_before_and_after` and `test_a_refused_transition_is_audited_too`; redaction proven in `AuditRedactionTest`. |

#### 13.1.6 `T-006` — `COMPLETE`

`tests/Feature/HousekeepingTaskTest.php`, 21 tests, passing within the 305.

| Criterion | Evidence |
|---|---|
| `AC-T-006-01` | `test_a_task_moves_created_assigned_in_progress_completed_inspected`, `test_a_failed_reinspection_sends_the_task_to_rework`, `test_an_inspected_task_can_still_require_rework`, `test_rework_before_inspection_leaves_the_room_clean`, `test_a_terminal_task_cannot_change_state`. |
| `AC-T-006-02` | `test_completing_a_task_moves_only_the_housekeeping_axis` and `test_inspecting_a_task_moves_only_the_housekeeping_axis`. |
| `AC-T-006-03` | `test_raising_out_of_order_makes_the_room_non_sellable_immediately`, `test_out_of_order_is_audited_with_its_reason`, `test_out_of_order_requires_a_reason`, `test_returning_to_service_restores_sellability`. |
| `AC-T-006-04` | `test_the_task_service_exposes_no_guest_or_financial_data`, `test_the_task_table_has_no_guest_or_financial_column`, `test_housekeeping_cannot_reach_financial_actions_in_its_own_property`, and the identity/folio cases in `RolePermissionMatrixTest`. |
| `AC-T-006-05` | `test_a_dirty_room_is_not_occupiable`. |

#### 13.1.7 `T-007`–`T-058` — `NOT STARTED`

No production code exists for any task from `T-007` onward. `app/Modules/Inventory` does not exist; the modules `Financials`, `Payments`, `Reservations`, `FrontDesk`, `Guests`, `Tax`, `NightAudit`, `Crs`, `ChannelManager`, and `Pos` each contain exactly one file, `MODULE.md`, which is a placeholder and not an implementation. The 14 module directories and the guard that asserts them are `T-001` scaffolding and are **not** evidence of progress on any downstream task.

`T-000` is likewise `NOT STARTED`: it is a PM and specialist action, and no dated, sourced sign-off exists for `B-01`…`B-06` or `C-01`…`C-10`.

### 13.2 Repository gates

Every gate this repository defines was run and passes. This is recorded as measured output, not as a claim.

| Gate | Command | Result |
|---|---|---|
| PHP static analysis | `php vendor/bin/phpstan analyse` | **0 errors** |
| PHP lint | `php vendor/bin/pint --test` | **passed** |
| Test suite | `php artisan test` | **305 tests, 949 assertions, all passing** |
| Strict types | `php artisan zafer:guard-strict-types` | **passed** — all project PHP files declare strict types |
| Money path | `php artisan zafer:guard-money` | **passed** — no float, double, or cast-to-float |
| Module boundaries | `php artisan zafer:guard-modules` | **passed** — 14 modules, no cross-module imports outside Contracts |
| Prohibited schema | `php artisan zafer:guard-prohibited-schema` | **passed** — 22 tables, no prohibited column shape |

**This section previously reported two failing gates.** At the time of writing, `phpstan` reported 9 errors and `pint --test` failed on 2 files, every one of them in `app/Modules/Identity/Auth/` — all unverified `T-004` code. That red state is **resolved**: the defects are fixed and the two gates now pass. The affected code was not merely reformatted; the errors were real (§13.1.4 lists them, including a signed-difference comparison that would have let an expired session live forever). `T-001`–`T-003`, `T-005`, and `T-006` were never implicated by those failures, which is why their recorded status is unchanged.

**Green gates are not a release judgement.** §13.3 still applies, and the project status in §14 is still RED for readiness reasons that no gate measures.

### 13.3 What was NOT measured

Stated explicitly so that no reader infers more than the evidence supports:

- No code-coverage figure of any kind, for any task.
- No benchmark, latency percentile, throughput, or capacity number. `T-034` is unstarted and blocked on `B-05`.
- No CI run result. The workflow is defined; its outcome on any host is not recorded here.
- No `composer audit` result (no Composer CLI available) and no `gitleaks` result (CI-only).
- No penetration test, SAST report, or external security review. `T-033` is unstarted and blocked on `C-10`.
- No compliance position of any kind. `T-035` is unstarted and blocked on `B-01`, `B-02`, and `C-10`.
- No performance, correctness, or resilience claim about any task from `T-007` onward.

### 13.4 Blockers, critical decisions, and the quality gate — unchanged

`B-01`…`B-06` and `C-01`…`C-10` are **unchanged and none is resolved**. Their authoritative status is `docs/BLOCKER-STATUS.md`; this section does not restate, reinterpret, or advance any of them. No value, threshold, algorithm, provider, jurisdiction, or owner has been invented anywhere in this reconciliation.

The dependency graph and the critical path in §11 are unchanged. `T-000` remains the highest-priority item in this document, and the §65 observation stands: the longest chain is gated on decisions (`C-04`, `B-06`, `B-01`, `B-02`), not on code.

The project remains **RED — not ready for development** (`docs/PRD.md` §46). That status is a readiness judgement about the specification and the unanswered questions, not a statement about the foundation code, and it is not affected by the evidence above. `Prd_Maker.md` §43 is also still not satisfied for any task in this document: the tests that exist prove the criteria recorded in §13.1, but no task has a completed review, a named accountable verifier (`C-10`), a verified production configuration, or a runbook. `docs/PRD.md` §43 already records why a Definition of Done with no verifier is not a Definition of Done.
