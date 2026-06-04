#!/usr/bin/env bash
#
# HTTP integration suite. Runs against a live Shopware backend with the plugin
# installed. Repeatable on a clean install.
#
# Updated for T2 (Idempotency-Key): every mutating request (POST/PUT/PATCH/
# DELETE on orders + status) now sends a fresh Idempotency-Key, and the
# idempotency behaviour itself is covered (missing key -> 400, replay, conflict).
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/../.env.test"

BASE="$SHOPWARE_URL/api/order-integration/v1"
PASS=0
FAIL=0

assert_status() { local d="$1" e="$2" a="$3"; if [[ "$a" == "$e" ]]; then echo "✓ $d"; ((PASS++))||true; else echo "✗ $d — expected HTTP $e, got $a"; ((FAIL++))||true; fi; }
assert_eq()     { local d="$1" e="$2" a="$3"; if [[ "$a" == "$e" ]]; then echo "✓ $d"; ((PASS++))||true; else echo "✗ $d — expected '$e', got '$a'"; ((FAIL++))||true; fi; }
ok()  { echo "✓ $1"; ((PASS++))||true; }
bad() { echo "✗ $1"; ((FAIL++))||true; }

jqpy() { python3 -c "import sys,json;d=json.load(sys.stdin);print($1)" 2>/dev/null; }
idem() { python3 -c "import uuid;print(uuid.uuid4())"; }   # fresh Idempotency-Key

TOKEN=$(curl -sf -X POST "$SHOPWARE_URL/api/oauth/token" \
  -H 'Content-Type: application/json' \
  -d "{\"grant_type\":\"password\",\"client_id\":\"administration\",\"username\":\"$SHOPWARE_ADMIN_USER\",\"password\":\"$SHOPWARE_ADMIN_PASSWORD\",\"scopes\":\"write\"}" \
  | jqpy "d['access_token']")

if [[ -z "$TOKEN" ]]; then
  echo "✗ Could not obtain an admin token from: $SHOPWARE_URL"
  echo "  HTTP 000 means the host is not reachable from where this runs."
  echo "  Run on the shopware-be LXC (SHOPWARE_URL=http://localhost) or set a reachable URL in .env.test,"
  echo "  and check SHOPWARE_ADMIN_USER / SHOPWARE_ADMIN_PASSWORD."
  exit 1
fi
ok "Token acquired"

AUTH=(-H "Authorization: Bearer $TOKEN")
JSON=(-H "Content-Type: application/json")

CUSTOMER_ID=$(curl -s "${AUTH[@]}" "$SHOPWARE_URL/api/customer?limit=1" | jqpy "d['data'][0]['id']")
ORDER_BODY="{\"salesChannelId\":\"$SHOPWARE_SALES_CHANNEL_ID\",\"customer\":{\"id\":\"$CUSTOMER_ID\"},\"lineItems\":[{\"productId\":\"$SHOPWARE_TEST_PRODUCT_ID\",\"quantity\":1}]}"

# Create an order via the API (mutating -> needs a fresh Idempotency-Key).
create_order() {
  curl -s -X POST "$BASE/orders" "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" \
    -d "$ORDER_BODY" | jqpy "d.get('id','')"
}
status_code() { curl -s -o /dev/null -w "%{http_code}" "$@"; }

echo "=== Read & auth ==="
assert_status "GET /orders returns 200" "200" "$(status_code "${AUTH[@]}" "$BASE/orders")"

LIST=$(curl -s "${AUTH[@]}" "$BASE/orders")
COUNT=$(echo "$LIST" | jqpy "len(d['items'])")
[[ "${COUNT:-0}" -ge 1 ]] && ok "GET /orders returns at least 1 item (got $COUNT)" || bad "GET /orders returns >=1 item (got ${COUNT:-0})"

