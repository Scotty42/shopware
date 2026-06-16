# Order Integration Plugin — Grooming Overview

**Purpose:** High-level orientation on goals, what has been spiked/built, and what is open.

---

## 1. Why this plugin exists — the problem

Shopware ships two HTTP APIs:

| API | Problem for us |
|---|---|
| **Store API** | Designed for human-paced storefront traffic — wrong traffic plane for service-to-service load |
| **Admin API** | Runs on the same PHP-FPM pool as the storefront, goes through the full Shopware DAL on every call — saturates the shop under order-volume read/write traffic |

Neither is suitable as a production integration surface for a D2C scenario with an ERP or OMS. We need a domain-shaped, service-to-service REST API that speaks to Shopware's **internal PHP services directly** — no extra HTTP hop, correct Shopware pricing/tax/order/shipement/ state machine behaviour, versionable contract.

---

## 2. Solution — the OrderIntegration Plugin (Spike)

A **Shopware plugin** (`/custom/plugins/OrderIntegration`) that:

- Registers its own routes under `/api/order-integration/v1/...`
- Calls Shopware's internal services (`CartService`, `OrderPersister`, `OrderConverter`, `StateMachineRegistry`, `EntityRepository`) in-process
- Exposes a clean, domain-shaped REST API with RFC 9457 error bodies, ETag/If-Match, idempotency, cursor pagination
- Runs alongside the existing Shopware instance — no separate container needed for the core path

### Deployment topology

```
shopware-fe (Nuxt 3)  ──HTTP LAN──►  shopware-be (Apache + PHP-FPM + Shopware)
                                              │
                                     OrderIntegration Plugin
                                              │
                                  (optional) order-integration-db (PostgreSQL 17)
                                       read projection + write queue
```

---

## 3. Architecture decision record (Spike)

Four options were evaluated (see `docs/order-api-concept.md` and `docs/spike-order-creation.md`):

| Option | Description | Decision |
|---|---|---|
| A | Callers hit Shopware Admin API directly | Rejected — not suitable for D2C load |
| B | Standalone facade in front of Admin API | Viable stepping stone; overkill to operate separately |
| C | Standalone facade + CQRS read projection + write queue | The **infra/load axis** — built **inside the plugin** (T13, env-gated, off by default) |
| D | **Plugin inside Shopware** using internal services | **Chosen for the endpoint layer** — lowest latency, in-process, uses Shopware's own checkout code |

D (the plugin) is the endpoint implementation. C is the infra evolution on top of it. They are orthogonal axes that coexist.

**Order creation spike conclusion** (`docs/spike-order-creation.md`): the only path that gives correct pricing, taxes, promotions, and state-machine events is using Shopware's own `CartService` + `OrderConverter` + `OrderPersister` in-process — exactly what the plugin does.

---

## 4. What has already been built and merged

### 4a. Core API (Phases 1–3 + Hardening, all in `main`)

**Orders**

| Endpoint | Done |
|---|---|
| `GET /v1/orders` — list with cursor pagination, filters, sort | ✅ |
| `POST /v1/orders` — create via CartService + OrderPersister | ✅ |
| `GET /v1/orders/{id}` | ✅ |
| `PATCH /v1/orders/{id}` — update mutable fields | ✅ |
| `DELETE /v1/orders/{id}` — soft cancel | ✅ |

**Status transitions**

| Endpoint | Done |
|---|---|
| `PUT /v1/orders/{id}/status` | ✅ |
| `PUT /v1/orders/{id}/payment-status` | ✅ |
| `PUT /v1/orders/{id}/delivery-status` | ✅ |

**Deliveries sub-resource**

| Endpoint | Done |
|---|---|
| List, get, create split shipment, patch tracking, status transition | ✅ |
| ETag on `GET /deliveries/{id}` and `POST /deliveries` | ✅ |
| `If-Match` required on `PATCH /deliveries/{id}` and `PUT /deliveries/{id}/status` | ✅ |

