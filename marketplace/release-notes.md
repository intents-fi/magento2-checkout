# Release notes: Intents Checkout for Magento

## 1.0.0 (first public release)

**Compatibility:** see "Tested versions" in `listing.md`. Luma-based checkout.

- Connect intents.fi: connect the store in a few clicks with no keys to copy. The key and payment
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