assert_status "GET /orders?status=open returns 200" "200" "$(status_code "${AUTH[@]}" "$BASE/orders?status=open")"
assert_status "GET /orders/{id} unknown returns 404" "404" "$(status_code "${AUTH[@]}" "$BASE/orders/b878ba70bf7d47a12ae61ad5b1dc8582")"
assert_eq "404 has RFC 9457 code" "order.not_found" "$(curl -s "${AUTH[@]}" "$BASE/orders/b878ba70bf7d47a12ae61ad5b1dc8582" | jqpy "d['code']")"
assert_status "Invalid token returns 401" "401" "$(status_code -H 'Authorization: Bearer invalid-token' "$BASE/orders")"
assert_status "No token returns 401" "401" "$(status_code "$BASE/orders")"

echo ""
echo "=== Pagination (keyset cursor) ==="
CURSOR=$(curl -s "${AUTH[@]}" "$BASE/orders?limit=1" | jqpy "d['page']['nextCursor'] or ''")
[[ -n "$CURSOR" ]] && ok "GET /orders?limit=1 returns nextCursor" || bad "GET /orders?limit=1 nextCursor empty"
if [[ -n "$CURSOR" ]]; then
  CURSOR_ENC=$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$CURSOR")
  assert_status "GET /orders with cursor returns 200" "200" "$(status_code "${AUTH[@]}" "$BASE/orders?limit=1&cursor=$CURSOR_ENC")"
fi

echo ""
echo "=== Validation (T4/T5/T6) ==="
assert_status "status=invalid -> 422"          "422" "$(status_code "${AUTH[@]}" "$BASE/orders?status=invalid")"
assert_status "limit=999 -> 422"               "422" "$(status_code "${AUTH[@]}" "$BASE/orders?limit=999")"
assert_status "createdAfter=not-a-date -> 422" "422" "$(status_code "${AUTH[@]}" "$BASE/orders?createdAfter=not-a-date")"
assert_status "sort=createdAt:asc -> 200"       "200" "$(status_code "${AUTH[@]}" "$BASE/orders?sort=createdAt:asc")"
assert_status "sort=orderNumber:desc -> 200"    "200" "$(status_code "${AUTH[@]}" "$BASE/orders?sort=orderNumber:desc")"
assert_status "sort=total:asc (unknown) -> 422" "422" "$(status_code "${AUTH[@]}" "$BASE/orders?sort=total:asc")"
assert_status "sort=createdAt:up (bad dir) -> 422" "422" "$(status_code "${AUTH[@]}" "$BASE/orders?sort=createdAt:up")"
assert_status "salesChannelId filter -> 200"    "200" "$(status_code "${AUTH[@]}" "$BASE/orders?salesChannelId=$SHOPWARE_SALES_CHANNEL_ID")"
assert_status "salesChannelId=not-hex -> 422"   "422" "$(status_code "${AUTH[@]}" "$BASE/orders?salesChannelId=not-hex")"
UUID_CUST=$(python3 -c "import sys;h=sys.argv[1];print(f'{h[0:8]}-{h[8:12]}-{h[12:16]}-{h[16:20]}-{h[20:32]}')" "$CUSTOMER_ID")
assert_status "customerId as UUID accepted -> 200"  "200" "$(status_code "${AUTH[@]}" "$BASE/orders?customerId=$UUID_CUST")"
assert_status "customerId as 32-hex accepted -> 200" "200" "$(status_code "${AUTH[@]}" "$BASE/orders?customerId=$CUSTOMER_ID")"
assert_status "customerId garbage -> 422"       "422" "$(status_code "${AUTH[@]}" "$BASE/orders?customerId=not-an-id")"

echo ""
echo "=== Idempotency (T2) ==="
# Missing Idempotency-Key on a mutation -> 400
assert_status "POST without Idempotency-Key -> 400" "400" "$(status_code -X POST "${AUTH[@]}" "${JSON[@]}" -d "$ORDER_BODY" "$BASE/orders")"
# Replay: same key + same body returns the original order
IK=$(idem)
R1=$(curl -s -X POST "$BASE/orders" "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $IK" -d "$ORDER_BODY" | jqpy "d.get('id','')")
R2=$(curl -s -X POST "$BASE/orders" "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $IK" -d "$ORDER_BODY" | jqpy "d.get('id','')")
[[ -n "$R1" && "$R1" == "$R2" ]] && ok "Replay: same key+body -> same order ($R1)" || bad "Replay: expected same order, got '$R1' vs '$R2'"
# Conflict: same key + different body -> 409
DIFF_BODY="{\"salesChannelId\":\"$SHOPWARE_SALES_CHANNEL_ID\",\"customer\":{\"id\":\"$CUSTOMER_ID\"},\"lineItems\":[{\"productId\":\"$SHOPWARE_TEST_PRODUCT_ID\",\"quantity\":2}]}"
assert_status "Reused key + different body -> 409" "409" "$(status_code -X POST "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $IK" -d "$DIFF_BODY" "$BASE/orders")"