**Cross-cutting hardening (T1–T11)**

- Idempotency-Key enforcement on all mutating endpoints (400 missing, 409 reuse conflict)
- If-Match / ETag optimistic concurrency on orders and deliveries (412 stale, 428 missing)
- Cursor-keyset pagination, `sort`, `salesChannelId`, `customerId` UUID normalization
- Soft-delete correctness (409 on illegal transition, 204 on re-cancel)
- POST validation (addresses + customer context)
- RFC 9457 `application/problem+json` on all error paths
- PHPUnit unit test suite (runs in CI on PHP 8.2/8.3/8.4); bash integration test suite

**Concurrency hardening (fix/concurrency-guards + test/concurrency-coverage)**

Six TOCTOU and data-race issues identified and fixed:

| Fix | Detail |
|---|---|
| Write-lock per order | `FlockStore`-backed `symfony/lock` mutex (`order.write:{id}`, 10 s TTL) on every mutating endpoint — `PATCH`, `DELETE`, all three status transitions. Swap to `RedisStore` / `DoctrineDbalStore` for multi-server. |
| Idempotency per-key mutex | Second lock (`idempotency:{key}`, 30 s TTL) serialises the `begin()`/`complete()` window in `HandlesIdempotency`, closing the duplicate-execution race. |
| Delivery If-Match | `EnforcesIfMatch` trait wired into `DeliveryController`; `deliveryEtagFor()` uses same `sha1(id|versionId|updatedAt)` formula as order ETag. All delivery mutations return a fresh ETag. |
| Atomic order patch | `OrderPatchService` collapsed to a single `orderRepository->update()` call with inline nested addresses — billing and shipping embedded, no separate repo calls, no dead `$orderAddressRepository` / `$tagRepository` dependencies. |
| Projection on delivery transitions | `OrderProjectionSubscriber` now also subscribes to `order_delivery.written`; delivery state transitions refresh the parent order in the CQRS read projection. |
| ETag versionId note | `OrderMapper::etagFor()` documents that Shopware's `versionId` for live orders is always `Defaults::LIVE_VERSION` (fixed UUID) — ETag uniqueness depends entirely on `updatedAt` microsecond precision. |

PHPUnit coverage added for all six areas (5 new test suites). Codecov upload added to CI (PHP 8.3, non-fatal if `CODECOV_TOKEN` absent).

### 4b. ERP Pull-Sync (T12, in `main`)

Adds a **pull-queue** side channel for the ERP iPaaS:

| Endpoint | Description |
|---|---|
| `GET /v1/erp/orders` | Pull queue — FIFO list of orders not yet acknowledged by the ERP |
| `POST /v1/erp/orders/acknowledge` | Mark a batch (1–500) as forwarded; sets `customFields.erpSyncedAt`; idempotent |

Self-healing complement to the webhook push path: after an iPaaS outage or cold start the queue still holds everything unacknowledged.

### 4c. CQRS Read Projection + Write Queue (T13, in `main`, off by default)

The load-aware variant — built and **A/B-benchmarked** on the BE. Activated via env flags:

| Flag | Effect |
|---|---|
| `ORDER_INTEGRATION_ASYNC_WRITES=true` | `POST /v1/orders` enqueues → `202 Accepted` + job URL; worker applies to Shopware |
| `ORDER_INTEGRATION_PROJECTION_READS=true` | `GET` reads served from Postgres JSONB projection instead of Shopware DAL |

**Why this matters — measured results** (300 writes + 1 500 reads at concurrency 24):

| Metric | Baseline (sync) | CQRS async (2 workers) |
|---|---:|---:|
| Write throughput | 18 req/s | 99 req/s (**+443%**) |
| Write latency p95 (client-visible) | 1 633 ms | 310 ms (**−81%**) |
| Read throughput | 44 req/s | 128 req/s (**+191%**) |
| Read latency p95 | 879 ms | 265 ms (**−70%**) |
| Errors | 0 | 0 |

