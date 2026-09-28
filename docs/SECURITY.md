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

Step-up is required for a **closed, enumerable** set of operations. Naming them here is the point: "sensitive" must not be re-decided by whoever writes the next endpoint, because a control whose trigger is left to each caller is not a control.

*(`DR-T004-09` is referenced in several places in this repository as the origin of this list. It is not an entry in the authoritative register (`docs/PRD.md` §15) and defines nothing; the list's authority is this table, cross-referenced by `docs/API-SPEC.md` §3.11.1 and `PRD.md` `SEC-018`. Registering it is `PM registration required` — see §12.)*

| # | Operation | Why | Source |
|---|---|---|---|
| 1 | **Refund a payment** | Separation of duties — the approver must not be the requester | `ADR-0014` §6 |
| 2 | **Post a configuration change** (tax rate, policy) | A config change rewrites what every future financial statement says | `SEC-006`, `TH-16` |
| 3 | **Grant or revoke a property scope** | Scope grants are the widest privilege in the system; self-granting is already barred | `ADR-0014` §6 |
| 4 | **Reopen the business date** | Rewrites already-closed accounting periods | `ADR-0014` §6 |
| 5 | **Export data** | Bulk extraction of guest PII | `TH-12` |
| 6 | **Impersonate another user** | Act-as is the strongest privilege of all | `ADR-0014` §6 |
| 7 | **Reveal a masked identity document** | Defeats masking, which is the control on that data | `ADR-0012`, §4.1 |

**CONFLICTS OF RECORD.** These were recorded by an earlier run with no resolution
preferred between the sides. Each has since been **examined and classified**; the
classification, the evidence, and the recommended resolution are in **§12.1.7**, which
is the authoritative statement of their current status. Nothing below has been
silently rewritten, and no ADR, `PRD.md`, or `TASKS.md` text was changed to obtain
those classifications.

- **Conflict A — the acceptance criterion points at a different document.**
  `AC-T-004-05` (`docs/TASKS.md`) refers to "the sensitive operation set in
  `ADR-0014` §6". `ADR-0014` §6 is a separation-of-duties table of additional
  controls, not a step-up set: only one of its rows (Refund) names step-up at all.
  The seven-item set lives here, in `docs/API-SPEC.md` §3.11.1, and in `PRD.md`
  `SEC-018`. Which document the criterion is bound to is undecided.
- **Conflict B — a supersession claim with no target.** An earlier revision of this
  file stated that this table superseded "a six-item summary in §6". No such list
  exists in §6; the only step-up entry in §6 is the row that points forward to this
  §6.1. The claim has been removed rather than resolved, because what it referred to
  is not recoverable from the repository.
- **Conflict C — step-up required outside this list.** `docs/STATE-MACHINES.md`
  requires step-up for three operations that are not on this list: reservation
  cancellation (§A-T16, line 78), raising `OUT_OF_ORDER` (line 155), and
  `FORCED_CLOSE` (line 492). The statement below says an operation outside this list
  does not require step-up, so the two documents currently disagree. This table is
  the canonical list and has **not** been extended.

**An operation not on this list does not require step-up.** Adding an eighth is a
change to this table, not an implementation detail. — *formally resolved by
§12.1.7 "Conflict C" as `PROPOSED — GOVERNANCE`: the normative-document rule
(`PRD.md:811`) makes this list govern, and the three `STATE-MACHINES.md` references are
not currently registered Step-up requirements. Expanding the set is a PM act and is
**not** done here.*

**Status: gate implemented; the endpoint that would invoke it is not.** The
control now exists at runtime and is enforced, but nothing a user can reach calls
it yet. Recorded precisely, because "does not exist" and "exists but is not wired
to a route" are very different statements and the first understates what was built.

What exists: `StepUpGuard` (`app/Modules/Identity/Auth/StepUpGuard.php`) enforces
all four conditions — exists, fresh, bound to this operation, bound to this
subject — and `StepUpOperation` holds the seven as a closed enum. `StepUpVerifier`
performs RFC 6238 verification and mints a `StepUpProof`; `StepUpGuard::complete()`
will not record a completed step-up without one, and the three session keys are
sealed with an HMAC so the session payload alone cannot establish a step-up. The
gate was previously reachable by any caller holding the guard, with no second
factor involved; that hole is closed and `tests/Security/StepUpVerifierTest.php`
proves it by driving real TOTP verification.

What does not exist: no route invokes any of it. `API-SPEC.md` §3.11 describes
`POST /api/v1/auth/step-up` and `POST /api/v1/auth/mfa/verify`; neither is
registered. There is no MFA enrolment flow, no recovery path, and no secret store —
`mfa_secrets` remains reserved in `docs/DATA-MODEL.md` §2 with no migration, because
who may enrol and who authorises a recovery is the `C-10` accountability question
and answering it here would be inventing it. `AC-T-004-05` therefore stays
**PARTIAL**: a control with no caller is not an enforced control, and claiming
otherwise would be the same class of overstatement this section exists to prevent.

The MFA *mechanism* row in §12 remains open, and `DR-T004-08` is still an
unregistered reference rather than a decision. TOTP is implemented as the baseline
factor (`config/security.php`, `docs/SECURITY.md` §12.1.1 row 12) under delegated
technical authority; that is an implemented baseline, not a registered decision, and
it does not resolve the row.

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

The authoritative Decision Register for this project is `docs/PRD.md` §15, which
currently holds `DR-001` … `DR-014`. **Identifiers of the form `DR-T004-*` that
appear elsewhere in this repository are NOT entries in that register and carry no
registered meaning.** They are listed here and in §6.1 as *unregistered references*
so that the ambiguity is visible rather than implied; formal registration is a PM
decision (`PM registration required`) and is not made in this document.

Rows below with `—` in the ID column are open items that have never been assigned a
registered ID. That is the existing convention of this table, not a new one.

| ID | Item | Owner |
|---|---|---|
| `SEC-007` | Password hashing algorithm | Security (`TBD`) |
| `SEC-007` | Password hashing work factor / cost | Security (`TBD`) |
| `SEC-008` | Session idle timeout and absolute lifetime | Security + Security (`TBD`) |
| — | Step-up freshness window (how long a completed step-up stays valid) | Security (`TBD`) |
| — | Exact security header set | Security (`TBD`) |
| — | Security-header enforcement location | Security (`TBD`) |
| — | Rate limit values (max attempts, decay, lockout threshold, lockout duration) | Security + Ops (`TBD`, needs `B-05`) |
| — | Lockout keying for unconfirmed / nonexistent accounts | Security + Ops (`TBD`, needs `B-05`) |
| — | MFA mechanism | Security (`TBD`) |
| — | MFA coverage: which roles mandatory | Security (`TBD`) |
| — | Retention of raw IP addresses in immutable audit records | Security + Legal (`TBD`, needs `C-09`) |
| — | Key management: rotation, hierarchy, decryption authority | Security + Ops (`H-03`) |
| — | Vulnerability patch cadence and remediation SLAs | Security (`TBD`) |
| — | Penetration test scope and timing | Security (`TBD`) |
| `SEC-013` | Webhook signature mechanism | Depends on `B-04` / `B-02` |
| `C-10` | **Who the security owner is** | **PM — blocks all of the above** |

**Every row in the table above is addressed — with a classification and, where a
baseline is defensible, a recommended value — in §12.1.1.** The table is the
authoritative list of what is open; §12.1.1 is the AI Project Lead's technical
analysis of it. Neither is a substitute for the other, and §12.1.1 does not change
any row's open status: a `PROPOSED` row is still open, and a `BLOCKED` row is still
blocked.

### 12.1 AI Project Lead — Proposed T-004 Security Decisions Pending Formal Registration

**What this section is, and what it is not.**

Under the current delegation the AI Project Lead holds *technical decision-making
authority*: it may make a defensible engineering or security proposal wherever the
answer follows from the established requirements, the normative documentation, the
existing threat model, OWASP guidance, established security practice, or framework
capability. What it does **not** hold is the authority to *register* a decision.

| Label | Meaning | Status in this repository |
|---|---|---|
| `DECIDED` | The requirement is **already formally established** in the authoritative project documentation. Recorded here for traceability; nothing was decided by this section | Formal |
| `PROPOSED — ENGINEERING` | An AI Project Lead technical proposal. **Not a decision** | **Proposal only** |
| `PROPOSED — SECURITY` | An AI Project Lead security proposal. **Not a decision** | **Proposal only** |
| `BLOCKED — BUSINESS` | Requires a business fact the project does not hold | **Blocker** |
| `BLOCKED — LEGAL/COMPLIANCE` | Requires a legal or compliance determination | **Blocker** |
| `BLOCKED — ACCOUNTABILITY` | Requires a named accountable owner | **Blocker** |
| `BLOCKED — GOVERNANCE` | Requires a PM change to the authoritative register | **Blocker** |

**The word `APPROVED` does not appear in this section, and must not be applied to
anything in it.** `docs/PRD.md` §15 holds `DR-001` … `DR-014` and nothing else; an
identifier of the form `DR-T004-*` is not a registered Decision ID and this section
does not create one, imply one, or use one as authority for a proposal.

**Implementation remains prohibited for every row in this section.** No code, config,
migration, or `.env` value was changed to produce it, and none may be until the
appropriate governance gate is passed by the accountable owner. A proposal is not a
licence. Where a proposal is a *baseline* rather than a *value*, the value still
requires the named authority.

**Normative hierarchy used throughout**, from `docs/PRD.md:811` (*"`docs/SECURITY.md`
is normative"*) and the relationship each document declares for itself:

```text
docs/PRD.md §15 register + the normative-document rule
    → docs/SECURITY.md          (normative for security)
        → docs/API-SPEC.md      (cross-references §6.1 for the step-up set)
            → docs/STATE-MACHINES.md, docs/DATA-MODEL.md   (specification / subordinate)
```

`docs/STATE-MACHINES.md:7` states its own status as *"Draft — specification only.
Nothing is implemented"*, and §0 states that its states and transitions *"are a
requirement for `docs/TASKS.md`, not a description of existing behaviour"*. It is
therefore subordinate in both status and self-declared maturity, which is what
resolves Conflict C below. No ADR and no PRD text was modified to obtain this.

#### 12.1.1 Proposal register

22 rows. The §12 table above supplies 16 of them; the remaining six are the
per-dimension splits this section is required to decide separately — `SEC-007`
algorithm vs. cost, `SEC-008` idle vs. absolute, the `B-05` lockout values vs. their
keying architecture, and raw-IP *necessity* vs. *period* vs. *owner*.

| # | Row | Classification | Recommended value | Deferred? |
|---|---|---|---|---|
| 1 | `SEC-007` password hashing **algorithm** | `PROPOSED — SECURITY` | **Argon2id** | Yes |
| 2 | `SEC-007` password hashing **work factor** | `BLOCKED — BUSINESS` | Baseline floor `m=65536 KiB, t=2, p=1` | Yes |
| 3 | `SEC-008` **idle** timeout | `PROPOSED — SECURITY` | **900 s (15 min)** | Yes |
| 4 | `SEC-008` **absolute** lifetime | `PROPOSED — SECURITY` | **43200 s (12 h)** | Yes |
| 5 | Step-up freshness window | `PROPOSED — SECURITY` | **300 s (5 min)** | Yes |
| 6 | Exact security header set | `PROPOSED — SECURITY` | §12.1.2 table | Yes |
| 7 | Security-header **enforcement location** | `PROPOSED — ENGINEERING` | §12.1.3 | Yes |
| 8 | `B-05` max attempts + decay period | `BLOCKED — BUSINESS` | Baseline `5 / 300 s` | Yes |
| 9 | `B-05` lockout threshold + duration | `BLOCKED — BUSINESS` | Baseline `5 failures / 900 s` | Yes |
| 10 | `B-05` lockout keying for **unconfirmed / nonexistent** accounts | `BLOCKED — BUSINESS` | Same bucket as a real account; never a separate one | Yes |
| 11 | `B-05` rate-limit **keying architecture** | `PROPOSED — SECURITY` | Independent account **and** IP dimensions, both enforced | Yes |
| 12 | `MFA` **mechanism** | `PROPOSED — SECURITY` | TOTP primary, passkey/WebAuthn preferred where available | Yes |
| 13 | `MFA` **coverage** (which roles) | `PROPOSED — SECURITY` | §12.1.4 — 6 of the 12 `ADR-0014` §5 roles | Yes |
| 14 | Raw IP retention — **technical necessity** | `DECIDED` | Retain; already required by `ADR-0016` §2 and §5 | n/a |
| 15 | Raw IP retention — **period** | `BLOCKED — LEGAL/COMPLIANCE` | *None proposed* | Yes |
| 16 | Raw IP retention — **owner** | `BLOCKED — ACCOUNTABILITY` | *None proposed* | Yes |
| 17 | Key management (rotation, hierarchy, decryption authority) | `BLOCKED — BUSINESS` | *None proposed* | Yes |
| 18 | Vulnerability patch cadence + remediation SLAs | `PROPOSED — ENGINEERING` | §12.1.5 | Yes |
| 19 | Penetration test scope + timing | `BLOCKED — BUSINESS` | *None proposed* | Yes |
| 20 | `SEC-013` webhook signature mechanism | `BLOCKED — BUSINESS` | *None proposed* | Yes |
| 21 | `C-10` Security Owner — **role** | `DECIDED` | "Security Owner" | n/a |
| 22 | `C-10` Security Owner — **person** | `BLOCKED — ACCOUNTABILITY` | *None proposed* | Yes |

#### 12.1.2 `SEC-007` — password hashing

**Row 1 — algorithm. `PROPOSED — SECURITY`. Recommended value: Argon2id.**

This is a **proposal**, not a registered Decision, and no code or config change was
made to record it. Specifically:

- `ADR-0002`'s mention of argon2id (line 18) is **supporting evidence and capability
  context only**. It is a backend-stack rationale line, and it is **not** prior formal
  approval of `SEC-007` — `SEC-007` was still recorded `TBD` in the same document set.
- The proposal rests on: the project's own `TH-01` mitigation ("strong password
  hashing"); `SEC-007`'s own wording ("must meet current guidance"); the memory-hard
  property of Argon2id against GPU/ASIC cracking; and **verified framework capability**
  — `Illuminate\Hashing\Argon2IdHasher` ships with the pinned `laravel/framework
  ^13.17` (resolved 13.33.0), exposes `createArgon2idDriver()`, and the running
  PHP 8.3.33 build has `PASSWORD_ARGON2ID` defined. `SecurityPolicy` already resolves
  the algorithm through `Hash::driver()`, so no code change is implied by adopting it.
- **Trade-off:** Argon2id's memory cost makes it a poor fit for a memory-constrained
  or autoscaling environment with a tight per-pod memory limit, and it cannot be
  tuned downward at runtime the way bcrypt's cost can. `bcrypt` remains the fallback
  if the deployment cannot hold 64 MiB per concurrent login.

**Row 2 — work factor / cost. `BLOCKED — BUSINESS`.**

A final cost cannot be selected without deployment capacity, and selecting one
anyway is the accident `T-004` §Risks warns about. This is a capacity fact, not a
security judgement.

> **Recommended baseline (a floor, not an approval):** `memory_cost = 65536 KiB`,
> `time_cost = 2`, `threads = 1` — the OWASP-aligned minimum for Argon2id.
>
> **Measured on the pinned runtime** (PHP 8.3.33, `PASSWORD_ARGON2ID`, 10 iterations,
> this machine — indicative only, not a capacity result):
>
> | Parameters | ms/hash | Concurrent logins within 1 CPU-sec/sec |
> |---|---|---|
> | `t=1 m=64 MiB p=1` | 48.6 | ~20 |
> | **`t=2 m=64 MiB p=1`** | **82.3** | **~12** |
> | `t=2 m=128 MiB p=1` | 173.1 | ~5 |
> | `t=3 m=64 MiB p=1` | 116.5 | ~8 |
> | `t=3 m=128 MiB p=1` | 234.3 | ~4 |
> | `t=4 m=128 MiB p=1` | 305.9 | ~3 |
> | `bcrypt cost=12` (for reference) | 190.7 | ~5 |
>
> **CPU / memory / load dependency:** Argon2id is *memory-hard*, so the binding
> constraint is per-process memory, not CPU. At the proposed floor each concurrent
> login holds 64 MiB; at `t=2/m=128 MiB` it holds 128 MiB. Peak concurrent
> authentications therefore sets the memory requirement, and peak authentication
> *rate* sets the CPU requirement. `B-05` currently records **peak concurrency and
> staffing levels as `NOT CONFIRMED`** with inferred value **`NONE`**; a cost factor
> chosen without them is a guess that silently becomes a production capacity limit.
>
> **To unblock:** peak concurrent staff authentications (absolute, not average);
> staff headcount per property per shift; the container/pod memory ceiling; the number
> of application replicas; and the peak login rate at shift start. All are `B-05` /
> `DEP-005` items owned by PM + operations.

#### 12.1.3 `SEC-008` — session lifetimes, and step-up freshness

All three are `PROPOSED — SECURITY`. None is legally required, and none is claimed
to be. The rationale below is drawn from the project's own threat model (`TH-01`
spoofing via stolen session) and from the privileged back-office nature of a PMS —
a session at this workstation can reach refunds, folios, and guest identity data.

**Row 3 — idle timeout. Recommended: 900 s (15 minutes).**

An idle timeout bounds how long a *present but unattended* workstation stays
authenticated — the walk-away-from-a-desk case, which in a back office with a
shared or open station is the ordinary case rather than the exotic one. Session
hijacking exposure: a stolen session cookie is only useful while the session is
still inside its idle window, so the idle timeout is the control that most directly
caps that exposure.

- **Trade-off:** a 15-minute idle timeout is tight for long operational tasks. A Night
  Auditor running a batch, or a Finance user reconciling across screens, will be
  re-prompted mid-task. The operational cost is real and is why the value is
  proposed at 15 minutes rather than 5. It is a *proposal* precisely because the
  right balance between desk security and desk productivity is a business fact the
  project does not hold.

**Row 4 — absolute lifetime. Recommended: 43200 s (12 hours).**

The idle timeout bounds the unattended case; the absolute lifetime bounds the
*continuously used* case — an attacker who holds a live session and keeps it active
would otherwise never trip the idle timeout. `43200 s` strictly exceeds the `900 s`
idle timeout, so the idle timeout always binds first within a shift, while the
absolute cap ends a session at the end of a working day regardless of activity.

- **Credential/session theft window:** 12 hours is a shift, not a week. A token
  captured from a shared or compromised workstation is useful for the remainder of
  that shift and no longer.
- **Operational usability:** one working day of continuous use is permitted without
  re-authentication; the next authentication is a login.
- **Interaction with privileged operations:** a long absolute lifetime does **not**
  weaken step-up, because step-up is separately gated on its own freshness window
  (row 5). The two controls are independent by design, and shortening either does
  not substitute for the other.
- Laravel's stock `config/session.php` `'lifetime' => 120` is **not** the basis of
  either proposal; `test_laravels_stock_session_lifetime_is_not_adopted` asserts it
  is not adopted.

**Row 5 — step-up freshness. Recommended: 300 s (5 minutes).**

**This row has no registered Decision ID.** It is the `—` row in the §12 table above.
No `SEC-008-C` is invented here, and none should be.

- **Security property:** the window bounds how long a completed re-authentication
  authorises further privileged operations. A step-up is a *recent* proof of presence
  for a *specific* operation, not a session-lifetime substitute. 5 minutes is long
  enough to complete one operation deliberately and short enough that a
  step-up-authorised workstation left unattended stops authorising privileged actions
  quickly.
- **Dependency on the final MFA mechanism:** this value's *meaning* depends on row 12
  and nothing more. Whichever mechanism is chosen, freshness is a timestamp on a
  session key (`auth.step_up_at`) compared against a threshold. If the mechanism is
  a possession factor (passkey/TOTP) the window can be somewhat longer; if it is
  something the user re-types, shorter. **This dependency is a design input, not a
  legal blocker**, and it is not the reason the row is a proposal rather than a
  decision.
- **Scope coupling:** the freshness window is per-**operation**, matching the existing
  `auth.step_up_operation` session key. A step-up taken to reveal a document number
  must not authorise a refund.

**Rows 6 and 7 — security headers. Row 6: `PROPOSED — SECURITY`. Row 7: `PROPOSED — ENGINEERING`.**

*Technically recommended baseline (row 6).* No header below is claimed to be legally
required; the project documentation establishes none, and the split is deliberate.

| Header | Value | Purpose |
|---|---|---|
| `Content-Security-Policy` | `default-src 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'` | `frame-ancestors 'none'` carries the clickjacking control; `'self'`-only default is what makes an XSS payload unable to exfiltrate to an arbitrary host |
| `X-Content-Type-Options` | `nosniff` | Stops a browser re-interpreting a served type; the standard defence for a JSON API |
| `Referrer-Policy` | `no-referrer` | Guest-identity URLs must not leak into a `Referer` on outbound navigation |
| `X-Frame-Options` | `DENY` | Legacy clickjacking control, retained alongside `frame-ancestors` for older agents |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` — **conditional** | **Only if HTTPS is guaranteed at the edge for every host and every response path, including error and redirect responses.** Emitting HSTS on a host that can still serve plaintext is a self-inflicted outage risk, so it is gated on that guarantee rather than recommended unconditionally |
| `Permissions-Policy` | `geolocation=(), camera=(), microphone=()` | This application requests none of these; denying them removes the prompt surface entirely |

*Legally required baseline: **none identified.*** No project document establishes a
legal requirement for any of these headers, and none is asserted here. A
`Content-Security-Policy` requirement in a Saudi regulatory context, if one exists, is
a `BLOCKED — LEGAL/COMPLIANCE` question this section does not answer.

*Row 7 — enforcement location.* The requirement is *"security headers on **all**
responses"* (`SEC-009`, `API-SPEC.md` §5). A per-endpoint or per-controller approach
cannot satisfy that: it misses framework-generated 404s, method-not-allowed 405s, and
unhandled-exception responses, and it silently depends on every future endpoint
remembering. Recommended enforcement architecture, at the Laravel application
boundary:

- a **global** middleware in the application's `withMiddleware` stack, so it wraps
  every matched route and every exception response the framework renders, including
  `DomainFailure` → `ErrorResponseFactory` and the unhandled-throwable path already
  wired in `bootstrap/app.php`; and
- headers applied on the **response object** after the response is generated, not only
  on the success path.

`X-Frame-Options`/`frame-ancestors` and `nosniff` must be set on error responses
too — a 500 that leaks a framed error page is still a clickjacking target. HSTS
belongs at the TLS-terminating edge (or in that middleware) *only* under the
guarantee noted above. **No such middleware was created in this run**; this is an
architecture proposal, and `AC-T-004-08` remains unresolved by design with
`test_no_security_header_is_invented` asserting nothing was invented.

#### 12.1.4 `B-05` — authentication abuse controls, and keying

**Rows 8, 9, 10 — values. `BLOCKED — BUSINESS`.** `B-05` records operating scale as
**`NOT CONFIRMED`**, owner PM + operations, inferred value **`NONE`**, holding open
per-property room counts, occupancy, staffing levels, peak concurrency, and rate
limits. A rate ceiling is a direct function of how many staff authenticate from how
many addresses at shift change. Engineering baselines are given so the decision is
cheap to make once the data arrives — **`BLOCKED` is not a reason to withhold one.**

> **Row 8 — max attempts and decay. Recommended baseline: 5 attempts per 300 s
> (5 minutes).**
>
> - *Credential stuffing addressed:* a per-account ceiling of 5 failures inside a
>   5-minute window means a spray of 100 leaked credentials against 20 accounts is
>   capped at 5 attempts per account per 5 minutes, turning a hours-long spray into a
>   multi-day one.
> - *False-positive risk:* a genuine user mistyping a password five times in five
>   minutes — plausible on a shared front-desk terminal with a keypad and a name
>   spelled three ways. 5/300 s is set at the tolerant end of defensible; 3 would lock
>   out legitimate users routinely.
> - *Operational tuning:* the window must exceed the time to type a wrong password
>   several times deliberately and stay under the time an attacker needs to complete a
>   parallel spray. Rate *per key*, not globally.
>
> **Row 9 — lockout threshold and duration. Recommended baseline: 5 consecutive
> failures triggers a 900 s (15 minute) lockout.**
>
> - *Brute-force addressed:* 5 wrong passwords followed by a 15-minute block makes
>   online brute force of a non-trivial password computationally infeasible while the
>   account is protected, at 4 blocks/hour ≈ 20 attempts/hour ≈ 480/day.
> - *False-positive risk:* the real cost is a staff member locked out of a live
>   property system at 02:00. **The lockout response must be paired with a
>   staff-side unlock path owned by a human**, and no such path exists because
>   `C-10` has no named owner. This is a genuine reason the value is `BLOCKED` and not
>   merely cautious: a lockout with no unlock procedure is an availability incident
>   generator against check-in.
> - *DoS risk:* a deliberate lockout is trivially triggerable by anyone who knows a
>   colleague's username. Mitigation is **not** to weaken the lockout but to keep the
>   response generic, to alert on repeated lockouts from one address, and to have a
>   human unlock path. Weak lockout = no control; no lockout = no control.
>
> **Row 10 — lockout keying for unconfirmed / nonexistent accounts. Recommended
> baseline: the nonexistent account is bucketed exactly as a real one is; it must NOT
> receive a separate, weaker bucket.**
>
> - Login must not reveal account existence — already asserted by
>   `test_every_refusal_carries_the_same_message`.
> - If nonexistent accounts were exempt from lockout, that exemption *is* the
>   enumeration oracle: an attacker distinguishes "no such user" from "wrong
>   password" purely by whether a lockout ever arrives. If they were locked
>   *harder* than real accounts, the same oracle appears inverted.
> - The rate limit must therefore apply **before** and **independently of** whether the
>   account exists. A nonexistent account must also cost the same in time — which the
>   current dummy-hash verification in `AuthenticationService` already does.
>
> **To unblock all three:** peak concurrent staff, staff count per property per shift,
> number of distinct shared/desk devices per property, expected properties per group
> (`D-001` fixes 10), and whether SSO/proxy authentication will terminate client
> addresses upstream — the last point determines whether the IP dimension is a real
> control or an office-NAT artefact.

**Row 11 — keying architecture. `PROPOSED — SECURITY`.**

The current implementation uses **one combined key**, `email + IP`, hashed:
`AuthenticationRateLimiter::key()` at line 129 is
`hash('sha256', Str::lower(trim($email)).'|'.($ipAddress ?? 'unknown'))`, and both the
rate-limit counter and the lockout counter are scoped to that single digest. Hashing
the key is correct and should be kept — it is right that the credential never reaches
the cache backend. **The defect is the combining, not the hashing.**

A single combined key gives the attacker a multiplication of the two limits rather
than the protection of both:

- **Credential stuffing:** N accounts × M addresses produces N×M distinct keys, so the
  effective ceiling is *N×M×5* attempts. The per-account control the ceiling is
  supposed to express does not exist.
- **Distributed / rotating-source attacks:** an attacker rotating source addresses
  against one account gets a **brand-new key on every request** and is never rate
  limited at all. This is the single most damaging property of the combined key, and
  it is exactly the technique a credential-stuffing list is sold for.
- **IP dimension is currently unenforceable as a control** for the same reason: no
  address is ever locked out on its own merits.
- **Nonexistent accounts** compound this — a spray of unknown usernames is throttled
  per (unknown-email, IP) pair, which is the weakest possible key for a spray.
- **Enumeration:** acceptable only while the key is uniform, which it is today.
- **DoS / false-positive trade-off:** a pure per-account limit lets an attacker lock
  any known colleague out at will; a pure per-IP limit punishes an entire property
  behind one NAT or a corporate proxy, which for a 10-property hotel group behind a
  single egress address would mean a shared lockout. **Independent dimensions are the
  answer to both**: enforce a per-account ceiling (slower, account-scoped lockout) and
  a *separate, higher* per-IP ceiling (catches rotation and spray) — neither inherited
  from the other, and the per-IP ceiling set with shared-egress in mind.
- **Account identifier:** key on the *submitted* identifier, not a resolved user ID,
  because nonexistent accounts have no ID and must still be counted. The submitted
  email is lowercased and trimmed, then hashed — never stored.

> **Recommended design:** independent account and IP rate-limit dimensions, **both**
> enforced, neither derived from the other, with the account dimension keyed on the
> normalised submitted identifier and the IP dimension on the client address, each
> hashed, and the IP threshold set above the per-account threshold. The nonexistent
> account case is handled by the account dimension alone, before any lookup.

**This is a design proposal. It was not implemented, and the existing
`AuthenticationRateLimiter` is untouched.** The present combined key remains live, and
because `B-05` values are unresolved the limiter fails closed in production anyway.

#### 12.1.5 `MFA`

**Row 12 — mechanism. `PROPOSED — SECURITY`. Recommended technical baseline: TOTP as
the primary mechanism, with passkey/WebAuthn preferred where the estate supports it.
SMS and email are not acceptable as the primary mechanism.**

| Mechanism | Assessment |
|---|---|
| **TOTP** (RFC 6238, e.g. RFC 6238 app / authenticator) | **Recommended primary.** Phishing-resistant enough for this threat model, no network dependency, works when the PMS is segmented from the internet, no per-message cost, and standards-based. Known residual: not cryptographically bound to the origin, so a sophisticated phishing proxy can relay a challenge — mitigated for the highest-value roles by preferring a passkey |
| **Passkey / WebAuthn** | **Preferred where available.** Origin-bound and therefore genuinely phishing-resistant; no shared secret on the server beyond a public key. Adoption is gated on staff device and browser estate, which is unresolved. Best treated as the target state with TOTP as the universally-available floor |
| **SMS** | **Not acceptable as primary.** Vulnerable to SIM swap, SS7 interception, and cost-based denial of service against the sender; requires a phone number for staff whose delivery is not guaranteed. Acceptable only as a documented break-glass recovery channel |
| **Email** | **Not acceptable as primary.** The channel is the same mailbox the credential reset path uses, so a mailbox compromise defeats both; it additionally offers no second factor in the sense that matters, since email is usually already a single authenticated session |

- **Deployment complexity:** TOTP enrolment requires a shared-secret store. `mfa_secrets`
  is already in the data model (`DATA-MODEL.md` §2) with a separate key boundary, so no
  schema change is implied. Passkeys require an RP ID decision and a relying-party
  origin, which does not exist yet because no public origin has been chosen.
- **Recovery requirements, stated plainly:** **MFA recovery is the weakest link and
  has no owner.** Recovery codes, out-of-band verification, and the staff-lockout
  unlock path all need a human decision-maker, and `C-10` has none. A second factor
  with an unreviewable recovery path is a control that a single social-engineering
  call can remove. This must be resolved together with row 22, not after it.
- **Operational assumption:** staff must possess a second factor. Front-desk and
  housekeeping staff turnover is high (`DEP-007` `TBD`).
- **Staff-device dependency — stated explicitly as an unresolved deployment
  dependency, not a blocker on the proposal:** whether staff can be issued and retain a
  personal device for TOTP enrolment, and whether managed/shared devices are permitted
  at all, is a deployment fact the project does not hold. It is recorded here as a
  dependency of the *deployment*, and does not make the mechanism proposal
  indefensible — the baseline above is stated conditionally on that fact.

**Row 13 — coverage. `PROPOSED — SECURITY`.** Derived from the existing role
authorisation model, not from a new ranking. No role is invented, added, or
re-purposed. The derivation is mechanical: **a role requires MFA if it can perform any
of the seven canonical step-up operations, or any operation that changes
authorisation, money, or guest identity data.**

From `ADR-0014` §5 (the 12 roles) and the §6.1 step-up set:

| Role | Can perform a step-up / security-sensitive operation? | MFA |
|---|---|---|
| **Group Manager** | Configuration, scope grants, business-date reopen, export, rate and policy approval, role administration | **Required** |
| **Hotel Manager** | Configuration, scope grants, business-date reopen, export, refund approval, out-of-order | **Required** |
| **Finance** | Refund, manual adjustment, reversal, credit/debit note, payment void — §6.1 #1 | **Required** |
| **Night Auditor** | Business-date reopen, night-audit step replay, `OUT_OF_ORDER` | **Required** |
| **Compliance Officer** | Audit trail review, identity-document reveal (§6.1 #7), tax configuration review, DLQ replay | **Required** |
| **Revenue Manager** | Configuration (rates, rate plans, restrictions), export | **Required** |
| **Auditor** | Read everything in scope including the audit trail and guest identity documents | **Required** |
| Front Desk Agent | Neither — `ADR-0014` §5 explicitly excludes refunds, configuration, night audit, scope administration | Not required |
| Reservation Agent | Neither — excludes check-in/out, refunds, configuration, financial posting | Not required |
| Housekeeping | Neither — §5 explicitly excludes guest identity data, folio, and financial data | Not required |
| POS Cashier | No Phase A permissions at all (deferred) | Not required |
| Support | Time-bound scoped diagnose, every access audited; no persistent grant | **Required** — a time-bound grant that reaches audited guest data is still a privileged path |

**Six plus Auditor plus Support = 8 of the 12 roles require MFA.** Note that
`Support` and `Auditor` are included on the *power* they hold, not on trust: §5 gives
`Support` "no grant without a named approver and an expiry", and that named approver
is an accountability question this project cannot currently answer.

`ADR-0016:23` already requires `MFA challenge and failure` to be audited. That
obligation is **formal today**; only the mechanism and the coverage set are open.

#### 12.1.6 Other rows

**Row 14 — raw IP retention, technical necessity. `DECIDED`.**

Recording the client address is already required, not proposed: `ADR-0016` §2 requires
every audit record to capture *where* and *source*, and §5 requires "integration
failures: timeouts, dead-letter entries, reconciliation mismatches" to be auditable.
Technically, raw IP supports credential-stuffing investigation (the address
dimension is the only signal that distinguishes one spray from many), incident
correlation across requests, and audit investigation. It also flows from `DR-011`'s
prohibition on identity data in logs: the address is a technical identifier, not
guest identity data, so retaining it does not breach `DR-011` — but that is a
*different* question from how long it is kept. **Recorded here to separate necessity
from period; row 15 is where the open question lives.**

**Row 15 — retention period. `BLOCKED — LEGAL/COMPLIANCE`.** The project has not
established the applicable legal or compliance retention requirement for raw IP in
immutable audit records. No ZATCA, PDPL, or other retention period is inferred here.
`B-01` (compliance exposure) and `C-09` are both open, and §12 above already records
this row as `needs C-09`.

**Row 16 — retention owner. `BLOCKED — ACCOUNTABILITY`.** No person is named anywhere
in this proposal and none is invented.

**Row 17 — key management. `BLOCKED — BUSINESS`.** `H-03` states key management is a
release gate, not hardening, and rotation/hierarchy/decryption authority need an
infrastructure and KMS decision the project has not made.

**Row 18 — vulnerability patch cadence and remediation SLAs. `PROPOSED — ENGINEERING`.**
Recommended baseline, as a *starting* policy: critical — 7 days; high — 30 days;
medium — 90 days; low — next scheduled release. `ADR-0002` records that Laravel's
security release cadence is predictable, which is what makes a time-boxed SLA
defensible. Trade-off: an SLA shorter than the deployment pipeline is unenforceable, so
this baseline is explicitly contingent on `T-030` IaC provisioning existing, and it
must be reconciled with `H-03` key rotation rather than tracked separately.

**Row 19 — penetration test scope and timing. `BLOCKED — BUSINESS`.** Scope depends on
what will be deployed and by whom, and `B-03` records no provider chosen.

**Row 20 — `SEC-013` webhook signature mechanism. `BLOCKED — BUSINESS`.** The
mechanism is `UNKNOWN` pending provider, and `B-02`/`B-04` are open. No provider is
guessed and no mechanism is proposed; a signature scheme cannot be chosen without
knowing what signs.

**Row 21 — `C-10` Security Owner, the role. `DECIDED`.** "Security Owner" is already
the repository's term: `docs/SECURITY.md:8`, `docs/PRD.md:11`, and `Prd_Maker.md:502`
all use it, and `DEPLOYMENT.md:237` uses `Security` as the owner of the security
review gate. The role is formally established and needs no decision. Its authority:
sign-off on this document and on `SEC-007`/`SEC-008` values; ownership of the security
review gate (`DEPLOYMENT.md:237`) and the vulnerability and key-management controls;
the decision on MFA mechanism, coverage, and recovery; and the decision on whether a
`BLOCKED` row in §12.1.1 is unblocked. **It is the role that converts any row in this
section from proposal to decision — which is precisely why its absence blocks all of
them.**

**Row 22 — `C-10` Security Owner, the person. `BLOCKED — ACCOUNTABILITY`.** `TBD` in
every document that carries the role. **No name, and no GitHub username, is proposed
here**: none appears in the repository, and inventing one would fabricate an
accountability assignment. This row cannot be closed by engineering.

#### 12.1.7 Step-up: the three recorded conflicts, classified

**Conflict A — the acceptance criterion points at the wrong document. Classified:
MIS-CITATION / CROSS-REFERENCE DEFECT.**

Verified this run: `ADR-0014` §6 is a **separation-of-duties / sensitive-operation
matrix** of 9 rows, and **exactly one of the nine — Refund — names step-up at all**.
The other eight name other additional controls (approver independence, approval
thresholds, reason codes, volume limits, permission checks). It is therefore not a
step-up catalogue and was never intended to be one.

The seven-operation set is established concordantly in three places:
`SECURITY.md` §6.1 (the normative table), `API-SPEC.md` §3.11.1 (the same seven, with
endpoint mappings), and `PRD.md` `SEC-018` (the same seven, by name, as a formal
security requirement). `AC-T-004-05` in `docs/TASKS.md:286` refers to "the sensitive
operation set in `ADR-0014` §6", which points at a document that cannot supply the
set.

**Finding:** `AC-T-004-05` should reference the **normative Step-up specification** —
`docs/SECURITY.md` §6.1, cross-referenced by `docs/API-SPEC.md` §3.11.1 and
`PRD.md` `SEC-018` — rather than treating `ADR-0014` §6 as the Step-up catalogue. This
is a defect in the *cross-reference*, not a dispute about which operations are
covered: the three concordant sources agree, and `ADR-0014` §6 agrees wherever it
speaks about step-up at all. `ADR-0014` is **not modified** by this finding, and
neither is `AC-T-004-05` in this run.

**Conflict B — a supersession claim with no target.** Already recorded above as
removed-not-resolved. It is a documentation-hygiene item with no security consequence
and needs no decision; nothing is claimed to supersede anything now.

**Conflict C — step-up required outside the canonical seven. Classified:
`PROPOSED — GOVERNANCE`.**

Verified this run: `docs/STATE-MACHINES.md` requires step-up for **three** operations
that are not on the list — reservation cancellation (`A-T16`, line 78), raising
`OUT_OF_ORDER` (line 155), and `FORCED_CLOSE` (line 492). (Its other step-up
references — refund at lines 297/351 and business-date reopen at line 384 — *are* on
the list, so they are concordant and raise no conflict.)

The normative hierarchy in §12.1 above **does** resolve this. `PRD.md:811` makes
`SECURITY.md` normative; `STATE-MACHINES.md` is subordinate to it and declares itself
*"Draft — specification only"* at line 7, with §0 stating its contents are
requirements for `TASKS.md` rather than descriptions of behaviour. A draft
specification does not add to a normative control set.

> **Recommended resolution: the seven-operation canonical Step-up set in
> `SECURITY.md` §6.1 governs the current `T-004` security implementation scope. The
> three `STATE-MACHINES.md` references are not currently registered or normative
> Step-up requirements, and the canonical set is not expanded.**

**If the PM later intends reservation cancellation, `OUT_OF_ORDER`, or `FORCED_CLOSE`
to require Step-up, the canonical security set must be formally expanded — in
`SECURITY.md` §6.1, `API-SPEC.md` §3.11.1, and `PRD.md` `SEC-018` — before any
implementation.** The set is **not** expanded automatically here, and this proposal
does not register that expansion.

**`DR-T004-09` — canonical seven. Substance: `DECIDED`. Formal registration:
`BLOCKED — GOVERNANCE`.**

- **Substance.** The seven operations are established by concordant normative
  documentation: `SECURITY.md` §6.1, `API-SPEC.md` §3.11.1, and `PRD.md` `SEC-018`
  (a formal security requirement naming the same seven by name). Verification this run
  confirms all three agree. The substance is therefore already established in
  authoritative documentation and is recorded as `DECIDED` **for traceability only** —
  this section decided nothing.
- **Registration.** `DR-T004-09` appears in exactly two places, both in
  `docs/SECURITY.md` (lines 122 and 159) and neither in the §15 register. It is an
  unregistered reference and carries no registered meaning. Making it a real Decision
  ID is a change to the authoritative register and is a **PM act**. No Decision ID is
  invented or implied here.

**`DR-T004-10` / `STEP_UP_PERFORMED`. Substance: `DECIDED`. Formal registration:
`BLOCKED — GOVERNANCE`.**

Verified this run, and the three-way position is *not* symmetric:

1. `PRD.md` `SEC-018` (formal requirement) states that privileged actions *"require
   step-up **and produce an audit event**"*. The audit obligation is **formal**.
2. `API-SPEC.md` §3.11.1 line 334 fixes the event name at
   `POST /api/v1/auth/step-up` → `STEP_UP_PERFORMED`, and `§2.1` reserves
   `STEP_UP_REQUIRED` (403). The **vocabulary is established**.
3. `ADR-0016:23`'s catalogue reads *"authentication success, failure, lockout,
   logout, MFA challenge and failure"* and does **not** list `STEP_UP_PERFORMED`.

The absence in `ADR-0016` does **not** cancel requirements 1 and 2. `ADR-0016` §5 is
explicitly a **minimum** catalogue *"from `Prd_Maker.md` §32"*, and `SEC-018` is a
formal `PRD.md` security requirement; a minimum list is not an exhaustive one. Further,
`ADR-0016:25` already requires *"privileged access: impersonation attempt, support
access, export, bulk operation"* to be audited, so the privileged-access category is
covered even though this one event name is not spelled out.

**So:** the requirement that a step-up produces an audit event, and the name
`STEP_UP_PERFORMED`, are established in substance by existing authoritative
requirements — recorded as `DECIDED` for traceability. Whether `ADR-0016`'s catalogue
should be *amended* to name it explicitly, and whether `DR-T004-10` should be
registered in §15, are both **PM governance acts** and are **not** performed here. No
code, no audit action, and no `MFA_*` action was added.

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
