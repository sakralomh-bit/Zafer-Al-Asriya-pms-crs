# Zafer Al-Asriya v1.0 — Engineering Discovery Report

| Field | Value |
|---|---|
| Project | Zafer Al-Asriya v1.0 |
| Project Manager | محمد فايز |
| Repository | `https://github.com/mohamedabofayz` |
| Document | `docs/DISCOVERY.md` |
| Version | 0.1 |
| Status | Draft — discovery complete, planning only |
| Created | 2026-09-27 |
| Last Updated | 2026-09-27 |
| Governing framework | `Prd_Maker.md` v2.0.1 (unmodified) |

---

## 0. How to read this document

This is a **discovery and gap analysis**, not a code review. The brief asked for a repository audit; the honest result of that audit is that there is nothing to audit. Section 1 states this with evidence. Everything after it is a **target-state specification and gap analysis** derived from two sources only:

1. The confirmed product decisions of the project manager (recorded in `docs/PRD.md` §1 and the ADRs).
2. `Prd_Maker.md`, the governing framework.

Where information is not available, this document writes `TBD`, `ASSUMED`, `BLOCKED`, `UNKNOWN`, or `NOT_APPLICABLE` rather than guessing. It does not claim that any capability, API, regulation, SLA, credential, or integration exists.

**Language note:** technical documentation is written in English with technical identifiers preserved in English, per `Prd_Maker.md` §66 and §67. The product **user interface** is Arabic-first (see `docs/ADR/0015-localization-and-rtl-architecture.md`). An Arabic executive summary is provided in `docs/PRD.md` §2.

---

## 1. Repository Audit

### 1.1 Method

Directory enumeration was performed on `C:\Users\User\Desktop\Zafer Al-Asriya pms-crs` using a recursive file glob, a root-level glob, and a dotfile glob.

### 1.2 Finding

**The repository contains exactly one file: `Prd_Maker.md` (2,944 lines).**

| Item | Finding | Evidence |
|---|---|---|
| Directory structure | Single file at repository root | Recursive glob returned exactly one path |
| Documentation | `Prd_Maker.md` only | No `README`, `LICENSE`, `CONTRIBUTING`, `CHANGELOG` |
| `AGENTS.md` | **Absent** | Dotfile and recursive glob returned no match |
| Languages | **None present** | No `.php`, `.ts`, `.vue`, `.py`, `.js`, `.java`, `.cs` |
| Frameworks | **None present** | No framework manifest |
| Package managers | **None present** | No `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml`, `requirements.txt`, `pyproject.toml`, `go.mod` |
| Databases | **None present** | No schema, no migrations, no seeds, no ERD, no ORM models |
| Source code | **None present** | No controllers, services, models, jobs, commands |
| APIs | **None present** | No routes, no OpenAPI/Swagger specification, no endpoint definitions |
| Tests | **None present** | No test directory, no test runner configuration, no coverage config |
| CI/CD | **None present** | No `.github/`, no pipeline YAML, no hooks |
| Infrastructure | **None present** | No Dockerfile, no Terraform, no Kubernetes manifests, no IaC of any kind |
| Environment configuration | **None present** | No `.env.example`, no config template, no documented environment variables |
| Authentication | **None present** | — |
| External integrations | **None present** | No SDK, no client, no adapter, no webhook handler |
| Deployment mechanism | **None present** | No deployment script, no release config, no runbook |
| Version control metadata | No dotfiles or hidden directories returned by enumeration | — |
| Tests / coverage results | **None** | Nothing has been executed, because nothing exists to execute |

### 1.3 What this means

This is a **greenfield project**. There is:

- No existing system to extend, refactor, migrate, or reverse-engineer.
- No prior technology choice to preserve or unpick.
- No regression risk from existing behaviour.
- No measured performance, no recorded defects, no operational history.
- No historical requirement that could contradict the governing framework.

It also means the words "current technology stack", "current deployment mechanism", and "existing external integrations" in the task brief have **no answer in the repository**. They were resolved by decision (`D-001`…`D-008`), not by discovery, and each is recorded in an ADR.

### 1.4 Contradiction check against `Prd_Maker.md`

The brief describes a project whose requirements must be checked for contradiction against `Prd_Maker.md`. With a single-file repository, there is no internal contradiction to find.

One external contradiction was found and resolved by the project manager:

| ID | Contradiction | Resolution |
|---|---|---|
| X-01 | The directory is named `pms-crs`, implying a combined PMS + CRS first release, while the confirmed role list is heavily PMS/cashiering/night-audit oriented. Both could not be equally P0. | Resolved by `D-002`: Phase A is PMS Core; CRS is Phase B; Channel Manager and POS are Phase C. The project manager stated explicitly that the repository name does not determine the release boundary. |

One scope omission was found and is **not** resolved:

| ID | Omission | Handling |
|---|---|---|
| X-02 | ZATCA e-invoicing, Shomoos, and the National Tourism Monitoring Platform appear in `Prd_Maker.md` §23 and §51 as verification prompts for Saudi projects, but were absent from the project manager's initial phase scope. | ZATCA e-invoicing was elevated to a P0 Phase A workstream by explicit decision (`D-003`). Shomoos and the National Tourism Monitoring Platform remain `TBD` with a named owner (critical issue `C-03`) and are recorded as an open compliance question, not as requirements. |

