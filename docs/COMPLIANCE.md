# Zafer Al-Asriya v1.0 — Compliance and Data Governance

| Field | Value |
|---|---|
| Document | `docs/COMPLIANCE.md` |
| Version | 0.1 |
| Status | Draft — verification record and requirements. **No compliance claim is made.** |
| Compliance Owner | **`TBD` (`C-10`)** |
| Verification date | 2026-09-27 |
| Related | `D-003`, `D-004`, `D-007`, `ADR-0010`, `ADR-0012`, `ADR-0013`, `Prd_Maker.md` §23, §51 |

---

## 0. Mandatory negative constraints

These apply to this document and to every other document in this set. They are stated first because they are the constraints most easily violated by accident.

1. **No compliance claim is made anywhere.** This document set contains no statement that Zafer Al-Asriya is compliant, non-compliant, or partially compliant with any law, standard, or platform requirement.
2. **No legal advice is given.** Engineering specifies controls; legal characterization requires a qualified professional.
3. **Zafer Al-Asriya's ZATCA wave, VAT threshold status, and legal status are NOT inferred** from the number of hotels, an estimate of group revenue, or the published wave thresholds. They `REQUIRES CONFIRMATION BY THE ORGANIZATION'S AUTHORIZED TAX/COMPLIANCE REPRESENTATIVE` (`B-01`).
4. **ZATCA API endpoints, request/response schemas, certificate and CSID requirements, TLV/QR binary structure and signing, UBL schema constraints, the onboarding procedure, error codes, rate limits, and sandbox availability are `UNKNOWN`** until read from authoritative ZATCA developer documentation (`B-02`). They are not invented anywhere in this set.
5. **Encryption does not establish PDPL compliance. Tokenization does not establish PCI DSS compliance. Hosting in Saudi Arabia does not establish compliance of any kind.** (`Prd_Maker.md` §51.)
6. **The applicable VAT rate is not stated** in this document. It must be read from an authoritative ZATCA source.
7. **Shomoos and the National Tourism Monitoring Platform applicability is undetermined** (`C-03`). No requirement is defined and none is assumed.

---

## Part 1 — Compliance and regulatory requirements

### `COM-001` — ZATCA e-invoicing

```text
Jurisdiction:        Saudi Arabia
Authority:           Zakat, Tax and Customs Authority (ZATCA)
Requirement:         Phase 1 (Generation) enforceable 4 Dec 2021 for all taxpayers
                     (excluding non-resident taxpayers) and any party issuing tax
                     invoices on behalf of a VAT-registered supplier. Phase 2
                     (Integration with FATOORA) rolled out in waves from 1 Jan 2023,
                     with ZATCA notifying each wave at least 6 months in advance.
Scope:               Tax invoices and notes issued to guests
Effective Date:      Phase 1: 2021-12-04. Phase 2: 2023-01-01, in waves
Applicable Population: Taxpayers within the scope of the e-invoicing regulation
Technical Impact:    Compliance adapter; transactional outbox; credentials/CSID;
                     gapless invoice numbering; UBL/XML and QR requirements
                     (specifics UNKNOWN); submission status tracking; DLQ;
                     reconciliation
Data Impact:         Invoice fields (exact set UNKNOWN); buyer identifiers, which
                     may include personal data
Operational Impact:  Dead-letter monitoring; manual replay; reconciliation; a
                     long-lead CSID onboarding dependency (DEP-010)
Evidence:            V-01, V-02, V-05
Source URL:          zatca.gov.sa/en/E-Invoicing/Introduction/Pages/Roll-out-phases.aspx
Source Version:      Page last update 01 Sep 2026
Verified On:         2026-09-27
Owner:               TBD (C-10)
Open Interpretation: The organization's wave and obligations REQUIRE CONFIRMATION
                     (see COM-002 and B-01).
