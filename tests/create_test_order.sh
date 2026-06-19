#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/../.env.test"

# 1. Gast-Kontext anlegen
CONTEXT_TOKEN=$(curl -sf -X GET "$SHOPWARE_URL/store-api/context" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")
echo "✓ Context token: $CONTEXT_TOKEN"

# 2. Gast registrieren — neuer Token kommt im Response-Header
REGISTER_RESPONSE=$(curl -sf -D - -X POST "$SHOPWARE_URL/store-api/account/register" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
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
    \"storefrontUrl\": \"http://127.0.0.1:8000\"
  }")

CONTEXT_TOKEN=$(echo "$REGISTER_RESPONSE" | grep -i "sw-context-token:" | awk '{print $2}' | tr -d '\r')
echo "✓ Guest registered, new token: $CONTEXT_TOKEN"

# 3. Produkt in Cart legen
curl -sf -X POST "$SHOPWARE_URL/store-api/checkout/cart/line-item" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "items": [{
      "type": "product",
      "referencedId": "11dc680240b04f469ccba354cbf0b967",
      "quantity": 1
    }]
  }' > /dev/null
echo "✓ Product added to cart"

# 4. Order abschicken
ORDER=$(curl -sf -X POST "$SHOPWARE_URL/store-api/checkout/order" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}')

ORDER_ID=$(echo "$ORDER" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
ORDER_NUMBER=$(echo "$ORDER" | python3 -c "import sys,json; print(json.load(sys.stdin)['orderNumber'])")
echo "✓ Order created: $ORDER_NUMBER ($ORDER_ID)"