### 1.5 Standing evidence rules for this document set

1. No capability is described as implemented, existing, or complete. Nothing is implemented.
2. No external API, endpoint, credential, certificate, tax rate, price, SLA, certification, or wave assignment is asserted without a source, date, and version in `docs/COMPLIANCE.md` §3.
3. No compliance, PCI, or PDPL conformance is claimed anywhere. Encryption and tokenization do not establish compliance (`Prd_Maker.md` §51, §27, §22).
4. No test result, coverage figure, latency number, or throughput figure is reported. Nothing has been measured.
5. Zafer Al-Asriya's own ZATCA wave, VAT threshold status, and legal compliance status are **not inferred** from the number of hotels or any revenue estimate. They require confirmation by the organization's authorized tax/compliance representative.

---

## 2. Existing vs Missing Matrix

Legend: **Exists** = evidence in repository · **Partial** = partial evidence · **Missing** = no evidence of any kind.

Evidence value `ABSENT — no repository artifact exists` is used for every `Missing` row, because the repository contains only `Prd_Maker.md`.

### 2.1 PMS Core (Phase A)

| Requirement | Exists | Partial | Missing | Evidence | Priority |
|---|:-:|:-:|:-:|---|---|
| Hotel / property management | | | ✔ | ABSENT | P0 |
| Room types | | | ✔ | ABSENT | P0 |
| Physical rooms | | | ✔ | ABSENT | P0 |
| Room status / room state machine | | | ✔ | ABSENT | P0 |
| Room inventory and availability | | | ✔ | ABSENT | P0 |
| Atomic inventory allocation / anti-double-booking | | | ✔ | ABSENT | P0 |
| Reservations | | | ✔ | ABSENT | P0 |
| Reservation state machine | | | ✔ | ABSENT | P0 |
| Guests and guest profiles | | | ✔ | ABSENT | P0 |
| Guest identity documents (fields only) | | | ✔ | ABSENT | P0 |
| Check-in | | | ✔ | ABSENT | P0 |
| Check-out | | | ✔ | ABSENT | P0 |
| Stay extension | | | ✔ | ABSENT | P0 |
| Room transfer / room move | | | ✔ | ABSENT | P0 |
| No-show handling | | | ✔ | ABSENT | P0 |
| Cancellation | | | ✔ | ABSENT | P0 |
| Rate plans | | | ✔ | ABSENT | P0 |
| Restrictions (minimum stay, arrival/departure) | | | ✔ | ABSENT | P0 |
| Housekeeping | | | ✔ | ABSENT | P0 |
| Maintenance / out-of-order rooms | | | ✔ | ABSENT | P0 |
| Night audit | | | ✔ | ABSENT | P0 |
| Guest folio | | | ✔ | ABSENT | P0 |
| Charges / postings | | | ✔ | ABSENT | P0 |
| Taxes / VAT calculation | | | ✔ | ABSENT | P0 |
| Payments (cash) | | | ✔ | ABSENT | P0 |
| Payments (tokenised terminal card) | | | ✔ | ABSENT | P0 |
| Deposits / pre-authorisations | | | ✔ | ABSENT | P0 |
| Refunds | | | ✔ | ABSENT | P0 |
| Cashiering / cashier shift | | | ✔ | ABSENT | P0 |
| Settlement and reconciliation | | | ✔ | ABSENT | P0 |
| Invoicing (standard + simplified tax invoice) | | | ✔ | ABSENT | P0 |
| Credit notes / debit notes | | | ✔ | ABSENT | P0 |
| ZATCA / FATOORA e-invoicing integration | | | ✔ | ABSENT | P0 |
| Compliance submission state + outbox + DLQ | | | ✔ | ABSENT | P0 |
| RBAC | | | ✔ | ABSENT | P0 |
| Property-level authorization and data isolation | | | ✔ | ABSENT | P0 |
| Audit log | | | ✔ | ABSENT | P0 |
| Arabic RTL user interface | | | ✔ | ABSENT | P0 |
| English LTR user interface | | | ✔ | ABSENT | P0 |
| Reporting | | | ✔ | ABSENT | P0 |
| Notifications (email/SMS/WhatsApp/in-app) | | | ✔ | ABSENT | P1 |
| Observability / monitoring / alerting | | | ✔ | ABSENT | P0 |
| Backups and restore validation | | | ✔ | ABSENT | P0 |
| Disaster recovery (RPO/RTO) | | | ✔ | ABSENT | P0 |
| Security controls (authn, session, rate limit, headers) | | | ✔ | ABSENT | P0 |
| Privacy controls (retention, masking, deletion, access log) | | | ✔ | ABSENT | P0 |
| CI/CD pipeline | | | ✔ | ABSENT | P0 |
| Infrastructure as Code | | | ✔ | ABSENT | P0 |
| Test framework and suites | | | ✔ | ABSENT | P0 |
| Concurrency test harness | | | ✔ | ABSENT | P0 (release gate) |
| Accessibility conformance | | | ✔ | ABSENT | P1 |
| Import / migration capability (versioned, dry-run) | | | ✔ | ABSENT | P2 |
| Documentation of API contracts | | | ✔ | ABSENT | P0 |

