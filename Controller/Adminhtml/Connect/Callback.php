<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Adminhtml\Connect;

use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * The admin comes back from dashboard.intents.fi (through the storefront's
 * `intentsfi/connect/back`). Exchanges the code, registers the webhook and saves the keys.
 *
 * The admin URL key cannot survive the round trip, so this one action is exempt from it
 * (`_publicActions`); it still needs a signed-in admin with the Intents Checkout ACL resource,
 * and `state` is its CSRF check: it must equal the request this same admin user started.
 */
class Callback extends AbstractConnect implements HttpGetActionInterface
{
    /** @var string[] */
    protected $_publicActions = ['callback'];

    public function execute()
    {
        $query = [];
        foreach (['state', 'code', 'error'] as $field) {
            $value = $this->getRequest()->getParam($field);
            if (is_string($value)) {
                $query[$field] = $value;
            }
        }
        $flow = $this->provider->flow();
        $done = $flow->finish($query, $this->userId(), fn (?int $website) => $this->provider->settings($website), time());
        return $this->back($done['result'], $done['website']);
    }
}
