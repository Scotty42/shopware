# ERP Pull-Sync — Concept

**Context:** the ERP iPaaS integrates with this API in **pull** mode (see
`order-api-concept.md` §7a, where the ERP is the primary integration). The iPaaS
periodically pulls orders in a given state — e.g. `cancelled` — and forwards them
to the ERP. It must not forward the same order twice.

This feature adds (a) a **pull-queue** endpoint that returns only orders the ERP
has not yet acknowledged, and (b) an **acknowledge** endpoint that flags an order
as "made known to the ERP" so it drops out of the queue.

---

## 1. Why pull (not only webhooks)

The existing ERP design (§7a) is push-first via the `order.created` webhook. Pull
is the complement the iPaaS asked for:

- It is **self-healing**: after an iPaaS outage or cold start the queue still holds
  everything unacknowledged — no missed-webhook reconciliation logic needed.
- It is **idempotent by construction**: the queue is "orders without the flag",
  so re-pulling before acknowledging is safe.
- It fits the read path of the target architecture (`order-api-concept.md` §2,
  Option C): today it reads Shopware via the plugin; later the same contract can
  be served from the read projection.

Pull and push coexist: a webhook subscriber can still react in real time, while
the iPaaS uses pull as its reliable system of record.

## 2. The "known to ERP" fields

Two customFields are written on acknowledge:

| Field | Type | Purpose |
| --- | --- | --- |
| `erpSyncedAt` | ISO-8601 timestamp | When the order was first acknowledged. Controls the pull queue (absent = unacknowledged). |
| `erpOrderId` | string (≤ 100 chars) | The ERP's own order number (e.g. NAV Sales Order No). Written only when the caller supplies it; omitted otherwise. |

| Decision | Rationale |
| --- | --- |
| **customFields, not new columns** | No Shopware migration/entity extension; works on stock 6.6/6.7. The DAL can still filter on `customFields.erpSyncedAt`. |
| **Timestamp, not boolean** | Records *when* the ERP acknowledged — useful for audit, SLA, and debugging double-sends. Absent/null = not yet synced. |
| **Set once (idempotent)** | Re-acknowledging keeps the first timestamp; already-synced orders are not patched, so `erpOrderId` is also first-write-wins. |
| **Exposed via `customFields`** | OrderMapper already returns `customFields` verbatim, so both fields are visible on every Order payload with no mapper change. |

Resetting the flag (force a re-pull) is intentionally **out of scope** here — a
later `DELETE .../erp-sync` or an admin reset can clear the customField. Noted as
follow-up.

## 3. Endpoints

### `GET /v1/erp/orders` — pull queue

Returns orders **not yet acknowledged** (`erpSyncedAt` absent), optionally
filtered by domain `status`, oldest first (FIFO), cursor-paginated.

| Param | Notes |
|---|---|
| `status` | optional; one of `open`, `in_progress`, `completed`, `cancelled` |
| `limit` | 1–200, default 50 |
| `cursor` | opaque keyset cursor (createdAt asc + id) |

Response: `{ items: Order[], page: { limit, nextCursor } }` — same `Order` shape
as the rest of the API.

Typical call: `GET /v1/erp/orders?status=cancelled`.

### `POST /v1/erp/orders/acknowledge` — mark as forwarded

Body: `{ "orderIds": ["<id>", ...] }` (1–500 ids). Sets `erpSyncedAt = now` for
every id that is not already synced.

Response `200`:
```json
{
  "acknowledged":  ["..."],
  "alreadySynced": ["..."],
  "notFound":      ["..."],
  "counts": { "acknowledged": 1, "alreadySynced": 0, "notFound": 0 }
}
```

**Idempotent:** already-synced ids are reported under `alreadySynced` and keep
their original timestamp; unknown ids are reported under `notFound` rather than
failing the whole batch. This composes cleanly with the `Idempotency-Key`
mechanism (backlog T2) once merged — the operation is naturally retry-safe.

## 4. Workflow

```
  iPaaS                         Order API                       Shopware
    │  GET /erp/orders?status=cancelled  │                          │
    ├───────────────────────────────────►│  search: customFields.   │
    │                                     │  erpSyncedAt IS NULL     │
    │ ◄───────────────────────────────────  + state=cancelled       │
    │  (forward to ERP)                   │                          │
    │  POST /erp/orders/acknowledge       │                          │
    ├───────────────────────────────────►│  update customFields     │
    │                                     ├─────────────────────────►│
    │ ◄─── { acknowledged, ... } ─────────│                          │
    │  next pull no longer returns them   │                          │
```

## 5. Security

Reuses the plugin's Phase-1 auth (Shopware OAuth2). In the target auth model
(`order-api-openapi.yaml`) these map to dedicated ERP scopes: `erp:read` for the
pull queue, `erp:write` for acknowledge — a higher rate limit than generic
callers, consistent with §7a.

## 6. Mapping to Shopware

| Operation | DAL |
|---|---|
| Pull queue | `search(order)` with `EqualsFilter('customFields.erpSyncedAt', null)` (+ optional `stateMachineState.technicalName`), FIFO sort, keyset cursor. |
| Acknowledge | `update([... 'customFields' => ['erpSyncedAt' => now] ...])` — Shopware merges customFields, preserving other keys. |

## 7. Testing

`ErpSyncPolicy` holds all decision logic (sync detection, patch shape, batch
partitioning) and is fully unit-tested without a kernel
(`tests/Unit/Erp/ErpSyncPolicyTest.php`). `ErpSyncService` (DAL) and
`ErpSyncController` (HTTP) are thin and covered by the bash integration suite.