### 2.2 CRS (Phase B — deferred with traceability)

| Requirement | Exists | Partial | Missing | Evidence | Priority |
|---|:-:|:-:|:-:|---|---|
| Central Reservation System | | | ✔ | ABSENT | P1 (Phase B) |
| Booking engine (property selection, availability search) | | | ✔ | ABSENT | P1 (Phase B) |
| Rate plan and rate code management | | | ✔ | ABSENT | P1 (Phase B) |
| Cancellation policies (CRS-facing) | | | ✔ | ABSENT | P1 (Phase B) |
| Guest-facing online card capture | | | ✔ | ABSENT | P1 (Phase B) |
| Reservation modification / cancellation via CRS | | | ✔ | ABSENT | P1 (Phase B) |
| Guest identity capture during booking | | | ✔ | ABSENT | P1 (Phase B) |
| Booking confirmation notification templates | | | ✔ | ABSENT | P1 (Phase B) |
| Booking engine fraud / abuse controls | | | ✔ | ABSENT | P1 (Phase B) |

### 2.3 Channel Manager and POS (Phase C — deferred with traceability)

| Requirement | Exists | Partial | Missing | Evidence | Priority |
|---|:-:|:-:|:-:|---|---|
| Booking.com integration | | | ✔ | ABSENT | P2 (Phase C) |
| Expedia integration | | | ✔ | ABSENT | P2 (Phase C) |
| Agoda integration | | | ✔ | ABSENT | P2 (Phase C) |
| Almosafer integration | | | ✔ | ABSENT | P2 (Phase C) |
| OTA inventory/rate/restriction synchronization | | | ✔ | ABSENT | P2 (Phase C) |
| OTA booking ingestion, modification, cancellation | | | ✔ | ABSENT | P2 (Phase C) |
| Channel mapping and reconciliation | | | ✔ | ABSENT | P2 (Phase C) |
| Sync health monitoring | | | ✔ | ABSENT | P2 (Phase C) |
| OTA virtual cards | | | ✔ | ABSENT | P2 (Phase C) |
| POS menu / item / modifier management | | | ✔ | ABSENT | P2 (Phase C) |
| POS orders, tables, service locations | | | ✔ | ABSENT | P2 (Phase C) |
| POS cashier and shift (deferred; cashier shift for PMS settlement is P0) | | | ✔ | ABSENT | P2 (Phase C) |
| POS room-charge posting | | | ✔ | ABSENT | P2 (Phase C) |
| POS refund / void and end-of-day | | | ✔ | ABSENT | P2 (Phase C) |

### 2.4 Deferred-within-Phase-A items

These are required by the confirmed design but have no agreed business rule yet. They are **deferred, not deleted**, and carry a traceability ID in `docs/TASKS.md`.

| Requirement | Status | Evidence | Priority |
|---|---|---|---|
| Company folio (corporate billing) | Deferred — target phase TBD | ABSENT | P1 (H-06) |
| Group folio | Deferred — target phase TBD | ABSENT | P1 (H-06) |
| Group reservations | Deferred — target phase TBD | ABSENT | P1 (H-06) |
| Negotiated / contract rates | Deferred — target phase TBD | ABSENT | P1 (H-06) |
| Charge transfers between folios | Deferred — target phase TBD | ABSENT | P1 (H-06) |
| Write-offs | Deferred — target phase TBD | ABSENT | P1 (H-06) |
| Bengali localization | Deferred — architecturally possible, not in Phase A UI scope | ABSENT | P2 (M-01) |
| Foreign currency / FX in ledger | Deferred — currency policy TBD (C-06) | ABSENT | P1 (C-06) |
| Legacy PMS import execution | Explicitly out of Phase A critical path (`D-008`) | ABSENT | P2 |

**Nothing in this repository is marked `Exists` or `Partial`, because nothing exists.**

---

## 3. Requirements Audit

`Defined` = specified in a confirmed project-manager decision and traceable in this document set. `Partially defined` = the capability is in scope but a governing rule is missing. `Undefined` = not specified anywhere; needs a decision.

### 3.1 Hotel operations

