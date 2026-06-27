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

# storefrontUrl must match a configured Shopware storefront domain.
# Shopware 6.7 validates this strictly; if registration fails with a domain error,
# set SHOPWARE_STOREFRONT_URL explicitly in .env.test.
STOREFRONT_URL="${SHOPWARE_STOREFRONT_URL:-}"
if [[ -z "$STOREFRONT_URL" ]]; then
  STOREFRONT_URL="$SHOPWARE_URL"
  echo "WARNING: SHOPWARE_STOREFRONT_URL not set -- falling back to SHOPWARE_URL ($SHOPWARE_URL)" >&2
  echo "         Set SHOPWARE_STOREFRONT_URL in .env.test if registration fails with a domain error" >&2
fi

# 1. Gast-Kontext anlegen
CONTEXT_TOKEN=$(curl -sf --max-time 30 -X GET "$SHOPWARE_URL/store-api/context" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  "${CF_ARGS[@]}" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['token'])")
echo "Context token: $CONTEXT_TOKEN"

# 2. Land-ID dynamisch ermitteln (vermeidet installations-spezifische UUIDs)
COUNTRY_ISO="${SHOPWARE_TEST_COUNTRY_ISO:-DE}"
COUNTRY_ID=$(curl -sf --max-time 30 -X POST "$SHOPWARE_URL/store-api/country" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
  "${CF_ARGS[@]}" \
  -d "{\"filter\":[{\"type\":\"equals\",\"field\":\"iso\",\"value\":\"$COUNTRY_ISO\"}],\"limit\":1}" \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['elements'][0]['id'])")
echo "Country ID ($COUNTRY_ISO): $COUNTRY_ID"

# 3. Gast registrieren -- neuer Token kommt im Response-Header
# -D <file> schreibt Header in eine separate Datei; Body geht nach /dev/null.
# Damit ist grep auf die reine Header-Datei eindeutig (kein Body-Gemisch wie bei -D -).
HEADER_FILE=$(mktemp)
trap 'rm -f "$HEADER_FILE"' EXIT

curl -sf --max-time 30 -D "$HEADER_FILE" -X POST "$SHOPWARE_URL/store-api/account/register" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
  "${CF_ARGS[@]}" \
  -d "{
    \"email\": \"testorder+$(python3 -c 'import uuid; print(uuid.uuid4().hex[:12])')@example.com\",
    \"password\": \"Test1234!\",
    \"guest\": true,
    \"firstName\": \"Test\",
    \"lastName\": \"Customer\",
    \"billingAddress\": {
      \"street\": \"Teststrasse 1\",
      \"zipcode\": \"12345\",
      \"city\": \"Berlin\",
      \"countryId\": \"$COUNTRY_ID\"
    },
    \"storefrontUrl\": \"$STOREFRONT_URL\"
  }" > /dev/null

CONTEXT_TOKEN=$(grep -i "sw-context-token:" "$HEADER_FILE" | awk '{print $2}' | tr -d '\r')
if [[ -z "$CONTEXT_TOKEN" ]]; then
  echo "ERROR: sw-context-token missing from registration response headers" >&2
  exit 1
fi
echo "Guest registered, new token: $CONTEXT_TOKEN"

# 4. Produkt in Cart legen
curl -sf --max-time 30 -X POST "$SHOPWARE_URL/store-api/checkout/cart/line-item" \
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
echo "Product added to cart"

# 5. Order abschicken
ORDER=$(curl -sf --max-time 30 -X POST "$SHOPWARE_URL/store-api/checkout/order" \
  -H "sw-access-key: $SHOPWARE_STORE_ACCESS_KEY" \
  -H "sw-context-token: $CONTEXT_TOKEN" \
  -H "Content-Type: application/json" \
  "${CF_ARGS[@]}" \
  -d '{}')

ORDER_ID=$(echo "$ORDER" | python3 -c "import sys,json; print(json.load(sys.stdin)['id'])")
ORDER_NUMBER=$(echo "$ORDER" | python3 -c "import sys,json; print(json.load(sys.stdin)['orderNumber'])")
echo "Order created: $ORDER_NUMBER ($ORDER_ID)"