```

### `COM-002` — Wave context — **an observation, not an applicability claim**

```text
Fact:     Wave 24 covered taxpayers whose VAT revenue exceeded SAR 375,000 in
          2022, 2023 or 2024, with an integration deadline of 30 June 2026.
          Wave 25 covers revenue exceeding SAR 187,500 in 2022-2025, with a
          deadline of 1 February 2027.
Source:   zatca.gov.sa news pages (V-03, V-04)
Verified: 2026-09-27

OBSERVATION (recorded to prompt confirmation, NOT a determination):
  A group operating 10 properties plausibly exceeds the Wave 24 revenue
  threshold. If so, and if the 30 June 2026 deadline has passed without
  integration, a compliance exposure may ALREADY EXIST independently of this
  project.

  This is NOT a legal determination and NOT a statement about the
  organization's status. It is the reason B-01 is the first task in
  docs/TASKS.md: the question must go to an authorized tax representative
  immediately, not wait for the software.
```

### `COM-003` — PDPL

```text
Jurisdiction:        Saudi Arabia
Authority:           Saudi Data & AI Authority (SDAIA)
Requirement:         PDPL in force since 14 Sep 2023 (Royal Decree M/148 of
                     27 Mar 2023); one-year compliance grace period ended
                     14 Sep 2024; actively enforced. SDAIA violation committees
                     issued 48 penalty decisions in 2025, covering processing
                     without legal basis, disclosure without justification, and
                     failure to implement appropriate technical, administrative
                     and organizational measures.
Technical Impact:    Minimization, retention and deletion, access control and
                     logging, processor management, breach response, security
                     measures
Operational Impact:  Data-subject request handling; breach notification
                     workflow (no 24/7 model yet — H-05)
Evidence:            V-06, V-07, V-08
Source:              sdaia.gov.sa; spa.gov.sa (16 Jan 2026); DLA Piper / CMS
Verified On:         2026-09-27
Owner:               TBD (C-10)
NOTE:                NO PDPL COMPLIANCE IS CLAIMED BY THIS DOCUMENT.
```

### `COM-004` — Cross-border personal data transfer

```text
Authority:           SDAIA — Regulation on Personal Data Transfer Outside the
                     Kingdom (updated September 2024)
Requirement:         Transfers require an assessment of protection adequacy,
                     appropriate safeguards, or another permitted pathway.
                     Binding Corporate Rules guidelines and a Risk Assessment
                     Guideline exist.
Technical Impact:     No automatic replication of guest personal data outside
                     Saudi Arabia without an approved assessment. Cross-region
                     database replication, telemetry/log export to third-party
                     services, and vendor support access are each a transfer
                     and are each gated.
Data Impact:         Guest PII, including identity and contact data
Evidence:            V-09
Verified On:         2026-09-27
Owner:               TBD (C-10)
Open Interpretation: SDAIA's list of adequate-protection territories is reported
                     as not yet confirmed. Treated as an open obligation.
```

### `COM-005` — VAT

```text
Requirement:  VAT applies to taxable supplies.
Technical:    Tax rate configuration is versioned, effective-dated, scoped, and
              audited. Rounding stage and mode are TBD (C-04).
UNRESOLVED:   The applicable rate is NOT stated here — it must be read from an
              authoritative ZATCA source.
```

### `COM-006` — PCI

```text
Position:   The system stores no PAN, CVV, or magstripe data, and no schema
            column exists for them. Card payment uses provider-hosted or
            tokenised flows.
Position:   This REDUCES PCI scope. IT DOES NOT ESTABLISH PCI DSS COMPLIANCE.
            Actual scope depends on the final architecture, the provider
            relationship and contract, the environments, the controls, and the
            applicable assessment requirements — determined by the provider's
            qualified security assessment and the acquirer/bank relationship,
            and by a qualified assessor.
```

### `COM-007` — Shomoos

```text
Status:          APPLICABILITY UNDETERMINED
Context:         Listed in Prd_Maker.md §23 and §51 as a verification prompt for
                 Saudi projects. Omitted from the approved phase scope.
