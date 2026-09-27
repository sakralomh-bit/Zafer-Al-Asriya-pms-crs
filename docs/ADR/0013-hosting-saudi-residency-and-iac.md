# ADR-0013: Hosting, Saudi Data Residency, and Infrastructure as Code

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-007`, `B-03`, `C-01`, `ADR-0020`, `ADR-0012`, `docs/DEPLOYMENT.md`

## Context

`D-007` requires production guest personal data to be hosted in a **Saudi Arabia cloud region where technically available**, on managed compute, managed MySQL, and managed Redis where practical, with private networking, encryption at rest and in transit, encrypted backups in approved Saudi-region storage, Terraform-based Infrastructure as Code, centralized secrets management, no production secrets in source control, automated backups, periodic restore-validation tests, documented backup retention, documented disaster-recovery procedures, and **explicit RPO and RTO defined before production approval**.

It also requires that cross-region warm-standby capability be designed but that **no automatic replication of guest personal data outside Saudi Arabia** be activated without a documented PDPL/SDAIA cross-border transfer assessment and organizational/legal approval. It forbids assuming a provider, forbids Kubernetes and multi-cloud for v1.0, and requires the application to stay portable.

`B-03`: **no provider is selected.** `C-01`: **RPO and RTO have no values.**

## Decision

### 1. Topology

One production application deployment · managed relational database · managed Redis · secure object storage · background workers/queues. `docs/DEPLOYMENT.md` holds the detail.

No orchestrator, no multi-cloud, no active-active multi-region. `Prd_Maker.md` §72 forbids architecture added for fashion, and no documented requirement justifies any of these.

### 2. Provider-neutral by design, provider-specific by necessity

`ADR-0020` defines the evaluation framework and hard gates. **The provider is not selected here, and this ADR does not pretend otherwise.** An ADR containing a confident table of SLAs, prices, and region names would be a fabrication — those facts are not in the repository and change over time.

What *is* fixed regardless of provider:

- The **database is a hard gate**, not a preference. Atomic inventory allocation (`ADR-0008`) depends on explicit `SELECT ... FOR UPDATE` semantics at a known isolation level. A provider whose managed MySQL cannot be verified for this behaviour is not a candidate.
- The **deployed isolation configuration must be verified**, not assumed from intent.
- Backup and **point-in-time restore** is a hard gate, because a backup that has never been restored is not a backup.

### 3. Private networking

Databases and internal services are on private subnets with no public database endpoint. The application reaches the database only from inside the network boundary. This is the control that prevents the most common cloud misconfiguration, an internet-reachable database holding guest personal data and financial records.

### 4. Encryption

- **In transit:** TLS for all external and internal service communication.
- **At rest:** encryption for databases, object storage, and backups.
- **Backups:** encrypted, stored in approved Saudi-region storage where technically available.
- **Key management:** centralized, with a **separate key boundary for identity data** (`ADR-0012`) so that routine access — a backup, a replica, a support query, a misconfigured export — does not yield readable identity fields.
- **Secrets:** centralized secrets management. No production secret in source control, ever. Secret scanning in CI.

### 5. Cross-border transfer — the hard gate

**No automatic replication of guest personal data outside Saudi Arabia may be activated** without a documented transfer assessment and organizational/legal approval.

The SDAIA Regulation on Personal Data Transfer Outside the Kingdom is in force and provides for adequacy assessment, appropriate safeguards, and other permitted pathways (`V-09`). It is also reported that SDAIA's list of adequate-protection territories is **not yet confirmed** — so this is treated as an open obligation rather than a solved question.

Three specific transfer paths are gated, because each is a way personal data can leave without anyone deciding to send it:

| Path | Gate |
|---|---|
| Cross-region database replication | Default **off** for guest personal data; requires an approved assessment |
| Log/trace/telemetry export to an external service | Provider sub-processor review required; no identity data in telemetry in any case (`ADR-0012`) |
| Vendor support access (including read-only) | Reviewed, documented, audited; Support scope is explicitly scoped and time-bound (`D-001`, `ADR-0014`) |

The third is the one most often overlooked: a support engineer in another country reading a production database is a cross-border transfer, and the fact that it was "just support" does not change it.

### 6. Environments

Development, staging/UAT, and production. **No environment other than production may contain real guest personal data** (`D-007`, `PRI-008`). Development and staging use synthetic data only, and may use lower-cost infrastructure — that is the explicit permission in `D-007`, and the reason the rule is written as "no real guest data outside production" rather than "no non-production environment".

### 7. Backups and restore validation

Automated backups, encrypted, in approved Saudi-region storage. Retention documented — **values `TBD` (`H-07`)**. **Restore-validation tests are a release requirement, not a later task:** a backup that has never been restored is an assumption, and discovering it is unrestorable during an incident is the worst possible time.

RPO and RTO are **`TBD` (`C-01`)** and must be set before production approval. They are not invented here, because a fabricated RPO is worse than an absent one — it is a commitment the system was never designed to meet.

### 8. Cross-region warm standby

**Designed, not built, and not activated.** The topology is planned so a warm standby is possible; activation of any PII-carrying replica is gated on §5. A standby that cannot legally be activated is worse than no standby, because it invites the assumption that disaster recovery is already solved.

### 9. Infrastructure as Code

All infrastructure is reproducible through Terraform or equivalent. Nothing is created by hand in a console. This is what makes the environment reproducible, reviewable, and recoverable — and it is what makes the provider decision reversible at the application layer even though it is not reversible in the infrastructure layer.

### 10. Portability

The domain and application layers must not import provider SDK types. Infrastructure access sits behind the modules' own interfaces. This is what keeps the provider decision (`B-03`) from becoming an architectural commitment across the whole codebase, and it is why the provider can be chosen later than the domain.

### 11. Monitoring

Infrastructure, application, security, and database monitoring, with centralized collection. Stack **`TBD` (`H-04`)**. Alerts must carry a threshold, severity, owner, escalation, and runbook (`Prd_Maker.md` §31). **No 24/7 support or incident-escalation model exists yet (`H-05`)** — a real gap for a system that cannot stop check-in.

## Criteria Applied

Compliance (residency and transfer posture), security (networking, encryption, key boundaries, secrets), operational simplicity (managed services over self-managed), recovery (backup, restore validation, DR), reversibility (portability), cost (managed-service premium against operational load).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| On-premise in Saudi Arabia | Rejected | `D-007` specifies managed cloud. Independently: patching, backup, replication, monitoring, and capacity all land on a team not assumed to exist, and RTO becomes a function of hardware procurement. |
| Self-managed MySQL/Redis on cloud VMs | Deferred | Sometimes cheaper at small scale, but moves backup, patching, and failover onto the team, contradicting the managed-service intent. Reconsider if a Phase 0 cost analysis shows a material difference. |
| Kubernetes | Rejected for v1.0 | Not required by any documented requirement. `Prd_Maker.md` §72. A single deployment plus workers does not need an orchestrator. |
| Multi-cloud or active-active multi-region | Rejected for v1.0 | Doubles cost and complexity with no availability requirement that justifies it — and multi-region active-active would also force cross-border PII replication, which `D-007` prohibits without an assessment. |
| Selecting a provider now from general knowledge | **Rejected** | Region availability, product parity, pricing, and SLA terms are all `UNKNOWN` and change. `ADR-0020` defines the framework; the decision needs primary sources. |
| Cross-region replication for DR "just in case" | **Rejected** | It would automatically replicate guest personal data outside Saudi Arabia in violation of `D-007`, and nobody would have made that decision deliberately. |

## Consequences

- All infrastructure work that needs a provider identity is blocked (`B-03`). The IaC structure can be designed provider-agnostically; concrete resources cannot be created.
- Residency becomes a legal-review item, not a procurement checkbox. The provider's sub-processor list and where telemetry and support access originate are PDPL processor questions.
- Production approval is blocked on `C-01` (RPO/RTO) and `H-07` (retention and restore-validation cadence), independent of the provider.
- Key management (`H-03`) is a release gate: the separate identity-data key boundary is a requirement, not a hardening improvement.
- The absence of a 24/7 support model (`H-05`) is an operational risk for a system that must not stop check-in, and it is not solved by choosing a good provider.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Guest PII leaves Saudi Arabia by default configuration | Low | **Critical** | Replication default-off; §5 gates; verified at deployment |
| Support access from another jurisdiction is a transfer | **Medium** | High | Reviewed, documented, audited, explicitly scoped |
| A backup is never successfully restored | Medium | **Critical** | Restore validation is a release requirement (`H-07`) |
| RPO/RTO are set aspirationally and not met | Medium | High | `C-01` must be derived from the verified backup and restore capability, not chosen first |
| A managed service is unavailable in the Saudi region | Low | High | `ADR-0020` hard gate G-1; a global-region fallback is a compliance change requiring separate approval |
| Provider lock-in spreads into the domain | Medium | High | Portability rule; no provider types in domain/application layers |
| A console change is made by hand | Medium | High | IaC-only; drift detection |
| Key management is deferred as hardening | Medium | **Critical** | Release gate (`H-03`) |

## Reversibility

**Moderate.** The application and domain layers are portable by rule and cheap to move. The infrastructure layer is not: Terraform provider resources, managed-service configuration semantics, IAM policy syntax, backup/restore tooling, and observability integration are provider-coupled, and migrating them is a real project. This asymmetry is the reason `D-007` insists on portability at the application layer — it keeps most of the system out of the irreversible part.

## References

`D-001`, `D-004`, `D-007`, `B-03`, `C-01`, `H-03`, `H-04`, `H-05`, `H-07`, `ADR-0008`, `ADR-0012`, `ADR-0014`, `ADR-0016`, `ADR-0020`, `Prd_Maker.md` §20.5, §20.6, §26, §31, §51, §72, §64, `V-09`, `docs/DEPLOYMENT.md`, `docs/SECURITY.md` §7.
