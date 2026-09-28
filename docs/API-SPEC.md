# Zafer Al-Asriya v1.0 — API Specification

| Field | Value |
|---|---|
| Document | `docs/API-SPEC.md` |
| Version | 0.1 |
| Status | Draft — contract specification. **No endpoint is implemented.** |
| Related | `ADR-0014`, `ADR-0019`, `ADR-0011`, `ADR-0012`, `docs/STATE-MACHINES.md`, `docs/DATA-MODEL.md` |

---

## 0. Scope and non-scope

This document specifies the **first-party internal API** (staff application) and the **public booking API** (Phase B) shape. It specifies **no external endpoint** for any OTA, payment provider, or ZATCA/FATOORA, because no such provider has been selected or verified (`B-02`, `B-04`). Those contracts are `UNKNOWN — requires confirmation from the authoritative source before production` and must be obtained from provider documentation. Inventing a plausible endpoint is a defect.

---

## 1. Conventions

### 1.1 Versioning and base paths

```text
/api/v1/...        Internal staff API (first-party application)
/public/v1/...     Public booking API (Phase B, guest-facing, internet-exposed)
```

Version in the path, not a header. Additive optional fields are non-breaking; removals, renames, type changes, narrowed validation, and changed semantics for an existing case are breaking and require a major version.

**Degradation policy:** a deprecated endpoint is announced, monitored for real usage, and removed only after observed usage reaches zero over an agreed window. Any surface with an external consumer must register that consumer so deprecation is verified against real usage, not assumed usage.

### 1.2 Authentication

| Surface | Method | Status |
|---|---|---|
| Staff API | Session-based with CSRF protection; MFA available; step-up for sensitive operations | Specified; algorithm/timeout values `TBD` (`SEC-007`, `SEC-008`) |
| Public booking API (Phase B) | **Token mechanism `TBD` — do not assume** | `TBD` |

**No token format, lifetime, or header scheme is asserted here.** Inventing one would fix a security contract on a guess.

### 1.3 Required headers

| Header | Direction | Requirement |
|---|---|---|
| `X-Correlation-ID` | Request, optional | Generated at the edge if absent; returned in the response; propagated to jobs and external calls |
| `Idempotency-Key` | Request, **required** on money/inventory/reservation operations | Client-generated unique per logical operation |
| `Accept-Language` | Request, optional | `ar` or `en`; drives `message` localization only, never logic |

### 1.4 Property scoping

Every property-scoped endpoint takes `property_id` and **enforces it server-side against the caller's grants**. A caller without a grant receives `PROPERTY_SCOPE_DENIED` — **not** an empty result (`ADR-0014` §3).

`property_id` is a required input, never inferred from the payload, and never trusted from a client-supplied "current property" without a scope check.

### 1.5 Error model

```json
{
  "code": "INVENTORY_UNAVAILABLE",
  "message": "No inventory is available for the requested criteria.",
  "request_id": "01J8Z9K2M4N6P8Q0R2S4T6V8X0",
  "details": [],
  "retryable": false
}
```

| Field | Contract status |
|---|---|
| `code` | **Stable machine-readable identifier. This is the contract.** |
| `message` | Human-facing, localized. **Not** part of the contract; wording may change without a version bump. |
| `request_id` | Correlation ID. Present in the response, audit record, log line, job, and external call. |
| `details` | Field-level validation information only |
| `retryable` | Server assertion on whether **the same request** may be safely re-sent |

**`retryable` is a correctness control, not a convenience.** A payment with an unknown outcome returns `retryable: false`, because a client retrying it produces a duplicate charge. Clients branch on `code`, never on `message` or on HTTP status alone.

### 1.6 Prohibited in any response body

Stack traces · SQL or ORM exceptions · internal hostnames, container names, or service topology · secrets, tokens, keys · environment values · framework debug output · internal class or table names · **identity-document numbers** · any card data · internal staff notes.

A client of this API is a hotel receptionist. Diagnostic detail goes to the correlated server-side record, retrievable by staff holding audit scope.

### 1.7 HTTP status discipline

