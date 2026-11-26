# Release notes: Intents Checkout for Magento

## 1.0.2

**Compatibility:** see "Tested versions" in `listing.md`. Luma-based checkout.

- The Composer package is now `intents/module-checkout`, so it matches the Intents vendor name
  on the Adobe Commerce Marketplace. Install with `composer require intents/module-checkout`.
  It replaces `intentsfi/module-checkout`: a store on 1.0.1 runs
  `composer remove intentsfi/module-checkout && composer require intents/module-checkout`, then
  `bin/magento setup:upgrade`. Settings, keys and orders are unchanged.
- No other changes: the code is the same as 1.0.1.

## 1.0.1

**Compatibility:** see "Tested versions" in `listing.md`. Luma-based checkout.

- PHP 8.4 and 8.5 are now supported: tested on Magento Open Source 2.4.9 with each, as well as
  PHP 8.2 and 8.3 as before. Adobe lists PHP 8.5 for Magento 2.4.9.
- The package description now names Magento Open Source only. Adobe Commerce has not been
  tested yet.
- The admin settings, order comments and messages now say Intents instead of intents.fi. The
  Connect button is now **Connect to Intents**. Nothing to reconfigure: settings, keys and
  orders are unchanged.

## 1.0.0 (first public release)

**Compatibility:** see "Tested versions" in `listing.md`. Luma-based checkout.

- Connect: connect the store in a few clicks with no keys to copy. The key and payment
  notifications are set up for you; **Go live** and **Disconnect** on the same settings page.
  Manual key entry still works.
- "Pay with crypto" at checkout, with the order total first. Customers pay on the hosted Intents
  payment page with the crypto they hold; you receive USDC or USDT in your own wallet.
- Orders are invoiced only by a signed `invoice.paid` notification, once the funds are delivered.
  Duplicates and out-of-order deliveries are handled in the same transaction as the order change.
- Underpayments put the order on hold; late payments are never invoiced automatically.
- Each order's invoice is tied to its store and order, so two stores on one Intents account
  never share a payment. A changed order total gets a fresh invoice.
- Cancelling an order closes its invoice.
- Test mode, per-website keys, encrypted secrets, `var/log/intentsfi_checkout.log` with no keys or
  secrets in it.
