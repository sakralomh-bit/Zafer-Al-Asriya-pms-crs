# Product Requirements Document — Zafer Al-Asriya v1.0

| Field | Value |
|---|---|
| Project | Zafer Al-Asriya v1.0 |
| Version | 0.1 |
| Status | **Draft** — not a delivery contract |
| Owner | Zafer Al-Asriya |
| Product Manager | محمد فايز |
| Technical Owner | Lead Product Architect |
| Security Owner | `TBD` (`C-10`) |
| Compliance Owner | `TBD` (`C-10`) |
| Created | 2026-09-27 |
| Last Updated | 2026-09-27 |
| Target Release | Phase A (PMS Core) |
| Governing framework | `Prd_Maker.md` v2.0.1 |
| Related | `docs/DISCOVERY.md`, `docs/ARCHITECTURE.md`, `docs/DATA-MODEL.md`, `docs/API-SPEC.md`, `docs/STATE-MACHINES.md`, `docs/SECURITY.md`, `docs/COMPLIANCE.md`, `docs/TEST-STRATEGY.md`, `docs/DEPLOYMENT.md`, `docs/TASKS.md` |

---

## 1. Decisions incorporated in this PRD

These were confirmed by the project manager and are the fixed inputs to everything below. Full reasoning is in the referenced ADR.

| ID | Decision |
|---|---|
| `D-001` | Single tenant. Organization "Zafer Al-Asriya", initially 10 properties, one deployment. No SaaS multi-tenancy, no database-per-tenant, no schema-per-tenant in v1.0. Property-level authorization and data isolation. Domain model extensible to future multi-tenancy without rewriting the business core. |
| `D-002` | Phased release. Phase A = PMS Core. Phase B = CRS. Phase C = Channel Manager + POS. Requirements are never deleted to shrink a release; deferred items keep their traceability. |
| `D-003` | ZATCA e-invoicing is a P0 Phase A workstream, implemented as a pluggable compliance adapter over the PMS financial domain. |
| `D-004` | Guest identity documents: **fields only, no images**. Separate encryption boundary, masking, access logging, configurable retention, synthetic data in dev/test. |
| `D-005` | Payments: cash + provider-hosted/tokenised terminal cards. Deposits, pre-authorisations, captures, refunds, voids, reconciliation, idempotency. Provider-agnostic adapter. PMS never stores PAN/CVV/magstripe. |
| `D-006` | Modular monolith. PHP 8.3+/Laravel, MySQL 8 InnoDB, Vue 3 + TypeScript + Tailwind, Redis + Horizon. BCMath for money; no float. MySQL row locking alone does not guarantee double-booking prevention. |
| `D-007` | Saudi-region managed cloud, single provider, provider-neutral. Terraform IaC, private networking, encrypted backups, no automatic cross-border PII replication without an approved transfer assessment. |
| `D-008` | Greenfield. No legacy migration in the Phase A critical path; import-friendly model for a future versioned import. |

---

## 2. Executive Summary

### 2.1 English

Zafer Al-Asriya v1.0 is a property-management platform for a hotel group operating **10 properties in one deployment**. The first production release (Phase A) delivers the PMS core: property and room master data, reservations with atomic inventory, guests, front desk operations, folios and payments, housekeeping, night audit, role-based access with property-level isolation, an immutable audit trail, and an Arabic-first bilingual interface. It also delivers a **ZATCA e-invoicing compliance adapter**, because invoice issuance in Saudi Arabia is a regulated business obligation rather than an optional feature.

The release boundary is deliberately staged. The Central Reservation System and booking engine follow in Phase B; the Channel Manager (Booking.com, Expedia, Agoda, Almosafer) and the POS follow in Phase C. CRS is in the v1.x product roadmap but is implemented only after PMS Core passes its production quality gate, because PMS Core carries the correctness risks that cannot be iterated on cheaply — inventory concurrency, financial posting integrity, and night audit.

The primary constraints are: operational correctness over feature breadth; exact decimal arithmetic for all money; a single reliable deployment rather than architectural novelty; guest personal data hosted in a Saudi Arabia region; and the hard requirement that an external integration failure never takes down the core PMS.

**The document is in Draft and the project is not Ready for Development.** Six blockers and ten critical issues remain open — see §46.

### 2.2 الملخص التنفيذي (بالعربية)

**زعفر الآصريا ١.٠** منصة لإدارة Rhetار فندقية لمجموعة فندقية تدير **١٠ منشآت في نشر واحد**. الإصدار الإنتاجي الأول (المرحلة أ) يشمل النواة الأساسية لنظام إدارة الفنادق: بيانات المنشآت وأنواع الغرف والغرف الفعلية، والحجوزات مع تخصيص المخزون بشكل ذري، والنزلاء، وعمليات الاستقبال والمن Departure، والسجلات المالية والمدفوعات، وخدمة الغرف، والتدقيق الليلي، والتحكم في الصلاحيات على مستوى المنشأة مع عزل البيانات، وسجل تدقيق غير قابل للتعديل، وواجهة عربية أساسية مع دعم الإنجليزية. كما يشمل **مهايئ الامتثال لفاتورة الضريبة الإلكترونية (ZATCA)**، لأن إصدار الفواتير في المملكة العربية السعودية التزام تنظيمي وليس ميزة اختيارية.

نطاق الإصدار مُقسّم عمداً: نظام الحجز المركزي ومحرك الحجز الإلكتروني في المرحلة ب، ومدير القنوات (Booking.com وExpedia وAgoda وAlmosafer) ونظام نقاط البيع في المرحلة ج. النظام المركزي جزء من خارطة الطريق، لكن يُنفَّذ بعد اجتياز النواة الأساسية لبوابة الجودة الإنتاجية، لأن النواة承载 المخاطر التي لا يمكن تكرارها بتكلفة منخفضة: تزامن المخزون، وسلامة القيود المالية، والتدقيق الليلي.

**هذه الوثيقة في حالة مسودة، والمشروع غير جاهز للتطوير.** لا تزال هناك ست عوائق حرجة وعشرة قضايا حرجة مفتوحة — راجع القسم ٤٦.

> **Note on the Arabic text:** the paragraph above is a product summary written by engineering, **not** a legal or regulatory statement. The official Arabic terminology of Saudi tax and hotel regulation has **not** been verified against an authoritative source and must be confirmed by the organization's authorized tax and compliance representative before use in any customer-facing or official context. See `docs/COMPLIANCE.md` §1 and blockers `B-01`, `B-02`.

---

## 3. Problem Statement

```text
Current state:
  The repository contains no software. Ten properties are operating on
  systems outside this project's scope; no requirement, behaviour, or
  constraint has been captured for them in any form.

Problem:
  There is no requirements specification, no data model, no state machine
  definition, no integration contract, and no test strategy for the
  platform intended to run hotel operations, guest data, reservations,
  payments, and regulated tax invoicing for 10 properties.

Affected users:
  Every role in D-001 — Group Manager, Hotel Manager, Front Desk Agent,
  Reservation Agent, Housekeeping, Finance, Night Auditor, POS Cashier
  (Phase C), Revenue Manager, Compliance Officer, Auditor, Support.

Business impact:
  UNKNOWN. No baseline, volume, or cost data has been supplied. See B-05.

Root causes known today:
  None identified. This is a new implementation, not a replacement of a
  known-deficient system.

Evidence:
  Repository enumeration — exactly one file: Prd_Maker.md. See
  docs/DISCOVERY.md §1.

Desired outcome:
  A complete, traceable, testable specification from which Phase A can be
  built and accepted, followed by Phase B and Phase C.
```

**Explicit honesty statement:** per `Prd_Maker.md` §11, baselines are **never invented**. Every business impact figure is `TBD`, not estimated.

---

## 4. Vision and Outcomes

A single reliable deployment that a hotel group can operate 10 properties from, where the numbers in the folio, the invoices issued to guests, and the audit trail are all defensible, and where the correctness guarantees (no double booking, no double charge, no duplicate invoice) are **proven by tests rather than asserted**.

---

## 5. Goals / Non-Goals

### 5.1 Goals

| ID | Goal | Metric | Target | Window |
|---|---|---|---|---|
| `G-01` | No double booking of a sellable unit | Confirmed duplicate allocations | **0** | Every release, permanently |
| `G-02` | No duplicate financial effect | Duplicate postings/payments from retry or re-run | **0** | Every release, permanently |
| `G-03` | No duplicate or uncontrolled tax invoice | Gaps or duplicates in the invoice sequence | **0** | Every release |
| `G-04` | Property data isolation is provable | Property-breakout tests passing | 100% of matrix | Every release |
| `G-05` | Every sensitive action is attributable | Auditable actions lacking a record | **0** | Every release |
| `G-06` | Every P0 requirement is traceable end to end | P0 requirements with a broken chain link | **0** | At each release gate |

All six are **binary correctness goals**, deliberately, because they are the ones where a miss is a real-world harm rather than a degraded experience.

### 5.2 Non-Goals for v1.0

| Non-goal | Reason | Deferred to |
|---|---|---|
| SaaS multi-tenancy | `D-001` | Future, with a documented migration path |
| Channel Manager / OTA integration | `D-002` | Phase C |
| POS | `D-002` | Phase C |
| Guest-facing online card capture | `D-005` | Phase B |
| Guest identity document images | `D-004` | Not approved; requires a verified requirement |
| Company / group folios, transfers, write-offs, negotiated rates | Scope control (`H-06`) | `TBD` |
| Legacy PMS data migration | `D-008` | Not approved |
| AI/automation of any operational or financial action | `Prd_Maker.md` §60; not justified by any requirement | Not planned |
| Machine learning pricing or demand forecasting | Not justified by any requirement | Not planned |

---

## 6. Success Metrics

`Prd_Maker.md` §11 requires a definition, formula, source, owner, baseline, target, frequency, population, and exclusions for each metric. **Baselines are `TBD` because none exist** — inventing one is explicitly forbidden.

