# Zafer Al-Asriya v1.0 — Test Strategy

| Field | Value |
|---|---|
| Document | `docs/TEST-STRATEGY.md` |
| Version | 0.1 |
| Status | Draft — strategy. **No tests exist. Nothing has been measured.** |
| QA Owner | **`TBD` (`C-10`)** |
| Related | `ADR-0021`, `Prd_Maker.md` §40, §41, §43, §63 |

---

## 1. The central problem

`D-006` states:

> MySQL row locking alone does not guarantee prevention of double booking.

That sentence is easy to write and easy to forget once code exists, because a `SELECT ... FOR UPDATE` inside a transaction is the textbook answer and it satisfies a reviewer. It is also unproven until something executes the race.

**Therefore: concurrency verification is a Phase A release gate, not an optional suite.** It is the only evidence for the system's highest-severity requirement, and the only thing that distinguishes a guarantee from a hope.

**No coverage percentage, latency figure, throughput number, or pass rate is reported in this document, because nothing has been measured.** This repository is documentation-only.

## 2. Test layers

| Layer | Purpose | Runs in CI | Gate |
|---|---|:-:|:-:|
| Unit | Money arithmetic, tax computation, rounding, state predicates, business-date logic | Every push | Yes |
| State machine | Every valid transition, **every invalid transition**, terminal states, repeated transitions | Every push | Yes |
| Contract | Module-to-module contracts; adapter interfaces against fakes | Every push | Yes |
| Integration | Real database, real queue, **faked** external systems | Every push | Yes |
| **Concurrency** | Real database, real transactions, real contention | Every push | **Yes — release gate** |
| Security | Authorization matrix, property breakout, escalation, injection, session abuse, rate limiting, data leakage | Every push | Yes |
| Privacy | Masking, access logging, retention/purge, export, **no identity data in logs** | Every push | Yes |
| Accessibility | Keyboard, focus, contrast, forms, **direction (RTL/LTR)** | Every push | Yes |
| Recovery | Backup restore, replay, reconciliation, DLQ handling | Before release; periodically in production | Yes |
| Performance | Scenario-based per `Prd_Maker.md` §63 | Before release | Yes |
| UAT | Business acceptance with real hotel staff | Before pilot | Yes |

## 3. Concurrency suite — the release gate

| ID | Scenario | Assertion |
|---|---|---|
| `CON-01` | N concurrent valid booking attempts for the **last** sellable unit | Exactly one `CONFIRMED`; N−1 `INVENTORY_UNAVAILABLE`; zero duplicate allocations; all attempts traceable by correlation ID |
| `CON-02` | Same request replayed concurrently with the same `Idempotency-Key` | Exactly one allocation; all callers get the original result |
| `CON-03` | Two concurrent check-ins for the same physical room | At most one succeeds; the other gets a deterministic denial |
| `CON-04` | Concurrent payment submissions, same key, same folio | Exactly one payment record; no double charge |
| `CON-05` | Two concurrent night audit runs, same property + business date | At most one proceeds |
| `CON-06` | Allocation racing night audit for the same property | No allocation to a closed business date; no corruption |
| `CON-07` | Concurrent folio postings and a refund on one folio | Balance stays consistent; derived balance never drifts |
| `CON-08` | Expired hold racing a new allocation | Expired hold never resurrects; unit allocatable exactly once |
| `CON-09` | Concurrent configuration change during allocation | Allocation uses a coherent configuration version |
| `CON-10` | Outbox dispatch racing a transaction commit | No event for an uncommitted write; no lost event |

### 3.1 The removal test — the property that makes the suite meaningful

For each case, **temporarily removing the intended control (the row lock, the unique constraint, the idempotency check) MUST cause the test to fail**, and this must be run and observed before the gate is trusted.

A concurrency test that passes with its control removed is evidence of nothing and is treated as a **defect in the test**, not a passing result. This is the single most important property of the suite, and it is the difference between testing a guarantee and performing a ritual.

### 3.2 Flake policy

**No flaky-test tolerance on the concurrency suite.** A flake is a root-cause investigation, not a retry. A suite that gets retried until green has stopped being a gate, and the failure it is most likely to hide is exactly the one that matters.

