# ADR-0019: API Versioning, Error Model, and Correlation

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-006`, `ADR-0017`, `docs/API-SPEC.md`

## Context

`Prd_Maker.md` §25 requires each API to specify method, path, purpose, auth, authorization, schema, validation, statuses, error codes, idempotency, pagination, filtering, sorting, rate limit, audit event, correlation ID, versioning, and backward compatibility. It also requires a structured error model and states: *"Never expose stack traces, secrets, SQL, internal hostnames, or sensitive implementation details to end users."*

`Prd_Maker.md` §62 states: *"Never silently break consumers of a public API."*

This system has three distinct consumer classes, and they have different compatibility needs:

1. **The first-party Arabic/English web application** — same team, same release, so strict coupling is acceptable and cheap.
2. **The Phase B booking engine** — a guest-facing public surface, browser-based, cannot be force-upgraded, and is exposed to the internet.
3. **Phase C channel manager and any future third parties** — server-to-server, long-lived, must survive internal refactoring.

An error code is also a **client contract**. If a front-end branches on a message string, or if a retry decision is made by parsing prose, the API is already broken in a way that no schema validation will catch.

## Decision

### 1. Versioning

- All endpoints are versioned explicitly in the path (`/api/v1/...`). A header-only version is not sufficient: intermediaries cache by URL, and a version that is invisible in the path is a version that gets cached wrong.
- **The public booking surface (Phase B) and any third-party surface (Phase C) are contract-frozen within a major version.** The first-party application surface may evolve more freely *within* a version because it ships in lockstep, but a breaking change to it still requires a version bump if any external consumer exists.
- A breaking change means: removing or renaming a field, narrowing an accepted value, tightening validation that previously passed, changing a type, changing a status code for an existing case, or changing a semantic. Additive optional fields are not breaking.
- **Deprecated endpoints are announced, monitored for actual usage, and removed only after observed usage reaches zero** over an agreed observation window. Removal is a deliberate act with a deprecation notice, never a side effect of a refactor.
- A consumer-inventory requirement: any surface with an external consumer must register that consumer so deprecation can be verified against real usage rather than assumed usage.

### 2. Error model

One shape, everywhere, for every error, including validation errors and authentication failures:

```json
{
  "code": "INVENTORY_UNAVAILABLE",
  "message": "No inventory is available for the requested criteria.",
  "request_id": "01J8Z9K2M4N6P8Q0R2S4T6V8X0",
  "details": [],
  "retryable": false
}
```

- **`code`** is a stable machine-readable identifier. It is the contract. Clients branch on `code` and never on `message`.
- **`message`** is for humans, is localized (Arabic/English per `ADR-0015`), and is explicitly **not** part of the contract. Its wording may change without a version bump.
- **`request_id`** is the correlation ID. It appears in the response, in the audit record, in the log line, in the queued job, and in any external call — so a user reporting "it failed" gives support one value that reconstructs the whole path.
- **`details`** carries field-level validation information only. It must never carry internal detail.
- **`retryable`** is a server assertion about whether the *same request* may be safely re-sent. A payment with an unknown outcome is **not** `retryable: true`; it requires reconciliation. This field exists so that a client never has to guess, and so that a client cannot turn an unknown payment outcome into a duplicate charge by naive retry.

### 3. Prohibited in any response body

Stack traces. SQL or ORM exceptions. Internal hostnames, container names, or service topology. Secrets, tokens, keys. Environment values. Framework debug pages. Internal class or table names. **Identity-document numbers** (`ADR-0012`). Any card data. Any internal note intended for staff.

The error message a client sees is written for a hotel receptionist, not for a developer. Diagnostic detail goes to the correlated server-side record, retrievable by staff with the audit scope.

### 4. HTTP status discipline

Status codes describe the **transport-level outcome**; the `code` field describes the **domain outcome**. A domain failure that is a normal, expected business result — a sold-out room, an invalid state transition, a declined card — is a `4xx` with a precise `code`, **not** a `500` and **not** a `200` with an error buried in the body.

A `500` means the system failed in a way it does not understand, and it is a defect signal: `5xx` responses are alerted on and counted as availability failures.

`INVENTORY_UNAVAILABLE` on a lost allocation race is an expected outcome of a correct system. It must not be reported as an error rate.

### 5. Correlation and idempotency

- A correlation/request ID is generated at the edge if absent, propagated through the service call, written to the log, the audit record, the outbox row, the external request, and returned to the client.
- **`Idempotency-Key` is required** on every operation that creates or moves money, inventory, or a reservation. Repeating a request with the same key returns the **original** result and creates no second effect. The key is scoped to the operation and retained for a defined window.
- Replaying an idempotent request after its key expires is not guaranteed to be deduplicated, and this must be stated to clients rather than assumed safe.

### 6. List endpoints

Every list endpoint is paginated with a bounded page size and a maximum. Filtering and sorting are allow-listed per endpoint — an arbitrary sort on an unindexed column is an availability incident waiting to happen. Sort fields are drawn from a fixed, indexed set.

### 7. Property scoping

Every property-scoped endpoint takes a `property_id` and **enforces it server-side against the caller's grants**. A caller without a grant for that property receives a denial, not an empty result — an empty result leaks the existence of the property and makes scoping bugs invisible in testing.

### 8. No external endpoint is specified

This document defines **no** OTA, payment-provider, or ZATCA endpoint. No such provider has been selected or verified (`B-02`, `B-04`). Their contracts are `UNKNOWN` and must be obtained from authoritative provider documentation before any adapter is written. Inventing a plausible endpoint is a defect, not a placeholder.

## Criteria Applied

Correctness (a stable `code` and a truthful `retryable` prevent client-side logic errors), security (no detail leakage), testability (deterministic codes make negative tests assertable), reversibility (versioned surfaces are upgradeable), operational simplicity (one error shape), compliance (ZATCA submission state is observable without a second channel).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| Header-based versioning only | Rejected | Invisible to caches and intermediaries; a cached wrong version is hard to diagnose. |
| GraphQL as the primary API | Deferred | A flexible query surface is not what this domain needs; the requirement is a stable, cacheable, well-documented contract. A GraphQL read facade may be added later for reporting without changing the write contract. |
| REST with error messages as the contract | Rejected | Localized, human-facing message strings cannot be a stable contract; clients would break on a copy change. |
| Returning `200` with an in-body error flag | Rejected | Breaks HTTP semantics, breaks caching and monitoring, and hides domain failures from error-rate alerting. |
| Per-request random `request_id` with no propagation | Rejected | Support cannot reconstruct a failure across the queue and the provider. |
| No `retryable` field, clients infer from status | Rejected | A client would guess, and on an unknown payment outcome a guess produces a duplicate charge. |
| Validating and retrying transparently inside the API | Rejected for non-idempotent operations | Automatic retry of a non-idempotent money operation is a duplicate-charge vector. Retry is an explicit client decision governed by `retryable` and the idempotency key. |

## Consequences

- Error codes become a versioned public contract and require a registry to prevent accidental reuse or redefinition.
- A deprecation process with usage monitoring is required before any third-party surface exists.
- Localization of `message` is safe precisely because it is not a contract; this removes a whole class of breaking-change risk.
- The prohibition on internal detail must be enforced in review, and negative tests must assert that an error response contains no stack trace, SQL, or identity data.
- Because `5xx` is an availability signal, the distinction between an expected domain failure and an unexpected system failure is a correctness requirement, not a cosmetic one.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A developer puts internal detail in `message` or `details` | Medium | High | Review rule plus automated assertion that error bodies contain no stack/SQL/identity patterns |
| A breaking change ships without a version bump | Medium | High | Consumer inventory; explicit breaking-change checklist in the release process |
| A client retries an unknown payment outcome | Low | **Critical** | `retryable: false` for unknown outcomes; reconciliation path; client contract test |
| Error codes drift or are reused with different meanings | Medium | Medium | Code registry reviewed on change |
| Deprecation removes an endpoint still in use | Low | High | Usage monitoring with an observation window before removal |

## Reversibility

**High.** Error codes can be added; a wrongly-shared `code` is a compatibility problem but not a data problem. Versioning is designed to be additive. The genuinely irreversible exposure is the reverse mistake — a client having already built against a contract that later changes meaning.

## References

`Prd_Maker.md` §25 (API Requirements), §26 (Idempotency/Concurrency), §29 (Error Handling), §31 (Observability), §55 (Webhook Security), §62 (Data Migration and Versioning), `ADR-0012`, `ADR-0015`, `docs/API-SPEC.md`, `docs/SECURITY.md` §4.
