# Testing

The plugin is tested on two layers.

## 1. Unit tests (PHPUnit) — run in CI

No Shopware kernel required. These cover the pure-logic seams:

- `QueryValidator` — list-parameter validation (limit, status, sort,
  customerId/salesChannelId, dates, cursor).
- `StateMachineService` — domain-status → Shopware-action mapping and
  exception translation.
- `OrderMapper` — REQUIRED_ASSOCIATIONS contract, weak-ETag stability,
  spec-required payload keys, money/totals + line-item formatting, and the
  primary-delivery consistency rule.
- `IdempotencyService` / `EtagComparator` / `SoftDeletePolicy` /
  `OrderCreateValidator` — the cross-cutting request-handling rules.
- `ExceptionSubscriber` — the RFC 9457 problem+json rendering for every
  handled exception type.
- **Concurrency hardening (added in `fix/concurrency-guards` + `test/concurrency-coverage`):**
  - `HandlesIdempotencyLockingTest` — lock acquired before `begin()` and released in
    `finally` (even on throw); replay works under lock; `null` factory is
    backwards-compatible; acquire-before-store-check enforced via a spy.
  - `OrderControllerWriteLockTest` — `order.write:{id}` lock acquired before work,
    released in `finally`, correct key shape and 10 s TTL verified.
  - `DeliveryEtagTest` — `W/"sha1(id|versionId|updatedAt)"` shape, stability for
    equal inputs, sensitivity to id/updatedAt (including microsecond) changes,
    `EtagComparator` integration.
  - `OrderPatchServiceAtomicTest` — single `update()` call regardless of fields
    patched, addresses embedded inline (no separate repo calls), no DB read for
    scalar-only patches, tags encoded as `[{name:...}]`.
  - `OrderProjectionSubscriberDeliveryTest` — `onDeliveryWritten()` resolves parent
    order IDs and upserts them; no-op when DB unconfigured or IDs empty; errors
    swallowed and forwarded to the logger.

**Coverage reporting:** CI uploads a Clover XML report to Codecov on PHP 8.3 only
(via `codecov/codecov-action@v4`). Coverage is non-fatal if the `CODECOV_TOKEN`
secret is absent — the job still passes. See `.github/workflows/ci.yml`.

Run locally:

```bash
composer install
composer test:unit
```

CI runs this suite on PHP 8.2 / 8.3 / 8.4 for every PR (`.github/workflows/ci.yml`).

## 2. Integration tests (bash) — live Shopware required, not yet gating

`tests/api_test.sh` drives the real HTTP endpoints against a running Shopware
backend with the plugin installed. It exercises the controllers,
`OrderCreationService`, `OrderPatchService` and `DeliveryController`, which
need a kernel + DB and therefore cannot run in the unit job.

`.github/workflows/integration.yml` scaffolds this run as a manual
(`workflow_dispatch`) job. It is intentionally not a PR gate yet: provisioning
a Shopware service container, installing the plugin, and seeding a product +
sales channel is a follow-up. Until then, run it locally:

```bash
cp .env.test.dist .env.test   # fill in once
tests/create_test_order.sh
tests/api_test.sh
```

If `.env.test` points to a Cloudflare Access protected URL, also set
`CF_ACCESS_CLIENT_ID` and `CF_ACCESS_CLIENT_SECRET`. In normal local/internal
setups these stay empty.

## Coverage gap (tracked)

The HTTP layer is currently covered only by the bash suite, which does not run
in CI. The unit tests above were extended to pull as much controller-adjacent
logic as possible into kernel-free, CI-gated tests. Closing the gap fully means
enabling the integration workflow as a PR gate — see the follow-up in
`docs/BACKLOG.md` (T11).
