<?php
/**
 * Reads this module's configuration.
 */
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * `payment/intentsfi_checkout/*`.
 *
 * The two secrets are stored encrypted. etc/config.xml declares them with the Encrypted backend
 * model, which makes Magento's config metadata processor decrypt them on read, so ScopeConfig
 * already returns plaintext here; decrypting again would turn a valid key into garbage.
 *
 * The API origin can only be changed in `app/etc/env.php` (`intentsfi_checkout/api_base`), never
 * in the admin: an admin setting would let anyone with config access point the store's secret key
 * at a server of their choosing.
 */
class Config
{
    public const METHOD_CODE = 'intentsfi_checkout';
    public const VERSION = '1.0.3';
    public const SUPPORT_EMAIL = 'support@intents.fi';

    private const PREFIX = 'payment/' . self::METHOD_CODE . '/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'active', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function apiKey(?int $storeId = null): string
    {
        return $this->secret('api_key', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function webhookSecret(?int $storeId = null): string
    {
        return $this->secret('webhook_secret', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** `test` or `live` from the key's prefix; null when the saved key is not a secret key. */
    public function mode(?int $storeId = null): ?string
    {
        return KeyMode::detect($this->apiKey($storeId));
    }

    /** The key saved at a website scope, or the default scope when `$websiteId` is null. */
    public function apiKeyForWebsite(?int $websiteId): string
    {
        return $websiteId === null
            ? $this->secret('api_key', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null)
            : $this->secret('api_key', ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    /**
     * Every distinct webhook secret on this install: the default scope and each website.
     *
     * A webhook is not addressed to a store, so the body is verified against each one; the
     * processor then insists the secret that verified is the order's own store's.
     *
     * @return list<string>
     */
    public function allWebhookSecrets(): array
    {
        $secrets = [$this->secret('webhook_secret', ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null)];
        foreach ($this->storeManager->getWebsites() as $website) {
            $secrets[] = $this->secret('webhook_secret', ScopeInterface::SCOPE_WEBSITE, (int) $website->getId());
        }
        return array_values(array_unique(array_filter($secrets, static fn (string $s): bool => $s !== '')));
    }

    public function apiBaseUrl(): string
    {
        $base = $this->deploymentConfig->get('intentsfi_checkout/api_base');
        return is_string($base) && preg_match('#^https?://#', $base) === 1
            ? rtrim($base, '/')
            : Api\Client::DEFAULT_BASE_URL;
    }

    private function secret(string $field, string $scope, ?int $scopeId): string
    {
        $value = $this->scopeConfig->getValue(self::PREFIX . $field, $scope, $scopeId);
        return is_string($value) ? trim($value) : '';
    }
}
