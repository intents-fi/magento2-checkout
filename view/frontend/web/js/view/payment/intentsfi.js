define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], (Component, rendererList) => {
    rendererList.push({
        type: 'intentsfi_checkout',
        component: 'IntentsFi_Checkout/js/view/payment/method-renderer/intentsfi-method',
    });

    return Component.extend({});
});
