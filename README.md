# Intents Checkout for Magento Open Source

Crypto checkout for Magento 2: the customer pays with whatever token they hold, and you receive
one asset (a stablecoin, BTC, ETH or SOL) at your own address. Non-custodial: Intents never holds your funds.

The module is a thin offsite payment method over the public Intents Checkout API. It creates an
invoice for the order, sends the customer to the hosted payment page at pay.intents.fi, and
invoices the order only when a signed `invoice.paid` webhook says the funds have been delivered.

- Module: `IntentsFi_Checkout` · Composer package: `intentsfi/module-checkout`
- Tested on Magento Open Source **2.4.7-p10 and 2.4.8-p5 on PHP 8.2 and 8.3** (Composer, production
  mode), and 2.4.9 on PHP 8.3, 8.4 and 8.5; see `marketplace/listing.md`. Requires PHP 8.2 to 8.5
  and Magento 2.4.7 or later. Not tested on Adobe Commerce yet.
- Luma-based checkout (the standard Knockout checkout). Hyvä Checkout is not supported yet.
- Support: **support@intents.fi** (email only)

## Install

**From a zip (`app/code`)**

```bash
mkdir -p app/code/IntentsFi/Checkout
unzip intentsfi-magento2-<version>.zip -d app/code/IntentsFi/Checkout
bin/magento module:enable IntentsFi_Checkout
bin/magento setup:upgrade
bin/magento setup:di:compile          # production mode only
bin/magento setup:static-content:deploy   # production mode only
bin/magento cache:flush
```

**With Composer** (Packagist, from the public mirror of this folder):

```bash
composer require intentsfi/module-checkout
bin/magento module:enable IntentsFi_Checkout
bin/magento setup:upgrade
```

`setup:upgrade` creates one table, `intentsfi_checkout_webhook_event`, which remembers the webhook
events already applied.

## Uninstall

**Composer install:**

```bash
bin/magento module:uninstall IntentsFi_Checkout --remove-data
```

`--remove-data` runs `Setup/Uninstall.php`: it drops `intentsfi_checkout_webhook_event` and deletes
`payment/intentsfi_checkout/*` (the encrypted key and webhook secret, and the affiliate ID, included)
at every scope.
Orders keep their payment information and comments. Without `--remove-data`, the data stays.
Disconnect first if the store is connected, so the keys are revoked by Intents too.

**`app/code` install:** `bin/magento module:disable IntentsFi_Checkout`, delete
`app/code/IntentsFi/Checkout`, then `bin/magento setup:upgrade`. Magento leaves a removed
`app/code` module's table and settings in place.

## External services

This module is a connector to Intents (intents.fi), a third-party payment service. It cannot take
payments without an Intents account. It talks to:

