# ADR-0005: Background Processing — Redis + Laravel Horizon + Transactional Outbox
- Status: Accepted
- Date: 2026-09-27
- Deciders: Project Manager (محمد فايز), Lead Product Architect
- Related: D-003, D-005, D-006, docs/PRD.md, docs/ARCHITECTURE.md, docs/STATE-MACHINES.md
## Context
The PMS must integrate with external systems: ZATCA/FATOORA e-invoicing (D-003), payment provider terminals and APIs (D-005), and future OTAs/channel managers (Phase C). Prd Maker §33 and §24 mandate that external integration failure must never take down the core PMS. The pattern must guarantee: exactly-once delivery semantics for financial/compliance events; bounded retries with exponential backoff and jitter; dead-letter queue (DLQ) for manual replay; reconciliation of external state; and audit trail for every attempt. Night-audit batch jobs (folio closure, revenue posting, tax calculation, invoice generation) are long-running, resumable, and must not block the web tier.
## Decision
**Redis + Laravel Horizon + Transactional Outbox** as the **only sanctioned mechanism** for calling any external system.

### Full Chain
1. **Command** (HTTP request, scheduled job, or domain event) initiates a business operation.
2. **DB Transaction** (single InnoDB transaction):
   - Business write (e.g., `invoices` row, `payments` row, `outbox` row).
   - Outbox row: `id (ULID)`, `aggregate_type`, `aggregate_id`, `event_type`, `payload (JSON)`, `status = 'pending'`, `attempts = 0`, `next_retry_at`, `created_at`.
3. **Transaction commits** — business state and outbox row are atomic.
4. **Horizon worker** (separate process, supervised) polls `outbox` where `status = 'pending' AND next_retry_at <= NOW()` ordered by `created_at`.
5. **Worker processes**:
   - Marks row `status = 'processing'`, increments `attempts`.
   - Calls external API (ZATCA, payment provider, OTA) with idempotency key from payload.
   - On **success**: marks `status = 'completed'`, `completed_at = NOW()`, writes response to `response_payload`, emits domain event for reconciliation.
   - On **retryable error** (network timeout, 5xx, rate limit 429): calculates backoff = `min(base * 2^attempts + jitter, max_backoff)`, updates `next_retry_at`, `status = 'pending'`.
   - On **non-retryable error** (4xx except 429, validation, auth failure, unknown payment outcome): marks `status = 'dead_letter'`, `error_code`, `error_message`, triggers alert.
6. **Reconciliation**: A scheduled job compares PMS state (e.g., `invoices.zatca_status`) with external state (ZATCA portal, payment provider webhook) and repairs drift.

### Retry Policy (configurable per integration)
| Parameter | Value | Source |
|---|---|---|
| Max attempts | 5 (ZATCA), 3 (payments), 10 (OTA) | TBD per provider SLA |
| Base backoff | 60 seconds | |
| Max backoff | 3600 seconds (1 hour) | |
| Jitter | ±25% of calculated backoff | |
| Retryable errors | `ETIMEDOUT`, `ECONNREFUSED`, 5xx, 429 | |
| Non-retryable errors | 4xx (except 429), unknown payment outcome, signature verification failure | Prd Maker §33: "Never retry blindly after an unknown payment or financial outcome" |

### Dead Letter Queue (DLQ)
- Separate table `outbox_dlq` (archive of dead-letter rows with full context).
- Admin UI: list, filter, inspect payload/response, **manual replay** (resets `status = 'pending'`, `attempts = 0`, `next_retry_at = NOW()`).
- Alerting: DLQ growth rate > threshold → PagerDuty/Slack.

### Night-Audit Batch
- Implemented as a Horizon job chain (or Laravel Batch) with explicit checkpoints.
- Each property processed in a separate job; failure of one property does not block others.
- Job is idempotent: re-running with same `business_date` + `property_id` is safe (state machine guards).
- Progress persisted in `night_audit_runs` table (status, started_at, completed_at, properties_total, properties_completed, errors_json).