echo ""
echo "=== Status transitions ==="
TR=$(create_order)
[[ -n "$TR" ]] && ok "Created transition order $TR" || bad "Create transition order"
assert_status "PUT /status in_progress -> 200" "200" "$(status_code -X PUT "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{"status":"in_progress"}' "$BASE/orders/$TR/status")"
assert_status "PUT /payment-status paid -> 200" "200" "$(status_code -X PUT "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{"status":"paid"}' "$BASE/orders/$TR/payment-status")"
assert_status "PUT /delivery-status shipped -> 200" "200" "$(status_code -X PUT "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{"status":"shipped"}' "$BASE/orders/$TR/delivery-status")"
assert_status "PUT /status unknown name -> 409" "409" "$(status_code -X PUT "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{"status":"invalid"}' "$BASE/orders/$TR/status")"
assert_status "PUT /status no field -> 422" "422" "$(status_code -X PUT "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{}' "$BASE/orders/$TR/status")"

echo ""
echo "=== Spec-compliant Order shape ==="
assert_field() {
  local d="$1" json="$2" path="$3" v
  v=$(echo "$json" | python3 -c "
import sys,json
try:
  o=json.load(sys.stdin)
  for k in '$path'.split('.'):
    o=o[int(k[1:-1])] if k.startswith('[') else o[k]
  print('present' if o is not None else 'null')
except Exception: print('missing')")
  [[ "$v" == "present" ]] && { echo "✓ $d"; ((PASS++))||true; } || { echo "✗ $d — $v at $path"; ((FAIL++))||true; }
}

SAMPLE_ID=$(echo "$LIST" | jqpy "d['items'][0]['id']")
OJSON=$(curl -s "${AUTH[@]}" "$BASE/orders/$SAMPLE_ID")
for f in paymentStatus deliveryStatus currency version customer billingAddress lineItems; do
  assert_field "GET single has $f" "$OJSON" "$f"
done
assert_field "GET single has customer.email" "$OJSON" "customer.email"
assert_field "GET single has lineItems[0].id" "$OJSON" "lineItems.[0].id"
assert_field "GET single has total.amount" "$OJSON" "total.amount"

ETAG=$(curl -s -I "${AUTH[@]}" "$BASE/orders/$SAMPLE_ID" | grep -i '^etag:' | awk '{print $2}' | tr -d '\r\n')
[[ -n "$ETAG" ]] && ok "GET single returns ETag header ($ETAG)" || bad "GET single ETag missing"

echo ""
echo "=== POST validation ==="
NEW_ID=$(create_order)
[[ -n "$NEW_ID" ]] && ok "POST /orders created $NEW_ID" || bad "POST /orders create"
assert_status "POST missing salesChannelId -> 422" "422" "$(status_code -X POST "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d "{\"lineItems\":[{\"productId\":\"$SHOPWARE_TEST_PRODUCT_ID\",\"quantity\":1}]}" "$BASE/orders")"
assert_status "POST missing lineItems -> 422" "422" "$(status_code -X POST "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d "{\"salesChannelId\":\"$SHOPWARE_SALES_CHANNEL_ID\"}" "$BASE/orders")"