| Capability | Status | Note |
|---|---|---|
| Hotel / property management | Partially defined | 10 properties, one deployment, one organization. Property attributes (name, address, timezone, currency, tax profile, accounting profile) are not specified. |
| Room types | Partially defined | In Phase A. Occupancy model (max adults/children, bed configuration) unspecified. |
| Physical rooms | Partially defined | In Phase A. Room numbering, floor, out-of-order handling specified in principle only. |
| Inventory | Defined | Atomic allocation is a P0 correctness requirement (`ADR-0008`). |
| Reservations | Partially defined | In Phase A. Stay length limits, booking window, cut-off times undefined. |
| Guests | Partially defined | In Phase A. Profile merge/deduplication rules undefined. |
| Check-in | Partially defined | Preconditions, ID verification steps, deposit policy undefined. |
| Check-out | Partially defined | Cut-off time undefined (`C-05`). Late-checkout policy undefined. |
| Room transfer | Partially defined | In Phase A. Transfer with charge impact, partial-stay transfer, and audit rules undefined. |
| Housekeeping | Partially defined | In Phase A. Task types, assignment, SLA undefined. |
| Night audit | Partially defined | In Phase A. Business date, cut-off, reopen policy undefined (`C-05`). |
| Folios | Partially defined | Guest folio confirmed. Company/group folios deferred (`H-06`). |
| Charges | Partially defined | Charge types, posting rules, auto-posting policy undefined. |
| Taxes | Partially defined | VAT applies; inclusive/exclusive, rounding stage, rounding mode undefined (`C-04`). |
| Payments | Defined | Architecture defined (`ADR-0011`); provider unselected (`B-04`). |
| Refunds | Partially defined | In Phase A. Refund approval thresholds and reason codes undefined. |
| POS | Deferred | Phase C. |
| CRS | Deferred | Phase B. |
| Booking engine | Deferred | Phase B. |
| Channel manager | Deferred | Phase C. |
| OTA synchronization | Deferred | Phase C. Partner behavior is entirely `UNKNOWN` and must not be invented. |
| Reporting | Partially defined | In Phase A. Report catalogue undefined (`M-03`). |
| Group management | Undefined | Not in any confirmed phase (`H-06`). |

### 3.2 Access, data, and platform concerns

| Capability | Status | Note |
|---|---|---|
| RBAC | Defined | 12 roles, role + property scope + policy (`ADR-0014`). |
| Data scopes | Defined | Property-level, server-enforced. Explicit `user_property_scope` grant model. |
| Audit logs | Partially defined | Event list and immutability defined; retention duration TBD (`C-09`). |
| Notifications | Partially defined | Channels in scope; providers unselected (`C-07`). Consent mechanics depend on channel and on PDPL. |
| Localization (Arabic/English) | Defined | In Phase A. |
| Arabic RTL | Defined | In Phase A. |
| English LTR | Defined | In Phase A. |
| Bengali | Deferred | Architecturally possible, not in Phase A UI scope (`M-01`). |
| Security | Partially defined | Requirements listed in `docs/SECURITY.md`; no implementation exists. |
| Privacy | Partially defined | Identity data minimization defined (`D-004`); regulatory applicability of required fields unverified (`C-02`). |
| Backups | Partially defined | Required; frequency, retention, and restore-validation cadence have no values (`H-07`). |
| Disaster recovery | Undefined | RPO and RTO have no values (`C-01`). Provider unselected (`B-03`). |
| Observability | Undefined | No stack selected, no metric, no alert threshold (`H-04`). |
| Availability target | Undefined | No uptime target set. |
| Concurrency / capacity | Undefined | Operating scale unknown (`B-05`) — no NFR target can be set defensibly. |

---

## 4. State Machine Audit

Full specification: `docs/STATE-MACHINES.md`. **Nothing is implemented.** This section states the gap.

`Prd_Maker.md` §18 and §1.7 require an explicit state machine for every lifecycle entity. **No state machine exists in this repository.** All nine required machines below are specified in `docs/STATE-MACHINES.md`; the gap analysis here records what was missing and what remains unresolved.

| ID | Machine | States in repo | States specified | Transitions specified | Invalid transitions specified | Concurrency risk specified | Unresolved |
|---|---|---|---|---|---|---|---|
| A | Reservation | none | 12 | yes | yes | yes | Booking window, cut-off times, no-show cut-off |
| B | Room | none | 8 | yes | yes | yes | Room status vs housekeeping status separation |
| C | Inventory | none | 7 (allocation lifecycle) | yes | yes | yes | Oversell policy, hold expiry duration |
| D | Payment | none | 14 | yes | yes | yes | Unknown-outcome handling path |
| E | Refund | none | 9 | yes | yes | yes | Approval thresholds |
| F | Folio | none | 7 | yes | yes | yes | Company/group folio types deferred |
| G | Night Audit | none | 6 | yes | yes | yes | **Business date and cut-off undefined (`C-05`)** |
| H | External Integration | none | 8 (submission lifecycle) | yes | yes | yes | ZATCA specifics `UNKNOWN` (`B-02`) |
| I | Channel synchronization | none | **deferred, `TBD`** | no | no | no | Phase C; partner behavior must not be invented |

Additional machines specified because the decisions imply them: Invoice, Credit/Debit Note, Cashier Shift, Housekeeping Task, User/Session, Integration Submission.

### 4.1 Missing states — representative, not exhaustive

- **Reservation:** no state exists for *hold expiry* distinct from cancellation; no state for *partially paid*; no state for *property-side dispute*.
- **Room:** no separation of *occupancy* status from *cleanliness/housekeeping* status, which is a modelling error PMS systems commonly make.
- **Inventory:** no state for *expired hold* distinct from *released hold*; no state for *manually blocked* (maintenance).
- **Payment:** no state for *unknown outcome* — the single most dangerous gap. A payment whose result the provider did not confirm must not be resolved by retry.
- **Refund:** no state for *partially refunded* distinct from *refunded*.
- **Night audit:** no state for *reopened* business date, and no defined permission to reopen.
- **Invoice:** no state for *compliance submission failed* distinct from *invoice cancelled*.