| ID | Metric | Definition | Baseline | Target | Frequency | Owner |
|---|---|---|---|---|---|---|
| `KPI-01` | Double bookings | Count of confirmed allocations exceeding sellable inventory | 0 (by design) | 0 | Continuous | `TBD` |
| `KPI-02` | Double charges | Duplicate financial effect from one logical operation | 0 (by design) | 0 | Continuous | `TBD` |
| `KPI-03` | Integration availability | Successful submissions / total, excluding accepted failures | `TBD` | `TBD` | Daily | `TBD` |
| `KPI-04` | Dead-letter backlog | Items in DLQ older than the agreed window | `TBD` | 0 sustained | Hourly | `TBD` |
| `KPI-05` | Night audit completion | Properties closed by the agreed time | `TBD` | 100% | Daily | `TBD` |
| `KPI-06` | Availability | Uptime, measured and defined | `TBD` (`C-01`) | `TBD` | Monthly | `TBD` |
| `KPI-07` | API latency | p50/p95/p99 by endpoint class | `TBD` (`B-05`) | `TBD` | Continuous | `TBD` |
| `KPI-08` | Front-desk workflow success | Journeys completed without an error | `TBD` | `TBD` | Weekly | `TBD` |

---

## 7. Scope

### 7.1 Phase A — PMS Core (in scope, P0)

Properties · room types · physical rooms · room status · inventory and atomic allocation · reservations · guests · guest identity fields (no images) · check-in · check-out · stay extension · room transfer · no-show · cancellation · deposits and pre-authorisations · folios · charges · taxes and VAT · payments (cash + tokenised terminal) · refunds · cashier shifts and settlement · reconciliation · housekeeping · night audit · invoicing (standard and simplified) · credit and debit notes · ZATCA compliance adapter · RBAC · property-level authorization · audit trail · Arabic RTL and English LTR · reporting · notifications · observability · backups · disaster recovery.

### 7.2 Phase B — CRS (deferred, traceable)

Property selection · availability search · booking engine · rate plans · restrictions · guest-facing reservation modification and cancellation · online card capture · guest identity capture during booking · confirmation notifications.

### 7.3 Phase C — Channel Manager and POS (deferred, traceable)

Booking.com · Expedia · Agoda · Almosafer · OTA inventory/rate/restriction synchronization · booking ingestion, modification, cancellation · channel mapping and reconciliation · sync health · virtual cards · POS menu/orders/tables · POS cashier and shift · room-charge posting · POS refund/void and end-of-day.

### 7.4 Scope guard

Any request that changes a core journey, the data model, security, compliance, an external contract, or release capacity is a formal change request (`Prd_Maker.md` §12, §68). No such request has been raised to date.

---

## 8. Personas and Actors

`Prd_Maker.md` §13: a persona is not a permission role. Personas describe context and pain; roles are the authorization subject.

| Persona | Goal | Frequency | Context | Pain points | Permissions | Sensitive data exposure | Success outcome |
|---|---|---|---|---|---|---|---|
| Group Manager | See the whole group's performance and control policy | Daily | Off-site or on-site | No consolidated view | All properties | Aggregate financial | Policy applied consistently |
| Hotel Manager | Run one property | All day | On property | Fragmented tools | Assigned property | Full property operational and financial | Property runs without escalation |
| Front Desk Agent | Check guests in and out correctly | Constant | At the desk, queue present | Wrong room, wrong balance | Assigned property | Guest identity, folio | Fast, correct, auditable stay |
| Reservation Agent | Take and manage bookings | Constant | Desk or phone | Double booking, disputes | Authorized properties | Guest identity, stay history | Correct availability every time |
| Housekeeping | Get rooms guest-ready | Per shift | In the property | Unclear priority, room status disputes | Assigned property | Room status only | Every room correctly marked |
| Finance | Close, settle, reconcile, invoice | Daily/period | Back office | Reconciliation gaps, rounding disputes | Authorized properties | Full financial | Defensible numbers |
| Night Auditor | Close the business date | Nightly | Back office, at cutoff | Interrupted runs, ambiguity | Authorized properties | Full financial | Clean, resumable close |
| POS Cashier | Take payment and post room charges | Constant | Outlet | *(Phase C)* | Assigned property | Payment outcome | *(Phase C)* |
| Revenue Manager | Optimize rates and restrictions | Daily | Back office | Availability errors | Authorized properties | Rates, occupancy | Rates that can be sold |
| Compliance Officer | Evidence ZATCA, tax and privacy compliance | Periodic | Back office | Missing evidence, gaps | Authorized properties | Invoices, tax, audit | Every filing reconstructable |
| Auditor | Read-only verification | Periodic | Back office | Cannot see without risk of change | Read-only scope | Read-only | Assurance without alteration risk |
| Support | Diagnose a production issue | On demand | Remote | Cannot see what happened | Explicitly scoped, time-bound | Scoped, audited | Diagnosis without scope creep |
| Guest *(external)* | Book, stay, be invoiced correctly | Per stay | Direct or via channel | — | None — the subject of the data | — | Correct stay, correct invoice |

---

## 9. Roles, RBAC/ABAC and Data Scope

Full specification: `ADR-0014`, `docs/SECURITY.md` §3.

`Allow = Identity × Role × Resource × Action × Scope × Policy`

Deny by default. Backend enforcement on every request. Property scope enforced server-side. **UI visibility is not a security boundary.** Impersonation is default DENY.

| Role | Scope | Permitted | Explicitly NOT permitted |
|---|---|---|---|
| Group Manager | All 10 properties (explicit grants) | Configure, view all, approve, reopen business dates, manage roles and scopes | — (still subject to separation of duties on self-grant) |
| Hotel Manager | Assigned property/properties | Full operational and financial for those properties, room blocks, overrides | Access to other properties; granting own additional scope |
| Front Desk Agent | Assigned property | Check-in, check-out, room transfer, guest lookup, folio view | Rate configuration, refunds, night audit, configuration |
| Reservation Agent | Authorized properties | Create/modify/cancel reservations, holds, availability | Check-in, refunds, configuration |
| Housekeeping | Assigned property | Room status, housekeeping tasks, out-of-order | Guest identity, folio, financial data, reservations |
| Finance | Authorized properties | Postings, payments, refunds (with step-up), settlement, reconciliation, invoices | Check-in/out, rate configuration |
| Night Auditor | Authorized properties | Run/resume night audit, reopen with approval, no-show, out-of-order | Refunds, rate configuration, scope changes |
| POS Cashier | Assigned property | *(Phase C)* | — |
| Revenue Manager | Authorized properties | Rates, restrictions, forecasts | Refunds, scope changes, check-in |
| Compliance Officer | Authorized properties | Invoices, compliance submissions, audit trail, configuration review | Modify financial records; ordinary bookings |
| Auditor | Read-only scope | Read everything in scope | **Any write.** Cannot alter, including the audit trail |
| Support | Explicitly scoped, time-bound | Diagnose within scope; every access audited | Anything outside scope; no persistent grant |

**Separation of duties:** a user cannot grant themselves scope. A user who acts cannot alter the record of the action. Finance refunds require step-up authentication and an audit event. Business-date reopen requires a distinct authorization.

---

## 10. User Journeys

### `UJ-01` — Create and confirm a reservation

```text
Actor:        Reservation Agent
Trigger:      Guest request (phone, desk, or walk-in)
Preconditions:
  - User authenticated; property scope granted
  - Inventory service available

Main flow:
  1. Search availability for property + dates + occupancy
  2. Select a room type
  3. Create or select the guest profile
  4. Create the reservation in DRAFT
  5. Hold inventory (DRAFT -> HELD)          [transaction, row-locked]
  6. Confirm                                 [HELD -> CONFIRMED, atomic
                                              with allocation commit]
  7. Emit reservation-confirmed event
  8. Record audit events
  9. Show confirmation

Alternative flows:
  - Payment policy requires prepayment -> PENDING_PAYMENT instead
  - Guest not identified yet -> PROVISIONAL guest profile

Validation:
  - Within booking window; satisfies minimum-stay restriction
  - Inventory available for EVERY night of the stay
  - Room type occupiable

Failure flows:
  - Duplicate request (same Idempotency-Key)
    -> return the ORIGINAL result; no second allocation
  - Inventory lost race
    -> INVENTORY_UNAVAILABLE; NO booking committed
  - Hold expired before confirmation
    -> RESERVATION_HOLD_EXPIRED
  - Network failure after commit
    -> client retries with the SAME key; original result returned

Postconditions:
  - Exactly one allocation exists for the stay
  - Room occupancy status = RESERVED
  - Audit trail contains HELD and CONFIRMED

Audit events:     RESERVATION_HELD, RESERVATION_CONFIRMED
Notifications:    Configurable; providers TBD (C-07)
```

### `UJ-02` — Check in a guest

```text
Actor:        Front Desk Agent
Trigger:      Guest arrival
Preconditions: Reservation CONFIRMED; room occupiable; agent in scope

Main flow:
  1. Find the reservation
  2. Capture guest identity FIELDS (no images)      [ADR-0012]
  3. Evaluate room occupiability INSIDE the transaction
  4. Confirm the room assignment
  5. Open the folio                                [atomic with check-in]
  6. Apply deposit / pre-authorisation per policy
  7. Transition CONFIRMED -> CHECKED_IN
  8. Update room status to OCCUPIED
  9. Audit + notify

Failure flows:
  - Room not occupiable (dirty, out of order, or taken)
    -> ROOM_NOT_OCCUPIABLE; assign another room or reschedule housekeeping
  - Room taken concurrently by another agent
    -> deterministic denial; exactly one check-in succeeds
  - Payment pre-authorisation outcome UNKNOWN
    -> PAYMENT_OUTCOME_UNKNOWN; checkout may proceed under policy TBD;
       the unknown payment goes to RECONCILIATION, never to retry

Postconditions: Folio open; room OCCUPIED; guest identity captured and audited
Audit events:    GUEST_CHECKED_IN, IDENTITY_CAPTURED, PAYMENT_* (if any)
```

### `UJ-03` — Check out and settle

```text
Actor:        Front Desk Agent
Trigger:      Guest departure
Preconditions: Reservation CHECKED_IN; folio open

Main flow:
  1. Post outstanding charges
  2. Verify no unposted charges remain
  3. Present the balance
  4. Take payment (cash or tokenised terminal)
  5. Allocate the payment to the folio
  6. Issue the tax invoice; queue the ZATCA submission [outbox]
  7. Transition CHECKED_IN -> CHECKED_OUT
  8. Room -> DIRTY; housekeeping task created
  9. Reconcile payment to the provider record
 10. Audit

Failure flows:
  - Unposted charges      -> FOLIO_HAS_UNPOSTED_CHARGES
  - Payment declined      -> guest may settle another way; no auto-retry
  - Payment outcome UNKNOWN -> reconciliation; invoice still issues per policy TBD
  - ZATCA submission fails -> business operation COMPLETES; invoice flagged
                             SUBMISSION_PENDING; dead-lettered; visible.
                             Compliance failure never rolls back the checkout.
```

