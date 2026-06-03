#!/usr/bin/env bash
#
# Integration test for the CQRS async write path (T13).
#
# Requires:
#   - a live Shopware backend with this plugin installed
#   - ORDER_INTEGRATION_ASYNC_WRITES=true and a configured
#     ORDER_INTEGRATION_DB_DSN (see docs/infrastructure-setup.md)
#   - either a running worker, or SHOPWARE_CONSOLE set so this script can drain
#     the queue once (e.g. SHOPWARE_CONSOLE="php /var/www/shopware/bin/console")
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/../.env.test"

PASS=0
FAIL=0
ok() { echo "✓ $1"; ((PASS++)) || true; }
bad() { echo "✗ $1"; ((FAIL++)) || true; }

TOKEN=$(curl -sf -X POST "$SHOPWARE_URL/api/oauth/token" \
  -H 'Content-Type: application/json' \
  -d "{\"grant_type\":\"password\",\"client_id\":\"administration\",\"username\":\"$SHOPWARE_ADMIN_USER\",\"password\":\"$SHOPWARE_ADMIN_PASSWORD\",\"scopes\":\"write\"}" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
[[ -n "$TOKEN" ]] && ok "Token acquired" || bad "Token acquisition"

BODY=$(cat <<JSON
{"salesChannelId":"$SHOPWARE_SALES_CHANNEL_ID","lineItems":[{"productId":"$SHOPWARE_TEST_PRODUCT_ID","quantity":1}]}
JSON
)

# 1. async POST returns 202 + a job id
RESP=$(curl -s -w "\n%{http_code}" -X POST "$SHOPWARE_URL/api/order-integration/v1/orders" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Prefer: respond-async' \
  -H "Idempotency-Key: $(python3 -c 'import uuid;print(uuid.uuid4())')" \
  -d "$BODY")
STATUS=$(echo "$RESP" | tail -1)
JSON_BODY=$(echo "$RESP" | sed '$d')
[[ "$STATUS" == "202" ]] && ok "POST (async) returns 202" || bad "POST (async) returns 202 — got $STATUS"

JOB_ID=$(echo "$JSON_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('jobId',''))")
[[ -n "$JOB_ID" ]] && ok "202 carries a jobId" || bad "202 carries a jobId"

# 2. job is retrievable
JOB_STATUS=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TOKEN" \
  "$SHOPWARE_URL/api/order-integration/v1/jobs/$JOB_ID")
[[ "$JOB_STATUS" == "200" ]] && ok "GET /v1/jobs/{id} returns 200" || bad "GET /v1/jobs/{id} returns 200 — got $JOB_STATUS"

# 3. drain the queue (if a console is provided) and wait for completion
if [[ -n "${SHOPWARE_CONSOLE:-}" ]]; then
  $SHOPWARE_CONSOLE order-integration:write-queue:drain --once >/dev/null 2>&1 || true
fi

ORDER_ID=""
for _ in $(seq 1 15); do
  J=$(curl -s -H "Authorization: Bearer $TOKEN" "$SHOPWARE_URL/api/order-integration/v1/jobs/$JOB_ID")
  S=$(echo "$J" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))")
  if [[ "$S" == "succeeded" ]]; then
    ORDER_ID=$(echo "$J" | python3 -c "import sys,json; print((json.load(sys.stdin).get('result') or {}).get('orderId',''))")
    break
  fi
  [[ "$S" == "dead" ]] && break
  sleep 1
done
[[ -n "$ORDER_ID" ]] && ok "Job succeeded and produced an orderId" || bad "Job did not complete (is a worker running?)"

# 4. the created order is now readable
if [[ -n "$ORDER_ID" ]]; then
  OS=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TOKEN" \
    "$SHOPWARE_URL/api/order-integration/v1/orders/$ORDER_ID")
  [[ "$OS" == "200" ]] && ok "Created order is readable" || bad "Created order readable — got $OS"
fi

echo "-----------------------------------------"
echo "PASS=$PASS FAIL=$FAIL"
[[ "$FAIL" -eq 0 ]]
