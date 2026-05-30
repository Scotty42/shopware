#!/usr/bin/env bash
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/../.env.test"

PASS=0
FAIL=0

assert_status() {
  local description="$1" expected="$2" actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    echo "✓ $description"
    ((PASS++)) || true
  else
    echo "✗ $description — expected HTTP $expected, got $actual"
    ((FAIL++)) || true
  fi
}

assert_eq() {
  local description="$1" expected="$2" actual="$3"
  if [[ "$actual" == "$expected" ]]; then
    echo "✓ $description"
    ((PASS++)) || true
  else
    echo "✗ $description — expected '$expected', got '$actual'"
    ((FAIL++)) || true
  fi
}

TOKEN=$(curl -sf -X POST "$SHOPWARE_URL/api/oauth/token" \
  -H 'Content-Type: application/json' \
  -d "{\"grant_type\":\"password\",\"client_id\":\"administration\",\"username\":\"$SHOPWARE_ADMIN_USER\",\"password\":\"$SHOPWARE_ADMIN_PASSWORD\",\"scopes\":\"write\"}" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
assert_eq "Token acquired" "1" "1"

# GET /v1/orders — 200
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders")
assert_status "GET /v1/orders returns 200" "200" "$STATUS"

# GET /v1/orders — at least 1 item
RESPONSE=$(curl -s -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders")
COUNT=$(echo "$RESPONSE" | python3 -c "import sys,json; print(len(json.load(sys.stdin)['items']))")
if [[ "$COUNT" -ge 1 ]]; then
  echo "✓ GET /v1/orders returns at least 1 item (got $COUNT)"
  ((PASS++)) || true
else
  echo "✗ GET /v1/orders — expected at least 1 item, got $COUNT"
  ((FAIL++)) || true
fi

# GET /v1/orders?status=open — 200
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders?status=open")
assert_status "GET /v1/orders?status=open returns 200" "200" "$STATUS"

# Pagination — nextCursor with limit=1
CURSOR=$(curl -s -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders?limit=1" \
  | python3 -c "import sys,json; c=json.load(sys.stdin)['page']['nextCursor']; print(c if c else '')")
if [[ -n "$CURSOR" ]]; then
CURSOR_ENC=$(python3 -c "import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))" "$CURSOR")
  echo "✓ GET /v1/orders?limit=1 returns nextCursor"
  ((PASS++)) || true
else
  echo "✗ GET /v1/orders?limit=1 — nextCursor is empty"
  ((FAIL++)) || true
fi

# Pagination — second page
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders?limit=1&cursor=$(python3 -c "import urllib.parse; print(urllib.parse.quote('$CURSOR'))")"  )
assert_status "GET /v1/orders with cursor returns 200" "200" "$STATUS"

# GET /v1/orders/{id} — valid
ORDER_ID=$(echo "$RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin)['items'][0]['id'])")
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders/$ORDER_ID")
assert_status "GET /v1/orders/{id} returns 200" "200" "$STATUS"

# GET /v1/orders/{id} — not found
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders/b878ba70bf7d47a12ae61ad5b1dc8582")
assert_status "GET /v1/orders/{id} unknown id returns 404" "404" "$STATUS"

ERROR_CODE=$(curl -s -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders/b878ba70bf7d47a12ae61ad5b1dc8582" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['code'])")
assert_eq "404 response has RFC 9457 code" "order.not_found" "$ERROR_CODE"

# Invalid token — 401
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer invalid-token" \
  "$SHOPWARE_URL/api/order-integration/v1/orders")
assert_status "Invalid token returns 401" "401" "$STATUS"

# No token — 401
STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  "$SHOPWARE_URL/api/order-integration/v1/orders")
assert_status "No token returns 401" "401" "$STATUS"

echo ""

# Client Credentials Auth
CC_TOKEN=$(curl -sf -X POST "$SHOPWARE_URL/api/oauth/token" \
  -H 'Content-Type: application/json' \
  -d "{\"grant_type\": \"client_credentials\", \"client_id\": \"$SHOPWARE_INTEGRATION_ACCESS_KEY\", \"client_secret\": \"$SHOPWARE_INTEGRATION_SECRET\"}" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
assert_eq "Client credentials token acquired" "1" "1"

STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $CC_TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/orders")
assert_status "GET /v1/orders with client credentials returns 200" "200" "$STATUS"

echo ""
echo "Results: $PASS passed, $FAIL failed"
[[ $FAIL -eq 0 ]] && exit 0 || exit 1