### `UJ-04` — Night audit for one property

Full specification in `ADR-0018` and `docs/STATE-MACHINES.md` §G.

```text
Actor:        System (scheduled) or Night Auditor
Preconditions: Cut-off time passed; no active run for property + business date

Main flow (each step independently idempotent and resumable):
  1. Close open folios / post unposted charges
  2. Determine no-shows
  3. Resolve late checkouts
  4. Post revenue
  5. Compute and post taxes
  6. Settle payments; close cashier shifts
  7. Generate invoices; queue ZATCA submissions
  8. Reconcile
  9. Mark the business date CLOSED

Failure flows:
  - Step fails -> run becomes FAILED and STOPS (fail-stop, never continue)
  - Operator resumes -> continues from the failed step; no re-posting
  - Manual override -> separately authorized, audited
  - Reopen a closed business date -> separately authorized, audited, policy TBD
```

---

## 11. Business Rules

`Prd_Maker.md` §16: every rule is independently testable and never buried in prose.

```text
BUS-001  Name: Atomic inventory allocation
  Rule: A sellable unit for a given property and night MUST NOT be allocated
        to more than one reservation in a confirmed state.
  Applies when: Any allocation, hold, confirmation, or room transfer occurs.
  Exception: None. An explicitly configured oversell policy is TBD and is not
        assumed to exist.
  Source: D-006, ADR-0008
  Testable: CON-01 — N concurrent attempts for the last unit yield exactly
        one CONFIRMED and N-1 INVENTORY_UNAVAILABLE, with zero duplicates.
```

```text
BUS-002  Name: Idempotent financial and inventory operations
  Rule: An operation that creates or moves money, inventory, or a
        reservation MUST be safe to repeat with the same Idempotency-Key,
        returning the original result and creating no second effect.
  Applies when: Any create, confirm, capture, refund, or transfer.
  Source: Prd_Maker.md §26, D-006
  Testable: CON-02, CON-04.
```

```text
BUS-003  Name: No blind retry after an unknown financial outcome
  Rule: When a payment or financial request's outcome is not confirmed, the
        system MUST NOT retry. It MUST record UNKNOWN_OUTCOME and route to
        reconciliation.
  Applies when: Any payment, capture, or refund with no confirmed result.
  Source: Prd_Maker.md §33, ADR-0011, docs/STATE-MACHINES.md §D.2
  Testable: Simulated timeout produces UNKNOWN_OUTCOME, zero provider
        retries, and a reconciliation task; the API returns retryable:false.
```

```text
BUS-004  Name: Append-only financial ledger
  Rule: A posted financial record MUST NOT be updated or deleted. Every
        correction MUST be a new compensating entry referencing the original.
  Applies when: Any correction to a posting, payment, or invoice.
  Source: ADR-0009
  Testable: No application code path issues UPDATE or DELETE against posted
        records; a correction produces a linked reversal.
```

```text
BUS-005  Name: Derived folio balance
  Rule: A folio balance MUST be derived from its postings and MUST NOT be
        independently mutable. Any cached balance is a rebuildable
        projection, never the source of truth.
  Source: ADR-0009
  Testable: Random posting/refund sequences preserve the invariant; CON-07.
```

```text
BUS-006  Name: Exact decimal arithmetic for money
  Rule: All monetary calculation MUST use exact decimal arithmetic. Binary
        floating point MUST NOT be used for prices, taxes, discounts,
        payments, refunds, folio balances, or exchange rates. Rounding MUST
        occur at exactly one defined stage with a defined mode.
  Applies when: Every monetary computation.
  Exception: None.
  Source: D-006, ADR-0006, Prd_Maker.md §17, §59
  Testable: Static check for float usage in money paths; rounding tests.
  UNRESOLVED: Rounding stage and mode are TBD (C-04).
```

```text
BUS-007  Name: Server-side enforcement of state transitions
  Rule: A state transition MUST be validated and applied server-side. Client
        behaviour MUST NOT be a precondition. An invalid transition MUST
        return its documented deterministic error code.
  Source: Prd_Maker.md §18, ADR-0019
  Testable: Each state machine's invalid-transition table is exercised.
```

```text
BUS-008  Name: Integration failure never blocks core operations
  Rule: A failure in any external system MUST NOT cause a core PMS operation
        to fail or to become unavailable. External work MUST be dispatched
        through a transactional outbox with bounded retry, dead-letter
        handling, and reconciliation.
  Applies when: ZATCA submission, payment provider, notification delivery.
  Source: D-006, ADR-0005
  Testable: With the provider unavailable, checkout completes; the submission
        is dead-lettered and visible.
```

```text
BUS-009  Name: Property data isolation
  Rule: A user MUST NOT read or modify data belonging to a property they are
        not granted. Absence of a grant means denial, not an empty result.
  Applies when: Every request touching property-scoped data.
  Source: D-001, ADR-0014
  Testable: Full authorization matrix; property-breakout tests.
```

```text
BUS-010  Name: Compliance state is separate from business state
  Rule: A business operation MUST NOT be rolled back because a compliance
        submission failed, and a compliance failure MUST NOT be silently
        dropped. Both states are tracked independently.
  Source: Prd_Maker.md §50.2, D-003
  Testable: A failed ZATCA submission leaves the invoice ISSUED and the
        submission DEAD_LETTER, with an alert.
```

```text
BUS-011  Name: Controlled, gapless invoice numbering
  Rule: Tax invoice numbers MUST be issued from a controlled sequence per
        legal entity, with no gaps and no duplicates, including across
        failures, rollbacks, and aborted transactions.
  Applies when: Any invoice or credit/debit note issuance.
  Source: D-003
  UNRESOLVED: Sequence scope depends on the legal-entity answer (B-06).
  Testable: Inject failures at every point; assert no gap, no duplicate.
```

```text
BUS-012  Name: Identity-data minimization
  Rule: The system MUST store only the minimum guest identity-document
        fields required, and MUST NOT collect document images. Sensitive
        fields MUST be encrypted at rest, masked by default, access-logged,
        and excluded from logs, analytics, error reports, and telemetry.
  Applies when: Any guest identity handling.
  Source: D-004, ADR-0012
  UNRESOLVED: Which fields are legally required is TBD (C-02). This rule is
        a minimization decision, NOT a legal determination.
  Testable: Automated log scan; masking tests; no image column exists.
```

```text
BUS-013  Name: Card data exclusion
  Rule: The PMS MUST NOT store PAN, CVV/CVC, magnetic-stripe data, or full
        card numbers in any store, log, analytics system, or telemetry.
        Only provider-safe references may be stored.
  Applies when: Every payment path.
  Source: D-005, ADR-0011
  Testable: No card column exists; automated log scan.
```

```text
BUS-014  Name: Audit immutability
  Rule: Audit records MUST NOT be updated or deleted through the
        application. The identity that performs an action MUST NOT be able
        to alter the record of that action.
  Source: ADR-0016
  Testable: No UPDATE/DELETE path exists; append-only assertion.
```

```text
BUS-015  Name: Resumable night audit
  Rule: A night audit run MUST resume from its last completed step and MUST
        NOT re-run a completed step. A step failure MUST stop the run and
        MUST NOT proceed. At most one run may exist per property and
        business date.
  Applies when: Night audit execution, resume, or replay.
  Source: ADR-0018, D-006
  Testable: CON-05; interrupt at every step and assert no double posting,
        no duplicate invoice, and no ambiguous business date.
```

```text
BUS-016  Name: No unverified regulatory claim
  Rule: No system output, document, or user-facing message may assert
        regulatory compliance, a ZATCA wave assignment, a legal status, or
        PCI scope, unless a sourced and dated authority record exists.
  Source: D-003, D-005, Prd_Maker.md §71
  Testable: Document review; no affirmative compliance claim in docs/.
```

---

## 12. Domain Model

See `docs/DATA-MODEL.md` for the full catalogue. Key entities: `organizations` → `legal_entities` → `properties` → `room_types` → `physical_rooms` → `inventory`; `reservations` → `reservation_nights` → `inventory_allocations`; `guests` → `guest_identity_documents`; `folios` → `folio_postings`; `payments` → `payment_allocations` → `refunds`; `invoices` → `invoice_number_sequences`; `integration_submissions`; `audit_events`.

**System of record** (`Prd_Maker.md` §1.8):

| Datum | System of record | Writer | Readers | Consistency |
|---|---|---|---|---|
| Sellable inventory | PMS `inventory` | PMS | CRS, Channel Manager | Strong, transactional |
| Reservation | PMS | PMS (Channel Manager proposes) | All | Strong |
| Card transaction | **Payment provider** | Provider | PMS (reference only) | Eventual, reconciled |
| Folio balance / charges / refunds | PMS | PMS | Finance, front desk, reporting | Strong |
| Guest profile | PMS | PMS | All | Strong |
| Tax invoice content | PMS | PMS | Finance, guest | Strong |
| Invoice compliance status | ZATCA/FATOORA | Authority | PMS (status) | Eventual, reconciled |
| Tax rate | `tax_rates` (versioned) | Compliance/Finance | Calculation | Snapshot per posting |

---

## 13. State Machines

Specified in `docs/STATE-MACHINES.md`: Reservation (§A), Room (§B), Inventory/Allocation (§C), Payment (§D), Refund (§E), Folio (§F), Night Audit (§G), External Integration (§H), Channel synchronization (§I — **deferred, Phase C, no partner behavior invented**), plus Invoice, Credit/Debit Note, Cashier Shift, Housekeeping Task, User/Session, and Guest Profile (§J).

Every machine carries a transition table, an invalid-transition table with deterministic error codes, and defined concurrency behaviour. Terminal states are explicit. No transition is implied by UI behaviour.

---

## 14. Functional Requirements

Selected P0 requirements. Each has acceptance criteria in §35. The full set is enumerated in `docs/TASKS.md` by traceable task.

```text
FR-001  Title: Property and room master data
Priority: P0
Actor/System: Hotel Manager
Requirement: The system SHALL allow an authorized user to create and manage
  properties, room types, and physical rooms scoped to granted properties.
Validation: Occupancy within room-type limits; unique room code per property.
Side Effects: Configuration versioned and audited.
Acceptance: AC-FR-001-01, AC-FR-001-02
```

