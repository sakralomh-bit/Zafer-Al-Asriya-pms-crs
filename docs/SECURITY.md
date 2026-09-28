# Zafer Al-Asriya v1.0 — Security

| Field | Value |
|---|---|
| Document | `docs/SECURITY.md` |
| Version | 0.1 |
| Status | Draft — requirements and threat model. **No control is implemented; there is no code.** |
| Security Owner | **`TBD` (`C-10`)** — this document cannot be signed off without one |
| Related | `ADR-0014`, `ADR-0016`, `ADR-0011`, `ADR-0012`, `Prd_Maker.md` §14, §20.6, §54, §55, §56 |

---

## 1. Honest statement of current state

There is **no application, no infrastructure, no deployed environment, and no data** in this repository. Everything below is a **requirement**, not a description of a control in place. Any statement that a control "is in place" would be false.

The threat model below is therefore a **design-time exercise**: it identifies what must be defended before code is written, which is the cheapest time to defend it.

## 2. Threat model

Per `Prd_Maker.md` §54, covering the named threat classes.

| ID | Threat | Asset | Attack surface | Likelihood | Impact | Required control | Residual risk |
|---|---|---|---|---|---|---|---|
| `TH-01` | **Spoofing** — a user or system pretending to be another identity | Guest PII, financial records | Stolen session, weak password policy, missing MFA, unverified webhook | Medium | **Critical** | Strong password hashing (**algorithm `TBD`**), session management, MFA for privileged roles, step-up for sensitive actions, webhook signature verification | Medium until MFA coverage is complete |
| `TH-02` | **Tampering** — altering financial or operational data | Folio, payments, reservations, configuration | Direct DB access, mass assignment, unvalidated input | Low | **Critical** | Server-side validation, deny-by-default mass assignment protection, DB not publicly reachable, append-only ledger, config versioning | Low |
| `TH-03` | **Repudiation** — denying what was done | Audit trail, dispute evidence | Missing audit, mutable audit, shared accounts | Low | **Critical** | Append-only audit, separation of duties, per-identity accounts, no shared logins | Low |
| `TH-04` | **Information disclosure** — leaking guest identity data | Document numbers, contact data, folios | Logs, telemetry, error messages, exports, backups, support access | **High** | **Critical** | Minimization (no images), separate key boundary, masking, access logging, **automated log scanning**, export controls, error minimization, encrypted backups | **Medium** — the most likely real leak path |
| `TH-05` | **Denial of service** — the PMS becomes unusable | Availability of check-in | Availability search flooding, report abuse, queue exhaustion | Medium | High | Rate limiting, bounded page sizes, allow-listed sorting, queue backpressure, capacity planning (`B-05`) | Medium until limits are set |
| `TH-06` | **Privilege escalation** — gaining rights not granted | All assets | Missing authorization check, client-side enforcement, mass assignment | Medium | **Critical** | Backend-enforced authorization on every request, deny by default, no UI-only control, no superuser flag, authorization test matrix | Low |
| `TH-07` | **Property breakout** (tenant breakout in a single-tenant system) — a user in Property A reaching Property B | Guests, folios, financial records across 10 properties | Missing scope check on one endpoint, direct object reference, inferred scope | **Medium** | **Critical** | Explicit grant model, mandatory scope check on every property-scoped endpoint, **deny rather than filter**, breakout test across all roles and all 10 properties | Low if the test is enforced |
| `TH-08` | **Replay attacks** | Payments, compliance submissions, sessions | Replayed requests, replayed webhooks, replayed idempotency keys | Medium | High | Idempotency keys, webhook replay protection, timestamp tolerance, short-lived credentials | Low |
| `TH-09` | **Webhook forgery** — a forged callback moving money or state | Payments, invoice compliance status | Public callback URL, unsigned payloads | Medium | **Critical** | Signature verification (**mechanism `UNKNOWN`** pending provider), timestamp tolerance, source validation, idempotency, out-of-order rejection | Medium until a provider is chosen |
| `TH-10` | **Duplicate payment requests** | Money | Client retry, operator double-click, unknown-outcome retry | **High** | **Critical** | `Idempotency-Key` on every money operation, `UNKNOWN_OUTCOME` state with `retryable: false`, reconciliation-only resolution | Low |
| `TH-11` | **Malicious file upload** | Any uploaded content | File endpoints | **N/A** | N/A | **No file upload exists in Phase A.** If identity images are ever approved, `Prd_Maker.md` §56 controls apply before the capability exists | N/A |
| `TH-12` | **Export abuse** — bulk extraction of guest data | Guest PII | Export endpoints | Medium | High | Role-gating, volume limits, step-up, full auditing, watermark/provenance, rate limiting | Low |
| `TH-13` | **Cross-border data exposure** | Guest PII | Cross-region replication, telemetry export, vendor support access | Low | **Critical** | Replication default-off, transfer-assessment gate, subprocessor review, scoped and time-bound support access (`D-007`) | Low if the gate is enforced |
| `TH-14` | **Card data exposure** | Cardholder data | Payment paths, provider error payloads, logs | Low | **Critical** | **No card column exists in the schema**; provider-hosted/tokenised flows; no card data in logs or telemetry | Low |
| `TH-15` | **Business-date manipulation** — closing or reopening a period to alter reported results | Financial records, tax invoices | Night audit controls, reopen authorization | Low | **Critical** | Single-run enforcement, separate reopen authorization with approval, immutable postings, full audit | Low |
| `TH-16` | **Configuration abuse** — changing tax or policy to misstate results | Tax correctness, revenue | Config endpoints, over-broad config permissions | Low | High | Permission-controlled, validated, versioned, recoverable, audited (`Prd_Maker.md` §61), step-up on tax changes | Low |

