<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Connect;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store's addresses Connect sends: `site_url`, the storefront `return_url`, and the webhook
 * endpoint. All on the website's default store view, so they share an origin.
 */
class StoreUrls
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function siteUrl(?int $websiteId): string
    {
        return rtrim((string) $this->store($websiteId)->getBaseUrl(UrlInterface::URL_TYPE_WEB, true), '/');
    }

    public function returnUrl(?int $websiteId): string
    {
        return $this->link($websiteId) . '/intentsfi/connect/back';
    }

    public function webhookUrl(?int $websiteId): string
    {
        return $this->link($websiteId) . '/intentsfi/webhook';
    }

    /**
     * The dashboard origin. Only `app/etc/env.php` can change it (`intentsfi_checkout/dashboard_url`),
     * for a local dashboard, never the admin.
     */
    public function dashboardUrl(): string
    {
        $url = $this->deploymentConfig->get('intentsfi_checkout/dashboard_url');
        return is_string($url) && preg_match('#^https?://#', $url) === 1 ? rtrim($url, '/') : '';
    }

    private function link(?int $websiteId): string
    {
        return rtrim((string) $this->store($websiteId)->getBaseUrl(UrlInterface::URL_TYPE_LINK, true), '/');
    }

    private function store(?int $websiteId): StoreInterface
    {
        $store = $this->storeManager->getDefaultStoreView();
        if ($websiteId !== null) {
            $store = $this->storeManager->getWebsite($websiteId)->getDefaultStore() ?: $store;
        }
        return $store;
    }
}
