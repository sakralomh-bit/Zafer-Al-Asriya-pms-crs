# ADR-0021: Testing Strategy and Concurrency Verification

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-006`, `ADR-0008`, `ADR-0018`, `B-05`, `docs/TEST-STRATEGY.md`

## Context

`D-006` carries a standing rule:

> MySQL row locking alone does not guarantee prevention of double booking.

This is the most important sentence in the architecture, and it has a testing consequence that is easy to state and easy to quietly skip. Locking *looks* correct in code review. A `SELECT ... FOR UPDATE` inside a transaction is the textbook answer, and reading it satisfies the reviewer. What it does not do is **prove** that under real concurrency — real isolation configuration, real commit timing, real deadlock behaviour, real connection pooling — exactly one of two competing allocations wins.

Only a test that actually creates the race can supply that proof. Therefore:

> **Concurrency verification is a Phase A release gate, not an optional test suite.**

`Prd_Maker.md` §40 requires coverage of functional, state-machine, integration, security, privacy, performance, recovery, and accessibility concerns, and §63 requires every performance target to have a defined scenario. `Prd_Maker.md` §43 (Definition of Done) requires automated tests to be complete and acceptance criteria to pass before a feature is Done.

## Decision

### 1. Test layers

| Layer | Purpose | Runs in CI |
|---|---|---|
| Unit | Pure domain logic: money arithmetic, tax computation, rounding, state-transition predicates, date/business-date logic | Every push |
| State machine | Every valid transition, **every invalid transition**, terminal states, repeated transitions | Every push |
| Contract | Module-to-module contracts; adapter interfaces against fakes | Every push |
| Integration | Real database and real queue, fakes for external systems | Every push |
| **Concurrency** | Real database, real transactions, real contention | Every push — **gate** |
| Security | Authorization matrix, property breakout, privilege escalation, injection, session abuse, rate limiting, data leakage | Every push |
| Privacy | Masking, access logging, retention/purge, export, and the prohibition on identity data in logs | Every push |
| Recovery | Backup restore, replay, reconciliation, DLQ handling | Before release; periodic in production |
| Performance | Scenario-based, per `Prd_Maker.md` §63 | Before release |
| Accessibility | Keyboard, focus, contrast, forms, **and direction (RTL/LTR)** | Every push |
| UAT | Business acceptance with real hotel staff | Before pilot |

### 2. The mandatory concurrency suite

Each case below is a **release gate**. Each must be repeatable and must fail deterministically when the control is removed.

| ID | Scenario | Assertion |
|---|---|---|
| CON-01 | N concurrent valid booking attempts for the **last** sellable unit | Exactly one reaches `CONFIRMED`; N−1 receive `INVENTORY_UNAVAILABLE`; zero duplicate confirmed allocations; all attempts traceable by correlation ID |
| CON-02 | Same request replayed with the same `Idempotency-Key` concurrently | Exactly one allocation created; all callers receive the original result |
| CON-03 | Two concurrent check-ins for the same physical room | At most one succeeds; the other receives a deterministic denial |
| CON-04 | Concurrent payment submissions for the same folio with the same key | Exactly one payment record; no double charge |
| CON-05 | Two concurrent night audit runs for the same property + business date | At most one run proceeds; single-run guarantee holds |
| CON-06 | Allocation racing night audit for the same property | No allocation to a closed business date; no corruption |
| CON-07 | Concurrent folio postings and a refund on the same folio | Balance remains consistent; derived balance never drifts from postings |
| CON-08 | Expired hold racing a new allocation attempt | Expired hold never resurrects; the unit is allocatable exactly once |
| CON-09 | Concurrent configuration change during allocation | Allocation uses a coherent configuration version |
| CON-10 | Outbox dispatch racing a transaction commit | No event is dispatched for an uncommitted write; no lost event |

**The removal test.** For each case, temporarily removing the intended control (the row lock, the unique constraint, the idempotency check) MUST cause the test to fail. A concurrency test that passes with its control removed is not evidence of anything and must be treated as a defect. This is the single most important property of this suite.

### 3. State machine testing

Generated from the state machine specifications in `docs/STATE-MACHINES.md`, not hand-maintained, so that a new state cannot be added without its transitions being covered.

- Every **valid** transition is exercised and asserted to reach the target state with the specified side effects.
- Every **invalid** transition returns its **documented deterministic error code**. An invalid transition that returns a generic validation error is a defect.
- Terminal states reject all further transitions.
- Repeated application of the same transition is safe (idempotent where specified, rejected where not — the specification must say which, per transition).
- Concurrent application of competing transitions has defined conflict behaviour, and that behaviour is tested.

### 4. Integration failure testing

Against fakes and simulated failures, never against a real provider sandbox as a correctness gate:

success · timeout · rate limit (HTTP 429) · malformed response · provider outage · duplicate callback · out-of-order callback · signature verification failure · replayed callback · partial success · unknown outcome.

**The unknown-outcome payment case is mandatory.** A payment whose result the provider did not confirm must go to reconciliation, never to automatic retry. `Prd_Maker.md` §33: *"Never retry blindly after an unknown payment or financial outcome."*

### 5. Security testing

Authentication and session handling · authorization matrix per role (`ADR-0014`) · **property breakout** (a caller requesting a property they are not granted — must be denied, not silently empty) · privilege escalation · injection (SQL, command, template, stored) · CSRF where applicable · rate limiting and abuse · **sensitive data leakage** (error bodies, logs, telemetry, exports) · export abuse and volume limits · webhook forgery and replay · impersonation (default DENY) · file upload (currently `NOT_APPLICABLE` in Phase A; the test is written if that changes).

### 6. Privacy testing

Masking of document numbers in default views · authorized reveal · access-log emission on reveal · retention and purge execution · guest-data deletion versus financial-record retention conflict · **identity data never appearing in application logs, analytics, error reports, or telemetry** — asserted by an automated scan of captured log output.

### 7. Performance testing

`Prd_Maker.md` §63 requires every performance target to define: scenario, population, concurrent users, request mix, dataset size, network assumption, warm/cold cache, duration, measurement tool, percentile, pass threshold, failure threshold.

**A synthetic data generator covering 10 properties with realistic occupancy is a prerequisite**, not an optional extra — without a dataset of a stated size, no performance result is interpretable and no regression is detectable.

**Thresholds are currently `TBD`, blocked on `B-05`** (unknown room counts, occupancy, staffing, peak concurrency). A latency target set without those inputs would be a fabricated number. The scenario template is defined; the values are pending.

### 8. Mandatory non-claims

- No coverage percentage, latency figure, throughput number, concurrency figure, or pass rate is reported anywhere until actually measured.
- There is currently **no application code and nothing has been measured**. This repository is documentation-only.
- A lab benchmark is never presented as a real-user metric (`Prd_Maker.md` §63).

### 9. Data policy

Synthetic data only, in every non-production environment (`D-004`, `D-007`). The synthetic generator must produce *plausible* guests — including names and document numbers that are obviously synthetic — because a test that never sees realistic-shaped data fails to surface encoding, collation, and normalization defects. Real guest personal data must never be requested, copied, or used in a test.

### 10. CI gate

Every push runs: static analysis · lint · unit · state machine · contract · integration · **concurrency** · security · privacy · accessibility · dependency scanning · secret scanning. A red concurrency suite blocks the merge, not just the release.

## Criteria Applied

Correctness (this is the only evidence for the double-booking guarantee), testability, security, compliance, operational simplicity, reversibility, cost (fakes rather than paid sandbox dependencies in the correctness gate).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Row locking plus code review, no concurrency test | **Rejected** | This is exactly the failure `D-006` warns about. Code review cannot observe race behaviour; only execution can. |
| Concurrency tests as a nightly job rather than per-push | Rejected | A race that is introduced and merged should fail at the merge, not the next night. |
| Load testing against a real payment sandbox | Rejected for the correctness gate | Network latency and provider availability would dominate the result and make it non-deterministic. Fakes for correctness; sandbox for certification. |
| Manual QA double-booking attempt | Rejected as evidence | Manual attempts cannot reliably produce the interleaving, and cannot be repeated. Useful as a supplementary check, not as gate evidence. |
| Property-based / fuzz testing as the primary approach | Deferred | Valuable addition, but the state machine and concurrency gates are the mandatory core and are specified explicitly first. |
| Testing against production data copies | **Rejected** | `D-007` forbids real guest personal data outside production. |

## Consequences

- The test suite, especially the concurrency suite, is a build cost that must be budgeted and maintained as the product grows. This is accepted deliberately: it is cheaper than a double booking with a real guest.
- The state machine tests are generated from the specification, so the specification and the tests cannot diverge.
- Performance thresholds remain unset until `B-05` is answered, which means the performance release gate cannot currently be evaluated.
- No test can demonstrate regulatory or PCI compliance. Tests demonstrate **control behaviour**. Compliance is an organizational and legal determination, not a test result.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A concurrency test is flaky and gets disabled | High | **Critical** | No flaky-test tolerance on the concurrency suite; investigate root cause; the removal test proves the test has teeth |
| A test passes with its control removed | Medium | **Critical** | The removal test is mandatory per case, run and observed before the gate is trusted |
| External fakes drift from real provider behavior | High | High | Contract tests against the provider sandbox before certification; fakes are explicitly not certification |
| Coverage is mistaken for correctness | High | High | Statement of intent; gates are behavioural assertions, not a coverage number |
| Performance thresholds are set without real scale | Medium | High | Blocked on `B-05`; no target is published before then |
| Test data drifts toward real personal data | Low | High | Synthetic-only policy enforced in review and by secret/PII scanning |

## Reversibility

**High.** The strategy is conventional and the tools are not yet chosen. The genuinely irreversible element is the **set of state machine specifications**, because the generated tests are derived from them — so the specifications are reviewed and treated as the source of truth, not as a testing convenience.

## References

`D-004`, `D-006`, `D-007`, `B-05`, `ADR-0005`, `ADR-0008`, `ADR-0011`, `ADR-0014`, `ADR-0016`, `ADR-0018`, `Prd_Maker.md` §26, §33, §34, §40, §41, §43, §54, §55, §63, `docs/STATE-MACHINES.md`, `docs/TEST-STRATEGY.md`.
