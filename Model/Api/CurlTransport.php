<?php
declare(strict_types=1);

namespace IntentsFi\Checkout\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;

/** {@see TransportInterface} over Magento's own HTTP client. */
class CurlTransport implements TransportInterface
{
    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        $curl->setOption(CURLOPT_FOLLOWLOCATION, false);
        $curl->setHeaders($headers);

        try {
            if ($method === 'GET') {
                $curl->get($url);
            } elseif ($method === 'POST') {
                $curl->post($url, $body ?? '');
            } else {
                throw new \RuntimeException('Unsupported method ' . $method);
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        return ['status' => (int) $curl->getStatus(), 'body' => (string) $curl->getBody()];
    }
}
