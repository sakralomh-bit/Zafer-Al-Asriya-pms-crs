# ADR-0003: Database — MySQL 8.x InnoDB vs PostgreSQL 16
- Status: Accepted
- Date: 2026-09-27
- Deciders: Project Manager (محمد فايز), Lead Product Architect
- Related: D-006, docs/PRD.md, docs/ARCHITECTURE.md, docs/DATA-MODEL.md
## Context
The modular monolith (ADR-0001) requires a single relational database with strong ACID guarantees, row-level locking, foreign keys, unique constraints, and explicit transaction boundaries. The core correctness requirement is atomic inventory allocation (exactly one of two concurrent booking attempts for the last unit succeeds) and atomic folio posting. D-006 explicitly decides MySQL 8.x InnoDB. This ADR records the evidence-based comparison against PostgreSQL 16.
## Decision
**MySQL 8.x InnoDB** is the selected database.
- Isolation level: **READ COMMITTED** with explicit `SELECT ... FOR UPDATE` (or `SELECT ... FOR SHARE` where appropriate) in short transactions. This is the default in MySQL 8.0. The application must verify the server configuration (`transaction_isolation = READ-COMMITTED`) at startup and in CI.
- Row-level locking: InnoDB uses clustered primary keys; `FOR UPDATE` locks the index records scanned. The inventory allocation transaction must lock the specific inventory unit row (or the room-type availability row with a deterministic lock ordering) before checking availability and inserting the allocation.
- Unique constraints: A unique index on `(property_id, room_type_id, business_date, inventory_unit_id)` (or equivalent allocation key) prevents duplicate allocations at the storage engine level, independent of application logic.
- Foreign keys: Enforced for referential integrity (property → room types → rooms → inventory → reservations → folios → charges/payments).
- Cost/availability: Managed MySQL 8.x is universally available in Saudi-region cloud providers at lower cost than managed PostgreSQL with equivalent SLA.
## Criteria Applied
From Prd Maker §73, the following criteria drove the decision:
- **Correctness** (decisive, with caveat): InnoDB row-level locking with explicit `FOR UPDATE` in a short READ COMMITTED transaction, combined with unique constraints and the allocation state machine (ADR-0008), satisfies the double-booking acceptance condition. **However: MySQL row locking ALONE does NOT guarantee prevention of double booking.** See Mandatory Warning below.
- **Operational simplicity**: Universal operational familiarity in the region. Every DBA, cloud provider, and monitoring tool supports MySQL. Backup/restore (Percona XtraBackup, mysqldump, managed snapshots) is well-understood.
- **Cost**: Managed MySQL is consistently lower cost than managed PostgreSQL in Saudi-region providers for equivalent compute/storage.
- **Availability**: Multi-AZ managed MySQL is a standard offering; failover is tested and documented.
- **D-006 explicit decision**: The project manager confirmed MySQL as the decision.
Criteria that did not drive the decision:
- **Advanced PostgreSQL features**: PostgreSQL 16 has real advantages — serializable isolation level (true serializability without application locks), better partial indexes for rate/restriction models (e.g., `WHERE status = 'ACTIVE'`), JSONB for flexible schema, and richer window functions. These were acknowledged but deemed not decisive for the Phase A scope.
- **Reversibility**: Both databases are reversible; this was not a primary driver.
## Alternatives Considered
1. **PostgreSQL 16**
   - What it is: Advanced open-source RDBMS with serializable isolation, partial indexes, JSONB, and rich indexing.
   - Why rejected: The correctness requirement (atomic inventory allocation) is achievable in both. PostgreSQL's serializable isolation would simplify application logic (no explicit `FOR UPDATE` needed for serializable transactions), but it introduces serialization failures (SQLSTATE 40001) that require application-level retry logic with exponential backoff. InnoDB's READ COMMITTED + explicit locking is more predictable for the team's current expertise. Operational cost and regional managed-service maturity favor MySQL. The D-006 decision is explicit.
   - Deferred trigger: If a future requirement demands complex JSON document queries (e.g., dynamic rate rules) that JSONB handles significantly better than MySQL's JSON functions, or if serializable isolation eliminates a proven contention hotspot that READ COMMITTED + locking cannot resolve.
2. **MariaDB 10.11+**
   - What it is: MySQL fork with some optimizer improvements and additional storage engines.
   - Why rejected: Managed MariaDB offerings in Saudi-region clouds are less common than MySQL. InnoDB is the same engine. No compelling feature for this project justifies deviating from the standard MySQL managed service.
   - Deferred trigger: If a specific MariaDB feature (e.g., ColumnStore for analytics) becomes a hard requirement.
## Consequences
- All migrations target MySQL 8.0+ syntax (CTEs, window functions, JSON functions, invisible indexes, `CHECK` constraints).
- Connection pooling: Use proxy (ProxySQL) or application-level pooling (Laravel's database config). Max connections tuned for Horizon workers + web workers.
- Backup: Managed automated backups + point-in-time recovery (PITR). Restore validation cadence TBD (blocker C-01).
- Monitoring: `performance_schema`, `sys` schema, slow query log, deadlock logging (`innodb_print_all_deadlocks = ON`).
## Mandatory Warning
**MySQL row locking ALONE does NOT guarantee prevention of double booking.** Inventory correctness requires the **complete chain**:
1. Transactional allocation (ADR-0001, ADR-0008)
2. Appropriate row locking (`SELECT ... FOR UPDATE` on the inventory unit or availability row)
3. Unique constraints on the allocation key (prevents duplicate rows at storage engine level)
4. Allocation rules (business logic: max one allocation per unit per business date)
5. Reservation state-machine validation (invalid transitions rejected)
6. Idempotency keys on every create request
7. Concurrency tests that prove the acceptance condition (ADR-0021)

Cross-reference ADR-0008 (inventory concurrency and allocation) for the full strategy. Omitting any link in this chain creates a double-booking vulnerability.
## Risks
- **Lock contention**: High contention on popular room types/dates. Mitigation: Short transactions, lock ordering (always lock by `property_id, room_type_id, business_date` ascending), optimistic retry with backoff for `LOCK_WAIT_TIMEOUT`.
- **Deadlocks**: InnoDB detects and rolls back one transaction. Application must retry on `SQLSTATE 40001` (deadlock) and `HY000` (lock wait timeout). Max retry attempts: 3 with exponential backoff + jitter.
- **Configuration drift**: Server `transaction_isolation` must be verified. CI must include a test that asserts `SELECT @@transaction_isolation = 'READ-COMMITTED'`.
- **Replication lag**: If read replicas are used for availability search, they must not serve allocation decisions. Allocation writes go to primary only.
## Reversibility
Reversible with effort. Migration to PostgreSQL would require: (1) schema translation (data types, indexes, constraints), (2) rewriting locking queries (`FOR UPDATE` → `FOR UPDATE` works in PG but semantics differ; serializable isolation would replace explicit locks), (3) migrating stored procedures/functions (none planned), (4) validating all unique constraints and foreign keys, (5) full data migration with reconciliation, (6) updating CI/CD and Terraform. Estimated effort: 2–3 months. Trigger: documented evidence that MySQL cannot meet a verified non-functional requirement (e.g., serializable isolation mandated by auditor, or JSONB performance critical for dynamic pricing).
## References
- D-006 (explicit MySQL decision)
- Prd Maker §34 (double-booking protection)
- Prd Maker §73 (decision criteria)
- ADR-0001 (modular monolith), ADR-0008 (inventory concurrency), ADR-0021 (testing/concurrency verification)