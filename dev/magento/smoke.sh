#!/usr/bin/env bash
# End-to-end check of IntentsFi_Checkout on the dev/magento install, in TEST mode.
#
#   INTENTSFI_TEST_KEY=ik_test_… dev/magento/smoke.sh
#
# Needs the store served at $BASE (php -S, see install.sh). It:
#   1. configures the method with the test key and a local webhook secret,
#   2. places a real guest order over the REST API with the method,
#   3. creates the invoice through the module (a real test-mode invoice on api.intents.fi),
#   4. delivers signed webhooks to /intentsfi/webhook built from that real invoice:
#      processing, paid, a duplicate paid, then a late expired — and checks the order after each,
#   5. checks a bad signature is refused with 401.
# Test mode never moves funds. The webhooks are signed locally because intents.fi cannot reach a
# store on 127.0.0.1; on a public store, the real deliveries follow the same path.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="${MAGENTO_SRC:-$HERE/src}"
BASE="${BASE:-http://127.0.0.1:8080}"
KEY="${INTENTSFI_TEST_KEY:?set INTENTSFI_TEST_KEY to an ik_test_ key}"
API="${INTENTSFI_API:-https://api.intents.fi}"
SECRET="whsec_smoke$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
M() { (cd "$SRC" && php bin/magento "$@"); }
H() { (cd "$SRC" && php "$HERE/smoke.php" "$@"); }
fail() { echo "FAIL: $*" >&2; exit 1; }

case "$KEY" in ik_test_*) ;; *) fail "refusing to run with a key that is not ik_test_";; esac

echo "== configure"
M config:set payment/intentsfi_checkout/active 1 >/dev/null
M config:set payment/intentsfi_checkout/api_key "$KEY" >/dev/null
M config:set payment/intentsfi_checkout/webhook_secret "$SECRET" >/dev/null
M cache:flush >/dev/null

echo "== a product"
ADMIN=$(curl -sf -X POST "$BASE/rest/V1/integration/admin/token" -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"Admin12345!"}' | tr -d '"')
SKU="intentsfi-smoke"
curl -sf -X POST "$BASE/rest/V1/products" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' -d "{
  \"product\": {\"sku\":\"$SKU\",\"name\":\"Smoke test tee\",\"attribute_set_id\":4,\"price\":5,\"status\":1,
  \"visibility\":4,\"type_id\":\"simple\",\"weight\":1,
  \"extension_attributes\":{\"website_ids\":[1],\"stock_item\":{\"qty\":1000,\"is_in_stock\":true}}}}" >/dev/null || true
# Multi-source inventory: stock it on the default source too, or it cannot be added to a cart.
curl -sf -X POST "$BASE/rest/V1/inventory/source-items" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d "{\"sourceItems\":[{\"sku\":\"$SKU\",\"source_code\":\"default\",\"quantity\":1000,\"status\":1}]}" >/dev/null || true
M indexer:reindex >/dev/null 2>&1 || true

echo "== a guest order with the method"
CART=$(curl -sf -X POST "$BASE/rest/V1/guest-carts" | tr -d '"')
curl -sf -X POST "$BASE/rest/V1/guest-carts/$CART/items" -H 'Content-Type: application/json' \
  -d "{\"cartItem\":{\"sku\":\"$SKU\",\"qty\":1,\"quote_id\":\"$CART\"}}" >/dev/null
ADDR='{"firstname":"Ada","lastname":"Lovelace","street":["1 Market St"],"city":"San Francisco","region_id":12,"region":"California","postcode":"94105","country_id":"US","telephone":"4155550100","email":"ada.smoke@example.com"}'
curl -sf -X POST "$BASE/rest/V1/guest-carts/$CART/shipping-information" -H 'Content-Type: application/json' \
  -d "{\"addressInformation\":{\"shipping_address\":$ADDR,\"billing_address\":$ADDR,\"shipping_carrier_code\":\"flatrate\",\"shipping_method_code\":\"flatrate\"}}" > "$TMP/ship.json"