| Status | Meaning |
|---|---|
| `200` / `201` | Success |
| `400` | Malformed request |
| `401` | Not authenticated |
| `403` | Authenticated but denied — `PROPERTY_SCOPE_DENIED`, `STEP_UP_REQUIRED` |
| `404` | Resource does not exist **within the caller's scope** |
| `409` | Domain conflict — `INVENTORY_UNAVAILABLE`, `RESERVATION_STATE_INVALID`, `PAYMENT_STATE_INVALID` |
| `422` | Valid shape, unacceptable value — `CANCELLATION_POLICY_VIOLATION`, `REFUND_EXCEEDS_PAYMENT` |
| `429` | Rate limited; includes retry guidance |
| `500` | The system failed in a way it does not understand — **a defect signal, alerted and counted** |

A domain failure that is a normal business outcome — a sold-out room, an invalid transition, a declined card — is a `4xx` with a precise `code`. **It is not a `500`.** A lost allocation race is a correctly functioning system and must not inflate the error rate.

### 1.8 Idempotency

Required on every operation that creates or moves **money, inventory, or a reservation**.

```text
First request with key K  -> execute, persist result, return
Repeat request with key K -> return the ORIGINAL result; no second effect
Same key, different body  -> 409 IDEMPOTENCY_KEY_REUSED
Key expired               -> replay not guaranteed to deduplicate; stated to clients
```

### 1.9 Pagination, filtering, sorting

Bounded page size with a maximum on every list endpoint. Filtering and sorting are **allow-listed per endpoint** — an arbitrary sort on an unindexed column is an availability incident waiting to happen. Sort fields are drawn from a fixed, indexed set.

### 1.10 Audit

Every state-changing endpoint emits the audit events defined in `ADR-0016`. Reads of masked identity fields emit an audit event **on reveal**, not on masked read.

---

## 2. Error code registry

Codes are versioned and **must not be reused with a different meaning**.

### 2.1 Authorization / authentication

| Code | HTTP | Meaning |
|---|---|---|
| `AUTH_REQUIRED` | 401 | No valid session |
| `AUTH_FAILED` | 401 | Invalid credentials |
| `MFA_REQUIRED` | 403 | Step-up authentication required |
| `STEP_UP_REQUIRED` | 403 | Sensitive operation requires re-authentication |
| `PROPERTY_SCOPE_DENIED` | 403 | No grant for the requested property |
| `PERMISSION_DENIED` | 403 | Role lacks the action for the resource |
| `ACCOUNT_SUSPENDED` | 403 | — |
| `IMPERSONATION_DENIED` | 403 | Impersonation is default DENY |

### 2.2 Inventory / reservation

| Code | HTTP | Meaning |
|---|---|---|
| `INVENTORY_UNAVAILABLE` | 409 | No sellable unit; **the expected outcome of a lost race** |
| `ROOM_NOT_SELLABLE` | 409 | Unit is out of order or blocked |
| `ROOM_NOT_OCCUPIABLE` | 409 | Room is dirty, in progress, or otherwise not occupiable |
| `ROOM_STATE_INVALID` | 409 | Illegal room transition |
| `RESERVATION_STATE_INVALID` | 409 | Illegal reservation transition |
| `RESERVATION_HOLD_EXPIRED` | 409 | Hold lapsed before confirmation |
| `OUTSIDE_BOOKING_WINDOW` | 422 | Date outside the configured window |
| `RESTRICTION_VIOLATION` | 422 | Minimum-stay or arrival/departure restriction violated |
| `CANCELLATION_POLICY_VIOLATION` | 422 | Inside a non-refundable window |

### 2.3 Financial