```text
FR-002  Title: Atomic inventory allocation
Priority: P0
Requirement: The system SHALL allocate a sellable unit atomically. The
  guarantee is the COMBINATION of transaction, row lock, unique constraint,
  allocation rule, state validation, idempotency, and concurrency test.
  MySQL row locking alone does NOT satisfy this requirement.
Failure Behavior: Losing attempt receives INVENTORY_UNAVAILABLE; nothing
  partial is committed.
Acceptance: AC-FR-002-01 .. AC-FR-002-04 (CON-01, CON-02)
```

```text
FR-003  Title: Reservation lifecycle
Priority: P0
Requirement: The system SHALL manage the reservation state machine with
  server-side validation and deterministic errors for invalid transitions.
Acceptance: AC-FR-003-01 .. AC-FR-003-03
```

```text
FR-004  Title: Check-in and check-out
Priority: P0
Requirement: The system SHALL perform check-in and check-out with room
  precondition re-evaluation inside the transaction, and SHALL open the
  folio atomically with check-in.
Failure Behavior: Concurrent check-in for the same room yields exactly one
  success; the other receives a deterministic denial.
Acceptance: AC-FR-004-01, AC-FR-004-02 (CON-03)
```

```text
FR-005  Title: Folio and immutable ledger
Priority: P0
Requirement: The system SHALL post all financial entries to an append-only
  ledger, derive balances from postings, and represent corrections as
  compensating entries. Floats MUST NOT be used.
Acceptance: AC-FR-005-01 .. AC-FR-005-03 (CON-07)
```

```text
FR-006  Title: Payments with provider abstraction
Priority: P0
Requirement: The system SHALL record cash and tokenised terminal payments
  through a provider-agnostic adapter, supporting deposits,
  pre-authorisations, captures, refunds, voids, and reconciliation, with
  idempotent operations and an explicit UNKNOWN_OUTCOME state. Card data
  MUST NOT be stored.
Acceptance: AC-FR-006-01 .. AC-FR-006-04 (CON-04, unknown-outcome case)
```

```text
FR-007  Title: Night audit
Priority: P0
Requirement: The system SHALL run a per-property, per-business-date night
  audit that is resumable, idempotent per step, fail-stop, and single-run.
Acceptance: AC-FR-007-01 .. AC-FR-007-03 (CON-05, CON-06)
```

```text
FR-008  Title: Tax and invoicing with ZATCA compliance adapter
Priority: P0
Requirement: The system SHALL compute VAT per the approved tax policy, issue
  tax invoices and credit/debit notes from a controlled gapless sequence per
  legal entity, and submit them to FATOORA through a pluggable compliance
  adapter using the transactional outbox, with bounded retry, dead-letter
  handling, reconciliation, and audit. Every invoice MUST be traceable to the
  originating folio and transactions.
Constraint: The organization's wave and legal status require confirmation by
  an authorized tax representative. Provider API specifics are UNKNOWN.
Acceptance: AC-FR-008-01 .. AC-FR-008-04
```

```text
FR-009  Title: Role-based access with property scope
Priority: P0
Requirement: The system SHALL enforce deny-by-default, role-based
  authorization with server-side property scope on every request, and SHALL
  log all authorization changes.
Acceptance: AC-FR-009-01, AC-FR-009-02
```

```text
FR-010  Title: Audit trail
Priority: P0
Requirement: The system SHALL write an append-only audit record for every
  auditable event, capturing who/what/when/where/before/after/reason/
  correlation/source/result, and SHALL log every reveal of a masked identity
  field.
Acceptance: AC-FR-010-01, AC-FR-010-02
```

```text
FR-011  Title: Bilingual interface with RTL/LTR
Priority: P0
Requirement: The system SHALL provide a complete Arabic (RTL) and English
  (LTR) interface from one component set using CSS logical properties, with
  no hard-coded direction assumptions.
Acceptance: AC-FR-011-01, AC-FR-011-02
```

```text
FR-012  Title: Housekeeping
Priority: P0
Requirement: The system SHALL manage room housekeeping status and tasks,
  with occupancy status evaluated independently of housekeeping status.
Acceptance: AC-FR-012-01
```

```text
FR-013  Title: Reporting
Priority: P1
Requirement: The system SHALL produce reports that state their source of
  truth, refresh frequency, time zone, currency, and authorization, and
  SHALL NOT mix operational and financial definitions without labelling.
Acceptance: AC-FR-013-01
```

```text
FR-014  Title: Notifications
Priority: P1
Requirement: The system SHALL deliver notifications over configured channels
  with templates, locale, retry, and delivery tracking, and SHALL NOT include
  sensitive personal or financial data in a message unnecessarily.
Acceptance: AC-FR-014-01
```

---

## 15. Data Requirements

See `docs/DATA-MODEL.md` §5 for the full field table with classification, retention, encryption, export, and deletion rules.

| ID | Requirement |
|---|---|
| `DR-001` | Every property-scoped table carries `property_id`, enforced server-side on access. |
| `DR-002` | User access is granted per property by explicit grant record, not by a superuser flag. |
| `DR-003` | Inventory is the authoritative sellable ledger with enforced non-negative counts. |
| `DR-004` | Reservations are normalized per night, not per date range. |
| `DR-005` | Guest identity documents: fields only, no images, encrypted, masked, access-logged. |
| `DR-006` | Room status is three orthogonal axes, not a single column. |
| `DR-007` | `folio_postings` is append-only; the balance is derived. |
| `DR-008` | No card data column may exist for PAN, CVV, or track data. |
| `DR-009` | Invoice numbers come from a controlled gapless sequence per legal entity. |
| `DR-010` | Business date is stored separately from the creation timestamp. |
| `DR-011` | Identity data MUST NOT appear in logs, metrics, traces, or error reports. |
| `DR-012` | Retention periods are configurable per data category. |
| `DR-013` | Operational personal data is separable from financial and tax records so policy can be applied independently. |
| `DR-014` | An idempotency record is stored per money/inventory/reservation operation. |

---

## 16. Integration Requirements

`Prd_Maker.md` §24 template completed in `docs/DISCOVERY.md` §5.1 and `docs/COMPLIANCE.md`.

```text
INT-001  Provider: Saudi authority — FATOORA platform (ZATCA)
Purpose: E-invoice / credit note / debit note integration
Direction: Outbound (+ inbound validation responses)
Authentication: UNKNOWN — requires confirmation from the authoritative source
Environment(s): UNKNOWN — sandbox availability unverified
Rate Limits: UNKNOWN
Timeout: TBD
Retry Policy: Bounded, exponential backoff + jitter, retryable/non-retryable
             error classes defined
Idempotency: Required on every submission
Ordering: Submission sequence per business date
Consistency: Asynchronous; business state independent of compliance state
Error Mapping: UNKNOWN — no code list verified
Dead Letter: Required; never silently dropped
Reconciliation: Required; submission status, final disposition, recon status
Monitoring: Submission success rate, DLQ depth, backlog age
Credential Rotation: UNKNOWN + TBD
Versioning: Adapter versioned; payload version recorded per submission
Sandbox/Certification: UNKNOWN
Fallback: Submission queued; business operation continues; invoice flagged
Data Shared: Invoice and tax data — exact field set UNKNOWN
PII: Buyer identifiers may be personal data
Compliance Impact: NO COMPLIANCE CLAIM IS MADE
Source: zatca.gov.sa — see docs/COMPLIANCE.md §3
BLOCKERS: B-01 (organization's wave/status), B-02 (API specifics)
```

```text
INT-002  Provider: TBD — Saudi payment service provider (not selected)
Purpose: Card authorisation, capture, refund, void
Direction: Outbound; inbound webhook UNKNOWN
Authentication: UNKNOWN
Environment(s): UNKNOWN — sandbox required by D-005
Rate Limits / Timeout / Error Mapping / Webhook signature: UNKNOWN
Retry Policy: Bounded; NEVER retries an UNKNOWN_OUTCOME
Idempotency: Required on every operation
Consistency: Provider is system of record for the card transaction; PMS is
             system of record for allocation and reconciliation status
Dead Letter: Required
Reconciliation: Required — provider settlement vs. internal record
Monitoring: Authorisation success rate, failure classes, unknown-outcome count
Data Shared: Cardholder authentication only; NEVER PAN/CVV
Compliance Impact: NO PCI CLAIM IS MADE. Tokenization reduces scope but does
                   not establish compliance.
BLOCKER: B-04
```

```text
INT-003  Provider: Email / SMS / WhatsApp providers — TBD
Purpose: Guest and staff notifications
Status: Channels in scope (P1); providers unselected (C-07)
Consent mechanics: DEPEND on channel and on PDPL — TBD, requires legal review
```

```text
INT-004..007  Booking.com / Expedia / Agoda / Almosafer
Phase: C (deferred). ALL PARTNER BEHAVIOUR IS UNKNOWN AND MUST NOT BE INVENTED.
Constraint: The PMS remains the system of record. An OTA message is a
            proposal to be validated and applied, never a fact to be trusted.
```

**Integration design rules in force** (`Prd_Maker.md` §24): never assume external availability; never use UI state as proof of external success; persist external request and correlation IDs; make business operations idempotent; separate synchronous customer-facing operations from asynchronous reconciliation; keep payload mapping outside the core domain; version adapters; add contract tests.

---

## 17. API Requirements

`docs/API-SPEC.md` is normative. Summary: versioned REST (`/api/v1/...`); the structured error model with `code` / `message` / `request_id` / `details` / `retryable`; **no** stack traces, SQL, secrets, hostnames, or identity-document numbers in any response; `Idempotency-Key` on every money/inventory/reservation operation; correlation ID propagated end to end; bounded pagination and allow-listed sorting; property scope enforced server-side with denial (not an empty result) for out-of-scope access.

**No OTA, payment-provider, or ZATCA endpoint is specified anywhere in this document set**, because no such provider has been selected or verified (`B-02`, `B-04`).

---

## 18. Security Requirements

`docs/SECURITY.md` is normative.

