<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Ui;

use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\KeyMode;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** What the checkout's payment renderer needs: where to go after the order is placed. */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $url,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getConfig(): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        return [
            'payment' => [
                Config::METHOD_CODE => [
                    'redirectUrl' => $this->url->getUrl('intentsfi/checkout/redirect', ['_secure' => true]),
                    'isTestMode' => $this->config->mode($storeId) === KeyMode::TEST,
                    'supportEmail' => Config::SUPPORT_EMAIL,
                ],
            ],
        ];
    }
}
