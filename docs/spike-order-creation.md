# Spike — Creating Orders in Shopware 6 from a Facade API

**Goal.** Decide how the Order Lifecycle API's `POST /v1/orders` is implemented internally against Shopware 6.

**Why this is a spike, not a design.** The four candidate paths have very different cost and risk profiles. Two of them are routinely misused in the Shopware ecosystem (the "white sheet of paper" warning in the docs). The decision drives sprint-1 scope.

## Constraints from the API contract

The facade promises:

- Caller supplies a domain order (customer, addresses, line items, payment/shipping method) — **not** a pre-computed price breakdown.
- The facade returns a complete `Order` with calculated `subtotal`, `tax`, `total`, line-item totals, and a Shopware-issued `orderNumber`.
- The result must be a real Shopware order: it must appear in the admin panel, fire the normal events, respect the state machine, and be picked up by every downstream Shopware mechanism (invoices, mails, payments, B2B suite, etc.).
- Idempotency must hold: replaying `POST /v1/orders` with the same `Idempotency-Key` must return the same order, not create a second one.

This rules out anything that produces "an order row in the database" without going through Shopware's order-creation pipeline.

## Candidate paths

### Path 1 — Plain `POST /api/order` on the Admin API

You send a full order JSON: header, line items with `unitPrice` / `totalPrice`, taxes, addresses, transactions, deliveries.

- **Pros:** one call, no cart involved, easy to retry.
- **Cons (significant):**
  - Shopware does **not** recalculate prices. The facade would have to implement Shopware-equivalent pricing (gross/net, tax rules per country, percent vs. absolute line-item discounts, rounding rules, promotion stacking). This is a moving target — Shopware changes the pricing engine across minor versions.
  - No promotion engine, no automatic shipping calculation, no flow events fire reliably. Plugins that hook into checkout events do not see this order.
  - The DAL accepts almost anything. Wrong data shape → invoices, exports, accounting are quietly broken downstream.
- **Verdict:** unsuitable unless the caller is itself an ERP that already owns pricing. Not appropriate for a generic D2C facade.

### Path 2 — `POST /api/_action/order` (cart-to-order convert action)

Build a Shopware Cart server-side, then convert it to an order via the action endpoint. This uses Shopware's `OrderConverter` and is the same code path the storefront checkout uses.

- **Pros:** correct pricing, correct events, correct downstream behaviour. Uses Shopware's own conversion code.
- **Cons:**
  - The cart must exist in the system — built either via Store API calls server-side, or via the `CartService` directly inside a plugin.
  - From outside Shopware, building a cart is a sequence of Store API calls under a `sw-context-token` (sales channel + customer/guest context). That's 4–8 round trips per order before the convert call.
  - Mixing two APIs (Store API for cart, Admin API for the conversion action) requires careful auth/context plumbing. The Admin API call may not honour the same sales channel rules as the Store API.
- **Verdict:** correct in principle, awkward and chatty in practice. Acceptable if Path 4 is not affordable.

### Path 3 — Server-to-server Store API checkout

The facade authenticates a "service customer" (or guest) on the storefront sales channel, executes the same calls a headless frontend would: `POST /store-api/checkout/cart/line-item`, `POST /store-api/checkout/cart/order`.

- **Pros:** uses the supported end-to-end checkout. Pricing, promotions, flow events, payment intents — all correct.
- **Cons:**
  - The Store API is rate-limited and designed for human-paced storefront traffic. Pushing D2C order volume through it competes with real shoppers and is what the original concern about Admin API load also implies for Store API at scale.
  - A "service customer" is a workaround: you either manage one technical customer per partner or use guest checkout. Both have edge cases (address de-duplication, customer counts inflated, mailing list pollution).
  - Many round trips per order.
- **Verdict:** works for low write volume. Not the production target.

### Path 4 — Custom Shopware plugin that exposes a single endpoint (**recommended**)

Build a small Shopware plugin that registers one Admin API route, e.g. `POST /api/_action/external/order/create`. Internally it uses `CartService`, `OrderConverter`, and `OrderPersister` — Shopware's own checkout code — to materialise a cart and convert it in a single transaction. The route is callable only by the facade with elevated scope.

- **Pros:**
  - One call from the facade. No multi-step orchestration.
  - Uses Shopware's official services — pricing, promotions, taxes, flow events all correct.
  - The plugin can run inside the same PHP process and Shopware DB transaction; idempotency can be enforced inside the plugin itself.
  - We control the input schema, so we accept the facade's domain DTO directly and don't ship Shopware internals to callers.
  - The plugin is the right place for sales-channel selection logic (the facade tells it which channel, the plugin builds the right `SalesChannelContext`).
- **Cons:**
  - Requires Shopware plugin development (PHP, Symfony, Shopware DAL knowledge).
  - Plugin must be maintained across Shopware upgrades — but it uses public Shopware services with stable interfaces, so churn is low.
  - Adds a deployment artefact to the Shopware side.
- **Verdict:** lowest long-term cost, lowest runtime overhead, cleanest separation. Recommended target state.

## Recommendation

**Phase 1 (sprint 1–2):** Path 2 (cart build + `_action/order`) from the facade. Get the API contract right, ship something working, accept the latency cost.

**Phase 2 (parallel track, sprint 2–4):** Build the Shopware plugin (Path 4). Cut the facade over once the plugin is stable. Keep Path 2 as a fallback for one release.

**Reject** Path 1 (broken pricing) and Path 3 (Store API not for this load).

Across all phases:

- All write traffic goes through the facade's write queue (see concept §2, Option C). The queue applies per-Shopware concurrency caps so creation traffic cannot starve the storefront.
- Idempotency lives in the facade's idempotency store, **not** in Shopware. Re-execution against Shopware is avoided by the facade detecting the replay before the worker dispatches the call.
- The `OrderEventNotification` webhook for `order.created` is fired by the facade after it confirms Shopware accepted the order, not by Shopware directly — keeps the contract single-sourced.

## Open questions to resolve in the spike sprint

1. Which sales channel(s) does the facade operate against? One per partner, or one shared "API sales channel"?
2. Customer model: do callers always send a registered `customerId`, do we always create a guest, or both?
3. Payment method handling: do orders arrive already paid (caller supplies a transaction reference) or do we trigger a Shopware payment? Only the former is realistic for a service-to-service flow.
4. Promotions: are promotion codes ever passed in by callers, or are promotions applied only via Shopware-side rules?
5. B2B suite: are quote-to-order or approval flows in scope?

## Sources

- [Shopware Orders concept](https://developer.shopware.com/docs/concepts/commerce/checkout-concept/orders.html)
- [Shopware Admin API — Order](https://shopware.stoplight.io/docs/admin-api/991f88e90b0d6-order)
- [Shopware Admin API — Order Management](https://shopware.stoplight.io/docs/admin-api/fdd24cc76f22d-order-management)
- [Creating orders via Admin API (Macopedia)](https://macopedia.com/blog/news/how-to-create-a-correct-order-using-admin-api-in-shopware-6)
- [Shopware forum — creating orders via Admin API](https://forum.shopware.com/t/how-to-create-an-order-with-the-admin-api/87285)
