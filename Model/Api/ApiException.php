<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Api;

/**
 * An intents.fi API call that did not succeed. `getApiCode()` is the API's own error code
 * (`INVALID_REQUEST`, `UNAUTHORIZED`, `INVOICE_NOT_OPEN`, …) or one of this module's `MODULE_*`
 * codes, and the message is safe to show to the merchant.
 */
class ApiException extends \RuntimeException
{
    public function __construct(
        private readonly string $apiCode,
        string $message,
        private readonly int $httpStatus = 0
    ) {
        parent::__construct($message);
    }

    public function getApiCode(): string
    {
        return $this->apiCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
