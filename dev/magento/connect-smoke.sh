#!/usr/bin/env bash
# Connect, Go live and Disconnect on the dev/magento install, through the real admin, against
# connect-mock.php standing in for api.intents.fi and dashboard.intents.fi.
#
#   (cd dev/magento/src && php -S 127.0.0.1:8080 -t pub phpserver/router.php) &
#   dev/magento/connect-smoke.sh
#
# Points app/etc/env.php's intentsfi_checkout api_base and dashboard_url at the mock for the run
# and puts them back after. Checks: the admin lands back connected in test mode with an encrypted
# key and a webhook secret; Go live swaps in the live key and revokes the test one; Disconnect
# revokes the live key and clears the scope. Use a real store and dashboard for the real thing.
# No pipefail: `grep -q` and `head` close their pipe early on purpose.
set -eu

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$HERE/src"
BASE="${BASE:-http://127.0.0.1:8080}"
MOCK_PORT="${MOCK_PORT:-8790}"
MOCK="http://127.0.0.1:$MOCK_PORT"
TMP="$(mktemp -d)"
export CONNECT_MOCK_LOG="$TMP/mock.log"
JAR="$TMP/cookies"
M() { (cd "$SRC" && php bin/magento "$@"); }
fail() { echo "FAIL: $*" >&2; exit 1; }
php -S "127.0.0.1:$MOCK_PORT" "$HERE/connect-mock.php" >"$TMP/mock.out" 2>&1 &
MOCK_PID=$!
cp "$SRC/app/etc/env.php" "$TMP/env.php"
cleanup() { kill "$MOCK_PID" 2>/dev/null || true; cp "$TMP/env.php" "$SRC/app/etc/env.php"; M cache:flush >/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT

php -r '$f=$argv[1]; $c=include $f; $c["intentsfi_checkout"]=["api_base"=>$argv[2],"dashboard_url"=>$argv[2]];
file_put_contents($f, "<?php\nreturn ".var_export($c, true).";\n");' "$SRC/app/etc/env.php" "$MOCK"
# Start from a store with no key, secret or connection.
(cd "$SRC" && php -r 'require "app/bootstrap.php"; $b=\Magento\Framework\App\Bootstrap::create(BP,$_SERVER);
  $r=$b->getObjectManager()->get(\Magento\Framework\App\ResourceConnection::class);
  $r->getConnection()->delete($r->getTableName("core_config_data"), ["path IN (?)" => ["payment/intentsfi_checkout/api_key","payment/intentsfi_checkout/webhook_secret","payment/intentsfi_checkout/connection"]]);')
M cache:flush >/dev/null
sleep 1

echo "== admin login"
LOGIN_PAGE=$(curl -s -c "$JAR" -b "$JAR" "$BASE/admin/")
FORM_KEY=$(printf '%s' "$LOGIN_PAGE" | sed -n 's/.*name="form_key" type="hidden" value="\([^"]*\)".*/\1/p' | head -1)
[ -n "$FORM_KEY" ] || fail "no form key on the admin login page"
curl -s -c "$JAR" -b "$JAR" -o /dev/null -X POST "$BASE/admin/" \
  --data-urlencode "form_key=$FORM_KEY" --data-urlencode "login[username]=admin" --data-urlencode 'login[password]=Admin12345!'
# Admin URLs carry a secret key per action: take the config editor's from the menu.
# (Or none, when the store has admin/security/use_form_key off.)
EDIT=$(curl -s -c "$JAR" -b "$JAR" -L "$BASE/admin/" | grep -o 'href="[^"#]*system_config/edit/section/[^"#]*' | head -1 \
  | sed 's/^href="//; s#/section/[^/]*/#/section/payment/#')
[ -n "$EDIT" ] || fail "admin login failed"
config_page() { curl -s -c "$JAR" -b "$JAR" -L "$EDIT"; }
PAGE=$(config_page); printf "%s" "$PAGE" > "${SMOKE_PAGE:-/dev/null}"
printf '%s' "$PAGE" > "${SMOKE_PAGE:-/dev/null}"
printf '%s' "$PAGE" | grep -q 'intentsfi-checkout-connect' || fail "the Connect panel is not on the payment settings page"
link() { printf '%s' "$PAGE" | grep -o "href=\"[^\"]*intentsfi_checkout/connect/start/[^\"]*mode/$1/[^\"]*\"" | head -1 | sed 's/^href="//; s/"$//; s/&amp;/\&/g'; }

# Press a Start link and play the dashboard: follow every redirect until the admin is back.
round_trip() {
  local start="$1" loc
  loc=$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{redirect_url}' "$start")
  case "$loc" in "$MOCK/connect?"*) ;; *) fail "Start did not send the admin to the dashboard: $loc";; esac
  printf '%s' "$loc" | grep -q 'platform=magento' || fail "platform is not magento"
  printf '%s' "$loc" | grep -q 'code_challenge_method=S256' || fail "no S256 challenge"
  curl -s -c "$JAR" -b "$JAR" -L -o "$TMP/back.html" -w '%{url_effective}\n' "$loc"
}
connection() { M config:show | sed -n 's|^payment/intentsfi_checkout/connection - ||p'; }
raw_key() { (cd "$SRC" && php -r 'require "app/bootstrap.php"; $b=\Magento\Framework\App\Bootstrap::create(BP,$_SERVER);
  $r=$b->getObjectManager()->get(\Magento\Framework\App\ResourceConnection::class); $c=$r->getConnection();
  echo (string) $c->fetchOne($c->select()->from($r->getTableName("core_config_data"),"value")->where("path = ?","payment/intentsfi_checkout/api_key")->where("scope = ?","default"));'); }
key_prefix() { (cd "$SRC" && php -r 'require "app/bootstrap.php"; $b=\Magento\Framework\App\Bootstrap::create(BP,$_SERVER);
  $om=$b->getObjectManager(); $c=$om->get(\IntentsFi\Checkout\Model\Config::class); echo substr($c->apiKeyForWebsite(null),0,8), " ", $c->webhookSecret() !== "" ? "secret" : "nosecret";'); }

echo "== connect (test)"
START=$(link test); [ -n "$START" ] || fail "no Connect intents.fi link"
round_trip "$START" | grep -q 'system_config/edit/section/payment' || fail "did not land back on the payment settings"
grep -q 'Connected. Your keys and payment notifications are set up.' "$TMP/back.html" || fail "no Connected message"
[ "$(key_prefix)" = "ik_test_ secret" ] || fail "test key and webhook secret not saved: $(key_prefix)"
connection | grep -q '"mode":"test"' || fail "connection not recorded"
case "$(raw_key)" in ik_*|"") fail "the key is not stored encrypted";; esac
grep -q '"path":"\\/v0\\/webhooks"' "$CONNECT_MOCK_LOG" || fail "no webhook registered"
grep '"path":"\\/v0\\/connect\\/token"' "$CONNECT_MOCK_LOG" | grep -q '"auth":""' || fail "the token exchange sent a key"
WANT_HOOK=$(php -r 'echo trim(json_encode($argv[1] . "/intentsfi/webhook"), "\"");' "$BASE")
grep '"path":"\\/v0\\/webhooks"' "$CONNECT_MOCK_LOG" | grep -qF "\"url\":\"$WANT_HOOK\"" || fail "wrong webhook URL"
echo "connected: $(connection)"

echo "== test connection"
PAGE=$(config_page)
TC=$(printf '%s' "$PAGE" | grep -o "url: '[^']*testConnection[^']*'" | head -1 | sed "s/^url: '//; s/'$//" \
  | php -r 'echo json_decode("\"" . trim(stream_get_contents(STDIN)) . "\"");')
[ -n "$TC" ] || fail "no Test connection button"
FK=$(printf '%s' "$PAGE" | sed -n "s/.*FORM_KEY = '\([^']*\)'.*/\1/p" | head -1)
TCR=$(curl -s -c "$JAR" -b "$JAR" "$TC" --data-urlencode "form_key=$FK" -H 'X-Requested-With: XMLHttpRequest')
printf '%s' "$TCR" | grep -q '"ok":true' || fail "Test connection: $TCR"
printf '%s' "$TCR" | grep -q 'Mock Goods' || fail "Test connection did not name the merchant: $TCR"
echo "test connection: $TCR"

TEST_KEY=$(grep '"path":"\\/v0\\/webhooks"' "$CONNECT_MOCK_LOG" | tail -1 | php -r '$l=json_decode(stream_get_contents(STDIN),true); echo substr($l["auth"],7);')

echo "== go live"
PAGE=$(config_page); printf "%s" "$PAGE" > "${SMOKE_PAGE:-/dev/null}"
LIVE=$(link live); [ -n "$LIVE" ] || fail "no Go live link"
round_trip "$LIVE" >/dev/null
[ "$(key_prefix)" = "ik_live_ secret" ] || fail "live key not saved: $(key_prefix)"
grep '"path":"\\/v0\\/connect\\/revoke"' "$CONNECT_MOCK_LOG" | grep -q "Bearer $TEST_KEY" || fail "the test connection was not revoked"
connection | grep -q '"mode":"live"' || fail "connection not live"

echo "== disconnect"
PAGE=$(config_page); printf "%s" "$PAGE" > "${SMOKE_PAGE:-/dev/null}"
DISC=$(printf '%s' "$PAGE" | grep -o "action: '[^']*disconnect[^']*'" | head -1 | sed "s/^action: '//; s/'$//" \
  | php -r 'echo json_decode("\"" . trim(stream_get_contents(STDIN)) . "\"");')
[ -n "$DISC" ] || fail "no Disconnect button"
FK=$(printf '%s' "$PAGE" | sed -n "s/.*FORM_KEY = '\([^']*\)'.*/\1/p" | head -1)
curl -s -c "$JAR" -b "$JAR" -L -o "$TMP/disc.html" "$DISC" --data-urlencode "form_key=$FK"
grep -q 'Disconnected. The keys this store used have been revoked.' "$TMP/disc.html" || fail "no Disconnected message"
[ "$(grep -c '"path":"\\/v0\\/connect\\/revoke"' "$CONNECT_MOCK_LOG")" = 2 ] || fail "disconnect did not revoke"
[ "$(key_prefix)" = " nosecret" ] || fail "keys not cleared: $(key_prefix)"
[ -z "$(connection)" ] || fail "connection not cleared"

echo "== an expired or foreign return"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/intentsfi/connect/back?state=never-issued-state-0123456789&code=abcdefghijklmnopqrstuvwxyz")
[ "$code" = 400 ] || fail "the storefront hop forwarded a state it never issued ($code)"

echo "OK: Connect, Go live, Disconnect"