| Code | HTTP | Meaning |
|---|---|---|
| `FOLIO_CLOSED` | 409 | Folio is settled/closed |
| `FOLIO_HAS_UNPOSTED_CHARGES` | 409 | Cannot close with unposted charges |
| `BUSINESS_DATE_REOPEN_NOT_PERMITTED` | 409 | Reopen not authorized or not allowed |
| `PAYMENT_STATE_INVALID` | 409 | Illegal payment transition |
| `PAYMENT_NOT_AUTHORIZED` | 422 | Capture without authorization |
| **`PAYMENT_OUTCOME_UNKNOWN`** | 409 | **`retryable: false`. Result unconfirmed — reconcile, never retry.** |
| `REFUND_EXCEEDS_PAYMENT` | 422 | — |
| `REFUND_REASON_REQUIRED` | 422 | — |
| `REFUND_SOURCE_REQUIRED` | 422 | Refund without an originating payment |
| `REFUND_NOT_APPROVED` | 422 | Submission without approval |
| `REFUND_STATE_INVALID` | 409 | — |
| `TAX_POLICY_NOT_CONFIGURED` | 422 | Tax rate unavailable (`C-04`) |
| `CURRENCY_NOT_SUPPORTED` | 422 | Currency policy undefined (`C-06`) |

### 2.4 Integrity and processing

| Code | HTTP | Meaning |
|---|---|---|
| `IDEMPOTENCY_KEY_REUSED` | 409 | Same key, different request body |
| `VALIDATION_FAILED` | 422 | Field-level errors in `details` |
| `RATE_LIMITED` | 429 | — |
| `CONCURRENCY_CONFLICT` | 409 | Optimistic version conflict; safe to re-read and retry |
| `BUSINESS_RULE_VIOLATION` | 422 | Generic named rule failure |

### 2.5 Internal

| Code | HTTP | Meaning | Detail exposed to client |
|---|---|---|:-:|
| `INTERNAL_ERROR` | 500 | Unhandled failure | **None** — correlation ID only |
| `SERVICE_UNAVAILABLE` | 503 | Dependency unavailable | **None** |

---

## 3. Endpoint groups

Every group below follows the standard field set: method · path · purpose · auth · authorization · request schema · response schema · validation · HTTP statuses · error codes · idempotency · pagination · filtering · sorting · rate limit · audit event · correlation ID · versioning.

Schemas are described structurally here. **Exact field-level schemas are `TBD` and must be produced per endpoint before implementation**; a specification that invents a full JSON schema now would be presenting an unvalidated guess as a contract.

### 3.1 Properties and configuration

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `GET` | `/api/v1/properties` | List properties in scope | Any authenticated, scoped | n/a | — |
| `POST` | `/api/v1/properties` | Create property | Group Manager | Required | `PROPERTY_CREATED` |
| `GET` | `/api/v1/properties/{id}` | Read property | Scoped | n/a | — |
| `PATCH` | `/api/v1/properties/{id}` | Update property | Group Manager / Hotel Manager | Required | `PROPERTY_UPDATED` |
| `GET` | `/api/v1/properties/{id}/config` | Read operating configuration | Scoped | n/a | — |
| `PATCH` | `/api/v1/properties/{id}/config` | Update configuration (versioned) | Group Manager | Required | `CONFIG_CHANGED` |
| `GET` | `/api/v1/properties/{id}/tax-rates` | List tax rates | Scoped | n/a | — |
| `POST` | `/api/v1/properties/{id}/tax-rates` | Create/version tax rate | Compliance/Finance + step-up | Required | `TAX_RATE_CHANGED` |

**Rate limit:** configuration writes are low-volume, step-up protected, and audited.

### 3.2 Room types and rooms

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `GET` | `/api/v1/properties/{id}/room-types` | List room types | Scoped | n/a | — |
| `POST` | `/api/v1/properties/{id}/room-types` | Create room type | Hotel Manager | Required | `ROOM_TYPE_CREATED` |
| `PATCH` | `/api/v1/room-types/{id}` | Update room type | Hotel Manager | Required | `ROOM_TYPE_UPDATED` |
| `GET` | `/api/v1/properties/{id}/rooms` | List rooms (filterable by status axes) | Scoped | n/a | — |
| `POST` | `/api/v1/properties/{id}/rooms` | Create physical room | Hotel Manager | Required | `ROOM_CREATED` |
| `PATCH` | `/api/v1/rooms/{id}/status` | Transition a status axis | Role per axis | Required | `ROOM_*` per `docs/STATE-MACHINES.md` §B |