Action:          An open question to the project manager and legal counsel
                 (C-03), NOT an assumed requirement and NOT an assumed
                 exemption.
```

### `COM-008` — National Tourism Monitoring Platform

```text
Status:          APPLICABILITY UNDETERMINED
Action:          Same as COM-007 (C-03).
```

### `COM-009` — Cybersecurity obligations

```text
Status:    Applicability of specific cybersecurity controls to the organization
           and sector is UNDETERMINED. Requirements are captured in
           docs/SECURITY.md as engineering controls.
Owner:     TBD (C-10)
```

### `COM-010` — Hotel guest registration

```text
Status:    WHICH guest identity fields are legally required, and for how long
           they must be retained, is UNKNOWN and UNVERIFIED (C-02).
Note:      D-004 (fields only, no images) is a data-minimization ENGINEERING
           decision. It is NOT a legal determination of sufficiency.
Action:    Verify against authoritative sources before Phase A3 closes. Do not
           infer that image storage is required merely because a hotel performs
           guest registration.
```

---

## Part 2 — Privacy and data governance

`Prd_Maker.md` §22. **No PDPL conformance is claimed.**

### 2.1 The three-way distinction (required by the project manager)

| Category | Content | Handling |
|---|---|---|
| **(1) Required for hotel operations** | Name, contact details, stay dates, room, folio, payment outcome | Minimized, purpose-limited, retained per policy |
| **(2) Required by verified Saudi regulatory requirements** | **UNKNOWN.** Guest registration fields and retention periods require verification (`C-02`). ZATCA invoice fields are a separate sub-category, `UNKNOWN` until `B-02` | Not asserted, not assumed, not inferred |
| **(3) Optional — MUST NOT be collected by default** | Document images, biometric data, marketing consent, nationality-based profiling, cross-property behavioural tracking, inferred preferences | **Not collected** |

### 2.2 Data inventory and classification

Classification is an **internal engineering control scheme**, not a legal categorization (`Prd_Maker.md` §21: *"Never invent a legal sensitivity category."*).

| Data | Classification | Controls |
|---|---|---|
| Guest name, contact, nationality, date of birth | Personal Data | Purpose-limited; access-logged; retention `TBD` |
| **Document type, country, number, expiry** | Personal Data — Restricted (internal) | Encrypted under a **separate key boundary**; masked by default; reveal requires authorization + step-up + audit; **never in logs/telemetry**; retention configurable, periods `TBD` |
| **Document images** | — | **NOT COLLECTED** (`D-004`) |
| Card data | — | **NEVER STORED**; no schema column |
| Provider token, auth reference, transaction ID, result code | Confidential | Access-scoped; excluded from general logs |
| Folio, charges, taxes, payments, balances, invoices | Confidential / Restricted (internal) | Append-only; no deletion; export restricted |
| Audit events | Confidential (internal) | Append-only; access itself audited; retention `TBD` |
| Application logs, metrics, traces | Internal | **No identity, no document, no card, no guest names** |
| Outbox and submission payloads | Confidential | Business data only; no card data; no secrets; access-scoped |

### 2.3 Data subject rights

| Right | Status |
|---|---|
| Access | `TBD` — requires a defined export surface, which must be role-gated and audited |
| Correction | `TBD` |
| **Erasure** | **CONFLICT — policy decision required.** Erasure may conflict with financial record retention and tax invoice retention (`ADR-0009`, `ADR-0012` §8). Engineering cannot resolve this and must not silently pick a side. |
| Marketing consent | Not collected by default. Mechanics depend on channel and on PDPL — requires legal review (`C-07`) |
| Portability | `TBD` |

**The design response to the erasure conflict:** operational personal data is stored separably from financial and tax records (`DR-013`, `PRI-006`), so a policy can be applied to each independently. Until a policy exists, a deletion request is **escalated, never silently actioned in either direction**.

### 2.4 Retention

**All retention periods are `TBD` (`C-09`).** They are a business and legal decision and are not set by engineering.

Retention is configurable per data category (`Prd_Maker.md` §23: *"Retention periods must be configurable when regulations may vary by data category or legal version."*). Purge is a purpose-built, authorized, **audited** job; it never touches posted financial records or the audit trail.

### 2.5 Purpose limitation and minimization

- Data is collected for the stated operational, tax, or legal purpose only.
- No cross-property behavioural profiling by default: a guest of Property A has no expectation of being profiled across Properties B–J.
- Guest preferences are opt-in.
- Collection of identity data is minimized to fields only; images are not collected.

### 2.6 Access, logging, and audit

Every access to a sensitive identity field that reveals the full value is audited. Audit records capture **that** access and **who** performed it, and never the value itself (`ADR-0016`). Audit records are excluded from guest-facing exports; support access to audit data is scoped and itself audited.

### 2.7 Processors and subprocessors

A subprocessor register is required (`PRI-010`). **Its contents cannot be written yet**, because they depend on the selected cloud provider (`B-03`) and payment provider (`B-04`). This is a genuine dependency, not an omission.

### 2.8 Cross-border transfer assessment

Required before any transfer of guest personal data outside Saudi Arabia, covering: cross-region replication · log/trace/telemetry export to third-party services · vendor support access. Each is gated in `ADR-0013` §5. The provider's sub-processor list and where telemetry and support access originate are themselves PDPL processor questions.

### 2.9 Breach and incident response

A breach/incident workflow is required (`PRI-011`). **It does not exist, and no 24/7 escalation model is defined (`H-05`).** This is a real gap for a system that cannot stop check-in and holds guest personal data.

### 2.10 Privacy notice

`TBD` — requires legal drafting. Not drafted here.

### 2.11 Test-data policy

**Synthetic data only** in every non-production environment (`D-004`, `D-007`, `PRI-008`). Real guest personal data must never be requested, copied, anonymized-and-used, or otherwise introduced into a test environment. The synthetic generator (`ADR-0021` §7) must produce plausible-shaped but obviously synthetic identities — because a test that never sees realistic encodings and collations fails to surface real defects.

---

## Part 3 — Source verification log

Source hierarchy per `Prd_Maker.md` §49: 1 law/regulation/official publication · 2 official regulator/authority documentation · 3 official vendor documentation · 4 standards body · 5 contract/SLA · 6 reputable secondary · 7 community/forum.

| ID | Claim | Source | Type | Version/date | Verified | Applies to | Notes |
|---|---|---|---|---|---|---|---|
| `V-01` | ZATCA Phase 1 enforceable 4 Dec 2021 | `zatca.gov.sa/en/E-Invoicing/Introduction/Pages/Roll-out-phases.aspx` | 1 | Page last update 01 Sep 2026 | 2026-09-27 | `COM-001` | Excludes non-resident taxpayers |
| `V-02` | Phase 2 in waves from 1 Jan 2023; ≥6 months notice | same | 1 | same | 2026-09-27 | `COM-001` | — |
| `V-03` | Wave 24: VAT revenue > SAR 375,000; deadline 30 Jun 2026 | `zatca.gov.sa/en/Pages/news-1426.aspx` | 1 | Published 26 Sep 2025 | 2026-09-27 | `COM-002` | Context only |
| `V-04` | Wave 25: > SAR 187,500 (2022–2025); deadline 1 Feb 2027 | `zatca.gov.sa/en/MediaCenter/News/Pages/default.aspx` | 1 | Current index | 2026-09-27 | `COM-002` | Context only |
| `V-05` | Phase 2 requires FATOORA integration, a specified format, extra fields | `zatca.gov.sa/en/E-Invoicing/Pages/default.aspx` | 1 | Site last update 10 Aug 2026 | 2026-09-27 | `COM-001` | Field list `UNKNOWN` |
| `V-06` | PDPL effective 14 Sep 2023; grace ended 14 Sep 2024; in force and enforced | DLA Piper / CMS expert guide 25 Sep 2026; DLA Piper *Data Protection Laws of the World* | 2–6 | 2026-09-25 | 2026-09-27 | `COM-003` | Primary SDAIA legal text not read in this pass — a limitation, stated |
| `V-07` | 48 SDAIA penalty decisions in 2025; violations included processing without legal basis and missing technical/organizational measures | `spa.gov.sa/en/N2489505` (16 Jan 2026) | 2 | 2026-01-16 | 2026-09-27 | `COM-003` | — |
| `V-08` | PDPL sanctions: administrative warning or fine up to SAR 5,000,000; criminal offence for disclosure of sensitive personal data with harmful intent | DLA Piper, CMS | 2–6 | 2026-09-25 | 2026-09-27 | `COM-003` | — |
| `V-09` | Regulation on Personal Data Transfer Outside the Kingdom in force; BCR guidelines; Risk Assessment Guideline | `sdaia.gov.sa/en/SDAIA/about/Pages/RegulationsAndPolicies.aspx`; `spa.gov.sa/en/N2163905` (01 Sep 2024) | 1–2 | Regulation updated Sep 2024 | 2026-09-27 | `COM-004` | Adequate-protection territory list reported as unconfirmed |

### 3.1 Verified as `UNKNOWN`

Each of the following is `UNKNOWN — requires confirmation from the authoritative source before production`:

ZATCA API endpoints · ZATCA request/response schemas · ZATCA authentication mechanism · **ZATCA certificate and CSID requirements** · **ZATCA TLV/QR binary structure and cryptographic signing** · **ZATCA UBL XML schema constraints** · **ZATCA onboarding procedure** · ZATCA error codes · ZATCA rate limits · **ZATCA sandbox availability** · ZATCA Phase 2 invoice field list · **the applicable VAT rate** · **Zafer Al-Asriya's integration wave** · Shomoos applicability · National Tourism Monitoring Platform applicability · hotel guest-registration field requirements · payment provider API and capabilities · cloud provider region, service parity, SLA, and price.

### 3.2 Verification limitations of this pass

Stated so they are not mistaken for completed research:

- The **primary SDAIA legal text** and the **primary ZATCA developer documentation** were **not** read. `V-06` and `V-08` rest on reputable secondary legal sources, which are adequate for orientation but not for compliance determination.
- The **applicable VAT rate** was not retrieved and is not stated anywhere in this set.
- No **government platform** was contacted; no sandbox was accessed; no certification process was started.
- No **payment provider** or **cloud provider** documentation was read, because none is selected.

`Prd_Maker.md` §50.5: **current rules must be re-verified before production.** A PRD does not freeze regulations, and neither does this document.

---

## Part 4 — Compliance readiness

| Item | Ready? | Blocker |
|---|:-:|---|
| ZATCA architecture and boundary | **Yes** — designed provider-agnostically | Protocol implementation blocked by `B-02` |
| Invoice lifecycle and numbering | **Yes** — designed | Sequence scope blocked by `B-06` |
| ZATCA production integration | **No** | `B-01`, `B-02`, `B-06`, `C-04` |
| PDPL-oriented engineering controls | **Yes** — specified | No owner (`C-10`); no compliance claim |
| PDPL compliance | **No, and not claimed** | Requires legal review, DPO/owner, and organizational controls |
| Cross-border transfer posture | **Defined as a gate** | Cannot be closed without a provider (`B-03`) |
| PCI scope posture | **Minimized** | Not assessed; requires a provider and a qualified assessor |
| Guest registration compliance | **No** | `C-02` |
| Shomoos / tourism platform | **Unknown** | `C-03` |
| Breach response | **No** | `H-05` |
| Subprocessor register | **No** | Depends on `B-03`, `B-04` |
| Privacy notice | **No** | Legal drafting required |

**Overall: the compliance *design* is complete; compliance *verification* has not begun and cannot begin on several fronts without the confirmations listed as blockers.** The single most urgent item is `B-01` — it concerns a possible exposure that exists **today** and is independent of this project.