| ID | Requirement |
|---|---|
| `SEC-001` | Authentication with session management; MFA available; step-up for sensitive operations. |
| `SEC-002` | Authorization `Allow = Identity × Role × Resource × Action × Scope × Policy`, deny by default, backend-enforced. |
| `SEC-003` | Property scope enforced server-side; a denial is never an empty result. |
| `SEC-004` | No superuser bypass on property scope; Group Manager is an explicit grant. |
| `SEC-005` | Secrets managed centrally; no production secret in source control. |
| `SEC-006` | TLS in transit; encryption at rest; separate key boundary for identity data. |
| `SEC-007` | Password hashing — **algorithm `TBD`**, must meet current guidance. |
| `SEC-008` | Session security: idle timeout, absolute lifetime, revocation. **Values `TBD`.** |
| `SEC-009` | CSRF protection where applicable; security headers on all responses. |
| `SEC-010` | Rate limiting on authentication, search, export, and payment endpoints. |
| `SEC-011` | Immutable audit trail; separation of duties. |
| `SEC-012` | Dependency scanning and secret scanning in CI. |
| `SEC-013` | Webhook signature verification, timestamp tolerance, and replay protection — **mechanism `UNKNOWN`** pending provider. |
| `SEC-014` | Secure error handling: no internal detail exposure. |
| `SEC-015` | Export controls: role-gated, volume-limited, audited. |
| `SEC-016` | Property breakout is a named threat with a mandatory test. |
| `SEC-017` | No `FLOAT`/`DOUBLE` for money (also a correctness control). |
| `SEC-018` | Privileged actions (refund, configuration, scope grant, business-date reopen, export, impersonation, identity-document reveal) require step-up and produce an audit event. |

---

## 19. Privacy and Data Governance

`docs/COMPLIANCE.md` §2 is normative. The three-way distinction required by the project manager:

| Category | Content | Handling |
|---|---|---|
| **(1) Required for hotel operations** | Name, contact, stay dates, room, folio, payment outcome | Collected for service delivery; minimized; retained per policy |
| **(2) Required by verified Saudi regulatory requirements** | **UNKNOWN.** Guest registration field requirements and retention periods require verification against authoritative sources (`C-02`). ZATCA invoice fields are a separate category, `UNKNOWN` until `B-02` | Not asserted; not assumed |
| **(3) Optional — MUST NOT be collected by default** | Document images, marketing consent, biometric data, nationality-based profiling, cross-property behavioural tracking | Not collected |

| ID | Requirement |
|---|---|
| `PRI-001` | Document images are not collected in v1.0; the data model permits an isolated future capability. |
| `PRI-002` | Identity fields are encrypted at rest under a separate key boundary, masked by default, access-logged on reveal. |
| `PRI-003` | Identity data is excluded from logs, metrics, traces, error reports, and analytics. |
| `PRI-004` | Retention is configurable per category; periods are `TBD` (`C-09`). |
| `PRI-005` | Data-subject access, correction, and erasure are defined. **Erasure versus financial and tax record retention is a policy conflict requiring legal resolution.** |
| `PRI-006` | Operational personal data is stored separably from financial/tax records so policy applies independently. |
| `PRI-007` | Purpose limitation: no cross-property behavioural profiling by default. |
| `PRI-008` | Synthetic data only in every non-production environment. |
| `PRI-009` | No cross-border transfer of guest personal data without a documented transfer assessment and approval. |
| `PRI-010` | A subprocessor register is maintained; **contents depend on selected providers** (`B-03`, `B-04`). |
| `PRI-011` | A breach/incident response workflow exists. **No 24/7 escalation model yet (`H-05`).** |

**No PDPL conformance is claimed.** Encryption does not establish PDPL compliance (`Prd_Maker.md` §22, §51).

---

## 20. Compliance / Regulatory Requirements

`docs/COMPLIANCE.md` §1 and §3 are normative.

```text
COM-001  Jurisdiction: Saudi Arabia
  Authority: Zakat, Tax and Customs Authority (ZATCA)
  Requirement: E-invoicing Phase 1 (Generation) enforceable 4 Dec 2021;
    Phase 2 (Integration with FATOORA) rolled out in waves from 1 Jan 2023
  Source URL: zatca.gov.sa/en/E-Invoicing/Introduction/Pages/Roll-out-phases.aspx
  Verified On: 2026-09-27
  Technical Impact: Adapter, outbox, CSID/credentials, gapless numbering
  Evidence: V-01, V-02, V-05
  Open Interpretation: **The organization's wave, VAT threshold status, and
    legal status REQUIRE CONFIRMATION by the authorized tax/compliance
    representative.** Not inferred. (B-01)
```

```text
COM-002  Wave thresholds observed (context only, NOT an applicability claim)
  Wave 24: VAT revenue > SAR 375,000 (2022/2023/2024); deadline 30 June 2026
  Wave 25: VAT revenue > SAR 187,500 (2022-2025); deadline 1 Feb 2027
  Source: zatca.gov.sa news pages; Verified 2026-09-27
  NOTE: A 10-property group plausibly exceeds these thresholds. This is
        stated as an OBSERVATION prompting confirmation, NOT as a
        determination of the organization's legal status. (B-01)
```

```text
COM-003  Jurisdiction: Saudi Arabia
  Authority: SDAIA
  Requirement: PDPL in force since 14 Sep 2023; compliance grace ended
    14 Sep 2024; actively enforced (48 penalty decisions issued in 2025)
  Evidence: V-06, V-07, V-08
  Technical Impact: Minimization, retention, access logging, processor
    management, breach response
  NOTE: **No PDPL compliance claim is made by this document.**
```

```text
COM-004  Cross-border transfer
  Authority: SDAIA — Regulation on Personal Data Transfer Outside the Kingdom
  Requirement: Transfers require an adequacy assessment, appropriate
    safeguards, or another permitted pathway
  Evidence: V-09
  Technical Impact: No automatic PII replication outside Saudi Arabia without
    a documented assessment and approval (D-007)
  Open Interpretation: SDAIA's list of adequate-protection territories is
    reported as not yet confirmed. Treated as an open obligation.
```

```text
COM-005  Shomoos
  Status: APPLICABILITY UNDETERMINED. Omitted from the approved phase scope.
  Owner: TBD (C-03). No requirement is defined and none is assumed.
```

```text
COM-006  National Tourism Monitoring Platform
  Status: APPLICABILITY UNDETERMINED. Omitted from the approved phase scope.
  Owner: TBD (C-03). No requirement is defined and none is assumed.
```

```text
COM-007  VAT
  Requirement: Applies to taxable supplies
  UNRESOLVED: inclusive vs exclusive, rounding stage, rounding mode (C-04).
  Tax RATE is NOT stated in this document — it must be read from an
  authoritative ZATCA source and is currently UNKNOWN.
```

```text
COM-008  Payment / PCI
  Position: The system stores no PAN, CVV, or track data (D-005).
  Status: **NO PCI DSS COMPLIANCE IS CLAIMED.** Tokenization and
    provider-hosted flows reduce scope; actual scope depends on the final
    architecture, the provider relationship, the environments, the controls,
    and the applicable assessment requirements.
```

**The words "compliant" and "certified" appear nowhere in this document set as an affirmative claim about Zafer Al-Asriya.**

---

## 21. Payments and Financial Model

`docs/DATA-MODEL.md` §4; `ADR-0006`, `ADR-0009`, `ADR-0011`.

- Append-only ledger; corrections are compensating entries (`BUS-004`).
- Balance derived from postings, never independently mutated (`BUS-005`).
- Exact decimal arithmetic only; float prohibited (`BUS-006`).
- Payment lifecycle: `INITIATED → REQUIRES_ACTION → AUTHORIZED → CAPTURE_PENDING → CAPTURED → SETTLEMENT_PENDING → SETTLED`, with `PARTIALLY_REFUNDED`, `REFUND_PENDING`, `REFUNDED`, `VOIDED`, `FAILED`, `CANCELLED`, and **`UNKNOWN_OUTCOME`**.
- Refunds are a separate lifecycle and always reference the source payment.
- Every invoice and credit/debit note traces to the originating folio and its transactions.
- Card data is never stored.
- **Deferred:** company folios, group folios, transfers, write-offs, negotiated rates (`H-06`).
- **Unresolved:** currency policy (`C-06`), tax and rounding policy (`C-04`), legal-entity scope (`B-06`).

---

## 22. Reporting and Analytics

`REP-XXX` catalogue `TBD` (`M-03`). Every report must state source of truth, refresh frequency (real-time / near-real-time / daily / period-closed), time zone, currency, export formats, authorization, retention, and reconciliation rule. **Operational and financial definitions must never be mixed without labelling** (`Prd_Maker.md` §29).

Candidate Phase A reports, all `TBD` in detail: occupancy and availability; revenue by property/date/room type; folio and settlement summary; payment and refund summary; tax and invoice register; night audit completion status; integration submission and dead-letter status; audit activity.

---

## 23. Notifications

Events, recipients, channels, templates, locale, retry, and delivery tracking are `TBD`; providers unselected (`C-07`). Channels: email, SMS, WhatsApp, in-app. Sensitive personal or financial data must not appear unnecessarily in a message. Consent mechanics depend on channel and on PDPL and require legal review.

---

## 24. Localization and Accessibility

**Localization:** Arabic (RTL) and English (LTR) in Phase A. Bengali architecturally possible, **deferred** (`M-01`). Gregorian default; Hijri `TBD`. Canonical UTC storage; business date separate from calendar date.

**Accessibility:** target standard and level `TBD` (`C-08`). Direction correctness is treated as a correctness concern, not a cosmetic one, and RTL/LTR is a test matrix dimension.

---

## 25. Non-Functional Requirements

**All thresholds are `TBD`.** They are not invented. Setting a latency target without peak concurrency, request mix, and dataset size would be fabrication (`Prd_Maker.md` §20.1, §63).

| ID | Area | Requirement | Status |
|---|---|---|---|
| `NFR-001` | Performance | p50/p95/p99 per endpoint class | `TBD` (`B-05`) |
| `NFR-002` | Concurrency | Peak concurrent users and requests/sec | `TBD` (`B-05`) |
| `NFR-003` | Search latency | Availability and guest search | `TBD` (`B-05`, `M-04`) |
| `NFR-004` | Availability | Uptime target and measurement window | `TBD` (`C-01`) |
| `NFR-005` | Reliability | Bounded retry, DLQ, reconciliation, transaction boundaries — **specified in `ADR-0005`** | Defined |
| `NFR-006` | Recovery | RPO, RTO, backup frequency, retention, restore validation | `TBD` (`C-01`, `H-07`) |
| `NFR-007` | Security | Per `ADR-0014`, `ADR-0016`, `ADR-0021` — **specified** | Defined |
| `NFR-008` | Accessibility | WCAG version and level | `TBD` (`C-08`) |
| `NFR-009` | Localization | Language, direction, formatting | Defined (`ADR-0015`) |
| `NFR-010` | Observability | Structured logs with correlation IDs; metrics; traces; alerts with threshold, severity, owner, escalation, runbook | Partially defined; stack `TBD` (`H-04`) |
| `NFR-011` | Capacity planning | Data volume, growth, scaling trigger | `TBD` (`B-05`) |