**Note on likelihoods:** these are engineering judgements made without production data. They are **not** derived from incident history, because there is no incident history.

## 3. Authorization

`ADR-0014` is normative. Summary:

```text
Allow = Identity × Role × Resource × Action × Scope × Policy
```

- **Deny by default**, explicit grants, **backend enforcement on every request**.
- Property scope is an **explicit grant record**, never a superuser flag. Group Manager holds 10 explicit grants.
- An out-of-scope request is **denied** (`PROPERTY_SCOPE_DENIED`), never returned as an empty result — an empty result both leaks existence and hides scope bugs from tests.
- **UI visibility is not a security boundary.** Hidden buttons, menus, and fields are user-experience features, not controls.
- **Impersonation is default DENY.** No endpoint exists for it.

Full role matrix: `ADR-0014` §5. The cross-role boundaries that matter operationally and each of which is a test case: Finance cannot check a guest in; an Auditor cannot write anything; Housekeeping cannot see a guest's document number; a Reservation Agent cannot take a payment; a user cannot grant themselves scope.

## 4. Sensitive data handling

### 4.1 Identity data (`ADR-0012`)

| Rule | Status |
|---|---|
| Fields only (type, issuing country, number, expiry) — **no images** | Decided |
| Encrypted at rest under a **separate key boundary** | Required; key management detail `TBD` (`H-03`) |
| Masked by default; reveal requires explicit authorization + step-up + audit | Decided |
| Access to a revealed field is audited; the **value** is not logged | Decided |
| **Excluded from logs, metrics, traces, error reports, telemetry** | Required; enforced by automated scan |
| Retention configurable; periods `TBD` (`C-09`) | Decided, values pending |
| Synthetic data only outside production | Decided |

**Which fields are legally required is `TBD` (`C-02`).** This is a minimization decision, not a legal determination, and no legal advice is given.

### 4.2 Card data (`ADR-0011`)

| Rule | Status |
|---|---|
| **Never** store PAN, CVV/CVC, or magstripe data | Decided — **structurally enforced: no such column exists** |
| **Never** in logs, analytics, or telemetry | Required; enforced by automated scan |
| Store only provider-safe references | Decided |

