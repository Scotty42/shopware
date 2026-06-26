# Order Lifecycle API — Concept & Architecture

**Context:** Shopware 6 headless deployment (one backend, multiple frontends). This document specifies a service-to-service REST API to expose the order lifecycle (create, read, update attributes, status transitions, complete, cancel/delete) to internal and external systems.

**Audience:** internal/external developers, architects, security reviewers.

---

## 1. Shopware 6 — API surface in scope

Shopware 6 ships two HTTP APIs:

| API | Purpose | Auth | Consumer |
|---|---|---|---|
| **Store API** | Storefront-facing: catalog, cart, checkout, customer account. Built for headless frontends (SPA, mobile app). | Sales channel access key + optional customer context token (`sw-context-token`). | Frontends, end users (via frontend). |
| **Admin API** | Full CRUD over all entities (orders, products, customers, media, configuration). State-machine transitions. Built for integrations and automation. | OAuth 2.0 (password grant for users, client credentials for integrations). | Backend services, ERPs, MDM, OMS. |

Relevant Shopware concepts for orders:

- **Order entity:** header data (orderNumber, orderDate, prices, currency, sales channel) with related collections `lineItems`, `deliveries`, `transactions`, `addresses`, `tags`, `customFields`.
- **State machine:** Three independent state machines on every order — `order.state`, `order_transaction.state` (payment), `order_delivery.state` (shipping). Transitions are constrained (e.g., `refund` only from `paid`). Transitions are triggered via `POST /api/_action/order/{orderId}/state/{transition}` (and equivalents for transaction/delivery). Direct writes to the state field are blocked by Shopware.
- **Store API order creation** requires a full cart context (sales channel, customer/guest, line items, addresses, payment, shipping) and several round trips. **Admin API order creation** is one-shot but you assemble the entire calculated order yourself — Shopware does not recalculate prices.

This shapes our design choices below.

---

## 2. Architecture options

The user requested both variants. Below: trade-offs, then a recommendation.

### Option A — Direct use of Shopware Admin API

External services authenticate against Shopware's OAuth2 (`/api/oauth/token`, `client_credentials` grant) and call Admin API endpoints directly.

**Pros**
- Zero additional code to build/operate.
- Full feature parity with Shopware — every field, every association, every state transition.
- Always current with Shopware releases.

**Cons**
- Tight coupling: callers see Shopware's data model verbatim (UUIDs, nested associations, `salesChannelId`, `versionId`, snake_case fields, etc.). Breaking Shopware upgrades break every integration.
- No domain-shaped contract: callers must understand the three state machines, the difference between `order.state` and `order_transaction.state`, the `_action` endpoints, the `Criteria` query DSL.
- Authentication is Shopware-managed — hard to add mTLS, hard to map clients to fine-grained scopes without writing a Shopware plugin.
- Versioning is Shopware's versioning, not yours. Deprecations happen on Shopware's schedule.
- No place to enforce caller-specific rate limits, audit logs, transformation, masking of PII.

**Use when:** small number of trusted internal callers, short timeline, no plan to swap Shopware later.

### Option B — Facade API in front of Shopware (recommended)

A new service (your "Order API") exposes a clean, versioned REST contract. Internally it speaks Admin API to Shopware. Callers never see Shopware.

```
                  ┌────────────────────────────────────────┐
                  │   Order API (facade, this spec)        │
   Service A ───► │   v1, mTLS+OAuth2, RFC 9457 errors     │
   Service B ───► │   Idempotency-Key, ETag, RFC 9457      │ ──► Shopware Admin API
   ERP/OMS  ───► │   Domain DTOs, scopes, audit log       │      (OAuth2 client credentials,
                  │   Webhooks (optional)                  │       internal network only)
                  └────────────────────────────────────────┘
```

**Pros**
- Stable contract, versioned independently of Shopware.
- Authentication owned by you: mTLS at the edge, OAuth2 scopes per caller, mutual cert pinning.
- Domain language: a single `status` field for callers, mapped internally to the three Shopware state machines.
- Cross-cutting concerns in one place: idempotency keys, audit, rate limit, PII redaction, retry.
- You can later replace Shopware (or add a second backend) without breaking callers.

