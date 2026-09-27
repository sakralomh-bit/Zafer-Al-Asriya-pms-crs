# ADR-0006: Money Representation — BCMath Decimal
- Status: Accepted
- Date: 2026-09-27
- Deciders: Project Manager (محمد فايز), Lead Product Architect
- Related: D-006, docs/PRD.md, docs/DATA-MODEL.md, docs/API-SPEC.md
## Context
All monetary values in the PMS — prices, taxes, discounts, payments, refunds, folio balances, exchange rates, deposits, pre-authorizations, night-audit postings, invoice totals, ZATCA invoice amounts — must be represented exactly. Binary floating-point (`float`/`double` in PHP, `number` in JavaScript) cannot represent decimal fractions exactly (e.g., `0.1 + 0.2 !== 0.3`). This causes silent rounding errors that accumulate in folios, tax calculations, and reconciliation. Prd Maker §17 (Money rules), §59 (Money/Tax/Rounding), and the code-review/CI rule prohibit float/double for any monetary value.
## Decision
**BCMath for ALL money operations** in PHP. **String serialization** for API transport. **Exact DECIMAL column** in MySQL.

### PHP (Backend)
- Every monetary value is a `Money` value object wrapping a `string` (the canonical decimal representation, e.g., `"1234.56"`) and a `Currency` value object (ISO 4217, e.g., `SAR`).
- All arithmetic uses BCMath functions: `bcadd`, `bcsub`, `bcmul`, `bcdiv`, `bccomp`, `bcmod`, `bcscale`.
- `bcscale` is set globally at boot (e.g., `bcscale(10)`) and **never changed at runtime**. Each operation specifies scale explicitly where needed.
- Rounding: **exactly once at a defined stage** (TBD — blocker C-04). The `Money` object does not round on construction or arithmetic. A `RoundingPolicy` service applies the configured mode (e.g., `HALF_UP`, `BANKERS`) at the **single authorized rounding point** (e.g., invoice total, tax line, payment capture). Frontend never rounds.
- Prohibited: `float`, `double`, `(float)` cast, `number_format` on raw floats, `json_encode` of floats (use `JSON_PRESERVE_ZERO_FRACTION` only for non-money), any math operator (`+`, `-`, `*`, `/`) on money values.
- CI enforcement: PHPStan rule forbids `float`/`double` type hints on money properties/parameters. Custom PHPStan rule flags binary operators on `Money` objects. Pest test asserts `Money::fromFloat(0.1)->add(0.2)->equals('0.3')`.

### MySQL (Storage)
- Column type: `DECIMAL(p, s)` for amounts — an **exact** `DECIMAL` with **explicit, declared** precision and scale, never `FLOAT` or `DOUBLE`, and never a `DECIMAL` without explicit precision/scale. `DECIMAL(p, 6)` is expected for exchange rates.
- Currency: `CHAR(3)` ISO 4217, `NOT NULL`. A default is permitted **only** once the group currency is confirmed.
- **Scale: `TBD` — blocker `C-04`.** The *representation* (exact `DECIMAL`) is decided by this ADR; the *scale* is a finance and tax policy decision and is **not** decided here. `DECIMAL(19, 4)` is a **provisional working value** for internal computation headroom, not an approved figure, and it MUST be confirmed by the accountable finance/compliance owner before any amount column is created. Choosing a scale here would present an unvalidated guess as a financial policy. See `docs/DATA-MODEL.md` §4.3 and `docs/PRD.md` §21.
- No `FLOAT`, `DOUBLE`, `DECIMAL` without explicit precision/scale.
- Generated columns for computed totals (e.g., `folio_balance = SUM(charges) - SUM(payments)`) use `DECIMAL` arithmetic.

### API / Frontend Transport
- **All monetary values serialized as strings** in JSON: `"amount": "1234.5600"`, `"currency": "SAR"`.
- Frontend (Vue/TS) receives strings, renders via `Intl.NumberFormat` with `currency` and digit counts matching the **approved** currency minor unit and storage scale. **Frontend never parses money as `number`**. TypeScript type: `type MoneyString = string & { __brand: 'Money' }`.
- OpenAPI spec: `type: string, format: decimal`, with the digit pattern generated from the approved scale once `C-04` is resolved.

