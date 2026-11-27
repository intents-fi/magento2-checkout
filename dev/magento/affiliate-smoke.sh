#!/usr/bin/env bash
# The optional affiliate ID on the dev/magento install, through the real admin, against
# connect-mock.php standing in for api.intents.fi and dashboard.intents.fi.
#
#   (cd dev/magento/src && php -S 127.0.0.1:8080 -t pub phpserver/router.php) &
#   dev/magento/affiliate-smoke.sh
#
# Points app/etc/env.php's intentsfi_checkout api_base and dashboard_url at the mock for the run
# and puts them back after. Checks: Save Config lowercases a valid ID and names its affiliate
# (GET /v0/ref/resolve, with no key), warns on an unknown ID or an unreachable API and still saves,
# refuses an invalid ID and keeps the old value, and empty clears it; Connect to Intents carries
# `ref` only when an ID is set; Test connection shows "Referred by" when the merchant has one; and
# invoice create sends X-Intents-Affiliate only when an ID is set.
# No pipefail: `grep -q` and `head` close their pipe early on purpose.
set -eu

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/src"
BASE="${BASE:-http://127.0.0.1:8080}"
MOCK_PORT="${MOCK_PORT:-8791}"
MOCK="http://127.0.0.1:$MOCK_PORT"
TMP="$(mktemp -d)"
export CONNECT_MOCK_LOG="$TMP/mock.log"
export CONNECT_MOCK_REFERRED_BY="Oak Studio"
JAR="$TMP/cookies"
M() { (cd "$SRC" && php bin/magento "$@"); }
H() { (cd "$SRC" && php "$HERE/smoke.php" "$@"); }
fail() { echo "FAIL: $*" >&2; exit 1; }
php -S "127.0.0.1:$MOCK_PORT" "$HERE/connect-mock.php" >"$TMP/mock.out" 2>&1 &
MOCK_PID=$!
cp "$SRC/app/etc/env.php" "$TMP/env.php"
cleanup() { kill "$MOCK_PID" 2>/dev/null || true; cp "$TMP/env.php" "$SRC/app/etc/env.php"; M cache:flush >/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT

php -r '$f=$argv[1]; $c=include $f; $c["intentsfi_checkout"]=["api_base"=>$argv[2],"dashboard_url"=>$argv[2]];
file_put_contents($f, "<?php\nreturn ".var_export($c, true).";\n");' "$SRC/app/etc/env.php" "$MOCK"
M config:set payment/intentsfi_checkout/active 1 >/dev/null
M config:set payment/intentsfi_checkout/api_key ik_test_Hq7Vb2Lm9Xc4Rt6Yw8Zp3Nk5 >/dev/null
M config:set payment/intentsfi_checkout/webhook_secret whsec_Pq8Lk3Mn7Vb2Xc5Zt9Rw4Yh6 >/dev/null
M config:delete payment/intentsfi_checkout/affiliate_id >/dev/null 2>&1 || true
M cache:flush >/dev/null
sleep 1

saved() { M config:show payment/intentsfi_checkout/affiliate_id 2>/dev/null || true; }
resolve_calls() { grep -c '"path":"\\/v0\\/ref\\/resolve"' "$CONNECT_MOCK_LOG" || true; }

echo "== admin login"
LOGIN_PAGE=$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin/")
FORM_KEY=$(printf '%s' "$LOGIN_PAGE" | sed -n 's/.*name="form_key" type="hidden" value="\([^"]*\)".*/\1/p' | head -1)
[ -n "$FORM_KEY" ] || fail "no form key on the admin login page"
curl -s -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/admin/" \
  --data-urlencode "form_key=$FORM_KEY" --data-urlencode "login[username]=admin" --data-urlencode 'login[password]=Admin12345!'
EDIT=$(curl -s -c "$JAR" -b "$JAR" -L "$BASE/admin/" | grep -o 'href="[^"#]*system_config/edit/section/[^"#]*' | head -1 \
  | sed 's/^href="//; s#/section/[^/]*/#/section/payment/#')
[ -n "$EDIT" ] || fail "admin login failed"
PAGE=$(curl -s -c "$JAR" -b "$JAR" -L "$EDIT")
printf '%s' "$PAGE" | grep -q 'Affiliate ID (optional)' || fail "no Affiliate ID field on the payment settings"
printf '%s' "$PAGE" | grep -qF "Set up by an agency or developer? Paste their Intents affiliate ID. It doesn&#039;t change your fee. It only applies before your store&#039;s first live payment." \
  || printf '%s' "$PAGE" | grep -qF "Set up by an agency or developer? Paste their Intents affiliate ID. It doesn't change your fee. It only applies before your store's first live payment." \
  || fail "the help text is not the agreed one"
SAVE=$(printf '%s' "$PAGE" | grep -o 'action="[^"]*system_config/save[^"]*"' | head -1 | sed 's/^action="//; s/"$//')
[ -n "$SAVE" ] || fail "no Save Config form"

# Save Config with only the affiliate field; prints the page it lands back on.
save() {
  local page fk
  page=$(curl -s -c "$JAR" -b "$JAR" -L "$EDIT")
  fk=$(printf '%s' "$page" | sed -n "s/.*FORM_KEY = '\([^']*\)'.*/\1/p" | head -1)
  curl -s -c "$JAR" -b "$JAR" -L "$SAVE" --data-urlencode "form_key=$fk" \
    --data-urlencode "groups[intentsfi_checkout][fields][affiliate_id][value]=$1"
}

echo "== a valid ID is lowercased and named"
save ' Oak-Studio ' > "$TMP/p1.html"
grep -q 'Linked to Oak Studio.' "$TMP/p1.html" || fail "no Linked to Oak Studio"
[ "$(saved)" = "oak-studio" ] || fail "saved as '$(saved)', not oak-studio"
grep '"path":"\\/v0\\/ref\\/resolve"' "$CONNECT_MOCK_LOG" | tail -1 | grep -q '"auth":"","affiliate":null,"query":{"code":"oak-studio"}' \
  || fail "resolve did not send only the ID: $(grep resolve "$CONNECT_MOCK_LOG" | tail -1)"
echo "saved: $(saved); resolve calls: $(resolve_calls)"

echo "== saving again without a change asks nothing"
before=$(resolve_calls)
save 'oak-studio' > /dev/null
[ "$(resolve_calls)" = "$before" ] || fail "resolve called for an unchanged ID"

echo "== an invalid ID is refused and the old one kept"
save 'bad id!' > "$TMP/p2.html"
grep -q 'Affiliate IDs are 6 to 16 lowercase letters, digits or hyphens.' "$TMP/p2.html" || fail "no refusal message"
[ "$(saved)" = "oak-studio" ] || fail "an invalid ID replaced the saved one: $(saved)"
save 'ab' > "$TMP/p2b.html"
grep -q 'Affiliate IDs are 6 to 16' "$TMP/p2b.html" || fail "ab not refused"

echo "== an unknown ID is a warning and still saved"
save 'nobody-here' > "$TMP/p3.html"
grep -q 'Unknown affiliate ID' "$TMP/p3.html" || fail "no Unknown affiliate ID warning"
[ "$(saved)" = "nobody-here" ] || fail "unknown ID not saved: $(saved)"

echo "== an unreachable API is a warning and still saved"
save 'down-for-test' > "$TMP/p4.html"
grep -q 'check the affiliate ID right now. It was saved.' "$TMP/p4.html" || fail "no could-not-check warning"
[ "$(saved)" = "down-for-test" ] || fail "ID not saved while the API was down: $(saved)"

echo "== Connect carries ref only when an ID is set"
save 'oak-studio' > /dev/null
start_location() {
  local page start
  page=$(curl -s -c "$JAR" -b "$JAR" -L "$EDIT")
  start=$(printf '%s' "$page" | grep -o "href=\"[^\"]*intentsfi_checkout/connect/start/[^\"]*mode/test/[^\"]*\"" | head -1 | sed 's/^href="//; s/"$//; s/&amp;/\&/g')
  [ -n "$start" ] || fail "no Connect to Intents link"
  curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{redirect_url}' "$start"
}
LOC=$(start_location)
case "$LOC" in "$MOCK/connect?"*) ;; *) fail "Start did not go to the dashboard: $LOC";; esac
printf '%s' "$LOC" | grep -q '[?&]ref=oak-studio\(&\|$\)' || fail "no ref=oak-studio in $LOC"
echo "with ID: $LOC"
save '' > "$TMP/p5.html"
[ -z "$(saved)" ] || fail "empty did not clear the ID: $(saved)"
LOC=$(start_location)
printf '%s' "$LOC" | grep -q '[?&]ref=' && fail "ref sent with no ID: $LOC"
printf '%s' "$LOC" | grep -q '[?&]partner=' && fail "partner sent: $LOC"
echo "without ID: no ref"