**Validation:** occupancy within room-type limits; unique room code per property. A status transition touching more than one axis is rejected — the axes are independent by design (`ADR-0014`, `docs/DATA-MODEL.md` §2.7).

### 3.3 Availability and reservations

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `POST` | `/api/v1/properties/{id}/availability` | **Search** availability (may read a replica) | Scoped | No | — |
| `POST` | `/api/v1/reservations` | Create reservation (`DRAFT` → `HELD`) | Reservation Agent | **Required** | `RESERVATION_HELD` |
| `GET` | `/api/v1/reservations/{id}` | Read reservation | Scoped | n/a | — |
| `POST` | `/api/v1/reservations/{id}/confirm` | `HELD` → `CONFIRMED` (atomic with allocation) | Reservation Agent | **Required** | `RESERVATION_CONFIRMED` |
| `POST` | `/api/v1/reservations/{id}/cancel` | Cancel | Reservation Agent | **Required** | `RESERVATION_CANCELLED` |
| `POST` | `/api/v1/reservations/{id}/modifications` | Request modification | Reservation Agent | **Required** | `RESERVATION_MODIFICATION_REQUESTED` |
| `GET` | `/api/v1/properties/{id}/reservations` | List with filters (date range, state, guest) | Scoped | n/a | — |

**Rate limit:** availability search is the highest-volume read and is the primary rate-limit target; it is also an abuse vector for inventory exhaustion and must be limited per identity and per property.

**Critical:** the availability **search** endpoint and the **allocation** path are deliberately different. Search is advisory and may be stale; allocation is authoritative and reads the primary. A client that treats a search result as a guarantee will be corrected at confirm time with `INVENTORY_UNAVAILABLE` — that is the designed outcome, not a bug.

### 3.4 Guests

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `GET` | `/api/v1/properties/{id}/guests` | Search guests | Scoped | n/a | — |
| `POST` | `/api/v1/guests` | Create profile | Front Desk / Reservation Agent | Required | `GUEST_CREATED` |
| `GET` | `/api/v1/guests/{id}` | Read profile — **document number masked** | Scoped | n/a | — |
| `POST` | `/api/v1/guests/{id}/identity/reveal` | **Reveal masked document number** | **Explicitly authorized + step-up** | Required | `IDENTITY_REVEALED` |
| `PUT` | `/api/v1/guests/{id}/identity` | Store identity **fields** (no images) | Front Desk | Required | `IDENTITY_CAPTURED` |
| `POST` | `/api/v1/guests/{id}/merge` | Merge duplicates | Hotel Manager + step-up | Required | `GUEST_MERGED` |

**No endpoint accepts an identity document image.** No such field exists. **No endpoint ever returns a full document number by default.**

**Search requirements** (normalization, ranking, typo tolerance, latency target) are `TBD` (`M-04`); the `details` and ordering behaviour must be specified before implementation rather than improvised.

### 3.5 Front desk

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `POST` | `/api/v1/reservations/{id}/check-in` | `CONFIRMED` → `CHECKED_IN` (folio opened atomically) | Front Desk | **Required** | `GUEST_CHECKED_IN` |
| `POST` | `/api/v1/reservations/{id}/check-out` | `CHECKED_IN` → `CHECKED_OUT` | Front Desk | **Required** | `GUEST_CHECKED_OUT` |
| `POST` | `/api/v1/reservations/{id}/room-transfer` | Transfer rooms (atomic release + allocate) | Front Desk | **Required** | `ROOM_TRANSFERRED` |
| `POST` | `/api/v1/reservations/{id}/extend-stay` | Extend stay | Front Desk | **Required** | `STAY_EXTENDED` |

**Concurrency:** the room-occupiability precondition is evaluated **inside the same transaction** as the reservation transition. Two concurrent check-ins for one room yield exactly one success (`AC-FR-004-02`, test `CON-03`).