- **api.intents.fi**, from the store's server: when an order is placed with the method (the order
  total and currency, a reference made from the increment ID and a short store key, a description,
  the return URLs, the customer's email for their receipt, and the order's increment ID and a check
  value so the payment matches its order, plus the affiliate ID in an `X-Intents-Affiliate` header
  if one is set); to read or cancel that invoice; for **Test connection**;
  and for Connect (the one-time code, the PKCE verifier and the store URL, to receive the keys;
  registering the store's webhook URL; revoking on Disconnect); and `GET /v0/ref/resolve`, which
  sends only the affiliate ID, on settings save when the **Affiliate ID** changes, to show who it
  belongs to. The token exchange and `/v0/ref/resolve` are unauthenticated; every other request is
  authenticated with the store's secret key.
- **dashboard.intents.fi**, in the admin's browser, when **Connect to Intents** or **Go live** is
  pressed: the store URL, the address to return to, the mode and two random values, and the
  affiliate ID (as `ref`) if one is set.
- **pay.intents.fi**, in the customer's browser: the hosted payment page.

Intents sends signed payment notifications to `https://<your store>/intentsfi/webhook`; the
module refuses any it cannot verify. Terms: https://intents.fi/terms · Privacy:
https://intents.fi/privacy · Docs: https://intents.fi/docs/checkout/magento

## Licence

MIT (`LICENSE`). Pricing: 0.4% per payment, no monthly fee, charged by Intents; the module
itself is free.

## Configure

**Stores → Configuration → Sales → Payment Methods → Intents Checkout**, at the default scope or
per website.

### Connect (no keys to copy)

1. Press **Connect to Intents**. You go to dashboard.intents.fi, sign in or sign up, and approve.
2. You come back to the same settings page **connected in test mode**: the module has saved a test
   secret key, registered this store's webhook URL with it and saved the webhook secret. Nothing to
   copy or paste.
3. Set **Enabled** to Yes, **Save Config**, and place a test order. The hosted page shows a
   **Complete the test payment** button for a test invoice; the order moves to **Processing**, with
   an invoice, when the webhook arrives.
4. **Go live** fetches your live key once the go-live checklist on the dashboard is done (a
   verified email and a payout wallet). Until then it says what is missing and the store stays in
   test mode. On success the live key replaces the test one, and the test connection is revoked.
5. **Disconnect** revokes this scope's keys and webhook with Intents, then clears them here.

How it works: the module makes a `state` and a PKCE verifier (kept server-side in Magento's cache
for this admin user, never in a URL or cookie) and sends you to
`dashboard.intents.fi/connect?platform=magento&site_url&return_url&state&code_challenge&mode`
(plus `ref` when an affiliate ID is saved).
The `return_url` is the storefront's `/intentsfi/connect/back`, because the API wants it on the
store's own origin and Magento's admin often lives elsewhere. That route only forwards `code` and
`state` to the admin callback URL saved when you pressed Connect; the admin callback checks the
state belongs to the same admin user, exchanges the code with the verifier
(`POST /v0/connect/token`), registers the webhook (`POST /v0/webhooks`) and saves both secrets
encrypted. The protocol code is the one every Intents store plugin shares
(`plugins/_shared/connect/class-connect-protocol.php`), copied into `lib/connect/` by
`bin/build-dist.sh`. Connect needs the store on `https://` (plain http only for `localhost`,
`127.0.0.1` and `.test` hosts, against a development API).

### Enter keys manually

1. In your [Intents dashboard](https://dashboard.intents.fi), check your settlement asset and
   address, and copy your **test** secret key (`ik_test_…`).
2. Under **Enter keys manually**, paste it into **API key**. The **Mode** row shows *Test* or
   *Live*, read from the key's prefix. A publishable `pk_…` key is refused on save.
3. In the dashboard (**Webhooks**), add the **Webhook URL** shown (`https://<your store>/intentsfi/webhook`)
   as an endpoint for the same key, subscribed to the `invoice.*` events. Paste the `whsec_…`
   secret it shows into **Webhook secret**.
4. **Save Config**, then press **Test connection**: it shows the merchant, the mode and the
   settlement wallet the saved key belongs to.
5. To go live, replace the key and the webhook secret with the live ones (`ik_live_…` and the
   live endpoint's `whsec_…`).

### Affiliate ID (optional)

If an agency or developer set up your checkout, paste the Intents affiliate ID they gave you into
**Affiliate ID (optional)** (below the keys) and **Save Config**. It doesn't change your fee, and
it only applies before your store's first live payment. Save it before pressing **Connect to
Intents**, so it goes along with the connection.

- An ID is 6 to 16 lowercase letters, digits or hyphens; it is saved lowercased, and anything else
  is refused with "Affiliate IDs are 6 to 16 lowercase letters, digits or hyphens." Empty clears it.
- When it changes, the module asks Intents who it belongs to (`GET /v0/ref/resolve`, five-second
  timeout, nothing but the ID) and shows "Linked to {name}", or a warning ("Unknown affiliate ID",
  or that it couldn't be checked right now). Either way the ID is saved.
- It goes to Intents as `ref` on the Connect link and as the `X-Intents-Affiliate` header on invoice
  create, nowhere else. Intents decides whether it applies; nothing in the store depends on it.
- **Test connection** says "Referred by {name}" when Intents reports one for the merchant.

Stored at `payment/intentsfi_checkout/affiliate_id` (default scope or per website).

A key pasted over a connected one ends the connection here (Disconnect will not revoke a key it
did not make); revoke the old one from **Integrations** on the dashboard.

The API key and webhook secret are stored encrypted, are marked sensitive (never exported by
`app:config:dump`), and are never written to a log. Keys can be set per website.

### Currency

**Invoices are priced in USD, and the module never converts.** The method is hidden at checkout
unless the website's base currency **and** the currency the customer is charged in are both USD.
The configuration page says so, per store view, when that is not the case.

### Pointing at a local API

The API and dashboard origins can only be changed in `app/etc/env.php`, never in the admin:

```php
'intentsfi_checkout' => ['api_base' => 'http://127.0.0.1:8787', 'dashboard_url' => 'http://localhost:3001'],
```

## How a payment runs

1. The customer chooses the method. The checkout shows the amount first, then explains that the
   token and network are chosen on the next page.
2. Placing the order runs the `initialize` command, which only parks the order at
   **Pending Payment**. Nothing is charged and the order email waits.
3. The checkout goes to `/intentsfi/checkout/redirect`, which creates an invoice with
   `reference = <order increment id>-<store key>` and redirects to its hosted `url`. The reference
   is the API's idempotency key, so a reload or a second tab lands on the same invoice. The store
   key is eight hex characters of a hash of the store's base URL (scheme and trailing slash
   ignored): two stores on one Intents merchant can both have order `000000001`, and without
   it the second would be handed the first one's invoice. An invoice whose metadata names another
   order (`magento_increment_id`, `magento_order_check`) is never reused, redirected to or
   canceled; the next reference is tried instead.
4. The customer pays on the hosted page. Coming back to `/intentsfi/checkout/success` never marks
   the order paid.
5. Intents delivers signed `invoice.*` webhooks to `/intentsfi/webhook`:

| Event | What the order does |
|---|---|
| `invoice.processing` | comment only. Do not ship yet |
| `invoice.underpaid` | **on hold**, with a comment saying what is still owed |
| `invoice.paid` | **invoiced** (offline capture) and moved to **Processing**; the order email is sent. An underpaid hold is released first |
| `invoice.paid` for an amount or currency that is not the order's | **on hold** with a comment, never invoiced |
| `invoice.expired`, `invoice.canceled` | order **canceled**, unless it is paid or held for review |
| `invoice.needs_review` | comment and an admin notification |

If the customer leaves the payment page (`/intentsfi/checkout/cancel`), the invoice is canceled
through the API first; only if that succeeds is the order canceled and the cart restored. If the
API refuses, a payment may be in flight, so the order is left alone.

### Webhooks: signature, duplicates, order

- **Signature.** `X-Intents-Signature: t=<unix>,v1=<hex>` where `v1 = HMAC-SHA256(secret, "<t>.<raw body>")`.
  The body is verified, in constant time and within five minutes, before it is parsed. A bad
  signature is answered `401`, so the delivery is retried (usually the secret is not pasted yet).
  The controller is exempt from Magento's form-key CSRF check because the HMAC is the credential.
- **Binding.** Each invoice carries `magento_increment_id` and an order check derived from the
  order's `protect_code`. An event whose check does not match, or that was signed with another
  website's secret, never moves the order.
- **Duplicates.** Each event id is inserted into `intentsfi_checkout_webhook_event` under a unique
  key, in the same transaction as the order change, with the order row locked. A redelivery is
  answered `200 {"duplicate": true}` and changes nothing; a failure rolls both back and answers
  `500`, so the retry is applied in full.
- **Out of order.** Each event has a rank (`processing`/`underpaid` 1, `expired`/`canceled` 2,
  `paid` 3). A late `expired` or `canceled` never undoes a paid order, and `invoice.paid` before
  `invoice.processing` still ends paid.
- **Late payments.** A payment that lands after the order was canceled (by expiry, by an admin, or
  by Magento's pending-payment cleanup cron) is never invoiced automatically: the order gets a
  comment and an admin notification, and you decide.

### When an order outlives its invoice

An invoice is priced when it is created. If the order total changes, the key mode changes, or the
invoice expires or is canceled, the next redirect creates a new invoice,
`<increment id>-<store key>-r2`, then `-r3` (SPEC-checkout §11.30). The old invoice is canceled **after** the order points at the new
one, so its own `invoice.canceled` webhook cannot cancel the order. Only the current invoice drives
the order: a payment on a superseded invoice puts the order on hold with a note naming both
amounts.

Canceling an order in Magento also closes its Intents invoice, so it cannot be paid later.

## Develop

```
registration.php, composer.json, etc/        module wiring (Adapter facade, pools, routes, schema)
Model/Signature, KeyMode, Amount,            pure logic, no Magento: unit-tested
      CurrencySupport, InvoicePlan, Affiliate,
      Webhook/OrderAction, Webhook/Processor,
      Api/Client
Model/InvoiceManager                         create / reuse / supersede the invoice
Model/Webhook/MagentoOrderPort, DbEventLedger  carry a webhook decision out on a Magento order
Controller/Checkout/{Redirect,Success,Cancel}  the customer's way to and from the hosted page
Controller/Webhook/Index                     POST /intentsfi/webhook
Controller/Adminhtml/System/TestConnection   the "Test connection" button
Model/Config/Backend/{ApiKey,AffiliateId}    the API key and affiliate ID fields' checks on save
Model/Connect/ConnectFlow                    Connect: start, the storefront hop, finish, disconnect (pure, unit-tested)
Model/Connect/{ScopeSettings,CachePendingStore,StoreUrls}  Connect's Magento side
Controller/Adminhtml/Connect/{Start,Callback,Disconnect}   the Connect buttons and the way back
Controller/Connect/Back                      GET /intentsfi/connect/back: forwards the return to the admin
lib/connect/ (built)                         the shared Connect protocol, copied in by bin/build-dist.sh
Observer/                                    availability (USD + key), close the invoice on cancel
view/                                        checkout renderer and the admin button
Test/Unit/                                   PHPUnit for the pure logic
bin/run-phpunit.sh, bin/build-dist.sh        tests, and the zip that ships
bin/phpcs.sh                                 the Magento Coding Standard on the built zip (Marketplace review)
marketplace/                                 Adobe Commerce Marketplace listing, user guide, screenshots (not shipped)
dev/magento/                                 a throwaway Magento install to test on
```

### Tests

```bash
bin/run-phpunit.sh        # PHP 8.2+ and Composer; installs PHPUnit into dev/phpunit
```

The suite covers the signature scheme, key-mode detection, amounts without floats, the currency
rule, the invoice plan and `-r` references, every event → order decision, the out-of-order guard,
idempotency (a duplicate is applied once, a failure rolls the claim back), and binding to the right
store's secret. The webhook processor runs against an in-memory order store with the same
transaction semantics as the Magento one. Connect is covered against a scripted API: the PKCE
request, the storefront hop, single-use returns, forged or foreign states, expiry, a token in the
wrong mode, live locked before go-live, Go live revoking the test connection, Disconnect, and
never revoking a key entered by hand. The affiliate ID: the field's rules, resolve (named, unknown,
unreachable), `ref` on the Connect URL only when set, and the header on invoice create only.

```bash
bin/phpcs.sh              # Magento Coding Standard, severity 10, on the built zip
```

### A real Magento install

```bash
docker compose -f dev/magento/docker-compose.yml up -d     # MariaDB 11.4 + OpenSearch 2.19
dev/magento/install.sh                                     # Magento 2.4.8-p5 + this module in app/code
cd dev/magento/src && php -S 127.0.0.1:8080 -t pub phpserver/router.php
```

PHP runs on the host and needs Magento's extensions (bcmath, curl, gd, intl, mbstring, pdo_mysql,
soap, sodium, xsl, zip). Packages come from the Mage-OS mirror, which needs no repo.magento.com
keys. Admin is `admin` / `Admin12345!` at `/admin`.

For 2.4.9, use the services Adobe lists for it and pick the PHP version with `with-php.sh` (it puts
`php8.5` or `php8.4` first on `PATH` for every script and Composer):

```bash
MARIADB_IMAGE=mariadb:12.3 OPENSEARCH_IMAGE=opensearchproject/opensearch:3.10.1 \
  docker compose -f dev/magento/docker-compose.yml up -d
MAGENTO_VERSION=2.4.9 dev/magento/with-php.sh 8.5 dev/magento/install.sh
(cd dev/magento/src && ../with-php.sh 8.5 php -S 127.0.0.1:8080 -t pub phpserver/router.php) &
dev/magento/with-php.sh 8.5 bin/run-phpunit.sh
```

```bash
INTENTSFI_TEST_KEY=ik_test_… dev/magento/smoke.sh   # a real test-mode order against api.intents.fi
dev/magento/connect-smoke.sh                        # Connect, Go live, Disconnect through the admin, against connect-mock.php
dev/magento/affiliate-smoke.sh                      # the affiliate ID through the admin, Connect and invoice create, against connect-mock.php
```

`setup:install` needs Docker for MariaDB and OpenSearch. If Docker Hub rate-limits the pulls,
pull `mirror.gcr.io/library/mariadb:11.4` and `mirror.gcr.io/opensearchproject/opensearch:2.19.1`
(for 2.4.9: `mariadb:12.3` and `opensearchproject/opensearch:3.10.1`) and `docker tag` them to the
names in `docker-compose.yml`.

### Building what ships

```bash
bin/build-dist.sh         # -> plugins/.dist/intentsfi-magento2-<version>.zip
```

The zip has `composer.json` at its root and no `vendor/`, `dev/`, `bin/`, `marketplace/`, tests or
dotfiles (the script refuses to build if any slip in), so the same file goes to the Adobe Commerce
Marketplace and into `app/code/IntentsFi/Checkout`. It refuses to build if `composer.json`'s
version and `Config::VERSION` differ. Nothing in the module is compiled or minified: the PHP,
templates and RequireJS/Knockout files ship as written.
