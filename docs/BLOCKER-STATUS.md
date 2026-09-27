# Zafer Al-Asriya v1.0 — Blocker and Critical-Decision Status Register

| Field | Value |
|---|---|
| Document | `docs/BLOCKER-STATUS.md` |
| Status | **Authoritative dated status register.** Supersedes any earlier phrasing of the same IDs. |
| Recorded | 2026-09-27 |
| Recorded by | Phase 0 implementation session, on a project-manager instruction |
| Related | `docs/DISCOVERY.md` §11, `docs/PRD.md` §39, `docs/COMPLIANCE.md`, `docs/TASKS.md` §1 |

---

## 0. Purpose and rules for this register

This register exists so that no downstream reader can mistake an unanswered question for a settled one.

Rules for this document:

1. **No value is invented, inferred, defaulted, or estimated.** A row below records a *status*, never a substitute value.
2. `NOT CONFIRMED` means an authorized human has not stated the answer in writing with a source and a date. It is **not** a soft yes, and it is **not** permission to proceed on an assumption.
3. `UNKNOWN` means the fact has not been verified against an authoritative source. Per `Prd_Maker.md` §71 and `docs/STATE-MACHINES.md` §G-8, `UNKNOWN` is never a placeholder for a guess.
4. A blocked value is recorded as blocked **even where engineering could plausibly pick something**, because a plausible default is indistinguishable from a deliberate business rule once it is in code.
5. This register is a **governance** artefact. It does not record engineering progress. Task status lives in `docs/TASKS.md`.

---

## 1. Status legend

| Status | Meaning | Effect on implementation |
|---|---|---|
| `NOT CONFIRMED` | No authorized written answer exists | Any task depending on it is gated; the mechanism may be built, the value may not |
| `UNKNOWN` | Not verified against an authoritative source | No endpoint, field, format, code, or threshold may be fabricated |
| `NOT SELECTED` | A procurement decision has not been made | Work may stop at an interface/port; no provider-specific code |
| `OPEN` | Recorded, unresolved, no owner action completed | As above |

---

## 2. Blocker register

### `B-01` — ZATCA wave and legal / compliance status

| Attribute | Value |
|---|---|
| **Status** | **`NOT CONFIRMED`** |
| Question | Zafer Al-Asriya's ZATCA integration wave, its applicable deadlines, and its current compliance status |
| Owner required | Authorized tax / compliance representative named by the PM |
| Recorded by | Project-manager instruction, 2026-09-27 |
| Source of the answer | **None yet.** Must be a written statement from the authorized representative, with a date |
| Inferred value | **NONE. Must not be inferred from the number of hotels, revenue, or any estimate.** |
| Gates | `T-020`, `T-023`, and any compliance claim anywhere in the document set |
| Note | Per `docs/TASKS.md` RISK-004, an exposure that may exist **today** is independent of this project and must not wait for engineering scheduling |

### `B-06` — Invoicing legal-entity structure

| Attribute | Value |
|---|---|
| **Status** | **`NOT CONFIRMED`** |
| Question | One invoicing legal entity or several; commercial registration and VAT registration numbers |
| Owner required | PM + finance |
| Source of the answer | **None yet** |
| Inferred value | **NONE.** The number of legal entities determines the invoice-number sequence scope and the per-entity credential count |
| Gates | `T-020`, `T-021` |
| Implementation consequence recorded | `legal_entities` **exists as a table** (`T-002`, `AC-T-002-03`) with the CR and VAT registration columns present and **nullable**, so the model is not retrofitted later. The columns stay `NULL`; no placeholder value is inserted |

### `B-02` — ZATCA technical / API / certificate / CSID / TLV / UBL details

| Attribute | Value |
|---|---|
| **Status** | **`UNKNOWN`** — remains unknown until authoritative developer documentation is reviewed |
| Unknowns held open | API endpoints, request and response schemas, authentication, certificate and CSID requirements, TLV and QR binary structure, signing, UBL schema constraints, onboarding procedure, error code list, rate limits, sandbox and certification availability |
| Owner required | Compliance, on PM authorisation to obtain the documentation |
| Source of the answer | Authoritative ZATCA developer documentation. **Not** third-party summaries, not inference |
| Inferred value | **NONE.** No endpoint, field, certificate format, or TLV structure may be invented |
| Gates | `T-023`, `T-024`. Does **not** block `T-022` (the adapter interface and outbox) |

### `B-03` — Cloud provider

| Attribute | Value |
|---|---|
| **Status** | **`NOT SELECTED`** |
| Owner required | PM |
| Source of the answer | **None yet.** `ADR-0020` is a comparison, not a decision |
| Inferred value | **NONE.** No provider name, region identifier, or service catalogue may be assumed |
| Gates | The infrastructure-as-code portion of `T-001`; `T-023` infrastructure; all of Phase A7 |
| Residency constraint already decided | `D-007`: Saudi-region managed cloud, single provider, provider-neutral design. Residency is decided; the provider is not |