### 3.6 Folio, charges, invoices

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `GET` | `/api/v1/folios/{id}` | Read folio and postings | Scoped (Finance, Front Desk) | n/a | — |
| `GET` | `/api/v1/folios/{id}/postings` | List postings, paginated | Scoped | n/a | — |
| `POST` | `/api/v1/folios/{id}/charges` | Post a charge | Finance | **Required** | `CHARGE_POSTED` |
| `POST` | `/api/v1/folios/{id}/adjustments` | Manual adjustment (reason required) | Finance + step-up | **Required** | `ADJUSTMENT_POSTED` |
| `POST` | `/api/v1/folios/{id}/reversals` | Compensating entry referencing a posting | Finance + step-up | **Required** | `POSTING_REVERSED` |
| `GET` | `/api/v1/folios/{id}/balance` | Derived balance | Scoped | n/a | — |
| `POST` | `/api/v1/folios/{id}/invoices` | Issue tax invoice (gapless sequence) | Finance | **Required** | `INVOICE_ISSUED` |
| `GET` | `/api/v1/invoices/{id}` | Read invoice | Scoped | n/a | — |
| `POST` | `/api/v1/invoices/{id}/credit-notes` | Issue credit note | Finance + step-up | **Required** | `CREDIT_NOTE_ISSUED` |
| `POST` | `/api/v1/invoices/{id}/debit-notes` | Issue debit note | Finance + step-up | **Required** | `DEBIT_NOTE_ISSUED` |

**There is no endpoint to update or delete a posted record.** Corrections go through `/reversals`. Invoice edits are impossible by design (`ADR-0009`).

**No ZATCA-specific field, endpoint, or status is exposed.** The invoice endpoint returns business and tax state only; compliance submission state is a separate read-only concern surfaced to the Compliance Officer.

### 3.7 Payments and refunds

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `POST` | `/api/v1/folios/{id}/payments` | Record cash / tokenised card payment | Finance / Front Desk | **Required** | `PAYMENT_*` |
| `POST` | `/api/v1/payments/{id}/capture` | Capture an authorization | Finance | **Required** | `PAYMENT_CAPTURE_REQUESTED` |
| `POST` | `/api/v1/payments/{id}/void` | Void an authorization | Finance + step-up | **Required** | `PAYMENT_VOIDED` |
| `GET` | `/api/v1/payments/{id}` | Read payment (provider references only) | Scoped | n/a | — |
| `POST` | `/api/v1/payments/{id}/refunds` | Request a refund (step-up + reason) | Finance + approval | **Required** | `PAYMENT_REFUND_REQUESTED` |
| `GET` | `/api/v1/reconciliations` | Reconciliation status and backlog | Finance | n/a | — |

**No endpoint accepts card data.** The PMS never stores a PAN, CVV, or track data; no such field exists in any request schema (`ADR-0011`).

**On an unconfirmed payment result**, the endpoint returns `PAYMENT_OUTCOME_UNKNOWN` with `retryable: false`, and the client must not resubmit. Resubmission under a new key risks a duplicate charge; resolution happens through reconciliation.

### 3.8 Night audit

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `GET` | `/api/v1/properties/{id}/night-audit` | Run state and completed steps | Night Auditor / scoped | n/a | — |
| `POST` | `/api/v1/properties/{id}/night-audit/run` | Start a run | Night Auditor | Required | `NIGHT_AUDIT_STARTED` |
| `POST` | `/api/v1/properties/{id}/night-audit/resume` | Resume a failed run | Night Auditor | Required | `NIGHT_AUDIT_RESUMED` |
| `POST` | `/api/v1/properties/{id}/night-audit/steps/{step}/replay` | Replay one step (override required) | Night Auditor + step-up | Required | `NIGHT_AUDIT_STEP_REPLAYED` |
| `POST` | `/api/v1/properties/{id}/business-dates/{date}/reopen` | Reopen a closed business date | **Distinct authorization + approval** | Required | `BUSINESS_DATE_REOPENED` |

**Concurrency:** at most one run per property + business date (`NIGHT_AUDIT_ALREADY_RUNNING`, 409). Replay is a deliberate override, never an automatic consequence of a retry.

### 3.9 Compliance submissions (read-only to the business domain)

