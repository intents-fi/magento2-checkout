<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Adminhtml\Connect;

use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * "Connect to Intents" (`mode=test`) and "Go live" (`mode=live`): keep a fresh request for this
 * admin and send them to dashboard.intents.fi, with the saved affiliate ID as `ref` if there is
 * one. The admin URL key is this link's CSRF check.
 */
class Start extends AbstractConnect implements HttpGetActionInterface
{
    public function execute()
    {
        $mode = $this->getRequest()->getParam('mode') === 'live' ? 'live' : 'test';
        $websiteId = $this->websiteParam();
        $urls = $this->provider->urls();

        $started = $this->provider->flow()->start(
            $mode,
            $this->userId(),
            $urls->siteUrl($websiteId),
            $urls->returnUrl($websiteId),
            $this->_backendUrl->getUrl('intentsfi_checkout/connect/callback', ['_nosecret' => true]),
            $urls->webhookUrl($websiteId),
            $websiteId,
            time(),
            $this->provider->affiliateId($websiteId)
        );
        if (!$started['ok']) {
            return $this->back((string) $started['result'], $websiteId);
        }
        return $this->resultRedirectFactory->create()->setUrl((string) $started['url']);
    }
}