echo ""
echo "=== PATCH ==="
PATCH_ID=$(create_order)
COMMENT=$(curl -s -X PATCH "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{"customerComment":"Bitte klingeln"}' "$BASE/orders/$PATCH_ID" | jqpy "d.get('customerComment','')")
assert_eq "PATCH updates customerComment" "Bitte klingeln" "$COMMENT"
assert_status "PATCH empty body -> 422" "422" "$(status_code -X PATCH "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{}' "$BASE/orders/$PATCH_ID")"
assert_status "PATCH unknown id -> 404" "404" "$(status_code -X PATCH "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{"customerComment":"x"}' "$BASE/orders/b878ba70bf7d47a12ae61ad5b1dc8582")"
NEW_STREET=$(curl -s -X PATCH "${AUTH[@]}" "${JSON[@]}" -H "Idempotency-Key: $(idem)" -d '{"shippingAddress":{"street":"Neue Strasse 9"}}' "$BASE/orders/$PATCH_ID" | python3 -c "import sys,json;print((json.load(sys.stdin).get('shippingAddress') or {}).get('street',''))" 2>/dev/null)
assert_eq "PATCH shippingAddress persists street" "Neue Strasse 9" "$NEW_STREET"

echo ""
echo "=== Delivery sub-resource (incl. T10) ==="
# Note: DeliveryController is not idempotency-wired in T2 (follow-up), so these
# do not need an Idempotency-Key.
DORD=$(create_order)
DID=$(curl -s "${AUTH[@]}" "$BASE/orders/$DORD" | jqpy "d['deliveries'][0]['id']")
assert_status "GET /deliveries -> 200" "200" "$(status_code "${AUTH[@]}" "$BASE/orders/$DORD/deliveries")"
NEW_DID=$(curl -s -X POST "${AUTH[@]}" "${JSON[@]}" -d '{}' "$BASE/orders/$DORD/deliveries" | jqpy "d.get('id','')")
[[ -n "$NEW_DID" ]] && ok "POST /deliveries split shipment $NEW_DID" || bad "POST /deliveries"
assert_status "GET /deliveries/{id} -> 200" "200" "$(status_code "${AUTH[@]}" "$BASE/orders/$DORD/deliveries/$DID")"
assert_status "PUT /deliveries/{id}/status shipped -> 200" "200" "$(status_code -X PUT "${AUTH[@]}" "${JSON[@]}" -d '{"status":"shipped"}' "$BASE/orders/$DORD/deliveries/$DID/status")"
assert_status "PUT /deliveries/{id}/status no field -> 422" "422" "$(status_code -X PUT "${AUTH[@]}" "${JSON[@]}" -d '{}' "$BASE/orders/$DORD/deliveries/$DID/status")"
DCT=$(curl -s -D - -o /dev/null -X PUT "${AUTH[@]}" "${JSON[@]}" -d '{}' "$BASE/orders/$DORD/deliveries/$DID/status" | grep -i '^content-type:' | tr -d '\r')
echo "$DCT" | grep -qi 'application/problem+json' && ok "delivery 422 is application/problem+json (T10)" || bad "delivery 422 content-type (got: $DCT)"

echo ""
echo "=== DELETE soft cancel (incl. T7 idempotency) ==="
DEL=$(create_order)
assert_status "DELETE -> 204" "204" "$(status_code -X DELETE "${AUTH[@]}" -H "Idempotency-Key: $(idem)" "$BASE/orders/$DEL")"
assert_eq "DELETE sets status cancelled" "cancelled" "$(curl -s "${AUTH[@]}" "$BASE/orders/$DEL" | jqpy "d['status']")"
assert_status "DELETE again (idempotent) -> 204" "204" "$(status_code -X DELETE "${AUTH[@]}" -H "Idempotency-Key: $(idem)" "$BASE/orders/$DEL")"
assert_eq "still cancelled after re-DELETE" "cancelled" "$(curl -s "${AUTH[@]}" "$BASE/orders/$DEL" | jqpy "d['status']")"
assert_status "DELETE unknown id -> 404" "404" "$(status_code -X DELETE "${AUTH[@]}" -H "Idempotency-Key: $(idem)" "$BASE/orders/b878ba70bf7d47a12ae61ad5b1dc8582")"
assert_status "DELETE ?hard=true -> 403" "403" "$(status_code -X DELETE "${AUTH[@]}" -H "Idempotency-Key: $(idem)" "$BASE/orders/$DEL?hard=true")"

echo ""
echo "Results: $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1