**No performance result, coverage figure, or benchmark exists or is claimed.**

---

## 26. Reliability / Backup / Disaster Recovery

See `docs/DEPLOYMENT.md`. Automated backups; encrypted backups in approved Saudi-region storage; documented retention (**values `TBD`**); periodic restore-validation tests (**cadence `TBD`**); documented DR procedures; **RPO and RTO `TBD` (`C-01`)**. Cross-region warm standby is **designed** but no automatic guest-PII replication outside Saudi Arabia may be activated without an approved transfer assessment (`D-007`). Provider unselected (`B-03`).

---

## 27. Observability / Operations

Structured logs with correlation, request, actor, and integration IDs; **never secrets, never raw card data, never identity-document numbers**. Metrics at minimum: request count, latency, errors, queue depth, retry count, dead-letter count, reconciliation backlog, integration success rate, database health, resource saturation. Traces for multi-step critical workflows. Alerts must have a threshold, severity, owner, escalation, and runbook. Stack `TBD` (`H-04`). **No 24/7 support model yet (`H-05`).**

---

## 28. Audit Trail

`ADR-0016`. Append-only; who/what/when/where/before/after/reason/correlation/source/result; separation of duties; no user may alter the record of their own action; every reveal of a masked identity field is audited; audit never included in guest-facing exports; support audit access is scoped and audited; retention `TBD` (`C-09`).

---

## 29. Error Handling / Retry / Idempotency / Reconciliation

The `Prd_Maker.md` §33 outcome set is defined for every external and asynchronous workflow: success · transient failure · permanent failure · timeout · duplicate · out-of-order event · partial success · **unknown result** · manual review.

The sanctioned pattern is `ADR-0005`: command → transactional write (business + outbox) → worker → external API → success / retry / dead letter → reconciliation. Retries are bounded with backoff and jitter. **Never retry blindly after an unknown payment or financial outcome.** Idempotency keys on every money, inventory, and reservation operation.

---

## 30. Import / Export / Migration

`D-008`: no legacy migration in the Phase A critical path. The model is import-friendly. A future import must support mapping, validation, deduplication, referential integrity, dry-run, record-count reconciliation, error reporting, rollback before commit, audit trail, and batch tracking. **If a real legacy PMS is later identified, stop and open a dedicated migration workstream before importing any production data.** Export formats: `TBD` (`M-03`); exports are role-gated, volume-limited, and audited.

---

## 31. Feature Prioritization

| ID | Feature | Priority | Reason | Phase |
|---|---|---|---|---|
| F-001 | Properties, room types, rooms | P0 | Foundation | A |
| F-002 | Reservations + atomic inventory | P0 | Core correctness risk | A |
| F-003 | Guests + identity fields | P0 | Operational necessity | A |
| F-004 | Check-in/out, room transfer | P0 | Core operation | A |
| F-005 | Folio, charges, taxes, payments, refunds | P0 | Money correctness | A |
| F-006 | Night audit | P0 | Period close | A |
| F-007 | Housekeeping | P0 | Room availability correctness | A |
| F-008 | RBAC + property scope + audit | P0 | Security baseline | A |
| F-009 | ZATCA compliance adapter | P0 | **Regulated obligation (`D-003`)** | A |
| F-010 | Bilingual RTL/LTR UI | P0 | Arabic-first product | A |
| F-011 | Reporting | P1 | Operational need | A |
| F-012 | Notifications | P1 | Guest service | A |
| F-013 | CRS + booking engine | P1 | Product scope | **B** |
| F-014 | Online card capture | P1 | Needed by CRS | **B** |
| F-015 | Channel Manager (4 OTAs) | P2 | Distribution | **C** |
| F-016 | POS + cashier + room charge | P2 | Property operation | **C** |
| F-017 | Company/group folios, transfers, write-offs | P1 | Deferred, unscheduled (`H-06`) | `TBD` |
| F-018 | Bengali localization | P2 | Architecturally possible (`M-01`) | Deferred |

---

## 32. Release Plan

| Stage | Entry criteria | Exit criteria |
|---|---|---|
| Development | Phase A requirements approved; blockers affecting the task cleared | Task acceptance criteria pass |
| Internal QA | Feature complete for the module | State, concurrency, security, privacy suites green |
| UAT | QA green; runbooks drafted | Hotel staff accept; defects triaged |
| Sandbox/certification | Provider sandbox available (`B-04`) | Provider certification where required |
| Pilot | UAT accepted; DR rehearsed; RPO/RTO set | Pilot success criteria met |
| Gradual rollout | Pilot stable | Per-property rollout |
| Full rollout (10 properties) | Each property validated | — |
| Stabilization | — | KPI review; defect trend acceptable |

**Phase A cannot enter QA while `B-03` (provider) and `C-01` (RPO/RTO) remain open.**

---

## 33. Pilot / Rollout / Rollback

Pilot: selected properties, defined duration, explicit success criteria, telemetry, support model, rollback trigger, training, and incident escalation. Support model is `TBD` (`H-05`).

Rollback per `Prd_Maker.md` §39: trigger, decision owner, rollback method, data compatibility, schema rollback safety, external side effects, customer communication, recovery verification. **For Phase A, a greenfield cutover, rollback is primarily a configuration and access rollback; schema rollback safety must be assessed per migration.** Night audit and financial postings are not reversible by rollback — they require compensating entries, which is why the pilot scope is limited.

---

## 34. Testing Strategy

`docs/TEST-STRATEGY.md` and `ADR-0021`. Layers: unit, state machine, contract, integration, **concurrency (release gate)**, security, privacy, recovery, performance, accessibility, UAT.

**The concurrency suite is a Phase A release gate, not optional**, because it is the only evidence for `BUS-001`. Each case must be demonstrated to fail when its control is removed.

Synthetic data only. No coverage percentage, latency figure, or benchmark is reported, because nothing has been measured.

---

## 35. Acceptance Criteria

Given/When/Then. Full set in `docs/TASKS.md`; the P0 core:

```text
AC-FR-002-01  Given one available sellable unit and two concurrent valid
  booking attempts, when both attempt allocation, then exactly one reaches
  CONFIRMED, the other receives INVENTORY_UNAVAILABLE, and no duplicate
  confirmed allocation exists.

AC-FR-002-02  Given a successful allocation, when the same request is
  replayed with the same Idempotency-Key, then the original result is
  returned and no second allocation is created.

AC-FR-002-03  Given the row lock is removed from the allocation
  transaction, when AC-FR-002-01 runs, then it FAILS. (The removal test.)

AC-FR-004-01  Given a room in DIRTY housekeeping status, when a check-in
  attempts to occupy it, then the check-in is rejected with
  ROOM_NOT_OCCUPIABLE and no folio is opened.

AC-FR-004-02  Given two agents checking in the same room concurrently, when
  both submit, then exactly one succeeds and the other receives a
  deterministic denial.

AC-FR-005-01  Given a posted folio posting, when a correction is required,
  then a compensating entry referencing the original is created and the
  original is unchanged.

AC-FR-005-02  Given a folio with postings, when the balance is recomputed,
  then it equals the sum of its postings, and the stored projection can be
  rebuilt to the same value.

AC-FR-005-03  Given a monetary computation, when it is executed, then no
  binary floating-point arithmetic is involved and the value is exact.

AC-FR-006-01  Given a payment request whose result is not confirmed, when
  the timeout elapses, then the payment is UNKNOWN_OUTCOME, the API returns
  retryable:false, NO provider retry occurs, and a reconciliation task is
  created.

AC-FR-006-02  Given a card payment, when it is recorded, then no PAN, CVV,
  or track data is stored in any table, log, or telemetry.

AC-FR-007-01  Given a night audit interrupted at step 4 of 9, when it is
  resumed, then it continues from step 4 and no charge, revenue posting, or
  invoice is duplicated.

AC-FR-007-02  Given a step failure, when the run encounters it, then the run
  STOPS and does not proceed to the next step, and the business date is not
  marked CLOSED.

AC-FR-007-03  Given two concurrent night audit invocations for the same
  property and business date, when both run, then at most one proceeds.

AC-FR-008-01  Given an issued invoice, when the FATOORA submission fails
  permanently, then the invoice remains ISSUED, the submission is
  DEAD_LETTERED and visible, an alert fires, and the checkout is NOT rolled
  back.

AC-FR-008-02  Given invoice numbering, when failures are injected at every
  issuance point, then the sequence contains no gaps and no duplicates.

AC-FR-008-03  Given an invoice, when it is traced, then every charge,
  payment, and tax posting that contributed to it is reachable.

AC-FR-009-01  Given a user granted property A only, when they request
  property B, then the request is DENIED — not an empty result.

AC-FR-010-01  Given any auditable action, when it completes, then an
  append-only audit record exists with all nine required fields.

AC-FR-010-02  Given a masked document number, when an authorized user
  reveals it, then an audit record is created identifying the user, the
  time, and the record — and the value itself is NOT written to the log.

AC-FR-011-01  Given the Arabic locale, when any flow renders, then direction
  is RTL and layout uses logical properties with no mirrored logic error.

AC-FR-011-02  Given the English locale, when the same flow renders, then
  direction is LTR and no Arabic string is truncated or misordered.
```

---

## 36. Dependencies

