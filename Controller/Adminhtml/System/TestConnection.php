<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Adminhtml\System;

use IntentsFi\Checkout\Model\Api\ApiException;
use IntentsFi\Checkout\Model\ClientFactory;
use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\KeyMode;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * "Test connection": calls `GET /v0/merchant` with the saved key for the scope being edited and
 * reports the merchant, mode and settlement wallet it belongs to. Never echoes the key.
 */
class TestConnection extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'IntentsFi_Checkout::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly ClientFactory $clientFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $json = $this->jsonFactory->create();
        $website = $this->getRequest()->getParam('website');
        $websiteId = is_string($website) && ctype_digit($website) ? (int) $website : null;

        $key = $this->config->apiKeyForWebsite($websiteId);
        $problem = KeyMode::problem($key);
        if ($problem !== null) {
            return $json->setData(['ok' => false, 'message' => (string) __($problem) . ' ' . (string) __('Save the key first.')]);
        }

        try {
            $merchant = $this->clientFactory->create($key)->getMerchant();
        } catch (ApiException $e) {
            return $json->setData(['ok' => false, 'message' => (string) __('Intents said: %1 (%2)', $e->getMessage(), $e->getApiCode())]);
        }

        $settlement = is_array($merchant['settlement'] ?? null) ? $merchant['settlement'] : [];
        $parts = [
            (string) __('Connected to %1', is_string($merchant['name'] ?? null) ? $merchant['name'] : '?'),
            (string) __('%1 mode', is_string($merchant['mode'] ?? null) ? $merchant['mode'] : (string) KeyMode::detect($key)),
        ];
        if (is_string($merchant['status'] ?? null)) {
            $parts[] = (string) __('status %1', $merchant['status']);
        }
        if (is_string($settlement['address'] ?? null)) {
            $parts[] = (string) __('settles to %1', $settlement['address']);
        } else {
            $parts[] = (string) __('no settlement wallet set yet: add one in your Intents dashboard');
        }
        return $json->setData(['ok' => true, 'message' => implode(' · ', $parts)]);
    }
}
