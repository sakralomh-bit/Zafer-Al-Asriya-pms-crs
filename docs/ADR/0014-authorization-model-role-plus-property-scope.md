# ADR-0014: Authorization Model — Role plus Property Scope plus Policy

- **Status:** Accepted
- **Date:** 2026-09-27
- **Deciders:** Project Manager (محمد فايز), Lead Product Architect
- **Related:** `D-001`, `BUS-009`, `ADR-0007`, `ADR-0016`, `ADR-0019`, `docs/SECURITY.md` §3

## Context

`D-001` defines the tenancy: one organization, initially 10 properties, one deployment, with **property-level authorization and data isolation** and a named list of twelve roles. Each role's scope is stated: Group Manager across all properties, Hotel Manager for assigned properties, Front Desk Agent for one assigned property, Reservation Agent for authorized properties, and so on.

`Prd_Maker.md` §14 requires a permission formula, a least-privilege default, and explicit rules: **deny by default, explicit grants, backend enforcement, tenant/property scope enforced server-side, and UI visibility is not a security boundary**. It also requires that sensitive operations get stronger controls — MFA, step-up authentication, approval workflow, audit event, dual control — and states plainly: *"Never rely on hidden buttons as authorization."*

`Prd_Maker.md` §54 names **tenant breakout** as a distinct threat class. In a single-tenant system the equivalent threat is **property breakout**: a user in Property A reading or modifying Property B's guests, folios, or financial records. With 10 properties holding guest personal data and financial records, this is the highest-likelihood authorization failure in the system.

## Decision

### 1. The permission formula

```text
Allow = Identity × Role × Resource × Action × Scope × Policy
```

Every one of the six factors must evaluate true. A missing factor is a denial, not a pass. The formula is evaluated server-side on **every** request, before any business logic executes.

### 2. Scope is a grant record, not a filter, and not a flag

Access to a property is expressed as an **explicit grant** in `user_property_scope`. Absence of a grant means **no access**.

Group Manager's access to all 10 properties is modeled as **10 explicit grant records**, not an `is_superadmin` flag. This is a deliberate trade: more rows, in exchange for a model with no unauditable bypass. A superuser flag is a permanent hole in the scope model that bypasses every future check, cannot be audited meaningfully, and makes the property-breakout test vacuous — because the superuser path would pass it by construction.

### 3. Deny, do not filter

A request for a property the caller is not granted receives an explicit **denial** (`PROPERTY_SCOPE_DENIED`), never an empty result set.

Two reasons, and the second is the important one:

1. **Information disclosure.** An empty result tells the caller the property exists. A denial does not.
2. **Testability.** An empty result makes a scope bug invisible in testing — "no rooms returned" looks identical to "correctly denied". A denial is assertable.

### 4. Backend enforcement, always

Authorization is enforced server-side on every request. The client application's visibility rules are a **user-experience feature, not a control**. A hidden button is not authorization. A hidden menu is not authorization. Hiding a field is not authorization.

Concretely: the API must deny an out-of-scope request whether it came from the application, from a `curl` command, or from a crafted request.

### 5. The role matrix

| Role | Scope | Core permitted | Explicitly NOT permitted |
|---|---|---|---|
| **Group Manager** | All 10 properties (explicit grants) | Group-wide configuration, consolidated reporting, rate and policy approval, business-date reopen approval, role and scope administration | Nothing by role — but subject to separation of duties (§6); cannot grant themselves additional scope |
| **Hotel Manager** | Assigned property/properties | Full operational and financial authority for those properties; room blocks; rate overrides with approval; out-of-order decisions; Night Auditor/Housekeeping supervision | Other properties; granting own scope; refunds outside approval thresholds |
| **Front Desk Agent** | Assigned property | Check-in, check-out, room transfer, stay extension, guest lookup, folio view and charge entry | Refunds, rate configuration, night audit, scope administration, configuration |
| **Reservation Agent** | Authorized properties | Availability search, reservation create/modify/cancel, holds, no-show marking, guest lookup | Check-in/out, refunds, configuration, financial posting |
| **Housekeeping** | Assigned property | Room housekeeping status, task assignment and completion, inspection, out-of-order raising | **Guest identity data**, folio and financial data, reservations, rates |
| **Finance** | Authorized properties | Postings, payments, refunds (step-up + approval), settlement, reconciliation, invoice and tax work, financial reporting | Check-in/out, rate configuration, scope administration, night audit execution |
| **Night Auditor** | Authorized properties | Run/resume night audit, mark no-shows, raise out-of-order, reopen **with approval** | Refunds, rate configuration, scope changes, own approval of a reopen |
| **POS Cashier** | Assigned property | **Phase C — deferred.** Role defined so the model is complete; no Phase A permissions | — |
| **Revenue Manager** | Authorized properties | Rates, rate plans, restrictions, availability management, forecasting | Refunds, scope changes, check-in, financial posting |
| **Compliance Officer** | Authorized properties | Invoice and compliance submission review, audit trail review, tax configuration review, DLQ replay authorization | Modify posted financial records; ordinary booking operations; alter audit records |
| **Auditor** | Read-only scope | Read everything within scope, including the audit trail | **Any write at all**, including to the audit trail. No impersonation |
| **Support** | Explicitly scoped, **time-bound** | Diagnose within scope; every access audited; no persistent grant | Anything outside scope; no grant without a named approver and an expiry |

