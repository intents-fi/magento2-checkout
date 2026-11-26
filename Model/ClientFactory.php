<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model;

use IntentsFi\Checkout\Model\Api\Client;
use IntentsFi\Checkout\Model\Api\CurlTransport;
use Psr\Log\LoggerInterface;

/** Builds an API client for a key, logging to var/log/intentsfi_checkout.log. */
class ClientFactory
{
    public function __construct(
        private readonly CurlTransport $transport,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function create(string $apiKey): Client
    {
        return new Client(
            $this->transport,
            $apiKey,
            $this->config->apiBaseUrl(),
            'intentsfi-checkout-magento/' . Config::VERSION,
            function (string $line): void {
                $this->logger->error($line);
            }
        );
    }

    public function forStore(?int $storeId): Client
    {
        return $this->create($this->config->apiKey($storeId));
    }
}