**No PCI DSS compliance is claimed.** Tokenization and provider-hosted flows reduce scope; actual scope depends on the final architecture, the provider relationship, the environments, the controls, and the applicable assessment requirements.

### 4.3 Financial data

Confidential. Append-only. Exports restricted and audited. Refunds step-up. No deletion.

### 4.4 Error and log hygiene

Never emit to a client or a log: stack traces, SQL, internal hostnames, secrets, environment values, internal class or table names, **document numbers**, card data. Tests assert this, because a leak in a log is durable, widely readable, and typically outlives the code that produced it.

## 5. Audit trail

`ADR-0016` is normative. Append-only; who/what/when/where/before/after/reason/correlation/source/result; separation of duties; **no user can alter the record of their own action**; every identity-field reveal audited; audit excluded from guest-facing exports; support audit access scoped and itself audited; retention `TBD` (`C-09`).

## 6. Application security

| Control | Requirement | Status |
|---|---|---|
| Password hashing | **Algorithm `TBD`** — must meet current guidance; not assumed | Blocked |
| Session security | Idle timeout, absolute lifetime, revocation on role change | **Values `TBD`** |
| MFA | Available; required for privileged roles and step-up flows | Specified |
| Step-up authentication | Required for the seven canonical operations in §6.1 | **Specified, mechanism `TBD`** |
| CSRF | Required for cookie-authenticated state-changing requests | Specified |
| Security headers | On all responses | **Exact set `TBD`** |
| Rate limiting | Authentication, availability search, export, payment submission | **Values `TBD`** (`B-05`) |
| Input validation | Schema validation at the edge | Specified |
| Mass assignment protection | Deny by default | Specified |
| Request size limits | On all bodies | Specified |
| Dependency scanning | CI | Required |
| Secret scanning | CI; no production secret in source control | Required (`D-007`) |
| SAST / static analysis | CI | Required |
| Penetration testing | Before production | Required; scope `TBD` |
| Vulnerability management | Patch cadence and SLAs | **`TBD`** |

### 6.1 Canonical step-up operations

Step-up is required for a **closed, enumerable** set of operations (`DR-T004-09`). Naming them here is the point: "sensitive" must not be re-decided by whoever writes the next endpoint, because a control whose trigger is left to each caller is not a control.

| # | Operation | Why | Source |
|---|---|---|---|
| 1 | **Refund a payment** | Separation of duties — the approver must not be the requester | `ADR-0014` §6 |
| 2 | **Post a configuration change** (tax rate, policy) | A config change rewrites what every future financial statement says | `SEC-006`, `TH-16` |
| 3 | **Grant or revoke a property scope** | Scope grants are the widest privilege in the system; self-granting is already barred | `ADR-0014` §6 |
| 4 | **Reopen the business date** | Rewrites already-closed accounting periods | `ADR-0014` §6 |
| 5 | **Export data** | Bulk extraction of guest PII | `TH-12` |
| 6 | **Impersonate another user** | Act-as is the strongest privilege of all | `ADR-0014` §6 |
| 7 | **Reveal a masked identity document** | Defeats masking, which is the control on that data | `ADR-0012`, §4.1 |

This supersedes the earlier six-item summary in §6, which omitted #7 while §4.1 already required step-up for the reveal.

**An operation not on this list does not require step-up.** Adding an eighth is a change to this table, not an implementation detail.

**Status: specified, not implemented.** `T-004` recorded `STEP_UP_PERFORMED` in the audit vocabulary and reserved `auth.step_up_at` / `auth.step_up_operation` session keys, but built no gate, no re-authentication endpoint, and no MFA challenge handler. The mechanism that *satisfies* a step-up is unspecified (`DR-T004-08`, OPEN; `SEC-007`). The control above therefore **does not exist at runtime**, and no endpoint is registered to invoke it. It is documented now so the implementer inherits the list instead of inventing a different one.