### 4.2 Missing transitions — representative

- Reservation → `EXPIRED` (hold expiry without a scheduler contract).
- Reservation → `NO_SHOW` (no-show detection job, cut-off `TBD`).
- Reservation → `IN_HOUSE` → `CHECKED_OUT` → `COMPLETED` (stay lifecycle end-state).
- Payment → `UNKNOWN` → resolution by reconciliation (mandatory path).
- Night Audit `COMPLETED` → `REOPENED` (requires authorization + audit event; policy `TBD`).
- Integration Submission → `DEAD_LETTER` → manual replay.

### 4.3 Invalid transitions

Every machine in `docs/STATE-MACHINES.md` carries an **invalid-transition table** mapping each forbidden transition to a deterministic error code. `Prd_Maker.md` §18 requires that invalid transitions produce deterministic errors and that no transition may be implied only by UI behaviour. UI-only gating is explicitly rejected in `docs/SECURITY.md`.

### 4.4 Concurrency risks identified

| Risk | Where | Control |
|---|---|---|
| Double booking of the last sellable unit | Inventory allocation | `ADR-0008`: transaction + row lock + unique constraint + allocation rule + state validation + idempotency + **mandatory concurrency test** |
| Two agents checking in the same room | Check-in | Room status precondition inside the same transaction as the reservation transition |
| Duplicate payment submission | Payment | `Idempotency-Key` required; duplicate returns the original result |
| Double night audit for the same business date | Night audit | Single-run-per-property-per-business-date lock; resumable, not re-runnable into a duplicate state |
| Concurrent folio posting racing a refund | Folio | Append-only postings; balance derived, never overwritten |
| Concurrent configuration change | Admin config | Versioned configuration + audit event |

---

## 5. Integration Audit

`Prd_Maker.md` §24 requires an integration contract for every external system. **No integration exists in this repository.** Two integrations are in Phase A scope architecturally; four OTA partners plus a payment provider are unselected.

### 5.1 Integration contract completeness (required by `Prd_Maker.md` §24)

| Field | ZATCA / FATOORA | Payment provider |
|---|---|---|
| Provider | Saudi authority — FATOORA platform | `TBD` — not selected (`B-04`) |
| Purpose | E-invoice / credit note / debit note integration | Card authorisation, capture, refund, void |
| Direction | Outbound, plus inbound validation responses | Outbound requests; inbound webhook **UNKNOWN** |
| Protocol | **UNKNOWN** | **UNKNOWN** |
| Authentication | **UNKNOWN** — certificate/CSID requirements unverified (`B-02`) | **UNKNOWN** |
| Environment(s) | **UNKNOWN** — sandbox availability unverified | **UNKNOWN** — required by `D-005` |
| Rate limits | **UNKNOWN** | **UNKNOWN** |
| Timeout | `TBD` — engineering default to be set | `TBD` |
| Retry policy | Bounded, exponential backoff + jitter, max attempts, retryable error classes | Bounded; **never retry an unknown payment outcome** |
| Idempotency | Required on every submission | Required on every payment operation |
| Ordering | Submission sequence per business date | Not assumed; reconciliation governs |
| Consistency | Asynchronous; business state independent of compliance state | Provider is SoR for the card transaction; PMS is SoR for allocation |
| Error mapping | `UNKNOWN` — no code list verified | Provider result code stored verbatim |
| Dead letter | Required | Required |
| Reconciliation | Required — status tracking, final disposition, reconciliation status | Required — allocation of payment to folio |
| Monitoring | Required — submission success rate, DLQ depth, backlog | Required — authorisation success rate, failure classes |
| Credential rotation | **UNKNOWN** + `TBD` | **UNKNOWN** + `TBD` |
| Versioning | Adapter versioned; payload version recorded per submission | Adapter versioned |
| Sandbox / certification | **UNKNOWN** | **UNKNOWN** |
| Fallback | Submission queued; business operation continues; invoice flagged `SUBMISSION_PENDING` | Cash path remains available; folio flagged for reconciliation |
| Data shared | Invoice data, tax identifiers — exact field set **UNKNOWN** | Cardholder authentication only; **never PAN/CVV** |
| PII | Yes — buyer identifiers may be personal data | Cardholder data is minimized by tokenization |
| Compliance impact | **No compliance claim is made** | **No PCI claim is made** |
| Source | `zatca.gov.sa` pages in `docs/COMPLIANCE.md` §3 | — |

### 5.2 Named integrations — status

| Integration | Phase | Status | Evidence | Blocking item |
|---|---|---|---|---|
| ZATCA / FATOORA | A (P0) | Architecture designed; **no provider API verified** | ABSENT | `B-02` |
| Shomoos | Undetermined | Applicability **undetermined** | ABSENT | `C-03` |
| National Tourism Monitoring Platform | Undetermined | Applicability **undetermined** | ABSENT | `C-03` |
| Payment gateway (MPSP) | A (P0) | Adapter interface designed; **provider unselected** | ABSENT | `B-04` |
| Booking.com | C | **Not started. Partner behavior `UNKNOWN`.** | ABSENT | — |
| Expedia | C | **Not started. Partner behavior `UNKNOWN`.** | ABSENT | — |
| Agoda | C | **Not started. Partner behavior `UNKNOWN`.** | ABSENT | — |
| Almosafer | C | **Not started. Partner behavior `UNKNOWN`.** | ABSENT | — |
| Email / SMS / WhatsApp providers | A/P1 | Channels in scope; **providers unselected** | ABSENT | `C-07` |