## 4. State machine testing

Generated from `docs/STATE-MACHINES.md`, not hand-maintained, so a new state cannot be added without its transitions being covered.

| Case | Assertion |
|---|---|
| Valid transition | Reaches the target state with the specified side effects and audit event |
| **Invalid transition** | Returns its **documented deterministic error code** — a generic validation error is a defect |
| Terminal state | Rejects every further transition |
| Repeated transition | Behaviour matches the specification (idempotent where specified, rejected where not) |
| Concurrent competing transitions | Behaviour matches the specified conflict rule |

Machines covered: Reservation (§A), Room (§B), Inventory/Allocation (§C), Payment (§D), Refund (§E), Folio (§F), Night Audit (§G), External Integration (§H), and §J.1–J.6. **Channel synchronization (§I) is deferred to Phase C and is not tested, because it is not designed.**

## 5. Integration failure testing

Against fakes and simulated failures — **never** against a live provider sandbox as a correctness gate, because network latency and provider availability would dominate the result and make it non-deterministic.

| Case | Expected behaviour |
|---|---|
| Success | Submission recorded with provider reference and correlation ID |
| Timeout | `UNKNOWN_OUTCOME` / retry per the machine; **no blind retry for money** |
| Rate limit (429) | Bounded retry with backoff and jitter |
| Malformed response | Recorded as a permanent failure; not retried indefinitely |
| Provider outage | **Core PMS remains fully available** (`BUS-008`); work queues; dead-letters |
| Duplicate callback | Deduplicated; no second effect |
| Out-of-order callback | Rejected and routed to reconciliation; **not applied out of order** |
| Signature verification failure | Rejected; audited; not retried |
| Replayed callback | Rejected by replay protection |
| Partial success | Explicit state; no silent completion |
| **Unknown payment outcome** | `UNKNOWN_OUTCOME`; `retryable: false`; **zero provider retries**; reconciliation task created |

## 6. Security and privacy testing

| Case | Assertion |
|---|---|
| Authentication and session handling | Login, logout, idle timeout, revocation on role change |
| Authorization matrix | All 12 roles × all resources × all scopes |
| **Property breakout** | For every role, a user without a grant for property B receives `PROPERTY_SCOPE_DENIED` — across **all 10 properties**, not a sample |
| Privilege escalation | No path from a lower role to a higher one; no self-grant of scope |
| Injection | SQL, command, template, stored |
| CSRF | Rejected on cookie-authenticated state-changing requests |
| Rate limiting and abuse | Thresholds enforced; availability search and export limited |
| **Sensitive data leakage** | Error bodies, logs, telemetry, and exports contain no stack trace, SQL, hostname, document number, or card data |
| **Identity data in logs** | **Automated scan of captured log output asserts no document number appears** |
| Export abuse | Role-gated, volume-limited, audited |
| Webhook forgery and replay | Unsigned, tampered, and replayed payloads rejected |
| Impersonation denial | No endpoint exists; attempts are denied and audited |
| Privacy: masking | Document numbers masked by default |
| Privacy: reveal | Authorized reveal works; **every reveal is audited; the value is not logged** |
| Privacy: retention and purge | Purge executes per policy; never touches financial records or audit events |
| Card data | No column exists; no card data in any store or log |

## 7. Performance testing

`Prd_Maker.md` §63 requires every performance target to define a scenario. The template:

```text
Scenario:            TBD (endpoint class)
Population:          TBD (B-05)
Concurrent Users:    TBD (B-05)
Request Mix:         TBD (B-05)
Dataset Size:        TBD (B-05) — requires the synthetic generator
Network Assumption:  TBD
Warm/Cold Cache:     TBD
Duration:            TBD
Measurement Tool:    TBD
Percentile:          p50 / p95 / p99
Pass Threshold:      TBD (B-05)
Failure Threshold:   TBD (B-05)
```

### 7.1 The synthetic data generator is a prerequisite

A generator producing **10 properties** with realistic room counts, occupancy patterns, and reservation history is required before any performance result is interpretable and before any regression is detectable. A performance test against a toy dataset measures the database's ability to return rows from an empty table.

