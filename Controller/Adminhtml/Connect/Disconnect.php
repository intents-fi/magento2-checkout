<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Adminhtml\Connect;

use Magento\Framework\App\Action\HttpPostActionInterface;

/** "Disconnect": revoke this scope's connection on intents.fi, then forget its keys here. */
class Disconnect extends AbstractConnect implements HttpPostActionInterface
{
    public function execute()
    {
        $websiteId = $this->websiteParam();
        $result = $this->provider->flow()->disconnect($this->provider->settings($websiteId));
        return $this->back($result, $websiteId);
    }
}
