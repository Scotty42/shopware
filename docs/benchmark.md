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

## Observed results (reference)

A single reference run on the LXC dev setup — workload **300 writes + 1500 reads
at concurrency 24** (`--writes 300 --reads 1500 --concurrency 24`). Treat these
as a *shape*, not a guarantee; re-measure on your hardware.

| Metric | Baseline (sync) | CQRS async · 1 worker | CQRS async · 2 workers |
|---|---:|---:|---:|
| Write throughput (req/s) | 18.3 | 76.6 | 99.4 |
| Write latency p50 / p95 (ms) — client-visible | 1282 / 1633 | 302 / 384 | 229 / 310 |
| Read throughput (req/s) | 44.0 | 84.6 | 128.1 |
| Read latency p50 / p95 (ms) | 393 / 879 | 276 / 355 | 179 / 265 |
| Write **end-to-end** completion p95 (ms) | ≈ write latency (~1.6 s) | 94 632 | 40 662 |
| Errors | 0 | 0 | 0 |

Reading the numbers:

- **Client-visible writes** get much faster and tighter — enqueue p95 −76…−81 %,
  throughput up to +443 %. The API stops blocking for the full Shopware order
  creation and just records + queues.
- **Reads** improve too (throughput up to +191 %, p95 −70 %). The gain is solid
  but bounded by fixed per-request overhead (HTTP, token validation, kernel boot)
  that sits on top of the saved DB time.
- **End-to-end write completion is the eventual-consistency cost**: a burst of
  300 queued writes takes tens of seconds to fully apply. It scales ~linearly
  with workers — **1 → 2 workers roughly halved the completion p95 (94.6 s →
  40.7 s)**. Add workers until Shopware (not the CQRS DB) is the bottleneck.
- **Zero errors in every run at concurrency 24** — Shopware coped synchronously,
  so the queue's *saturation protection* is not visible here. It shows at higher
  concurrency (e.g. `--concurrency 96/150`), where the sync path starts erroring
  / timing out while the async enqueue stays flat. That is the load level at
  which the write queue earns its keep.
