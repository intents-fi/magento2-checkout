<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Connect;

use IntentsFi\Checkout\Model\Config;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Connect's settings at the default scope (`$websiteId` null) or one website: the same encrypted
 * `api_key` and `webhook_secret` the manual fields save, plus `connection`, which is what the
 * settings page shows (never a key).
 *
 * The writer bypasses the fields' backend models, so the two secrets are encrypted here exactly
 * as Magento's Encrypted backend model would.
 */
class ScopeSettings implements SettingsInterface
{
    private const PREFIX = 'payment/' . Config::METHOD_CODE . '/';

    public function __construct(
        private readonly Config $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $writer,
        private readonly EncryptorInterface $encryptor,
        private readonly SerializerInterface $serializer,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypes,
        private readonly ?int $websiteId = null
    ) {
    }

    public function apiKey(): string
    {
        return $this->config->apiKeyForWebsite($this->websiteId);
    }

    public function connection(): ?array
    {
        $raw = $this->websiteId === null
            ? $this->scopeConfig->getValue(self::PREFIX . 'connection', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
            : $this->scopeConfig->getValue(self::PREFIX . 'connection', ScopeInterface::SCOPE_WEBSITE, $this->websiteId);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $saved = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        // Only while the key here is still the one Connect saved: a key pasted over it manually
        // is not a connection, and Disconnect must not revoke what it does not own.
        if (!is_array($saved) || ($saved['key'] ?? '') !== ConnectFlow::fingerprint($this->apiKey())) {
            return null;
        }
        return [
            'mode' => (string) ($saved['mode'] ?? ''),
            'business' => (string) ($saved['business'] ?? ''),
            'merchant_status' => (string) ($saved['merchant_status'] ?? ''),
            'connection_id' => (string) ($saved['connection_id'] ?? ''),
            'site' => (string) ($saved['site'] ?? ''),
            'webhook' => (bool) ($saved['webhook'] ?? false),
            'connected_at' => (int) ($saved['connected_at'] ?? 0),
        ];
    }

    public function save(array $token, string $webhookSecret, int $now): void
    {
        $this->write('api_key', $this->encryptor->encrypt($token['secret_key']));
        $this->write('webhook_secret', $webhookSecret === '' ? '' : $this->encryptor->encrypt($webhookSecret));
        $this->write('connection', (string) $this->serializer->serialize([
            'key' => ConnectFlow::fingerprint($token['secret_key']),
            'mode' => $token['mode'],
            'business' => $token['business'],
            'merchant_status' => $token['merchant_status'],
            'connection_id' => $token['connection_id'],
            'site' => $token['site'],
            'webhook' => $webhookSecret !== '',
            'connected_at' => $now,
        ]));
        $this->flush();
    }

    public function forget(): void
    {
        foreach (['api_key', 'webhook_secret', 'connection'] as $field) {
            [$scope, $scopeId] = $this->scope();
            $this->writer->delete(self::PREFIX . $field, $scope, $scopeId);
        }
        $this->flush();
    }

    private function write(string $field, string $value): void
    {
        [$scope, $scopeId] = $this->scope();
        $this->writer->save(self::PREFIX . $field, $value, $scope, $scopeId);
    }

    /** @return array{0: string, 1: int} */
    private function scope(): array
    {
        return $this->websiteId === null
            ? [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]
            : [ScopeInterface::SCOPE_WEBSITES, $this->websiteId];
    }

    private function flush(): void
    {
        $this->cacheTypes->cleanType('config');
        $this->reinitableConfig->reinit();
    }
}