Without `B-05` (real room counts, occupancy, staffing, peak concurrency), the thresholds above **cannot be filled in honestly**, and this document leaves them `TBD` rather than inventing numbers.

### 7.2 Non-comparison rule

A lab benchmark is never presented as a real-user metric (`Prd_Maker.md` §63). No such comparison has been made here.

## 8. Recovery testing

Backup restore from a clean environment · restore validation cadence compliance · failover or standby procedure rehearsal (blocked by `B-03`) · outbox replay after a crash · dead-letter replay · reconciliation after a simulated provider outage · compensation after a partially failed night audit.

## 9. Accessibility and localization testing

Keyboard navigation · focus management · contrast · form labelling · **screen reader where required (level `TBD` — `C-08`)** · **direction matrix: every UI flow tested in both RTL and LTR** · Arabic/English string completeness (CI fails on a missing key) · number/date/currency formatting per locale · **mixed-direction content (Arabic label containing a Latin identifier) — a correctness concern, not cosmetic** · pluralization per locale rules.

## 10. Test data policy

**Synthetic only**, in every non-production environment (`D-004`, `D-007`, `PRI-008`). Real guest personal data must never be requested, copied, or used in a test.

The generator must produce plausible-shaped but obviously synthetic identities. A test corpus of `"Test Test"` and `"aaa@aaa.com"` will not surface encoding, collation, normalization, or length defects — and those defects appear on real Arabic and mixed-script data at 2 a.m. during check-in.

## 11. CI gate

Every push:

```text
Static analysis · lint · unit · state machine · contract
· integration (fakes) · CONCURRENCY · security · privacy
· accessibility · dependency scanning · secret scanning
```

A red concurrency suite **blocks the merge**, not merely the release. A red secret scan blocks the merge absolutely.

## 12. UAT

Performed with real hotel staff from the 10 properties, against realistic scenarios: peak arrival morning · a full house with one remaining room · a group arrival · a walk-in with a payment decline · a night audit interruption · a refund with an approval threshold. UAT is a **release gate** and requires a named QA owner (`C-10`).

## 13. Coverage of the requirements that matter

| Requirement | Evidence type |
|---|---|
| `BUS-001` no double booking | `CON-01`, `CON-02`, `CON-08` + removal test |
| `BUS-002` idempotency | `CON-02`, `CON-04` |
| `BUS-003` no blind retry on unknown outcome | Integration failure suite |
| `BUS-004` append-only ledger | Ledger invariant test; no update path |
| `BUS-005` derived balance | `CON-07` |
| `BUS-006` exact decimal | Static float check + rounding tests |
| `BUS-007` server-side state validation | State machine matrix |
| `BUS-008` integration isolation | Provider-outage test |
| `BUS-009` property isolation | Breakout test, all roles, all 10 properties |
| `BUS-010` compliance/business separation | `AC-FR-008-01` |
| `BUS-011` gapless numbering | Fault injection at every issuance point |
| `BUS-012` identity minimization | Masking, log scan, no image column |
| `BUS-013` card exclusion | No card column; log scan |
| `BUS-014` audit immutability | Append-only assertion; redaction |
| `BUS-015` resumable night audit | `CON-05`, `CON-06`, interrupt-at-every-step |
| `BUS-016` no unverified claim | Document review gate |

## 14. Unresolved

| Item | Blocker |
|---|---|
| Latency, throughput, and concurrency thresholds | `B-05` |
| Realistic dataset size | `B-05` |
| Accessibility level | `C-08` |
| Rate limit values | `B-05` |
| Provider sandbox for certification | `B-04` |
| Cloud environment for recovery testing | `B-03` |
| RPO/RTO-driven recovery test criteria | `C-01` |
| **Named QA owner** | **`C-10`** |

## 15. Statement

**This strategy has never been executed.** No test exists, no suite has run, and no result — pass, fail, coverage, or latency — is reported anywhere in this document set. The plan is complete; the evidence does not yet exist. `ADR-0021` and the release gate in `docs/DEPLOYMENT.md` exist to close that gap before anything is called production-ready.
