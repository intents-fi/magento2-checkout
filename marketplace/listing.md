# Listing: Intents Checkout for Magento 2

Copy for the Adobe Commerce Marketplace (Commerce Marketplace Developer Portal) and the Packagist
package, following `brand/LISTING-KIT.md` (names, approved copy, store rules) and
`specs/BRIEF-release-magento.md`. The company is **Intents**; intents.fi is its domain.

## Name

Intents Checkout – Crypto Payments for Magento 2

(`brand/LISTING-KIT.md`. The platform brief suggested "… for Magento 2 / Adobe Commerce"; the kit's
pattern puts one platform trademark last. Never rename a listing during review.)

Package: `intentsfi/module-checkout` (Composer) · module `IntentsFi_Checkout` · publisher **Intents** ·
https://intents.fi/integrations/magento

## Short description

Get paid in crypto from any chain, settle your way. Non-custodial: every payment goes straight to your wallet.

## Long description

Accept crypto in your Magento store. Customers pay with any token on any chain; you receive one
asset, on one chain, in your own wallet.

### What it does

Intents Checkout is a non-custodial crypto payment gateway. Merchants price in USD; customers pay
with whatever they hold — USDT on Tron, native BTC, SOL, ETH on any L2, NEAR and more — and the
merchant always receives one settlement asset on one chain, sent straight to their own wallet.
Intents Checkout never holds funds. Every payment is routed across several independent liquidity
networks to the best available rate, so checkout keeps working if one network is down.

### How it works

1. Install and click **Connect to Intents** in Stores → Configuration → Sales → Payment Methods →
   Intents Checkout (or paste API keys). You come back connected in test mode, with payment
   notifications set up.
2. Choose your settlement asset and wallet in the Intents dashboard.
3. Customers pick "Pay with crypto", pay in any token on the secure hosted payment page, and the
   order is invoiced and marked Processing by a signed webhook.

Requires an Intents account. 0.4% per payment, no monthly fee. The customer pays on the hosted
payment page, and the funds go straight to your wallet: Intents never holds them.

### Features

- Non-custodial: every payment goes straight to your wallet
- 3,000+ assets on 18 chains for your customers; one settlement asset for you
- Best-rate routing with failover across several liquidity networks
- One-click setup with Connect, Go live and Disconnect; manual API keys still work
- Test mode, so you can place orders without moving real funds
- Signed webhooks: an order is invoiced only once your funds are delivered; duplicates and
  out-of-order deliveries are handled safely
- Underpayments go on hold with what is still owed; late payments are never invoiced automatically
- Light and dark hosted checkout, with the order total first
- Per-website keys, stored encrypted
- Optional affiliate ID, for agencies and developers who set up your checkout

### Requirements

