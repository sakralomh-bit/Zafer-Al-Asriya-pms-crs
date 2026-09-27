# ADR-0007: Tenancy and Property Isolation
- Status: Accepted
- Date: 2026-09-27
- Deciders: Project Manager (محمد فايز), Lead Product Architect
- Related: D-001, D-006, D-008, docs/PRD.md, docs/ARCHITECTURE.md, docs/DATA-MODEL.md, docs/SECURITY.md
## Context
D-001 decides: **Single tenant** — one organization ("Zafer Al-Asriya"), initially 10 properties, one deployment. **No** SaaS multi-tenancy, **no** database-per-tenant, **no** schema-per-tenant in v1.0. Property-level authorization and data isolation enforced server-side. Prd Maker §53 rule: "multi-property does not automatically mean multi-tenant." The domain model must remain extensible to future SaaS multi-tenancy (organization dimension) without rewriting the business core.
## Decision
**Single-tenant, multi-property architecture with future-SaaS-safe design.**

### Current Model (v1.0)
- **Organization**: Single row in `organizations` (name, commercial_registration, vat_number, timezone, default_currency, tax_profile_id). `id` is a stable internal surrogate key (ULID/UUID).
- **Properties**: 1–N rows in `properties` (`organization_id`, `name`, `code`, `timezone`, `currency`, `address`, `contact`, `settings_json`). Every property belongs to the single organization.
- **Property-scoped tables**: Every table that stores property-specific data **must** carry `property_id` (foreign key to `properties.id`). Examples: `room_types`, `rooms`, `inventory_units`, `reservations`, `guests`, `folios`, `charges`, `payments`, `invoices`, `housekeeping_tasks`, `night_audit_runs`, `outbox`, `audit_logs`, `user_property_scopes`.
- **Authorization**: `user_property_scopes` (many-to-many: `user_id`, `property_id`, `role_id`). Server-side enforcement: every query/controller/action resolves the current user's authorized `property_id` set and scopes the query. **No query executes without a property scope** (except organization-level admin).
- **Cross-property access**: Only through an explicit `CrossPropertyScopeService` that requires an elevated role (e.g., `Group Manager`, `Revenue Manager`). This service is the **only** code path that queries across `property_id` without a single-property filter. All other services/repositories assume a single `property_id` context.

### Future-SaaS-Safe Design (No Multi-Tenancy Built Now)
The following constraints ensure that adding an `organization_id` dimension later is a **data migration + scope extension**, not a business-logic rewrite:

1. **Stable internal surrogate primary keys**: All entities use ULID/UUID (`id`) as primary key. No natural keys (property code, room number, guest email) as PK. No composite PKs that include `property_id`.
2. **Every property-scoped table carries `property_id`**: This is already required for v1.0 isolation. Adding `organization_id` later is a nullable column + backfill + index, not a schema redesign.
3. **Business logic never assumes the absence of an organization dimension**: 
   - Repository methods accept `property_id` (required) and optionally `organization_id` (default: current organization from context).
   - No `WHERE organization_id = 1` hard-coded. Organization context comes from the authenticated user's organization (single in v1.0).
   - Domain services (inventory, reservation, folio) are written against `property_id` scope. They do not reference `organizations` table.
4. **Cross-property access only through explicit scope service**: The `CrossPropertyScopeService` is the single seam. In v1.0 it filters by the single organization's properties. In future SaaS, it adds `organization_id` to the filter. No other code crosses property boundaries.
5. **Documented touch points for future `organization_id` addition**:
   - **Schema**: Add `organization_id` (FK to `organizations`) to `properties`, `users`, `roles`, `permissions`, `audit_logs`, `outbox`, `night_audit_runs`, `reports`, `settings`. Backfill from the single organization.
   - **Auth**: `User` gains `organization_id`. Login resolves organization (subdomain, header, or SSO claim).
   - **Scope resolution**: `CurrentScope` service returns `{ organization_id, property_ids[] }` instead of just `property_ids[]`.
   - **Cross-property service**: Adds `organization_id` to all queries.
   - **Tenant-aware jobs**: Horizon jobs receive `organization_id` in payload.
   - **Billing/usage**: New module for per-organization metering.
   - **Configuration**: `organizations.settings_json` for per-tenant feature flags.
   - **Total estimated touch points**: ~15 files (migrations, auth, scope service, cross-property service, job base class, config). **Zero changes to inventory, reservation, folio, payment, housekeeping, night-audit, ZATCA, reporting domain logic.**

### What Is NOT Built in v1.0
- Database-per-tenant (separate MySQL instances/schemas).
- Schema-per-tenant (shared DB, separate schema per org).
- Row-level security (RLS) policies.
- Tenant-aware routing / subdomain middleware (beyond single-org).
- Per-tenant customization (themes, workflows, modules).
- Organization-level billing / subscription management.
- Data residency per organization (D-007 covers residency for the single deployment).