### Hard Rule
**Integration failure must never take down the core PMS.** The web tier (HTTP controllers) never calls external APIs directly. All external calls go through the outbox. If Redis/Horizon is down, the outbox accumulates; workers catch up when restored. No synchronous external call in the request path.
## Criteria Applied
From Prd Maker §73:
- **Correctness** (decisive): Transactional outbox guarantees the business write and the intent to notify are atomic. No "phantom" events (business succeeded, event lost) and no "ghost" events (event sent, business rolled back).
- **Recovery**: Bounded retries + DLQ + manual replay + reconciliation = full recovery path. Night-audit resumability via checkpoints.
- **Operational simplicity**: One queue system (Horizon) for all async work. One monitoring dashboard. One retry/DLQ/replay model.
- **Security**: Outbox payloads never contain PAN/CVV (D-005). Identity document numbers encrypted (D-004). Payloads signed for webhook verification.
- **Testability**: Fake Horizon worker in tests; outbox rows asserted; fake external adapters for ZATCA/payment/OTA.
- **Cost**: Redis managed instance included in D-007 hosting. Horizon is open-source (Laravel first-party).
Criteria that did not drive the decision:
- **Performance**: Horizon throughput is sufficient for 10 properties. Not a driver.
## Alternatives Considered
1. **Direct HTTP calls from controllers with try/catch**
   - What it is: Controller calls payment/ZATCA API synchronously, rolls back on failure.
   - Why rejected: External latency (2–30s) blocks web workers. Timeout or crash leaves business state inconsistent. No retry, no DLQ, no reconciliation. Violates Prd Maker §33 and D-006 standing rule.
2. **RabbitMQ / Kafka + separate consumer services**
   - What it is: Message broker with dedicated consumer deployments.
   - Why rejected: Overengineering (Prd Maker §72) for 10 properties. Adds infrastructure (broker cluster, consumer deployments, schema registry), operational complexity, and a second failure domain. Horizon on Redis provides sufficient durability, ordering, and observability for this scale.
   - Deferred trigger: Documented throughput evidence showing Redis/Horizon cannot handle peak event volume, or regulatory requirement for message broker audit trail.
3. **Database polling without Redis (Laravel scheduler polls `outbox` directly)**
   - What it is: Cron job every minute runs a command that processes pending outbox rows.
   - Why rejected: Polling interval adds latency (up to 1 minute). No real-time dashboard, no priority queues, no rate limiting per integration, no horizontal scaling of workers. Horizon provides all of these with negligible operational cost.
   - Deferred trigger: If Redis is prohibited by policy (not the case per D-007).
## Consequences
- All external integrations (ZATCA, payment, future OTA) **must** implement an outbox publisher. No exceptions.
- Horizon configuration: `horizon.php` defines queues (`outbox`, `night-audit`, `notifications`, `reports`), balance strategies, max processes, timeout, memory limit.
- Monitoring: Horizon metrics (jobs/s, failed, runtime, queue length) exported to Prometheus/Grafana. Alert on: queue depth > threshold, failure rate > threshold, DLQ growth.
- CI: Integration tests use fake HTTP client; outbox assertions verify payload shape and retry behavior.
## Risks
- **Redis outage**: Outbox accumulates in MySQL. Workers pause. On Redis recovery, workers drain backlog. Mitigation: Managed Redis with multi-AZ, memory alerting at 70%.
- **Duplicate external calls**: If worker crashes after API success but before marking `completed`, the next retry sends a duplicate. Mitigation: **Idempotency keys on every external call** (required by payment/ZATCA/OTA APIs). Outbox payload includes idempotency key derived from `aggregate_type:aggregate_id:event_type:attempt`.
- **Payload schema evolution**: Outbox `payload` is JSON. Version field (`payload_version`) required. Workers handle multiple versions.
- **Night-audit long runtime**: If a property's audit exceeds Horizon timeout (default 3600s), job fails. Mitigation: Chunk by property, checkpoint per property, configurable timeout per job type.
## Reversibility
Reversible. To replace Horizon: (1) implement new queue consumer (RabbitMQ/Kafka/SQS), (2) keep outbox table schema, (3) dual-write to new broker during transition, (4) cut over workers, (5) decommission Horizon. The outbox pattern is broker-agnostic. Trigger: documented evidence that Horizon cannot meet SLAs or Redis is unavailable.
## References
- D-003 (ZATCA P0), D-005 (payments), D-006 (Redis + Horizon)
- Prd Maker §24 (integration requirements), §33 (error handling/retry/reconciliation), §73 (criteria)
- ADR-0002 (Laravel/Horizon), ADR-0010 (ZATCA adapter), ADR-0011 (payment abstraction), ADR-0016 (audit trail)