## 7. Infrastructure security

Per `ADR-0013` and `D-007`: private networking with **no public database endpoint** · TLS in transit · encryption at rest · encrypted backups in approved Saudi-region storage · centralized secrets management · KMS with a **separate key boundary for identity data** · IaC-only provisioning with drift detection · centralized monitoring · **no automatic cross-border PII replication** without an approved transfer assessment.

**Key management is a release gate, not hardening** (`H-03`).

## 8. Backup security

Backups are encrypted, held in approved Saudi-region storage, access-restricted, and covered by the same identity-data key boundary as the primary. Retention is documented (**values `TBD`**, `H-07`). **Restore validation is a release requirement** — a backup that has never been restored is an assumption, and discovering it is unrestorable during an incident is the worst possible timing.

## 9. Webhook security

`Prd_Maker.md` §55 requirements apply in full: signature verification (**mechanism `UNKNOWN`** pending provider) · timestamp tolerance · replay protection · source validation · idempotency · event ordering · deduplication · response behaviour · audit logging.

**Never trust a callback solely because it came to the correct URL.** A correct URL is public knowledge; the signature is the control.

## 10. File handling

**`NOT_APPLICABLE` in Phase A** — no file upload capability exists, because identity images are out of scope (`D-004`) and nothing else in Phase A uploads files.

If a future change introduces file upload, `Prd_Maker.md` §56 controls apply **before** the capability is built: accepted types, maximum size, malware scanning, content validation, storage location, encryption, download authorization, expiring URLs, naming rules, retention, deletion, audit, and preview restrictions. **Never trust a file extension alone.**

## 11. Security testing

Per `ADR-0021`: authentication · session handling · the full authorization matrix · **property breakout across all 12 roles and all 10 properties** · privilege escalation · injection (SQL, command, template, stored) · CSRF · rate limiting and abuse · **sensitive data leakage** (error bodies, logs, telemetry, exports) · export abuse and volume limits · webhook forgery and replay · impersonation denial · backup and restore access · **automated scan asserting no identity or card data in captured log output**.

## 12. Open security decisions

| ID | Item | Owner |
|---|---|---|
| `SEC-007` | Password hashing algorithm | Security (`TBD`) |
| `SEC-008` | Session idle timeout and absolute lifetime | Security + Security (`TBD`) |
| — | Exact security header set | Security (`TBD`) |
| — | Rate limit values | Security + Ops (`TBD`, needs `B-05`) |
| — | MFA coverage: which roles mandatory | Security (`TBD`) |
| — | Key management: rotation, hierarchy, decryption authority | Security + Ops (`H-03`) |
| — | Vulnerability patch cadence and remediation SLAs | Security (`TBD`) |
| — | Penetration test scope and timing | Security (`TBD`) |
| `SEC-013` | Webhook signature mechanism | Depends on `B-04` / `B-02` |
| `C-10` | **Who the security owner is** | **PM — blocks all of the above** |

## 13. Residual risk statement

| Risk | Severity | Why it cannot currently be closed |
|---|---|---|
| Design-level risk of a property breakout | **Critical** | The control is specified; the test does not exist. Closed only by `CON`/breakout tests passing. |
| Identity data reaching a log | **Critical** | The control is specified; the scan does not exist. |
| Duplicate charge from an unknown outcome | **Critical** | The state machine is specified; no implementation exists. |
| Cross-border PII exposure | **Critical** | Gate specified; no provider chosen, so the configuration cannot be reviewed (`B-03`). |
| Unreviewed regulatory posture | **High** | The organization may have a compliance exposure independent of this system, and that is unknown to engineering (`B-01`). |
| No security owner | **High** | `C-10`. No security review can be signed off. |
| Undefined session and credential policy | Medium | Blocked on `SEC-007`, `SEC-008`. |

**No security assurance of any kind is provided by this document.** It specifies what must be built, what must be tested, and what remains unknown.