echo "== Test connection names the referrer"
PAGE=$(curl -s -c "$JAR" -b "$JAR" -L "$EDIT")
TC=$(printf '%s' "$PAGE" | grep -o "url: '[^']*testConnection[^']*'" | head -1 | sed "s/^url: '//; s/'$//" \
  | php -r 'echo json_decode("\"" . trim(stream_get_contents(STDIN)) . "\"");')
FK=$(printf '%s' "$PAGE" | sed -n "s/.*FORM_KEY = '\([^']*\)'.*/\1/p" | head -1)
TCR=$(curl -s -c "$JAR" -b "$JAR" "$TC" --data-urlencode "form_key=$FK" -H 'X-Requested-With: XMLHttpRequest')
printf '%s' "$TCR" | grep -q 'Referred by Oak Studio' || fail "Test connection: $TCR"
echo "test connection: $TCR"

echo "== invoice create sends X-Intents-Affiliate only when set"
ADMIN=$(curl -sf -X POST "$BASE/rest/V1/integration/admin/token" -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"Admin12345!"}' | tr -d '"')
SKU="intentsfi-smoke"
curl -sf -X POST "$BASE/rest/V1/products" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' -d "{
  \"product\": {\"sku\":\"$SKU\",\"name\":\"Smoke test tee\",\"attribute_set_id\":4,\"price\":5,\"status\":1,
  \"visibility\":4,\"type_id\":\"simple\",\"weight\":1,
  \"extension_attributes\":{\"website_ids\":[1],\"stock_item\":{\"qty\":1000,\"is_in_stock\":true}}}}" >/dev/null || true