| ID | Dependency | Type | Owner | Criticality | Failure impact | Mitigation |
|---|---|---|---|---|---|---|
| DEP-001 | ZATCA developer documentation | government | `TBD` | **Critical** | ZATCA workstream cannot be designed (`B-02`) | Obtain and cite; `UNKNOWN` until then |
| DEP-002 | Tax/legal representative confirmation | legal | `TBD` | **Critical** | Wave and compliance position unknown (`B-01`) | Written confirmation |
| DEP-003 | Cloud provider | infrastructure | `TBD` | **Critical** | No deployment possible (`B-03`) | `ADR-0020` |
| DEP-004 | Payment provider + sandbox | vendor | `TBD` | **Critical** | Payment workstream blocked (`B-04`) | Adapter abstraction contains it |
| DEP-005 | Operating scale data | internal | `TBD` | **Critical** | No NFR target possible (`B-05`) | Operations supplies |
| DEP-006 | Legal entity determination | legal/finance | `TBD` | **Critical** | Invoice sequence scope unknown (`B-06`) | Finance supplies CR/VAT numbers |
| DEP-007 | Hotel operations staff for UAT | internal | `TBD` | High | Acceptance cannot be judged | UAT plan in Phase A |
| DEP-008 | Notification providers | vendor | `TBD` | Medium | Guest notifications delayed (`C-07`) | Channels configurable |
| DEP-009 | Hotel staff onboarding and training | internal | `TBD` | High | Pilot fails operationally | Training plan in rollout |
| DEP-010 | ZATCA onboarding / CSID issuance | government | `TBD` | High | Cannot submit in production | Start early; long lead time |
| DEP-011 | Security, privacy, compliance, QA, finance, ops owners | internal | `TBD` | High | No Definition of Done possible (`C-10`) | Named in Phase 0 |

---

## 37. Assumptions

| ID | Assumption | Why needed | Risk if wrong | Owner | Validation date | Status |
|---|---|---|---|---|---|---|
| `ASM-001` | The group is subject to Saudi VAT and tax invoicing | Justifies the entire ZATCA workstream | Wrong legal entity or basis | `TBD` | Before Phase A5 | `ASSUMED` |
| `ASM-002` | Ten properties, materially similar operating model | Shared configuration and reporting | Property-specific requirements surface late | `TBD` | Phase A1 | `ASSUMED` |
| `ASM-003` | Arabic-first UI with English peer | UI and content plan | English-primary needed | `TBD` | Phase A6 | `CONFIRMED` (`D-002`) |
| `ASM-004` | SAR is the operating currency | Currency and rounding design | Multi-currency required | `TBD` | Before Phase A5 | `ASSUMED` (`C-06`) |
| `ASM-005` | Card payment at property via terminal, not online, in Phase A | Payment scope | Online needed in Phase A | `TBD` | Phase A3 | `CONFIRMED` (`D-005`) |
| `ASM-006` | Document **fields** are sufficient operationally | Identity data design | Regulatory verification requires more (`C-02`) | `TBD` | Before Phase A3 | `ASSUMED` — explicitly **not** a legal determination |
| `ASM-007` | No legacy system needs migrating | Rollout plan | Migration workstream required | `TBD` | Phase 0 | `CONFIRMED` (`D-008`) — re-verify at Phase 0 |
| `ASM-008` | A managed cloud with a Saudi region is available | Hosting | Hosting decision must change | `TBD` | Phase 0 | `ASSUMED` (`B-03`) |

---

## 38. Risks and Mitigations

| ID | Risk | Probability | Impact | Severity | Mitigation | Trigger | Owner | Residual |
|---|---|---|---|---|---|---|---|---|
| `RISK-001` | Double booking occurs in production | Low (controls designed) | **Critical** | **Critical** | `ADR-0008` combined controls + concurrency gate | Any duplicate allocation | Eng | Low if gate enforced |
| `RISK-002` | Duplicate charge from a retry | Low | **Critical** | **Critical** | Idempotency + `UNKNOWN_OUTCOME` + no blind retry | Any duplicate payment | Eng | Low |
| `RISK-003` | Duplicate or gapped tax invoice | Medium | **Critical** | **Critical** | Gapless sequence + step idempotency + resume-not-restart | Any sequence anomaly | Eng/Compliance | Medium until `B-02` |
| `RISK-004` | Group is out of ZATCA compliance independent of this project | **Unknown** | **Critical** | **Critical** | Escalate to the tax representative | Wave 24/25 deadline passed | PM | **Unknown** — cannot be assessed without `B-01` |
| `RISK-005` | Night audit leaves an ambiguous business date | Medium | **Critical** | **Critical** | Fail-stop + resumable steps + step ledger | Any unhandled failure | Eng | Low |
| `RISK-006` | Property data leak across the group | Medium | High | **High** | Explicit scope grants + server enforcement + breakout tests | Any scope-denial failure | Eng/Sec | Low |
| `RISK-007` | Identity data exposure | Low | High | **High** | Minimization, encryption, masking, access logging, log scanning | Any identity field in a log | Eng/Sec | Low |
| `RISK-008` | Guest PII leaves Saudi Arabia | Low | **Critical** | **High** | No auto-replication; transfer assessment gate | Any cross-region PII config | Ops | Low if gate enforced |
| `RISK-009` | Requirements built on unverified regulatory assumptions | **High** currently | High | **High** | `UNKNOWN` marking + named owners | Any affirmative claim without a source | PM | **High until `B-01`/`B-02` close** |
| `RISK-010` | Scope pressure re-admits POS into Phase A | Medium | High | **High** | `D-002`; POS would create a third posting path | POS work requested in Phase A | PM | Low |
| `RISK-011` | Delays from blockers | **High** | Medium | Medium | Start unblocked tasks T-001…T-006 in parallel | Phase 0 | PM | Medium |
| `RISK-012` | Concurrency tests become flaky and get disabled | High | **Critical** | **High** | No flaky tolerance; removal test | First flake | Eng | Medium |
| `RISK-013` | Cloud provider unavailable in a Saudi region | Low | High | **High** | `ADR-0020` hard gate G-1 | Provider evaluation | PM | Low |
| `RISK-014` | NFR targets remain unset at release | Medium | Medium | Medium | Force `B-05` resolution in Phase 0 | Approach Phase A7 | PM | Medium |

---

## 39. Open Questions

> **Status register.** The authoritative dated status of `B-01`, `B-02`, `B-03`, `B-04`, `B-05`, `B-06`, `C-01`, and `C-04` is recorded in `docs/BLOCKER-STATUS.md`. As of 2026-09-27 all eight were re-confirmed by the project manager as **not confirmed / not selected / unknown**. No value in this table may be inferred or defaulted.

| ID | Question | Why it matters | Owner | Due | Blocking |
|---|---|---|---|---|:-:|
| `Q-001` | What is Zafer Al-Asriya's ZATCA wave and current status? | Determines whether a compliance exposure exists **today** | PM + tax rep | Phase 0 | **Yes** (`B-01`) |
| `Q-002` | One legal entity or several? CR and VAT numbers? | Invoice sequence scope and credential count | PM + finance | Phase 0 | **Yes** (`B-06`) |
| `Q-003` | Room counts, occupancy, staffing, peak concurrency per property? | Every NFR and the capacity plan | PM + ops | Phase 0 | **Yes** (`B-05`) |
| `Q-004` | Which cloud provider? | All infrastructure work | PM | Phase 0 | **Yes** (`B-03`) |
| `Q-005` | Which payment provider, and is a sandbox available? | Payment workstream | PM + finance | Phase A3 | **Yes** (`B-04`) |
| `Q-006` | May engineering obtain ZATCA developer documentation? | Removes all `UNKNOWN` from the ZATCA design | Compliance | Phase 0 | **Yes** (`B-02`) |
| `Q-007` | VAT inclusive or exclusive; rounding stage and mode; precision/scale | Every monetary computation | Finance | Phase A5 | Yes (`C-04`) |
| `Q-008` | Business date, cut-off, same-day arrival, late checkout, reopen policy | Night audit correctness | Ops + finance | Phase A4 | Yes (`C-05`) |
| `Q-009` | SAR only, or multi-currency with FX? | Ledger design | Finance | Phase A5 | Yes (`C-06`) |
| `Q-010` | Who are the accountable security, privacy, compliance, QA, finance, ops owners? | Definition of Done cannot be met | PM | Phase 0 | Yes (`C-10`) |
| `Q-011` | Do verified Saudi rules require specific guest registration fields, and for how long? | Identity data design and retention | Legal | Phase A3 | Yes (`C-02`) |
| `Q-012` | Do Shomoos or the National Tourism Monitoring Platform apply? | Possible undeclared scope | PM + legal | Phase 0 | Yes (`C-03`) |
| `Q-013` | RPO, RTO, backup retention, restore-validation cadence? | Production approval | Ops | Phase A7 | Yes (`C-01`) |
| `Q-014` | WCAG version and level? | A11Y requirements and tests | PM | Phase A6 | No (`C-08`) |
| `Q-015` | Which phase for company/group folios, transfers, write-offs, negotiated rates? | Financial model completeness | PM + finance | Phase A3 | No (`H-06`) |
| `Q-016` | Accessibility, support hours, SLA, retention schedule, report catalogue, search requirements | Completeness | PM | Phase A6 | No |

---

## 40. Decision Log

| ID | Date | Decision | Alternatives considered | Reason | Impact | Owner |
|---|---|---|---|---|---|---|
| `DEC-001` | 2026-09-27 | Single tenant, 10 properties, one deployment | Multi-tenant SaaS; DB-per-tenant; schema-per-tenant | `D-001` | Property-level isolation; future-SaaS-safe model | PM |
| `DEC-002` | 2026-09-27 | Phased release: A/B/C | Single combined release; PMS-only | `D-002` | Phase A focused on correctness | PM |
| `DEC-003` | 2026-09-27 | ZATCA e-invoicing is P0 in Phase A | Defer to later; external fiscal system | `D-003` | Compliance adapter workstream | PM |
| `DEC-004` | 2026-09-27 | Identity fields only, no images | Store images; store nothing | `D-004` | Minimization; smaller breach impact | PM |
| `DEC-005` | 2026-09-27 | Cash + tokenised terminal; provider-agnostic adapter | Online capture in Phase A; manual keying | `D-005` | Minimal PCI scope | PM |
| `DEC-006` | 2026-09-27 | Laravel/MySQL/Vue modular monolith | NestJS+MySQL+React; Postgres; microservices | `D-006` | Single deployable; ACID allocation | PM |
| `DEC-007` | 2026-09-27 | Saudi-region managed cloud, provider-neutral | On-premise; hybrid; multi-cloud | `D-007` | Residency; no auto cross-border PII | PM |
| `DEC-008` | 2026-09-27 | Greenfield, no migration | Import history; parallel run | `D-008` | Simpler cutover | PM |
| `DEC-009` | 2026-09-27 | Repository name does not determine release boundary | Treat `pms-crs` as the scope | PM instruction | Scope from approved product phases | PM |

---

## 41. Traceability Matrix

