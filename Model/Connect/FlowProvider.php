<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Connect;

use IntentsFi\Checkout\Model\ClientFactory;
use IntentsFi\Checkout\Model\Config;
use Psr\Log\LoggerInterface;

/** Builds {@see ConnectFlow} with Magento's pieces, and the settings of a scope. */
class FlowProvider
{
    public function __construct(
        private readonly CachePendingStore $pending,
        private readonly ClientFactory $clientFactory,
        private readonly StoreUrls $urls,
        private readonly ScopeSettingsFactory $settingsFactory,
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    public function flow(): ConnectFlow
    {
        return new ConnectFlow(
            $this->pending,
            fn (string $key) => $this->clientFactory->create($key),
            $this->urls->dashboardUrl(),
            function (string $line): void {
                $this->logger->error($line);
            }
        );
    }

    public function settings(?int $websiteId): SettingsInterface
    {
        return $this->settingsFactory->create(['websiteId' => $websiteId]);
    }

    /** The affiliate ID saved at a scope (null: default), or ''. */
    public function affiliateId(?int $websiteId): string
    {
        return $this->config->affiliateIdForWebsite($websiteId);
    }

    public function urls(): StoreUrls
    {
        return $this->urls;
    }
}