**No API capability for any external system above is known. All are marked `UNKNOWN` and must not be invented.**

---

## 6. Security Audit

`Prd_Maker.md` §54 requires a threat model for critical systems. Full specification in `docs/SECURITY.md`. Summary of what is required and what is absent.

| Control area | Requirement source | Status in repository |
|---|---|---|
| Authentication | `ADR-0014`, `Prd_Maker.md` §14 | ABSENT |
| MFA / step-up for sensitive operations | `Prd_Maker.md` §14 | ABSENT |
| Authorization (role + resource + action + scope) | `ADR-0014` | ABSENT |
| RBAC — 12 roles | `D-001` | Roles named; **no model, no enforcement, no test** |
| Property-level data scope, server-enforced | `D-001` | ABSENT |
| Least privilege / deny by default | `Prd_Maker.md` §14 | ABSENT |
| Secrets management / KMS | `D-007` | ABSENT |
| Encryption in transit (TLS) | `D-007` | ABSENT |
| Encryption at rest | `D-007` | ABSENT |
| Separate key boundary for identity data | `D-004`, `ADR-0012` | ABSENT |
| Password hashing | `Prd_Maker.md` §20.6 | ABSENT — algorithm not chosen |
| Session security | `Prd_Maker.md` §20.6 | ABSENT |
| CSRF protection where applicable | `Prd_Maker.md` §20.6 | ABSENT |
| Rate limiting / abuse prevention | `Prd_Maker.md` §20.6 | ABSENT |
| Audit logs (immutable) | `ADR-0016` | ABSENT |
| Security headers | `Prd_Maker.md` §20.6 | ABSENT |
| Dependency security / vulnerability scanning | `Prd_Maker.md` §20.6 | ABSENT — no CI |
| Secure file handling | `Prd_Maker.md` §56 | `NOT_APPLICABLE` in Phase A (no file upload); revisited if document images are ever approved |
| Backup security | `D-007` | ABSENT |
| Webhook signature verification / replay protection | `Prd_Maker.md` §55 | ABSENT; mechanism `UNKNOWN` pending provider |
| Property breakout (tenant breakout) threat | `Prd_Maker.md` §54 | Named threat; **no control, no test** |
| Duplicate payment request threat | `Prd_Maker.md` §54 | Named threat; idempotency designed, not implemented |
| Export abuse | `Prd_Maker.md` §54 | Named threat; no export controls exist |

---

## 7. Privacy / Data Governance Audit

Full specification in `docs/COMPLIANCE.md` §2. Summary.

| Data category | Examples | Classification | Status |
|---|---|---|---|
| Identity data | Name, nationality, date of birth | Personal Data | Minimization required; exact required field set `TBD` (`C-02`) |
| Identity-document data | Document type, issuing country, **document number**, expiry date | Personal Data — Restricted | `D-004`: fields only, **no images**; separate encryption boundary; masked; access-logged; retention configurable; excluded from logs/telemetry |
| Guest contact data | Phone, email, address | Personal Data | Collected for service delivery; consent/notice mechanics `TBD` |
| Financial data — payment | Token, auth reference, transaction ID, amount, currency, result code | Confidential | `D-005`: **PAN/CVV/magstripe never stored** |
| Financial data — folio | Charges, taxes, payments, balances, invoices | Confidential / Restricted | Append-only; compensating entries only |
| Behavioral data | Stay history, preferences | Personal Data | Retention `TBD` |
| Special-category-adjacent | Nationality, document data — treated as heightened-sensitivity operational data | Restricted | Not asserted to be a legal "special category"; classification is an internal control decision |
| Audit data | Who/what/when/where/before/after/reason | Confidential | Immutable; retention `TBD` |
| Telemetry / logs | Request IDs, latencies, errors | Internal | **Must never contain document numbers or card data** |

### 7.1 The three-way distinction required by the project manager

1. **Data required for hotel operations** — name, contact, stay dates, room, folio, payment outcome.
2. **Data required by verified Saudi regulatory requirements** — **currently UNKNOWN.** The applicable registration fields and their retention periods must be verified against authoritative sources (blocker `C-02`). ZATCA invoice fields are a separate category: they are required to issue a compliant tax invoice, but the exact field set is `UNKNOWN` until B-02 is resolved.
3. **Optional data that must not be collected by default** — document images, marketing consent by default, biometric data, nationality-based profiling, cross-property behavioural tracking.

### 7.2 Policy gaps

