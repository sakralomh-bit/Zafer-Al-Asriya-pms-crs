# ADR-0020: Cloud Provider Evaluation Framework

- **Status:** Proposed — **no provider selected**
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-007`, `B-03`, `ADR-0013`, `docs/DEPLOYMENT.md`

## Context

`D-007` requires production guest personal data to be hosted in a Saudi Arabia cloud region where technically available, on managed compute, managed MySQL, and managed Redis where practical, with private networking, encryption at rest and in transit, encrypted backups in approved Saudi-region storage, Terraform-based Infrastructure as Code, centralized secrets management, no production secrets in source control, automated backups, periodic restore-validation, documented DR procedures, and explicit RPO/RTO defined before production approval.

`D-007` also forbids assuming a provider at this stage and requires an ADR that compares candidate providers on the listed criteria. It further requires that the application stay portable so the domain and application layers are not tightly coupled to one vendor, and that Kubernetes and multi-cloud not be introduced for v1.0.

**No provider is selected. This is blocker `B-03`.**

This document deliberately contains **no scored verdict**, because a scored verdict built on unverified figures would be a fabrication. The market's service names, region identifiers, availability guarantees, and prices change, and none of them can be read from this repository. Producing a confident table of SLA percentages and prices from memory would be precisely the failure mode `Prd_Maker.md` §71 exists to prevent.

What this ADR provides instead is the **evaluation framework, the weighted criteria, the hard gates, and the shortlist of candidate categories** — so that a decision can be made and recorded quickly once primary sources are read.

## Decision

### 1. Hard gates — a candidate must pass all of these to be eligible

These are pass/fail, not scored. A candidate failing any hard gate is not a candidate.

| Gate | Requirement | Source |
|---|---|---|
| G-1 | A region physically located in Saudi Arabia is available, or a documented and legally reviewed alternative residency approach is accepted | `D-007` |
| G-2 | Managed MySQL with configurable storage engine settings, transaction isolation, and explicit row-level locking behaviour (`SELECT ... FOR UPDATE`) is available in that region | `D-006`, `ADR-0003` |
| G-3 | Managed Redis with persistence and queue semantics suitable for Horizon is available in that region | `D-006`, `ADR-0005` |
| G-4 | Private networking for the database and internal services, with no public database endpoint | `D-007` |
| G-5 | Automated backups **and** documented point-in-time restore, with restore validation that can actually be performed | `D-007`, `H-07` |
| G-6 | Encryption at rest and in transit, with a key-management service offering a **separate key boundary** for identity data | `D-004`, `ADR-0012` |
| G-7 | First-class IAM/RBAC with least-privilege role definition, and no shared long-lived root credentials in application configuration | `D-007`, `ADR-0014` |
| G-8 | Managed secrets management; no production secret in source control | `D-007` |
| G-9 | First-class Terraform or equivalent provider support for every resource in the deployment | `D-007` |
| G-10 | Monitoring, metrics, logs, and alerting, with a documented retention and export capability | `D-007`, `H-04` |
| G-11 | A contractual and technical path to a documented disaster-recovery posture within the RTO eventually agreed (`C-01`) | `D-007`, `C-01` |
| G-12 | Cross-border transfer implications of any support, telemetry, or sub-processor access to guest personal data are documented and acceptable after legal review | `V-09`, `D-007` |

### 2. Weighted criteria — applied only after the hard gates

| Criterion | Weight | Why it is weighted this way |
|---|---:|---|
| Saudi data residency capability | High | `D-007`; also interacts with the SDAIA transfer regulation (`V-09`) |
| Managed MySQL capability and locking semantics | High | The double-booking guarantee depends on it (`ADR-0008`) |
| Backup and point-in-time restore | High | `H-07`; a backup that has never been restored is not a backup |
| Encryption and key management | High | `D-004` requires a separate identity-data key boundary |
| Operational support in the region | High | `H-05`; no 24/7 in-house DBA/SRE is assumed |
| Availability and contractual commitments | Medium-High | Drives the availability target (`C-01`) |
| Terraform provider maturity | Medium | `D-007` requires IaC reproducibility |
| Monitoring and logging | Medium | `H-04` |
| IAM/RBAC granularity | Medium | `ADR-0014` |
| Cost | Medium | Must be evaluated against total cost including managed-service premiums, not instance price alone |
| Sub-processor and support-access posture | Medium | PDPL processor obligations (`V-07`, `V-09`) |
| Developer familiarity | Low-Medium | A real factor for maintainability, but it must not outrank the high-weight criteria |

### 3. Candidate shortlist

Candidates are named at the **category** level. Specific product names, region identifiers, service availability by region, pricing, and contractual terms are **all `UNKNOWN`** and must be read from each provider's current primary documentation before scoring.

| Candidate category | Examples to evaluate | Status |
|---|---|---|
| International hyperscaler with a Saudi region | To be confirmed from current provider documentation | Region availability, product parity in that region, and local support terms are all `UNKNOWN` |
| Second international hyperscaler with a Saudi region | To be confirmed from current provider documentation | As above |
| Third international hyperscaler with a Saudi region | To be confirmed from current provider documentation | As above |
| Regional / Gulf-based provider | To be identified | Residency story may be strong; managed-service depth, IaC maturity, and global support require verification |
| On-premise in Saudi Arabia | Previously considered | **Not a candidate under `D-007`**, which specifies managed cloud. Recorded here so the rejection is traceable rather than silent |

### 4. Verification requirements before this ADR can become "Accepted"

For every candidate, and from **primary provider sources only**, record for each criterion: the source URL, the document or page revision or retrieval date, and the verified value. Where a value cannot be obtained, leave it `UNKNOWN` and state why.

Explicitly **not** acceptable as sources: blog posts, vendor marketing comparisons, forum answers, or a model recalling prices.

The following must be verified and cited before the ADR is accepted:

- Region location and the legal entity operating it
- Whether every service in `G-2`…`G-10` is available **in that region**, not merely in the provider's global catalogue
- The contractual availability commitment, with its exclusions and measurement method
- Pricing at the scale this system will operate at, for the full managed stack
- The sub-processor list and where telemetry and support access originate
- Whether the region's data is replicated outside the kingdom by default, and how to prevent it

## Criteria Applied

Correctness (managed MySQL locking semantics underpin the double-booking guarantee), security (key boundaries, IAM, secrets), compliance (residency and transfer posture), operational simplicity, recovery, testability, cost, reversibility (portability at the application layer), performance.

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| On-premise in Saudi Arabia | Rejected | `D-007` specifies managed cloud. Independently: it puts patching, backup, replication, monitoring, and capacity on a team that is not assumed to exist, and makes RTO a function of hardware procurement. Recorded rather than silently dropped. |
| Kubernetes / container orchestration platform | Rejected for v1.0 | Not required by any documented requirement. `Prd_Maker.md` §72. A single application deployment plus workers does not need an orchestrator. |
| Multi-cloud or active-active multi-region | Rejected for v1.0 | Doubles operational cost and complexity with no documented availability requirement that justifies it. Cross-region warm standby is *designed* in `ADR-0013` but is not an active-active architecture. |
| Selecting a provider now from general knowledge | **Rejected** | Region availability, product parity, pricing, and contractual terms are all `UNKNOWN` and change. This would be a fabricated decision. |
| Self-managed MySQL/Redis on cloud VMs | Deferred | Viable and sometimes cheaper at small scale, but it moves backup, patching, and failover onto the team, which contradicts the managed-service intent of `D-007`. Reconsider if cost analysis in Phase 0 shows a material difference. |

## Consequences

- Infrastructure work that depends on a provider identity is blocked. The IaC baseline can be structured provider-agnostically, but concrete resources cannot be created.
- `D-007`'s portability rule is not optional decoration: it is what keeps this decision reversible. The domain and application layers must not import provider SDK types, and infrastructure access must sit behind the module's own interfaces.
- The residency question is a legal-review item, not only a technical one. The provider's sub-processor and telemetry posture is a PDPL processor question, not a procurement checkbox.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A provider is selected from a secondary source and a hard gate actually fails | Medium | High | `§4` primary-source verification is mandatory before acceptance |
| Residency is assumed from a marketing page | Medium | **Critical** | Residency is verified as a legal/technical fact with a documented sub-processor assessment |
| Default configuration replicates guest PII outside the kingdom | Low | **Critical** | G-12; replication behaviour explicitly verified and configured off unless approved |
| Managed service is unavailable in the Saudi region and a global region is used as a fallback | Low | High | G-1 is a hard gate; a global-region fallback is a compliance change requiring separate approval, not an engineering decision |
| Cost is underestimated by comparing instance price to managed-service price | Medium | Medium | Evaluate the full managed stack at expected scale, including backups, replicas, and observability |

## Reversibility

**Moderate.** The application-layer portability rule keeps the domain and application portable. The genuinely provider-coupled parts are: Terraform provider resources, managed-service configuration semantics, IAM policy syntax, backup/restore tooling, and observability integration. Migrating between providers is a real infrastructure project, not a configuration change. This is why the choice should be made deliberately against the gates, and why the application layer is kept strictly free of vendor types.

## References

`D-004`, `D-006`, `D-007`, `B-03`, `C-01`, `H-04`, `H-07`, `V-07`, `V-09`, `ADR-0003`, `ADR-0008`, `ADR-0012`, `ADR-0013`, `ADR-0014`, `Prd_Maker.md` §23, §31, §51, §72, §73, `docs/DEPLOYMENT.md`.