**Cons**
- One more service to build, deploy, and operate.
- Risk of contract drift if the facade lags Shopware features.
- Latency penalty: every call is at least one hop more.

**Use when:** more than a couple of consumers, external partners, long lifecycle expected, or any chance the commerce backend changes.

**Recommendation:** Option B. The OpenAPI in `order-api-openapi.yaml` describes the facade.

### Option C — Facade + read projection + async writes (the load-aware variant, **strongly recommended for D2C volume**)

The Shopware Admin API is not a production traffic plane. It is a generic CRUD layer over Shopware's entity framework (DAL): every call goes through validation, indexing, event firing, and serializer machinery. It is fine for back-office tools and integrations, but it is not the right place to absorb D2C order traffic — especially read traffic from the storefront/OMS/analytics services that often outnumbers writes 10–100×.

Hitting the Admin API at e-commerce read scale will:

- saturate Shopware app servers and starve the storefront (Store API runs on the same PHP-FPM pool),
- generate large query plans against the same MySQL/MariaDB instance the shop checkout depends on,
- exhibit unpredictable latency under load because every request rebuilds object graphs from scratch,
- make Shopware upgrades scary because every traffic spike depends on it.

The facade therefore needs to be more than a thin pass-through. Concretely:

```
                  ┌──────────────────────────────────────────────────────┐
                  │                  Order API (facade)                  │
                  │                                                      │
   reads ───────► │  Read path  ──►  Read projection (PG/Elasticsearch)  │ ◄── CDC / Shopware
                                                                                business events
                  │                                                      │     (order.written,
   writes ──────► │  Write path ──►  Command queue (Kafka/SQS/RabbitMQ)  │      state_machine.transition_*)
                  │                         │                            │
                  │                         ▼                            │
                  │                  Worker pool  ──────────────────────►│──► Shopware Admin API
                  │                  (rate-limited, retried, idempotent) │     (internal network only)
                  └──────────────────────────────────────────────────────┘
```

Key elements:

- **Read projection (CQRS-light).** A denormalized read store (Postgres with JSONB, or OpenSearch) holds the order data the facade serves. The projection is kept in sync from Shopware via either (a) CDC on the Shopware DB (Debezium → Kafka) or (b) Shopware's business event subscription / webhook plugin. Reads never hit the Admin API. This collapses p99 read latency to single-digit ms and decouples read load from Shopware.
- **Write queue.** Mutating calls (`POST /orders`, status transitions, patches) are accepted by the facade, recorded with their `Idempotency-Key`, and enqueued. A bounded worker pool dispatches them to the Admin API with backoff, retry, and a global concurrency cap that protects Shopware. The facade can answer the caller synchronously once the command is durably accepted (return `202 Accepted` + a job URL) or wait briefly for completion and return `200/201` — configurable per operation.
- **Cache in front of the projection.** Short-TTL (seconds) cache for hot order lookups, keyed by id and ETag.
- **Backpressure.** When the queue depth or Shopware error rate crosses a threshold, the facade sheds load (`503` with `Retry-After`) rather than dragging Shopware down.
- **Rate-limit per client** at the facade edge; protects the projection and the Admin API independently.

Trade-offs to be honest about:

- **Eventual consistency on reads.** A status change written via the API is not instantly visible on the read projection. Typical lag is sub-second with CDC, but it is not zero. The API mitigates this with a `read-your-writes` option: after a mutation the response carries a fresh snapshot, and clients can pass an `If-Match` ETag from that snapshot on subsequent reads to be routed to the primary path if needed.
- **Operational surface.** A queue, workers, a projection store, and CDC are non-trivial to operate. Worth it once order volume justifies it; overkill for < a few thousand orders/day where Option B + caching is enough.
- **Order-creation is the hardest write.** See `spike-order-creation.md` — Shopware's Admin API does not recalculate prices, and creating a real order with tax/promotions ultimately routes through either the Store API cart flow or a custom Shopware plugin endpoint. The write queue protects whichever path is chosen.