### `B-04` — Payment provider / sandbox

| Attribute | Value |
|---|---|
| **Status** | **`NOT SELECTED`** |
| Unknowns held open | Provider, API, webhook existence, webhook signature scheme, rate limits, settlement timing, pre-authorisation expiry rules, sandbox availability |
| Owner required | PM + finance |
| Source of the answer | **None yet** |
| Inferred value | **NONE.** No provider SDK type may appear in the domain (`ADR-0017` §4) |
| Gates | The concrete adapter of `T-010`; `T-012`; `T-013`; `T-014` |
| Implementation consequence recorded | `T-010` may build the adapter **interface**, the state machines, and a **fake** provider for tests. The concrete adapter is gated |

### `B-05` — Operating scale

| Attribute | Value |
|---|---|
| **Status** | **`NOT CONFIRMED`** |
| Unknowns held open | Per-property room counts, expected occupancy, staffing levels, peak concurrency, rate limits, realistic dataset size for performance testing |
| Owner required | PM + operations |
| Source of the answer | **None yet**, in writing |
| Inferred value | **NONE.** No row count, latency target, throughput target, or lock scope may be assumed |
| Gates | Capacity and index sizing, the performance suite, the concurrency harness thresholds, `CQRS` reconsideration |

### `C-01` — RPO / RTO and backup / restore policy

| Attribute | Value |
|---|---|
| **Status** | **`NOT CONFIRMED`** |
| Unknowns held open | Recovery point objective, recovery time objective, backup retention, restore-validation cadence, recovery test acceptance criteria |
| Owner required | Operations |
| Source of the answer | **None yet** |
| Inferred value | **NONE.** A default RPO/RTO would silently become a production approval criterion |
| Gates | `T-023` infrastructure, the recovery test suite (`docs/TEST-STRATEGY.md` §8), the release gate |

### `C-04` — VAT presentation, rounding, and money precision

| Attribute | Value |
|---|---|
| **Status** | **`NOT CONFIRMED`** |
| Unknowns held open | VAT inclusive or exclusive; the single authorised rounding **stage**; the rounding **mode**; storage **precision and scale**; order of computation |
| Owner required | Finance + compliance |
| Source of the answer | **None yet** |
| Inferred value | **NONE.** This is the highest-consequence open value in the set |
| What **is** decided and is being implemented | The **representation** only: BCMath arithmetic, exact `DECIMAL` storage, string transport (`ADR-0006`, `D-006`, `SEC-017`, `DM-2`) |
| Gates | `T-019`, `T-020`; and the creation of **any** monetary column |
| Implementation consequences recorded | (1) No `amount`/`rate` monetary column is created in `T-002` or `T-005`/`T-006`. (2) The `Money` value object performs **exact** arithmetic and does **not** round. (3) Any rounding operation requires an **explicit** caller-supplied policy; there is **no** default rounding policy in configuration, and requesting rounding without one is a hard failure. (4) `DECIMAL(19,4)` from `ADR-0006` remains **provisional** and is not written into any migration |
| Note on the provisional figure | `ADR-0006` §Decision records `DECIMAL(19,4)` as a "provisional working value … not an approved figure". It is treated as **not approved** and is therefore not used |

---

## 3. What this register does not resolve

The following remain open from `docs/PRD.md` §39 and are **unchanged** by this instruction. They are listed only to prevent the impression that recording the eight items above resolved anything else.

| ID | Subject | Status |
|---|---|---|
| `C-02` | Which guest-registration fields are legally required; retention | `OPEN` |
| `C-03` | Shomoos / National Tourism Monitoring Platform applicability | `OPEN` |
| `C-05` | Business date, cut-off, same-day arrival, late checkout, reopen policy | `OPEN` |
| `C-06` | Single currency or multi-currency; whether FX enters the ledger | `OPEN` |
| `C-07` | Notification providers | `OPEN` |
| `C-08` | Accessibility target (WCAG version and level) | `OPEN` |
| `C-09` | Retention schedule | `OPEN` |
| `C-10` | Named accountable owners | `OPEN` — note this also blocks `AC-T-000-07` |

`T-000` remains **NOT STARTED**. Recording that a decision is outstanding is not resolving it, and no acceptance criterion of `T-000` is satisfied by this document.

---

## 4. Readiness statement

The project readiness status is unchanged by this register.

**RED — NOT READY FOR DEVELOPMENT / NOT READY FOR PRODUCTION.**

The eight items above were re-confirmed as outstanding by the project manager on 2026-09-27. No engineering work in Phase 0 changes that status, and no compliance, PCI DSS, performance, or readiness claim is made or implied anywhere in this document set.