| Method | Path | Purpose | Authorization | Idempotency | Audit |
|---|---|---|---|:-:|---|
| `GET` | `/api/v1/invoices/{id}/submission` | Compliance submission state | Compliance Officer | n/a | — |
| `GET` | `/api/v1/integrations/dead-letters` | Dead-letter queue | Compliance Officer | n/a | — |
| `POST` | `/api/v1/integrations/dead-letters/{id}/replay` | Manual replay | Compliance Officer + step-up | Required | `SUBMISSION_REPLAYED` |

**No endpoint exists to force a ZATCA submission synchronously, and no endpoint returns a ZATCA response payload verbatim** — responses may contain tax identifiers, and the raw payload is not needed by a client. This is deliberate data minimization.

### 3.10 Audit (read-only)

| Method | Path | Purpose | Authorization | Audit |
|---|---|---|---|---|
| `GET` | `/api/v1/audit-events` | Query audit trail | Auditor, Compliance Officer, scoped | **The read is itself audited** |

**No create, update, or delete endpoint exists for audit events.** Their absence is the control (`ADR-0016`).

### 3.11 Identity and access

| Method | Path | Purpose | Authorization | Audit |
|---|---|---|---|---|
| `POST` | `/api/v1/auth/login` | Authenticate | Public | `AUTH_SUCCEEDED` / `AUTH_FAILED` |
| `POST` | `/api/v1/auth/logout` | End session | Authenticated | `AUTH_LOGOUT` |
| `POST` | `/api/v1/auth/mfa/verify` | MFA challenge | Authenticated | `MFA_*` |
| `POST` | `/api/v1/auth/step-up` | Re-authenticate for a sensitive action | Authenticated | `STEP_UP_PERFORMED` |
| `GET` | `/api/v1/me` | Current identity, roles, **granted properties** | Authenticated | — |
| `GET` | `/api/v1/users` | List users in scope | Group Manager | — |
| `POST` | `/api/v1/users/{id}/property-scopes` | Grant a property scope | Group Manager; **cannot self-grant** | `SCOPE_GRANTED` |
| `DELETE` | `/api/v1/users/{id}/property-scopes/{propertyId}` | Revoke a scope | Group Manager | `SCOPE_REVOKED` |

**No impersonation endpoint exists.** Impersonation is default DENY (`ADR-0014` §6). Adding one requires explicit approval and an audit design.

#### 3.11.1 Canonical step-up operations

Step-up is required for a **closed** set of operations. "Sensitive" is not a judgement call left to each endpoint; it is this list. Anything not on it does not demand re-authentication.

| Operation | Source | Endpoints |
|---|---|---|
| Refund a payment | `ADR-0014` §6 (separation of duties) | §3.5 `POST /payments/{id}/refunds` |
| Post a configuration change | `SEC-006` | §3.2 `POST /properties/{id}/tax-rates` |
| Grant or revoke a property scope | `ADR-0014` §6 | §3.11 `POST`/`DELETE` `/users/{id}/property-scopes` |
| Reopen the business date | `ADR-0014` §6 | §3.8 night audit |
| Export data | `SEC-006` | (export endpoints, not yet specified) |
| Impersonate another user | `ADR-0014` §6 | **No endpoint exists** — default DENY |
| Reveal a masked identity document | `ADR-0014` §6 | §3.4 `POST /guests/{id}/identity/reveal` |

**Status: specified, not implemented.** `T-004` built the authentication, session, and rate-limiting foundation only. There is no `StepUpGuard`, no `/api/v1/auth/step-up` route, and no MFA challenge handler in the codebase, so `STEP_UP_PERFORMED` is a **reserved audit action with no emitter**. `STEP_UP_REQUIRED` (403) is likewise reserved. Both are retained deliberately so the vocabulary is fixed before an implementer picks a different one.

Until a step-up mechanism exists, the control these rows describe **does not exist at runtime**, and the endpoints that depend on it are unreachable — no route is registered in this stage. `MFA_*` and `SEC-007` remain unresolved.

**This table is not the authority; `docs/SECURITY.md` §6.1 is.** `docs/PRD.md:811` states that `docs/SECURITY.md` is normative, which places this section and `docs/STATE-MACHINES.md` below it. Two consequences are now recorded rather than left open:

