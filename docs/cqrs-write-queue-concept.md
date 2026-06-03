# CQRS Read Projection + Write Queue — Concept

Realizes **Option C** from `order-api-concept.md` §2 inside the plugin: a
denormalized **read projection** (CQRS read side) and a durable **write queue**
with a bounded worker pool. The write queue is the production-critical part:
under real load, many clients write concurrently, and routing every write
straight through Shopware's DAL on the request thread does not survive parallel
spikes.

```
                 ┌────────────────────────────────────────────┐
   reads  ─────► │ Read path  ─► ReadProjection (Postgres JSONB)│ ◄─ order.written /
                 │                                              │    order.deleted events
   writes ─────► │ Write path ─► WriteQueue (Postgres)          │    (OrderProjectionSubscriber)
                 │                    │                         │
                 │                    ▼                         │
                 │            WriteQueueWorker  ───────────────►│─► Shopware (in-process services)
                 │            (SKIP LOCKED, retry, backpressure)│
                 └────────────────────────────────────────────┘
```

## 1. Why a write queue (the important part)

Direct synchronous writes under parallel access cause:

- **Lock contention & deadlocks** in Shopware's DAL/MySQL when many requests
  mutate related rows at once.
- **Unbounded concurrency** against Shopware — every inbound request becomes a
  live DB transaction; a burst can exhaust connections and stall checkout.
- **No retry/backpressure** — a transient Shopware error fails the caller.

The queue inverts this: a write is **durably accepted** and acknowledged with
`202 Accepted` + a job URL, then a **bounded worker pool** applies it with a
global concurrency cap, retries with backoff, and idempotency. Shopware sees a
controlled, steady write rate regardless of inbound spikes.

### Safe parallelism: SKIP LOCKED

`PdoWriteQueue::claim()` runs:

```sql
UPDATE order_write_queue SET status='in_progress', attempts=attempts+1, updated_at=:now
 WHERE id IN (
   SELECT id FROM order_write_queue
    WHERE status='queued' AND (available_at IS NULL OR available_at <= :now)
    ORDER BY created_at ASC
    LIMIT :batch
    FOR UPDATE SKIP LOCKED
 ) RETURNING *;
```

`FOR UPDATE SKIP LOCKED` lets N workers claim **disjoint** batches concurrently
without blocking each other and without ever handing the same command twice —
the correctness guarantee for parallel operation. `InMemoryWriteQueue` mirrors
these semantics so the worker logic is unit-tested without a DB.

## 2. Lifecycle of a write

```
 enqueue ─► queued ─► (claim) in_progress ─┬─ success ─► succeeded
                          ▲                 └─ failure ─┬─ retry  ─► queued (available_at = now + backoff)
                          └───────────────────────────┘ └─ exhausted ─► dead (dead-letter)
```

- **Idempotency:** `idempotency_key` is unique; a retried enqueue returns the
  original job. Composes with the request-level `Idempotency-Key` (backlog T2).
- **Retry:** `RetryPolicy` — exponential backoff, capped, with jitter in prod.
- **Backpressure:** `BackpressurePolicy` — when queue depth / error rate crosses
  a threshold, the API sheds load (`503` + `Retry-After`) instead of dragging
  Shopware down.
- **Dead letters:** exhausted commands become `status='dead'` for inspection.

## 3. Read projection (CQRS read side)

`OrderProjectionSubscriber` listens to Shopware `order.written` / `order.deleted`
and upserts the canonical Order snapshot into `order_read_projection` (JSONB).
`GET /v1/orders` and `GET /v1/orders/{id}` are served from the projection when
enabled — reads never touch Shopware's DAL, collapsing p99 read latency and
decoupling read load from the shop.

**Eventual consistency.** A write applied by the worker becomes visible on the
projection a moment later (event propagation). Read-your-writes for a caller is
handled by returning a fresh snapshot on the mutation response and the
`If-Match` ETag flow (backlog T3).

## 4. Feature flags & safety

Both paths are **off by default** and gated by env, so the plugin keeps its
synchronous, DAL-backed behaviour until infrastructure is in place:

| Flag | Effect |
|---|---|
| `ORDER_INTEGRATION_ASYNC_WRITES=true` | `POST /v1/orders` enqueues, returns `202` + job URL (per-request override: `Prefer: respond-async` / `respond-sync`). |
| `ORDER_INTEGRATION_PROJECTION_READS=true` | `GET` reads served from the projection. |

The PDO connection is opened lazily (`PdoConnectionProvider`), so with the flags
off and no `ORDER_INTEGRATION_DB_DSN` nothing connects and nothing breaks.

## 5. Components

| Class | Role | Tested |
|---|---|---|
| `WriteCommand` | queue item (type, payload, status, attempts, result) | via queue/worker tests |
| `WriteQueueInterface` + `PdoWriteQueue` / `InMemoryWriteQueue` | durable queue, SKIP LOCKED claim | InMemory unit-tested |
| `RetryPolicy`, `BackpressurePolicy` | backoff + load shedding | unit-tested |
| `WriteQueueWorker` | claim → handle → complete/retry/dead-letter | unit-tested (fake handler) |
| `ShopwareCommandHandler` | applies command via in-process services | integration (bash) |
| `DrainWriteQueueCommand` | `bin/console order-integration:write-queue:drain` | integration |
| `ReadProjectionInterface` + `PdoReadProjection` / `InMemoryReadProjection` | CQRS read store | InMemory unit-tested |
| `OrderProjectionWriter` + `OrderProjectionSubscriber` | keep projection in sync from events | integration |
| `CqrsGateway` | feature-flagged glue the controllers call | via controller (bash) |
| `JobController` | `GET /v1/jobs/{id}` job status | integration |

## 6. Testing

- **PHPUnit** (`tests/Unit/Cqrs/...`): RetryPolicy, BackpressurePolicy,
  InMemoryWriteQueue (incl. single-hand claim simulating parallel safety),
  WriteQueueWorker (success / retry / dead-letter), InMemoryReadProjection
  (filter, order, cursor). Kernel-free, run in CI.
- **Bash** (`tests/write_queue_test.sh`): async `POST` → `202` → job poll →
  worker drain → order readable, against a live Shopware + the read/queue DB.

## 7. Setup

DB container, schema, env (test + prod), and worker service: see
`docs/infrastructure-setup.md`.
