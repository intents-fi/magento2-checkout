<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Block\Adminhtml\System\Config;

use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\KeyMode;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/** Shows which mode the saved key puts the store in. Read from the prefix; never shows the key. */
class DetectedMode extends Field
{
    public function __construct(Context $context, private readonly Config $config, array $data = [])
    {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element)
    {
        $website = $this->getRequest()->getParam('website');
        $key = $this->config->apiKeyForWebsite(is_string($website) && ctype_digit($website) ? (int) $website : null);
        $mode = KeyMode::detect($key);

        if ($mode === KeyMode::TEST) {
            return '<strong>' . $this->escapeHtml(__('Test')) . '</strong> — '
                . $this->escapeHtml(__('payments are simulated end to end and never move real funds.'));
        }
        if ($mode === KeyMode::LIVE) {
            return '<strong>' . $this->escapeHtml(__('Live')) . '</strong> — '
                . $this->escapeHtml(__('real payments, settled to your wallet.'));
        }
        return $this->escapeHtml(__((string) KeyMode::problem($key)));
    }
}