- `ADR-0014` §6 is **not** a step-up set. It is a separation-of-duties matrix, and only 1 of its 9 rows (Refund) names step-up. `AC-T-004-05` refers to it as though it were the catalogue; that cross-reference is defective, and the seven above are the correct set. See `docs/SECURITY.md` §12.1.7 "Conflict A".
- `docs/STATE-MACHINES.md` requires step-up for three operations not on this list (reservation cancellation, `OUT_OF_ORDER`, `FORCED_CLOSE`). Under the normative-document rule those three are **not** currently registered Step-up requirements and the set is **not** expanded here. Expanding it is a PM act affecting this table, `docs/SECURITY.md` §6.1, and `PRD.md` `SEC-018` together. See §12.1.7 "Conflict C".

**Nothing above is a registered Decision.** `docs/PRD.md` §15 holds `DR-001` … `DR-014` only; `DR-T004-09` and `DR-T004-10` are unregistered references that define nothing. `docs/SECURITY.md` §12.1 records the substance as established by existing requirements and the registration as an open PM act.

---

## 4. Webhooks

**Direction is inbound only** and exists only if a selected provider supplies one. All webhook specifics are `UNKNOWN` (`B-02`, `B-04`).

Regardless of provider, the following are required (`Prd_Maker.md` §55):

| Requirement | Status |
|---|---|
| Signature verification | **Mechanism `UNKNOWN`** — must be implemented per provider |
| Timestamp tolerance | Required; window `TBD` |
| Replay protection | Required — deduplicate by provider event reference |
| Source validation | Required |
| Idempotency | Required — the state machine is the deduplication point |
| Out-of-order handling | Rejected and routed to reconciliation, **not applied out of order** |
| Response behaviour | Acknowledge quickly; process asynchronously |
| Audit logging | Required |

**"Never trust a callback solely because it came to the correct URL."**

---

## 5. API security controls

| Control | Application |
|---|---|
| CSRF | Required for cookie-authenticated state-changing requests |
| Security headers | On all responses — **exact set `TBD`** |
| Rate limiting | Authentication, availability search, export, payment submission |
| Request size limits | On all bodies, especially guest-identity payloads |
| Content-type enforcement | Reject unexpected content types rather than coercing |
| Input validation | Schema validation at the edge before business logic |
| Authorization | Every endpoint, without exception (`ADR-0014`) |
| Response minimization | Identity masked by default; internal detail never returned |
| Export controls | Role-gated, volume-limited, audited |

## 6. Unresolved before implementation

| Item | Status |
|---|---|
| Public API authentication mechanism (Phase B) | `TBD` |
| Session duration, idle timeout, absolute lifetime | `TBD` (`SEC-008`) |
| Password hashing algorithm | `TBD` (`SEC-007`) |
| Exact security header set | `TBD` |
| Rate limit values | `TBD` — needs `B-05` |
| Field-level request/response schemas | **Not yet specified.** Must be produced per endpoint. A specification that invents full JSON schemas now would present an unvalidated guess as a contract. |
| Search normalization, ranking, typo tolerance | `TBD` (`M-04`) |
| Availability search response shape and freshness indicator | `TBD` |

Each `TBD` above now has a recorded classification and, where a baseline is
defensible, a recommended value in `docs/SECURITY.md` §12.1.1. **A recommendation is
not a resolution**: every row in this table remains `TBD`, and no value was adopted,
defaulted, or written to configuration. `SEC-007` and `SEC-008` are `PROPOSED —
SECURITY`; the rate limits are `BLOCKED — BUSINESS` on `B-05`; the header set is
`PROPOSED — SECURITY`. Implementation stays prohibited until the governance gate is
passed by the accountable owner (`C-10`).
| All OTA endpoints | **Deferred (Phase C). `UNKNOWN`.** |
| All ZATCA endpoints | **`UNKNOWN` (`B-02`)** |
| All payment provider endpoints | **`UNKNOWN` (`B-04`)** |
| Webhook signature schemes | **`UNKNOWN`** |

**This document specifies a contract shape, not an implementation.** No endpoint described here exists, and the field-level schemas that would make it buildable are explicitly marked as not yet written rather than filled with plausible-looking guesses.
