/**
 * Offsite: the order is placed first, then the customer is sent to the secure payment page.
 * Nothing here marks the order paid; only a signed webhook does.
 *
 * Methods use shorthand syntax (not arrows) because Knockout calls them with the component as `this`.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/quote',
    'Magento_Catalog/js/price-utils',
    'Magento_Checkout/js/model/full-screen-loader',
], (Component, quote, priceUtils, fullScreenLoader) =>
    Component.extend({
        defaults: {
            template: 'IntentsFi_Checkout/payment/intentsfi',
            redirectAfterPlaceOrder: false,
        },

        config() {
            return window.checkoutConfig.payment.intentsfi_checkout || {};
        },

        /** The amount the customer will pay, formatted the way the store shows prices. */
        getFormattedTotal() {
            const totals = quote.totals();
            if (!totals || totals.grand_total === undefined) {
                return '';
            }
            return priceUtils.formatPrice(totals.grand_total, quote.getPriceFormat());
        },

        isTestMode() {
            return !!this.config().isTestMode;
        },

        getSupportEmail() {
            return this.config().supportEmail || 'support@intents.fi';
        },

        afterPlaceOrder() {
            fullScreenLoader.startLoader();
            window.location.replace(this.config().redirectUrl);
        },
    }));
