# ADR-0002: Backend Stack — Laravel + PHP 8.3+
- Status: Accepted
- Date: 2026-09-27
- Deciders: Project Manager (محمد فايز), Lead Product Architect
- Related: D-006, docs/PRD.md, docs/ARCHITECTURE.md, docs/TEST-STRATEGY.md
## Context
The project requires a backend framework for a modular monolith PMS with these non-negotiable characteristics: exact decimal arithmetic for all monetary values (prices, taxes, discounts, payments, refunds, folio balances, exchange rates); Arabic/RTL ecosystem maturity for backend-generated documents and validation messages; mature queue/cron system for night-audit batch processing; XML and X.509 certificate library support for the ZATCA/FATOORA e-invoicing adapter; Gulf/KSA developer availability for hiring and support; operational cost efficiency; and testability with static analysis. The decision must be evidence-based per Prd Maker §73.
## Decision
**Laravel 11+ on PHP 8.3+** is the selected backend stack.
- PHP 8.3 provides native BCMath extension for arbitrary-precision decimal arithmetic. BCMath is a compiled C extension; decimal operations are deterministic and exact. No userland library is required.
- Laravel provides: Eloquent ORM with explicit transaction control (`DB::transaction`), row-level locking (`lockForUpdate`), and pessimistic locking; Horizon (Redis-backed queue dashboard, retry policy, delayed jobs, rate limiting, metrics); robust task scheduling (cron) for night-audit batch jobs; mature validation, localization (including RTL locale support), and testing utilities (Pest/PHPUnit, parallel testing, database transactions per test); first-party packages for Sanctum (API tokens), Scout (search), Octane (high-performance server); and a large Gulf/KSA talent pool.
- XML/X.509: PHP's `ext-xml`, `ext-dom`, `ext-openssl`, and `ext-sodium` are core extensions. Laravel's HTTP client and storage abstractions integrate cleanly with ZATCA certificate/CSID workflows.
- Static analysis: PHPStan Level 8+ and Psalm are mature in the Laravel ecosystem; `laravel-phpstan` extension provides framework-aware analysis. BCMath guard tests can be enforced in CI.
- Operational cost: PHP-FPM + Octane (Swoole/RoadRunner) on managed compute is cost-effective at the 10-property scale.
## Criteria Applied
From Prd Maker §73, the following criteria drove the decision:
- **Correctness** (decisive): BCMath is a language-level guarantee for exact decimal arithmetic. JavaScript/TypeScript requires `decimal.js` or similar, which is a discipline requirement, not a language guarantee. A float bug in a userland decimal library is a runtime correctness risk; BCMath eliminates the category.
- **Security**: Mature CSRF, encryption, hashing (argon2id), signed URLs, rate limiting, and headers out of the box. Laravel's security release cadence is predictable.
- **Operational simplicity**: Single framework, unified queue (Horizon), unified scheduler, unified testing, unified localization.
- **Recovery**: Horizon provides job retry, backoff, dead-letter, and manual replay UI. Night-audit batch jobs are idempotent commands with deterministic state machines.
- **Testability**: Pest/PHPUnit with parallel execution, in-memory SQLite for unit tests, transactional database tests, fakes for external services (ZATCA, payment provider).
- **Cost**: PHP talent is widely available in KSA/Gulf. Managed Laravel hosting (Laravel Forge, Vapor, or custom on managed Kubernetes/VMs) is well-understood.
- **Performance**: Octane (Swoole) provides 10x throughput over PHP-FPM for I/O-bound workloads if needed.
- **Reversibility**: The modular monolith (ADR-0001) keeps business logic decoupled from framework internals via domain services and repositories. Migration to another PHP framework or to a different language would require rewriting the HTTP/queue layer but not the domain core.
Criteria that did not drive the decision:
- **Ecosystem hype**: Laravel's popularity was not a criterion; the technical fit for the stated requirements was.
## Alternatives Considered
1. **NestJS + TypeScript (Node.js)**
   - What it is: Opinionated Node.js framework with DI, decorators, and TypeScript-first design.
   - Why rejected: JavaScript `number` is IEEE-754 binary floating-point. Exact decimal arithmetic requires `decimal.js`, `big.js`, or `bignumber.js` — a userland library. This is a **discipline** requirement, not a language guarantee. A single `+` instead of `.plus()` anywhere in the codebase introduces a silent precision bug. CI linting can catch some cases but not all (e.g., numeric literals in JSON parsed by `JSON.parse`). No static analysis tool provides 100% coverage for this class of error. Night-audit batch processing in Node.js lacks a Horizon-equivalent: BullMQ is capable but requires separate Redis, separate dashboard, separate retry/backoff/DLQ configuration, and separate operational runbooks. XML/X.509 certificate handling in Node.js is functional but less mature than PHP's core extensions for the specific ZATCA TLV/UBL binary structures. Gulf/KSA Node.js talent exists but Laravel/PHP talent is deeper for enterprise business applications. Operational cost of Node.js at equivalent throughput is higher due to single-threaded event loop limitations for CPU-bound decimal arithmetic.
   - Deferred trigger: If the team composition shifts to predominantly Node.js expertise and a verified decimal arithmetic discipline process (compiler-enforced, not lint-only) is demonstrated.

