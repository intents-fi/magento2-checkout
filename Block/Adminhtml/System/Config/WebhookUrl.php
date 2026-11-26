<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** The read-only webhook URL a merchant pastes into the intents.fi dashboard. */
class WebhookUrl extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $store = $this->_storeManager->getDefaultStoreView();
        $website = $this->getRequest()->getParam('website');
        if (is_string($website) && ctype_digit($website)) {
            $store = $this->_storeManager->getWebsite((int) $website)->getDefaultStore() ?: $store;
        }
        $url = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK, true), '/') . '/intentsfi/webhook';

        $html = '<input type="text" class="input-text admin__control-text" readonly="readonly" onclick="this.select()" value="'
            . $this->escapeHtmlAttr($url) . '"/>';
        $html .= '<p class="note"><span>' . $this->escapeHtml(__(
            'Add this URL as a webhook endpoint for the same key in your Intents dashboard, subscribed to the invoice events, and paste the secret it shows into Webhook secret.'
        )) . '</span></p>';
        if (!str_starts_with($url, 'https://')) {
            $html .= '<p class="note"><strong>' . $this->escapeHtml(__('This store is not served over HTTPS. Live webhooks are only delivered to https:// URLs.')) . '</strong></p>';
        }
        return $html;
    }
}