Phased rollout:

1. **Phase 1 (MVP):** Option B — synchronous facade in front of the Admin API, with caching and per-client rate limiting. Good enough for low write volume and limited read traffic.
2. **Phase 2:** Add the read projection fed by Shopware business events / CDC. Cut over reads. Admin API load drops sharply.
3. **Phase 3:** Add the write queue and async worker pool. The API contract is unchanged; only the response code on some mutations becomes `202` where the caller opts in via header.

The OpenAPI in `order-api-openapi.yaml` is written so that none of the three phases is a breaking change for callers: response codes for mutations include `202 Accepted` from day one, idempotency is required from day one, ETag/If-Match are required from day one.

**Bottom line.** For the stated context (D2C e-commerce volume), Option C is the recommendation. Option B is the acceptable starting point only as Phase 1, with a documented path to Phase 2/3. Option A (direct Admin API to callers) is not acceptable at D2C load.

---

## 3. Resource model

| Resource | Path | Notes |
|---|---|---|
| Order | `/v1/orders/{orderId}` | Canonical resource. `orderId` is the API's own UUID, mapped 1:1 to Shopware order id. |
| Order collection | `/v1/orders` | `GET` for search/list, `POST` to create. |
| Status | `/v1/orders/{orderId}/status` | `PUT` to drive a transition. Body names the target state. Implementation validates against state machine. |
| Line items | `/v1/orders/{orderId}/line-items` | Read; partial mutation via PATCH on the order. |
| Custom attributes | exposed inline as `customFields: { key: value }` on the order. | Maps to Shopware `customFields`. |
| Events (optional) | `/v1/orders/{orderId}/events` | Audit/state history. Maps to Shopware `state_machine_history`. |

A single domain `status` field is exposed (`open`, `in_progress`, `completed`, `cancelled`). The facade fans it out to the right Shopware state machine. Payment and delivery state are exposed as separate read-only fields (`paymentStatus`, `deliveryStatus`) and have their own transition endpoints.

---

## 4. Lifecycle & state model

Domain status:

```
                  ┌─────────┐
   create ───────►│  open   │
                  └────┬────┘
                       │ start_processing
                       ▼
                  ┌─────────────┐
                  │ in_progress │
                  └────┬────┬───┘
            complete   │    │ cancel
                       ▼    ▼
                  ┌────────┐ ┌────────────┐
                  │complete│ │ cancelled  │
                  └────────┘ └────────────┘
```

Rules:
- `DELETE /v1/orders/{id}` is **soft delete** by default (sets status `cancelled`, retains record). Hard delete requires `?hard=true` and scope `orders:hard_delete`, and is rejected for orders with completed payments — required by accounting/audit rules.
- Status transitions are submitted via `PUT /v1/orders/{id}/status` with a body `{ "status": "...", "reason": "..." }`. Invalid transitions yield `409 Conflict` with a Problem Details body.
- Concurrent updates use **ETag / If-Match** optimistic concurrency. Mismatches yield `412 Precondition Failed`.

---

## 5. Cross-cutting concerns

### 5.1 Versioning

URL path versioning (`/v1/...`). Justification:
- Discoverable, cache-friendly, no header tax for partners.
- Stripe, GitHub, Shopify do the same.
- Trade-off (HATEOAS purity) is accepted: this is a service-to-service domain API, not a hypermedia application.
- Breaking changes get a new major version; additive changes are non-breaking and stay on the current one. We follow SemVer at the API boundary.

### 5.2 Idempotency

Required on every mutating operation (`POST /orders`, `PUT .../status`, `PATCH /orders/{id}`, `DELETE /orders/{id}`). Clients send an `Idempotency-Key` header (UUIDv4 recommended). The server:

1. Hashes the request body (SHA-256). Stores `{ key, hash, status, response }` in an idempotency store with 24h TTL.
2. Same key + same hash → returns the cached response.
3. Same key + different hash → `409 Conflict` ("idempotency key reused with different payload").
4. Key in flight → `425 Too Early` or block briefly, depending on policy.

