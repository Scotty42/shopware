# A/B Load Benchmark (CQRS on/off)

`tests/benchmark.py` drives a configurable, parallel read/write workload purely
over the HTTP API, records latency percentiles, throughput and error rate per
run, and compares two runs relatively. Use it to quantify what enabling T13
(async write queue + read projection) changes under load.

Stdlib only (no pip). Config from `.env.test` (same vars as `tests/api_test.sh`).

## What it measures

- **Writes** — `POST /v1/orders` with a fresh `Idempotency-Key`.
  - *baseline*: synchronous (`201`), latency = full create through Shopware.
  - *cqrs* (`--async`): `Prefer: respond-async` → `202` + jobId. Two numbers:
    the **enqueue** latency (client-visible) and the **end-to-end completion**
    latency (polls `GET /v1/jobs/{id}` until `succeeded`/`dead`).
- **Reads** — 50/50 mix of `GET /v1/orders?limit=50` and `GET /v1/orders/{id}`.
  In *cqrs* mode these are served from the projection (server-side flag).
- Per category: `throughput_per_s`, `p50/p90/p95/p99/max` ms, `errors`.

## Run the A/B

```bash
# 1) BASELINE — T13 off (ORDER_INTEGRATION_ASYNC_WRITES / PROJECTION_READS = false)
python3 tests/benchmark.py run --label baseline --writes 300 --reads 1500 \
    --concurrency 24 --out baseline.json

# 2) Enable T13 on the BE, clear cache, start a worker (see docs/infrastructure-setup.md):
#      ORDER_INTEGRATION_ASYNC_WRITES=true
#      ORDER_INTEGRATION_PROJECTION_READS=true
#      ORDER_INTEGRATION_DB_DSN=pgsql:host=order-integration-db.lan.internal;port=5432;dbname=order_integration
#      bin/console cache:clear
#      bin/console order-integration:write-queue:drain --sleep=0 &
python3 tests/benchmark.py run --label cqrs --async --writes 300 --reads 1500 \
    --concurrency 24 --out cqrs.json

# 3) Relative comparison
python3 tests/benchmark.py compare baseline.json cqrs.json
```

Keep `--writes/--reads/--concurrency` identical across both runs so the
comparison is apples-to-apples. Raise `--concurrency` to make the contention the
write queue is designed for more visible.

## How to read it

- **Reads** should get dramatically faster and higher-throughput in *cqrs* mode
  (projection vs. full DAL rebuild) — the clearest win.
- **Write enqueue** latency in *cqrs* mode is small and roughly constant under
  load (the API just records + queues). Compare it to the baseline synchronous
  write latency.
- **Write end-to-end** (`writes_completion`) is enqueue + queue wait + worker
  apply. It is bounded by the worker pool, not by inbound concurrency — that is
  the point: Shopware sees a steady write rate instead of a spike. Watch
  `pending_after_timeout` (queue keeping up?) and `dead` (failures).
- **Errors**: the baseline tends to accumulate write errors/timeouts as
  concurrency rises (DAL lock contention); the queue should drive these toward 0.

> The benchmark mutates data (creates orders). Run it against a test/staging
> Shopware, not production.
