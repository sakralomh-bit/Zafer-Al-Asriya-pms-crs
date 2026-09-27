# ADR-0012: Guest Identity Data Minimization and Encryption Boundary

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-004`, `BUS-012`, `ADR-0016`, `ADR-0013`, `C-02`, `docs/DATA-MODEL.md` §5.1, `docs/COMPLIANCE.md` §2

## Context

Guest identity data is the most sensitive category this system holds. `D-004` fixes the scope of what is stored and how it is protected.

`Prd_Maker.md` §21 is explicit: *"Do not default to storing copies of IDs or passports."* It requires that, where identity documents are involved, the specification define whether an image is stored, whether only fields are stored, why storage is necessary, retention, access control, encryption, deletion, audit logging, and the applicable legal basis.

`Prd_Maker.md` §22 requires purpose limitation and data minimization, and warns: *"Do not claim PDPL compliance simply because encryption exists."*

Two failure modes must both be avoided, and they pull in opposite directions:

- **Collecting too much** — storing passport images "just in case" creates the largest possible breach impact, the longest retention burden, and the widest access-control requirement, for a purpose that may not exist.
- **Collecting too little** — if a verified regulatory requirement mandates a field the system does not hold, the hotel cannot operate compliantly.

Engineering cannot resolve which fields are legally required. That is a legal determination. What engineering **can** do is minimize by default, isolate what is stored, make every access accountable, and design so that the answer can change later without a data migration.

## Decision

### 1. Fields only. No images.

| Stored | **Not stored in v1.0** |
|---|---|
| Document type | Passport image |
| Issuing country | National ID image |
| Document number | Iqama image |
| Expiry date | Any other identity document scan |

No image column, no object-storage bucket for identity documents, no malware-scanning pipeline, no signed-URL generation for identity files. Their absence is structural.

### 2. Rationale for excluding images

| Benefit of excluding | Cost avoided |
|---|---|
| Eliminates the largest sensitive binary store | No malware scanning, content validation, or file-handling control surface |
| Dramatically reduces breach impact | No image-retention problem |
| Identity data becomes queryable, auditable, and exportable in a controlled way | No signed expiring-URL infrastructure |
| Removal stays simple if it is ever required | No per-file authorization, encryption-at-rest, and deletion machinery |
| `Prd_Maker.md` §56 (file handling) is not in scope for Phase A | — |

**Recording the rationale matters as much as the rule.** A future request to "just add a passport photo" is easy to grant and hard to undo. The reasoning must be findable at the point of the request, which is why it is here.

### 3. Encryption with a separate key boundary

Document numbers and other sensitive identity fields are encrypted at rest under a **key separate from ordinary business data**, managed by the centralized secrets/KMS capability (`D-007`).

The separation is the point. If identity data and, say, room descriptions share a key, a compromise of routine application access yields identity data too. Separate key material means routine access — backups, replicas, a support query, a misconfigured export — does not automatically grant readable identity fields.

**Key management details are `TBD` (`H-03`)** — rotation policy, key hierarchy, and who holds the authority to decrypt. The *requirement* for a separate boundary is decided now and is not contingent on that answer.

### 4. Masking and authorized reveal

| Context | Behaviour |
|---|---|
| Default UI views | Document number **masked** |
| Search / lookup | Matching against the full value, never returning the full value by default |
| Reveal | Only for explicitly authorized roles, requires step-up authentication, and **produces an audit record** |
| Export | Role-gated, volume-limited, and audited (`SEC-015`) |
| API | Never returned unless required and authorized; never returned by default |

### 5. Access logging

Every reveal of a masked identity field produces an audit record identifying the user, the time, the record, and the correlation ID. The record captures **that** a sensitive field was revealed and by whom — **not the value** (`ADR-0016`).

Reading an already-masked value is not an auditable event; unmasking is. This distinction is stated explicitly so it is not later read as a gap.

### 6. Exclusion from logs, analytics, and telemetry

Identity data MUST NOT appear in application logs, analytics, error reports, metrics, traces, or any third-party telemetry. Enforced by an automated scan of captured log output in CI (`ADR-0021` §6), not only by developer discipline — because a leaked field in a log is durable, widely readable, and typically outlives the code that leaked it.

Provider and error payloads containing identity data are redacted before logging.

### 7. Retention and purge

Retention is **configurable per data category**. **The actual periods are `TBD` (`C-09`)** — they are a business and legal decision and are not set by engineering.

Purge is a purpose-built, authorized, **audited** job — not a general delete. Purge never touches posted financial records or the audit trail.

### 8. The erasure conflict is a policy decision, not an engineering one

A data-subject erasure request may conflict with financial record retention and tax invoice retention. Engineering cannot resolve this and must not silently pick a side.

The model therefore separates **operational personal data** (erasable) from **financial and tax records** (retained under obligation, access-restricted), so a policy can be applied to each independently (`DR-013`, `PRI-006`). Anonymization of the retained set is a candidate mechanism, `TBD`.

Until the policy exists: a deletion request MUST NOT silently delete financial or tax records, and MUST NOT silently retain operational personal data either. It is escalated.

### 9. Future image support is possible but isolated

The data model permits a future image capability as an **isolated** addition without destructively migrating existing rows. Adding it requires:

1. A **verified legal or regulatory requirement**, cited and dated.
2. Explicit approval.
3. A fresh threat model covering file handling (`Prd_Maker.md` §56).
4. Malware scanning, content validation, and signed expiring URLs.
5. A separate encryption boundary and its own retention policy.

**Requirement 1 is non-negotiable and is the reason this ADR exists.** Storing document images must not be assumed to be legally required merely because a hotel performs guest registration. That is an inference, not a fact, and the distinction is the whole purpose of the minimization decision.

### 10. The three-way distinction (required by the project manager)

| Category | Content | Handling |
|---|---|---|
| **(1) Required for hotel operations** | Name, contact, stay dates, room, folio, payment outcome | Minimized; purpose-limited; retained per policy |
| **(2) Required by verified Saudi regulatory requirements** | **UNKNOWN.** Which guest-registration fields are required, and for how long, requires verification against authoritative sources (`C-02`). ZATCA invoice fields are a separate sub-category, `UNKNOWN` until `B-02` | Not asserted; not assumed; not inferred |
| **(3) Optional — MUST NOT be collected by default** | Document images, biometric data, marketing consent, nationality-based profiling, cross-property behavioural tracking, inferred preferences | Not collected |

### 11. `D-004` is an engineering decision, not a legal determination

Explicitly recorded so it cannot be misread: this ADR is a **data-minimization engineering decision**. It is **not** a determination of which fields Saudi law requires a hotel to collect or retain. Regulatory applicability must be verified against authoritative sources before production (`C-02`), and this document provides **no legal advice**.

## Criteria Applied

Privacy (minimization and purpose limitation), security (encryption, masking, access control, audit), compliance (PDPL obligations without any compliance claim), operational simplicity (no file-handling subsystem in Phase A), reversibility (the data model permits a future isolated capability).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Store passport/national ID/Iqama images | **Rejected for v1.0** | Largest breach impact, longest retention burden, and a file-handling control surface — all for a requirement that has not been verified. Revisitable under §9. |
| Store nothing at all | Rejected | Would very likely prevent compliant guest registration. Minimization is not elimination. |
| Encrypt with the same key as business data | Rejected | Defeats the purpose of encryption at a boundary: routine access would yield identity data. |
| Decrypt on read without masking | Rejected | Every screen read becomes an exposure. Masking by default with an audited reveal is the standard control. |
| Log identity access for debugging | **Rejected** | The log becomes a secondary identity store with weaker access control and longer retention. |
| Set retention periods now | Deferred | A legal and business decision (`C-09`). Engineering does not invent it. |
| Rely on developer discipline to avoid logging identity data | Rejected | Enforced by automated log scanning instead. |

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Identity data leaks into logs | Medium | **Critical** | Automated log scan in CI; redaction of provider payloads; review rule |
| A future request adds document images without justification | Medium | High | Rationale recorded at the decision point; the four preconditions in §9 |
| The required field set is later found to include images | Medium | High | Data model permits an isolated addition; requirement is verified before Phase A3 close (`C-02`) |
| An authorized user bulk-reveals identity data | Low | High | Export controls, volume limits, step-up, full audit (`SEC-015`) |
| Retention is never configured, so data accumulates indefinitely | High | Medium | `C-09` assigned an owner; purge job is a release requirement |
| Key boundary is not implemented in practice | Medium | **Critical** | Key management is a release gate, not a follow-up (`H-03`) |
| A guest exercises erasure and financial records are deleted | Low | **Critical** | Separation of operational and financial data; escalation until policy exists |

## Reversibility

**Moderate.** Field-level remediation (widen masking, tighten roles, rotate keys) is straightforward and cheap. **Image storage would not be reversible in the same way** — once scanned, copied, and integrated into processes, removing it means deleting data that may have been relied upon, and it would have permanently enlarged the breach surface. That asymmetry is the strongest argument for the fields-only decision and for requiring verified evidence before it is revisited.

## References

`D-004`, `D-007`, `BUS-012`, `BUS-016`, `ADR-0011`, `ADR-0013`, `ADR-0016`, `ADR-0021`, `C-02`, `C-09`, `H-03`, `Prd_Maker.md` §21, §22, §51, §56, §64, `V-06`, `V-07`, `V-08`, `V-09`, `docs/DATA-MODEL.md` §5.1, §6, `docs/SECURITY.md` §2, `docs/COMPLIANCE.md` §2.
