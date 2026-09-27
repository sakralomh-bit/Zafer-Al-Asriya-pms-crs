# ADR-0018: Night Audit as a Resumable, Idempotent Batch

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-002`, `C-05`, `ADR-0008`, `ADR-0009`, `docs/STATE-MACHINES.md` §G

## Context

`Prd_Maker.md` §52 requires night audit to define: business date, open folios, unposted charges, no-shows, late checkouts, revenue posting, taxes, payments, cashier closure, reconciliation, retry/resume, manual review, and manual handling.

Night audit is the most dangerous routine in a PMS. It is a batch job that, on every single day, moves money, posts revenue, closes periods, and generates invoices — for **10 properties simultaneously**. Its worst failure mode is not that it fails loudly. It is that it **partially completes and is then re-run**, and the re-run posts the same revenue twice, or issues the same tax invoice twice, or closes a business date that had a legitimate late arrival added to it.

For a system issuing ZATCA-compliant invoices, duplicate invoice issuance is not merely an accounting error — it is a **regulatory** one, because invoice numbering must be gapless and controlled.

The second failure mode is a partially completed run leaving a business date in an **ambiguous** state: some folios closed, some not, revenue posted for some properties and not others, with no record of where it stopped. Manual recovery from that state, without a program, is where real money goes missing.

## Decision

1. **Night audit is a batch job per property, per business date.** Never global. One property's night audit failing must not block or corrupt another's.
2. **Single-run enforcement.** At most one night audit run may be active for a given property + business date. Enforced by a database-level guarantee, not a scheduler assumption.
3. **Resumable, not restartable.** A run that is interrupted resumes from the last completed step. It does not begin again. Every step is independently idempotent, so resuming a step that had actually completed is harmless.
4. **Every step is idempotent by construction.** Re-running a completed step MUST NOT double-post a charge, double-post revenue, double-generate a tax invoice, or advance a number sequence twice. Idempotency is enforced by the step's own logic and guarded by unique constraints where a sequence is involved.
5. **Fail-stop with an explicit state.** A step failure moves the run to a named failed state and **stops**. It does not continue to the next step. The business date is left in a state the system can describe exactly, and manual intervention is required to resume or to intervene.
6. **No partially-visible business date.** The run state records exactly which steps completed, with timestamps and actor. A support engineer must be able to answer "how far did it get" without reading application logs.
7. **Business date and calendar date are distinct.** Every posting carries both. Night audit operates on the business date; it never rewrites a calendar timestamp.
8. **Concurrency with live operations.** Night audit must not race an in-flight allocation, check-in, or payment for the same property. The per-property-per-business-date lock covers the run itself; ordering against live operations is a documented precondition of each step.
9. **Manual intervention is a first-class path**, not an emergency improvisation. A manual override, a step replay, a business-date reopen — each is a distinct authorized action with its own audit event and its own permission requirement.
10. **Reopening a closed business date** is a distinct, separately authorized operation with a distinct state transition, never an implicit consequence of re-running the job. Reopening must define what may be re-posted and what may not. **Policy `TBD` — see below.**

### Run state machine

`NOT_STARTED → RUNNING → COMPLETED` (normal)
`RUNNING → PAUSED → RUNNING` (operator pause, resumable)
`RUNNING → FAILED` (step failure; manual intervention required)
`COMPLETED → REOPENED → RUNNING` (authorized reopen, **policy `TBD`**)

Terminal for a run: `COMPLETED`, `FAILED`. Terminal for a business date: `CLOSED`. `REOPENED` is **not** terminal — it exists so that reopening is visible and audited rather than silent.

### Steps

Order is a design decision; final order depends on the unresolved business-date policy. Candidate order: close remaining open folios and post unposted charges → determine no-shows → resolve late checkouts → post revenue → compute and post taxes → settle payments and close cashier shifts → generate and submit invoices via the outbox → reconcile → mark business date `CLOSED`.

**Every step's placement is `TBD` until the business-date and cut-off policy is decided** (`C-05`).

## Criteria Applied

Correctness (double-posting and double-invoicing are unacceptable), reversibility (an ambiguous business date is the worst possible state and must be impossible by design), operational simplicity (it runs unattended, nightly, for 10 properties), compliance (invoice issuance and numbering integrity), testability (each step idempotency is directly testable), cost.

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Re-runnable from scratch (restart) | **Rejected** | Re-running completed steps risks duplicate postings and duplicate invoices. Resumability exists precisely to avoid this. |
| Real-time posting with no night audit | Rejected | Hotels require a period-closed model; a business date must be closable and its figures frozen for reporting and compliance. |
| Manual / operator-driven night audit (click-through wizard) | Rejected as the only path | Not automatable and not repeatable at 10 properties. Acceptable only as a manual-intervention path layered on an automated run. |
| One global night audit run for all properties | Rejected | A single property's failure would block or corrupt the others. Ten properties have different cut-off times, occupancy, and staffing reality. |
| Continue past a failed step | **Rejected** | Produces a partially-posted business date that is impossible to reason about. Fail-stop with an explicit state is the only recoverable design. |
| Event-sourced night audit | Deferred | A heavier model than the problem requires; an append-only ledger plus a resumable step ledger achieves the needed guarantee. |
| Automated silent reopen on failure | **Rejected** | Reopening a closed period must be a deliberate, authorized, audited act. |

## Consequences

- Night audit is the most heavily tested part of the system. Step-level idempotency, resume-from-failure, fail-stop behaviour, and single-run enforcement are all mandatory test cases.
- A failed run requires an operator. The runbook is a release gate, and the manual-intervention path is designed up front rather than improvised during an incident.
- Business date and cut-off policy are on the critical path. The state machine and the step list are fully specified, but the values that make them meaningful are `TBD`.
- The run record is itself a compliance artefact and is retained and audited.

## Unresolved — `TBD`, not assumed

| Item | Status | Why it matters |
|---|---|---|
| Business date definition and cut-off time | `TBD` (`C-05`) | Determines which charges belong to which period. **A 14:00 checkout convention is NOT assumed.** |
| Same-day arrival handling | `TBD` (`C-05`) | Determines whether an arrival charges to the current or previous business date. |
| Late-checkout policy | `TBD` (`C-05`) | Affects revenue posting and folio closure. |
| Reopen policy: what may and may not be re-posted | `TBD` (`C-05`) | Governs the `COMPLETED → REOPENED` transition. |
| Who may authorize a reopen | `TBD` | Maps to a role in `ADR-0014`; likely Night Auditor with Finance approval. |

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Duplicate invoice issuance on re-run | Medium | **Critical (regulatory)** | Step idempotency + controlled gapless numbering + explicit resume-not-restart |
| Business date left ambiguous after a failure | Medium | **Critical** | Fail-stop with a recorded step ledger; business date is never `CLOSED` unless every step completed |
| Two runs for the same property and date | Low | High | Database-level single-run guarantee, not a scheduler assumption |
| Run races a live check-in or allocation | Medium | High | Documented precondition per step; lock scope per property + business date |
| Night audit overruns into the next business day | Medium | Medium | Step monitoring and alerting; no unbounded steps |
| The whole group depends on one night's run | Medium | High | Per-property isolation; a failed property does not block the other nine |

## Reversibility

**High for the state machine, low for the posted data.** The run state machine can be extended. But posted financial records and issued invoices are immutable by design (`ADR-0009`), so a night-audit defect discovered after a production run is corrected with compensating entries and, for invoices, with the appropriate credit/debit note — not by editing history. This is the strongest argument for treating step idempotency as a release gate rather than a test.

## References

`Prd_Maker.md` §52 (Night Audit), §18 (State Machine Standard), §28 (Financial Ledger and Folio), §29 (Compliance), §33 (Retry), §58 (Business date vs calendar date), §38 (Rollback), `C-05`, `ADR-0008`, `ADR-0009`, `ADR-0010`, `docs/STATE-MACHINES.md` §G, `docs/DATA-MODEL.md` §4, `docs/TEST-STRATEGY.md` §7.