This protects against duplicate orders from retried POSTs across timeouts and network failures.

### 5.3 Error model — RFC 9457 Problem Details

All errors use `application/problem+json` per RFC 9457. Minimum fields: `type`, `title`, `status`, `detail`, `instance`. We extend with:
- `code` — stable machine identifier (e.g. `order.invalid_state_transition`).
- `errors[]` — for validation errors, an array of `{ pointer, code, message }` using JSON Pointer.
- `traceId` — correlation id for log lookup.

### 5.4 Pagination, filtering, sorting

- Cursor pagination on list endpoints: `?cursor=...&limit=...` (max 200). `next` cursor returned in body.
- Filtering: a small allow-list of fields (`status`, `createdAt`, `updatedAt`, `customerId`, `salesChannelId`). No generic query DSL — keeps the contract small and avoids leaking Shopware `Criteria` syntax.
- Sorting: `sort=createdAt:desc` style.

### 5.5 Conditional requests & caching

- `GET` returns `ETag` + `Last-Modified`.
- `If-None-Match` / `If-Modified-Since` supported.
- `If-Match` required on `PUT`/`PATCH`/`DELETE` for optimistic concurrency.

**Implementation status (plugin):**

| Resource | GET returns ETag | Mutating requests require If-Match |
|---|---|---|
| `Order` | Yes — `W/"sha1(id\|versionId\|updatedAt)"` | `PATCH`, `DELETE`, all status mutations |
| `OrderDelivery` | Yes — same algorithm keyed on delivery entity | `PATCH /deliveries/{id}`, `PUT /deliveries/{id}/status` |

Mismatched `If-Match` returns `412 Precondition Failed` (RFC 9110). Missing `If-Match`
on a mutating call returns `428 Precondition Required`.

Each mutating response also returns a fresh `ETag` of the updated resource, so callers
can chain operations without a separate `GET`.

**Concurrency hardening:** write mutations are serialised per order via a `FlockStore`-backed
`symfony/lock` mutex (`order.write:{orderId}`, 10 s TTL). A separate per-key mutex guards
the idempotency `begin()`/`complete()` window (`idempotency:{key}`, 30 s TTL). Swap to
`RedisStore` or `DoctrineDbalStore` for multi-server deployments.

**ETag note on Shopware versionId:** Shopware's `versionId` for live orders is always
`Defaults::LIVE_VERSION` (a fixed UUID), not an incrementing write counter. ETag uniqueness
therefore depends on `updatedAt` microsecond precision. This is sufficient for the
optimistic-concurrency guard but means two writes within the same microsecond cannot be
distinguished — an acceptable trade-off at normal order volumes.

### 5.6 Rate limiting

Per client (mTLS cert subject or OAuth `client_id`). Headers `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` per RFC 9331. `429 Too Many Requests` with Problem Details on breach.

### 5.7 Observability

- Required header `Traceparent` (W3C Trace Context) — propagated to Shopware via Admin API call.
- Server adds `X-Request-Id` to every response.
- Audit log of every mutation: actor (client id / cert subject), action, before/after diff, timestamp.

---

## 6. Security

### 6.1 Transport

- TLS 1.3 only. TLS 1.2 acceptable as fallback for legacy partners; below that rejected.
- HSTS on the public endpoint. Certificate from a public CA on the server side.

### 6.2 Authentication — three schemes, layered

The OpenAPI spec defines three security schemes. They are not alternatives in the strict sense — production should require **mTLS + (OAuth2 or API key)**.

1. **mTLS (recommended primary)**
   - Clients present an X.509 certificate signed by an internal CA.
   - Server validates cert chain, expiry, revocation (OCSP stapling), and matches subject CN/SAN against an allow-list.
   - Cert subject becomes the principal for audit and rate limiting.
   - Strong because credentials cannot be replayed or phished, and the transport binds identity to the connection.

2. **OAuth 2.0 Client Credentials (recommended on top of mTLS)**
   - `client_credentials` grant. Tokens are short-lived JWTs (max 1h), signed by an internal auth server.
   - Scopes: `orders:read`, `orders:write`, `orders:status`, `orders:hard_delete`.
   - Adds **authorization** (scopes per caller) on top of mTLS **authentication**. Same machine identity may have different scopes per integration.