curl -sf -X POST "$BASE/rest/V1/inventory/source-items" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
  -d "{\"sourceItems\":[{\"sku\":\"$SKU\",\"source_code\":\"default\",\"quantity\":1000,\"status\":1}]}" >/dev/null || true
M indexer:reindex >/dev/null 2>&1 || true
order() {
  local cart addr id
  cart=$(curl -sf -X POST "$BASE/rest/V1/guest-carts" | tr -d '"')
  curl -sf -X POST "$BASE/rest/V1/guest-carts/$cart/items" -H 'Content-Type: application/json' \
    -d "{\"cartItem\":{\"sku\":\"$SKU\",\"qty\":1,\"quote_id\":\"$cart\"}}" >/dev/null
  addr='{"firstname":"Ada","lastname":"Lovelace","street":["1 Market St"],"city":"San Francisco","region_id":12,"region":"California","postcode":"94105","country_id":"US","telephone":"4155550100","email":"ada.smoke@example.com"}'
  curl -sf -X POST "$BASE/rest/V1/guest-carts/$cart/shipping-information" -H 'Content-Type: application/json' \
    -d "{\"addressInformation\":{\"shipping_address\":$addr,\"billing_address\":$addr,\"shipping_carrier_code\":\"flatrate\",\"shipping_method_code\":\"flatrate\"}}" >/dev/null
  id=$(curl -sf -X POST "$BASE/rest/V1/guest-carts/$cart/payment-information" -H 'Content-Type: application/json' \
    -d "{\"email\":\"ada.smoke@example.com\",\"paymentMethod\":{\"method\":\"intentsfi_checkout\"},\"billingAddress\":$addr}" | tr -d '"')
  curl -sf "$BASE/rest/V1/orders/$id" -H "Authorization: Bearer $ADMIN" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["increment_id"];'
}
last_invoice_affiliate() { grep '"path":"\\/v0\\/invoices"' "$CONNECT_MOCK_LOG" | tail -1 | php -r '$l=json_decode(stream_get_contents(STDIN),true); echo json_encode($l["affiliate"]);'; }

save 'Oak-Studio' > /dev/null
INC=$(order); [ -n "$INC" ] || fail "no order"
H pay-url "$INC" | grep -q '^https://pay.intents.fi/' || fail "no hosted URL for $INC"
[ "$(last_invoice_affiliate)" = '"oak-studio"' ] || fail "invoice create sent $(last_invoice_affiliate), not oak-studio"
echo "order $INC: X-Intents-Affiliate oak-studio"
grep '"path":"\\/v0\\/merchant"' "$CONNECT_MOCK_LOG" | grep -q '"affiliate":null' || fail "the header went on another call"

save '' > /dev/null
INC=$(order); [ -n "$INC" ] || fail "no order"
H pay-url "$INC" | grep -q '^https://pay.intents.fi/' || fail "no hosted URL for $INC"
[ "$(last_invoice_affiliate)" = 'null' ] || fail "invoice create sent $(last_invoice_affiliate) with no ID set"
echo "order $INC: no header"

echo "OK: affiliate ID"