grep -q '"code":"intentsfi_checkout"' "$TMP/ship.json" || fail "intentsfi_checkout is not offered for a USD cart"
ORDER_ID=$(curl -sf -X POST "$BASE/rest/V1/guest-carts/$CART/payment-information" -H 'Content-Type: application/json' \
  -d "{\"email\":\"ada.smoke@example.com\",\"paymentMethod\":{\"method\":\"intentsfi_checkout\"},\"billingAddress\":$ADDR}" | tr -d '"')
INC=$(curl -sf "$BASE/rest/V1/orders/$ORDER_ID" -H "Authorization: Bearer $ADMIN" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["increment_id"];')
echo "order $INC"
H state "$INC" | grep -q '"state": "pending_payment"' || fail "order not pending_payment after place"

# -rN when the merchant already has an invoice for this increment id from another install with
# the same base URL (a shared test merchant): that invoice is never reused (README).
echo "== the invoice"
URL=$(H pay-url "$INC")
echo "$URL" | grep -q '^https://' || fail "no hosted URL"
URL2=$(H pay-url "$INC")
[ "$URL" = "$URL2" ] || fail "a second redirect made a second invoice"
INV=$(H state "$INC" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["payment"]["intentsfi_invoice_id"];')
curl -sf "$API/v0/invoices/$INV" -H "Authorization: Bearer $KEY" > "$TMP/invoice.json"
php -r '$i=json_decode(file_get_contents($argv[1]),true); if(preg_match("/^".preg_quote($argv[2],"/")."-[0-9a-f]{8}(-r[0-9]+)?$/",$i["reference"])!==1) {fwrite(STDERR,"reference ".$i["reference"]."\n"); exit(1);}' "$TMP/invoice.json" "$INC" \
  || fail "invoice reference is not <increment id>-<store key>[-rN]"
echo "invoice $INV for $URL"

deliver() { # deliver <event id> <type> <status> [secret] -> prints HTTP status
  php -r '$i=json_decode(file_get_contents($argv[1]),true); $i["status"]=$argv[4];
    echo json_encode(["id"=>$argv[2],"type"=>$argv[3],"createdAt"=>gmdate("c"),"data"=>$i], JSON_UNESCAPED_SLASHES);' \
    "$TMP/invoice.json" "$1" "$2" "$3" > "$TMP/body.json"
  local sig; sig=$(H sign "${4:-$SECRET}" "$TMP/body.json")
  curl -s -o "$TMP/resp.json" -w '%{http_code}' -X POST "$BASE/intentsfi/webhook" \
    -H 'Content-Type: application/json' -H "X-Intents-Signature: $sig" --data-binary @"$TMP/body.json"
}
state() { H state "$INC" | php -r '$s=json_decode(stream_get_contents(STDIN),true); echo $s["state"]."/".$s["invoices"];'; }

echo "== webhooks"
[ "$(deliver evt_smoke_bad invoice.paid paid whsec_wrong000000000000000)" = 401 ] || fail "bad signature not refused"
[ "$(state)" = "pending_payment/0" ] || fail "bad signature changed the order"
echo "bad signature: 401, order unchanged"

[ "$(deliver "evt_smoke_1_$INC" invoice.processing processing)" = 200 ] || fail "processing not accepted"
[ "$(state)" = "pending_payment/0" ] || fail "processing changed the state"
echo "processing: comment only"

[ "$(deliver "evt_smoke_2_$INC" invoice.paid paid)" = 200 ] || fail "paid not accepted"
[ "$(state)" = "processing/1" ] || fail "paid did not invoice the order: $(state)"
echo "paid: processing, 1 invoice"

[ "$(deliver "evt_smoke_2_$INC" invoice.paid paid)" = 200 ] || fail "duplicate not acknowledged"
grep -q '"duplicate":true' "$TMP/resp.json" || fail "duplicate not detected: $(cat "$TMP/resp.json")"
[ "$(state)" = "processing/1" ] || fail "duplicate changed the order"
echo "duplicate paid: acknowledged, still 1 invoice"

[ "$(deliver "evt_smoke_3_$INC" invoice.expired expired)" = 200 ] || fail "late expired not accepted"
[ "$(state)" = "processing/1" ] || fail "late expired undid a paid order"
echo "late expired: ignored, still processing"

echo "== order $INC"
H state "$INC"
echo "PASS"