| Policy | Status |
|---|---|
| Retention schedule values | `TBD` — not set by the business (`C-09`) |
| Deletion / purge execution | Designed; **no values, no job** |
| Access logging to sensitive fields | Designed in `ADR-0012`; not implemented |
| Data subject access / correction | Undefined |
| Data subject erasure | Undefined — conflicts with financial record retention; `TBD` with legal owner |
| Lawful-basis mapping | **Not provided and not invented here.** Requires legal review. |
| Cross-border transfer assessment | Required before any PII replication outside Saudi Arabia; not performed (`D-007`) |
| Subprocessor register | Undefined — depends on selected providers |
| Breach / incident response workflow | Undefined; no 24/7 escalation model (`H-05`) |
| Privacy notice | Undefined |
| Test-data policy | **Defined:** synthetic identity, guest, and card data only in all non-production environments (`D-004`, `D-007`) |

---

## 8. Financial Domain Audit

Full specification in `docs/DATA-MODEL.md` §4 and `docs/PRD.md` §21. Summary.

| Concept | Phase A? | Model exists? | Gap |
|---|:-:|:-:|---|
| Guest folio | ✔ P0 | No | Folio types and lifecycle need definition |
| Company folio | Deferred | No | Deferred, not designed (`H-06`) |
| Group folio | Deferred | No | Deferred, not designed (`H-06`) |
| Deposits | ✔ P0 | No | Applied-to-charge, release, and forfeiture rules undefined |
| Charges | ✔ P0 | No | Charge catalogue, auto-posting rules undefined |
| Discounts | ✔ P0 | No | Discount-before/after-tax order undefined |
| Taxes | ✔ P0 | No | **Inclusive vs exclusive, rounding stage, rounding mode all `TBD` (`C-04`)** |
| Payments | ✔ P0 | No | Provider unselected (`B-04`) |
| Refunds | ✔ P0 | No | Approval thresholds, reason codes, partial-refund rules undefined |
| Adjustments | ✔ P0 | No | Manual adjustment authority undefined |
| Transfers between folios | Deferred | No | Deferred (`H-06`) |
| Write-offs | Deferred | No | Deferred (`H-06`) |
| Cashier shifts | ✔ P0 | No | Shift open/close, variance, deposit-in-drawer undefined |
| Settlement | ✔ P0 | No | End-of-day settlement procedure undefined |
| Reconciliation | ✔ P0 | No | Provider-settlement-to-folio reconciliation undefined |
| OTA virtual cards | Phase C | No | Deferred; partner behavior `UNKNOWN` |
| Room charges from POS | Phase C | No | Deferred (`D-002`) |
| Invoice / credit note / debit note | ✔ P0 | No | Lifecycle defined in `docs/STATE-MACHINES.md`; legal numbering scope `TBD` (`B-06`) |

### 8.1 Accounting inconsistencies to prevent by design

1. **Double posting on retry** — prevented by append-only postings plus idempotency keys.
2. **Destructive update of a posted record** — prohibited; corrections use compensating entries linked to the original.
3. **Balance stored and also derived** — the balance is derived from postings; storing and mutating a separate balance creates drift. Must be designed so the two cannot diverge.
4. **Rounding applied at more than one stage** — rounding happens exactly once, at a defined stage (`C-04`).
5. **Tax computed on a rounded subtotal vs a rounded total** — must be specified, not left to implementation.
6. **Refund not linked to the original payment** — refunds carry a mandatory reference to the originating payment.
7. **Invoice not traceable to the folio** — traceability to the originating folio and transactions is a P0 requirement (`D-003`).
8. **Currency mixing** — currency policy is `TBD` (`C-06`); no FX may enter the ledger until decided.
9. **Business date vs posting timestamp divergence** — every posting carries both; they are not interchangeable (`C-05`).
10. **Three posting paths (folio, POS, night audit) in Phase A** — avoided by design: POS is deferred to Phase C, so Phase A has folio posting and night audit posting only. This is a deliberate scope control, and it is why POS was deferred.

---

## 9. Inventory Concurrency Audit

**Answering the brief directly: the system currently prevents nothing, because there is no system.** This section specifies the mechanism that must exist.

`Prd_Maker.md` §34 requires the PRD to define how two concurrent requests for the final unit are handled. `D-006` adds a standing rule: **MySQL row locking alone does not guarantee prevention of double booking.**

### 9.1 Required strategy

| Layer | Control | Why it is needed |
|---|---|---|
| 1 | Explicit database transaction with a short, well-defined scope | All-or-nothing allocation |
| 2 | `SELECT ... FOR UPDATE` on the specific inventory rows for the requested property, room type, and date range | Serializes competing allocations for the same unit |
| 3 | Unique constraint as a last-resort backstop | Turns a logic defect into a deterministic error instead of silent corruption |
| 4 | Explicit allocation rule (sellable unit, out-of-order, maintenance-blocked units) | Prevents allocation of an unsellable unit |
| 5 | Reservation state-machine validation inside the same transaction | Prevents allocating to a reservation in a non-allocatable state |
| 6 | Client `Idempotency-Key` | Prevents duplicate allocation from a retried or double-clicked request |
| 7 | **Mandatory concurrency test** | The only evidence that the combination works |

### 9.2 Required acceptance condition

