<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** The "Test connection" button; see Controller/Adminhtml/System/TestConnection. */
class TestConnection extends Field
{
    protected $_template = 'IntentsFi_Checkout::system/config/test-connection.phtml';

    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    public function getAjaxUrl(): string
    {
        $params = [];
        $website = $this->getRequest()->getParam('website');
        if (is_string($website) && ctype_digit($website)) {
            $params['website'] = $website;
        }
        return $this->getUrl('intentsfi_checkout/system/testConnection', $params);
    }
}