The cost: **eventual consistency** — queued writes are applied by the worker pool seconds to tens of seconds later (scales ~linearly with worker count). Acceptable for the integration use case; unacceptable for the direct checkout path.

Core implementation detail: the write queue uses `FOR UPDATE SKIP LOCKED` so N workers drain in parallel without ever handing the same command twice.

---

## 5. What is open / not yet built

### 5a. Must-do follow-ups (no owner yet)

| Item | Description | Where |
|---|---|---|
| ERP webhook push (`order.created`) | Outbound webhook when an order is created/transitioned, so the ERP gets notified in real time (complement to the pull queue) | Concept §7a |
| `POST /v1/shipment-events` | Batched inbound endpoint for FFP-driven shipment status + tracking; up to 500 events/call, keyed by `externalShipmentReference` | Concept §7a |
| Line-item allocation (`positions`) on split deliveries | PATCH delivery positions not yet implemented | Phase 3 note |

### 5b. Security & Auth hardening (Phase 5 — not started)

Currently using Shopware OAuth 2.0 (password grant + client credentials). Target:

- **mTLS** at the API gateway edge
- **Scoped OAuth 2.0 client credentials** per caller (`orders:read`, `orders:write`, `orders:status`, `erp:read`, `erp:write`, `shipment-events:ingest`)
- **API key fallback** for partners that cannot do mTLS
- Per-client **rate limiting** with RFC 9331 headers

### 5c. Infrastructure (order-integration-db LXC)

Required only when CQRS flags are enabled. Setup is fully documented in `docs/infrastructure-setup.md`:

- PostgreSQL 17 in its own Proxmox LXC (Debian Trixie)
- Schema: `order_read_projection` (JSONB) + `order_write_queue` (SKIP LOCKED)
- Systemd worker service (`bin/console order-integration:write-queue:drain`)
- Retention purge command for the write queue

### 5d. Observability (not yet built)

- W3C `Traceparent` propagation through to Shopware
- Audit log (actor, action, before/after diff) on every mutation
- `X-Request-Id` on every response

---

## 6. Key design invariants to preserve

1. **No extra HTTP hop.** The plugin calls Shopware services in-process. Any change that introduces an Admin API call on the hot path breaks the performance model.
2. **Shopware does the pricing.** `CartService` + `OrderConverter` are the only correct path for order creation. Do not implement price/tax logic in the plugin.
3. **CQRS is opt-in.** Both flags default to off. With them off and no `ORDER_INTEGRATION_DB_DSN`, nothing touches Postgres and the plugin behaves synchronously — no new infra needed to run it.
4. **Idempotency lives in the plugin, not in Shopware.** The `IdempotencyService` detects replays before a worker dispatches a command to Shopware. Re-execution against Shopware is avoided at the queue level.
5. **RFC 9457 everywhere.** All error responses must be `application/problem+json` with `type`, `title`, `status`, `detail`, `code`. New endpoints must go through `ExceptionSubscriber`, not return ad-hoc JSON.

---

## 7. Reference documents

| Document | What it covers |
|---|---|
| `docs/order-api-concept.md` | Full architecture analysis, Options A/B/C, ERP integration design (incl. webhook + shipment-events), security model |
| `docs/spike-order-creation.md` | The four order-creation paths in Shopware 6 — why Path 4 (plugin) won |
| `docs/cqrs-write-queue-concept.md` | CQRS read projection + write queue design, SKIP LOCKED, retry, backpressure |
| `docs/erp-pull-sync-concept.md` | ERP pull queue + acknowledge flag design (T12) |
| `docs/benchmark.md` | A/B benchmark tool and measured sync-vs-async results |
| `docs/infrastructure-setup.md` | Postgres LXC provisioning, schema, env, worker service (T13) |
| `docs/BACKLOG.md` | Completed hardening backlog T1–T11 with per-task rationale |
| `docs/testing.md` | Unit vs. integration test layer breakdown and the CI gap |
