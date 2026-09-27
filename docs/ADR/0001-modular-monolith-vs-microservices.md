# ADR-0001: Modular Monolith vs Microservices
- Status: Accepted
- Date: 2026-09-27
- Deciders: Project Manager (محمد فايز), Lead Product Architect
- Related: D-001, D-006, docs/PRD.md, docs/ARCHITECTURE.md, docs/DATA-MODEL.md
## Context
The project is a greenfield hotel Property Management System (PMS) for a single organization ("Zafer Al-Asriya") with initially 10 properties, one deployment. The core domain includes inventory allocation (room availability), reservation lifecycle, folio/ledger posting, payments, night audit, housekeeping, and ZATCA e-invoicing compliance. Two critical operations must be correct under concurrency: (1) atomic inventory allocation — exactly one of two concurrent booking attempts for the last available unit succeeds; (2) atomic folio posting — charges, payments, adjustments, and reversals must apply as a single ACID transaction to preserve ledger integrity. Prd Maker section 34 defines the acceptance condition: given one available unit and two concurrent valid booking attempts, no more than one may enter a confirmed state for that unit. Prd Maker section 72 warns against overengineering: architectural complexity must be justified by documented evidence, not fashion.
## Decision
Adopt a **modular monolith** architecture. The application is a single deployable unit (Laravel) sharing one MySQL database. The codebase is organized into independently bounded modules that communicate in-process. The 14 required bounded modules (from ADR-0017) are:
1. Organization & Property Master Data
2. Identity & Access (users, roles, property scopes)
3. Room Types & Physical Rooms
4. Inventory Allocation Engine
5. Reservation State Machine
6. Folio & Immutable Ledger
7. Payment Provider Adapter
8. Housekeeping & Room Status
9. Night Audit Batch
10. Tax, Invoicing & ZATCA Compliance Adapter
11. Outbox / DLQ / Reconciliation Worker
12. Reporting & Analytics
13. Notifications & Localization
14. Audit Trail & Observability
Modules share the database but own their tables; cross-module queries are allowed only through explicit service boundaries. No service-to-service network calls exist in the core PMS. External integrations (ZATCA, payment providers, future OTAs) are the only network boundaries, and they are accessed exclusively via the transactional outbox pattern (ADR-0005).
## Criteria Applied
From Prd Maker section 73, the following criteria drove the decision:
- **Correctness** (decisive): Atomic inventory allocation and atomic folio posting are single-database-transaction problems. A modular monolith makes double-booking prevention a local ACID guarantee. Splitting inventory and reservations into separate services converts this into a distributed-consensus problem requiring sagas/2PC and a compensating-transaction model, which adds latency, operational complexity, and failure modes without improving correctness.
- **Operational simplicity**: One deployment, one database, one migration history, one backup/restore procedure, one monitoring surface. This matches the team size and the Phase A scope (PMS Core only).
- **Recovery**: Single-database point-in-time recovery is trivial; distributed saga recovery is not.
- **Testability**: In-process module boundaries are testable with the same tooling; distributed system testing requires contract tests, chaos engineering, and service virtualization.
- **Cost**: One managed MySQL instance, one compute deployment. Microservices would multiply infrastructure and operational cost.
- **Performance**: In-process calls eliminate network latency for the hottest paths (inventory check, folio posting).
- **Security**: Fewer network boundaries reduce attack surface; secrets management is centralized.
Criteria that did not drive the decision:
- **Reversibility**: The architecture is reversible (see Reversibility below), but this was not a primary driver.
- **Scalability**: Current and projected load (10 properties) does not require horizontal scaling of individual domains. Documented throughput evidence would be required to justify splitting.
## Alternatives Considered
1. **Microservices (inventory, reservations, folio, payments, guests as separate services)**
   - What it is: Each bounded context runs as an independent deployable with its own database, communicating via async events or synchronous APIs.
   - Why rejected: Converts the single-transaction correctness requirement (inventory allocation + folio posting) into a distributed transaction problem. Requires saga orchestration, idempotent compensation handlers, duplicate detection across services, and eventual consistency windows that are unacceptable for the last-unit booking case. No documented throughput or availability evidence justifies this cost for 10 properties.
   - Deferred trigger: Documented evidence that a single deployment cannot meet latency or availability SLAs under production load, or that independent deployability of domains is required by organizational structure.

2. **Modular monolith with separate databases per module**
   - What it is: Each module owns a separate MySQL schema/database; cross-module transactions use distributed transactions or sagas.
   - Why rejected: Reintroduces the distributed-consensus problem for inventory+folio atomicity without the operational independence of true microservices. Adds connection pooling complexity and backup/restore coordination.
   - Deferred trigger: Regulatory requirement for data isolation between domains (not currently applicable per D-001).

3. **Serverless functions per use case**
   - What it is: Each command/query runs as a separate FaaS invocation with shared database.
   - Why rejected: Cold starts add unacceptable latency to the check-in/check-out and inventory allocation paths. Debugging and tracing distributed functions is harder than a monolith. Operational tooling for PHP serverless is immature.
## Consequences
- Positive: Atomic inventory allocation and folio posting are guaranteed by InnoDB row-level locking within a single transaction (see ADR-0003, ADR-0008). Simpler CI/CD, simpler disaster recovery, simpler local development.
- Negative: All modules share a single failure domain (database outage affects all). Schema changes require coordination. Module discipline must be enforced by code review and static analysis (no cross-module imports of internal classes).
- Operational: One deployment pipeline, one Terraform stack, one backup policy.
## Risks
- Module boundary erosion: Developers may bypass service boundaries and couple modules through direct model access. Mitigation: Enforce via PHPStan/psalm rules, architecture tests, and code review gates.
- Future SaaS multi-tenancy: The monolith must be designed so that adding an `organization_id` dimension does not require rewriting the business core (see ADR-0007).
- Scaling ceiling: If a single domain (e.g., inventory) becomes a bottleneck, vertical scaling of the whole deployment is the only option until a split is justified by evidence.
## Reversibility
Reversible with effort. To extract a module as a service: (1) define a stable internal API for that module, (2) replicate its tables to a new database, (3) implement the outbox pattern for cross-domain events, (4) deploy the service, (5) cut over traffic. The trigger for reversal is **documented throughput/availability evidence** showing the monolith cannot meet SLAs, not architectural fashion or hypothetical scale. The decision must be recorded in a new ADR with measurement data.
## References
- D-001 (single tenant, 10 properties), D-006 (modular monolith decision)
- Prd Maker §34 (double-booking protection acceptance condition)
- Prd Maker §72 (anti-overengineering)
- Prd Maker §73 (decision criteria)
- ADR-0003 (MySQL locking), ADR-0008 (inventory concurrency), ADR-0017 (module boundaries)