`Goal → User need → Requirement → Business rule → State machine → Data → API → Task → Test → Acceptance criterion → KPI`

| Goal | Need | Requirement | Rule | State | Data | Task | Test | AC | KPI |
|---|---|---|---|---|---|---|---|---|---|
| `G-01` | No double booking | `FR-002` | `BUS-001`, `BUS-002` | `§C` | `DR-003`, `DR-013` | `T-007` | `CON-01`, `CON-02` | `AC-FR-002-01/02/03` | `KPI-01` |
| `G-02` | No double charge | `FR-005`, `FR-006` | `BUS-002`, `BUS-003`, `BUS-004` | `§D`, `§E`, `§F` | `DR-007`, `DR-008`, `DR-014` | `T-009`, `T-010` | `CON-04`, `CON-07` | `AC-FR-005-01/02/03`, `AC-FR-006-01/02` | `KPI-02` |
| `G-03` | Controlled invoicing | `FR-008` | `BUS-010`, `BUS-011` | `§H`, `§J.1` | `DR-009`, `DR-010` | Phase A5 | Sequence gaplessness; DLQ | `AC-FR-008-01/02/03` | `KPI-03`, `KPI-04` |
| `G-04` | Provable isolation | `FR-009` | `BUS-009` | — | `DR-001`, `DR-002` | `T-003` | Authorization matrix; breakout | `AC-FR-009-01` | — |
| `G-05` | Full attribution | `FR-010` | `BUS-014` | `§J.5` | `DR-012` | Phase A1 | Append-only; redaction | `AC-FR-010-01/02` | — |
| `G-06` | Full traceability | all | `BUS-007` | all | `DR-014` | all | State machine matrix | all | `KPI-06` |

**Known traceability gaps (marked, not repaired by assumption):**

| Item | Gap |
|---|---|
| `H-06` company/group folios, transfers, write-offs, negotiated rates | Requirement defined as deferred; no state machine, no task, no AC until phased (`Q-015`) |
| `C-04` tax/rounding | No test can be written without the rounding stage and mode |
| `C-05` business date | Night audit ACs can assert resumability but not period correctness without the policy |
| `B-02` ZATCA specifics | No acceptance criterion can assert protocol conformance |
| `C-03` Shomoos / tourism platform | Requirement not defined; no chain exists by design |
| `B-06` legal entity | Invoice sequence scope and credential count cannot be specified |

---

## 42. Definition of Ready

A requirement is Ready when: purpose, actor, scope, business rule, dependencies, data, authorization, failure behaviour, acceptance criteria, security/privacy implications, and compliance review are all defined, with no blocking open question. Per `Prd_Maker.md` §42.

**Current status:** no Phase A5 (ZATCA) requirement and no Phase A7 (infrastructure) requirement is Ready. `FR-001`–`FR-004`, `FR-009`–`FR-012` are Ready once their `TBD` operational parameters are set by operations.

---

## 43. Definition of Done

Per `Prd_Maker.md` §43: code complete, review complete, automated tests complete, acceptance criteria pass, security checks pass, observability present, audit behaviour present, documentation updated, migration complete if needed, rollback assessed, production configuration verified, support/runbook available.

**Blocked because no accountable owners are named for security, privacy, compliance, QA, finance, or operations (`C-10`).** A Definition of Done with no verifier is not a Definition of Done.

---

## 44. Change Control

`CR-XXX` per `Prd_Maker.md` §68. Changes affecting external contracts, regulated behaviour, financial rules, or security controls require explicit review by the relevant owner. **No change request has been raised to date.**

---

## 45. Source Verification Log

| ID | Claim | Source | Type | Version/date | Verified | Applies to | Notes |
|---|---|---|---|---|---|---|---|
| `V-01` | ZATCA Phase 1 enforceable 4 Dec 2021 | `zatca.gov.sa/en/E-Invoicing/Introduction/Pages/Roll-out-phases.aspx` | 1 — Authority | Page last update 01 Sep 2026 | 2026-09-27 | `COM-001` | Excludes non-resident taxpayers and parties invoicing on behalf of VAT-registered suppliers |
| `V-02` | Phase 2 in waves from 1 Jan 2023; ≥6 months notice | same | 1 | same | 2026-09-27 | `COM-001` | — |
| `V-03` | Wave 24: VAT revenue > SAR 375,000; deadline 30 Jun 2026 | `zatca.gov.sa/en/Pages/news-1426.aspx` | 1 | Published 26 Sep 2025 | 2026-09-27 | `COM-002` | Context only — not an applicability claim |
| `V-04` | Wave 25: > SAR 187,500 (2022–2025); deadline 1 Feb 2027 | `zatca.gov.sa/en/MediaCenter/News/Pages/default.aspx` | 1 | Current | 2026-09-27 | `COM-002` | Context only |
| `V-05` | Phase 2 requires FATOORA integration, specified format, extra fields | `zatca.gov.sa/en/E-Invoicing/Pages/default.aspx` | 1 | Site last update 10 Aug 2026 | 2026-09-27 | `COM-001` | Field list `UNKNOWN` |
| `V-06` | PDPL effective 14 Sep 2023; grace ended 14 Sep 2024; in force | DLA Piper / CMS expert guide 25 Sep 2026; DLA Piper DPL | 2–6 | 2026-09-25 | 2026-09-27 | `COM-003` | Primary SDAIA text not read in this pass |
| `V-07` | 48 SDAIA penalty decisions issued in 2025 | `spa.gov.sa/en/N2489505` (16 Jan 2026) | 2 | 2026-01-16 | 2026-09-27 | `COM-003` | Violations included processing without legal basis and missing technical/organizational measures |
| `V-08` | PDPL sanctions up to SAR 5,000,000; criminal offence for harmful sensitive-data disclosure | DLA Piper, CMS | 2–6 | 2026-09-25 | 2026-09-27 | `COM-003` | — |
| `V-09` | Regulation on Personal Data Transfer Outside the Kingdom in force | `sdaia.gov.sa/en/SDAIA/about/Pages/RegulationsAndPolicies.aspx`; `spa.gov.sa/en/N2163905` | 1–2 | Regulation updated Sep 2024 | 2026-09-27 | `COM-004` | Adequate-protection territory list reported as unconfirmed |

**Explicitly `UNKNOWN — requires confirmation from the authoritative source before production`:** ZATCA API endpoints · request/response schemas · certificate and CSID requirements · TLV/QR binary structure and signing · UBL schema constraints · onboarding procedure · ZATCA error codes · ZATCA rate limits · ZATCA sandbox availability · ZATCA invoice field list · the applicable VAT rate · Zafer Al-Asriya's wave · payment provider API and capabilities · cloud provider region, SLA, and price.

**No claim in this document set is labelled compliant.**

---

## 46. Final Quality Gate

`Prd_Maker.md` §65: GREEN requires all P0 requirements to have clear behaviour, ownership, data, state, acceptance criteria, security/privacy review, dependencies, failure handling, and no blocking open question.

### **READINESS STATUS: RED — NOT READY FOR DEVELOPMENT**

RED triggers present (§65): unverified mandatory regulatory claim · missing critical lifecycle state (multiple `TBD` policies) · undefined financial behaviour (rounding, currency, entity scope) · missing data ownership (no accountable owners) · unresolved critical dependencies · unsafe external integration assumption (two unselected providers) · missing acceptance criteria for parts of critical behaviour.

### Blockers (6)

`B-01` Organization's ZATCA wave and legal status unconfirmed · `B-02` ZATCA API/certificate/CSID details `UNKNOWN` · `B-03` No cloud provider · `B-04` No payment provider or sandbox · `B-05` Operating scale unknown · `B-06` Invoicing legal entity undetermined

### Critical issues (10)

`C-01` RPO/RTO undefined · `C-02` Guest registration requirements unverified · `C-03` Shomoos / tourism platform applicability undetermined · `C-04` Tax and rounding policy undefined · `C-05` Business date and cut-off undefined · `C-06` Currency policy undefined · `C-07` Notification providers unselected · `C-08` No accessibility target · `C-09` Retention schedule unset · `C-10` No accountable owners

### High (7)

`H-01` No CI/CD · `H-02` No concurrency harness · `H-03` Key management incomplete · `H-04` Observability stack unselected · `H-05` No 24/7 support model · `H-06` Group/company financial scope unscheduled · `H-07` Backup retention and restore-validation unset

### Medium (5) · Low (3)

`M-01` Bengali deferred · `M-02` No performance baseline · `M-03` Report export formats · `M-04` Search requirements · `M-05` Support hours/SLA
`L-01` POS/CM deferred · `L-02` OTA partner behaviour `UNKNOWN` · `L-03` ID naming convention

### Release gate matrix (§64)

| Gate | Required | Owner | Status | Evidence |
|---|---|---|---|---|
| Scope frozen | Yes | PM | **No** — blockers open | `D-002` defined but gates not passed |
| P0 requirements complete | Yes | PM | **No** | §14, §36, §41 |
| State machines reviewed | Yes | Tech | **Partial** | `docs/STATE-MACHINES.md` drafted; `TBD` policies open |
| Security review | Yes | Security | **No** — no owner | `C-10` |
| Privacy review | Applicable | Privacy/Legal | **No** — no owner | `C-10` |
| Regulatory verification | Applicable | Compliance | **No** | `B-01`, `B-02`, `C-03` |
| API contracts | Yes | Tech | Drafted, unverified | `docs/API-SPEC.md` |
| Acceptance criteria | Yes | QA | **Partial** | §35 core only; no owner (`C-10`) |
| P0 tests pass | Yes | QA | **No** — no code, no harness | `H-02` |
| Performance test | Applicable | Tech | **No** | `B-05` |
| DR test | Required | Ops | **No** | `B-03`, `C-01` |
| Migration rehearsal | Not applicable | — | N/A | `D-008` |
| Rollback plan | Yes | Release | Drafted | §33 |
| Monitoring | Yes | Ops | **No** | `H-04` |
| Runbook | Yes | Ops | **No** | `H-05` |

### What may proceed now

`T-001`–`T-006` in `docs/TASKS.md` (scaffold, organization/property, identity and access, authentication, room master data, housekeeping) are **not blocked** by any issue above and may begin once this PRD is approved to proceed to implementation of those specific tasks.

**This PRD must not be marked *Approved for Development*.** Per `Prd_Maker.md` §7: *"Never use 'Approved for Development' simply because the user says approved."*
