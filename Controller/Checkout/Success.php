<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;

/**
 * `GET /intentsfi/checkout/success`: where the hosted page sends the customer after paying. It
 * changes nothing — only a signed webhook marks an order paid — and shows the usual success page.
 */
class Success implements HttpGetActionInterface
{
    public function __construct(private readonly RedirectFactory $redirectFactory)
    {
    }

    public function execute()
    {
        return $this->redirectFactory->create()->setPath('checkout/onepage/success');
    }
}