3. **API key in header (`X-API-Key`)** — fallback only.
   - Cheaper to onboard, no PKI required.
   - Acceptable only behind mTLS for partners that cannot do client cert + OAuth.
   - Keys are stored hashed at rest, rotated quarterly, scoped same as OAuth.

### 6.3 Authorization model

Scope-based. The facade owns the mapping (client → allowed scopes → Shopware Admin API operations). The Shopware integration uses a single high-privilege Admin API client; the facade never forwards caller credentials.

### 6.4 Network

- Facade exposed only via an API gateway or service mesh (Istio/Linkerd) that terminates mTLS.
- Shopware Admin API never reachable from the public internet — only from the facade, on a private network.
- Egress from the facade is allow-listed to Shopware and the auth server.

### 6.5 Secrets

- Cert private keys and OAuth client secrets in a secret manager (Vault, AWS SM, GCP SM). Rotated automatically.
- Logs scrub `Authorization`, `X-API-Key`, payment instrument data, and PII per a deny-list.

### 6.6 PII / GDPR

- Order data contains personal data. Access logged for 90 days, retention configurable per data class.
- `DELETE` does not erase PII by default — that is a separate data subject right endpoint (out of scope here, mention only).

---

## 7. Mapping to Shopware internals (plugin implementation)

> **Architecture note.** This section was originally written for Option B (a
> standalone facade calling the Shopware Admin API over HTTP). The implemented
> solution is **Option D** — a Shopware plugin that calls Shopware's PHP services
> **in-process** with no HTTP hop to the Admin API. The mapping below reflects the
> in-process service calls used by the plugin.

| Plugin operation | Shopware in-process call |
|---|---|
| `POST /v1/orders` | `CartService` + `OrderConverter` + `OrderPersister` — builds a cart from the request DTO, converts it to an order with correct pricing/tax/promotions, and persists it in a single transaction. |
| `GET /v1/orders/{id}` | `EntityRepository` (`order`) with DAL `Criteria` + associations (`lineItems`, `deliveries`, `transactions`, `addresses`, `tags`). |
| `GET /v1/orders` | `EntityRepository` search with `Criteria` filter, sort, and keyset cursor. |
| `PATCH /v1/orders/{id}` | `EntityRepository::update()` — limited to safe mutable fields (`customFields`, `tags`, `customerComment`, addresses), atomic single-call. |
| `PUT /v1/orders/{id}/status` | `StateMachineRegistry::transition()` on `order.state` — plugin maps domain status to the Shopware transition action. |
| `PUT /v1/orders/{id}/payment-status` | `StateMachineRegistry::transition()` on `order_transaction.state`. |
| `PUT /v1/orders/{id}/delivery-status` | `StateMachineRegistry::transition()` on `order_delivery.state`. |
| `DELETE /v1/orders/{id}` (soft) | `StateMachineRegistry::transition()` to `cancelled`. Re-cancel is idempotent (204); transition to a non-cancellable state yields 409. |
| `DELETE /v1/orders/{id}?hard=true` | `EntityRepository::delete()` — Shopware blocks deletion for orders with completed payments; surfaced as 409 Conflict. |
| `GET /v1/orders/{id}/events` | Not yet implemented. Concept: `EntityRepository` search on `state_machine_history` filtered by `entityId`. |

---

## 7a. ERP integration — the primary integration

The single most important consumer of this API is the ERP. It is also a producer: shipment status from fulfillment partners (FFP) flows back through the ERP into Shopware. Treating the ERP as "just another generic caller" is wrong; this section calls out the specifics.

### Data ownership

| Concern | Master of record |
|---|---|
| Order header, customer, line items, prices, promotions | Shopware (via this API) |
| Order status (domain `open` → `completed`) | Shopware, driven by API or business events |
| Payment state | Payment provider, recorded in Shopware |
| **Delivery / shipment data** (tracking, dates, carrier) | **ERP**, pushed into Shopware via this API |
| Stock / availability | ERP (or WMS), out of scope here |