2. **Python (FastAPI/Django) + Decimal**
   - What it is: Python's `decimal.Decimal` is in the standard library and provides exact decimal arithmetic with configurable context.
   - Why rejected: Python's `decimal` context is thread-local and mutable; a misconfigured context (precision, rounding) in one request can leak to another unless rigorously isolated. Django ORM and FastAPI/Pydantic integration with `Decimal` is solid, but the queue/cron ecosystem (Celery + Redis/RabbitMQ) is operationally heavier than Horizon (separate broker, separate monitoring, separate beat scheduler). XML/X.509 libraries (`lxml`, `cryptography`) are mature. Gulf/KSA Python backend talent for enterprise PMS is thinner than PHP/Laravel. Operational cost: Python's GIL limits CPU-bound decimal throughput; multiprocessing adds complexity. Static analysis (mypy, pyright) is good but Laravel's PHPStan/Psalm integration is more framework-aware for business logic validation.
   - Deferred trigger: If data-science/ML integration becomes a core PMS requirement (not in Phase A scope).

3. **Go (standard library or frameworks like Gin/Fiber)**
   - What it is: Compiled language with `math/big` for arbitrary-precision decimals.
   - Why rejected: No mature PMS-grade framework with built-in ORM, queue, scheduler, localization, and testing utilities equivalent to Laravel. Building the equivalent of Horizon, Sanctum, Scout, and the validation/localization stack from scratch would be overengineering (Prd Maker §72). Gulf/KSA Go talent for business applications is limited. XML/X.509 handling is verbose. The modular monolith pattern is less idiomatic in Go (packages vs modules).
   - Deferred trigger: If the team has deep Go expertise and the PMS scope shifts to high-throughput microservices (contradicts ADR-0001).
## Consequences
- All monetary values use BCMath (`Money` value object wrapping `BCMath` operations). Float/double is prohibited in code review and CI (see ADR-0006).
- Queue/worker: Laravel Horizon on Redis. Night-audit jobs are scheduled commands with idempotency keys.
- ZATCA adapter: PHP XML/DOM/OpenSSL for certificate handling, TLV encoding, UBL generation.
- Localization: Laravel's translation system with RTL locale (`ar_SA`) as default; direction derived from locale metadata.
- CI pipeline: PHPStan Level 8, Psalm, Pest parallel tests, BCMath guard tests, secret scanning, dependency scanning.
## Risks
- PHP version upgrades: PHP 8.3 is actively supported until 2026-11-26 (security until 2027-11-26). Plan for 8.4 migration.
- Octane/Swoole stability: If Octane is used, Swoole extension compatibility must be verified per PHP minor version.
- Talent retention: Ensure compensation and growth path match market rates for senior Laravel engineers in KSA.
## Reversibility
Reversible at the HTTP/queue layer. The domain core (services, repositories, value objects, state machines) is framework-agnostic by design (ADR-0001). Migration would involve rewriting controllers, jobs, commands, and Horizon configuration. Estimated effort: 3–6 months for a team of 4–6. Trigger: documented evidence that PHP/Laravel cannot meet a new non-functional requirement (e.g., mandated language by regulator, or team restructuring).
## References
- D-006 (stack decision: PHP 8.3+/Laravel, MySQL, Vue/TS, Redis/Horizon, BCMath)
- Prd Maker §17 (Money rules: never use binary floating-point)
- Prd Maker §33 (Error handling/retry/outbox pattern)
- Prd Maker §59 (Money/Tax/Rounding)
- Prd Maker §73 (decision criteria)
- ADR-0005 (background processing), ADR-0006 (money representation), ADR-0010 (ZATCA adapter)