## Criteria Applied
From Prd Maker §73:
- **Correctness** (decisive): Property-level isolation is enforced at the query layer (server-side). No data leak across properties. The future-SaaS constraints prevent coupling that would make multi-tenancy a rewrite.
- **Security**: Deny-by-default, backend enforcement, UI visibility not a security boundary (Prd Maker §14). Property scope resolved from authenticated user, not request parameter.
- **Operational simplicity**: Single database, single deployment, single backup, single migration history. No tenant provisioning, no tenant onboarding flow, no tenant isolation testing.
- **Cost**: No multi-tenancy infrastructure (tenant routers, per-tenant configs, per-tenant monitoring).
- **Reversibility**: The design explicitly enables future multi-tenancy (see Reversibility).
Criteria that did not drive the decision:
- **Performance**: Single-tenant multi-property has no performance penalty at 10 properties.
## Alternatives Considered
1. **Database-per-tenant (separate MySQL instance per organization)**
   - What it is: Each organization gets its own managed MySQL instance. Shared application code connects to the correct DB based on tenant.
   - Why rejected: D-001 explicitly decides single tenant. Building DB-per-tenant for one organization is overengineering (Prd Maker §72). Operational cost: 10x managed DB instances, 10x backup policies, 10x connection pools, schema migration coordination across instances.
   - Deferred trigger: If the product becomes a SaaS with >50 organizations and documented evidence shows shared-DB contention or regulatory data-residency requirements per organization.

2. **Schema-per-tenant (shared MySQL, separate schema per org)**
   - What it is: One MySQL instance, each organization gets its own schema (`org_1`, `org_2`). Application sets `SET search_path` or connects to schema.
   - Why rejected: Same as above — overengineering for single tenant. MySQL schema switching is less ergonomic than PostgreSQL. Migration tooling (Laravel) assumes single schema. Cross-schema reporting is painful.
   - Deferred trigger: Same as database-per-tenant.

3. **Row-level security (RLS) policies in MySQL**
   - What it is: MySQL 8.0 does not support RLS natively (PostgreSQL does). Would require views or application-layer enforcement anyway.
   - Why rejected: Application-layer enforcement (current decision) is portable, testable, and works on any RDBMS. RLS would lock us to a specific MySQL feature that doesn't exist.
   - Deferred trigger: If MySQL adds RLS and a security audit mandates it.

4. **No `property_id` on tables (implicit scope via session)**
   - What it is: Tables don't have `property_id`; scope is enforced only by application logic filtering by a session variable.
   - Why rejected: **Unacceptable risk.** A single missed `WHERE` clause leaks data across properties. `property_id` on every row makes isolation **visible in the schema**, enforceable by FK, testable by query inspection, and auditable. Prd Maker §14: "tenant/property scope enforced server-side."
## Consequences
- **Migration cost for future SaaS**: ~15 files, 1–2 weeks. No domain logic rewrite.
- **Query discipline**: Every repository method must accept `property_id`. Static analysis rule: `PropertyScopeRequired` attribute on all repository methods; PHPStan flags missing `property_id` parameter.
- **Testing**: Every integration test creates a second property and asserts cross-property isolation (query returns only scoped property's data).
- **Admin UI**: Property switcher in header (for users with multi-property scope). Property context passed via header `X-Property-ID` or route parameter, validated against user's scopes.
## Risks
- **Scope leakage**: A repository method forgets `property_id` filter. Mitigation: Base repository trait enforces `applyPropertyScope(Builder $q): Builder`; all queries go through it. CI architecture test scans for raw `DB::table()` without scope.
- **Cross-property service misuse**: Developers call `CrossPropertyScopeService` for single-property queries. Mitigation: Service is `final`, methods named `getAcrossProperties(...)`, code review gate.
- **Organization context confusion**: In v1.0, `organization_id` is implicit (single row). Future developers may hard-code `1`. Mitigation: `OrganizationContext::current()` returns the single organization; all code uses it. No literals.
## Reversibility
**Designed for forward compatibility, not reversal.** The current architecture is the minimal viable single-tenant model. Adding multi-tenancy is the intended evolution path. The "reversal" would be removing `property_id` and collapsing to a single-property system — not planned. The cost to add `organization_id` later is documented above (~15 files, 1–2 weeks). Trigger: Business decision to launch SaaS multi-tenancy.
## References
- D-001 (single tenant, 10 properties, property-level isolation, future-SaaS-safe)
- D-006 (modular monolith, MySQL)
- D-008 (greenfield, import-friendly)
- Prd Maker §14 (RBAC/ABAC, property scope enforced server-side), §53 (multi-property ≠ multi-tenant)
- Prd Maker §73 (criteria)
- ADR-0001 (modular monolith), ADR-0014 (authorization model), ADR-0017 (module boundaries)