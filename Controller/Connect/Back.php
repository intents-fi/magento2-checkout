<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Connect;

use IntentsFi\Checkout\Model\Connect\FlowProvider;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\RedirectFactory;

/**
 * `GET /intentsfi/connect/back`: the Connect `return_url`. The API wants it on the store's own
 * origin, and Magento's admin is often on another, so this only forwards `code` and `state` to the
 * admin URL saved when Connect was pressed. It reads no session, saves nothing, and never
 * redirects anywhere a query names.
 */
class Back implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly FlowProvider $provider,
        private readonly RedirectFactory $redirectFactory,
        private readonly RawFactory $rawFactory
    ) {
    }

    public function execute()
    {
        $query = [];
        foreach (['state', 'code', 'error'] as $field) {
            $value = $this->request->getParam($field);
            if (is_string($value)) {
                $query[$field] = $value;
            }
        }
        $target = $this->provider->flow()->forwardUrl($query);
        if ($target === null) {
            $raw = $this->rawFactory->create();
            $raw->setHttpResponseCode(400);
            $raw->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
            return $raw->setContents((string) __('This link has expired. Go back to your Magento admin and press Connect intents.fi again.'));
        }
        return $this->redirectFactory->create()->setUrl($target);
    }
}