The API does not make Shopware the truth source for shipment data. Shopware mirrors what the ERP reports. Conflicts are resolved in favour of the ERP.

### Outbound: order → ERP

When a domain order is created (or transitions to `in_progress`), the ERP needs the fulfillment-relevant snapshot: customer, billing/shipping addresses, line items with SKU and quantity, shipping method, customer comment, custom fields, internal order id, Shopware order number.

Mechanism: the existing `order.created` webhook (subscription model from §5.7-style spec, owned by the ERP integration's `client_id`). Recommended pattern:

- ERP registers a webhook subscription for `order.created`, `order.patched`, `order.cancelled`.
- The webhook payload is the full `Order` resource — no separate "ERP DTO" — so the ERP integration speaks the same canonical model as everyone else.
- ERP responds 2xx within 5s and persists the payload. Long-running fulfillment work happens on the ERP side afterwards.
- The facade retries with exponential backoff on non-2xx; the subscription auto-disables after a configurable consecutive-failure budget.

For batch reconciliation (daily delta sync, ERP cold start, missed deliveries) the ERP uses the normal `GET /v1/orders?updatedAfter=...` cursor-paginated read against the read projection. Same model, no second endpoint.

### Inbound: shipment events → API

The hot integration. The FFP emits events ("scanned at hub", "out for delivery", "delivered", "exception"), the ERP normalises them, and the ERP posts them to this API. The volume is *higher* than the order-creation rate (one order → many shipment events).

Design choices:

- **Dedicated endpoint `POST /v1/shipment-events`** rather than reusing `PUT /v1/orders/{id}/delivery-status`. The latter would force the ERP to know the order id for every event and require one round trip per status flip; the former accepts a batch of events keyed by `externalShipmentReference` (the ERP's own id) and resolves to Shopware deliveries inside the facade.
- **Batch:** accept up to 500 events per call. Each event carries its own idempotency identifier (`eventId`) so partial retries are safe.
- **Identity:** the ERP authenticates with mTLS + OAuth2 client_credentials. Specific scope `shipment-events:ingest`. Higher rate limit than generic callers because this is an expected-high-volume integration.
- **Async:** the endpoint accepts, enqueues, and returns 202 with a per-event acceptance summary. Application of each event to the corresponding Shopware `order_delivery` happens in the facade's worker pool — same backpressure guarantees as for order creation.

Event types ingested from the ERP:

- `shipment.created` — ERP allocated a delivery (becomes a new Shopware `order_delivery` if not present).
- `shipment.tracking_assigned` — tracking code(s) and carrier known.
- `shipment.shipped` — physically left the warehouse / handed to carrier.
- `shipment.in_transit` — carrier scan, optional intermediate event.
- `shipment.out_for_delivery` — last-mile.
- `shipment.delivered` — POD received.
- `shipment.exception` — failed delivery attempt, address issue, damage.
- `shipment.returned` — package returned to sender.

These map to Shopware `order_delivery.state` transitions (`ship`, `ship_partially`, `return`, `return_partially`) plus tracking-code updates on the delivery record. The facade owns the mapping rules.

### Outbound: shipment → other subscribers

Once a shipment event is applied to Shopware, the facade emits a corresponding webhook (`order.delivery_status_changed`, `order.shipment_tracking_updated`) for any other subscriber that wants to react — typically: customer-comms (transactional email/SMS), the storefront's "track my order" page, analytics.

### Why not let the ERP call Shopware Admin API directly

It is technically possible. It would be a mistake:

- The FFP-driven event firehose hits Shopware on every parcel scan. Shopware's `order_delivery` writes are not cheap.
- Tracking-code formats, carrier names, exception taxonomies differ per FFP and per region. Normalisation belongs in the facade or ERP, not in plugin code inside Shopware.
- mTLS + scoped tokens for the ERP integration are easier to manage on the facade than via Shopware integrations.
- The facade's outbox/projection model gives all other consumers consistent views; bypassing it creates inconsistencies.

### Sequence (happy path, one parcel)

```
  Storefront      Order API           Shopware           ERP            FFP
      │              │                   │                │              │
      │ checkout     │                   │                │              │
      ├────────────► │                   │                │              │
      │              │ create order      │                │              │
      │              ├──────────────────►│                │              │
      │              │ ◄─────────────────┤                │              │
      │              │   order.created webhook ──────────►│              │
      │              │                   │                │ ship request │
      │              │                   │                ├─────────────►│
      │              │                   │                │ tracking, ack│
      │              │                   │                │ ◄────────────┤
      │              │   POST /shipment-events            │              │
      │              │ ◄─────────────────────────────────┤              │
      │              │ apply: tracking + state shipped   │              │
      │              ├──────────────────►│                │              │
      │              │   order.delivery_status_changed webhook ─► other subscribers
      │              │                   │                │ FFP scans    │
      │              │                   │                │ ◄────────────┤
      │              │   POST /shipment-events            │              │
      │              │ ◄─────────────────────────────────┤              │
      │              │ apply: state delivered            │              │
      │              ├──────────────────►│                │              │
```



- Returns and refunds — separate sub-resource, future iteration.
- Bulk operations (batch create/update) — future iteration; would use `application/json-seq` or async jobs.
- Webhooks/push notifications to subscribers on state change — in v1 (added in this revision; see OpenAPI `webhook-subscriptions` resource and `OrderEventNotification` schema).
- Tax/price recalculation — owned by Shopware. The facade is a transport, not a pricing engine.

---

## 9. Implementation decisions (resolved)

These questions were open during architecture; all are resolved by the plugin implementation:

1. **Order creation path.** Shopware prices the order. The plugin uses `CartService` + `OrderConverter` + `OrderPersister` in-process — callers supply domain input (line items, addresses, customer), Shopware applies all pricing, tax, promotion, and event logic. See `docs/spike-order-creation.md` for the full analysis.
2. **External partners.** Currently internal/iPaaS only. Phase-1 auth is Shopware OAuth 2.0 (client credentials grant via a dedicated Integration). Phase-5 target auth (mTLS + scoped OAuth 2.0 + API key) remains open — see `docs/grooming-overview.md` §5b.
3. **Multi-sales-channel.** Each order belongs to exactly one sales channel. The `salesChannelId` is passed on creation and stored on the order — no cross-channel collision.
4. **SLA targets.** Sync path is the baseline (Phase 1). CQRS async writes + read projection (T13) are built and benchmarked (`docs/benchmark.md`); eventual-consistency cost is acceptable for the integration use case (ERP iPaaS, not direct checkout).

---

## Sources

- [Shopware Admin API concept](https://developer.shopware.com/docs/concepts/api/admin-api.html)
- [Shopware Store API concept](https://developer.shopware.com/docs/concepts/api/store-api.html)
- [Shopware APIs overview](https://developer.shopware.com/docs/guides/development/integrations-api/)
- [Shopware order state machine guide](https://developer.shopware.com/docs/guides/plugins/plugins/checkout/order/using-the-state-machine.html)
- [Shopware Admin API reference](https://developer.shopware.com/resources/api/admin-api-reference.html)
- [Creating orders via Admin API — Macopedia](https://macopedia.com/blog/news/how-to-create-a-correct-order-using-admin-api-in-shopware-6)
- [RFC 9457 — Problem Details for HTTP APIs](https://www.rfc-editor.org/rfc/rfc9457.html)
- [OpenAPI Security schemes — Redocly](https://redocly.com/learn/openapi/openapi-visual-reference/security-schemes)
- [OAuth2 in OpenAPI — Speakeasy](https://www.speakeasy.com/openapi/security/security-schemes/security-oauth2)
- [Microsoft Azure — Web API design best practices](https://learn.microsoft.com/en-us/azure/architecture/best-practices/api-design)
- [REST API versioning strategies — Speakeasy](https://www.speakeasy.com/api-design/versioning)
- [Idempotency keys in REST APIs — Zuplo](https://zuplo.com/learning-center/implementing-idempotency-keys-in-rest-apis-a-complete-guide)
