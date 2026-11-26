<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Controller\Webhook;

use IntentsFi\Checkout\Model\Config;
use IntentsFi\Checkout\Model\Signature;
use IntentsFi\Checkout\Model\Webhook\DbEventLedger;
use IntentsFi\Checkout\Model\Webhook\MagentoOrderPort;
use IntentsFi\Checkout\Model\Webhook\Processor;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * `POST /intentsfi/webhook`: the only thing that marks an order paid.
 *
 * It is exempt from Magento's form-key CSRF check because intents.fi cannot hold a form key; the
 * credential is the HMAC signature over the raw body, which {@see Processor} verifies, in constant
 * time, before it reads the payload.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly MagentoOrderPort $orders,
        private readonly DbEventLedger $ledger,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute()
    {
        $json = $this->jsonFactory->create();
        $payload = $this->request instanceof HttpRequest ? (string) $this->request->getContent() : '';
        $header = (string) $this->request->getHeader(Signature::HEADER);

        $processor = new Processor($this->config->allWebhookSecrets(), $this->orders, $this->ledger);

        try {
            $result = $processor->handle($payload, $header);
        } catch (\Throwable $e) {
            // Rolled back, so the retry is handled in full. The payload is not logged.
            $this->logger->critical('webhook failed: ' . $e->getMessage(), ['exception' => $e]);
            return $json->setHttpResponseCode(500)->setData(['error' => 'internal_error']);
        }

        $result->status >= 400
            ? $this->logger->warning('webhook ' . $result->summary)
            : $this->logger->info('webhook ' . $result->summary);

        return $json->setHttpResponseCode($result->status)->setData($result->body);
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
