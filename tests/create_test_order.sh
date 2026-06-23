#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/../.env.test"

CF_ARGS=()
if [[ -n "${CF_ACCESS_CLIENT_ID:-}" && -n "${CF_ACCESS_CLIENT_SECRET:-}" ]]; then
  CF_ARGS=(-H "CF-Access-Client-Id: $CF_ACCESS_CLIENT_ID" -H "CF-Access-Client-Secret: $CF_ACCESS_CLIENT_SECRET")
fi

: "${SHOPWARE_TEST_PRODUCT_ID:?Missing SHOPWARE_TEST_PRODUCT_ID in .env.test}"
: "${SHOPWARE_STORE_ACCESS_KEY:?Missing SHOPWARE_STORE_ACCESS_KEY in .env.test}"

# 1. Gast-Kontext anlegen
CONTEXT_TOKEN=$(curl -sf -X GET "$SHOPWARE_URL/store-api/context" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  "${CF_ARGS[@]}" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")
echo "✓ Context token: $CONTEXT_TOKEN"

# 2. Gast registrieren — neuer Token kommt im Response-Header
REGISTER_RESPONSE=$(curl -sf -D - -X POST "$SHOPWARE_URL/store-api/account/register" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
  "${CF_ARGS[@]}" \
  -d "{
    \"email\": \"testorder+$(date +%s)@example.com\",
    \"password\": \"Test1234!\",
    \"guest\": true,
    \"firstName\": \"Test\",
    \"lastName\": \"Customer\",
    \"billingAddress\": {
      \"street\": \"Teststrasse 1\",
      \"zipcode\": \"12345\",
      \"city\": \"Berlin\",
      \"countryId\": \"019e77c7b1d771e594804b0ab7ed9071\"
    },
    \"storefrontUrl\": \"${SHOPWARE_STOREFRONT_URL:-$SHOPWARE_URL}\"
  }")

CONTEXT_TOKEN=$(echo "$REGISTER_RESPONSE" | grep -i "sw-context-token:" | awk '{print $2}' | tr -d '\r')
echo "✓ Guest registered, new token: $CONTEXT_TOKEN"

# 3. Produkt in Cart legen
curl -sf -X POST "$SHOPWARE_URL/store-api/checkout/cart/line-item" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
  "${CF_ARGS[@]}" \
  -d "{
    \"items\": [{
      \"type\": \"product\",
      \"referencedId\": \"$SHOPWARE_TEST_PRODUCT_ID\",
      \"quantity\": 1
    }]
  }" > /dev/null
echo "✓ Product added to cart"

# 4. Order abschicken
ORDER=$(curl -sf -X POST "$SHOPWARE_URL/store-api/checkout/order" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
  "${CF_ARGS[@]}" \
  -d '{}')

ORDER_ID=$(echo "$ORDER" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
ORDER_NUMBER=$(echo "$ORDER" | python3 -c "import sys,json; print(json.load(sys.stdin)['orderNumber'])")
echo "✓ Order created: $ORDER_NUMBER ($ORDER_ID)"