**Denial semantics worth stating explicitly:** Finance cannot check a guest in; an Auditor cannot write; Housekeeping cannot see a guest's document number; a Reservation Agent cannot take a payment. These are the cross-role boundaries a hotel group actually relies on, and each is a test case.

### 6. Separation of duties and step-up

| Action | Additional control |
|---|---|
| Grant or revoke a scope | Cannot grant to oneself; requires an independent approver; audited |
| Refund | Step-up authentication; approval threshold; reason code; audited |
| Business-date reopen | Distinct authorization (likely Night Auditor **with** Finance approval); audited |
| Manual rate override | Above a threshold: approval workflow; audited |
| Impersonation | **Default DENY.** Where permitted at all: time-bound, reason recorded, every action taken as the impersonated user is attributed to the impersonated identity **and** flagged to the impersonator; audited |
| Bulk export | Role-gated, volume-limited, audited |
| Configuration change | Permission-controlled, validated, versioned, recoverable, audited |
| Audit trail access | Scoped and **itself audited** |

The impersonation rule is the one most often specified as "allowed for admins" and most often regretted. Default DENY is the decision here.

### 7. Deny-by-default must be provable

Deny-by-default is a claim that is only meaningful if it is tested. The test is the **property breakout** case: for every role, and for every resource, a user without a grant for property B receives `PROPERTY_SCOPE_DENIED` for property B — across **all** 10 properties, not a sample.

### 8. Data sensitivity in the model

Authorization is not uniform across data. Sensitive classes carry extra restrictions independent of role:

| Class | Additional control |
|---|---|
| Identity-document fields | Masked by default; step-up + audit to reveal (`ADR-0012`) |
| Card data | **Never stored**; never returned by any endpoint (`ADR-0011`) |
| Financial records | Export restricted; refunds step-up; no deletion |
| Audit records | Read-only to all; append-only to the system; no user may alter |
| Support access | Explicitly scoped, time-bound, fully audited |

## Criteria Applied

Security (least privilege, deny by default, property breakout), correctness (a denied request is observable and testable), compliance (auditability of privileged access), operational simplicity (12 named roles, not a permission explosion), testability (the matrix is a test oracle).

## Alternatives Considered

| Alternative | Disposition | Objective reason |
|---|---|---|
| `is_superadmin` flag for Group Manager | **Rejected** | An unauditable permanent bypass of the scope model; makes the breakout test vacuous. Explicit grants are more rows and are provably correct. |
| Role-only, no property scope | **Rejected** | Every role except Group Manager is property-limited. Role alone would give a Front Desk Agent all 10 properties. |
| Return an empty result for out-of-scope requests | **Rejected** | Leaks property existence and makes scope bugs invisible in testing. |
| Reliant on UI hiding for authorization | **Rejected** | `Prd_Maker.md` §14. UI visibility is not a security boundary. |
| Row-level security in the database as the control | Deferred | A valuable **defence in depth** layer, not a substitute. Application enforcement must exist regardless, because the ORM and the reporting queries must also be correct. Worth adding if the deployment allows it. |
| Fine-grained permission-per-action configuration | Rejected for v1.0 | A general permission engine before the 12 named roles are proven produces an untestable matrix. Revisit if a role genuinely cannot be expressed. |
| Impersonation enabled for administrators | **Rejected** | Default DENY. When it is ever permitted, it is time-bound, reason-recorded, and fully audited. |

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Property breakout via a missed scope check on a new endpoint | Medium | **Critical** | Scope enforcement in a single reusable path; breakout test across all roles and all 10 properties; review rule |
| A developer adds an endpoint without a scope check | Medium | **Critical** | Mandatory authorization declaration per endpoint; test asserts a denial without a grant |
| An empty result is used for an out-of-scope request | Low | Medium | Review rule; explicit error code |
| Group Manager is modelled as a superuser after all | Low | **Critical** | Explicit grant records; no superuser field exists in the schema |
| Support scope is granted and not expired | Medium | High | Time-bound grants; expiry; every access audited |
| A user grants themselves scope | Low | **Critical** | Separation of duties; cannot self-grant; audited |
| Impersonation is added "just for support" | Medium | **Critical** | Default DENY recorded here; requires explicit approval and an audit design |
| Auditors gain write access through a role mix-up | Low | High | Auditor role is read-only by definition; a write attempt is denied and tested |
| Role × resource × scope matrix becomes untestable at scale | Low | Medium | 12 fixed roles; generated matrix tests |

## Reversibility

**Moderate.** The grant model is portable and the matrix is data-driven. The irreversible risk is a **breach that already happened** — a property breakout is a privacy incident with a legal dimension, not a bug to revert. That asymmetry is why the breakout test is a release gate across all roles and all properties, and why deny-by-default is specified before any endpoint exists.

## References

`D-001`, `D-004`, `D-005`, `BUS-009`, `BUS-014`, `ADR-0007`, `ADR-0011`, `ADR-0012`, `ADR-0016`, `ADR-0019`, `ADR-0021`, `Prd_Maker.md` §14, §15, §20.6, §32, §53, §54, `docs/SECURITY.md` §3, `docs/DATA-MODEL.md` §2.1, `docs/STATE-MACHINES.md` §J.5, `docs/TEST-STRATEGY.md` §5.