> Given one available sellable unit and two concurrent valid booking attempts, no more than one may enter a confirmed state. The successful attempt may continue to confirmation. The losing attempt MUST receive the deterministic error code `INVENTORY_UNAVAILABLE`. No duplicate confirmed allocation may exist. Both attempts MUST be traceable by request/correlation ID. Repeating either request with the same `Idempotency-Key` MUST NOT create a second allocation.

### 9.3 Additional concurrency risks

| Risk | Control |
|---|---|
| Check-in races another check-in on the same room | Room status precondition evaluated inside the same transaction as the reservation transition |
| Expired hold resurrects an allocated unit | Hold expiry must be evaluated inside the allocation transaction, not by a scheduler alone |
| Night audit closes a business date while a booking is in flight | Night audit takes a per-property-per-business-date lock and must not race an in-flight allocation |
| Two properties' allocations interleave | Lock scope is explicitly property-scoped to avoid unnecessary contention |
| Overselling via a stale read replica | Allocation reads the primary; replicas must never decide allocations |

### 9.4 Unresolved

Oversell policy, hold duration, booking window, and arrival/departure cut-off times are all `TBD`. These are not assumed defaults; they are business decisions.

---

## 10. Non-Functional Requirements Audit

`Prd_Maker.md` §20 requires NFRs to be measurable. **No NFR can currently be stated as a target, because the operating scale is unknown (`B-05`).** Setting a latency target without knowing peak concurrency, request mix, or dataset size would be fabrication.

| NFR area | Status | What is missing |
|---|---|---|
| API latency (p50/p95/p99) | `TBD` | Peak concurrency, request mix, dataset size, and an approved target (`B-05`) |
| Page performance | `TBD` | Same |
| Search latency (availability, guest lookup) | `TBD` | Same; search requirements themselves unspecified (`M-04`) |
| Concurrency | `TBD` | Peak concurrent users per property |
| Throughput | `TBD` | Peak requests/sec; daily reservation volume |
| Scalability | `TBD` | Data volume, growth assumption, scaling trigger |
| Availability / uptime target | `TBD` | No target set; measurement window undefined |
| RPO | `TBD` | Not defined (`C-01`) |
| RTO | `TBD` | Not defined (`C-01`) |
| Backup frequency and retention | `TBD` | No values (`H-07`) |
| Restore validation | `TBD` | Required; cadence unset (`H-07`) |
| Disaster recovery | `TBD` | Provider unselected (`B-03`); warm standby designed, not built |
| Monitoring | `TBD` | Stack unselected (`H-04`) |
| Logging | Partially defined | Structured logging with correlation IDs required; retention unset |
| Alerting | `TBD` | No threshold, severity, owner, escalation, or runbook defined |
| Capacity planning | `TBD` | Blocked on `B-05` |
| Accessibility | `TBD` | No WCAG target chosen (`C-08`) |
| Performance test model | `TBD` | `Prd_Maker.md` §63 scenario template cannot be populated without `B-05` |

### 10.1 Explicit non-claims

- No performance result is reported. Nothing has been measured.
- No coverage percentage is reported. There is no code.
- No availability or uptime figure is claimed.
- No RPO/RTO value is invented.
- No benchmark, laboratory metric, or production metric exists.

---

## 11. Phase Readiness

| Phase | Scope | Ready to build? | Blockers |
|---|---|:-:|---|
| Phase 0 | Plan approval, scaffold, CI, IaC baseline | Partially | `B-03` blocks the IaC baseline |
| Phase A1 | Master data, access control | **Yes** | None |
| Phase A2 | Inventory, reservations | **Yes** | None |
| Phase A3 | Front desk, folios, payments | Partially | `B-04`, `B-05` block parts |
| Phase A4 | Housekeeping, night audit | Partially | `C-05` |
| Phase A5 | Tax, invoicing, ZATCA | **No** | `B-01`, `B-02`, `B-06`, `C-04` |
| Phase A6 | Reporting, notifications, localization | Partially | `C-07`, `C-08`, `M-03` |
| Phase A7 | Hardening, DR, performance, UAT, cutover | **No** | `B-03`, `B-05`, `C-01`, `C-10` |
| Phase B | CRS | Not started | Phase A gate |
| Phase C | Channel Manager, POS | Not started | Phase B gate |

---

## 12. Related Documents

| Document | Purpose |
|---|---|
| `docs/PRD.md` | Product requirements, business rules, acceptance criteria, traceability |
| `docs/ARCHITECTURE.md` | Modular monolith topology, integration contracts, concurrency strategy |
| `docs/DATA-MODEL.md` | Entities, ERD, data classification, ledger model |
| `docs/API-SPEC.md` | Endpoint contracts, error model, idempotency |
| `docs/STATE-MACHINES.md` | All state machines, transitions, invalid transitions, concurrency |
| `docs/SECURITY.md` | Threat model and controls |
| `docs/COMPLIANCE.md` | ZATCA, VAT, PDPL, privacy, source verification log |
| `docs/TEST-STRATEGY.md` | Test strategy including the concurrency gate |
| `docs/DEPLOYMENT.md` | Topology, IaC, backups, DR, release gates |
| `docs/TASKS.md` | Task breakdown, priorities, dependencies, acceptance criteria |
| `docs/ADR/` | 21 architecture decision records |
