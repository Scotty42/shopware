#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ENV_FILE="$SCRIPT_DIR/../.env.test"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing .env.test — copy .env.test.dist and fill in credentials"
  exit 1
fi

source "$ENV_FILE"

TOKEN=$(curl -sf -X POST "$SHOPWARE_URL/api/oauth/token" \
  -H 'Content-Type: application/json' \
  -d "{\"grant_type\":\"password\",\"client_id\":\"administration\",\"username\":\"$SHOPWARE_ADMIN_USER\",\"password\":\"$SHOPWARE_ADMIN_PASSWORD\",\"scopes\":\"write\"}" \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")

echo "✓ Token acquired"

RESPONSE=$(curl -sf "$SHOPWARE_URL/api/order-integration/v1/orders" \
  -H "Authorization: Bearer $TOKEN")

echo "✓ GET /api/order-integration/v1/orders"
echo "$RESPONSE" | python3 -m json.tool