### Rounding Policy (TBD — Blocker C-04)
- **Stage**: Single authorized rounding point per transaction type (e.g., invoice finalization, tax line calculation, payment capture).
- **Mode**: `HALF_UP` (commercial) or `BANKERS` (banker's rounding) — TBD by finance/compliance.
- **Currency**: SAR (primary). Multi-currency TBD (blocker C-06).
- **Tax**: VAT inclusive vs exclusive — TBD (blocker C-04).
- **Marking**: The exact rounding policy is `TBD` in this ADR. The **representation** (BCMath + DECIMAL + string transport) is **decided**. Code review and CI enforce representation; rounding policy is a separate configuration constant.
## Criteria Applied
From Prd Maker §73:
- **Correctness** (decisive): BCMath is a compiled C extension providing deterministic, exact decimal arithmetic. No floating-point category errors possible. String transport eliminates JSON `number` parsing ambiguity.
- **Security**: No precision loss in financial ledger. Immutable posting (compensating entries only) prevents tampering.
- **Testability**: `Money` value object is pure, deterministic, trivially unit-testable. Property-based tests for arithmetic laws (associativity, commutativity, distributivity with rounding).
- **Operational simplicity**: Single representation everywhere. No conversion layer between PHP/MySQL/API.
- **Cost**: BCMath is built into PHP. No license cost.
Criteria that did not drive the decision:
- **Performance**: BCMath is slower than float but negligible at PMS transaction volumes. Not a driver.
- **Reversibility**: Representation is reversible (see below).
## Alternatives Considered
1. **PHP `decimal` extension (php-decimal / php-decimal-obj)**
   - What it is: C extension providing a `Decimal` class with operator overloading.
   - Why rejected: Not in PHP core; requires PECL installation and compilation on every environment. BCMath is bundled and enabled by default in PHP 8.3+. BCMath's functional API is explicit (no operator overloading surprises). `php-decimal` adds a dependency that could drift from PHP version support.
   - Deferred trigger: If BCMath performance becomes a measured bottleneck (unlikely).

2. **MySQL `DECIMAL` only, arithmetic in SQL**
   - What it is: Push all money math to SQL (`SELECT SUM(amount) ...`).
   - Why rejected: Business logic (tax rules, discount tiers, allocation splits) lives in PHP domain services. Splitting arithmetic between PHP and SQL creates inconsistency risk. BCMath in PHP keeps the domain model self-contained and testable without a database.
   - Deferred trigger: Not applicable.

3. **Integer minor units (cents/halalas) in PHP `int`**
   - What it is: Store `123456` for `1234.56 SAR` (2 decimals) or `12345600` (4 decimals).
   - Why rejected: Requires manual scale management per currency (SAR 2 vs 4, JPY 0, BHD 3). Exchange rates need fractional minor units. BCMath with explicit scale per currency is safer and self-documenting. Integer approach is error-prone when currencies change.
   - Deferred trigger: If a regulatory mandate requires integer-only storage (not currently the case).
## Consequences
- All domain services, repositories, and controllers use `Money` value object.
- Database migrations use an exact `DECIMAL` with the **approved** precision and scale (`$table->decimal('amount', $p, $s)`) and `$table->char('currency', 3)`. The scale is blocked on `C-04`.
- API resources cast money to string: `'amount' => (string) $money->getAmount()`.
- Frontend components accept `MoneyString` props. Form inputs use masked input libraries that output strings.
- Night-audit batch uses `Money` for all aggregations.
## Risks
- **BCMath scale leakage**: If `bcscale()` is called without argument, it returns current scale but also sets global scale if argument given. Mitigation: Never call `bcscale()` without argument; always pass explicit scale in every operation. Wrapper enforces this.
- **String comparison**: `bccomp` must be used for comparison, not `===` (which compares string representation, e.g., `"1.00" !== "1.0000"`). `Money` object normalizes on construction (trailing zeros preserved per scale).
- **Currency mismatch**: `Money` operations must assert same currency. Cross-currency requires explicit `ExchangeRate` service.
- **Frontend drift**: Developer uses `parseFloat(moneyString)` accidentally. Mitigation: ESLint rule `no-parsefloat-on-money` (custom), TypeScript branded type, code review checklist.
## Reversibility
Reversible at the value-object boundary. The `Money` class encapsulates BCMath. Swapping to `php-decimal` or integer minor units would change only the `Money` implementation, not its public API (add, subtract, multiply, divide, compare, allocate, format). Storage stays an exact `DECIMAL` (the approved precision and scale, `C-04`). API stays string. Estimated effort: 1–2 weeks. Trigger: BCMath maintenance ends (not expected) or a **measured** performance bottleneck in a hot path (e.g., night-audit aggregation of millions of rows). No such measurement exists today.
## References
- D-006 (BCMath for all money; no float/double)
- Prd Maker §17 (Money rules), §59 (Money/Tax/Rounding)
- Prd Maker §73 (criteria)
- Blocker C-04 (rounding policy TBD), C-06 (currency policy TBD)
- ADR-0002 (Laravel/PHP), ADR-0003 (MySQL DECIMAL), ADR-0004 (frontend string transport), ADR-0009 (financial ledger)