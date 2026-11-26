<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Block\Adminhtml\System\Config;

use IntentsFi\Checkout\Model\CurrencySupport;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Tells the admin, up front, when the store's currencies keep the method hidden at checkout:
 * the base currency, and every currency customers can be charged in, must be USD.
 */
class CurrencyNotice extends Field
{
    protected function _getElementHtml(AbstractElement $element)
    {
        $website = $this->getRequest()->getParam('website');
        $stores = is_string($website) && ctype_digit($website)
            ? $this->_storeManager->getWebsite((int) $website)->getStores()
            : $this->_storeManager->getStores();

        $problems = [];
        foreach ($stores as $store) {
            $base = (string) $store->getBaseCurrencyCode();
            $codes = array_unique(array_merge(
                [(string) $store->getDefaultCurrencyCode()],
                array_map('strval', (array) $store->getAvailableCurrencyCodes(true))
            ));
            foreach ($codes as $code) {
                $problem = CurrencySupport::problem($base, $code);
                if ($problem !== null) {
                    $problems[$problem] = (string) $store->getName();
                }
            }
        }

        if ($problems === []) {
            return $this->escapeHtml(__('USD. Invoices are priced in USD, so this store can use the payment method.'));
        }
        $html = '<div class="message message-warning"><ul>';
        foreach ($problems as $problem => $storeName) {
            $html .= '<li>' . $this->escapeHtml(__('%1: %2', $storeName, __($problem))) . '</li>';
        }
        return $html . '</ul></div>';
    }

    protected function _renderScopeLabel(AbstractElement $element)
    {
        return '';
    }
}