Requires an Intents account (free to create at https://intents.fi/signup). Magento Open Source
2.4.7 and 2.4.8 (tested on PHP 8.2 and 8.3) and 2.4.9 (tested on PHP 8.3, 8.4 and 8.5), with the
standard Luma-based checkout; PHP 8.2 to 8.5. The store's base and display currency must be US dollars, and
the store must be served over HTTPS.

### Pricing

0.4% per payment, charged by the Intents Checkout service. No monthly fee, no fixed fee. The
plugin is free.

### External services

This extension connects to the Intents Checkout service. It calls `api.intents.fi` to create
invoices and read their status when a customer checks out and when a webhook arrives, and sends the
customer to `pay.intents.fi` to pay. Data sent: order id, amount, currency, return URLs and the
customer's email (for their receipt). When you press **Connect to Intents** or **Go live**, your
browser goes to `dashboard.intents.fi` with your store address; the extension then exchanges a
one-time code for your keys, registers your store's webhook address, and revokes the keys on
**Disconnect**. If you enter an optional affiliate ID, it is sent as `ref` with Connect and in an
`X-Intents-Affiliate` header when an invoice is created, and `GET /v0/ref/resolve` on
`api.intents.fi` receives only the affiliate ID, on settings save, to show who it belongs to. Terms: https://intents.fi/terms · Privacy: https://intents.fi/privacy.

### Support

support@intents.fi · Docs: https://intents.fi/docs/checkout/magento

## FAQ

**Do you hold my funds?**
No. Each payment is delivered straight to the wallet address you set in your Intents dashboard.
Intents never has custody of your money.

**What does it cost?**
0.4% per payment, charged by the Intents Checkout service. No monthly fee, no fixed fee: a $5
payment costs 2 cents. The extension is free, and test mode is free.

**Which coins can my customers pay with?**
3,000+ assets on 18 chains, including USDT on Tron, native BTC, SOL, and ETH on any L2. The payment
page only offers what can be filled for the order amount at that moment.

**What do I receive?**
One asset that you choose: USDC or USDT on a supported network, or native BTC, ETH or SOL, whatever the customer paid with.

**When is an order marked paid?**
Only when Intents confirms that your funds have been delivered. The order is then invoiced and
moves to Processing, and the order email is sent.

**What if a customer sends too little, or pays late?**
Too little puts the order on hold, and the payment page shows the exact amount still to send.
A payment that arrives after the order was cancelled is never invoiced automatically: you get a
comment and an admin notification, and you decide.

**How do refunds work?**
Payments are non-custodial, so a refund is sent from your own wallet. A payment that can't be
completed is returned to the customer's refund address automatically.

**What do I need to go live?**
A verified email and a payout wallet on your Intents account. Then press Go live in the
extension's settings.

**Does it work with Hyvä Checkout or a headless storefront?**
Not yet: it supports the standard Luma-based checkout.

**What is the Affiliate ID field?**
If an agency or developer set up your checkout, they may give you their Intents affiliate ID to
paste there. It's optional, it doesn't change your fee, and it only applies before your store's
first live payment.

**Where do I get help?**
Email support@intents.fi (support is by email only), or read https://intents.fi/docs.

## Changelog

### 1.1.0

* Optional affiliate ID for agencies and developers who set up your checkout.
* Ready for settlement in ETH or SOL as well as stablecoins and BTC: order comments show the amount that reached your wallet as, for example, "0.0215 ETH on Base". Choose your settlement asset and wallet in the Intents dashboard.

### 1.0.1

* PHP 8.4 and 8.5 support, tested on Magento Open Source 2.4.9.
* The package description now names Magento Open Source only (Adobe Commerce is not tested yet).
* The settings, order comments and messages say Intents instead of intents.fi; the button is now
  **Connect to Intents**. Nothing to reconfigure.

### 1.0.0

* First release.

- Connect: connect the store in a few clicks with no keys to copy; Go live and
  Disconnect from the same settings page. Manual key entry still works.
- "Pay with crypto" at checkout, with the order total first.
- Orders are invoiced only by a signed confirmation that the funds have been delivered.
  Duplicate and out-of-order notifications are handled safely.
- Underpayments put the order on hold; late payments are never invoiced automatically.
- Each payment is tied to its store and order, so two stores on one account never share one.
- Cancelling an order closes its payment.
- Test mode, per-website keys, encrypted secrets, and a log with no keys or secrets in it.

## Tested versions

Run on 2026-11-26 in Docker. Every row: install, `setup:upgrade`, `setup:di:compile`, the
test-mode checks below, and removal. 1.1.0 (the optional affiliate ID) was run on 2.4.8-p5 with
PHP 8.3 (first row). The 2.4.9 rows on PHP 8.4 and 8.5 were run on 1.0.1; the others on 1.0.0,
whose code differs only in its screen text and version number.

| Magento Open Source | PHP | Install | Mode | Database | Search |
|---|---|---|---|---|---|
| 2.4.8-p5 | 8.3.6 | 1.0.1 zip in `app/code`, upgraded to the 1.1.0 zip; then `composer require` (path repository) and `module:uninstall --remove-data` (1.1.0) | developer | MariaDB 11.4 | OpenSearch 2.19 |
| 2.4.9 | 8.5.13, 8.4.28 | zip in `app/code` (1.0.1) | developer | MariaDB 12.3 | OpenSearch 3.10.1 |
| 2.4.7-p10 | 8.2, 8.3 | `composer require` from the mirror repository | production (`setup:static-content:deploy`) | MariaDB 10.6 | OpenSearch 2.19 |
| 2.4.8-p5 | 8.2, 8.3 | `composer require` from the mirror repository | production | MariaDB 11.4 | OpenSearch 2.19 |
| 2.4.9 | 8.3 | zip in `app/code` | developer | MariaDB 11.4 | OpenSearch 2.19 |
| 2.4.7-p9 | 8.3 | zip in `app/code` | developer | MariaDB 10.6 | OpenSearch 2.19 |

Checks on each: Connect, Go live and Disconnect through the admin (against a mock of the Connect
endpoints); Test connection; a test-mode order against api.intents.fi paid by a signed webhook
(invoice created, order Processing, comment "Settled: 9.96 USDC on Base"); a replayed webhook
creates no second invoice; a forged signature gets 401; `module:disable` + `setup:upgrade` (and,
on 2.4.8-p5, `module:uninstall --remove-data`) leave the store working.

1.1.0 on 2.4.8-p5 also: settings saved on 1.0.1 are kept by the upgrade; Connect, Go live,
Disconnect and Test connection pass again; the Affiliate ID field lowercases a valid ID and shows
"Linked to …", warns on an unknown ID or an unreachable API and still saves, refuses an invalid ID
and keeps the old one; the Connect link carries `ref` only when an ID is set; Test connection shows
"Referred by …"; invoice create sends `X-Intents-Affiliate` only when an ID is set (all against the
mock, `dev/magento/affiliate-smoke.sh`); `module:uninstall --remove-data` removes the ID with the
other settings. Not run on 1.1.0: a test-mode order against api.intents.fi.

The pure-logic PHPUnit suite (`bin/run-phpunit.sh`) passes on PHP 8.3 with 1.1.0 (113 tests);
on 1.0.1 (104 tests) it also passed on PHP 8.4 and 8.5.

Not run: Adobe Commerce (on-premises or cloud); PHP 8.4 and 8.5 on 2.4.7 or 2.4.8. `composer.json`
requires PHP 8.2, 8.3, 8.4 or 8.5 (`~8.2.0||~8.3.0||~8.4.0||~8.5.0`) and `magento/framework`
^103.0.7 (2.4.7 and later).

## Source code

Nothing in the package is compiled, minified or obfuscated: the PHP, `.phtml` templates, Knockout
template and RequireJS files ship as written, with the licence (`LICENSE`, MIT). The one use of
`base64_encode`, in `lib/connect/class-connect-protocol.php`, builds the base64url PKCE challenge
and random state values required by RFC 7636, and is commented as such.
