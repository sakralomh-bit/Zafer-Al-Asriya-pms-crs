# ADR-0016: Audit Trail and Immutability

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-001`, `D-004`, `ADR-0009`, `ADR-0012`, `ADR-0014`, `docs/SECURITY.md` §5

## Context

`Prd_Maker.md` §32 requires an audit trail for authentication events, authorization changes, privileged access, financial transactions, refunds, booking changes, sensitive data access, exports, configuration changes, compliance submissions, external integration failures, and manual overrides. It states plainly: *"Do not allow ordinary users to silently alter audit history."*

A hotel PMS holds the records a dispute is fought over. Who voided a charge. Who changed a rate at 23:00. Who revealed a guest's document number. Who exported a guest list. If an administrator can quietly edit that history, the system cannot support any dispute, complaint, or authority inspection, and its audit trail is worthless precisely when it is needed.

There is also a privacy tension: the audit trail records who accessed sensitive identity data, which means the audit trail itself is sensitive.

## Decision

1. **Append-only.** Audit records are created and never updated or deleted through the application. There is no application code path that issues an `UPDATE` or `DELETE` against an audit table. Retention purge, when it eventually happens, is a separately authorized, separately audited, purpose-built job — not a general delete.
2. **Record shape.** Every audit record captures: **who, what, when, where (organization/property), before, after, reason, correlation ID, source, result** — matching `Prd_Maker.md` §32. `before`/`after` are captured for state changes; for high-sensitivity events the payload is redacted rather than stored raw.
3. **Separation of duties.** The identity that performs an action cannot alter the record of that action. There is no superuser bypass on the audit path. Support access to audit data is an **audited, explicitly scoped** privilege, and "Support" is a scoped role in `D-001`, not an implicit admin.
4. **Identity-data access is itself an audited event.** Every reveal of a masked document number produces an audit record (`ADR-0012`). Reading a masked value by default does not; unmasking does.
5. **Auditable event catalogue** (minimum, from `Prd_Maker.md` §32):
   - authentication success, failure, lockout, logout, MFA challenge and failure
   - authorization change: role assignment, property-scope grant and revocation
   - privileged access: impersonation attempt, support access, export, bulk operation
   - financial: every posting, every payment, every refund, every adjustment, every reversal, every write-off
   - booking: creation, modification, cancellation, no-show, room transfer, rate change
   - sensitive data access: document-number reveal, sensitive-field export
   - configuration: tax rates, cancellation policy, room inventory, operating parameters, notification templates, integration credentials
   - compliance: invoice submission attempt, success, failure, dead-letter, manual replay
   - integration failures: timeouts, dead-letter entries, reconciliation mismatches
   - manual overrides and night-audit interventions
6. **Not guest-facing.** Audit records are never included in a guest-facing export, a booking confirmation, or a notification.
7. **Correlation.** Every audit record carries the same correlation ID as the request and the business operation, so an action is reconstructable end to end across the API, the queue, and the external call.
8. **Redaction.** Document numbers, card data, and secrets must never appear in audit payloads, even in `before`/`after`. The audit trail records *that* a sensitive field was revealed, and by whom — not the value.
9. **Relationship to the ledger.** The audit trail is broader than the financial ledger: it records attempts, denials, and configuration changes, not just financial state. The immutable ledger (`ADR-0009`) is the authoritative record of *financial* fact. The audit trail is the authoritative record of *who did what and when*. Neither substitutes for the other, and a financial correction is a compensating ledger entry **plus** an audit record, never an audit record instead of a ledger entry.

## Criteria Applied

Correctness (the audit trail is the dispute evidence), compliance and legal defensibility, security (non-repudiation is the `R` in STRIDE), least privilege, privacy (the audit trail is itself access-controlled), testability (append-only is mechanically testable), reversibility (append-only is the harder-to-reverse choice, taken deliberately).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Mutable audit table with a "last modified" column | Rejected | A row that can change is not evidence. This defeats the purpose of the record. |
| Database audit / binlog as the audit trail | Rejected as primary | It records *that* a statement ran, not *who* did it or *why*, and it is not queryable in business terms. Keep as a secondary forensic layer, not as the compliance record. |
| Audit records editable by a compliance administrator | Rejected | It re-creates the repudiation risk the control exists to remove. Retention purge is the only permitted mutation, and it is itself audited. |
| Audit only the financial events | Rejected | Most operational disputes (a rate change, a room transfer, a permission grant) are non-financial and must be reconstructable. |
| Store the full sensitive value in the audit record for forensic value | Rejected | Duplicates restricted data into a second store with different access rules. Records *that* access happened, not the value. |

## Consequences

- Audit volume grows and needs a retention and archival policy. Retention duration is **`TBD`** (`C-09`) — it is not set by the business and is not assumed here.
- The application must carry an audit writer on every sensitive path. This is a real cost in every module and must be designed in, not bolted on.
- `before`/`after` capture requires services to return prior state, which shapes some interfaces.
- Appending on every masked-field read would be too noisy; only *unmasked* reads are recorded. This must be stated in the audit-event contract so it is not interpreted as a gap.
- Audit access is a privileged operation with its own audit records — a deliberate, slightly recursive cost.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Audit writing fails silently and a gap opens | Medium | High | Audit write is part of the same transaction as the business change where they are the same fact; failures are observable, never swallowed |
| Retention never set, so audit grows unbounded | High | Medium | `C-09` assigned an owner; capacity planned in `C-01`/capacity planning |
| A module ships without audit events | Medium | High | Audit coverage is a Definition-of-Done item per module and a test assertion |
| Log injection via user-controlled values in audit fields | Low | Medium | Structured serialization; values are data, never format strings |
| Audit read by support exceeds the need | Medium | High | Support scope is explicit (`D-001`); audit reads are themselves audited |

## Reversibility

**Low.** Once data exists, append-only history cannot be retrofitted. Records written under a different shape cannot be migrated without losing their evidentiary value. This is the highest-cost-to-change control in the set, which is why it is decided before any module is built.

## References

`Prd_Maker.md` §32 (Audit Trail), §43 (Definition of Done), §54 (Threat Modeling — repudiation), `D-001` (Support role scope), `D-004` (identity access logging), `ADR-0009`, `ADR-0012`, `ADR-0014`, `docs/SECURITY.md` §5, `docs/DATA-MODEL.md` §6.
